#!/usr/bin/env python3
"""
oss_worker.py - OSS Timeline 常駐ワーカー
GitHub Trendingを定期取得してCMSに登録する

実行:
  python3 oss_worker.py          # 常駐
  python3 oss_worker.py --now    # 即時実行
  python3 oss_worker.py --now --weekly  # 週間トレンドで即時実行
"""
import sys
import os
import re
import subprocess
import json
import time
import datetime
import logging
import shutil

# ========== 設定 ==========
API_URL  = 'https://aiknowledgecms.exbridge.jp/saveoss.php'
LOG_FILE = os.environ.get('OSS_WORKER_LOG', os.path.expanduser('~/oss_worker.log'))
DASHBOARD_REPORT_URL = 'http://exbridge.ddns.net:8081/worker/report'


def report_worker(name, status, items, note=''):
    import urllib.request as _ur
    try:
        payload = json.dumps({'name': name, 'status': status, 'items': items, 'note': note}).encode()
        req = _ur.Request(DASHBOARD_REPORT_URL, data=payload, headers={'Content-Type': 'application/json'}, method='POST')
        _ur.urlopen(req, timeout=10)
    except Exception as exc:
        log.warning('dashboard report失敗: %s', exc)

def _load_config():
    config_path = os.path.join(os.path.dirname(__file__), 'config.yaml')
    if not os.path.exists(config_path):
        return {}
    conf = {}
    section = ''
    with open(config_path) as f:
        for line in f:
            line = line.rstrip()
            if not line or line.lstrip().startswith('#'):
                continue
            if line and not line.startswith(' ') and line.endswith(':'):
                section = line[:-1].strip()
                conf.setdefault(section, {})
            elif line.startswith('  ') and ':' in line:
                k, _, v = line.strip().partition(':')
                conf.setdefault(section, {})[k.strip()] = v.strip().strip("'\"")
    return conf

_conf = _load_config()

OLLAMA = os.environ.get(
    'OLLAMA_API',
    _conf.get('ollama', {}).get('api_url', 'https://exbridge.ddns.net/api/generate')
)
MODEL = os.environ.get(
    'OLLAMA_MODEL',
    _conf.get('ollama', {}).get('default_model', 'gemma4:e4b')
)
OLLAMA_TIMEOUT = int(os.environ.get('OSS_WORKER_OLLAMA_TIMEOUT', '60'))
OLLAMA_RETRIES = int(os.environ.get('OSS_WORKER_OLLAMA_RETRIES', '2'))
AI_PROVIDER = os.environ.get(
    'OSS_REGISTER_AI_PROVIDER',
    _conf.get('oss_register', {}).get('ai_provider', 'ollama')
).strip().lower()
CLAUDE_MODEL = os.environ.get(
    'OSS_REGISTER_CLAUDE_MODEL',
    _conf.get('oss_register', {}).get('claude_model', 'haiku')
)
CLAUDE_BIN = os.environ.get(
    'CLAUDE_BIN',
    _conf.get('oss_register', {}).get('claude_bin', '')
)
CODEX_MODEL = os.environ.get(
    'OSS_REGISTER_CODEX_MODEL',
    _conf.get('oss_register', {}).get('codex_model', 'gpt-5.5')
)
AI_TIMEOUT = int(os.environ.get('OSS_REGISTER_AI_TIMEOUT', '180'))

_pg_env = os.environ.get('PARAGRAPH_API_KEY')
PARAGRAPH_API_KEY = _pg_env if _pg_env is not None else _conf.get('paragraph', {}).get('api_key', '')
PARAGRAPH_API_URL = 'https://public.api.paragraph.com/api/v1/posts'
BANKR_DISCOVER_URL = 'https://bankr.bot/discover/0xDaecDda6AD112f0E1E4097fB735dD01D9C33cBA3'

