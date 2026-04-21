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
$x_redirect_uri  = 'https://aiknowledgecms.exbridge.jp/udebate.php';

define('DATA_DIR',     __DIR__ . '/data');

/* =========================================================
   OAuth2 PKCE
========================================================= */
function db_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function db_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return db_base64url($bytes);
}
function db_gen_challenge($verifier) {
    return db_base64url(hash('sha256', $verifier, true));
}
function db_x_post($url, $post_data, $headers) {
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
function db_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: Debate/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['db_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/',
        'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['db_login'])) {
    $verifier  = db_gen_verifier();
    $challenge = db_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['db_code_verifier'] = $verifier;
    $_SESSION['db_oauth_state']   = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['db_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['db_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['db_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = db_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']  = $data['access_token'];
            $_SESSION['session_token_expires']  = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['db_oauth_state'], $_SESSION['db_code_verifier']);
            $me = db_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
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
    $ref = db_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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
    if (preg_match('/(\d{15,20})/', $input, $m)) { return $m[1]; }
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
        'text' => $tweet['text'],
    );
    return $result;
}
function thread_to_text($thread) {
    $lines = array();
    foreach ($thread as $t) { $lines[] = $t['user'] . ': ' . $t['text']; }
    return implode("\n\n", $lines);
}
function ollama_call($prompt) {
    $payload = json_encode(array(
        'model'   => OLLAMA_MODEL,
        'prompt'  => $prompt,
        'stream'  => false,
        'options' => array(
            'num_ctx'     => 2048,
            'temperature' => 0.75,
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
    if (!$res) return '';
    $data = json_decode($res, true);
    return isset($data['response']) ? trim($data['response']) : '';
}

/* =========================================================
   SSE: ?sse=1&tweet_id=xxx&thread=...
========================================================= */
if (isset($_GET['sse']) && isset($_GET['tweet_id'])) {
    @set_time_limit(600);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $tweet_id    = preg_replace('/[^0-9]/', '', $_GET['tweet_id']);
    $thread_text = isset($_GET['thread']) ? trim($_GET['thread']) : '';

    if ($thread_text === '') {
        $jf = DATA_DIR . '/xinsight_' . $tweet_id . '.json';
        if (file_exists($jf)) {
            $jd = json_decode(file_get_contents($jf), true);
            if (is_array($jd) && !empty($jd['thread_text'])) {
                $thread_text = $jd['thread_text'];
            }
        }
    }
    if ($thread_text === '') {
        echo "data: " . json_encode(array('type' => 'error', 'text' => 'スレッド本文が取得できませんでした'), JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        exit;
    }

    function sse_out($type, $speaker, $text) {
        echo "data: " . json_encode(array(
            'type'    => $type,
            'speaker' => $speaker,
            'text'    => $text,
        ), JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    $turns = array();

    /* Round 0: A 冒頭主張 */
    sse_out('start', 'A', '');
    $p_a0 = "以下はXの投稿内容です。この内容について、肯定的・支持的な立場から意見を述べてください。\n\n条件:\n- 200字程度\n- 具体的な根拠を1〜2点挙げる\n- 断定的で説得力のある論調\n- 結論から述べる\n- 前置きや一人称は不要\n\n---\n{$thread_text}\n---\n\n意見のみ出力してください。";
    $a0 = ollama_call($p_a0);
    $turns[] = array('speaker' => 'A', 'text' => $a0);
    sse_out('message', 'A', $a0);

    /* Round 1: B 反論1 */
    sse_out('start', 'B', '');
    $hist = "[肯定側]\n" . $a0;
    $p_b1 = "以下はXの投稿と、それに対する肯定側の意見です。否定的・批判的な立場から反論してください。\n\n条件:\n- 200字程度\n- 相手の主張の弱点を具体的に指摘する\n- 論理的に、前置き不要\n\n---投稿---\n{$thread_text}\n\n---議論---\n{$hist}\n---\n\n反論のみ出力してください。";
    $b1 = ollama_call($p_b1);
    $turns[] = array('speaker' => 'B', 'text' => $b1);
    sse_out('message', 'B', $b1);

    /* Round 2: A 再反論 */
    sse_out('start', 'A', '');
    $hist .= "\n\n[否定側]\n" . $b1;
    $p_a2 = "以下はXの投稿と議論の流れです。肯定側として否定側の反論に切り返してください。\n\n条件:\n- 200字程度\n- 相手の反論の問題点を指摘しつつ立場を補強する\n- 新たな根拠を加える、前置き不要\n\n---投稿---\n{$thread_text}\n\n---議論---\n{$hist}\n---\n\n反論のみ出力してください。";
    $a2 = ollama_call($p_a2);
    $turns[] = array('speaker' => 'A', 'text' => $a2);
    sse_out('message', 'A', $a2);

    /* Round 3: B 再反論 */
    sse_out('start', 'B', '');
    $hist .= "\n\n[肯定側]\n" . $a2;
    $p_b3 = "以下はXの投稿と議論の流れです。否定側としてさらに反論してください。\n\n条件:\n- 200字程度\n- 議論の核心に迫る指摘、論理の矛盾を突く\n- 前置き不要\n\n---投稿---\n{$thread_text}\n\n---議論---\n{$hist}\n---\n\n反論のみ出力してください。";
    $b3 = ollama_call($p_b3);
    $turns[] = array('speaker' => 'B', 'text' => $b3);
    sse_out('message', 'B', $b3);

    /* Round 4: A 最終主張 */
    sse_out('start', 'A', '');
    $hist .= "\n\n[否定側]\n" . $b3;
    $p_a4 = "以下はXの投稿と議論の流れです。肯定側として最終的な主張をまとめてください。\n\n条件:\n- 150字程度\n- 議論を踏まえた最終立場、簡潔に力強く締める\n- 前置き不要\n\n---投稿---\n{$thread_text}\n\n---議論---\n{$hist}\n---\n\n最終主張のみ出力してください。";
    $a4 = ollama_call($p_a4);
    $turns[] = array('speaker' => 'A', 'text' => $a4);
    sse_out('message', 'A', $a4);

    /* Round 5: B 最終主張 */
    sse_out('start', 'B', '');
    $hist .= "\n\n[肯定側 最終]\n" . $a4;
    $p_b5 = "以下はXの投稿と議論の流れです。否定側として最終的な主張をまとめてください。\n\n条件:\n- 150字程度\n- 議論を踏まえた最終立場、簡潔に力強く締める\n- 前置き不要\n\n---投稿---\n{$thread_text}\n\n---議論---\n{$hist}\n---\n\n最終主張のみ出力してください。";
    $b5 = ollama_call($p_b5);
    $turns[] = array('speaker' => 'B', 'text' => $b5);
    sse_out('message', 'B', $b5);

    /* 結論: 司会AI */
    sse_out('start', 'judge', '');
    $hist .= "\n\n[否定側 最終]\n" . $b5;
    $p_judge = "以下はXの投稿に関するAI同士の議論です。中立的な司会者として結論をまとめてください。\n\n条件:\n- 250字程度\n- 両側の主張を公平に評価する\n- 争点を明確にし、合意・不合意を示す\n- 読者への示唆で締める\n- 「以上の議論を踏まえ」などで始める\n\n---投稿---\n{$thread_text}\n\n---議論---\n{$hist}\n---\n\n結論のみ出力してください。";
    $judge = ollama_call($p_judge);
    $turns[] = array('speaker' => 'judge', 'text' => $judge);
    sse_out('message', 'judge', $judge);

    /* JSON保存 — xinsight_*.json に debate フィールドを追加 */
    $xi_file = DATA_DIR . '/xinsight_' . $tweet_id . '.json';
    $xi_data = array();
    if (file_exists($xi_file)) {
        $xi_data = json_decode(file_get_contents($xi_file), true);
        if (!is_array($xi_data)) { $xi_data = array(); }
    }
    /* 基本フィールドが未設定なら補完 */
    if (empty($xi_data['tweet_id']))    { $xi_data['tweet_id']    = $tweet_id; }
    if (empty($xi_data['thread_text'])) { $xi_data['thread_text'] = $thread_text; }
    if (empty($xi_data['saved_at']))    { $xi_data['saved_at']    = date('Y-m-d H:i:s'); }
    /* debate フィールドを上書き保存 */
    $xi_data['debate_turns']      = $turns;
    $xi_data['debate_conclusion'] = $judge;
    $xi_data['debate_at']         = date('Y-m-d H:i:s');
    file_put_contents($xi_file, json_encode($xi_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    sse_out('done', '', '');
    exit;
}

/* =========================================================
   POST: スレッド取得
========================================================= */
$action      = isset($_POST['action'])     ? $_POST['action']         : '';
$tweet_url   = isset($_POST['tweet_url'])  ? trim($_POST['tweet_url']) : '';
$thread_text = isset($_POST['thread_text'])? trim($_POST['thread_text']): '';
$fetch_error = isset($_SESSION['db_flash_error']) ? $_SESSION['db_flash_error'] : '';
if (isset($_SESSION['db_flash_error'])) { unset($_SESSION['db_flash_error']); }

/* GETで読み込み */
$saved_debate = null;
if ($tweet_url === '' && isset($_GET['tweet_url']) && $_GET['tweet_url'] !== '') {
    $tweet_url = trim($_GET['tweet_url']);
    $tid_get   = extract_tweet_id($tweet_url);
    if ($tid_get !== '') {
        $xf = DATA_DIR . '/xinsight_' . $tid_get . '.json';
        if (file_exists($xf)) {
            $xd = json_decode(file_get_contents($xf), true);
            if (is_array($xd)) {
                $thread_text = isset($xd['thread_text']) ? $xd['thread_text'] : '';
                $tweet_url   = isset($xd['tweet_url'])   ? $xd['tweet_url']   : $tweet_url;
                if (!empty($xd['debate_turns'])) {
                    $saved_debate = $xd;
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    if ($action === 'fetch' && $tweet_url !== '') {
        $tweet_id = extract_tweet_id($tweet_url);
        if ($tweet_id === '') {
            $_SESSION['db_flash_error'] = 'URLからツイートIDを取得できませんでした';
            header('Location: ' . $x_redirect_uri);
            exit;
        }
        $xf = DATA_DIR . '/xinsight_' . $tweet_id . '.json';
        if (file_exists($xf)) {
            $xd = json_decode(file_get_contents($xf), true);
            if (is_array($xd) && !empty($xd['thread_text'])) {
                $thread_text = $xd['thread_text'];
            }
        }
        if ($thread_text === '') {
            $thread = fetch_thread($tweet_id, 0);
            if (empty($thread)) {
                $_SESSION['db_flash_error'] = 'ツイートを取得できませんでした';
                header('Location: ' . $x_redirect_uri);
                exit;
            }
            $thread_text = thread_to_text($thread);
        }
        /* thread_text を JSON に保存（リダイレクト後も読めるように） */
        if ($thread_text !== '') {
            if (file_exists($xf)) {
                $xd = json_decode(file_get_contents($xf), true);
                if (!is_array($xd)) { $xd = array(); }
            } else {
                $xd = array();
            }
            if (empty($xd['tweet_id']))    { $xd['tweet_id']    = $tweet_id; }
            if (empty($xd['tweet_url']))   { $xd['tweet_url']   = $tweet_url; }
            if (empty($xd['thread_text'])) { $xd['thread_text'] = $thread_text; }
            if (empty($xd['saved_at']))    { $xd['saved_at']    = date('Y-m-d H:i:s'); }
            file_put_contents($xf, json_encode($xd, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        header('Location: ' . $x_redirect_uri . '?tweet_url=' . urlencode($tweet_url));
        exit;
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UDebate AI — AIGM</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#2563eb;--accent-h:#1d4ed8;
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

.container{max-width:900px;margin:0 auto;padding:1.5rem}

.section{background:var(--surface);border:1px solid var(--border);border-radius:10px;margin-bottom:1rem;overflow:hidden}
.section-header{padding:.75rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between}
.section-title{font-weight:600;font-size:.85rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.step{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700}
.section-body{padding:1rem}

.row{display:flex;gap:.6rem;align-items:flex-start}
input[type=text]{flex:1;border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text)}
input[type=text]:focus{border-color:var(--accent)}
textarea.code-area{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.75rem;font-family:var(--mono);font-size:.8rem;line-height:1.7;outline:none;resize:vertical;color:var(--text);transition:border .15s;min-height:100px}
textarea.code-area:focus{border-color:var(--accent)}

.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans)}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn-debate{background:linear-gradient(135deg,#7c3aed,#2563eb);color:#fff;font-size:.9rem;padding:.65rem 2.5rem}
.btn-debate:hover{opacity:.88}
.btn:disabled{opacity:.5;cursor:not-allowed}

.msg-error{color:var(--red);font-size:.8rem;margin-top:.4rem}
.char-count{font-size:.75rem;color:var(--muted);text-align:right;margin-top:.3rem;font-family:var(--mono)}

.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}

/* =========================================================
   LINE風 議論エリア
========================================================= */
.debate-wrap{
    background:#dde5ed;
    border-radius:10px;
    padding:16px 12px;
    min-height:160px;
    display:flex;
    flex-direction:column;
    gap:14px;
    max-height:70vh;
    overflow-y:auto;
}

/* A側（肯定）: 右寄せ 青 */
.msg-row{display:flex;flex-direction:column;max-width:76%}
.msg-row.side-a{align-self:flex-end;align-items:flex-end}
.msg-row.side-b{align-self:flex-start;align-items:flex-start}
.msg-row.side-judge{align-self:center;align-items:center;max-width:92%;width:92%}

.speaker-label{font-size:10px;font-weight:600;color:#6b7c8f;margin-bottom:3px;padding:0 4px;letter-spacing:.04em}

.round-badge{font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;margin-bottom:3px;display:inline-block}
.round-badge-a{background:#dbeafe;color:#1d4ed8}
.round-badge-b{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}

.bubble{
    padding:10px 14px;
    border-radius:18px;
    font-size:13px;
    line-height:1.8;
    white-space:pre-wrap;
    word-break:break-word;
    max-width:100%;
}
/* 肯定AI: 右 青 */
.bubble-a{
    background:#2563eb;
    color:#fff;
    border-bottom-right-radius:4px;
}
/* 否定AI: 左 白 */
.bubble-b{
    background:#fff;
    color:#0f172a;
    border:1px solid #e2e8f0;
    border-bottom-left-radius:4px;
    box-shadow:0 1px 2px rgba(0,0,0,.05);
}

/* タイピング */
.typing-row{display:flex;flex-direction:column}
.typing-row.side-a{align-self:flex-end;align-items:flex-end}
.typing-row.side-b{align-self:flex-start;align-items:flex-start}
.typing-indicator{
    display:flex;
    align-items:center;
    gap:4px;
    padding:10px 14px;
    border-radius:18px;
    width:fit-content;
}
.typing-indicator-a{background:#2563eb}
.typing-indicator-b{background:#fff;border:1px solid #e2e8f0;box-shadow:0 1px 2px rgba(0,0,0,.05)}
.typing-dot{width:7px;height:7px;border-radius:50%;animation:typingBounce 1.2s infinite ease-in-out}
.typing-dot-a{background:rgba(255,255,255,.7)}
.typing-dot-b{background:#94a3b8}
.typing-dot:nth-child(2){animation-delay:.2s}
.typing-dot:nth-child(3){animation-delay:.4s}
@keyframes typingBounce{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-6px)}}

/* 結論 */
.debate-divider{
    display:flex;align-items:center;gap:8px;
    font-size:10px;color:#6b7c8f;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
}
.debate-divider::before,.debate-divider::after{content:'';flex:1;height:1px;background:#b0bec5}

.judge-card{
    background:#fefce8;
    border:1px solid #fde68a;
    border-radius:12px;
    padding:14px 16px;
    width:100%;
}
.judge-header{
    font-size:11px;font-weight:700;letter-spacing:.06em;
    color:#92400e;margin-bottom:8px;
    display:flex;align-items:center;gap:6px;text-transform:uppercase;
}
.judge-body{font-size:13px;line-height:1.85;color:#1c1917;white-space:pre-wrap}

/* ステータスバー */
.status-bar{
    font-size:11px;color:#92400e;
    text-align:center;padding:8px 12px;
    background:#fffbeb;border:1px solid #fde68a;border-radius:6px;
    margin-bottom:.75rem;display:none;font-weight:500;
}
.status-bar.active{display:block}

@media(max-width:600px){
    .msg-row,.msg-row.side-judge{max-width:88%}
    .container{padding:1rem}
    .row{flex-wrap:wrap}
    .row input[type=text]{flex:1 1 100%}
    .row .btn{flex:1 1 auto;font-size:.75rem;padding:.45rem .6rem}
    .debate-wrap{max-height:60vh}
}
</style>
</head>
<body>

<header>
    <div class="logo-group"><div class="logo">U<span>Debate</span></div><span class="u2a-badge">URL2AI</span>Debate</div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?db_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?db_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">

    <!-- STEP 1 -->
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
                        <?php if (!$is_admin): ?> disabled title="管理者のみ"<?php endif; ?>
                        onclick="submitFetch()">
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

    <!-- STEP 2 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">2</span> スレッド本文（編集可）</div>
            <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .7rem"
                onclick="document.getElementById('thread_text').value='';document.getElementById('thread_count').textContent='0 文字'">クリア</button>
        </div>
        <div class="section-body">
            <textarea class="code-area" id="thread_text" rows="5"
                placeholder="スレッド本文がここに表示されます。直接入力も可能です。"><?php echo h($thread_text); ?></textarea>
            <div class="char-count" id="thread_count"><?php echo mb_strlen($thread_text); ?> 文字</div>
        </div>
    </div>

    <!-- STEP 3 -->
    <div style="display:flex;justify-content:center;margin-bottom:1rem">
        <button type="button" class="btn btn-debate" id="btn-debate"
            <?php if (!$is_admin): ?> disabled title="管理者のみ"<?php endif; ?>
            onclick="startDebate()">
            <span class="btn-label">⚔️ AI議論を開始</span>
            <span class="spinner"></span>
        </button>
    </div>

    <div class="status-bar" id="status-bar">準備中...</div>

    <!-- STEP 4: 議論表示 -->
    <div class="section" id="debate-section" style="<?php echo ($saved_debate ? '' : 'display:none'); ?>">
        <div class="section-header">
            <div class="section-title">
                <span class="step" style="background:#7c3aed">⚔</span>
                AI議論タイムライン
                <span style="font-size:10px;color:var(--muted);font-weight:400;margin-left:6px;">
                    🔵 肯定 &nbsp; ⬜ 否定
                </span>
            </div>
            <?php if ($saved_debate && !empty($saved_debate['debate_at'])): ?>
            <span style="font-size:10px;color:var(--muted)"><?php echo h($saved_debate['debate_at']); ?></span>
            <?php endif; ?>
        </div>
        <div class="section-body" style="padding:.75rem">
            <div class="debate-wrap" id="debate-wrap">

            <?php if ($saved_debate && !empty($saved_debate['debate_turns'])): ?>
            <?php
            $ra = 0; $rb = 0;
            $la = array('冒頭主張', '再反論', '最終主張');
            $lb = array('反論①', '再反論', '最終主張');
            foreach ($saved_debate['debate_turns'] as $turn):
                $spk = $turn['speaker'];
                $txt = isset($turn['text']) ? $turn['text'] : '';
                if ($spk === 'A'):
                    $badge = isset($la[$ra]) ? $la[$ra] : ''; $ra++;
            ?>
            <div class="msg-row side-a">
                <?php if ($badge): ?><span class="round-badge round-badge-a"><?php echo h($badge); ?></span><?php endif; ?>
                <div class="speaker-label">肯定AI</div>
                <div class="bubble bubble-a"><?php echo h($txt); ?></div>
            </div>
            <?php elseif ($spk === 'B'):
                    $badge = isset($lb[$rb]) ? $lb[$rb] : ''; $rb++;
            ?>
            <div class="msg-row side-b">
                <?php if ($badge): ?><span class="round-badge round-badge-b"><?php echo h($badge); ?></span><?php endif; ?>
                <div class="speaker-label">否定AI</div>
                <div class="bubble bubble-b"><?php echo h($txt); ?></div>
            </div>
            <?php elseif ($spk === 'judge'): ?>
            <div class="debate-divider">結論</div>
            <div class="msg-row side-judge">
                <div class="judge-card">
                    <div class="judge-header">⚖️ 司会AI — まとめ</div>
                    <div class="judge-body"><?php echo h($txt); ?></div>
                </div>
            </div>
            <?php endif; endforeach; ?>
            <?php endif; ?>

            </div>

            <?php if ($saved_debate && !empty($saved_debate['debate_conclusion'])): ?>
            <?php
                $tid_copy    = isset($saved_debate['tweet_id']) ? $saved_debate['tweet_id'] : extract_tweet_id($tweet_url);
                $debatev_url = 'https://aiknowledgecms.exbridge.jp/udebatev.php?id=' . urlencode($tid_copy);
            ?>
            <div style="display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap;">
                <button id="copy-debate-btn" type="button" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .8rem" onclick="copyDebate()">📋 まとめをコピー</button>
                <a href="<?php echo h($debatev_url); ?>" target="_blank" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .8rem">📋 UDebateV で見る ↗</a>
            </div>
            <script>
            function copyDebate() {
                var conclusion = <?php echo json_encode($saved_debate['debate_conclusion'], JSON_UNESCAPED_UNICODE); ?>;
                var tweetUrl  = <?php echo json_encode($tweet_url, JSON_UNESCAPED_UNICODE); ?>;
                var debatevUrl = <?php echo json_encode($debatev_url, JSON_UNESCAPED_UNICODE); ?>;
                var text = '#URL2AI 議論\n' + conclusion
                         + '\n\n意見の異なる２つのAIで議論させました。\nUDebate AI\n' + debatevUrl
                         + '\n\n' + tweetUrl;
                navigator.clipboard.writeText(text).then(function() {
                    var btn = document.getElementById('copy-debate-btn');
                    btn.textContent = '✓ コピー済';
                    setTimeout(function() { btn.textContent = '📋 まとめをコピー'; }, 2000);
                });
            }
            </script>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
var debateConclusion = '';
var debateTweetUrl = '';
var debateRunning = false;
var roundA = 0; var roundB = 0;
var labelsA = ['冒頭主張', '再反論', '最終主張'];
var labelsB = ['反論①', '再反論', '最終主張'];

/* 文字カウント */
var ta = document.getElementById('thread_text');
var counter = document.getElementById('thread_count');
if (ta && counter) {
    ta.addEventListener('input', function() {
        counter.textContent = this.value.length + ' 文字';
    });
}

function submitFetch() {
    var btn = document.getElementById('btn-fetch');
    btn.classList.add('loading'); btn.disabled = true;
    document.getElementById('form-fetch').submit();
}

function setStatus(msg) {
    var bar = document.getElementById('status-bar');
    bar.textContent = msg;
    bar.className = 'status-bar active';
}

function appendTyping(side) {
    var wrap = document.getElementById('debate-wrap');
    var row = document.createElement('div');
    row.className = 'typing-row side-' + side;
    row.id = 'typing-row';
    var ind = document.createElement('div');
    ind.className = 'typing-indicator typing-indicator-' + side;
    var dc = side === 'a' ? 'typing-dot typing-dot-a' : 'typing-dot typing-dot-b';
    ind.innerHTML = '<div class="' + dc + '"></div><div class="' + dc + '"></div><div class="' + dc + '"></div>';
    row.appendChild(ind);
    wrap.appendChild(row);
    wrap.scrollTop = wrap.scrollHeight;
}

function removeTyping() {
    var el = document.getElementById('typing-row');
    if (el) el.parentNode.removeChild(el);
}

function appendMessage(speaker, text) {
    removeTyping();
    var wrap = document.getElementById('debate-wrap');

    if (speaker === 'judge') {
        debateConclusion = text;
        var div = document.createElement('div');
        div.className = 'debate-divider'; div.textContent = '結論';
        wrap.appendChild(div);
        var row = document.createElement('div');
        row.className = 'msg-row side-judge';
        var card = document.createElement('div');
        card.className = 'judge-card';
        var hdr = document.createElement('div');
        hdr.className = 'judge-header'; hdr.textContent = '⚖️ 司会AI — まとめ';
        var body = document.createElement('div');
        body.className = 'judge-body'; body.textContent = text;
        card.appendChild(hdr); card.appendChild(body);
        row.appendChild(card); wrap.appendChild(row);

    } else if (speaker === 'A') {
        var badge = roundA < labelsA.length ? labelsA[roundA] : '';
        roundA++;
        var row = document.createElement('div');
        row.className = 'msg-row side-a';
        if (badge) {
            var sp = document.createElement('span');
            sp.className = 'round-badge round-badge-a'; sp.textContent = badge;
            row.appendChild(sp);
        }
        var lbl = document.createElement('div');
        lbl.className = 'speaker-label'; lbl.textContent = '肯定AI';
        row.appendChild(lbl);
        var bubble = document.createElement('div');
        bubble.className = 'bubble bubble-a'; bubble.textContent = text;
        row.appendChild(bubble);
        wrap.appendChild(row);

    } else if (speaker === 'B') {
        var badge = roundB < labelsB.length ? labelsB[roundB] : '';
        roundB++;
        var row = document.createElement('div');
        row.className = 'msg-row side-b';
        if (badge) {
            var sp = document.createElement('span');
            sp.className = 'round-badge round-badge-b'; sp.textContent = badge;
            row.appendChild(sp);
        }
        var lbl = document.createElement('div');
        lbl.className = 'speaker-label'; lbl.textContent = '否定AI';
        row.appendChild(lbl);
        var bubble = document.createElement('div');
        bubble.className = 'bubble bubble-b'; bubble.textContent = text;
        row.appendChild(bubble);
        wrap.appendChild(row);
    }

    wrap.scrollTop = wrap.scrollHeight;
}

function startDebate() {
    if (debateRunning) return;
    var thread = document.getElementById('thread_text').value.trim();
    if (!thread) { alert('スレッド本文を入力してください'); return; }
    var tweetUrl = document.getElementById('tweet_url_input').value.trim();
    var m = tweetUrl.match(/(\d{15,20})/);
    var tweetId = m ? m[1] : '';
    if (!tweetId) { alert('ツイートIDが取得できません。URLを確認してください'); return; }

    debateRunning = true;
    roundA = 0; roundB = 0;
    var btn = document.getElementById('btn-debate');
    btn.disabled = true; btn.classList.add('loading');
    setStatus('⏳ 議論を生成中です。しばらくお待ちください（3〜5分程度）...');

    var section = document.getElementById('debate-section');
    section.style.display = '';
    var wrap = document.getElementById('debate-wrap');
    wrap.innerHTML = '';

    var statusSeq = {
        'A': ['肯定AIが主張を生成中...', '肯定AIが再反論を生成中...', '肯定AIが最終主張を生成中...'],
        'B': ['否定AIが反論を生成中...', '否定AIが再反論を生成中...', '否定AIが最終主張を生成中...'],
        'judge': ['司会AIが結論をまとめています...']
    };
    var pendingA = 0; var pendingB = 0;

    var params = '?sse=1&tweet_id=' + encodeURIComponent(tweetId)
               + '&thread=' + encodeURIComponent(thread);
    var es = new EventSource(params);

    es.onmessage = function(e) {
        var d;
        try { d = JSON.parse(e.data); } catch(ex) { return; }

        if (d.type === 'error') {
            removeTyping();
            setStatus('エラー: ' + d.text);
            debateRunning = false;
            btn.disabled = false; btn.classList.remove('loading');
            es.close();
        } else if (d.type === 'start') {
            var spk = d.speaker;
            var side = spk === 'A' ? 'a' : (spk === 'B' ? 'b' : 'judge');
            var idx = spk === 'A' ? pendingA : (spk === 'B' ? pendingB : 0);
            var msgs = statusSeq[spk] || ['生成中...'];
            setStatus('⏳ ' + (msgs[idx] || msgs[msgs.length - 1]));
            if (spk === 'A') pendingA++;
            if (spk === 'B') pendingB++;
            appendTyping(side);
        } else if (d.type === 'message') {
            appendMessage(d.speaker, d.text);
        } else if (d.type === 'done') {
            es.close();
            debateRunning = false;
            btn.disabled = false; btn.classList.remove('loading');
            var bar = document.getElementById('status-bar');
            bar.textContent = '✅ 議論が完了しました';
            setTimeout(function() { bar.className = 'status-bar'; }, 4000);
            /* コピーボタン + UDebateVリンクをDOMに追加 */
            debateTweetUrl = document.getElementById('tweet_url_input').value.trim();
            var m = debateTweetUrl.match(/(\d{15,20})/);
            var tid = m ? m[1] : '';
            var debatevUrl = 'https://aiknowledgecms.exbridge.jp/udebatev.php?id=' + encodeURIComponent(tid);
            var sectionBody = document.querySelector('#debate-section .section-body');
            if (sectionBody && debateConclusion) {
                var btnRow = document.createElement('div');
                btnRow.style.cssText = 'display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap;';
                btnRow.innerHTML = '<button id="copy-debate-btn" type="button" style="display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .8rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:1px solid #cbd5e1;background:#f1f5f9;color:#0f172a;font-family:inherit;" onclick="copyDebateJS()">📋 まとめをコピー</button>'
                    + '<a href="' + debatevUrl + '" target="_blank" style="display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .8rem;border-radius:6px;font-size:.82rem;font-weight:600;border:1px solid #cbd5e1;background:#f1f5f9;color:#0f172a;text-decoration:none;">📋 UDebateV で見る ↗</a>';
                sectionBody.appendChild(btnRow);
            }
        }
    };

    es.onerror = function() {
        es.close();
        debateRunning = false;
        btn.disabled = false; btn.classList.remove('loading');
        removeTyping();
        setStatus('接続エラーが発生しました');
    };
}

function copyDebateJS() {
    var tweetUrl  = document.getElementById('tweet_url_input').value.trim();
    var m = tweetUrl.match(/(\d{15,20})/);
    var tid = m ? m[1] : '';
    var debatevUrl = 'https://aiknowledgecms.exbridge.jp/udebatev.php?id=' + encodeURIComponent(tid);
    var text = '#URL2AI 議論\n' + debateConclusion
             + '\n\n意見の異なる２つのAIで議論させました。\nUDebate AI\n' + debatevUrl
             + '\n\n' + tweetUrl;
    navigator.clipboard.writeText(text).then(function() {
        var btn = document.getElementById('copy-debate-btn');
        if (btn) { btn.textContent = '✓ コピー済'; setTimeout(function() { btn.textContent = '📋 まとめをコピー'; }, 2000); }
    });
}
</script>
</body>
</html>
