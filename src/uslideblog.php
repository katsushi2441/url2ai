<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');

if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', AIGM_COOKIE_DOMAIN);
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), time() + $session_lifetime, '/', AIGM_COOKIE_DOMAIN, true, true);
    }
}

$BASE_URL = AIGM_BASE_URL;
$THIS_FILE = 'uslideblog.php';
$SITE_NAME = 'USlideBlog';
$ADMIN = AIGM_ADMIN;
$DATA_DIR = __DIR__ . '/data/uslideblog';
$DEFAULT_IMAGE = $BASE_URL . '/images/url2ai-agent.svg';
$RENDERER_API = (isset($_aigm_config['uslideblog']['renderer_api']) && $_aigm_config['uslideblog']['renderer_api'] !== '') ? $_aigm_config['uslideblog']['renderer_api'] : 'http://exbridge.ddns.net:8022';
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0755, true); }

/* =========================================================
   X OAuth login
========================================================= */
$x_keys_file = __DIR__ . '/x_api_keys.sh';
$x_keys = array();
if (file_exists($x_keys_file)) {
    $lines = file($x_keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m)) {
            $x_keys[trim($m[1])] = trim($m[2]);
        }
    }
}
$x_client_id = isset($x_keys['X_API_KEY']) ? $x_keys['X_API_KEY'] : '';
$x_client_secret = isset($x_keys['X_API_SECRET']) ? $x_keys['X_API_SECRET'] : '';
$x_redirect_uri = $BASE_URL . '/' . $THIS_FILE;