# 1日4回実行する時刻（24h）
DAILY_HOURS  = [0, 6, 12, 18]
DAILY_TOP_N  = 1   # 1回あたり何件登録するか

# 週間トレンドの追加実行はデフォルト無効
WEEKLY_ENABLED = False
WEEKLY_DAY   = 0   # 0=月曜
WEEKLY_HOUR  = 6
WEEKLY_TOP_N = 2

SKIP_REPO_WORDS = [
    'awesome', 'collection', 'list', 'resources', 'roadmap',
    'cheatsheet', 'guide', 'tutorial', 'learning', 'examples',
    'templates', 'cookbook', 'papers', 'survey', 'notes',
    'bookmarks', 'reference', 'links', 'readings', 'study',
    'howto', 'best-practice', 'interview'
]
SKIP_GITHUB_USERS = [
    'topics', 'trending', 'search', 'login', 'orgs',
    'sponsors', 'features', 'marketplace', 'explore',
    'collections', 'events', 'about', 'pricing', 'apps'
]
# ==========================

_handlers = [logging.StreamHandler(sys.stdout)]
try:
    _handlers.insert(0, logging.FileHandler(LOG_FILE))
except Exception:
    pass

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=_handlers
)
log = logging.getLogger(__name__)


def configure_ai_provider(provider='', model='', claude_bin=''):
    global AI_PROVIDER, CLAUDE_MODEL, CODEX_MODEL, CLAUDE_BIN
    provider = (provider or AI_PROVIDER or 'ollama').strip().lower()
    if provider not in {'ollama', 'claude', 'codex'}:
        provider = 'ollama'
    AI_PROVIDER = provider
    if model:
        if provider == 'claude':
            CLAUDE_MODEL = model
        elif provider == 'codex':
            CODEX_MODEL = model
    if claude_bin:
        CLAUDE_BIN = claude_bin


def ai_resource():
    if AI_PROVIDER == 'claude':
        return {
            'resource': 'claude',
            'resource_key': f'claude:{CLAUDE_MODEL}',
            'ai_provider': 'claude',
            'ai_model': CLAUDE_MODEL,
        }
    if AI_PROVIDER == 'codex':
        return {
            'resource': 'codex',
            'resource_key': f'codex:{CODEX_MODEL}',
            'ai_provider': 'codex',
            'ai_model': CODEX_MODEL,
        }
    return {
        'resource': 'ollama',
        'resource_key': f"ollama:{os.environ.get('OSS_OLLAMA_HOST', '192.168.0.14').strip()}:{MODEL}",
        'ollama_host': os.environ.get('OSS_OLLAMA_HOST', '192.168.0.14').strip(),
        'ollama_endpoint': OLLAMA,
        'ollama_model': MODEL,
        'ai_provider': 'ollama',
        'ai_model': MODEL,
    }


def is_valid_github_repo(path):
    m = re.match(r'^/?([^/]+)/([^/\?#]+)/?$', path.replace('https://github.com', ''))
    if not m:
        return False
    user      = m.group(1).lower()
    repo_name = m.group(2).lower()
    if user in SKIP_GITHUB_USERS:
        return False
    for word in SKIP_REPO_WORDS:
        if word in repo_name:
            return False
    return True

def extract_title_from_readme(readme, fallback):
    generic_titles = {
        'about', 'overview', 'readme', 'introduction',
        'getting started', 'home', 'docs', 'documentation'
    }
    for line in readme.splitlines():
        line = line.strip()
        if not line:
            continue
        line = re.sub(r'<[^>]+>', '', line).strip()
        if not line:
            continue
        line = re.sub(r'\[([^\]]+)\]\([^\)]+\)', r'\1', line)
        line = line.strip()
        if not line:
            continue
        if 'shields.io' in line or 'badge' in line.lower():
            continue
        if '|' in line:
            continue
        if line.startswith('!') or line.startswith('>'):
            continue
        if line.startswith('-') or line.startswith('*'):
            continue
        if len(line) < 4 or len(line) > 120:
            continue
        if line.startswith('#'):
            title = line.lstrip('#').strip()
            if title.lower() in generic_titles:
                continue
            if title and len(title) > 2:
                return title
        if not line.startswith('<') and not line.startswith('|'):
            if line.lower() in generic_titles:
                continue
            return line
    return fallback

