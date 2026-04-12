<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set("Asia/Tokyo");

/* =========================================================
   セッション長期維持設定（30日）
========================================================= */
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.cookie_path',     '/');
    ini_set('session.cookie_domain',   'aiknowledgecms.exbridge.jp');
    ini_set('session.cookie_secure',   '1');
    ini_set('session.cookie_httponly',  '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(),
            time() + $session_lifetime, '/',
            'aiknowledgecms.exbridge.jp', true, true);
    }
}

/* =========================================================
   X API キー読み込み
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
$x_client_id     = isset($x_keys['X_API_KEY'])    ? $x_keys['X_API_KEY']    : '';
$x_client_secret = isset($x_keys['X_API_SECRET']) ? $x_keys['X_API_SECRET'] : '';
$x_redirect_uri  = 'https://aiknowledgecms.exbridge.jp/xinsight.php';


/* =========================================================
   OAuth2 PKCE
========================================================= */
function ep_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function ep_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return ep_base64url($bytes);
}
function ep_gen_challenge($verifier) {
    return ep_base64url(hash('sha256', $verifier, true));
}
function ep_x_post($url, $post_data, $headers) {
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'content'       => $post_data,
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}
function ep_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: XInsight/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['xi_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/',
        'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['xi_login'])) {
    $verifier  = ep_gen_verifier();
    $challenge = ep_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['xi_code_verifier'] = $verifier;
    $_SESSION['xi_oauth_state']   = $state;
    $params = array(
        'response_type'         => 'code',
        'client_id'             => $x_client_id,
        'redirect_uri'          => $x_redirect_uri,
        'scope'                 => 'tweet.read users.read offline.access',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    );
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($params));
    exit;
}
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['xi_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['xi_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['xi_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = ep_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']  = $data['access_token'];
            $_SESSION['session_token_expires']  = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['xi_oauth_state'], $_SESSION['xi_code_verifier']);
            $me = ep_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) {
                $_SESSION['session_username'] = $me['data']['username'];
            }
        }
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

/* =========================================================
   アクセストークン自動リフレッシュ
========================================================= */
if (
    !empty($_SESSION['session_refresh_token']) &&
    !empty($_SESSION['session_token_expires']) &&
    time() > $_SESSION['session_token_expires'] - 300
) {
    $cred_r = base64_encode($x_client_id . ':' . $x_client_secret);
    $post_r = http_build_query(array(
        'grant_type'    => 'refresh_token',
        'refresh_token' => $_SESSION['session_refresh_token'],
        'client_id'     => $x_client_id,
    ));
    $ref = ep_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . $cred_r,
    ));
    if (!empty($ref['access_token'])) {
        $_SESSION['session_access_token']  = $ref['access_token'];
        $_SESSION['session_token_expires'] = time() + (isset($ref['expires_in']) ? (int)$ref['expires_in'] : 7200);
        if (!empty($ref['refresh_token'])) {
            $_SESSION['session_refresh_token'] = $ref['refresh_token'];
        }
    } else {
        unset($_SESSION['session_access_token'], $_SESSION['session_refresh_token'], $_SESSION['session_token_expires'], $_SESSION['session_username']);
    }
}

$logged_in = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$username  = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin  = ($username === 'xb_bittensor');

/* =========================================================
   ヘルパー
========================================================= */
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function extract_tweet_id($input) {
    $input = trim($input);
    if (preg_match('/(\d{15,20})/', $input, $m)) {
        return $m[1];
    }
    return '';
}

function fx_get($tweet_id) {
    $url  = 'https://api.fxtwitter.com/i/status/' . preg_replace('/[^0-9]/', '', $tweet_id);
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
        'timeout'       => 15,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) return null;
    return json_decode($res, true);
}

function fetch_thread($tweet_id, $depth) {
    if ($depth > 15) return array();
    $data = fx_get($tweet_id);
    if (!$data || empty($data['tweet'])) return array();
    $tweet  = $data['tweet'];
    $result = array();
    if (!empty($tweet['replying_to_status'])) {
        $result = fetch_thread($tweet['replying_to_status'], $depth + 1);
    }
    $result[] = array(
        'user' => '@' . $tweet['author']['screen_name'],
        'name' => $tweet['author']['name'],
        'text' => $tweet['text'],
    );
    return $result;
}

