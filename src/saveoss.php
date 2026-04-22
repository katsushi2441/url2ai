<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$ADMIN     = 'xb_bittensor';
$DATA_DIR  = __DIR__ . '/data';
$DATA_FILE = $DATA_DIR . '/oss_posts.json'; // 旧形式（移行用）

function oss_post_file($id) {
    global $DATA_DIR;
    return $DATA_DIR . '/oss_' . preg_replace('/[^a-zA-Z0-9\-_]/', '-', $id) . '.json';
}

function oss_load_all_posts() {
    global $DATA_DIR, $DATA_FILE;
    $posts = array();
    $files = glob($DATA_DIR . '/oss_*.json');
    if ($files) {
        foreach ($files as $f) {
            $p = json_decode(file_get_contents($f), true);
            if (is_array($p) && !empty($p['id'])) {
                $posts[] = $p;
            }
        }
    }
    /* 旧形式の一括ファイルが残っている場合も取り込む（移行用） */
    if (file_exists($DATA_FILE)) {
        $old = json_decode(file_get_contents($DATA_FILE), true);
        if (is_array($old)) {
            $existing_ids = array();
            foreach ($posts as $p) { $existing_ids[$p['id']] = true; }
            foreach ($old as $p) {
                if (is_array($p) && !empty($p['id']) && !isset($existing_ids[$p['id']])) {
                    $posts[] = $p;
                }
            }
        }
    }
    return $posts;
}

function oss_save_post($post) {
    $file = oss_post_file($post['id']);
    $json = json_encode($post, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents($file, $json, LOCK_EX) !== false;
}

function oss_delete_post($id) {
    $file = oss_post_file($id);
    if (file_exists($file)) {
        return unlink($file);
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'POST only'));
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid JSON'));
    exit;
}

$action = isset($input['action']) ? $input['action'] : '';

// ========== 全件削除 ==========
if ($action === 'deleteall') {
    $files = glob($DATA_DIR . '/oss_*.json');
    $count = $files ? count($files) : 0;
    if ($files) {
        foreach ($files as $f) { @unlink($f); }
    }
    /* 旧形式ファイルも消す */
    if (file_exists($DATA_FILE)) { @unlink($DATA_FILE); $count++; }
    echo json_encode(array('status' => 'ok', 'deleted' => $count));
    exit;
}

// ========== 1件削除 ==========
if ($action === 'delete') {
    $target_id = isset($input['id']) ? trim($input['id']) : '';
    if (!$target_id) {
        http_response_code(400);
        echo json_encode(array('error' => 'id required'));
        exit;
    }
    if (!oss_delete_post($target_id)) {
        http_response_code(404);
        echo json_encode(array('error' => 'post not found'));
        exit;
    }
    echo json_encode(array('status' => 'ok', 'id' => $target_id));
    exit;
}

// ========== Ollama呼び出し ==========
function call_ollama($prompt) {
    $payload = json_encode(
        array('model' => OLLAMA_MODEL, 'prompt' => $prompt, 'stream' => false),
        JSON_UNESCAPED_UNICODE
    );
    $opts = array(
        'http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 120,
            'ignore_errors' => true,
        )
    );
    $ctx = stream_context_create($opts);
    $res = @file_get_contents(OLLAMA_API, false, $ctx);
    if (!$res) return '';
    $data     = json_decode($res, true);
    $response = isset($data['response']) ? $data['response'] : '';
    $lines    = explode("\n", $response);
    $trimmed  = array();
    foreach ($lines as $l) { $trimmed[] = trim($l); }
    return trim(implode("\n", $trimmed));
}

// ========== タイトル抽出（改善版） ==========
function clean_title_line($line) {
    // HTMLエンティティをデコード
    $line = html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // HTMLタグ除去
    $line = strip_tags($line);
    // Markdownリンク展開 [text](url) -> text
    $line = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $line);
    // Markdownの#見出し記号除去
    $line = ltrim($line, '# ');
    // 前後空白除去
    $line = trim($line);
    return $line;
}

