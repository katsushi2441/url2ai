<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');
set_time_limit(0);
ini_set('max_execution_time', 0);

$KEYWORD_DIR = __DIR__ . '/data';
$DATA_FILE   = __DIR__ . '/data/oss_posts.json';
$CACHE_FILE  = __DIR__ . '/data/zenn2oss_cache.json';
$ADMIN       = 'xb_bittensor';
$DRY_RUN     = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
$RSS_TIMEOUT = 10;
$self        = 'zenn2oss.php';
$dry         = $DRY_RUN ? '&dry_run=1' : '';

session_start();
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
if ($session_user !== $ADMIN) {
    http_response_code(403);
    echo '管理者のみ。<a href="oss.php">oss.phpからログイン</a>';
    exit;
}

$SKIP_USERS = array(
    'features','trending','topics','marketplace','explore','sponsors',
    'login','signup','about','pricing','contact','orgs','apps','settings',
    'notifications','pulls','issues','blob','tree','raw','commit','commits',
    'releases','tags','actions','packages','security','pulse','graphs','wiki',
);
$SKIP_REPOS = array(
    'blob','tree','raw','commit','commits','releases','tags','actions','wiki',
    'issues','pulls','discussions','projects','security','pulse','graphs',
    'settings','branches','compare','network','stargazers','watchers','forks',
    '.github',
);

/* ===== saveoss.phpと同じ登録ロジック ===== */

function call_ollama($prompt) {
    $payload = json_encode(
        array('model' => 'gemma4:e4b', 'prompt' => $prompt, 'stream' => false),
        JSON_UNESCAPED_UNICODE
    );
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $payload,
        'timeout'       => 120,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents(OLLAMA_API, false, stream_context_create($opts));
    if (!$res) return '';
    $data = json_decode($res, true);
    $response = isset($data['response']) ? $data['response'] : '';
    $lines = explode("\n", $response);
    $trimmed = array();
    foreach ($lines as $l) { $trimmed[] = trim($l); }
    return trim(implode("\n", $trimmed));
}

function fetch_github_repo_info($user, $reponame) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "User-Agent: AIGMBot/1.0\r\nAccept: application/vnd.github.v3+json\r\n",
        'timeout'       => 10,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents('https://api.github.com/repos/' . $user . '/' . $reponame, false, stream_context_create($opts));
    if (!$res) return null;
    return json_decode($res, true);
}

function clean_title_line($line) {
    $line = html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $line = strip_tags($line);
    $line = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $line);
    $line = ltrim($line, '# ');
    return trim($line);
}

function extract_title_from_readme($readme, $fallback) {
    foreach (explode("\n", $readme) as $line) {
        $raw = trim($line);
        if (!$raw) continue;
        if (strpos($raw, 'shields.io') !== false) continue;
        if (stripos($raw, 'badge') !== false) continue;
        if (strpos($raw, '|') !== false) continue;
        if ($raw[0] === '!') continue;
        if ($raw[0] === '>') continue;
        if ($raw[0] === '-' || $raw[0] === '*') continue;
        $cleaned = clean_title_line($raw);
        if (!$cleaned) continue;
        if (strlen($cleaned) < 2 || strlen($cleaned) > 120) continue;
        if (strpos($cleaned, '&') !== false && strpos($cleaned, ';') !== false) continue;
        return $cleaned;
    }
    return $fallback;
}

function extract_tags($post_text, $repo_name) {
    preg_match_all('/#(\w+)/', $post_text, $matches);
    $tags    = isset($matches[1]) ? $matches[1] : array();
    $generic = array('OSS', 'AI', 'GitHub', 'opensource', 'OpenSource', 'Github');
    $tags    = array_values(array_filter($tags, function($t) use ($generic) {
        return !in_array($t, $generic);
    }));
    $repo_tag   = preg_replace('/[-_.]/', '', $repo_name);
    $tags_lower = array_map('strtolower', $tags);
    if ($repo_tag && !in_array(strtolower($repo_tag), $tags_lower)) {
        $tags[] = $repo_tag;
    }
    foreach (array('AI', 'OSS', 'GitHub') as $fixed) {
        if (!in_array($fixed, $tags)) $tags[] = $fixed;
    }
    $seen = array(); $result = array();
    foreach ($tags as $t) {
        if (!in_array(strtolower($t), $seen)) {
            $seen[] = strtolower($t);
            $result[] = $t;
        }
    }
    return $result;
}