function usb_base64url($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function usb_gen_verifier() { $b = ''; for ($i = 0; $i < 32; $i++) { $b .= chr(mt_rand(0, 255)); } return usb_base64url($b); }
function usb_gen_challenge($v) { return usb_base64url(hash('sha256', $v, true)); }
function usb_http_post_form($url, $data, $headers) {
    $opts = array('http' => array('method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n", 'content' => $data, 'timeout' => 12, 'ignore_errors' => true));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return json_decode($res ? $res : '{}', true);
}
function usb_x_get($url, $token) {
    $opts = array('http' => array('method' => 'GET', 'header' => "Authorization: Bearer " . $token . "\r\nUser-Agent: USlideBlog/1.0\r\n", 'timeout' => 12, 'ignore_errors' => true));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return json_decode($res ? $res : '{}', true);
}

if (isset($_GET['usb_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/', AIGM_COOKIE_DOMAIN, true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['usb_login'])) {
    $verifier = usb_gen_verifier();
    $challenge = usb_gen_challenge($verifier);
    $state = md5(uniqid('', true));
    $_SESSION['usb_code_verifier'] = $verifier;
    $_SESSION['usb_oauth_state'] = $state;
    $params = array(
        'response_type' => 'code',
        'client_id' => $x_client_id,
        'redirect_uri' => $x_redirect_uri,
        'scope' => 'tweet.read users.read offline.access',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    );
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($params));
    exit;
}
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['usb_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['usb_oauth_state']) {
        $post = http_build_query(array(
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $x_redirect_uri,
            'code_verifier' => $_SESSION['usb_code_verifier'],
            'client_id' => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = usb_http_post_form('https://api.twitter.com/2/oauth2/token', $post, array('Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . $cred));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token'] = $data['access_token'];
            $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) { $_SESSION['session_refresh_token'] = $data['refresh_token']; }
            $me = usb_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) { $_SESSION['session_username'] = $me['data']['username']; }
        }
        unset($_SESSION['usb_oauth_state'], $_SESSION['usb_code_verifier']);
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

$logged_in = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin = ($session_user === $ADMIN);

/* =========================================================
   Helpers
========================================================= */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function x($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
function usb_slug($text) {
    $text = mb_strtolower((string)$text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    $text = trim($text, '-');
    if ($text === '') { $text = 'slideblog'; }
    return mb_substr($text, 0, 60, 'UTF-8');
}
function usb_id($title) {
    return date('YmdHis') . '-' . usb_slug($title);
}
function usb_file($id) {
    global $DATA_DIR;
    $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '-', (string)$id);
    return $DATA_DIR . '/' . $safe . '.json';
}
function usb_load($id) {
    $file = usb_file($id);
    if (!file_exists($file)) { return null; }
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : null;
}
function usb_save($post) {
    $file = usb_file($post['id']);
    file_put_contents($file, json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
function usb_all_posts() {
    global $DATA_DIR;
    $posts = array();
    $files = glob($DATA_DIR . '/*.json');
    if ($files) {
        foreach ($files as $file) {
            $d = json_decode(file_get_contents($file), true);
            if (!is_array($d) || empty($d['id'])) { continue; }
            $posts[] = $d;
        }
    }
    usort($posts, function($a, $b) {
        $ta = isset($a['created_at']) ? $a['created_at'] : '';
        $tb = isset($b['created_at']) ? $b['created_at'] : '';
        return strcmp($tb, $ta);
    });
    return $posts;
}
function usb_excerpt($text, $len) {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if (mb_strlen($text, 'UTF-8') <= $len) { return $text; }
    return mb_substr($text, 0, $len, 'UTF-8') . '...';
}
function usb_abs_url($url, $base) {
    $url = html_entity_decode(trim((string)$url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') { return ''; }
    if (preg_match('#^https?://#i', $url)) { return $url; }
    if (strpos($url, '//') === 0) { return 'https:' . $url; }
    $p = parse_url($base);
    if (empty($p['scheme']) || empty($p['host'])) { return ''; }
    $root = $p['scheme'] . '://' . $p['host'];
    if (strpos($url, '/') === 0) { return $root . $url; }
    $path = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $root . $path . $url;
}
function usb_fetch_url($url) {
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (compatible; USlideBlog/1.0)\r\nAccept: text/html,application/xhtml+xml,text/plain,application/pdf\r\n",
        'timeout' => 20,
        'ignore_errors' => true,
    ));
    $html = @file_get_contents($url, false, stream_context_create($opts));
    if (!$html) { return array('ok' => false, 'error' => 'URLを取得できませんでした。'); }
    if (strlen($html) > 1200000) { $html = substr($html, 0, 1200000); }
    $title = '';
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', $html, $m)) {
        $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $description = '';
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i', $html, $m)) {
        $description = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $image = '';
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
        $image = usb_abs_url($m[1], $url);
    }
    $body = $html;
    $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $body);
    $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $body);
    $body = preg_replace('/<\/(h[1-6]|p|li|pre|blockquote|section|article|div)>/i', "\n", $body);
    $body = preg_replace('/<[^>]+>/', ' ', $body);
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = preg_replace('/[ \t]+/u', ' ', $body);
    $body = preg_replace('/\R{3,}/u', "\n\n", $body);
    $body = trim($body);
    if ($title === '') { $title = $url; }
    return array('ok' => true, 'title' => mb_substr($title, 0, 140, 'UTF-8'), 'description' => mb_substr($description, 0, 220, 'UTF-8'), 'image' => $image, 'body' => mb_substr($body, 0, 7000, 'UTF-8'));
}
function usb_ollama($prompt) {
    $payload = json_encode(array(
        'model' => OLLAMA_MODEL,
        'prompt' => $prompt,
        'stream' => false,
        'options' => array('num_ctx' => 4096, 'temperature' => 0.45, 'top_k' => 40, 'top_p' => 0.9),
    ));
    $opts = array('http' => array('method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 90, 'ignore_errors' => true));
    $res = @file_get_contents(OLLAMA_API, false, stream_context_create($opts));
    if (!$res) { return ''; }
    $d = json_decode($res, true);
    return isset($d['response']) ? trim($d['response']) : '';
}
function usb_parse_json_response($text) {
    $text = trim((string)$text);
    if ($text === '') { return null; }
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $text, $m)) { $text = $m[1]; }
    else if (preg_match('/(\{.*\})/is', $text, $m)) { $text = $m[1]; }
    $d = json_decode($text, true);
    return is_array($d) ? $d : null;
}
function usb_default_tags($title, $body) {
    $src = $title . ' ' . $body;
    $map = array(
        'VibeCoding' => array('バイブ', 'vibe', 'vibecoding'),
        'ClaudeCode' => array('Claude Code', 'claude'),
        'Codex' => array('Codex', 'codex'),
        'Ollama' => array('Ollama', 'ollama'),
        'Gemma' => array('Gemma', 'gemma'),
        'OSS' => array('OSS', 'GitHub', 'README'),
        'AIAgent' => array('agent', 'エージェント'),
        'GPU' => array('GPU', 'CUDA'),
        'AI' => array('AI', 'LLM', '人工知能'),
    );
    $tags = array();
    foreach ($map as $tag => $words) {
        foreach ($words as $w) {
            if (mb_stripos($src, $w, 0, 'UTF-8') !== false) { $tags[] = $tag; break; }
        }
    }
    if (!$tags) { $tags = array('AI', 'Tech'); }
    return $tags;
}
function usb_fallback_slides($title, $description, $body) {
    $excerpt = usb_excerpt($body, 220);
    return array(
        array('title' => $title, 'body' => ($description !== '' ? $description : $excerpt), 'note' => 'URLから抽出した内容の概要です。', 'layout' => 'cover'),
        array('title' => 'この技術で何ができる？', 'body' => $excerpt, 'note' => '読者が得られる価値を整理します。', 'layout' => 'points'),
        array('title' => '構成と仕組み', 'body' => '入力、処理、出力の流れを分解し、どこでAIや自動化が効いているかを確認します。', 'note' => '', 'layout' => 'diagram'),
        array('title' => '実装ポイント', 'body' => '導入時に見るべきAPI、設定、データ構造、運用上の注意点を整理します。', 'note' => '', 'layout' => 'code'),
        array('title' => '活用方法', 'body' => '既存業務や開発フローにどう組み込めるか、具体的な利用シーンに落とし込みます。', 'note' => '', 'layout' => 'points'),
        array('title' => '注意点', 'body' => '制約、ライセンス、セキュリティ、品質確認、運用コストを事前に確認します。', 'note' => '', 'layout' => 'warn'),
        array('title' => 'まとめ', 'body' => '技術の価値、導入の第一歩、次に読むべき関連情報をまとめます。', 'note' => '', 'layout' => 'summary'),
    );
}
function usb_build_prompt($source) {
    $body = mb_substr($source['body'], 0, 3600, 'UTF-8');
    return "以下のURL本文を、原文の主張を崩さずに日本語のスライド型ブログへ変換してください。\n\n最重要条件:\n- 原文に書かれていない論点、サービス名、導入手順、注意点を勝手に追加しない\n- 原文の語り手の主張、熱量、結論を維持する\n- 要約しすぎて一般論にしない\n- 各スライドは、原文の流れに沿って分割する\n- 具体例がある場合は必ず残す\n- 断定の強さを弱めすぎない\n\n構成条件:\n- 5〜7枚のスライドにする\n- 各スライドは title, body, note, layout を持つ\n- body は原文の意味を忠実に保った2〜4文程度\n- tags は 3〜8個。例: VibeCoding, ClaudeCode, Cursor, Codex, v0, AI, BusinessAutomation\n- JSONのみを返す。説明文やMarkdownフェンスは禁止\n\n出力JSON形式:\n{\"title\":\"...\",\"description\":\"...\",\"tags\":[\"...\"],\"slides\":[{\"title\":\"...\",\"body\":\"...\",\"note\":\"...\",\"layout\":\"cover\"}]}\n\nURL: " . $source['url'] . "\nタイトル: " . $source['title'] . "\n説明: " . $source['description'] . "\n本文抜粋:\n" . $body;
}
function usb_markdown($post) {
    $out = '# ' . $post['title'] . "\n\n";
    $out .= isset($post['description']) ? $post['description'] . "\n\n" : '';
    $out .= 'Source: ' . (isset($post['source_url']) ? $post['source_url'] : '') . "\n\n";
    if (!empty($post['tags'])) { $out .= 'Tags: ' . implode(', ', $post['tags']) . "\n\n"; }
    foreach ($post['slides'] as $i => $s) {
        $out .= '## ' . ($i + 1) . '. ' . (isset($s['title']) ? $s['title'] : '') . "\n\n";
        $out .= (isset($s['body']) ? $s['body'] : '') . "\n\n";
        if (!empty($s['note'])) { $out .= '> ' . $s['note'] . "\n\n"; }
    }
    return $out;
}
function usb_renderer_request($path, $post) {
    global $RENDERER_API;
    $url = rtrim($RENDERER_API, '/') . $path;
    $payload = json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $opts = array('http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 90,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return $res ? $res : '';
}

/* =========================================================
   RSS / Downloads
========================================================= */
$posts = usb_all_posts();
if (isset($_GET['feed'])) {
    header('Access-Control-Allow-Origin: https://exbridge.jp');
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n";
    echo '<title>' . x($SITE_NAME . ' | AIスライド技術ブログ') . '</title>' . "\n";
    echo '<link>' . x($BASE_URL . '/' . $THIS_FILE) . '</link>' . "\n";
    echo '<description>' . x('技術解説URLをAIがスライドブログ化した記事一覧。') . '</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . x($BASE_URL . '/' . $THIS_FILE . '?feed') . '" rel="self" type="application/rss+xml"/>' . "\n";
    foreach (array_slice($posts, 0, 30) as $p) {
        if (isset($p['published']) && !$p['published'] && !$is_admin) { continue; }
        $link = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($p['id']);
        echo '<item><title>' . x($p['title']) . '</title><link>' . x($link) . '</link><guid isPermaLink="true">' . x($link) . '</guid>';
        if (!empty($p['created_at'])) { echo '<pubDate>' . date('r', strtotime($p['created_at'])) . '</pubDate>'; }
        echo '<description>' . x(isset($p['description']) ? $p['description'] : '') . '</description></item>' . "\n";
    }
    echo '</channel></rss>';
    exit;
}

$detail_id = isset($_GET['id']) ? trim($_GET['id']) : '';
$detail = $detail_id !== '' ? usb_load($detail_id) : null;
if ($detail && isset($_GET['format'])) {
    $fmt = $_GET['format'];
    if ($fmt === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $detail['id'] . '.json"');
        echo json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    if ($fmt === 'md') {
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $detail['id'] . '.md"');
        echo usb_markdown($detail);
        exit;
    }
    if ($fmt === 'html') {
        $api = usb_renderer_request('/api/uslideblog/marp-html', $detail);
        $api_json = $api !== '' ? json_decode($api, true) : null;
        if (is_array($api_json) && !empty($api_json['ok']) && !empty($api_json['html'])) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $detail['id'] . '.html"');
            echo $api_json['html'];
            exit;
        }
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $detail['id'] . '.html"');
        echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>' . h($detail['title']) . '</title><body>' . "\n";
        echo '<h1>' . h($detail['title']) . '</h1><p>' . h(isset($detail['description']) ? $detail['description'] : '') . '</p>';
        foreach ($detail['slides'] as $s) {
            echo '<section><h2>' . h(isset($s['title']) ? $s['title'] : '') . '</h2><p>' . nl2br(h(isset($s['body']) ? $s['body'] : '')) . '</p></section>';
        }
        echo '</body></html>';
        exit;
    }
    if ($fmt === 'pptx') {
        $pptx = usb_renderer_request('/api/uslideblog/pptx', $detail);
        if ($pptx !== '') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
            header('Content-Disposition: attachment; filename="' . $detail['id'] . '.pptx"');
            echo $pptx;
            exit;
        }
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PPTX renderer is not running. Start apps/uslideblog on port 8022.';
        exit;
    }
}