function extract_title_from_readme($readme, $fallback) {
    foreach (explode("\n", $readme) as $line) {
        $raw  = trim($line);
        if (!$raw) continue;

        // バッジ・シールド行をスキップ
        if (strpos($raw, 'shields.io') !== false) continue;
        if (stripos($raw, 'badge') !== false) continue;
        // テーブル行をスキップ
        if (strpos($raw, '|') !== false) continue;
        // 画像行をスキップ
        if ($raw[0] === '!') continue;
        // 引用行をスキップ
        if ($raw[0] === '>') continue;
        // リスト行をスキップ
        if ($raw[0] === '-' || $raw[0] === '*') continue;

        $cleaned = clean_title_line($raw);
        if (!$cleaned) continue;
        if (strlen($cleaned) < 2 || strlen($cleaned) > 120) continue;
        // エンティティが残っていたらスキップ（&middot;等の取り残し）
        if (strpos($cleaned, '&') !== false && strpos($cleaned, ';') !== false) continue;

        return $cleaned;
    }
    return $fallback;
}

// ========== GitHub APIでリポジトリ情報取得 ==========
function fetch_github_repo_info($user, $reponame) {
    $api_url = 'https://api.github.com/repos/' . $user . '/' . $reponame;
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "User-Agent: AIGMBot/1.0\r\nAccept: application/vnd.github.v3+json\r\n",
        'timeout'       => 10,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($api_url, false, stream_context_create($opts));
    if (!$res) return null;
    return json_decode($res, true);
}

// ========== タグ抽出 ==========
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
    $seen   = array();
    $result = array();
    foreach ($tags as $t) {
        if (!in_array(strtolower($t), $seen)) {
            $seen[]   = strtolower($t);
            $result[] = $t;
        }
    }
    return $result;
}