function register_oss($github_url, $DATA_FILE, $ADMIN) {
    preg_match('!github\.com/([^/]+)/([^/?#]+)!', $github_url, $m);
    if (!isset($m[1]) || !isset($m[2])) return array('status' => 'error', 'error' => 'cannot parse url');
    $user     = $m[1];
    $reponame = $m[2];
    $repo     = $user . '/' . $reponame;
    $fallback = $repo;

    $posts = array();
    if (file_exists($DATA_FILE)) {
        $posts = json_decode(file_get_contents($DATA_FILE), true);
        if (!$posts) $posts = array();
    }
    foreach ($posts as $p) {
        if ($p['github_url'] === $github_url) {
            return array('status' => 'duplicate');
        }
    }

    $repo_info = fetch_github_repo_info($user, $reponame);
    $api_title = '';
    if ($repo_info && !empty($repo_info['name'])) {
        $api_title = $repo_info['name'];
        if (!empty($repo_info['description'])) {
            $desc = trim($repo_info['description']);
            if (strlen($desc) >= 4 && strlen($desc) <= 100) {
                $api_title = $desc;
            }
        }
    }

    $readme = '';
    foreach (array('main', 'master') as $branch) {
        $raw_url = 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . '/README.md';
        $opts2   = array('http' => array('method' => 'GET', 'timeout' => 15, 'ignore_errors' => true,
                         'header' => "User-Agent: AIGMBot/1.0\r\n"));
        $content = @file_get_contents($raw_url, false, stream_context_create($opts2));
        if ($content && strpos(substr($content, 0, 100), '404') === false) {
            $readme = substr($content, 0, 2000);
            break;
        }
    }

    $readme_title = $readme ? extract_title_from_readme($readme, $fallback) : $fallback;
    $title        = $api_title ? $api_title : $readme_title;
    $context      = $readme ? 'README抜粋:' . "\n" . $readme : 'URL: ' . $github_url;

    $analysis_prompt =
        '以下のOSSについて、技術者向けに3点で簡潔に考察してください。' . "\n\n"
        . 'タイトル: ' . $title . "\n"
        . 'URL: ' . $github_url . "\n"
        . $context . "\n\n"
        . "出力形式（この形式のみで出力）：\n"
        . "■ 概要（1行）\n■ 特徴・用途（2〜3行）\n■ 結論（1行）";

    $post_prompt =
        "あなたはAI系OSSを紹介するXアカウントの中の人です。\n"
        . "以下のOSSについてX投稿文を日本語で作成してください。\n\n"
        . "ルール：\n"
        . "- 本文は100文字以内\n"
        . "- 技術的に正確、具体的な特徴を1〜2点\n"
        . "- ハッシュタグは付けない（別途自動付与します）\n"
        . "- 煽り・誇張なし\n"
        . "- URLは含めない（別途付与します）\n\n"
        . 'タイトル: ' . $title . "\n"
        . $context . "\n\n"
        . '投稿文のみ出力してください。';

    $analysis  = call_ollama($analysis_prompt);
    $post_text = call_ollama($post_prompt);
    $tags      = extract_tags($post_text, $reponame);
    $tag_str   = implode(' ', array_map(function($t){ return '#' . $t; }, $tags));
    $post_full = rtrim($post_text) . "\n" . $tag_str . "\n" . $github_url;

    $repo_id = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $user . '_' . $reponame);

    $new_post = array(
        'id'         => $repo_id,
        'author'     => $ADMIN,
        'github_url' => $github_url,
        'title'      => $title,
        'analysis'   => $analysis,
        'post_text'  => $post_full,
        'tags'       => $tags,
        'created_at' => date('Y-m-d H:i:s'),
        'timestamp'  => time()
    );

    array_unshift($posts, $new_post);

    if (file_put_contents($DATA_FILE, json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
        return array('status' => 'ok', 'title' => $title, 'id' => $repo_id);
    }
    return array('status' => 'error', 'error' => 'Failed to save');
}

