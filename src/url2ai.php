<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set("Asia/Tokyo");

/* =========================================================
   セッション
========================================================= */
if (session_status() === PHP_SESSION_NONE) {
    $sl = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $sl);
    ini_set('session.cookie_lifetime', $sl);
    ini_set('session.cookie_path',     '/');
    ini_set('session.cookie_domain',   'aiknowledgecms.exbridge.jp');
    ini_set('session.cookie_secure',   '1');
    ini_set('session.cookie_httponly',  '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), time() + $sl, '/',
            'aiknowledgecms.exbridge.jp', true, true);
    }
}

/* =========================================================
   X OAuth2 PKCE
========================================================= */
$x_keys_file = __DIR__ . '/x_api_keys.sh';
$x_keys = array();
if (file_exists($x_keys_file)) {
    foreach (file($x_keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m)) {
            $x_keys[trim($m[1])] = trim($m[2]);
        }
    }
}
$x_client_id     = (isset($x_keys['X_API_KEY']) ? $x_keys['X_API_KEY'] : '');
$x_client_secret = (isset($x_keys['X_API_SECRET']) ? $x_keys['X_API_SECRET'] : '');
$x_redirect_uri  = 'https://aiknowledgecms.exbridge.jp/url2ai.php';

function ep_base64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function ep_gen_verifier() {
    $b = ''; for ($i = 0; $i < 32; $i++) $b .= chr(mt_rand(0,255)); return ep_base64url($b);
}
function ep_gen_challenge($v) { return ep_base64url(hash('sha256', $v, true)); }
function ep_x_post($url, $post, $headers) {
    $res = @file_get_contents($url, false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $post, 'timeout' => 12, 'ignore_errors' => true,
    ]]));
    return json_decode($res ?: '{}', true);
}
function ep_x_get($url, $token) {
    $res = @file_get_contents($url, false, stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer $token\r\nUser-Agent: URL2AI/1.0\r\n",
        'timeout' => 12, 'ignore_errors' => true,
    ]]));
    return json_decode($res ?: '{}', true);
}

if (isset($_GET['ss_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time()-3600, '/', 'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri); exit;
}
if (isset($_GET['ss_login'])) {
    $v = ep_gen_verifier(); $c = ep_gen_challenge($v); $s = md5(uniqid('', true));
    $_SESSION['ss_code_verifier'] = $v; $_SESSION['ss_oauth_state'] = $s;
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => $x_client_id,
        'redirect_uri' => $x_redirect_uri,
        'scope' => 'tweet.read users.read offline.access',
        'state' => $s, 'code_challenge' => $c, 'code_challenge_method' => 'S256',
    ])); exit;
}
if (isset($_GET['code'], $_GET['state'], $_SESSION['ss_oauth_state']) && $_GET['state'] === $_SESSION['ss_oauth_state']) {
    $cred = base64_encode($x_client_id . ':' . $x_client_secret);
    $data = ep_x_post('https://api.twitter.com/2/oauth2/token', http_build_query([
        'grant_type' => 'authorization_code', 'code' => $_GET['code'],
        'redirect_uri' => $x_redirect_uri, 'code_verifier' => $_SESSION['ss_code_verifier'],
        'client_id' => $x_client_id,
    ]), ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . $cred]);
    if (isset($data['access_token'])) {
        $_SESSION['session_access_token'] = $data['access_token'];
        $_SESSION['session_token_expires'] = time() + ((isset($data['expires_in']) ? $data['expires_in'] : 7200));
        if (!empty($data['refresh_token'])) $_SESSION['session_refresh_token'] = $data['refresh_token'];
        $me = ep_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
        if (isset($me['data']['username'])) $_SESSION['session_username'] = $me['data']['username'];
        unset($_SESSION['ss_oauth_state'], $_SESSION['ss_code_verifier']);
    }
    header('Location: ' . $x_redirect_uri); exit;
}

$logged_in = !empty($_SESSION['session_access_token']);
$username  = (isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '');
$is_admin  = ($username === 'xb_bittensor');

/* =========================================================
   ヘルパー
========================================================= */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function api_post($url, $payload, $timeout = 90) {
    $body = json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Content-Length: ' . strlen($body)),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res === false) return null;
    return json_decode($res, true);
}

