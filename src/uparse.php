<?php
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
$x_redirect_uri  = 'https://aiknowledgecms.exbridge.jp/uparse.php';

define('OLLAMA_API',   'https://exbridge.ddns.net/api/generate');
define('OLLAMA_MODEL', 'gemma4:e4b');

/* =========================================================
   OAuth2 PKCE
========================================================= */
function up_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function up_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return up_base64url($bytes);
}
function up_gen_challenge($verifier) {
    return up_base64url(hash('sha256', $verifier, true));
}
function up_x_post($url, $post_data, $headers) {
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
function up_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: UParse/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['up_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/',
        'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['up_login'])) {
    $verifier  = up_gen_verifier();
    $challenge = up_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['up_code_verifier'] = $verifier;
    $_SESSION['up_oauth_state']   = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['up_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['up_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['up_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = up_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']  = $data['access_token'];
            $_SESSION['session_token_expires']  = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['up_oauth_state'], $_SESSION['up_code_verifier']);
            $me = up_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
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
    $ref = up_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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
        unset($_SESSION['session_access_token'], $_SESSION['session_refresh_token'],
              $_SESSION['session_token_expires'], $_SESSION['session_username']);
    }
}

$logged_in = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$username  = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin  = ($username === 'xb_bittensor');

/* =========================================================
   ヘルパー
========================================================= */
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function find_sh() {
    foreach (array('/bin/sh', '/usr/bin/sh', '/bin/bash') as $b) {
        if (file_exists($b)) return $b;
    }
    return '';
}
function run_cmd($cmd) {
    $sh = find_sh();
    if (!$sh) return array('', 1);
    $out = array(); $ret = 0;
    exec($sh . ' -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $ret);
    return array(implode("\n", $out), $ret);
}
function extract_tweet_id($input) {
    $input = trim($input);
    if (preg_match('/(\d{15,20})/', $input, $m)) return $m[1];
    return '';
}
function fx_get($tweet_id) {
    $url = 'https://api.fxtwitter.com/i/status/' . preg_replace('/[^0-9]/', '', $tweet_id);
    list($res, $ret) = run_cmd('curl -s --max-time 10 ' . escapeshellarg($url));
    if ($ret !== 0 || !$res) return null;
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
   構文解析＋例文生成プロンプト
========================================================= */
$default_prompt = "以下はXの投稿文（英語または日本語）です。

あなたの仕事は、表面的な文法ラベル付けではなく、「その構文がこの文脈でどんな意味・関係・話し手の意図を担っているか」まで見て、例文を作ることです。

【ステップ1】投稿文から主な構文を1〜2個特定してください。
- 構文名と構造を簡潔に示す
- その構文が元の文脈で表している意味・機能も書く
- 文法形が同じでも意味が違う場合は、元文に最も近い解釈を選ぶ

意味・機能の観点の例：
因果、対比、条件、仮定、強調、評価、依頼、断定の強さ、話し手の態度、時間関係、主語と目的語の意味関係 など

【ステップ2】その構文を使った例文を3〜5個、英語で生成してください。
- 合計200字（英語）程度
- 投稿の内容・テーマそのものは変えてよい
- ただし、元文と同じ「意味・機能」が伝わる例文にする
- 文法の形だけ似ていて、意味や語用論がずれる例文は作らない
- 特に、因果関係・条件関係・評価の向き・主語と目的語の役割・依頼/断定/推量などの発話意図を保つ
- 各例文の後に日本語訳を1行で付ける
- 番号付きリスト形式で出力

【出力フォーマット】
■ 構文：（構文名と構造）
■ 意味・機能：（この文脈でその構文が何を表しているかを1〜2文で）

■ 例文：
1. （英文）
   （日本語訳）
2. （英文）
   （日本語訳）
...

---
{thread}
---

上記フォーマットのみ出力してください。前置き・説明は不要です。";

/* =========================================================
   POST処理
========================================================= */
$action       = isset($_POST['action'])      ? $_POST['action']           : '';
$tweet_url    = isset($_POST['tweet_url'])   ? trim($_POST['tweet_url'])  : '';
$thread_text  = isset($_POST['thread_text']) ? trim($_POST['thread_text']): '';
$parse_result = '';
$fetch_error  = isset($_SESSION['up_flash_error']) ? $_SESSION['up_flash_error'] : '';
if (isset($_SESSION['up_flash_error'])) { unset($_SESSION['up_flash_error']); }

/* GETでtweet_urlが渡された場合、保存済みデータを読み込む */
if ($tweet_url === '' && isset($_GET['tweet_url']) && $_GET['tweet_url'] !== '') {
    $tweet_url    = trim($_GET['tweet_url']);
    $tweet_id_get = extract_tweet_id($tweet_url);
    if ($tweet_id_get !== '') {
        $save_file_get = __DIR__ . '/data/xinsight_' . $tweet_id_get . '.json';
        if (file_exists($save_file_get)) {
            $saved_get = json_decode(file_get_contents($save_file_get), true);
            if (is_array($saved_get)) {
                $thread_text  = isset($saved_get['thread_text'])  ? $saved_get['thread_text']  : '';
                $parse_result = isset($saved_get['parse_result']) ? $saved_get['parse_result'] : '';
                $tweet_url    = isset($saved_get['tweet_url'])    ? $saved_get['tweet_url']    : $tweet_url;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {

    /* ---- スレッド取得 ---- */
    if ($action === 'fetch' && $tweet_url !== '') {
        $tweet_id = extract_tweet_id($tweet_url);
        if ($tweet_id === '') {
            $_SESSION['up_flash_error'] = 'URLからツイートIDを取得できませんでした';
            header('Location: ' . $x_redirect_uri);
            exit;
        } else {
            $save_file = __DIR__ . '/data/xinsight_' . $tweet_id . '.json';
            if (file_exists($save_file)) {
                $saved = json_decode(file_get_contents($save_file), true);
                if (is_array($saved) && !empty($saved['parse_result'])) {
                    $thread_text  = isset($saved['thread_text'])  ? $saved['thread_text']  : '';
                    $parse_result = $saved['parse_result'];
                    $tweet_url    = isset($saved['tweet_url'])    ? $saved['tweet_url']    : $tweet_url;
                    $action       = 'loaded';
                }
            }
            if ($action !== 'loaded') {
                $thread = fetch_thread($tweet_id, 0);
                if (empty($thread)) {
                    $_SESSION['up_flash_error'] = 'ツイートを取得できませんでした';
                    header('Location: ' . $x_redirect_uri);
                    exit;
                } else {
                    $thread_text = thread_to_text($thread);
                }
            }
        }
    }

    /* ---- 構文解析＋例文生成 ---- */
    if (($action === 'fetch' || $action === 'analyze') && $thread_text !== '') {
        $prompt  = str_replace('{thread}', $thread_text, $default_prompt);
        $payload = json_encode(array(
            'model'  => OLLAMA_MODEL,
            'prompt' => $prompt,
            'stream' => false,
            'options' => array(
                'num_ctx'     => 2048,
                'temperature' => 0.4,
                'top_k'       => 40,
                'top_p'       => 0.9,
            )
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
            $resp         = json_decode($res, true);
            $parse_result = isset($resp['response']) ? trim($resp['response']) : '応答が取得できませんでした';
        } else {
            $parse_result = 'Ollama APIに接続できませんでした';
        }

        /* xinsight_TWEETID.json に parse_result キーを追加保存 */
        if ($parse_result !== '' && $tweet_url !== '') {
            $tweet_id_save = extract_tweet_id($tweet_url);
            if ($tweet_id_save !== '') {
                $save_file = __DIR__ . '/data/xinsight_' . $tweet_id_save . '.json';
                if (file_exists($save_file)) {
                    $save_data = json_decode(file_get_contents($save_file), true);
                    if (!is_array($save_data)) { $save_data = array(); }
                } else {
                    $save_data = array(
                        'tweet_id'    => $tweet_id_save,
                        'tweet_url'   => $tweet_url,
                        'username'    => $username,
                        'thread_text' => $thread_text,
                        'saved_at'    => date('Y-m-d H:i:s'),
                    );
                }
                $save_data['parse_result']   = $parse_result;
                $save_data['parse_saved_at'] = date('Y-m-d H:i:s');
                file_put_contents($save_file,
                    json_encode($save_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }

    /* PRGリダイレクト */
    if ($tweet_url !== '') {
        header('Location: ' . $x_redirect_uri . '?tweet_url=' . urlencode($tweet_url));
        exit;
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UParse — 構文解析＆例文生成</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#0891b2;--accent-h:#0e7490;
    --green:#059669;--red:#dc2626;
    --text:#0f172a;--muted:#64748b;
    --mono:'JetBrains Mono',monospace;
    --sans:'Inter',sans-serif;
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
.section-header{padding:.75rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between}
.section-title{font-weight:600;font-size:.85rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.step{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700}
.section-body{padding:1rem}

.row{display:flex;gap:.6rem;align-items:flex-start}
input[type=text]{flex:1;border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text)}
input[type=text]:focus{border-color:var(--accent)}
textarea.code-area{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.75rem;font-family:var(--mono);font-size:.8rem;line-height:1.7;outline:none;resize:vertical;color:var(--text);min-height:100px}

/* 結果エリア */
.parse-result-area{
    width:100%;
    background:#f0f9ff;
    border:1px solid var(--border2);
    border-radius:6px;
    padding:1rem 1.2rem;
    font-size:.88rem;
    line-height:2;
    color:var(--text);
    white-space:pre-wrap;
    font-family:var(--sans);
    min-height:200px;
}
/* 構文ハイライト */
.parse-result-area strong { color: var(--accent); }

.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans)}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn-generate{background:linear-gradient(135deg,#0891b2,#0e7490);color:#fff;padding:.65rem 2.5rem;font-size:.9rem}
.btn-generate:hover{background:linear-gradient(135deg,#0e7490,#155e75)}
.btn:disabled{opacity:.5;cursor:not-allowed}

.msg-error{color:var(--red);font-size:.8rem;margin-top:.4rem}
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}

#generating-msg{display:none;text-align:center;padding:12px 16px;font-size:.82rem;color:#0c4a6e;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;margin-bottom:1rem;font-weight:600;}

@media(max-width:600px){
    .row{flex-wrap:wrap}
    .row input[type=text]{flex:1 1 100%}
    .container{padding:1rem}
}
</style>
</head>
<body>

<header>
    <div class="logo-group"><div class="logo">U<span>Parse</span></div><span class="u2a-badge">URL2AI</span>Parse</div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?up_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?up_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">

    <!-- STEP 1: URL入力 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">1</span> XのURLを入力してスレッドを取得</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-fetch">
                <input type="hidden" name="action" value="fetch">
                <div class="row">
                    <input type="text" name="tweet_url" id="tweet_url_input"
                           placeholder="https://x.com/user/status/..."
                           value="<?php echo h($tweet_url); ?>">
                    <button type="button" class="btn btn-primary" id="btn-fetch"
                        <?php if (!$is_admin): ?>disabled title="ログインが必要です"<?php endif; ?>
                        onclick="submitFetch()">
                        <span class="btn-label">取得</span>
                        <span class="spinner"></span>
                    </button>
                    <?php if ($tweet_url !== ''): ?>
                    <a href="<?php echo h($tweet_url); ?>" target="_blank" class="btn btn-secondary">元の投稿 ↗</a>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary" id="btn-open"
                            onclick="openTweetUrl()" disabled>元の投稿 ↗</button>
                    <?php endif; ?>
                </div>
                <?php if ($fetch_error): ?>
                <div class="msg-error"><?php echo h($fetch_error); ?></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- STEP 2: 投稿本文 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">2</span> 投稿本文（編集可）</div>
            <button type="button" class="btn btn-secondary"
                    style="font-size:.75rem;padding:.3rem .7rem"
                    onclick="document.getElementById('thread_text').value=''">クリア</button>
        </div>
        <div class="section-body">
            <textarea class="code-area" id="thread_text" name="thread_text"
                      rows="5" form="form-analyze"
                      placeholder="投稿本文がここに表示されます。直接編集も可能です。"><?php echo h($thread_text); ?></textarea>
        </div>
    </div>

    <!-- 生成中メッセージ -->
    <div id="generating-msg">
        🔍 構文解析＆例文生成中です。1〜2分かかります。ページを閉じないでください...
    </div>

    <!-- STEP 3: 生成実行 -->
    <form method="POST" id="form-analyze">
        <input type="hidden" name="action" value="analyze">
        <input type="hidden" name="tweet_url" value="<?php echo h($tweet_url); ?>">
        <div style="display:flex;justify-content:center;margin-bottom:1rem">
            <button type="button" class="btn btn-generate" id="btn-analyze"
                <?php if (!$is_admin): ?>disabled title="ログインが必要です"<?php endif; ?>
                onclick="submitAnalyze()">
                <span class="btn-label">🔍 構文解析＆例文生成</span>
                <span class="spinner"></span>
            </button>
        </div>
    </form>

    <!-- STEP 4: 結果 -->
    <?php if ($parse_result !== ''): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--green)">✓</span> 構文解析＆例文</div>
            <button type="button" class="btn btn-secondary"
                    style="font-size:.75rem;padding:.3rem .7rem"
                    onclick="copyResult()">コピー</button>
        </div>
        <div class="section-body">
            <div class="parse-result-area" id="result_area"><?php
                /* ■ を強調表示 */
                $display = h($parse_result);
                $display = preg_replace('/^(■.+)$/mu', '<strong>$1</strong>', $display);
                echo $display;
            ?></div>
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
var btnOpen  = document.getElementById('btn-open');
if (urlInput && btnOpen) {
    urlInput.addEventListener('input', function() {
        btnOpen.disabled = this.value.trim() === '';
    });
}
function lockUI() {
    var btnF = document.getElementById('btn-fetch');
    var btnA = document.getElementById('btn-analyze');
    var msg  = document.getElementById('generating-msg');
    if (btnF) { btnF.disabled = true; btnF.style.opacity = '0.5'; }
    if (btnA) { btnA.disabled = true; btnA.style.opacity = '0.5'; }
    if (msg)  { msg.style.display = 'block'; }
}
function submitFetch() {
    lockUI();
    var btn = document.getElementById('btn-fetch');
    if (btn) { btn.classList.add('loading'); }
    document.getElementById('form-fetch').submit();
}
function submitAnalyze() {
    lockUI();
    var btn = document.getElementById('btn-analyze');
    if (btn) { btn.classList.add('loading'); }
    document.getElementById('form-analyze').submit();
}
function copyResult() {
    var el = document.getElementById('result_area');
    if (!el) return;
    var text = el.innerText || el.textContent;
    var tweetUrl = '<?php echo addslashes($tweet_url); ?>';
    if (tweetUrl) { text += '\n' + tweetUrl; }
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
}
</script>
</body>
</html>