/* ===== HTML出力 ===== */
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: text/html; charset=UTF-8');
header('X-Accel-Buffering: no');
echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>Zenn OSS Import</title>';
echo '<style>body{background:#111;color:#ccc;font-family:monospace;font-size:13px;padding:16px;}';
echo '#log{white-space:pre-wrap;line-height:1.6}';
echo '.ok{color:#4ade80}.err{color:#f87171}.dup{color:#555}.inf{color:#60a5fa}.dry{color:#fbbf24}.warn{color:#fb923c}';
echo 'h2{color:#fff;margin:0 0 8px}</style></head><body>';
echo '<h2>🦉 Zenn OSS Import' . ($DRY_RUN ? ' [DRY RUN]' : '') . '</h2><div id="log">';
flush();

function ln($msg, $cls='') {
    if ($cls) echo '<span class="' . $cls . '">' . htmlspecialchars($msg) . '</span>' . "\n";
    else echo htmlspecialchars($msg) . "\n";
    flush();
}

function redirect_next($url) {
    echo '</div><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '">';
    echo '</body></html>'; exit;
}

$step = isset($_GET['step']) ? $_GET['step'] : '0';

/* ================================================================
   step=0 : 初期化
================================================================ */
if ($step === '0') {
    ln('[START] ' . date('Y-m-d H:i:s'), 'inf');

    $registered = array();
    if (file_exists($DATA_FILE)) {
        $posts = json_decode(file_get_contents($DATA_FILE), true);
        if ($posts) {
            foreach ($posts as $p) {
                if (!empty($p['id'])) $registered[strtolower($p['id'])] = true;
                if (!empty($p['github_url'])) {
                    if (preg_match('#github\.com/([a-zA-Z0-9_.-]+)/([a-zA-Z0-9_.-]+)#i', $p['github_url'], $m)) {
                        $registered[strtolower($m[1].'_'.$m[2])] = true;
                    }
                }
            }
        }
    }
    ln('[INFO] 登録済み: ' . count($registered) . ' 件', 'inf');

    $users = array(); $seen = array();
    foreach (glob($KEYWORD_DIR . '/keyword_*.json') as $kf) {
        $kdata = @json_decode(file_get_contents($kf), true);
        if (!$kdata) continue;
        $sources = isset($kdata['sources']) ? $kdata['sources'] : array();
        if (!is_array($sources) || isset($sources[0])) continue;
        $zenn = isset($sources['zenn']) ? $sources['zenn'] : null;
        $zun  = ($zenn && isset($zenn['username'])) ? $zenn['username'] : '';
        if (!$zun || isset($seen[$zun])) continue;
        $seen[$zun] = true;
        $users[] = $zun;
    }
    ln('[INFO] Zennユーザー: ' . count($users) . ' 件', 'inf');

    if (empty($users)) { ln('[DONE] Zennユーザーなし', 'ok'); echo '</div></body></html>'; exit; }

    $cache = array('users' => $users, 'registered' => $registered, 'candidates' => array(),
                   'ok' => 0, 'dup' => 0, 'err' => 0);
    file_put_contents($CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE));
    redirect_next($self . '?step=rss_0' . $dry);
}