define('OSS2API',  'http://exbridge.ddns.net:8015/oss2api');
define('PDF2MD',   'http://exbridge.ddns.net:8010');
define('DATA_DIR', __DIR__ . '/data');

function save_result($tab, $input, $result) {
    $ts   = date('YmdHis');
    $file = DATA_DIR . '/url2ai_' . $tab . '_' . $ts . '.json';
    file_put_contents($file, json_encode([
        'tab'      => $tab,
        'input'    => $input,
        'result'   => $result,
        'saved_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $ts;
}

/* =========================================================
   POST処理
========================================================= */
$tab    = isset($_POST['tab'])    ? $_POST['tab']    : (isset($_GET['tab']) ? $_GET['tab'] : 'analyze');
$error  = '';
$result = null;
$input  = [];

$valid_tabs = ['analyze', 'browse', 'scan', 'bgremove', 'pdf'];
if (!in_array($tab, $valid_tabs)) $tab = 'analyze';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && isset($_POST['run'])) {
    $tab = (isset($_POST['tab']) ? $_POST['tab'] : 'analyze');

    if ($tab === 'analyze') {
        $url   = trim((isset($_POST['url']) ? $_POST['url'] : ''));
        $depth = (isset($_POST['depth']) ? $_POST['depth'] : 'full');
        $fmt   = (isset($_POST['format']) ? $_POST['format'] : 'json');
        if ($url === '') { $error = 'URLを入力してください'; }
        else {
            $input  = ['url' => $url, 'depth' => $depth, 'format' => $fmt];
            $result = api_post(OSS2API . '/url/analyze', $input, 30);
            if ($result) save_result('analyze', $input, $result);
            else $error = 'API呼び出しに失敗しました';
        }

    } elseif ($tab === 'browse') {
        $url    = trim((isset($_POST['url']) ? $_POST['url'] : ''));
        $action = (isset($_POST['action']) ? $_POST['action'] : 'screenshot');
        if ($url === '') { $error = 'URLを入力してください'; }
        else {
            $input  = ['url' => $url, 'action' => $action];
            $result = api_post(OSS2API . '/url/browse', $input, 60);
            if ($result) save_result('browse', $input, $result);
            else $error = 'API呼び出しに失敗しました（Playwright起動に時間がかかる場合があります）';
        }

    } elseif ($tab === 'scan') {
        $url = trim((isset($_POST['url']) ? $_POST['url'] : ''));
        if ($url === '') { $error = 'URLを入力してください'; }
        else {
            $input  = ['url' => $url];
            $result = api_post(OSS2API . '/url/scan', $input, 90);
            if ($result) save_result('scan', $input, $result);
            else $error = 'API呼び出しに失敗しました';
        }

    } elseif ($tab === 'bgremove') {
        $image_url = trim((isset($_POST['image_url']) ? $_POST['image_url'] : ''));
        $mode      = (isset($_POST['mode']) ? $_POST['mode'] : 'remove');
        $bg_color  = trim((isset($_POST['background_color']) ? $_POST['background_color'] : '#ffffff'));
        $blur_sig  = (int)((isset($_POST['blur_sigma']) ? $_POST['blur_sigma'] : 18));
        $out_fmt   = (isset($_POST['output_format']) ? $_POST['output_format'] : 'png');
        if ($image_url === '') { $error = '画像URLを入力してください'; }
        else {
            $payload = ['image_url' => $image_url, 'mode' => $mode, 'response' => 'json', 'output_format' => $out_fmt];
            if ($mode === 'replace') $payload['background_color'] = $bg_color;
            if ($mode === 'blur')    $payload['blur_sigma'] = $blur_sig;
            $input  = $payload;
            $result = api_post(OSS2API . '/image/remove-background', $payload, 60);
            if ($result) save_result('bgremove', $input, $result);
            else $error = 'API呼び出しに失敗しました';
        }

    } elseif ($tab === 'pdf') {
        $pdf_url = trim((isset($_POST['pdf_url']) ? $_POST['pdf_url'] : ''));
        $ticker  = trim((isset($_POST['ticker']) ? $_POST['ticker'] : ''));
        $pages   = trim((isset($_POST['pages']) ? $_POST['pages'] : ''));
        if ($pdf_url === '' && $ticker === '') { $error = 'PDF URL またはティッカーを入力してください'; }
        else {
            if ($pdf_url !== '') {
                $payload = ['pdf_url' => $pdf_url];
                if ($pages !== '') $payload['pages'] = $pages;
                $endpoint = PDF2MD . '/pdf/convert';
            } else {
                $payload  = ['ticker' => $ticker];
                $endpoint = PDF2MD . '/pdf/report';
            }
            $input  = $payload;
            $result = api_post($endpoint, $payload, 120);
            if ($result) save_result('pdf', $input, $result);
            else $error = 'API呼び出しに失敗しました';
        }
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>URL2AI Demo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#2563eb;--accent-h:#1d4ed8;
    --green:#059669;--red:#dc2626;--orange:#d97706;--purple:#7c3aed;
    --text:#0f172a;--muted:#64748b;
    --mono:'JetBrains Mono',monospace;--sans:'Inter',sans-serif;
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;font-size:14px}
header{background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.logo{font-size:1.1rem;font-weight:700;letter-spacing:-.02em}
.logo span{color:var(--accent)}
.logo-group{display:flex;align-items:center;gap:6px}
.u2a-badge{background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px}
.userbar{display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:var(--muted)}
.userbar strong{color:var(--green)}
.btn-sm{background:none;border:1px solid var(--border2);color:var(--muted);padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-sm:hover{border-color:var(--red);color:var(--red)}
.container{max-width:1000px;margin:0 auto;padding:1.5rem}
.section{background:var(--surface);border:1px solid var(--border);border-radius:10px;margin-bottom:1rem;overflow:hidden}
.section-header{padding:.75rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between}
.section-title{font-weight:600;font-size:.85rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.section-body{padding:1rem}
.row{display:flex;gap:.6rem;align-items:flex-start;flex-wrap:wrap}
input[type=text],input[type=url],select{border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text);background:var(--surface)}
input[type=text]:focus,input[type=url]:focus,select:focus{border-color:var(--accent)}
input.input-url{flex:1;min-width:0}
.form-group{display:flex;flex-direction:column;gap:.3rem;margin-bottom:.75rem}
.form-group label{font-size:.78rem;font-weight:600;color:var(--muted)}
textarea.code-area{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.75rem;font-family:var(--mono);font-size:.8rem;line-height:1.7;outline:none;resize:vertical;color:var(--text);transition:border .15s;min-height:180px;background:#f8fafc}
textarea.code-area:focus{border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans)}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn-green{background:var(--green);color:#fff}
.btn-green:hover{background:#047857}
.btn:disabled{opacity:.5;cursor:not-allowed}
.msg-error{color:var(--red);font-size:.82rem;margin-top:.5rem;padding:.5rem .75rem;background:#fef2f2;border:1px solid #fecaca;border-radius:6px}
/* tabs */
.tabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:1.5rem;overflow-x:auto}
.tab{padding:.6rem 1.1rem;font-size:.82rem;font-weight:600;cursor:pointer;border:none;background:none;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:color .15s}
.tab:hover{color:var(--text)}
.tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-panel{display:none}
.tab-panel.active{display:block}
/* result */
.result-box{margin-top:1rem}
.result-meta{font-size:.75rem;color:var(--muted);margin-bottom:.5rem;font-family:var(--mono)}
.risk-bar{height:8px;border-radius:4px;background:#e2e8f0;margin:.5rem 0 1rem}
.risk-bar-inner{height:100%;border-radius:4px;transition:width .4s}
.risk-score{font-size:2rem;font-weight:700;margin:.5rem 0}
.findings-group{margin-bottom:.75rem}
.findings-group h5{font-size:.78rem;font-weight:700;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em}
.finding-item{font-size:.8rem;padding:.3rem .6rem;border-radius:4px;margin-bottom:.2rem;font-family:var(--mono)}
.pill-critical{background:#fef2f2;color:#991b1b}
.pill-high{background:#fff7ed;color:#9a3412}
.pill-medium{background:#fffbeb;color:#92400e}
.pill-low{background:#f0fdf4;color:#166534}
.pill-info{background:#eff6ff;color:#1e40af}
.result-img{max-width:100%;border-radius:8px;border:1px solid var(--border);margin-top:.5rem}
.kv-table{width:100%;border-collapse:collapse;font-size:.82rem}
.kv-table th,.kv-table td{padding:.4rem .6rem;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}
.kv-table th{width:130px;color:var(--muted);font-weight:600;white-space:nowrap}
.link-list{list-style:none;font-size:.8rem;line-height:2}
.link-list a{color:var(--accent);text-decoration:none}
.link-list a:hover{text-decoration:underline}
.spinner{display:none;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}
.generating-msg{display:none;text-align:center;padding:12px 16px;font-size:.82rem;color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;margin-bottom:1rem;font-weight:600}
@media(max-width:600px){.row{flex-wrap:wrap}.row input{flex:1 1 100%}}
</style>
</head>
<body>
<header>
    <div class="logo-group">
        <div class="logo">URL<span>2AI</span></div>
        <span class="u2a-badge">URL2AI</span>
        <span style="font-size:.85rem;color:var(--muted)">OSS2API Demo</span>
    </div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?ss_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?ss_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
        <a href="url2aiv.php" class="btn-sm">履歴 →</a>
    </div>
</header>

<div class="container">

    <!-- タブナビ -->
    <div class="tabs">
        <button class="tab <?php echo $tab==='analyze'  ? 'active':'' ?>" onclick="switchTab('analyze')">🔍 URL解析</button>
        <button class="tab <?php echo $tab==='browse'   ? 'active':'' ?>" onclick="switchTab('browse')">🌐 ブラウズ</button>
        <button class="tab <?php echo $tab==='scan'     ? 'active':'' ?>" onclick="switchTab('scan')">🔒 セキュリティスキャン</button>
        <button class="tab <?php echo $tab==='bgremove' ? 'active':'' ?>" onclick="switchTab('bgremove')">🖼 背景除去</button>
        <button class="tab <?php echo $tab==='pdf'      ? 'active':'' ?>" onclick="switchTab('pdf')">📄 PDF→MD</button>
    </div>

    <?php if ($error): ?>
    <div class="msg-error"><?php echo h($error); ?></div>
    <?php endif; ?>


    <div id="generating-msg" class="generating-msg">⏳ 処理中です。しばらくお待ちください...</div>

    <!-- ========== URL解析 ========== -->
    <div class="tab-panel <?php echo $tab==='analyze' ? 'active':'' ?>" id="panel-analyze">
        <form method="POST" onsubmit="showGenerating()"><input type="hidden" name="run" value="1">
            <input type="hidden" name="tab" value="analyze">
            <div class="section">
                <div class="section-header"><div class="section-title">🔍 URL構造抽出</div></div>
                <div class="section-body">
                    <div class="row" style="margin-bottom:.75rem">
                        <input type="url" name="url" class="input-url" placeholder="https://example.com" value="<?php echo h($tab==='analyze' ? ((isset($input['url']) ? $input['url'] : '')) : '') ?>">
                    </div>
                    <div class="row" style="margin-bottom:.75rem;gap:1rem">
                        <div class="form-group">
                            <label>深さ</label>
                            <select name="depth">
                                <option value="full" <?php echo (((isset($input['depth']) ? $input['depth'] : 'full'))==='full')?'selected':'' ?>>full（最大8000文字）</option>
                                <option value="basic" <?php echo (((isset($input['depth']) ? $input['depth'] : ''))==='basic')?'selected':'' ?>>basic（要約600文字）</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>フォーマット</label>
                            <select name="format">
                                <option value="json" <?php echo (((isset($input['format']) ? $input['format'] : 'json'))==='json')?'selected':'' ?>>JSON</option>
                                <option value="markdown" <?php echo (((isset($input['format']) ? $input['format'] : ''))==='markdown')?'selected':'' ?>>Markdown</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="run" class="btn btn-green" <?php echo !$is_admin?'disabled':'' ?>>
                        <span class="btn-label">✦ 解析実行</span><span class="spinner"></span>
                    </button>
                    <?php if (!$is_admin): ?><span style="font-size:.78rem;color:var(--muted);margin-left:.5rem">ログインが必要です</span><?php endif; ?>
                </div>
            </div>
        </form>
        <?php if ($tab==='analyze' && $result): ?>
        <div class="section result-box">
            <div class="section-header"><div class="section-title"><span style="color:var(--green)">✓</span> 解析結果</div>
                <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:.25rem .6rem" onclick="copyJSON('result-analyze')">コピー</button>
            </div>
            <div class="section-body">
                <table class="kv-table">
                    <tr><th>タイトル</th><td><?php echo h((isset($result['title']) ? $result['title'] : '')) ?></td></tr>
                    <tr><th>説明</th><td><?php echo h((isset($result['description']) ? $result['description'] : '')) ?></td></tr>
                    <tr><th>要約</th><td><?php echo h((isset($result['summary']) ? $result['summary'] : '')) ?></td></tr>
                </table>
                <?php if (!empty($result['headings'])): ?>
                <div style="margin-top:.75rem"><strong style="font-size:.8rem">見出し</strong>
                    <ul style="margin-top:.3rem;padding-left:1.2rem;font-size:.82rem;line-height:1.8">
                        <?php foreach ($result['headings'] as $h2): ?>
                        <li><code style="font-size:.75rem;color:var(--muted)"><?php echo h((isset($h2['tag']) ? $h2['tag'] : '')) ?></code> <?php echo h((isset($h2['text']) ? $h2['text'] : '')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if (!empty($result['links'])): ?>
                <div style="margin-top:.75rem"><strong style="font-size:.8rem">リンク（<?php echo count($result['links']) ?>件）</strong>
                    <ul class="link-list" style="margin-top:.3rem">
                        <?php foreach (array_slice($result['links'],0,10) as $lk): ?>
                        <li><a href="<?php echo h((isset($lk['href']) ? $lk['href'] : '#')) ?>" target="_blank"><?php echo h(isset($lk['text']) ? $lk['text'] : (isset($lk['href']) ? $lk['href'] : '')) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if (!empty($result['content'])): ?>
                <div style="margin-top:.75rem"><strong style="font-size:.8rem">本文</strong>
                    <textarea class="code-area" rows="10" readonly id="result-analyze"><?php echo h($result['content']) ?></textarea>
                </div>
                <?php elseif (!empty($result['markdown'])): ?>
                <div style="margin-top:.75rem"><strong style="font-size:.8rem">Markdown</strong>
                    <textarea class="code-area" rows="10" readonly id="result-analyze"><?php echo h($result['markdown']) ?></textarea>
                </div>
                <?php else: ?>
                <textarea class="code-area" rows="6" readonly id="result-analyze"><?php echo h(json_encode($result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></textarea>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ========== URLブラウズ ========== -->
    <div class="tab-panel <?php echo $tab==='browse' ? 'active':'' ?>" id="panel-browse">
        <form method="POST" onsubmit="showGenerating()"><input type="hidden" name="run" value="1">
            <input type="hidden" name="tab" value="browse">
            <div class="section">
                <div class="section-header"><div class="section-title">🌐 Playwright ブラウズ</div></div>
                <div class="section-body">
                    <div class="row" style="margin-bottom:.75rem">
                        <input type="url" name="url" class="input-url" placeholder="https://example.com" value="<?php echo h($tab==='browse' ? ((isset($input['url']) ? $input['url'] : '')) : '') ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:.75rem">
                        <label>アクション</label>
                        <select name="action" style="width:200px">
                            <option value="screenshot" <?php echo (((isset($input['action']) ? $input['action'] : 'screenshot'))==='screenshot')?'selected':'' ?>>スクリーンショット（PNG）</option>
                            <option value="extract" <?php echo (((isset($input['action']) ? $input['action'] : ''))==='extract')?'selected':'' ?>>テキスト抽出</option>
                        </select>
                    </div>
                    <button type="submit" name="run" class="btn btn-green" <?php echo !$is_admin?'disabled':'' ?>>
                        <span class="btn-label">✦ 実行</span><span class="spinner"></span>
                    </button>
                    <?php if (!$is_admin): ?><span style="font-size:.78rem;color:var(--muted);margin-left:.5rem">ログインが必要です</span><?php endif; ?>
                </div>
            </div>
        </form>
        <?php if ($tab==='browse' && $result): ?>
        <div class="section result-box">
            <div class="section-header"><div class="section-title"><span style="color:var(--green)">✓</span> ブラウズ結果 — <?php echo h((isset($result['title']) ? $result['title'] : '')) ?></div></div>
            <div class="section-body">
                <?php if (!empty($result['screenshot'])): ?>
                <img src="data:image/png;base64,<?php echo $result['screenshot'] ?>" class="result-img" alt="screenshot">
                <?php elseif (!empty($result['content'])): ?>
                <textarea class="code-area" rows="14" readonly><?php echo h($result['content']) ?></textarea>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ========== セキュリティスキャン ========== -->
    <div class="tab-panel <?php echo $tab==='scan' ? 'active':'' ?>" id="panel-scan">
        <form method="POST" onsubmit="showGenerating()"><input type="hidden" name="run" value="1">
            <input type="hidden" name="tab" value="scan">
            <div class="section">
                <div class="section-header"><div class="section-title">🔒 Shannon-like セキュリティスキャン</div></div>
                <div class="section-body">
                    <div class="row" style="margin-bottom:.75rem">
                        <input type="url" name="url" class="input-url" placeholder="https://example.com" value="<?php echo h($tab==='scan' ? ((isset($input['url']) ? $input['url'] : '')) : '') ?>">
                    </div>
                    <p style="font-size:.78rem;color:var(--muted);margin-bottom:.75rem">3フェーズ：HTTPヘッダ解析 → 静的HTML解析 → Ollama AI分析</p>
                    <button type="submit" name="run" class="btn btn-green" <?php echo !$is_admin?'disabled':'' ?>>
                        <span class="btn-label">✦ スキャン実行</span><span class="spinner"></span>
                    </button>
                    <?php if (!$is_admin): ?><span style="font-size:.78rem;color:var(--muted);margin-left:.5rem">ログインが必要です</span><?php endif; ?>
                </div>
            </div>
        </form>
        <?php if ($tab==='scan' && $result): ?>
        <?php
            $score = (isset($result['risk_score']) ? $result['risk_score'] : 0);
            $score_color = $score >= 70 ? '#dc2626' : ($score >= 40 ? '#d97706' : '#059669');
        ?>
        <div class="section result-box">
            <div class="section-header"><div class="section-title"><span style="color:var(--green)">✓</span> スキャン結果</div></div>
            <div class="section-body">
                <div style="display:flex;align-items:center;gap:1.5rem;margin-bottom:1rem">
                    <div>
                        <div style="font-size:.75rem;color:var(--muted);font-weight:600">RISK SCORE</div>
                        <div class="risk-score" style="color:<?php echo $score_color ?>"><?php echo $score ?><span style="font-size:1rem;color:var(--muted)">/100</span></div>
                    </div>
                    <div style="flex:1">
                        <div class="risk-bar"><div class="risk-bar-inner" style="width:<?php echo $score ?>%;background:<?php echo $score_color ?>"></div></div>
                        <div style="font-size:.8rem;color:var(--muted)"><?php echo h((isset($result['summary']) ? $result['summary'] : '')) ?></div>
                    </div>
                </div>
                <?php $findings = isset($result['findings']) ? $result['findings'] : array(); ?>
                <?php foreach (['critical'=>'pill-critical','high'=>'pill-high','medium'=>'pill-medium','low'=>'pill-low','info'=>'pill-info'] as $sev => $cls): ?>
                <?php if (!empty($findings[$sev])): ?>
                <div class="findings-group">
                    <h5 style="color:<?php echo ['critical'=>'#991b1b','high'=>'#9a3412','medium'=>'#92400e','low'=>'#166534','info'=>'#1e40af'][$sev] ?>"><?php echo strtoupper($sev) ?> (<?php echo count($findings[$sev]) ?>)</h5>
                    <?php foreach ($findings[$sev] as $f): ?>
                    <div class="finding-item <?php echo $cls ?>"><?php echo h($f) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!empty($result['actions'])): ?>
                <div style="margin-top:1rem"><strong style="font-size:.8rem">推奨アクション</strong>
                    <ul style="margin-top:.3rem;padding-left:1.2rem;font-size:.82rem;line-height:2">
                        <?php foreach ($result['actions'] as $a): ?>
                        <li><?php echo h($a) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ========== 背景除去 ========== -->
    <div class="tab-panel <?php echo $tab==='bgremove' ? 'active':'' ?>" id="panel-bgremove">
        <form method="POST" onsubmit="showGenerating()" id="form-bgremove"><input type="hidden" name="run" value="1">
            <input type="hidden" name="tab" value="bgremove">
            <div class="section">
                <div class="section-header"><div class="section-title">🖼 画像背景除去 / 置換 / ぼかし</div></div>
                <div class="section-body">
                    <div class="form-group">
                        <label>画像URL</label>
                        <input type="url" name="image_url" class="input-url" placeholder="https://example.com/photo.jpg" value="<?php echo h($tab==='bgremove' ? ((isset($input['image_url']) ? $input['image_url'] : '')) : '') ?>">
                    </div>
                    <div class="row" style="gap:1rem;margin-bottom:.75rem">
                        <div class="form-group">
                            <label>モード</label>
                            <select name="mode" id="bgmode" onchange="updateBgOptions()">
                                <option value="remove"  <?php echo (((isset($input['mode']) ? $input['mode'] : 'remove'))==='remove') ?'selected':'' ?>>remove（透過PNG）</option>
                                <option value="replace" <?php echo (((isset($input['mode']) ? $input['mode'] : ''))==='replace')?'selected':'' ?>>replace（背景色）</option>
                                <option value="blur"    <?php echo (((isset($input['mode']) ? $input['mode'] : ''))==='blur')?'selected':'' ?>>blur（ぼかし）</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>出力形式</label>
                            <select name="output_format">
                                <option value="png" <?php echo (((isset($input['output_format']) ? $input['output_format'] : 'png'))==='png')?'selected':'' ?>>PNG</option>
                                <option value="webp" <?php echo (((isset($input['output_format']) ? $input['output_format'] : ''))==='webp')?'selected':'' ?>>WebP</option>
                                <option value="jpeg" <?php echo (((isset($input['output_format']) ? $input['output_format'] : ''))==='jpeg')?'selected':'' ?>>JPEG</option>
                            </select>
                        </div>
                    </div>
                    <div id="opt-replace" style="display:none;margin-bottom:.75rem">
                        <div class="form-group">
                            <label>背景色（replace）</label>
                            <input type="text" name="background_color" placeholder="#ffffff" value="<?php echo h((isset($input['background_color']) ? $input['background_color'] : '#ffffff')) ?>" style="width:160px">
                        </div>
                    </div>
                    <div id="opt-blur" style="display:none;margin-bottom:.75rem">
                        <div class="form-group">
                            <label>ぼかし強度（1〜80）</label>
                            <input type="text" name="blur_sigma" value="<?php echo h((isset($input['blur_sigma']) ? $input['blur_sigma'] : '18')) ?>" style="width:80px">
                        </div>
                    </div>
                    <button type="submit" name="run" class="btn btn-green" <?php echo !$is_admin?'disabled':'' ?>>
                        <span class="btn-label">✦ 実行</span><span class="spinner"></span>
                    </button>
                    <?php if (!$is_admin): ?><span style="font-size:.78rem;color:var(--muted);margin-left:.5rem">ログインが必要です</span><?php endif; ?>
                </div>
            </div>
        </form>
        <?php if ($tab==='bgremove' && $result && !empty($result['image_base64'])): ?>
        <div class="section result-box">
            <div class="section-header">
                <div class="section-title"><span style="color:var(--green)">✓</span> 処理結果（<?php echo h((isset($result['content_type']) ? $result['content_type'] : '')) ?>）</div>
                <a href="data:<?php echo h((isset($result['content_type']) ? $result['content_type'] : 'image/png')) ?>;base64,<?php echo $result['image_base64'] ?>" download="result.<?php echo h((isset($result['content_type']) ? $result['content_type'] : 'png'))==='image/png'?'png':($result['content_type']==='image/webp'?'webp':'jpg') ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.25rem .6rem">ダウンロード</a>
            </div>
            <div class="section-body" style="background:#e5e7eb">
                <img src="data:<?php echo h((isset($result['content_type']) ? $result['content_type'] : 'image/png')) ?>;base64,<?php echo $result['image_base64'] ?>" class="result-img" alt="result">
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ========== PDF→MD ========== -->
    <div class="tab-panel <?php echo $tab==='pdf' ? 'active':'' ?>" id="panel-pdf">
        <form method="POST" onsubmit="showGenerating()"><input type="hidden" name="run" value="1">
            <input type="hidden" name="tab" value="pdf">
            <div class="section">
                <div class="section-header"><div class="section-title">📄 PDF→Markdown / 投資レポート</div></div>
                <div class="section-body">
                    <div class="form-group">
                        <label>PDF URL（公開URLから変換）</label>
                        <input type="url" name="pdf_url" class="input-url" placeholder="https://example.com/document.pdf" value="<?php echo h($tab==='pdf' ? ((isset($input['pdf_url']) ? $input['pdf_url'] : '')) : '') ?>">
                    </div>
                    <div style="text-align:center;font-size:.78rem;color:var(--muted);margin:.5rem 0">— または —</div>
                    <div class="form-group">
                        <label>ティッカー / 企業名（投資レポート生成）</label>
                        <input type="text" name="ticker" placeholder="NVIDIA / Apple / ビットコイン" style="max-width:320px" value="<?php echo h($tab==='pdf' ? ((isset($input['ticker']) ? $input['ticker'] : '')) : '') ?>">
                    </div>
                    <div class="form-group">
                        <label>ページ指定（PDFのみ、例: 1-3,5）</label>
                        <input type="text" name="pages" placeholder="1-5" style="max-width:160px" value="<?php echo h($tab==='pdf' ? ((isset($input['pages']) ? $input['pages'] : '')) : '') ?>">
                    </div>
                    <button type="submit" name="run" class="btn btn-green" <?php echo !$is_admin?'disabled':'' ?>>
                        <span class="btn-label">✦ 変換実行</span><span class="spinner"></span>
                    </button>
                    <?php if (!$is_admin): ?><span style="font-size:.78rem;color:var(--muted);margin-left:.5rem">ログインが必要です</span><?php endif; ?>
                </div>
            </div>
        </form>
        <?php if ($tab==='pdf' && $result): ?>
        <div class="section result-box">
            <div class="section-header">
                <div class="section-title"><span style="color:var(--green)">✓</span> 変換結果</div>
                <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:.25rem .6rem" onclick="copyEl('pdf-result')">コピー</button>
            </div>
            <div class="section-body">
                <?php if (!empty($result['summary'])): ?>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:.75rem;font-size:.82rem;margin-bottom:.75rem"><?php echo h($result['summary']) ?></div>
                <?php endif; ?>
                <textarea class="code-area" id="pdf-result" rows="18" readonly><?php echo h(isset($result['markdown']) ? $result['markdown'] : json_encode($result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></textarea>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
function switchTab(t) {
    document.querySelectorAll('.tab').forEach(function(b){ b.classList.remove('active'); });
    document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
    var idx = ['analyze','browse','scan','bgremove','pdf'].indexOf(t);
    document.querySelectorAll('.tab')[idx].classList.add('active');
    document.getElementById('panel-'+t).classList.add('active');
}
function showGenerating() {
    document.getElementById('generating-msg').style.display = 'block';
    document.querySelectorAll('button[name=run]').forEach(function(b){
        b.disabled = true; b.classList.add('loading');
    });
}
function updateBgOptions() {
    var mode = document.getElementById('bgmode').value;
    document.getElementById('opt-replace').style.display = mode==='replace' ? 'block':'none';
    document.getElementById('opt-blur').style.display    = mode==='blur'    ? 'block':'none';
}
function copyEl(id) {
    var el = document.getElementById(id);
    if (!el) return;
    navigator.clipboard ? navigator.clipboard.writeText(el.value) : (el.select(), document.execCommand('copy'));
}
function copyJSON(id) { copyEl(id); }
// 初期状態
updateBgOptions();
<?php if ($tab==='bgremove' && !empty($input['mode'])): ?>
document.getElementById('bgmode').value = '<?php echo h($input['mode']) ?>';
updateBgOptions();
<?php endif; ?>
</script>
</body>
</html>