def extract_tags(post_text, github_url):
    tags = re.findall(r'#(\w+)', post_text)
    generic = {'OSS', 'AI', 'GitHub', 'opensource', 'OpenSource', 'Github'}
    tags = [t for t in tags if t not in generic]

    m = re.match(r'https://github\.com/[^/]+/([^/\?#]+)', github_url)
    if m:
        repo_tag = re.sub(r'[-_.]', '', m.group(1))
        if repo_tag and repo_tag.lower() not in [t.lower() for t in tags]:
            tags.append(repo_tag)

    for fixed in ['AI', 'OSS', 'GitHub']:
        if fixed not in tags:
            tags.append(fixed)

    seen = set()
    result = []
    for t in tags:
        if t.lower() not in seen:
            seen.add(t.lower())
            result.append(t)
    return result

def fetch_github_search(period='daily', language='', page=1, per_page=50):
    today = datetime.date.today()
    if period == 'daily':
        since = today - datetime.timedelta(days=1)
    elif period == 'weekly':
        since = today - datetime.timedelta(days=7)
    else:
        since = today - datetime.timedelta(days=30)

    q = 'pushed:>' + str(since) + '+stars:>50'
    if language:
        q += '+language:' + language

    url = ('https://api.github.com/search/repositories'
           '?q=' + q + '&sort=stars&order=desc'
           '&per_page=' + str(per_page) + '&page=' + str(page))

    cmd = ['curl', '-s', '--max-time', '15', url,
           '-H', 'Accept: application/vnd.github+json',
           '-H', 'User-Agent: oss-worker/1.0']
    result = subprocess.run(cmd, capture_output=True, text=True)
    raw = result.stdout.strip()
    if not raw:
        log.warning('GitHub Search API: 取得失敗')
        return []

    try:
        data = json.loads(raw)
    except Exception:
        log.warning('GitHub Search API: JSONパースエラー')
        return []

    items = []
    seen = set()
    for repo in data.get('items', []):
        url = repo.get('html_url', '')
        if not url or url in seen:
            continue
        seen.add(url)
        path = url.replace('https://github.com/', '')
        if not is_valid_github_repo(path):
            continue
        snippet = repo.get('description', '') or ''
        items.append({'url': url, 'snippet': snippet})

    log.info('GitHub Search API (%s): %d件取得', period, len(items))
    return items