/* =========================================================
   POST actions
========================================================= */
$flash = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if (!$is_admin) {
        $error = '生成・編集には管理者ログインが必要です。';
    } else if ($action === 'generate') {
        @set_time_limit(300);
        $url = isset($_POST['source_url']) ? trim($_POST['source_url']) : '';
        if (!preg_match('#^https?://#i', $url)) {
            $error = 'URLを入力してください。';
        } else {
            $src = usb_fetch_url($url);
            if (empty($src['ok'])) {
                $error = isset($src['error']) ? $src['error'] : 'URL解析に失敗しました。';
            } else {
                $src['url'] = $url;
                $ai = usb_ollama(usb_build_prompt($src));
                $parsed = usb_parse_json_response($ai);
                $title = ($parsed && !empty($parsed['title'])) ? $parsed['title'] : $src['title'];
                $desc = ($parsed && !empty($parsed['description'])) ? $parsed['description'] : ($src['description'] !== '' ? $src['description'] : usb_excerpt($src['body'], 160));
                $slides = ($parsed && !empty($parsed['slides']) && is_array($parsed['slides'])) ? $parsed['slides'] : usb_fallback_slides($title, $desc, $src['body']);
                $tags = ($parsed && !empty($parsed['tags']) && is_array($parsed['tags'])) ? $parsed['tags'] : usb_default_tags($title, $src['body']);
                $id = usb_id($title);
                $post = array(
                    'id' => $id,
                    'title' => $title,
                    'description' => $desc,
                    'source_url' => $url,
                    'source_title' => $src['title'],
                    'image' => $src['image'],
                    'tags' => array_values($tags),
                    'slides' => array_values($slides),
                    'theme' => isset($_POST['theme']) ? trim($_POST['theme']) : 'blue',
                    'published' => !empty($_POST['published']) ? 1 : 0,
                    'views' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_by' => $session_user,
                    'raw_excerpt' => usb_excerpt($src['body'], 1400),
                );
                usb_save($post);
                header('Location: ' . $THIS_FILE . '?id=' . urlencode($id) . '&edit=1');
                exit;
            }
        }
    } else if ($action === 'save') {
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $post = usb_load($id);
        if (!$post) {
            $error = '保存対象が見つかりません。';
        } else {
            $post['title'] = isset($_POST['title']) ? trim($_POST['title']) : $post['title'];
            $post['description'] = isset($_POST['description']) ? trim($_POST['description']) : '';
            $post['source_url'] = isset($_POST['source_url']) ? trim($_POST['source_url']) : '';
            $post['image'] = isset($_POST['image']) ? trim($_POST['image']) : '';
            $post['theme'] = isset($_POST['theme']) ? trim($_POST['theme']) : 'blue';
            $post['published'] = !empty($_POST['published']) ? 1 : 0;
            $tag_text = isset($_POST['tags']) ? trim($_POST['tags']) : '';
            $tags = preg_split('/[,、\s]+/u', $tag_text);
            $clean_tags = array();
            foreach ($tags as $t) { $t = trim($t, "# \t\r\n"); if ($t !== '') { $clean_tags[] = $t; } }
            $post['tags'] = $clean_tags;
            $slides = array();
            $titles = isset($_POST['slide_title']) && is_array($_POST['slide_title']) ? $_POST['slide_title'] : array();
            $bodies = isset($_POST['slide_body']) && is_array($_POST['slide_body']) ? $_POST['slide_body'] : array();
            $notes = isset($_POST['slide_note']) && is_array($_POST['slide_note']) ? $_POST['slide_note'] : array();
            $layouts = isset($_POST['slide_layout']) && is_array($_POST['slide_layout']) ? $_POST['slide_layout'] : array();
            for ($i = 0; $i < count($titles); $i++) {
                $st = trim($titles[$i]);
                $sb = isset($bodies[$i]) ? trim($bodies[$i]) : '';
                if ($st === '' && $sb === '') { continue; }
                $slides[] = array(
                    'title' => $st,
                    'body' => $sb,
                    'note' => isset($notes[$i]) ? trim($notes[$i]) : '',
                    'layout' => isset($layouts[$i]) ? trim($layouts[$i]) : 'points',
                );
            }
            if ($slides) { $post['slides'] = $slides; }
            $post['updated_at'] = date('Y-m-d H:i:s');
            usb_save($post);
            header('Location: ' . $THIS_FILE . '?id=' . urlencode($id) . '&edit=1&saved=1');
            exit;
        }
    } else if ($action === 'delete') {
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $file = usb_file($id);
        if ($id !== '' && file_exists($file)) { @unlink($file); }
        header('Location: ' . $THIS_FILE);
        exit;
    }
}