/* ================================================================
   step=rss_N : RSS取得
================================================================ */
if (preg_match('/^rss_(\d+)$/', $step, $sm)) {
    $idx   = intval($sm[1]);
    $cache = json_decode(file_get_contents($CACHE_FILE), true);
    $users = $cache['users'];
    $total = count($users);

    if ($idx >= $total) {
        $registered = $cache['registered'];
        $new_items  = array();
        foreach ($cache['candidates'] as $nid => $item) {
            if (!isset($registered[$nid])) $new_items[] = $item;
        }
        ln('');
        ln('[INFO] RSS取得完了。候補: ' . count($cache['candidates']) . ' 件 / 未登録: ' . count($new_items) . ' 件', 'inf');
        if (empty($new_items)) { ln('[DONE] 新規登録対象なし', 'ok'); echo '</div></body></html>'; exit; }
        $cache['new_items'] = array_values($new_items);
        file_put_contents($CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE));
        redirect_next($self . '?step=register_0' . $dry);
    }

    $zun      = $users[$idx];
    $rss_opts = array('http' => array('method' => 'GET', 'timeout' => $RSS_TIMEOUT, 'ignore_errors' => true,
                      'header' => "User-Agent: Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)\r\n"));
    ln(sprintf('[RSS %d/%d] %s', $idx+1, $total, $zun), 'inf');

    $raw = @file_get_contents('https://zenn.dev/' . rawurlencode($zun) . '/feed', false, stream_context_create($rss_opts));
    if (!$raw) {
        ln('  取得失敗', 'warn');
    } else {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        if (!$xml || !isset($xml->channel->item)) {
            ln('  RSS解析失敗', 'warn');
        } else {
            $found = 0;
            foreach ($xml->channel->item as $item) {
                $text = (string)$item->title . ' ' . strip_tags((string)$item->description);
                preg_match_all('#https?://github\.com/([a-zA-Z0-9_.-]+)/([a-zA-Z0-9_.-]+)#i', $text, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $user = $m[1]; $repo = rtrim($m[2], '.');
                    if (strlen($user) < 2 || strlen($repo) < 2) continue;
                    if (in_array(strtolower($user), $SKIP_USERS)) continue;
                    if (in_array(strtolower($repo), $SKIP_REPOS)) continue;
                    $nid  = strtolower($user . '_' . $repo);
                    if (!isset($cache['candidates'][$nid])) {
                        $cache['candidates'][$nid] = array('nid' => $nid, 'url' => 'https://github.com/'.$user.'/'.$repo, 'title' => (string)$item->title);
                        $found++;
                    }
                }
            }
            ln('  新規URL: ' . $found . ' 件（累計: ' . count($cache['candidates']) . ' 件）');
        }
    }

    file_put_contents($CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE));
    redirect_next($self . '?step=rss_' . ($idx+1) . $dry);
}

/* ================================================================
   step=register_N : 1件登録
================================================================ */
if (preg_match('/^register_(\d+)$/', $step, $sm)) {
    $idx   = intval($sm[1]);
    $cache = json_decode(file_get_contents($CACHE_FILE), true);
    $items = isset($cache['new_items']) ? $cache['new_items'] : array();
    $total = count($items);

    if ($idx >= $total) {
        ln(sprintf('[DONE] 完了: 登録=%d 重複=%d エラー=%d', $cache['ok'], $cache['dup'], $cache['err']), 'ok');
        echo '</div></body></html>'; exit;
    }

    $item = $items[$idx];
    ln(sprintf('[登録 %d/%d] %s', $idx+1, $total, $item['url']));
    ln('  ' . mb_substr($item['title'], 0, 80));

    if ($DRY_RUN) {
        ln('  [DRY_RUN]', 'dry');
        redirect_next($self . '?step=register_' . ($idx+1) . $dry);
    }

    $res = register_oss($item['url'], $DATA_FILE, $ADMIN);
    $st  = $res['status'];

    if ($st === 'ok' || $st === 'updated') {
        ln('  OK: ' . $res['title'], 'ok');
        $cache['registered'][$item['nid']] = true;
        $cache['ok']++;
    } elseif ($st === 'duplicate') {
        ln('  重複', 'dup');
        $cache['registered'][$item['nid']] = true;
        $cache['dup']++;
    } else {
        ln('  ERR: ' . (isset($res['error']) ? $res['error'] : '不明'), 'err');
        $cache['err']++;
    }

    file_put_contents($CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE));
    redirect_next($self . '?step=register_' . ($idx+1));
}

ln('[ERR] 不明なstep: ' . $step, 'err');
echo '</div></body></html>';