def fetch_github_trending(period='daily', language=''):
    url = 'https://github.com/trending?since=' + period
    if language:
        url = 'https://github.com/trending/' + language + '?since=' + period
    cmd = [
        'curl', '-s', '--max-time', '15', url,
        '-H', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    html = result.stdout
    if not html:
        log.warning('GitHub Trending: 取得失敗')
        return []

    raw_paths = re.findall(r'href="/([a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+)"', html)
    seen = set()
    items = []
    for path in raw_paths:
        repo_url = 'https://github.com/' + path
        if repo_url in seen:
            continue
        seen.add(repo_url)
        if not is_valid_github_repo(path):
            continue
        items.append({'url': repo_url, 'snippet': ''})

    log.info('GitHub Trending (%s): %d件取得', period, len(items))
    return items

def fetch_candidate_repos(period='daily', language=''):
    # Trendingの並びは「直近で伸びている」順に近いため、スター総数順より優先する。
    items = fetch_github_trending(period=period, language=language)
    if items:
        return items

    log.warning('GitHub Trending取得失敗のため Search API にフォールバック')
    return fetch_github_search(period=period, language=language)

def fetch_github_readme(github_url):
    m = re.match(r'https://github\.com/([^/]+/[^/]+?)(?:/|$)', github_url)
    if not m:
        return ''
    repo = m.group(1)
    for branch in ['main', 'master']:
        cmd = [
            'curl', '-s', '--max-time', '15',
            'https://raw.githubusercontent.com/' + repo + '/' + branch + '/README.md',
            '-H', 'User-Agent: Mozilla/5.0'
        ]
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.stdout and '404' not in result.stdout[:100]:
            return result.stdout[:2000]
    return ''

def ollama_request(prompt):
    for attempt in range(1, max(OLLAMA_RETRIES, 1) + 1):
        response_text = _ollama_request_once(OLLAMA, MODEL, prompt)
        if response_text:
            return response_text
        if attempt < OLLAMA_RETRIES:
            log.warning('Ollama retry: endpoint=%s model=%s next_attempt=%d', OLLAMA, MODEL, attempt + 1)
    return ''

def _ollama_request_once(endpoint, model, prompt):
    payload = json.dumps({
        'model': model, 'prompt': prompt, 'stream': False
    }, ensure_ascii=False)
    cmd = [
        'curl', '-sS', '--max-time', str(OLLAMA_TIMEOUT),
        endpoint,
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    raw = result.stdout.strip()
    if not raw:
        err = result.stderr.strip()
        if err:
            log.warning('Ollama応答なし: endpoint=%s model=%s error=%s', endpoint, model, err[:200])
        else:
            log.warning('Ollama応答なし: endpoint=%s model=%s returncode=%s', endpoint, model, result.returncode)
        return ''

    response_text = ''
    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            data = json.loads(line)
            response_text += data.get('response', '')
            if data.get('done', False):
                break
        except Exception:
            continue

    if not response_text:
        try:
            data = json.loads(raw)
            response_text = data.get('response', '')
        except Exception:
            log.warning('Ollama応答エラー: endpoint=%s model=%s raw=%s', endpoint, model, raw[:100])
            return ''

    response_text = '\n'.join(line.strip() for line in response_text.splitlines())
    return response_text.strip()


def ai_request(prompt):
    global AI_PROVIDER
    if AI_PROVIDER == 'claude':
        response = claude_request(prompt)
        if response:
            return response
        log.warning('Claude応答が空のためCodexへフォールバックします')
        AI_PROVIDER = 'codex'
        response = codex_request(prompt)
        if response:
            return response
        log.warning('Codex応答も空のためOllamaへフォールバックします')
        AI_PROVIDER = 'ollama'
        return ollama_request(prompt)
    if AI_PROVIDER == 'codex':
        response = codex_request(prompt)
        if response:
            return response
        log.warning('Codex応答が空のためOllamaへフォールバックします')
        AI_PROVIDER = 'ollama'
        return ollama_request(prompt)
    return ollama_request(prompt)


def _resolve_claude_bin():
    candidates = []
    if CLAUDE_BIN:
        candidates.append(CLAUDE_BIN)
    found = shutil.which('claude')
    if found:
        candidates.append(found)
    candidates.extend([
        '/home/kojima/.vscode-server/extensions/anthropic.claude-code-2.1.169-linux-x64/resources/native-binary/claude',
        '/home/kojima/.vscode-server/extensions/anthropic.claude-code-2.1.168-linux-x64/resources/native-binary/claude',
        '/home/kojima/.vscode-server/extensions/anthropic.claude-code-2.1.167-linux-x64/resources/native-binary/claude',
    ])
    for path in candidates:
        if path and os.path.exists(path) and os.access(path, os.X_OK):
            return path
    return ''


def claude_request(prompt):
    claude_bin = _resolve_claude_bin()
    if not claude_bin:
        log.warning('Claude binary not found')
        return ''
    cmd = [
        claude_bin,
        '-p',
        '--output-format', 'text',
        '--permission-mode', 'dontAsk',
        '--model', CLAUDE_MODEL,
        prompt,
    ]
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=AI_TIMEOUT)
    if result.returncode != 0:
        detail = (result.stderr or result.stdout or '').strip()
        log.warning('Claude応答エラー: returncode=%s detail=%s', result.returncode, detail[:300])
        return ''
    return result.stdout.strip()


def codex_request(prompt):
    codex_bin = shutil.which('codex') or '/home/kojima/.vscode-server/extensions/openai.chatgpt-26.513.21555-linux-x64/bin/linux-x86_64/codex'
    if not os.path.exists(codex_bin) and not shutil.which('codex'):
        log.warning('Codex binary not found')
        return ''
    cmd = [
        codex_bin,
        'exec',
        '--skip-git-repo-check',
        '--sandbox', 'read-only',
        '--model', CODEX_MODEL,
        '--cd', os.path.dirname(__file__),
        prompt,
    ]
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=AI_TIMEOUT)
    if result.returncode != 0:
        log.warning('Codex応答エラー: returncode=%s stderr=%s', result.returncode, result.stderr.strip()[:300])
        return ''
    return result.stdout.strip()