function thread_to_text($thread) {
    $lines = array();
    foreach ($thread as $t) {
        $lines[] = $t['user'] . ': ' . $t['text'];
    }
    return implode("\n\n", $lines);
}

/* =========================================================
   デフォルトプロンプト
========================================================= */
$default_prompt = "以下はXのスレッド投稿です。この内容に対する返信投稿文を日本語で1つ生成してください。

条件：
- 200字程度（140字〜220字）
- 投稿者の主張を踏まえた上で、独自の視点や補足を加える
- 共感・反論・深掘りのいずれかのトーンで
- ハッシュタグは不要
- そのままXに投稿できる文体で

---
{thread}
---

返信文のみを出力してください。説明や前置きは不要です。";

/* =========================================================
   POST処理
========================================================= */
$action       = isset($_POST['action']) ? $_POST['action'] : '';
$tweet_url    = isset($_POST['tweet_url'])   ? trim($_POST['tweet_url'])   : '';
$thread_text  = '';
if (isset($_POST['thread_text_b64']) && $_POST['thread_text_b64'] !== '') {
    $decoded = base64_decode($_POST['thread_text_b64'], true);
    if ($decoded !== false) {
        $thread_text = trim($decoded);
    }
}
$prompt_tmpl  = $default_prompt;
$insight      = '';
$fetch_error  = isset($_SESSION['xi_flash_error']) ? $_SESSION['xi_flash_error'] : '';
if (isset($_SESSION['xi_flash_error'])) { unset($_SESSION['xi_flash_error']); }

