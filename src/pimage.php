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
$THIS_FILE = 'pimage.php';
$VIEW_FILE = 'pimagev.php';
$SITE_NAME = 'PImage';
$ADMIN = AIGM_ADMIN;
$API_URL = getenv('UIMAGE_API_URL') ?: 'http://exbridge.ddns.net:8011/generate';
$UIMAGE_X402_URL = 'https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/uimage';

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

function pi_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function pi_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return pi_base64url($bytes);
}
function pi_gen_challenge($verifier) {
    return pi_base64url(hash('sha256', $verifier, true));
}
function pi_x_post($url, $post_data, $headers) {
    $opts = array('http' => array(
        'method' => 'POST',
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $post_data,
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}
function pi_x_get($url, $token) {
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "Authorization: Bearer $token\r\nUser-Agent: PImage/1.0\r\n",
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['pi_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/', AIGM_COOKIE_DOMAIN, true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['pi_login'])) {
    $verifier = pi_gen_verifier();
    $challenge = pi_gen_challenge($verifier);
    $state = md5(uniqid('', true));
    $_SESSION['pi_code_verifier'] = $verifier;
    $_SESSION['pi_oauth_state'] = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['pi_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['pi_oauth_state']) {
        $post = http_build_query(array(
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $x_redirect_uri,
            'code_verifier' => $_SESSION['pi_code_verifier'],
            'client_id' => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = pi_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token'] = $data['access_token'];
            $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int) $data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['pi_oauth_state'], $_SESSION['pi_code_verifier']);
            $me = pi_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
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
    $ref = pi_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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
function pi_x402_payload_json($prompt_text) {
    $payload = array(
        'input_type' => 'prompt',
        'prompt' => $prompt_text,
        'width' => 1024,
        'height' => 1024,
    );
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
function pi_slug($text) {
    $text = strtolower(trim((string) $text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}
function pi_prompt_id($prompt_text) {
    $slug = pi_slug(mb_substr(str_replace(array("\r", "\n"), ' ', $prompt_text), 0, 24));
    if ($slug === '') { $slug = 'prompt'; }
    return $slug . '-' . date('YmdHis');
}
function pi_data_file($prompt_id) {
    global $DATA_DIR;
    return $DATA_DIR . '/pimage_' . preg_replace('/[^a-zA-Z0-9\-_]/', '-', $prompt_id) . '.json';
}
function pi_image_file($prompt_id, $output_format) {
    global $DATA_DIR;
    $extension = $output_format === 'jpeg' ? 'jpg' : 'png';
    return $DATA_DIR . '/pimage_' . preg_replace('/[^a-zA-Z0-9\-_]/', '-', $prompt_id) . '.' . $extension;
}
function pi_load_saved($prompt_id) {
    $path = pi_data_file($prompt_id);
    if (!file_exists($path)) { return null; }
    $saved = json_decode(file_get_contents($path), true);
    return is_array($saved) ? $saved : null;
}
function pi_save_data($prompt_id, $data) {
    $path = pi_data_file($prompt_id);
    if (!is_dir(dirname($path))) { @mkdir(dirname($path), 0775, true); }
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function pi_save_image_binary($prompt_id, $output_format, $image_base64) {
    $path = pi_image_file($prompt_id, $output_format);
    if (!is_dir(dirname($path))) { @mkdir(dirname($path), 0775, true); }
    $binary = base64_decode((string) $image_base64, true);
    if ($binary === false) { return ''; }
    file_put_contents($path, $binary);
    return 'data/' . basename($path);
}
function pi_call_image_api($apiUrl, $payload) {
    if (!function_exists('curl_init')) {
        return array(false, 'cURL extension is not available on this server.');
    }
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => array('Accept: application/json', 'Content-Type: application/json'),
        CURLOPT_POSTFIELDS => json_encode($payload),
    ));
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) { return array(false, 'Image API request failed: ' . $curlErr); }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) { return array(false, 'Image API returned invalid JSON.'); }
    if ($httpCode >= 400) {
        $detail = isset($decoded['detail']) ? $decoded['detail'] : ('HTTP ' . $httpCode);
        return array(false, 'Image generation error: ' . $detail);
    }
    return array(true, $decoded);
}
function pi_load_all_prompts() {
    global $DATA_DIR;
    $posts = array();
    $files = glob($DATA_DIR . '/pimage_*.json');
    if ($files) {
        foreach ($files as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d) || empty($d['prompt_id'])) { continue; }
            $posts[] = $d;
        }
    }
    usort($posts, function($a, $b) {
        $ta = isset($a['pimage_saved_at']) ? $a['pimage_saved_at'] : '';
        $tb = isset($b['pimage_saved_at']) ? $b['pimage_saved_at'] : '';
        return strcmp($tb, $ta);
    });
    return $posts;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$prompt_id = isset($_POST['prompt_id']) ? trim($_POST['prompt_id']) : '';
$prompt_text = isset($_POST['prompt_text']) ? trim($_POST['prompt_text']) : '';
$force_regen = !empty($_POST['force_regen']);
$detail_id = isset($_GET['id']) ? trim($_GET['id']) : '';
$detail_post = null;
$fetch_error = isset($_SESSION['pi_flash_error']) ? $_SESSION['pi_flash_error'] : '';
if (isset($_SESSION['pi_flash_error'])) { unset($_SESSION['pi_flash_error']); }

if ($detail_id === '' && isset($_GET['prompt_id']) && $_GET['prompt_id'] !== '') {
    $detail_id = trim($_GET['prompt_id']);
}
if ($detail_id !== '') {
    $detail_post = pi_load_saved($detail_id);
    if (is_array($detail_post)) {
        if ($prompt_id === '') { $prompt_id = $detail_post['prompt_id']; }
        if ($prompt_text === '') { $prompt_text = isset($detail_post['prompt_text']) ? $detail_post['prompt_text'] : ''; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate') {
    if (!$is_admin) {
        $_SESSION['pi_flash_error'] = '現在は管理者のみ画像生成できます';
        header('Location: ' . $x_redirect_uri);
        exit;
    }
    if ($prompt_text === '') {
        $_SESSION['pi_flash_error'] = 'プロンプトを入力してください';
        header('Location: ' . $x_redirect_uri);
        exit;
    }
    $saved = ($prompt_id !== '') ? pi_load_saved($prompt_id) : null;
    if ($prompt_id === '') {
        $prompt_id = pi_prompt_id($prompt_text);
    }
    if (!$force_regen && is_array($saved) && !empty($saved['pimage_path'])) {
        header('Location: ' . $x_redirect_uri . '?id=' . urlencode($prompt_id));
        exit;
    }

    $payload = array(
        'prompt' => $prompt_text,
        'negative_prompt' => 'horror, creepy, ghost photo, grotesque, gore, blood, bad anatomy, blurry, low quality, dark horror, zombie, uncanny',
        'width' => 1024,
        'height' => 1024,
        'num_inference_steps' => 8,
        'guidance_scale' => 1.0,
        'use_pe' => true,
        'output_format' => 'png',
    );
    list($ok, $response) = pi_call_image_api($API_URL, $payload);
    if (!$ok) {
        $_SESSION['pi_flash_error'] = $response;
        header('Location: ' . $x_redirect_uri . ($prompt_id !== '' ? '?id=' . urlencode($prompt_id) : ''));
        exit;
    }
    if (empty($response['image_base64'])) {
        $_SESSION['pi_flash_error'] = '画像データが返ってきませんでした';
        header('Location: ' . $x_redirect_uri . ($prompt_id !== '' ? '?id=' . urlencode($prompt_id) : ''));
        exit;
    }
    $image_path = pi_save_image_binary($prompt_id, isset($response['output_format']) ? $response['output_format'] : 'png', $response['image_base64']);
    if ($image_path === '') {
        $_SESSION['pi_flash_error'] = '生成画像の保存に失敗しました';
        header('Location: ' . $x_redirect_uri . ($prompt_id !== '' ? '?id=' . urlencode($prompt_id) : ''));
        exit;
    }

    $save_data = is_array($saved) ? $saved : array();
    $save_data['prompt_id'] = $prompt_id;
    $save_data['prompt_text'] = $prompt_text;
    $save_data['pimage_path'] = $image_path;
    $save_data['pimage_saved_at'] = date('Y-m-d H:i:s');
    $save_data['saved_at'] = date('Y-m-d H:i:s');
    $save_data['username'] = $username;
    pi_save_data($prompt_id, $save_data);

    header('Location: ' . $x_redirect_uri . '?id=' . urlencode($prompt_id));
    exit;
}

$all_prompts = pi_load_all_prompts();
$page_title = $detail_post && !empty($detail_post['prompt_id'])
    ? $SITE_NAME . ' | ERNIE-Image-Turbo | ' . $detail_post['prompt_id']
    : $SITE_NAME . ' | ERNIE-Image-Turbo';
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
    --accent:#2563eb;--accent-h:#1d4ed8;--green:#059669;--red:#dc2626;
    --text:#0f172a;--muted:#64748b;--mono:'JetBrains Mono',monospace;--sans:'Inter',sans-serif;
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;font-size:14px}
header{background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.logo{font-size:1.1rem;font-weight:700;letter-spacing:-.02em}
.logo span{color:var(--accent)}
.logo-group{display:flex;align-items:center;gap:6px}
.u2a-badge{background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;letter-spacing:.03em}
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
input[type=text],select{flex:1;border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text);background:#fff}
input[type=text]:focus,select:focus,textarea:focus{border-color:var(--accent)}
textarea.code-area{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.75rem;font-family:var(--mono);font-size:.8rem;line-height:1.7;outline:none;resize:vertical;color:var(--text);transition:border .15s;min-height:180px;background:#fff}
.image-preview{display:block;max-width:100%;height:auto;border-radius:12px;border:1px solid var(--border);background:#fff}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans);text-decoration:none}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn:disabled{opacity:.5;cursor:not-allowed}
.msg-error{color:var(--red);font-size:.8rem;margin-top:.4rem}
.hint{font-size:.82rem;color:var(--muted);line-height:1.8}
.x402-box{margin-top:.75rem;padding:.85rem 1rem;border:1px solid #bfdbfe;background:#eff6ff;border-radius:10px}
.x402-box strong{color:#1d4ed8}
.x402-box code,.x402-box pre{font-family:var(--mono)}
.x402-box pre{margin-top:.55rem;padding:.75rem;border-radius:8px;background:#dbeafe;overflow:auto;font-size:.78rem;line-height:1.6;color:#1e293b;white-space:pre-wrap}
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}
.loading-msg{display:none;text-align:center;padding:12px 16px;font-size:.82rem;color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;margin-bottom:1rem;font-weight:600}
.detail-meta{display:flex;flex-wrap:wrap;gap:12px;font-size:12px;color:var(--muted)}
.detail-actions{display:flex;gap:8px;flex-wrap:wrap}
.prompt-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.prompt-card{border:1px solid var(--border);border-radius:12px;padding:14px;background:#fff}
.prompt-card-title{font-weight:700;font-size:.92rem;margin-bottom:6px}
.prompt-card-meta{font-size:.74rem;color:var(--muted);margin-bottom:8px}
.prompt-card-text{font-size:.78rem;line-height:1.7;color:#334155;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;white-space:pre-wrap}
@media (max-width: 600px) {
    .row { flex-wrap: wrap; }
    .row input[type=text], .row select { flex: 1 1 100%; min-width: 0; }
    .row .btn { flex: 1 1 calc(50% - .3rem); white-space: nowrap; font-size:.75rem; padding:.45rem .6rem; justify-content:center; }
    .container { padding: 1rem; }
    .section-body { padding: .75rem; }
}
</style>
</head>
<body>
<header>
    <div class="logo-group"><div class="logo">P<span>Image</span></div><span class="u2a-badge">URL2AI</span>Prompt</div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?pi_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?pi_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">1</span> プロンプトから画像を生成</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-generate">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="force_regen" id="force_regen" value="0">
                <input type="hidden" name="prompt_id" id="prompt_id" value="<?php echo h($prompt_id); ?>">
                <div>
                    <div style="font-size:.75rem;font-weight:600;color:var(--muted);margin-bottom:.3rem">プロンプト本文</div>
                    <textarea name="prompt_text" id="prompt_text" class="code-area" rows="12" placeholder="ここに画像生成プロンプトを入力"><?php echo h($prompt_text); ?></textarea>
                </div>
                <div class="row" style="margin-top:.75rem">
                    <button type="button" class="btn btn-primary" id="btn-generate"<?php if (!$is_admin): ?> disabled title="管理者のみ生成できます"<?php endif; ?> onclick="submitGenerate()">
                        <span class="btn-label">保存して画像生成</span>
                        <span class="spinner"></span>
                    </button>
                    <?php if ($detail_post && !empty($detail_post['prompt_id'])): ?>
                    <a href="<?php echo h($VIEW_FILE . '?id=' . urlencode($detail_post['prompt_id'])); ?>" class="btn btn-secondary">公開表示</a>
                    <?php endif; ?>
                    <?php if ($detail_post && !empty($detail_post['pimage_path']) && $is_admin): ?>
                    <button type="button" class="btn btn-secondary" onclick="submitRegenerate()">再生成</button>
                    <?php endif; ?>
                    <?php if ($detail_post && !empty($detail_post['pimage_path']) && $logged_in): ?>
                    <a href="<?php echo h($BASE_URL . '/' . $detail_post['pimage_path']); ?>" download class="btn btn-secondary">画像を保存</a>
                    <?php endif; ?>
                </div>
                <?php if ($fetch_error): ?>
                <div class="msg-error"><?php echo h($fetch_error); ?></div>
                <?php endif; ?>
                <div class="x402-box">
                    <div class="hint"><strong>Bankr x402 AIエージェントでも使えます。</strong> 完成した画像生成プロンプトは `UImage` endpoint に `input_type=prompt` で渡せます。endpoint: <code><?php echo h($UIMAGE_X402_URL); ?></code></div>
                    <pre><?php echo h(pi_x402_payload_json($prompt_text !== '' ? $prompt_text : 'bright pop illustration of a futuristic city, clean empty space for headline text')); ?></pre>
                </div>
            </form>
        </div>
    </div>

    <div id="generating-msg" class="loading-msg">
        ⏳ プロンプトから画像生成しています。しばらくお待ちください...
    </div>

    <?php if ($detail_post): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">2</span> 保存済みプロンプト</div>
            <div class="detail-meta">
                <span><?php echo h(isset($detail_post['username']) ? '@' . $detail_post['username'] : ''); ?></span>
                <span><?php echo h(isset($detail_post['pimage_saved_at']) ? $detail_post['pimage_saved_at'] : ''); ?></span>
                <span style="font-family:var(--mono)"><?php echo h(isset($detail_post['prompt_id']) ? $detail_post['prompt_id'] : ''); ?></span>
            </div>
        </div>
        <div class="section-body">
            <textarea class="code-area" rows="10" readonly><?php echo h(isset($detail_post['prompt_text']) ? $detail_post['prompt_text'] : ''); ?></textarea>
        </div>
    </div>

    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--green)">✓</span> 生成画像</div>
            <div class="detail-actions">
                <?php if (!empty($detail_post['prompt_id'])): ?>
                <a href="<?php echo h($VIEW_FILE . '?id=' . urlencode($detail_post['prompt_id'])); ?>" class="btn btn-secondary">公開表示</a>
                <button type="button" class="btn btn-secondary" onclick="copyText()">コピー</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="section-body">
            <?php if (!empty($detail_post['pimage_path'])): ?>
            <img class="image-preview" src="<?php echo h($BASE_URL . '/' . $detail_post['pimage_path']); ?>" alt="Generated image">
            <?php endif; ?>
            <?php if (!empty($detail_post['prompt_text'])): ?>
            <div class="x402-box">
                <div class="hint"><strong>このプロンプトでそのまま Bankr x402 AIエージェントを呼べます。</strong> 下の JSON を `UImage` endpoint に送ると、この prompt をそのまま再利用して画像生成できます。</div>
                <pre><?php echo h(pi_x402_payload_json($detail_post['prompt_text'])); ?></pre>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function lockUI() {
    var btn = document.getElementById('btn-generate');
    if (btn) { btn.disabled = true; btn.classList.add('loading'); }
    var msg = document.getElementById('generating-msg');
    if (msg) { msg.style.display = 'block'; }
}
function submitGenerate() {
    document.getElementById('force_regen').value = '0';
    lockUI();
    document.getElementById('form-generate').submit();
}
function submitRegenerate() {
    document.getElementById('force_regen').value = '1';
    lockUI();
    document.getElementById('form-generate').submit();
}
function copyText() {
    var id = document.getElementById('prompt_id').value;
    var textArea = document.getElementById('prompt_text');
    var title = textArea ? textArea.value.trim().split('\n')[0] : '';
    var text = '#URL2AI Prompt Image'
        + (title ? '\n' + title : '')
        + (id ? '\nhttps://aiknowledgecms.exbridge.jp/pimagev.php?id=' + encodeURIComponent(id) : '');
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    }
}
</script>
</body>
</html>