def make_analysis(title, url, readme, snippet):
    context = 'README抜粋:\n' + readme if readme else '概要: ' + snippet
    prompt = """以下のOSSについて、技術者向けに3点で簡潔に考察してください。

タイトル: {title}
URL: {url}
{context}

出力形式（この形式のみで出力）：
■ 概要（1行）
■ 特徴・用途（2〜3行）
■ 結論（1行）""".format(title=title, url=url, context=context)
    return ai_request(prompt)

def make_post_text(title, url, readme, snippet):
    context = 'README抜粋:\n' + readme if readme else '概要: ' + snippet
    prompt = """あなたはAI系OSSを紹介するXアカウントの中の人です。
以下のOSSについてX投稿文を日本語で作成してください。

ルール：
- 本文は100文字以内
- 技術的に正確、具体的な特徴を1〜2点
- ハッシュタグは付けない（別途自動付与します）
- 煽り・誇張なし
- URLは含めない（別途付与します）

タイトル: {title}
{context}

    投稿文のみ出力してください。""".format(title=title, context=context)
    return ai_request(prompt)

def make_paragraph_title(title, url, readme, snippet):
    context = 'README excerpt:\n' + readme if readme else 'Summary: ' + snippet
    prompt = """You are editing an English article title for a technical OSS roundup on Paragraph.xyz.
Write one concise, natural English title.

Rules:
- 90 characters or fewer
- Clear and technical, not clickbait
- Mention the OSS name when useful
- Output title only

OSS title: {title}
GitHub URL: {url}
{context}""".format(title=title, url=url, context=context)
    return ai_request(prompt)

def make_paragraph_content(title, url, readme, snippet):
    context = 'README excerpt:\n' + readme if readme else 'Summary: ' + snippet
    prompt = """Write an English article about the following OSS for engineers, suitable for Paragraph.xyz.

Rules:
- Output in Markdown
- 500 to 900 words
- Calm, technical, specific tone
- Start with a short introduction
- Include these sections with Markdown headings:
  ## What It Does
  ## Why It Matters
  ## Key Technical Points
  ## When To Use It
  ## Final Thoughts
- Add a GitHub link near the end
- No hashtags
- No exaggerated marketing language

OSS title: {title}
GitHub URL: {url}
{context}""".format(title=title, url=url, context=context)
    content = ai_request(prompt)
    if not content:
        return content
    return content.rstrip() + '\n\n---\nBankr / URL2AI:\n- Discover URL2AI on Bankr: ' + BANKR_DISCOVER_URL + '\n'