/* GETでtweet_urlが渡された場合、保存済みデータを読み込む */
if ($tweet_url === '' && isset($_GET['tweet_url']) && $_GET['tweet_url'] !== '') {
    $tweet_url = trim($_GET['tweet_url']);
    $tweet_id_get = extract_tweet_id($tweet_url);
    if ($tweet_id_get !== '') {
        $save_file_get = __DIR__ . '/data/xinsight_' . $tweet_id_get . '.json';
        if (file_exists($save_file_get)) {
            $saved_get = json_decode(file_get_contents($save_file_get), true);
            if (is_array($saved_get)) {
                $thread_text = isset($saved_get['thread_text']) ? $saved_get['thread_text'] : '';
                $insight     = isset($saved_get['insight'])     ? $saved_get['insight']     : '';
                $tweet_url   = isset($saved_get['tweet_url'])   ? $saved_get['tweet_url']   : $tweet_url;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {

    /* スレッド取得 */
    if ($action === 'fetch' && $tweet_url !== '') {
        $tweet_id = extract_tweet_id($tweet_url);
        if ($tweet_id === '') {
            $_SESSION['xi_flash_error'] = 'URLからツイートIDを取得できませんでした';
            header('Location: ' . $x_redirect_uri);
            exit;
        } else {
            /* 保存済みデータがあれば表示のみ */
            $save_file = __DIR__ . '/data/xinsight_' . $tweet_id . '.json';
            if (file_exists($save_file)) {
                $saved = json_decode(file_get_contents($save_file), true);
                if (is_array($saved) && !empty($saved['thread_text'])) {
                    $action = 'loaded';
                }
            }
            if ($action !== 'loaded') {
                $thread = fetch_thread($tweet_id, 0);
                if (empty($thread)) {
                    $_SESSION['xi_flash_error'] = 'ツイートを取得できませんでした';
                    header('Location: ' . $x_redirect_uri);
                    exit;
                } else {
                    $thread_text = thread_to_text($thread);
                    /* スレッド取得後にJSONとして保存 */
                    $save_file = __DIR__ . '/data/xinsight_' . $tweet_id . '.json';
                    if (file_exists($save_file)) {
                        $save_data = json_decode(file_get_contents($save_file), true);
                        if (!is_array($save_data)) { $save_data = array(); }
                    } else {
                        $save_data = array();
                    }
                    $save_data['tweet_id']    = $tweet_id;
                    $save_data['tweet_url']   = $tweet_url;
                    $save_data['username']    = $username;
                    $save_data['thread_text'] = $thread_text;
                    if (!isset($save_data['insight'])) { $save_data['insight'] = ''; }
                    $save_data['saved_at']    = date('Y-m-d H:i:s');
                    file_put_contents($save_file, json_encode($save_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }
            /* PRG: GETにリダイレクト */
            header('Location: ' . $x_redirect_uri . '?tweet_url=' . urlencode($tweet_url));
            exit;
        }
    }

    /* Ollama考察 */
    if ($action === 'analyze' && $thread_text !== '') {
        $prompt = str_replace('{thread}', $thread_text, $prompt_tmpl);
        $payload = json_encode(array(
            'model'  => OLLAMA_MODEL,
            'prompt' => $prompt,
            'stream' => false,
        ));
        $opts = array('http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 120,
            'ignore_errors' => true,
        ));
        $res = @file_get_contents(OLLAMA_API, false, stream_context_create($opts));
        if ($res) {
            $data = json_decode($res, true);
            $insight = isset($data['response']) ? trim($data['response']) : '応答が取得できませんでした';
        } else {
            $insight = 'Ollama APIに接続できませんでした';
        }
        /* 考察結果をJSONに書き込み */
        if ($insight !== '' && $tweet_url !== '') {
            $tweet_id_save = extract_tweet_id($tweet_url);
            if ($tweet_id_save !== '') {
                $save_file = __DIR__ . '/data/xinsight_' . $tweet_id_save . '.json';
                $save_data = array(
                    'tweet_id'    => $tweet_id_save,
                    'tweet_url'   => $tweet_url,
                    'username'    => $username,
                    'thread_text' => $thread_text,
                    'insight'     => $insight,
                    'saved_at'    => date('Y-m-d H:i:s'),
                );
                file_put_contents($save_file, json_encode($save_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        /* PRG: GETにリダイレクト */
        header('Location: ' . $x_redirect_uri . '?tweet_url=' . urlencode($tweet_url));
        exit;
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>XInsight</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#2563eb;--accent-h:#1d4ed8;
    --green:#059669;--red:#dc2626;--orange:#d97706;
    --text:#0f172a;--muted:#64748b;
    --mono:'JetBrains Mono',monospace;
    --sans:'Inter',sans-serif;
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;font-size:14px}

/* header */
header{background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.logo{font-size:1.1rem;font-weight:700;letter-spacing:-.02em}
.logo span{color:var(--accent)}
.userbar{display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:var(--muted)}
.userbar strong{color:var(--green)}
.btn-sm{background:none;border:1px solid var(--border2);color:var(--muted);padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-sm:hover{border-color:var(--red);color:var(--red)}

/* login */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:80vh}
.login-card{text-align:center;padding:2.5rem;border:1px solid var(--border);border-radius:12px;background:var(--surface);width:320px;box-shadow:0 4px 16px rgba(0,0,0,.06)}
.login-card h2{font-size:1.3rem;font-weight:700;margin-bottom:.4rem}
.login-card p{color:var(--muted);font-size:.82rem;margin-bottom:1.8rem}
.btn-login{display:inline-flex;align-items:center;gap:.5rem;background:var(--accent);color:#fff;padding:.65rem 1.6rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:.88rem;transition:background .2s}
.btn-login:hover{background:var(--accent-h)}
.btn-login svg{width:16px;height:16px;fill:white}

/* main layout */
.container{max-width:1100px;margin:0 auto;padding:1.5rem}

/* section */
.section{background:var(--surface);border:1px solid var(--border);border-radius:10px;margin-bottom:1rem;overflow:hidden}
.section-header{padding:.75rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between}
.section-title{font-weight:600;font-size:.85rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.step{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700}
.section-body{padding:1rem}

/* inputs */
.row{display:flex;gap:.6rem;align-items:flex-start}
input[type=text]{flex:1;border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text)}
input[type=text]:focus{border-color:var(--accent)}
textarea.code-area{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.75rem;font-family:var(--mono);font-size:.8rem;line-height:1.7;outline:none;resize:vertical;color:var(--text);transition:border .15s;min-height:120px}
textarea.code-area:focus{border-color:var(--accent)}
textarea.insight-area{background:#f8fafc;min-height:200px}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans)}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn-green{background:var(--green);color:#fff}
.btn-green:hover{background:#047857}
.btn:disabled{opacity:.5;cursor:not-allowed}

/* error / status */
.msg-error{color:var(--red);font-size:.8rem;margin-top:.4rem}
.msg-ok{color:var(--green);font-size:.8rem;margin-top:.4rem}
.char-count{font-size:.75rem;color:var(--muted);text-align:right;margin-top:.3rem;font-family:var(--mono)}

/* spinner */
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}

/* ---- スマホ対応 ---- */
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
    <div class="logo">X<span>Insight</span></div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?xi_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?xi_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<?php
/* 未ログインでも表示可、生成はis_adminのみ */ ?>
<div class="container">

    <!-- STEP 1: URL入力 & スレッド取得 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">1</span> XのURLを入力してスレッドを取得</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-fetch">
                <input type="hidden" name="action" value="fetch">
                <div class="row">
                    <input type="text" name="tweet_url" id="tweet_url_input" placeholder="https://x.com/user/status/..." value="<?php echo h($tweet_url); ?>">
                    <button type="submit" class="btn btn-primary" id="btn-fetch"<?php if (!$is_admin): ?> disabled title="ログインが必要です"<?php endif; ?>>
                        <span class="btn-label">取得</span>
                        <span class="spinner"></span>
                    </button>
                    <?php if ($tweet_url !== ""): ?>
                    <a href="<?php echo h($tweet_url); ?>" target="_blank" class="btn btn-secondary">元の投稿 ↗</a>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary" id="btn-open" onclick="openTweetUrl()" disabled>元の投稿 ↗</button>
                    <?php endif; ?>
                </div>
                <?php if ($fetch_error): ?>
                <div class="msg-error"><?php echo h($fetch_error); ?></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- STEP 2: スレッド本文 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">2</span> スレッド本文（編集可）</div>
            <div style="display:flex;gap:.4rem">
                <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .7rem" onclick="copyText('thread_text')">コピー</button>
                <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .7rem" onclick="document.getElementById('thread_text').value=''">クリア</button>
            </div>
        </div>
        <div class="section-body">
            <textarea class="code-area" id="thread_text" rows="8" form="form-analyze" placeholder="ここにスレッド本文が表示されます。直接編集も可能です。"><?php echo h($thread_text); ?></textarea>
            <div class="char-count" id="thread_count"><?php echo mb_strlen($thread_text); ?> 文字</div>
        </div>
    </div>

    <!-- STEP 3: プロンプト -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">3</span> プロンプト（編集可）</div>
            <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .7rem" onclick="resetPrompt()">デフォルトに戻す</button>
        </div>
        <div class="section-body">
            <textarea class="code-area" id="prompt_tmpl" rows="8"><?php echo h($prompt_tmpl); ?></textarea>
            <div class="char-count" style="color:var(--muted);font-size:.72rem;margin-top:.3rem">※ <code>{thread}</code> がスレッド本文に置換されます</div>
        </div>
    </div>

    <!-- STEP 4: 考察実行 -->
    <form method="POST" id="form-analyze">
        <input type="hidden" name="action" value="analyze">
        <input type="hidden" name="tweet_url" value="<?php echo h($tweet_url); ?>">
        <input type="hidden" name="thread_text_b64" id="thread_text_b64" value="<?php echo base64_encode($thread_text); ?>">
        <div style="display:flex;justify-content:center;margin-bottom:1rem">
            <button type="submit" class="btn btn-green" id="btn-analyze"<?php if (!$is_admin): ?> disabled title="ログインが必要です"<?php endif; ?> style="padding:.65rem 2.5rem;font-size:.9rem">
                <span class="btn-label">✦ AIで考察する</span>
                <span class="spinner"></span>
            </button>
        </div>
    </form>

    <!-- STEP 5: 考察結果 -->
    <?php if ($insight !== ''): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--green)">✓</span> 考察結果</div>
            <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .7rem" onclick="copyText('insight_area')">コピー</button>
        </div>
        <div class="section-body">
            <textarea class="code-area insight-area" id="insight_area" rows="14" readonly><?php echo h($insight); ?></textarea>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
/* 元の投稿ボタン */
function openTweetUrl() {
    var url = document.getElementById('tweet_url_input').value.trim();
    if (url) window.open(url, '_blank');
}
var urlInput = document.getElementById('tweet_url_input');
var btnOpen  = document.getElementById('btn-open');
if (urlInput && btnOpen) {
    urlInput.addEventListener('input', function() {
        btnOpen.disabled = this.value.trim() === '';
    });
}

var defaultPrompt = <?php echo json_encode($default_prompt); ?>;

function resetPrompt() {
    document.getElementById('prompt_tmpl').value = defaultPrompt;
}

function copyText(id) {
    var el = document.getElementById(id);
    if (!el) return;
    var text = el.value || el.innerText;
    if (id === 'insight_area' || id === 'story_area') {
        var tweetUrl = '';
        var urlEl = document.getElementById('tweet_url_input');
        if (urlEl && urlEl.value.trim()) {
            tweetUrl = urlEl.value.trim();
        } else {
            var hiddenUrl = document.querySelector('#form-analyze input[name="tweet_url"]');
            if (hiddenUrl) { tweetUrl = hiddenUrl.value.trim(); }
        }
        /* URLからユーザー名取得、/i/status/形式の場合はthread_textから取得 */
        var author = '';
        var mu = tweetUrl.match(/x\.com\/([^\/]+)\/status/);
        if (mu && mu[1] !== 'i') {
            author = '@' + mu[1];
        } else {
            /* thread_textの先頭@ユーザー名から取得 */
            var threadEl = document.getElementById('thread_text') || document.getElementById('story_area');
            var threadVal = '';
            if (document.getElementById('thread_text')) { threadVal = document.getElementById('thread_text').value; }
            var mt = threadVal.match(/^@(\S+):/m);
            if (mt) { author = '@' + mt[1]; }
        }
        var insightViewUrl = '';
        var tidM = tweetUrl.match(/(\d{15,20})/);
        if (tidM) { insightViewUrl = 'https://aiknowledgecms.exbridge.jp/xinsightv.php?id=' + tidM[1]; }
        text = text
            + (author         ? '\n' + author         : '')
            + (tweetUrl       ? '\n' + tweetUrl       : '')
            + (insightViewUrl ? '\n' + insightViewUrl : '');
    }
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        el.select();
        document.execCommand('copy');
    }
}

/* 文字数カウント */
var ta = document.getElementById('thread_text');
var counter = document.getElementById('thread_count');
if (ta && counter) {
    ta.addEventListener('input', function() {
        counter.textContent = this.value.length + ' 文字';
    });
}

/* 取得ボタン：hidden_promptにprompt_tmplの値をコピー */
document.getElementById('form-fetch').addEventListener('submit', function() {
    var pt = document.getElementById('prompt_tmpl');
    if (pt) {
        document.getElementById('hidden_prompt').value = pt.value;
    }
    var btn = document.getElementById('btn-fetch');
    btn.classList.add('loading');
    btn.disabled = true;
});

/* 考察ボタン */
document.getElementById('form-analyze').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('btn-analyze');
    btn.classList.add('loading');
    btn.disabled = true;
    syncThreadB64();
    this.submit();
});

function syncThreadB64() {
    var ta = document.getElementById('thread_text');
    var hidden = document.getElementById('thread_text_b64');
    if (ta && hidden) {
        hidden.value = btoa(unescape(encodeURIComponent(ta.value)));
    }
}

/* 初期化＆編集時に同期 */
syncThreadB64();
var taEl = document.getElementById('thread_text');
if (taEl) { taEl.addEventListener('input', syncThreadB64); }
</script>
</body>
</html>