if ($detail && empty($_GET['edit'])) {
    $detail['views'] = isset($detail['views']) ? ((int)$detail['views'] + 1) : 1;
    usb_save($detail);
}

/* =========================================================
   Page metadata
========================================================= */
$tag_filter = isset($_GET['tag']) ? trim($_GET['tag']) : '';
if ($tag_filter !== '') {
    $filtered = array();
    foreach ($posts as $p) {
        $tags = isset($p['tags']) && is_array($p['tags']) ? $p['tags'] : array();
        if (in_array($tag_filter, $tags)) { $filtered[] = $p; }
    }
    $posts = $filtered;
}

if ($detail) {
    $page_title = $detail['title'] . ' | ' . $SITE_NAME;
    $page_description = isset($detail['description']) ? $detail['description'] : '';
    $page_url = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail['id']);
    $page_type = 'article';
    $page_image = !empty($detail['image']) ? $detail['image'] : $DEFAULT_IMAGE;
} else {
    $page_title = $tag_filter !== '' ? '#' . $tag_filter . ' のスライドブログ | ' . $SITE_NAME : $SITE_NAME . ' | AIスライド技術ブログ';
    $page_description = '技術解説URLをAIが解析し、編集可能なスライド型技術ブログへ変換するURL2AIシリーズのWebシステムです。';
    $page_url = $BASE_URL . '/' . $THIS_FILE . ($tag_filter !== '' ? '?tag=' . urlencode($tag_filter) : '');
    $page_type = 'website';
    $page_image = $DEFAULT_IMAGE;
}
$jsonld = $detail ? array(
    '@context' => 'https://schema.org',
    '@type' => 'TechArticle',
    'headline' => $detail['title'],
    'description' => $page_description,
    'url' => $page_url,
    'image' => $page_image,
    'datePublished' => isset($detail['created_at']) ? $detail['created_at'] : '',
    'dateModified' => isset($detail['updated_at']) ? $detail['updated_at'] : '',
    'keywords' => isset($detail['tags']) ? implode(',', $detail['tags']) : '',
    'author' => array('@type' => 'Person', 'name' => isset($detail['created_by']) ? $detail['created_by'] : 'url2ai'),
) : array(
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $SITE_NAME,
    'description' => $page_description,
    'url' => $page_url,
);
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h(usb_excerpt($page_description, 160)); ?>">
<link rel="canonical" href="<?php echo h($page_url); ?>">
<link rel="alternate" type="application/rss+xml" title="USlideBlog RSS" href="<?php echo h($BASE_URL . '/' . $THIS_FILE . '?feed'); ?>">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h(usb_excerpt($page_description, 160)); ?>">
<meta property="og:type" content="<?php echo h($page_type); ?>">
<meta property="og:url" content="<?php echo h($page_url); ?>">
<meta property="og:image" content="<?php echo h($page_image); ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="stylesheet" href="https://unpkg.com/reveal.js@5/dist/reveal.css">
<link rel="stylesheet" href="https://unpkg.com/reveal.js@5/dist/theme/white.css">
<script type="application/ld+json"><?php echo json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?></script>
<?php if (AIGM_GTAG_ID !== ''): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo h(AIGM_GTAG_ID); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo h(AIGM_GTAG_ID); ?>');</script>
<?php endif; ?>
<style>
:root{--ink:#172033;--muted:#64748b;--line:#dbe3ef;--soft:#f6f8fb;--paper:#fff;--accent:#2563eb;--accent2:#14b8a6;--danger:#dc2626}
*{box-sizing:border-box}body{margin:0;background:var(--soft);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Yu Gothic",Meiryo,sans-serif;letter-spacing:0;line-height:1.75}a{color:inherit}.top{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.94);border-bottom:1px solid var(--line);backdrop-filter:blur(10px)}.wrap{max-width:1180px;margin:0 auto;padding:12px 18px}.bar{display:flex;align-items:center;justify-content:space-between;gap:14px}.brand{text-decoration:none;display:flex;align-items:center;gap:10px;min-width:0}.logo{width:38px;height:38px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}.brand b{display:block;font-size:19px;line-height:1}.brand span{display:block;font-size:12px;color:var(--muted);white-space:nowrap}.nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);background:#fff;color:var(--ink);text-decoration:none;border-radius:6px;padding:8px 12px;font-size:13px;font-weight:700;cursor:pointer;min-height:36px}.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn.sub{background:#eef6ff;border-color:#bfdbfe;color:#1d4ed8}.btn.danger{background:#fff1f2;border-color:#fecdd3;color:#be123c}.hero{max-width:1180px;margin:0 auto;padding:34px 18px 18px}.hero h1{font-size:34px;line-height:1.35;margin:0 0 10px}.lead{max-width:780px;color:var(--muted);font-size:15px;margin:0}.genbox{margin-top:20px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:16px}.genbox form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px}.input,.textarea,select{width:100%;border:1px solid var(--line);border-radius:6px;padding:10px 12px;background:#fff;font:inherit}.textarea{min-height:88px}.chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}.chip{display:inline-flex;border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 9px;font-size:12px;text-decoration:none;font-weight:700}.main{max-width:1180px;margin:0 auto;padding:16px 18px 54px}.msg{border-radius:8px;padding:12px 14px;margin-bottom:14px}.err{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}.ok{background:#ecfdf5;border:1px solid #bbf7d0;color:#047857}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.card{background:#fff;border:1px solid var(--line);border-radius:8px;text-decoration:none;overflow:hidden;display:flex;flex-direction:column;min-height:250px}.thumb{height:128px;background:linear-gradient(135deg,#e0f2fe,#f0fdfa);display:flex;align-items:center;justify-content:center;color:#1d4ed8;font-weight:800;text-align:center;padding:14px}.thumb img{width:100%;height:100%;object-fit:cover}.cardbody{padding:14px;display:flex;flex-direction:column;gap:8px;flex:1}.card h2{font-size:16px;line-height:1.5;margin:0}.meta{font-size:12px;color:var(--muted)}.tagrow{display:flex;flex-wrap:wrap;gap:5px}.tag{font-size:11px;color:#0f766e;background:#f0fdfa;border:1px solid #99f6e4;border-radius:999px;padding:1px 7px;text-decoration:none}.slide-shell{display:grid;grid-template-columns:220px minmax(0,1fr);gap:18px;align-items:start}.toc{position:sticky;top:76px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px}.toc a{display:block;text-decoration:none;font-size:13px;color:var(--muted);padding:7px 8px;border-radius:5px}.toc a:hover{background:#f1f5f9;color:var(--ink)}.slides{display:grid;gap:16px}.slide{min-height:430px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:34px;display:flex;flex-direction:column;justify-content:center;box-shadow:0 10px 24px rgba(15,23,42,.05)}.slide.cover{background:linear-gradient(135deg,#eff6ff,#f0fdfa)}.slide h2{font-size:32px;line-height:1.35;margin:0 0 18px}.slide p{font-size:18px;line-height:1.9;margin:0;white-space:pre-wrap}.note{margin-top:20px;border-left:3px solid var(--accent2);padding-left:12px;color:var(--muted);font-size:14px}.article-tools{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 18px}.editor{background:#fff;border:1px solid var(--line);border-radius:8px;padding:16px;margin-bottom:16px}.editor-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.slide-edit{border:1px solid var(--line);border-radius:8px;padding:12px;background:#fbfdff}.slide-edit h3{font-size:13px;margin:0 0 8px;color:var(--muted)}.footer{text-align:center;padding:24px 18px;color:var(--muted);font-size:12px}@media(max-width:860px){.bar{align-items:flex-start;flex-direction:column}.nav{justify-content:flex-start;overflow-x:auto;flex-wrap:nowrap;width:100%}.genbox form{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.slide-shell{grid-template-columns:1fr}.toc{position:static}.editor-grid{grid-template-columns:1fr}.hero h1{font-size:28px}.slide{min-height:360px;padding:24px}.slide h2{font-size:25px}.slide p{font-size:16px}.brand span{white-space:normal}}@media print{.top,.genbox,.toc,.article-tools,.editor .article-tools,.footer{display:none}.hero,.main{max-width:none;padding:0}.slide-shell{display:block}.slide{page-break-after:always;border:0;box-shadow:none;min-height:auto;height:90vh}.slide h2{font-size:30px}}
</style>
<style>
.generate-status{display:none;grid-column:1/-1;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:6px;padding:10px 12px;font-size:13px}
.is-generating .generate-status{display:block}
.is-generating button[type=submit]{opacity:.7;cursor:wait}
.reveal-wrap{background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden}
.reveal{height:72vh;min-height:520px;background:#fff}
.reveal .slides{text-align:left}
.reveal .slides section{padding:26px}
.reveal h2{font-size:1.35em;line-height:1.3;color:#172033}
.reveal p{font-size:.62em;line-height:1.75;color:#334155;white-space:pre-wrap}
.tiptap-editor{border:1px solid var(--line);border-radius:6px;background:#fff;min-height:120px;padding:10px 12px;line-height:1.7}
.tiptap-source{display:none}
.oss-note{background:#f8fafc;border:1px solid var(--line);border-radius:8px;padding:12px 14px;color:var(--muted);font-size:13px;margin:10px 0 16px}
</style>
</head>
<body>
<header class="top"><div class="wrap"><div class="bar">
  <a class="brand" href="<?php echo h($THIS_FILE); ?>"><div class="logo">US</div><div><b>USlideBlog</b><span>URLをAIスライド技術ブログへ</span></div></a>
  <nav class="nav">
    <a class="btn" href="knowradar.php">KnowRadar</a>
    <a class="btn" href="url2ai.html">URL2AI</a>
    <a class="btn sub" href="<?php echo h($THIS_FILE); ?>?feed">RSS</a>
    <?php if ($logged_in): ?><span class="btn">@<?php echo h($session_user); ?></span><a class="btn" href="?usb_logout=1">logout</a><?php else: ?><a class="btn primary" href="?usb_login=1">X login</a><?php endif; ?>
  </nav>
</div></div></header>

<section class="hero">
  <h1>技術記事を、AIがスライドブログ化。</h1>
  <p class="lead">技術ブログ、GitHub README、Zenn、Qiita、OSSドキュメントなどのURLを入力すると、AIが内容を解析し、編集可能なスライド型技術ブログを生成します。</p>
  <?php if ($is_admin): ?>
  <div class="genbox">
    <form method="post" id="generate-form">
      <input type="hidden" name="action" value="generate">
      <input class="input" type="url" name="source_url" placeholder="https://example.com/tech-article" required>
      <button class="btn primary" type="submit" id="generate-button">スライドブログ生成</button>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted)"><input type="checkbox" name="published" value="1" checked> 生成後に公開</label>
      <select name="theme"><option value="blue">Blue Tech</option><option value="green">Green OSS</option><option value="dark">Dark Code</option></select>
      <div class="generate-status" id="generate-status">URLを取得してAIでスライド構成を生成しています。30秒から90秒ほどかかることがあります。この画面のままお待ちください。</div>
    </form>
  </div>
  <?php endif; ?>
  <div class="chips">
    <?php foreach (array('VibeCoding','ClaudeCode','Codex','Ollama','Gemma','OSS','AIAgent','GPU') as $t): ?>
      <a class="chip" href="?tag=<?php echo urlencode($t); ?>">#<?php echo h($t); ?></a>
    <?php endforeach; ?>
  </div>
</section>

<main class="main">
<?php if ($error !== ''): ?><div class="msg err"><?php echo h($error); ?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="msg ok">保存しました。</div><?php endif; ?>

<?php if ($detail): ?>
  <?php if (isset($_GET['edit']) && $is_admin): ?>
  <form class="editor" method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo h($detail['id']); ?>">
    <div class="article-tools">
      <button class="btn primary" type="submit">保存</button>
      <a class="btn" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id'])); ?>">公開表示</a>
      <a class="btn sub" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id']) . '&format=md'); ?>">Markdown</a>
      <a class="btn sub" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id']) . '&format=json'); ?>">JSON</a>
    </div>
    <p><label>タイトル<br><input class="input" name="title" value="<?php echo h($detail['title']); ?>"></label></p>
    <p><label>説明<br><textarea class="textarea" name="description"><?php echo h(isset($detail['description']) ? $detail['description'] : ''); ?></textarea></label></p>
    <div class="editor-grid">
      <label>ソースURL<br><input class="input" name="source_url" value="<?php echo h(isset($detail['source_url']) ? $detail['source_url'] : ''); ?>"></label>
      <label>OGP画像URL<br><input class="input" name="image" value="<?php echo h(isset($detail['image']) ? $detail['image'] : ''); ?>"></label>
      <label>タグ（カンマ区切り）<br><input class="input" name="tags" value="<?php echo h(!empty($detail['tags']) ? implode(',', $detail['tags']) : ''); ?>"></label>
      <label>テーマ<br><select name="theme"><option value="blue">Blue Tech</option><option value="green"<?php echo (isset($detail['theme']) && $detail['theme'] === 'green') ? ' selected' : ''; ?>>Green OSS</option><option value="dark"<?php echo (isset($detail['theme']) && $detail['theme'] === 'dark') ? ' selected' : ''; ?>>Dark Code</option></select></label>
      <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="published" value="1"<?php echo !empty($detail['published']) ? ' checked' : ''; ?>> 公開する</label>
    </div>
    <h2>スライド編集</h2>
    <div class="editor-grid" id="slide-editor">
      <?php foreach ($detail['slides'] as $i => $s): ?>
      <div class="slide-edit">
        <h3>Slide <?php echo (int)($i + 1); ?></h3>
        <p><input class="input" name="slide_title[]" value="<?php echo h(isset($s['title']) ? $s['title'] : ''); ?>" placeholder="タイトル"></p>
        <p><textarea class="textarea tiptap-source" name="slide_body[]" placeholder="本文"><?php echo h(isset($s['body']) ? $s['body'] : ''); ?></textarea><div class="tiptap-editor" data-target="slide_body[]"><?php echo nl2br(h(isset($s['body']) ? $s['body'] : '')); ?></div></p>
        <p><textarea class="textarea" name="slide_note[]" placeholder="補足・発表ノート"><?php echo h(isset($s['note']) ? $s['note'] : ''); ?></textarea></p>
        <p><select name="slide_layout[]"><option value="cover">cover</option><option value="points"<?php echo (isset($s['layout']) && $s['layout'] === 'points') ? ' selected' : ''; ?>>points</option><option value="diagram"<?php echo (isset($s['layout']) && $s['layout'] === 'diagram') ? ' selected' : ''; ?>>diagram</option><option value="code"<?php echo (isset($s['layout']) && $s['layout'] === 'code') ? ' selected' : ''; ?>>code</option><option value="warn"<?php echo (isset($s['layout']) && $s['layout'] === 'warn') ? ' selected' : ''; ?>>warn</option><option value="summary"<?php echo (isset($s['layout']) && $s['layout'] === 'summary') ? ' selected' : ''; ?>>summary</option></select></p>
      </div>
      <?php endforeach; ?>
    </div>
  </form>
  <form method="post" onsubmit="return confirm('削除しますか？')" style="margin-top:-8px">
    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo h($detail['id']); ?>"><button class="btn danger" type="submit">削除</button>
  </form>
  <?php else: ?>
  <article>
    <h1 style="font-size:30px;line-height:1.35;margin:0 0 6px"><?php echo h($detail['title']); ?></h1>
    <div class="meta"><?php echo h(isset($detail['created_at']) ? $detail['created_at'] : ''); ?> / <?php echo (int)(isset($detail['views']) ? $detail['views'] : 0); ?> views / Source: <a href="<?php echo h(isset($detail['source_url']) ? $detail['source_url'] : ''); ?>" target="_blank" rel="noopener"><?php echo h(isset($detail['source_title']) && $detail['source_title'] !== '' ? $detail['source_title'] : (isset($detail['source_url']) ? $detail['source_url'] : '')); ?></a></div>
    <div class="tagrow" style="margin:10px 0"><?php if (!empty($detail['tags'])): foreach ($detail['tags'] as $t): ?><a class="tag" href="?tag=<?php echo urlencode($t); ?>">#<?php echo h($t); ?></a><?php endforeach; endif; ?></div>
    <p class="lead"><?php echo h(isset($detail['description']) ? $detail['description'] : ''); ?></p>
    <div class="article-tools">
      <?php if ($is_admin): ?><a class="btn primary" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id']) . '&edit=1'); ?>">編集</a><?php endif; ?>
      <button class="btn" onclick="window.print()">PDF/印刷</button>
      <a class="btn sub" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id']) . '&format=md'); ?>">Markdown</a>
      <a class="btn sub" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id']) . '&format=html'); ?>">HTML</a>
      <button class="btn sub" type="button" id="pptx-download">PPTX</button>
      <a class="btn sub" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id']) . '&format=json'); ?>">JSON</a>
    </div>
    <div class="oss-note">表示は Reveal.js、HTML出力は Marp、PPTX出力は PptxGenJS、編集UIは Tiptap を使う構成です。図解編集は Excalidraw / diagrams.net ブロックとして拡張します。</div>
    <div class="reveal-wrap">
      <div class="reveal">
      <div class="slides">
      <?php foreach ($detail['slides'] as $i => $s): $layout = isset($s['layout']) ? $s['layout'] : 'points'; ?>
        <section data-layout="<?php echo h($i === 0 ? 'cover' : $layout); ?>" id="s<?php echo (int)$i; ?>">
          <h2><?php echo h(isset($s['title']) ? $s['title'] : ''); ?></h2>
          <p><?php echo h(isset($s['body']) ? $s['body'] : ''); ?></p>
          <?php if (!empty($s['note'])): ?><aside class="notes"><?php echo h($s['note']); ?></aside><?php endif; ?>
        </section>
      <?php endforeach; ?>
      </div>
      </div>
    </div>
  </article>
  <?php endif; ?>
<?php else: ?>
  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:14px">
    <div><h2 style="margin:0;font-size:22px"><?php echo $tag_filter !== '' ? '#' . h($tag_filter) : '公開スライドブログ'; ?></h2><div class="meta">URL2AIシリーズのAIスライド型技術ブログ一覧</div></div>
  </div>
  <?php if (!$posts): ?><div class="msg err">まだスライドブログがありません。</div><?php endif; ?>
  <div class="grid">
  <?php foreach ($posts as $p): if (empty($p['published']) && !$is_admin) { continue; } ?>
    <a class="card" href="<?php echo h($THIS_FILE . '?id=' . urlencode($p['id'])); ?>">
      <div class="thumb"><?php if (!empty($p['image'])): ?><img src="<?php echo h($p['image']); ?>" alt=""><?php else: ?>USlideBlog<?php endif; ?></div>
      <div class="cardbody">
        <h2><?php echo h($p['title']); ?></h2>
        <div class="meta"><?php echo h(isset($p['created_at']) ? $p['created_at'] : ''); ?> / <?php echo (int)(isset($p['views']) ? $p['views'] : 0); ?> views</div>
        <div><?php echo h(usb_excerpt(isset($p['description']) ? $p['description'] : '', 92)); ?></div>
        <div class="tagrow"><?php if (!empty($p['tags'])): foreach (array_slice($p['tags'], 0, 4) as $t): ?><span class="tag">#<?php echo h($t); ?></span><?php endforeach; endif; ?></div>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</main>
<footer class="footer">USlideBlog / URL2AI series</footer>
<script src="https://unpkg.com/reveal.js@5/dist/reveal.js"></script>
<script src="https://cdn.jsdelivr.net/npm/pptxgenjs@4.0.1/dist/pptxgen.bundle.js"></script>
<script>
<?php if ($detail && empty($_GET['edit'])): ?>
window.USLIDEBLOG_POST = <?php echo json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
<?php endif; ?>
(function(){
  if (window.Reveal && document.querySelector('.reveal')) {
    Reveal.initialize({
      hash: true,
      slideNumber: true,
      controls: true,
      progress: true,
      center: true
    });
  }
})();
(function(){
  var btn = document.getElementById('pptx-download');
  if (!btn || !window.USLIDEBLOG_POST) return;
  btn.onclick = function(){
    if (!window.pptxgen) {
      location.href = '<?php echo $detail ? h($THIS_FILE . '?id=' . urlencode($detail['id']) . '&format=pptx') : ''; ?>';
      return;
    }
    var post = window.USLIDEBLOG_POST;
    var pptx = new pptxgen();
    pptx.layout = 'LAYOUT_WIDE';
    pptx.author = 'USlideBlog';
    pptx.subject = post.description || post.title || '';
    pptx.title = post.title || 'USlideBlog';
    (post.slides || []).forEach(function(slide, i){
      var s = pptx.addSlide();
      var cover = i === 0 || slide.layout === 'cover';
      s.background = { color: cover ? 'EFF6FF' : 'FFFFFF' };
      s.addText(slide.title || post.title || '', { x: 0.65, y: cover ? 1.15 : 0.48, w: 11.1, h: cover ? 1.25 : 0.65, fontSize: cover ? 34 : 25, bold: true, color: '172033', fit: 'shrink' });
      s.addText(slide.body || '', { x: 0.75, y: cover ? 2.65 : 1.45, w: 10.9, h: cover ? 2.5 : 4.4, fontSize: cover ? 20 : 17, color: '334155', fit: 'shrink', valign: 'mid' });
      if (slide.note) s.addNotes(slide.note);
    });
    pptx.writeFile({ fileName: (post.id || 'uslideblog') + '.pptx' });
  };
})();
(function(){
  var form = document.getElementById('generate-form');
  if (!form) return;
  form.onsubmit = function(){
    form.className += ' is-generating';
    var btn = document.getElementById('generate-button');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '生成中...';
    }
    var status = document.getElementById('generate-status');
    if (status) {
      status.style.display = 'block';
      status.innerHTML = 'URLを取得してAIでスライド構成を生成しています。完了すると編集画面へ移動します。';
    }
    return true;
  };
})();
</script>
<script type="module">
import { Editor } from 'https://esm.sh/@tiptap/core@2.11.5';
import StarterKit from 'https://esm.sh/@tiptap/starter-kit@2.11.5';

document.querySelectorAll('.tiptap-editor').forEach(function(el) {
  var source = el.previousElementSibling;
  if (!source || source.tagName !== 'TEXTAREA') return;
  var editor = new Editor({
    element: el,
    extensions: [StarterKit],
    content: source.value ? source.value.replace(/\n/g, '<br>') : '',
    onUpdate: function(ctx) {
      source.value = ctx.editor.getText({ blockSeparator: "\n" });
    }
  });
  if (el.closest('form')) {
    el.closest('form').addEventListener('submit', function() {
      source.value = editor.getText({ blockSeparator: "\n" });
    });
  }
});
</script>
</body>
</html>
