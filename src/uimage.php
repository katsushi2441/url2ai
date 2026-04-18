<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set("Asia/Tokyo");

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

$DATA_DIR = __DIR__ . '/data';
$BASE_URL = AIGM_BASE_URL;
$THIS_FILE = 'uimage.php';
$VIEW_FILE = 'uimagev.php';
$SITE_NAME = 'UImage';
$ADMIN = AIGM_ADMIN;
$API_URL = getenv('UIMAGE_API_URL') ?: 'http://exbridge.ddns.net:8011/generate';

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

function ui_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function ui_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) {
        $bytes .= chr(mt_rand(0, 255));
    }
    return ui_base64url($bytes);
}
function ui_gen_challenge($verifier) {
    return ui_base64url(hash('sha256', $verifier, true));
}
function ui_x_post($url, $post_data, $headers) {
    $opts = array('http' => array(
        'method' => 'POST',
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $post_data,
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) {
        $res = '{}';
    }
    return json_decode($res, true);
}
function ui_x_get($url, $token) {
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "Authorization: Bearer $token\r\nUser-Agent: UImage/1.0\r\n",
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) {
        $res = '{}';
    }
    return json_decode($res, true);
}

if (isset($_GET['ui_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/', AIGM_COOKIE_DOMAIN, true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['ui_login'])) {
    $verifier = ui_gen_verifier();
    $challenge = ui_gen_challenge($verifier);
    $state = md5(uniqid('', true));
    $_SESSION['ui_code_verifier'] = $verifier;
    $_SESSION['ui_oauth_state'] = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['ui_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['ui_oauth_state']) {
        $post = http_build_query(array(
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $x_redirect_uri,
            'code_verifier' => $_SESSION['ui_code_verifier'],
            'client_id' => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = ui_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token'] = $data['access_token'];
            $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int) $data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['ui_oauth_state'], $_SESSION['ui_code_verifier']);
            $me = ui_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) {
                $_SESSION['session_username'] = $me['data']['username'];
            }
        }
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

if (
    !empty($_SESSION['session_refresh_token']) &&
    !empty($_SESSION['session_token_expires']) &&
    time() > $_SESSION['session_token_expires'] - 300
) {
    $cred_r = base64_encode($x_client_id . ':' . $x_client_secret);
    $post_r = http_build_query(array(
        'grant_type' => 'refresh_token',
        'refresh_token' => $_SESSION['session_refresh_token'],
        'client_id' => $x_client_id,
    ));
    $ref = ui_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . $cred_r,
    ));
    if (!empty($ref['access_token'])) {
        $_SESSION['session_access_token'] = $ref['access_token'];
        $_SESSION['session_token_expires'] = time() + (isset($ref['expires_in']) ? (int) $ref['expires_in'] : 7200);
        if (!empty($ref['refresh_token'])) {
            $_SESSION['session_refresh_token'] = $ref['refresh_token'];
        }
    } else {
        unset($_SESSION['session_access_token'], $_SESSION['session_refresh_token'], $_SESSION['session_token_expires'], $_SESSION['session_username']);
    }
}