def post_to_paragraph(title, content, status='draft'):
    if not PARAGRAPH_API_KEY:
        log.warning('PARAGRAPH_API_KEY未設定 - スキップ')
        return {}
    payload = json.dumps({
        'title': title,
        'markdown': content,
        'status': status,
    }, ensure_ascii=False)
    cmd = [
        'curl', '-s', '--max-time', '30',
        '-X', 'POST', PARAGRAPH_API_URL,
        '-H', 'Authorization: Bearer ' + PARAGRAPH_API_KEY,
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        return json.loads(result.stdout)
    except Exception:
        log.warning('Paragraph投稿レスポンスエラー: %s', result.stdout[:100])
        return {}

def update_paragraph_url(post_id, paragraph_url='', paragraph_post_id=''):
    payload = json.dumps({
        'action': 'paragraph_update',
        'id': post_id,
        'paragraph_url': paragraph_url,
        'paragraph_post_id': paragraph_post_id,
    })
    cmd = ['curl', '-s', '--max-time', '10', API_URL,
           '-H', 'Content-Type: application/json', '-d', payload]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        return json.loads(result.stdout).get('status') == 'ok'
    except Exception:
        return False

def check_exists(github_url):
    payload = json.dumps({'action': 'check', 'github_url': github_url})
    cmd = ['curl', '-s', '--max-time', '10', API_URL,
           '-H', 'Content-Type: application/json', '-d', payload]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        return json.loads(result.stdout).get('exists', False)
    except Exception:
        return False

def save_to_cms(title, github_url, analysis, post_text, tags):
    payload = json.dumps({
        'title': title, 'github_url': github_url,
        'analysis': analysis, 'post_text': post_text, 'tags': tags,
    }, ensure_ascii=False)
    cmd = [
        'curl', '-s', '--max-time', '15',
        API_URL,
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        return json.loads(result.stdout)
    except Exception:
        return {'error': result.stdout[:100]}

def fetch_registered_urls():
    payload = json.dumps({'action': 'list'}, ensure_ascii=False)
    cmd = ['curl', '-s', '--max-time', '30', API_URL,
           '-H', 'User-Agent: oss-worker/1.0',
           '-H', 'Content-Type: application/json',
           '-d', payload]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        data = json.loads(result.stdout)
        posts = data.get('items', []) if isinstance(data, dict) and data.get('status') == 'ok' else data
        if not isinstance(posts, list):
            raise ValueError('list API did not return posts')
        urls  = set(p['github_url'] for p in posts if 'github_url' in p)
        log.info('登録済みURL取得: %d件', len(urls))
        return urls
    except Exception as e:
        log.warning('fetch_registered_urls(list API)失敗: %s', e)

    base_url = API_URL.replace('saveoss.php', 'data/oss_posts.json')
    list_url = base_url + '?t=' + str(int(time.time()))
    cmd = ['curl', '-s', '--max-time', '30', list_url,
           '-H', 'User-Agent: Mozilla/5.0',
           '-H', 'Cache-Control: no-cache']
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        posts = json.loads(result.stdout)
        urls = set(p['github_url'] for p in posts if 'github_url' in p)
        log.info('登録済みURL取得(旧JSON): %d件', len(urls))
        return urls
    except Exception as e:
        log.warning('fetch_registered_urls(旧JSON)失敗: %s', e)
        return set()

def _candidate_generator(period):
    """Trending全件 → Search API page=1,2,3... の順に候補を1件ずつ yield する"""
    seen = set()
    for r in fetch_github_trending(period=period):
        if r['url'] not in seen:
            seen.add(r['url'])
            yield r
    page = 1
    while page <= 20:
        results = fetch_github_search(period=period, page=page, per_page=50)
        if not results:
            break
        for r in results:
            if r['url'] not in seen:
                seen.add(r['url'])
                yield r
        page += 1

def run_job(period='daily', top_n=3):
    log.info('===== JOB START: %s top=%d =====', period, top_n)
    registered = fetch_registered_urls()
    log.info('登録済み: %d件', len(registered))

    success = 0
    for r in _candidate_generator(period):
        if success >= top_n:
            break
        if r['url'] in registered:
            continue
        if check_exists(r['url']):
            log.info('重複スキップ(CMS): %s', r['url'])
            registered.add(r['url'])
            continue

        log.info('[%d/%d候補] %s', success + 1, top_n, r['url'])

        readme   = fetch_github_readme(r['url'])
        fallback = r['url'].replace('https://github.com/', '')
        title    = extract_title_from_readme(readme, fallback) if readme else fallback
        log.info('タイトル: %s', title)

        analysis          = make_analysis(title, r['url'], readme, r['snippet'])
        post_text         = make_post_text(title, r['url'], readme, r['snippet'])
        paragraph_title   = make_paragraph_title(title, r['url'], readme, r['snippet'])
        paragraph_content = make_paragraph_content(title, r['url'], readme, r['snippet'])
        tags              = extract_tags(post_text, r['url'])
        tag_str           = ' '.join(['#' + t for t in tags])
        post_full         = post_text.rstrip() + '\n' + tag_str + '\n' + r['url']

        res    = save_to_cms(title, r['url'], analysis, post_full, tags)
        status = res.get('status', '')
        if status in ('ok', 'updated'):
            if status == 'updated':
                log.info('更新完了: %s', res.get('id'))
            else:
                log.info('登録完了: %s', res.get('id'))
            sns_notice = res.get('sns_notice') or {}
            if sns_notice.get('ok'):
                log.info('AIxEC SNS投稿完了: id=%s author=oss', sns_notice.get('id'))
            else:
                log.warning('AIxEC SNS投稿未完了: %s', sns_notice.get('error', 'unknown'))
            registered.add(r['url'])
            success += 1

            # Paragraph自動投稿は無効化
            # para_res = post_to_paragraph(paragraph_title, paragraph_content, status='published')

        elif status == 'duplicate':
            log.info('重複スキップ(save): %s', r['url'])
            registered.add(r['url'])
        else:
            log.warning('登録失敗: %s', res)

        if success < top_n:
            time.sleep(3)

    if success == 0:
        log.info('新規登録なし')
    log.info('===== JOB END =====')
    return success

def main():
    log.info('oss_worker 起動 - 実行時刻: %s', str(DAILY_HOURS))

    # 実行済み記録: {(date, hour): True}
    done = {}

    while True:
        now  = datetime.datetime.now()
        hour = now.hour
        date = now.date()
        key  = (date, hour)

        # デイリー実行チェック
        if hour in DAILY_HOURS and key not in done:
            done[key] = True
            try:
                report_worker('oss_worker', 'running', 0, 'daily実行中')
                n = run_job(period='daily', top_n=DAILY_TOP_N)
                report_worker('oss_worker', 'ok', n, 'daily完了 新規%d件' % n)
            except Exception as e:
                log.error('デイリージョブエラー: %s', e)
                report_worker('oss_worker', 'error', 0, str(e)[:80])

            # 週間トレンドの追加実行（必要なときだけ有効化）
            if WEEKLY_ENABLED and now.weekday() == WEEKLY_DAY and hour == WEEKLY_HOUR:
                weekly_key = (date, 'weekly')
                if weekly_key not in done:
                    done[weekly_key] = True
                    time.sleep(60)
                    try:
                        run_job(period='weekly', top_n=WEEKLY_TOP_N)
                    except Exception as e:
                        log.error('ウィークリージョブエラー: %s', e)

        # 古いdoneエントリを削除（メモリ節約）
        yesterday = date - datetime.timedelta(days=2)
        done = {k: v for k, v in done.items() if not (isinstance(k[0], datetime.date) and k[0] < yesterday)}

        time.sleep(60)

if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--now':
        period = 'weekly' if '--weekly' in sys.argv else 'daily'
        top_n  = WEEKLY_TOP_N if period == 'weekly' else DAILY_TOP_N
        run_job(period=period, top_n=top_n)
    else:
        main()
