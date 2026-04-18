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
    $input = trim($input);
    if (preg_match('/(\d{15,20})/', $input, $m)) {
        return $m[1];
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
function ui_default_prompt_template() {
    return "以下はXの投稿スレッドです。この内容をもとに、1枚の印象的な画像を生成するためのビジュアル表現を行います。\n\n条件：\n- 日本語でそのまま画像生成モデルに渡せるプロンプトにする\n- X投稿の主題・感情・状況を抽出して、視覚的なシーンに変換する\n- 写実寄りでもイラスト寄りでもよいが、雰囲気・構図・光・色を具体的に入れる\n- 人物が出る場合は年齢感・服装・表情・背景も補う\n- 説明文や前置きは不要。画像生成用プロンプトだけを出力する\n\n---\n{thread}\n---";
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

$default_prompt = ui_default_prompt_template();
$action = isset($_POST['action']) ? $_POST['action'] : '';
$tweet_url = isset($_POST['tweet_url']) ? trim($_POST['tweet_url']) : '';
$thread_text = isset($_POST['thread_text']) ? trim($_POST['thread_text']) : '';
$image_prompt = isset($_POST['image_prompt']) ? trim($_POST['image_prompt']) : '';
$negative_prompt = isset($_POST['negative_prompt']) ? trim($_POST['negative_prompt']) : '';
$generated_image_path = '';
$fetch_error = isset($_SESSION['ui_flash_error']) ? $_SESSION['ui_flash_error'] : '';
if (isset($_SESSION['ui_flash_error'])) {
    unset($_SESSION['ui_flash_error']);
}

if ($tweet_url === '' && isset($_GET['tweet_url']) && $_GET['tweet_url'] !== '') {
    $tweet_url = trim($_GET['tweet_url']);
}

if ($tweet_url !== '') {
    $tweet_id_get = ui_extract_tweet_id($tweet_url);
    if ($tweet_id_get !== '') {
        $save_file_get = $DATA_DIR . '/xinsight_' . $tweet_id_get . '.json';
        if (file_exists($save_file_get)) {
            $saved_get = json_decode(file_get_contents($save_file_get), true);
            if (is_array($saved_get)) {
                if ($thread_text === '') {
                    $thread_text = isset($saved_get['thread_text']) ? $saved_get['thread_text'] : '';
                }
                if ($image_prompt === '') {
                    $image_prompt = isset($saved_get['uimage_prompt']) ? $saved_get['uimage_prompt'] : '';
                }
                if ($negative_prompt === '') {
                    $negative_prompt = isset($saved_get['uimage_negative_prompt']) ? $saved_get['uimage_negative_prompt'] : '';
                }
                $generated_image_path = isset($saved_get['uimage_path']) ? $saved_get['uimage_path'] : '';
                $tweet_url = isset($saved_get['tweet_url']) ? $saved_get['tweet_url'] : $tweet_url;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    if ($action === 'fetch' && $tweet_url !== '') {
        $tweet_id = ui_extract_tweet_id($tweet_url);
        if ($tweet_id === '') {
            $_SESSION['ui_flash_error'] = 'URLからツイートIDを取得できませんでした';
            header('Location: ' . $x_redirect_uri);
            exit;
        }
        $thread = ui_fetch_thread($tweet_id, 0);
        if (empty($thread)) {
            $_SESSION['ui_flash_error'] = 'ツイートを取得できませんでした';
            header('Location: ' . $x_redirect_uri);
            exit;
        }
        $thread_text = ui_thread_to_text($thread);
        if (!is_dir($DATA_DIR)) {
            @mkdir($DATA_DIR, 0775, true);
        }
        $save_file = $DATA_DIR . '/xinsight_' . $tweet_id . '.json';
        $save_data = file_exists($save_file) ? json_decode(file_get_contents($save_file), true) : array();
        if (!is_array($save_data)) {
            $save_data = array();
        }
        $save_data['tweet_id'] = $tweet_id;
        $save_data['tweet_url'] = $tweet_url;
        $save_data['username'] = $username;
        $save_data['thread_text'] = $thread_text;
        if (!isset($save_data['uimage_prompt'])) {
            $save_data['uimage_prompt'] = '';
        }
        if (!isset($save_data['uimage_path'])) {
            $save_data['uimage_path'] = '';
        }
        $save_data['saved_at'] = date('Y-m-d H:i:s');
        file_put_contents($save_file, json_encode($save_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        header('Location: ' . $x_redirect_uri . '?tweet_url=' . urlencode($tweet_url));
        exit;
    }

    if ($action === 'generate' && $thread_text !== '' && $tweet_url !== '') {
        $tweet_id = ui_extract_tweet_id($tweet_url);
        if ($image_prompt === '') {
            $image_prompt = str_replace('{thread}', $thread_text, $default_prompt);
        }
        $payload = array(
            'prompt' => $image_prompt,
            'negative_prompt' => $negative_prompt,
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
            header('Location: ' . $x_redirect_uri . '?tweet_url=' . urlencode($tweet_url));
            exit;
        }
        if (empty($response['image_base64'])) {
            $_SESSION['ui_flash_error'] = '画像データが返ってきませんでした';
            header('Location: ' . $x_redirect_uri . '?tweet_url=' . urlencode($tweet_url));
            exit;
        }
        $image_path = ui_save_image_binary($tweet_id, isset($response['output_format']) ? $response['output_format'] : 'png', $response['image_base64']);
        if ($image_path === '') {
            $_SESSION['ui_flash_error'] = '生成画像の保存に失敗しました';
            header('Location: ' . $x_redirect_uri . '?tweet_url=' . urlencode($tweet_url));
            exit;
        }
        if (!is_dir($DATA_DIR)) {
            @mkdir($DATA_DIR, 0775, true);
        }
        $save_file = $DATA_DIR . '/xinsight_' . $tweet_id . '.json';
        $save_data = file_exists($save_file) ? json_decode(file_get_contents($save_file), true) : array();
        if (!is_array($save_data)) {
            $save_data = array();
        }
        $save_data['tweet_id'] = $tweet_id;
        $save_data['tweet_url'] = $tweet_url;
        $save_data['username'] = $username;
        $save_data['thread_text'] = $thread_text;
        $save_data['uimage_prompt'] = $image_prompt;
        $save_data['uimage_negative_prompt'] = $negative_prompt;
        $save_data['uimage_path'] = $image_path;
        $save_data['uimage_saved_at'] = date('Y-m-d H:i:s');
        $save_data['saved_at'] = date('Y-m-d H:i:s');
        file_put_contents($save_file, json_encode($save_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        header('Location: ' . $BASE_URL . '/' . $VIEW_FILE . '?id=' . urlencode($tweet_id));
        exit;
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UImage</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f8fafc;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#ec4899;--accent-h:#db2777;--green:#059669;--red:#dc2626;
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
.section-header{padding:.75rem 1rem;border-bottom:1px solid var(--border);background:#fff7fb;display:flex;align-items:center;justify-content:space-between}
.section-title{font-weight:600;font-size:.85rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.step{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700}
.section-body{padding:1rem}
.row{display:flex;gap:.6rem;align-items:flex-start}
input[type=text]{flex:1;border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text)}
input[type=text]:focus{border-color:var(--accent)}
textarea.code-area{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.75rem;font-family:var(--mono);font-size:.8rem;line-height:1.7;outline:none;resize:vertical;color:var(--text);transition:border .15s;min-height:120px;background:#fff}
textarea.code-area:focus{border-color:var(--accent)}
.image-preview{display:block;max-width:100%;height:auto;border-radius:10px;border:1px solid var(--border);background:#fff}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans);text-decoration:none}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn-green{background:var(--green);color:#fff}
.btn-green:hover{background:#047857}
.btn:disabled{opacity:.5;cursor:not-allowed}
.msg-error{color:var(--red);font-size:.8rem;margin-top:.4rem}
.char-count{font-size:.75rem;color:var(--muted);text-align:right;margin-top:.3rem;font-family:var(--mono)}
.hint{font-size:.8rem;color:var(--muted);line-height:1.8}
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}
@media (max-width: 600px) {
    .row { flex-wrap: wrap; }
    .row input[type=text] { flex: 1 1 100%; min-width: 0; }
    .row .btn { flex: 1 1 auto; white-space: nowrap; font-size:.75rem; padding:.45rem .6rem; }
    .container { padding: 1rem; }
    .section-body { padding: .75rem; }
}
</style>
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
            <div class="section-title"><span class="step">1</span> XのURLを入力してスレッドを取得</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-fetch">
                <input type="hidden" name="action" value="fetch">
                <div class="row">
                    <input type="text" name="tweet_url" id="tweet_url_input" placeholder="https://x.com/user/status/..." value="<?php echo h($tweet_url); ?>">
                    <button type="button" class="btn btn-primary" id="btn-fetch"<?php if (!$is_admin): ?> disabled title="管理者のみ生成できます"<?php endif; ?> onclick="submitFetch()">
                        <span class="btn-label">取得</span>
                        <span class="spinner"></span>
                    </button>
                    <?php if ($tweet_url !== ''): ?>
                    <a href="<?php echo h($tweet_url); ?>" target="_blank" class="btn btn-secondary">元の投稿 ↗</a>
                    <?php endif; ?>
                </div>
                <?php if ($fetch_error): ?>
                <div class="msg-error"><?php echo h($fetch_error); ?></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">2</span> スレッド本文</div>
        </div>
        <div class="section-body">
            <textarea class="code-area" id="thread_text" name="thread_text" rows="8" form="form-generate" placeholder="ここに取得したスレッド本文が表示されます。"><?php echo h($thread_text); ?></textarea>
            <div class="char-count" id="thread_count"><?php echo mb_strlen($thread_text); ?> 文字</div>
        </div>
    </div>

    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">3</span> 画像生成プロンプト</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-generate">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="tweet_url" value="<?php echo h($tweet_url); ?>">
                <div style="margin-bottom:.75rem">
                    <textarea class="code-area" id="image_prompt" name="image_prompt" rows="10" placeholder="生成に使う画像プロンプトをここで編集できます。"><?php echo h($image_prompt === '' && $thread_text !== '' ? str_replace('{thread}', $thread_text, $default_prompt) : $image_prompt); ?></textarea>
                </div>
                <div style="margin-bottom:.75rem">
                    <textarea class="code-area" id="negative_prompt" name="negative_prompt" rows="4" placeholder="blurry, low quality など任意で入力"><?php echo h($negative_prompt); ?></textarea>
                </div>
                <div style="display:flex;justify-content:center;margin-bottom:.75rem">
                    <button type="button" class="btn btn-green" id="btn-generate"<?php if (!$is_admin): ?> disabled title="管理者のみ生成できます"<?php endif; ?> style="padding:.65rem 2.5rem;font-size:.9rem" onclick="submitGenerate()">
                        <span class="btn-label">✦ 画像を生成</span>
                        <span class="spinner"></span>
                    </button>
                </div>
                <div class="hint">
                    生成できるのは現在 <strong><?php echo h($ADMIN); ?></strong> のみです。公開ビューは <a href="<?php echo h($VIEW_FILE); ?>" style="color:#db2777;text-decoration:none;">uimagev.php</a> から誰でも参照できます。
                </div>
            </form>
        </div>
    </div>

    <?php if ($generated_image_path !== ''): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--green)">✓</span> 現在の生成画像</div>
            <a class="btn btn-secondary" href="<?php echo h($BASE_URL . '/' . $VIEW_FILE . '?id=' . urlencode(ui_extract_tweet_id($tweet_url))); ?>">viewer を開く</a>
        </div>
        <div class="section-body">
            <img class="image-preview" src="<?php echo h($BASE_URL . '/' . $generated_image_path); ?>" alt="Generated image">
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
var ta = document.getElementById('thread_text');
var counter = document.getElementById('thread_count');
if (ta && counter) {
    ta.addEventListener('input', function() {
        counter.textContent = this.value.length + ' 文字';
    });
}
function lockUI() {
    var btnF = document.getElementById('btn-fetch');
    var btnG = document.getElementById('btn-generate');
    if (btnF) { btnF.disabled = true; btnF.classList.add('loading'); }
    if (btnG) { btnG.disabled = true; btnG.classList.add('loading'); }
}
function submitFetch() {
    lockUI();
    document.getElementById('form-fetch').submit();
}
function submitGenerate() {
    lockUI();
    document.getElementById('form-generate').submit();
}
</script>
</body>
</html>