$logged_in = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$username = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin = ($username === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function ui_extract_tweet_id($input) {
    $input = html_entity_decode((string) $input, ENT_QUOTES, 'UTF-8');
    $input = preg_replace('/[\x{00A0}\x{3000}\s]+/u', ' ', $input);
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (preg_match('/^\d{15,20}$/', $input)) {
        return $input;
    }
    $decoded = $input;
    for ($i = 0; $i < 2; $i++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            break;
        }
        $decoded = $next;
    }
    $patterns = array(
        '/(?:https?:\/\/)?(?:www\.)?(?:x|twitter)\.com\/(?:i\/web\/)?[^\/?#]+\/status(?:es)?\/(\d{15,20})/i',
        '/(?:https?:\/\/)?(?:www\.)?(?:x|twitter)\.com\/i\/status\/(\d{15,20})/i',
        '/status(?:es)?\/(\d{15,20})/i',
        '/\b(\d{15,20})\b/',
    );
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $decoded, $m)) {
            return $m[1];
        }
    }
    return '';
}
function ui_fx_get($tweet_id) {
    $url = 'https://api.fxtwitter.com/i/status/' . preg_replace('/[^0-9]/', '', $tweet_id);
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) {
        return null;
    }
    return json_decode($res, true);
}
function ui_fetch_thread($tweet_id, $depth) {
    if ($depth > 15) {
        return array();
    }
    $data = ui_fx_get($tweet_id);
    if (!$data || empty($data['tweet'])) {
        return array();
    }
    $tweet = $data['tweet'];
    $result = array();
    if (!empty($tweet['replying_to_status'])) {
        $result = ui_fetch_thread($tweet['replying_to_status'], $depth + 1);
    }
    $result[] = array(
        'user' => '@' . $tweet['author']['screen_name'],
        'name' => $tweet['author']['name'],
        'text' => $tweet['text'],
    );
    return $result;
}
function ui_thread_to_text($thread) {
    $lines = array();
    foreach ($thread as $t) {
        $lines[] = $t['user'] . ': ' . $t['text'];
    }
    return implode("\n\n", $lines);
}
function ui_build_image_prompt($thread_text) {
    return "以下はXの投稿スレッドです。この内容をもとに、URL2AI の公開デモ向けに、明るく見やすい1枚絵の画像生成プロンプトを日本語で作成してください。\n\n条件：\n- 説明文や前置きは不要。画像生成用プロンプトだけを出力する\n- 全体はクリーンで明るい、コミカルで親しみやすい、広告ビジュアルやポップなイラスト寄りにする\n- 不気味、ホラー、グロテスク、心霊写真風、流血、過度な肉体表現、過度に生々しい口内表現は避ける\n- 投稿に強い言葉や誇張表現があっても、過激にせずユーモラスで安全な比喩表現に変換する\n- 背景は明るく、色ははっきり、構図は整理され、被写体が分かりやすいこと\n- 人物が出る場合は自然で清潔感のある表情にし、奇形や崩れた顔にしない\n- 実写ホラー風ではなく、イラスト、ポスター、絵本、広告キービジュアルの方向を優先する\n\n---\n" . $thread_text . "\n---";
}
function ui_call_image_api($apiUrl, $payload) {
    if (!function_exists('curl_init')) {
        return array(false, 'cURL extension is not available on this server.');
    }
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Content-Type: application/json',
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
    ));
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return array(false, 'Image API request failed: ' . $curlErr);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array(false, 'Image API returned invalid JSON.');
    }
    if ($httpCode >= 400) {
        $detail = isset($decoded['detail']) ? $decoded['detail'] : ('HTTP ' . $httpCode);
        return array(false, 'Image generation error: ' . $detail);
    }
    return array(true, $decoded);
}
function ui_save_image_binary($tweet_id, $output_format, $image_base64) {
    global $DATA_DIR;
    if (!is_dir($DATA_DIR)) {
        @mkdir($DATA_DIR, 0775, true);
    }
    $extension = $output_format === 'jpeg' ? 'jpg' : 'png';
    $filename = 'uimage_' . $tweet_id . '.' . $extension;
    $path = $DATA_DIR . '/' . $filename;
    $binary = base64_decode((string) $image_base64, true);
    if ($binary === false) {
        return '';
    }
    file_put_contents($path, $binary);
    return 'data/' . $filename;
}
function ui_data_file($tweet_id) {
    global $DATA_DIR;
    return $DATA_DIR . '/xinsight_' . $tweet_id . '.json';
}
function ui_load_saved_post($tweet_id) {
    $save_file = ui_data_file($tweet_id);
    if (!file_exists($save_file)) {
        return null;
    }
    $saved = json_decode(file_get_contents($save_file), true);
    if (!is_array($saved)) {
        return null;
    }
    return $saved;
}
function ui_save_post_data($tweet_id, $data) {
    $save_file = ui_data_file($tweet_id);
    if (!is_dir(dirname($save_file))) {
        @mkdir(dirname($save_file), 0775, true);
    }
    file_put_contents($save_file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$tweet_url = isset($_POST['tweet_url']) ? trim($_POST['tweet_url']) : '';
$force_regen = !empty($_POST['force_regen']);
$custom_prompt = isset($_POST['custom_prompt']) ? trim($_POST['custom_prompt']) : '';
$detail_id = isset($_GET['id']) ? preg_replace('/[^0-9]/', '', trim($_GET['id'])) : '';
$detail_post = null;
$fetch_error = isset($_SESSION['ui_flash_error']) ? $_SESSION['ui_flash_error'] : '';
if (isset($_SESSION['ui_flash_error'])) {
    unset($_SESSION['ui_flash_error']);
}

if ($tweet_url === '' && isset($_GET['tweet_url']) && $_GET['tweet_url'] !== '') {
    $tweet_url = trim($_GET['tweet_url']);
}
if ($detail_id === '' && $tweet_url !== '') {
    $detail_id = ui_extract_tweet_id($tweet_url);
}
if ($detail_id !== '') {
    $detail_post = ui_load_saved_post($detail_id);
    if (is_array($detail_post) && empty($tweet_url) && !empty($detail_post['tweet_url'])) {
        $tweet_url = $detail_post['tweet_url'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'fetch') {
    if (!$is_admin) {
        $_SESSION['ui_flash_error'] = '現在は管理者のみ画像生成できます';
        header('Location: ' . $x_redirect_uri);
        exit;
    }
    $tweet_id = ui_extract_tweet_id($tweet_url);
    if ($tweet_id === '') {
        $_SESSION['ui_flash_error'] = 'URLからツイートIDを取得できませんでした';
        header('Location: ' . $x_redirect_uri);
        exit;
    }

    $saved = ui_load_saved_post($tweet_id);
    if (!$force_regen && is_array($saved) && !empty($saved['uimage_path']) && !empty($saved['thread_text'])) {
        header('Location: ' . $x_redirect_uri . '?id=' . urlencode($tweet_id));
        exit;
    }

    $thread = ui_fetch_thread($tweet_id, 0);
    if (empty($thread)) {
        $_SESSION['ui_flash_error'] = 'ツイートを取得できませんでした';
        header('Location: ' . $x_redirect_uri);
        exit;
    }
    $thread_text = ui_thread_to_text($thread);
    $image_prompt = ($custom_prompt !== '') ? $custom_prompt : ui_build_image_prompt($thread_text);
    $payload = array(
        'prompt' => $image_prompt,
        'negative_prompt' => 'horror, creepy, ghost photo, grotesque, gore, blood, disturbing mouth, realistic oral cavity, deformed face, extra limbs, bad anatomy, blurry, low quality, dark horror, zombie, uncanny',
        'width' => 1024,
        'height' => 1024,
        'num_inference_steps' => 8,
        'guidance_scale' => 1.0,
        'use_pe' => true,
        'output_format' => 'png',
    );
    list($ok, $response) = ui_call_image_api($API_URL, $payload);
    if (!$ok) {
        $_SESSION['ui_flash_error'] = $response;
        header('Location: ' . $x_redirect_uri);
        exit;
    }
    if (empty($response['image_base64'])) {
        $_SESSION['ui_flash_error'] = '画像データが返ってきませんでした';
        header('Location: ' . $x_redirect_uri);
        exit;
    }
    $image_path = ui_save_image_binary($tweet_id, isset($response['output_format']) ? $response['output_format'] : 'png', $response['image_base64']);
    if ($image_path === '') {
        $_SESSION['ui_flash_error'] = '生成画像の保存に失敗しました';
        header('Location: ' . $x_redirect_uri);
        exit;
    }

    $save_data = is_array($saved) ? $saved : array();
    $save_data['tweet_id'] = $tweet_id;
    $save_data['tweet_url'] = $tweet_url;
    $save_data['username'] = $username;
    $save_data['thread_text'] = $thread_text;
    $save_data['uimage_prompt'] = $image_prompt;
    $save_data['uimage_path'] = $image_path;
    $save_data['uimage_saved_at'] = date('Y-m-d H:i:s');
    $save_data['saved_at'] = date('Y-m-d H:i:s');
    ui_save_post_data($tweet_id, $save_data);

    header('Location: ' . $x_redirect_uri . '?id=' . urlencode($tweet_id));
    exit;
}

$page_title = $SITE_NAME;
if ($detail_post && !empty($detail_post['tweet_id'])) {
    $page_title = $SITE_NAME . ' | ERNIE-Image-Turbo | ' . $detail_post['tweet_id'];
} else {
    $page_title = $SITE_NAME . ' | ERNIE-Image-Turbo';
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($page_title); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f8fafc;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#2563eb;--accent-h:#1d4ed8;--green:#059669;--red:#dc2626;--amber:#d97706;
    --text:#0f172a;--muted:#64748b;--mono:'JetBrains Mono',monospace;--sans:'Inter',sans-serif;
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;font-size:14px}
header{background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.logo{font-size:1.1rem;font-weight:700;letter-spacing:-.02em}
.logo span{color:var(--accent)}
.userbar{display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:var(--muted)}
.userbar strong{color:var(--green)}
.btn-sm{background:none;border:1px solid var(--border2);color:var(--muted);padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-sm:hover{border-color:var(--red);color:var(--red)}
.container{max-width:1100px;margin:0 auto;padding:1.5rem}
.section{background:var(--surface);border:1px solid var(--border);border-radius:10px;margin-bottom:1rem;overflow:hidden}
.section-header{padding:.75rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.section-title{font-weight:600;font-size:.85rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.step{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700}
.section-body{padding:1rem}
.row{display:flex;gap:.6rem;align-items:flex-start}
input[type=text]{flex:1;border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text)}
input[type=text]:focus{border-color:var(--accent)}
textarea.code-area{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.75rem;font-family:var(--mono);font-size:.8rem;line-height:1.7;outline:none;resize:vertical;color:var(--text);transition:border .15s;min-height:120px;background:#fff}
.thread-area{background:#f8fafc}
.image-preview{display:block;max-width:100%;height:auto;border-radius:12px;border:1px solid var(--border);background:#fff}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans);text-decoration:none}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn:disabled{opacity:.5;cursor:not-allowed}
.msg-error{color:var(--red);font-size:.8rem;margin-top:.4rem}
.hint{font-size:.82rem;color:var(--muted);line-height:1.8}
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}
.loading-msg{display:none;text-align:center;padding:12px 16px;font-size:.82rem;color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;margin-bottom:1rem;font-weight:600}
.detail-meta{display:flex;flex-wrap:wrap;gap:12px;font-size:12px;color:var(--muted)}
.detail-actions{display:flex;gap:8px;flex-wrap:wrap}
@media (max-width: 600px) {
    .row { flex-wrap: wrap; }
    .row input[type=text] { flex: 1 1 100%; min-width: 0; }
    .row .btn { flex: 1 1 calc(50% - .3rem); white-space: nowrap; font-size:.75rem; padding:.45rem .6rem; justify-content:center; }
    .detail-actions { width:100%; }
    .detail-actions .btn { flex:1 1 calc(50% - .3rem); justify-content:center; min-width:0; white-space:nowrap; font-size:.75rem; padding:.45rem .6rem; }
    .container { padding: 1rem; }
    .section-body { padding: .75rem; }
}
</style>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-BP0650KDFR');
</script>
<script>
(function () {
    var s = document.createElement('script');
    s.src = 'https://aiknowledgecms.exbridge.jp/simpletrack.php'
        + '?url=' + encodeURIComponent(location.href)
        + '&ref=' + encodeURIComponent(document.referrer);
    document.head.appendChild(s);
})();
</script>
</head>
<body>
<header>
    <div class="logo">U<span>Image</span></div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?ui_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?ui_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">1</span> Xの投稿URLから画像を生成</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-fetch">
                <input type="hidden" name="action" value="fetch">
                <input type="hidden" name="force_regen" id="force_regen" value="0">
                <div class="row">
                    <input type="text" name="tweet_url" id="tweet_url_input" placeholder="https://x.com/user/status/..." value="<?php echo h($tweet_url); ?>">
                    <button type="button" class="btn btn-primary" id="btn-fetch"<?php if (!$is_admin): ?> disabled title="管理者のみ生成できます"<?php endif; ?> onclick="submitFetch()">
                        <span class="btn-label">取得して画像生成</span>
                        <span class="spinner"></span>
                    </button>
                    <?php if ($tweet_url !== ''): ?>
                    <a href="<?php echo h($tweet_url); ?>" target="_blank" rel="noopener" class="btn btn-secondary">元の投稿 ↗</a>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary" id="btn-open" onclick="openTweetUrl()" disabled>元の投稿 ↗</button>
                    <?php endif; ?>
                </div>
                <?php if ($fetch_error): ?>
                <div class="msg-error"><?php echo h($fetch_error); ?></div>
                <?php endif; ?>
                <?php if ($is_admin): ?>
                <div style="margin-top:.75rem">
                    <div style="font-size:.75rem;font-weight:600;color:var(--muted);margin-bottom:.3rem">🖊 プロンプト（空白=自動生成）</div>
                    <textarea name="custom_prompt" id="custom_prompt" class="code-area" rows="6" placeholder="空白のままにすると、ツイート内容からプロンプトを自動生成します。&#10;ここに入力するとそのプロンプトで画像生成します。"><?php echo h($custom_prompt); ?></textarea>
                </div>
                <?php else: ?>
                <input type="hidden" name="custom_prompt" id="custom_prompt" value="">
                <?php endif; ?>
                <div class="hint" style="margin-top:.6rem">
                    X投稿URLを入力すると、URL2AI ERNIE Image が ERNIE-Image-Turbo でスレッド取得から画像生成まで一気に実行します。現在生成できるのは <strong><?php echo h($ADMIN); ?></strong> のみです。
                </div>
            </form>
        </div>
    </div>

    <div id="generating-msg" class="loading-msg">
        ⏳ AI生成中です。スレッド取得と画像生成をまとめて実行しています。しばらくお待ちください...
    </div>

    <?php if ($detail_post): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">2</span> 取得したスレッド</div>
            <div class="detail-meta">
                <span><?php echo h(isset($detail_post['username']) ? '@' . $detail_post['username'] : ''); ?></span>
                <span><?php echo h(isset($detail_post['uimage_saved_at']) ? $detail_post['uimage_saved_at'] : ''); ?></span>
                <span style="font-family:var(--mono)"><?php echo h(isset($detail_post['tweet_id']) ? $detail_post['tweet_id'] : ''); ?></span>
            </div>
        </div>
        <div class="section-body">
            <textarea class="code-area thread-area" rows="10" readonly><?php echo h(isset($detail_post['thread_text']) ? $detail_post['thread_text'] : ''); ?></textarea>
        </div>
    </div>

    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--green)">✓</span> 生成画像</div>
            <div class="detail-actions">
                <?php if (!empty($detail_post['tweet_url'])): ?>
                <a href="<?php echo h($detail_post['tweet_url']); ?>" target="_blank" rel="noopener" class="btn btn-secondary">元の投稿 ↗</a>
                <?php endif; ?>
                <?php if (!empty($detail_post['tweet_id'])): ?>
                <a href="<?php echo h($VIEW_FILE . '?id=' . urlencode($detail_post['tweet_id'])); ?>" class="btn btn-secondary">公開表示</a>
                <?php endif; ?>
                <?php if (!empty($detail_post['tweet_id'])): ?>
                <button type="button" class="btn btn-secondary" onclick="copyText('uimage_copy')">コピー</button>
                <?php endif; ?>
                <?php if ($is_admin && !empty($detail_post['tweet_url'])): ?>
                <button type="button" class="btn btn-primary" id="btn-regenerate" onclick="submitRegenerate()">再生成</button>
                <?php endif; ?>
                <?php if ($logged_in && !empty($detail_post['uimage_path'])): ?>
                <a href="<?php echo h($BASE_URL . '/' . $detail_post['uimage_path']); ?>" download class="btn btn-secondary">画像を保存</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="section-body">
            <?php if (!empty($detail_post['uimage_path'])): ?>
            <img class="image-preview" src="<?php echo h($BASE_URL . '/' . $detail_post['uimage_path']); ?>" alt="Generated image">
            <?php endif; ?>
            <?php if (!empty($detail_post['uimage_prompt'])): ?>
            <?php if ($is_admin): ?>
            <div style="margin-top:.75rem">
                <div style="font-size:.75rem;font-weight:600;color:var(--muted);margin-bottom:.3rem">🖊 プロンプト編集（管理者）</div>
                <textarea id="prompt-editor" class="code-area" rows="8"><?php echo h($detail_post['uimage_prompt']); ?></textarea>
                <div class="hint" style="margin-top:.3rem">編集後に「再生成」を押すと、このプロンプトで画像を生成し直します。</div>
            </div>
            <?php else: ?>
            <div class="hint" style="margin-top:.75rem">
                URL2AI ERNIE Image は ERNIE-Image-Turbo に渡すプロンプトを内部で自動生成しています。
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function openTweetUrl() {
    var url = document.getElementById('tweet_url_input').value.trim();
    if (url) window.open(url, '_blank');
}
var urlInput = document.getElementById('tweet_url_input');
var btnOpen = document.getElementById('btn-open');
if (urlInput && btnOpen) {
    urlInput.addEventListener('input', function() {
        btnOpen.disabled = this.value.trim() === '';
    });
}
function copyText(mode) {
    var tweetUrl = '';
    var urlEl = document.getElementById('tweet_url_input');
    if (urlEl && urlEl.value.trim()) {
        tweetUrl = urlEl.value.trim();
    }
    var viewUrl = '';
    var tidMatch = tweetUrl.match(/(\d{15,20})/);
    if (tidMatch) {
        viewUrl = 'https://aiknowledgecms.exbridge.jp/uimagev.php?id=' + tidMatch[1];
    }
    var text = '#URL2AI 画像生成'
        + (viewUrl ? '\n' + viewUrl + '\n\nGenerated by Ernie-Image-Turbo' : '')
        + (tweetUrl ? '\n元の投稿\n' + tweetUrl : '');
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        var dummy = document.createElement('textarea');
        dummy.value = text;
        document.body.appendChild(dummy);
        dummy.select();
        document.execCommand('copy');
        document.body.removeChild(dummy);
    }
}
function lockUI() {
    var btnF = document.getElementById('btn-fetch');
    if (btnF) { btnF.disabled = true; btnF.classList.add('loading'); }
    if (urlInput) { urlInput.readOnly = true; }
    if (btnOpen) { btnOpen.disabled = true; }
    var msg = document.getElementById('generating-msg');
    if (msg) { msg.style.display = 'block'; }
}
function submitFetch() {
    var force = document.getElementById('force_regen');
    if (force) { force.value = '0'; }
    lockUI();
    document.getElementById('form-fetch').submit();
}
function submitRegenerate() {
    var force = document.getElementById('force_regen');
    if (force) { force.value = '1'; }
    var editor = document.getElementById('prompt-editor');
    var cp = document.getElementById('custom_prompt');
    if (editor && cp) { cp.value = editor.value.trim(); }
    else if (editor) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden'; hidden.name = 'custom_prompt'; hidden.value = editor.value.trim();
        document.getElementById('form-fetch').appendChild(hidden);
    }
    lockUI();
    document.getElementById('form-fetch').submit();
}
</script>
</body>
</html>