// ========== Ollama動作確認 ==========
if ($action === 'test_ollama') {
    $prompt = "あなたはAI系OSSを紹介するXアカウントの中の人です。\n以下のOSSについてX投稿文を日本語で作成してください。\n\nタイトル: テスト\n\n投稿文のみ出力してください。";
    $result = call_ollama($prompt);
    echo json_encode(array('result' => $result), JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 手動登録 ==========
if ($action === 'manual_register') {
    session_start();
    $session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
    if ($session_user !== $ADMIN) {
        http_response_code(403);
        echo json_encode(array('error' => 'unauthorized'));
        exit;
    }

    $github_url = isset($input['github_url']) ? trim($input['github_url']) : '';
    if (!$github_url || strpos($github_url, 'github.com/') === false) {
        http_response_code(400);
        echo json_encode(array('error' => 'invalid github_url'));
        exit;
    }

    $m = array();
    preg_match('!github\\.com/([^/]+)/([^/?#]+)!', $github_url, $m);
    if (!isset($m[1]) || !isset($m[2])) {
        http_response_code(400);
        echo json_encode(array('error' => 'cannot parse github url'));
        exit;
    }
    $user     = $m[1];
    $reponame = $m[2];
    $repo     = $user . '/' . $reponame;
    $fallback = $repo;

    /* 1. GitHub APIでリポジトリ名取得（最も信頼性が高い） */
    $repo_info = fetch_github_repo_info($user, $reponame);
    $api_title = '';
    if ($repo_info && !empty($repo_info['name'])) {
        $api_title = $repo_info['name'];
        /* descriptionがあればそちらを優先 */
        if (!empty($repo_info['description'])) {
            $desc = trim($repo_info['description']);
            if (strlen($desc) >= 4 && strlen($desc) <= 100) {
                $api_title = $desc;
            }
        }
    }

    /* 2. READMEからタイトル抽出（APIで取れなかった場合のフォールバック） */
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

    /* 3. 最終タイトル決定：API > README > fallback */
    if ($api_title) {
        $title = $api_title;
    } else {
        $title = $readme_title;
    }

    $context = '';
    if ($readme) {
        $context = 'README抜粋:' . "\n" . $readme;
    } else {
        $context = 'URL: ' . $github_url;
    }

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

    $repo_id = $user . '_' . $reponame;
    $repo_id = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $repo_id);

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

    /* github_url の重複チェック（既存なら上書き） */
    $found = false;
    foreach (oss_load_all_posts() as $p) {
        if (isset($p['github_url']) && $p['github_url'] === $github_url) {
            $found = true;
            break;
        }
    }

    if (!oss_save_post($new_post)) {
        http_response_code(500);
        echo json_encode(array('error' => 'Failed to save'));
        exit;
    }
    $status = $found ? 'updated' : 'ok';
    echo json_encode(array('status' => $status, 'id' => $repo_id, 'title' => $title), JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== Paragraph URL更新（Pythonワーカーから） ==========
if ($action === 'paragraph_update') {
    $post_id      = isset($input['id'])            ? trim($input['id'])            : '';
    $para_url     = isset($input['paragraph_url']) ? trim($input['paragraph_url']) : '';
    if (!$post_id || !$para_url) {
        http_response_code(400);
        echo json_encode(array('error' => 'id and paragraph_url required'));
        exit;
    }
    $posts = array();
    if (file_exists($DATA_FILE)) {
        $posts = json_decode(file_get_contents($DATA_FILE), true);
        if (!$posts) $posts = array();
    }
    $found = false;
    foreach ($posts as &$p) {
        if ($p['id'] === $post_id) {
            $p['paragraph_url'] = $para_url;
            $found = true;
            break;
        }
    }
    unset($p);
    if (!$found) {
        http_response_code(404);
        echo json_encode(array('error' => 'post not found'));
        exit;
    }
    file_put_contents($DATA_FILE, json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(array('status' => 'ok'));
    exit;
}

// ========== Paragraphへ投稿（管理画面から） ==========
if ($action === 'paragraph_post') {
    session_start();
    $session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
    if ($session_user !== $ADMIN) {
        http_response_code(403);
        echo json_encode(array('error' => 'unauthorized'));
        exit;
    }
    $post_id = isset($input['id']) ? trim($input['id']) : '';
    if (!$post_id) {
        http_response_code(400);
        echo json_encode(array('error' => 'id required'));
        exit;
    }
    if (!PARAGRAPH_API_KEY) {
        http_response_code(500);
        echo json_encode(array('error' => 'PARAGRAPH_API_KEY not configured'));
        exit;
    }
    $posts = array();
    if (file_exists($DATA_FILE)) {
        $posts = json_decode(file_get_contents($DATA_FILE), true);
        if (!$posts) $posts = array();
    }
    $target = null;
    foreach ($posts as $p) {
        if ($p['id'] === $post_id) { $target = $p; break; }
    }
    if (!$target) {
        http_response_code(404);
        echo json_encode(array('error' => 'post not found'));
        exit;
    }

    // Ollamaで記事生成
    $title   = isset($target['title'])      ? $target['title']      : '';
    $github  = isset($target['github_url']) ? $target['github_url'] : '';
    $context = isset($target['analysis'])   ? $target['analysis']   : '';

    $title_prompt = "Write one concise English title for a technical OSS article on Paragraph.xyz.\n- 90 characters or fewer\n- Clear and technical, not clickbait\n- Output title only\n\nOSS: {$title}\nGitHub: {$github}";
    $para_title = call_ollama($title_prompt);
    if (!$para_title) $para_title = $title;

    $content_prompt = "Write an English Markdown article about the following OSS for engineers (500-900 words).\nSections: ## What It Does / ## Why It Matters / ## Key Technical Points / ## When To Use It / ## Final Thoughts\nAdd GitHub link near end. No hashtags. Calm technical tone.\n\nOSS: {$title}\nGitHub: {$github}\nContext:\n{$context}";
    $para_content = call_ollama($content_prompt);
    if (!$para_content) {
        http_response_code(500);
        echo json_encode(array('error' => 'Ollama content generation failed'));
        exit;
    }

    // Paragraph API呼び出し
    $payload = json_encode(array(
        'title'    => $para_title,
        'markdown' => $para_content,
        'status'   => 'published',
    ), JSON_UNESCAPED_UNICODE);
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => "Authorization: Bearer " . PARAGRAPH_API_KEY . "\r\nContent-Type: application/json\r\n",
        'content'       => $payload,
        'timeout'       => 60,
        'ignore_errors' => true,
    ));
    $res     = @file_get_contents('https://public.api.paragraph.com/api/v1/posts', false, stream_context_create($opts));
    $res_arr = $res ? json_decode($res, true) : array();
    $para_url = isset($res_arr['url']) ? $res_arr['url'] : (isset($res_arr['id']) ? $res_arr['id'] : '');

    if (!$para_url) {
        http_response_code(500);
        echo json_encode(array('error' => 'Paragraph API failed', 'detail' => $res_arr));
        exit;
    }

    // paragraph_urlをJSONに保存
    foreach ($posts as &$p) {
        if ($p['id'] === $post_id) {
            $p['paragraph_url'] = $para_url;
            break;
        }
    }
    unset($p);
    file_put_contents($DATA_FILE, json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(array('status' => 'ok', 'paragraph_url' => $para_url), JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 重複チェック ==========
if ($action === 'check') {
    $url = isset($input['github_url']) ? trim($input['github_url']) : '';
    if (!$url) {
        http_response_code(400);
        echo json_encode(array('error' => 'github_url required'));
        exit;
    }
    $posts = array();
    if (file_exists($DATA_FILE)) {
        $posts = json_decode(file_get_contents($DATA_FILE), true);
        if (!$posts) $posts = array();
    }
    foreach ($posts as $p) {
        if ($p['github_url'] === $url) {
            echo json_encode(array('exists' => true));
            exit;
        }
    }
    echo json_encode(array('exists' => false));
    exit;
}

// ========== 登録（Pythonワーカーから・actionなし） ==========
if ($action !== '') {
    http_response_code(400);
    echo json_encode(array('error' => 'unknown action: ' . $action));
    exit;
}

$github_url = isset($input['github_url']) ? trim($input['github_url']) : '';
$title      = isset($input['title'])      ? trim($input['title'])      : '';
$analysis   = isset($input['analysis'])   ? trim($input['analysis'])   : '';
$post_text  = isset($input['post_text'])  ? trim($input['post_text'])  : '';
$tags       = isset($input['tags'])       ? $input['tags']             : array();

if (!$github_url || !$title) {
    http_response_code(400);
    echo json_encode(array('error' => 'github_url and title required'));
    exit;
}

foreach (oss_load_all_posts() as $p) {
    if (isset($p['github_url']) && $p['github_url'] === $github_url) {
        echo json_encode(array('status' => 'duplicate', 'message' => 'already exists'));
        exit;
    }
}

$m = array();
preg_match('!github\\.com/([^/]+)/([^/?]+)!', $github_url, $m);
if (isset($m[1]) && isset($m[2])) {
    $repo_id = $m[1] . '_' . $m[2];
    $repo_id = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $repo_id);
} else {
    $repo_id = uniqid('oss_', true);
}

$post = array(
    'id'         => $repo_id,
    'author'     => $ADMIN,
    'github_url' => $github_url,
    'title'      => $title,
    'analysis'   => $analysis,
    'post_text'  => $post_text,
    'tags'       => $tags,
    'created_at' => date('Y-m-d H:i:s'),
    'timestamp'  => time()
);

if (oss_save_post($post)) {
    echo json_encode(array('status' => 'ok', 'id' => $post['id']));
} else {
    http_response_code(500);
    echo json_encode(array('error' => 'Failed to save'));
}
?>
