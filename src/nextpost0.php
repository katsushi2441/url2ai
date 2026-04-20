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

$BASE_URL   = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE  = 'nextpost.php';
$ADMIN      = 'xb_bittensor';
$DATA_DIR   = __DIR__ . '/data';
$LOG_FILE   = $DATA_DIR . '/nextpost_log.json';

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
$x_client_id     = isset($x_keys['X_API_KEY'])             ? $x_keys['X_API_KEY']             : '';
$x_client_secret = isset($x_keys['X_API_SECRET'])          ? $x_keys['X_API_SECRET']          : '';
$o1_key          = isset($x_keys['X_API_KEY'])              ? $x_keys['X_API_KEY']              : '';
$o1_secret       = isset($x_keys['X_API_KEY_SECRET'])       ? $x_keys['X_API_KEY_SECRET']       : '';
$o1_token        = isset($x_keys['X_ACCESS_TOKEN'])         ? $x_keys['X_ACCESS_TOKEN']         : '';
$o1_token_secret = isset($x_keys['X_ACCESS_TOKEN_SECRET'])  ? $x_keys['X_ACCESS_TOKEN_SECRET']  : '';
$x_redirect_uri  = $BASE_URL . '/' . $THIS_FILE;

/* =========================================================
   OAuth2 PKCE（ログイン用）
========================================================= */
function np_b64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function np_verifier() {
    $b = ''; for ($i = 0; $i < 32; $i++) { $b .= chr(mt_rand(0,255)); } return np_b64url($b);
}
function np_challenge($v) { return np_b64url(hash('sha256', $v, true)); }
function np_http_post($url, $data, $headers) {
    $opts = array('http' => array('method' => 'POST',
        'header'  => implode("\r\n", $headers)."\r\n",
        'content' => $data, 'timeout' => 15, 'ignore_errors' => true));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    return json_decode($r ? $r : '{}', true);
}
function np_http_get($url, $token) {
    $opts = array('http' => array('method' => 'GET',
        'header'  => "Authorization: Bearer $token\r\nUser-Agent: NextPost/1.0\r\n",
        'timeout' => 12, 'ignore_errors' => true));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    return json_decode($r ? $r : '{}', true);
}

if (isset($_GET['np_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time()-3600, '/', 'aiknowledgecms.exbridge.jp', true, true);
    header('Location: '.$x_redirect_uri); exit;
}
if (isset($_GET['np_login'])) {
    $ver = np_verifier(); $chal = np_challenge($ver); $state = md5(uniqid('',true));
    $_SESSION['np_ver'] = $ver; $_SESSION['np_state'] = $state;
    $p = array('response_type'=>'code','client_id'=>$x_client_id,'redirect_uri'=>$x_redirect_uri,
               'scope'=>'tweet.read users.read offline.access',
               'state'=>$state,'code_challenge'=>$chal,'code_challenge_method'=>'S256');
    header('Location: https://twitter.com/i/oauth2/authorize?'.http_build_query($p)); exit;
}
if (isset($_GET['code'], $_GET['state'], $_SESSION['np_state']) && $_GET['state'] === $_SESSION['np_state']) {
    $cred = base64_encode($x_client_id.':'.$x_client_secret);
    $data = np_http_post('https://api.twitter.com/2/oauth2/token',
        http_build_query(array('grant_type'=>'authorization_code','code'=>$_GET['code'],
            'redirect_uri'=>$x_redirect_uri,'code_verifier'=>$_SESSION['np_ver'],'client_id'=>$x_client_id)),
        array('Content-Type: application/x-www-form-urlencoded','Authorization: Basic '.$cred));
    if (!empty($data['access_token'])) {
        $_SESSION['session_access_token']  = $data['access_token'];
        $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
        if (!empty($data['refresh_token'])) { $_SESSION['session_refresh_token'] = $data['refresh_token']; }
        unset($_SESSION['np_state'], $_SESSION['np_ver']);
        $me = np_http_get('https://api.twitter.com/2/users/me', $data['access_token']);
        if (!empty($me['data']['username'])) { $_SESSION['session_username'] = $me['data']['username']; }
    }
    header('Location: '.$x_redirect_uri); exit;
}
/* リフレッシュ */
if (!empty($_SESSION['session_refresh_token']) && !empty($_SESSION['session_token_expires'])
    && time() > $_SESSION['session_token_expires'] - 300) {
    $cred_r = base64_encode($x_client_id.':'.$x_client_secret);
    $ref = np_http_post('https://api.twitter.com/2/oauth2/token',
        http_build_query(array('grant_type'=>'refresh_token',
            'refresh_token'=>$_SESSION['session_refresh_token'],'client_id'=>$x_client_id)),
        array('Content-Type: application/x-www-form-urlencoded','Authorization: Basic '.$cred_r));
    if (!empty($ref['access_token'])) {
        $_SESSION['session_access_token']  = $ref['access_token'];
        $_SESSION['session_token_expires'] = time() + (isset($ref['expires_in']) ? (int)$ref['expires_in'] : 7200);
        if (!empty($ref['refresh_token'])) { $_SESSION['session_refresh_token'] = $ref['refresh_token']; }
    } else {
        unset($_SESSION['session_access_token'], $_SESSION['session_refresh_token'],
              $_SESSION['session_token_expires'], $_SESSION['session_username']);
    }
}

$logged_in    = !empty($_SESSION['session_access_token']);
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin     = ($session_user === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* =========================================================
   OAuth1.0a 署名（X投稿用）
========================================================= */
function np_oauth1_header($method, $url, $o1_key, $o1_secret, $o1_token, $o1_token_secret) {
    $oauth = array(
        'oauth_consumer_key'     => $o1_key,
        'oauth_nonce'            => bin2hex(openssl_random_pseudo_bytes(16)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp'        => (string)time(),
        'oauth_token'            => $o1_token,
        'oauth_version'          => '1.0',
    );
    ksort($oauth);
    $base = $method.'&'.rawurlencode($url).'&'.rawurlencode(http_build_query($oauth));
    $key  = rawurlencode($o1_secret).'&'.rawurlencode($o1_token_secret);
    $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));
    $parts = array();
    foreach ($oauth as $k => $v) { $parts[] = rawurlencode($k).'="'.rawurlencode($v).'"'; }
    return 'OAuth '.implode(', ', $parts);
}

/* =========================================================
   X投稿（OAuth1.0a）
========================================================= */
function np_post_tweet($text, $reply_id, $quote_url,
                        $o1_key, $o1_secret, $o1_token, $o1_token_secret) {
    $url     = 'https://api.twitter.com/2/tweets';
    $payload = array('text' => $text);
    if ($reply_id  !== '') { $payload['reply'] = array('in_reply_to_tweet_id' => $reply_id); }
    if ($quote_url !== '') {
        if (preg_match('/(\d{10,20})/', $quote_url, $m)) {
            $payload['quote_tweet_id'] = $m[1];
        }
    }
    $auth = np_oauth1_header('POST', $url, $o1_key, $o1_secret, $o1_token, $o1_token_secret);
    $opts = array('http' => array('method' => 'POST',
        'header'  => "Authorization: $auth\r\nContent-Type: application/json\r\nUser-Agent: NextPost/1.0\r\n",
        'content' => json_encode($payload), 'timeout' => 20, 'ignore_errors' => true));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    return json_decode($r ? $r : '{}', true);
}

/* =========================================================
   ネタファイル操作
   保存先: data/nextpost_ITEMID.json
========================================================= */
function np_item_file($item_id) {
    global $DATA_DIR;
    return $DATA_DIR . '/nextpost_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $item_id) . '.json';
}
function np_load_item($item_id) {
    $f = np_item_file($item_id);
    if (!file_exists($f)) return null;
    return json_decode(file_get_contents($f), true);
}
function np_save_item($item) {
    file_put_contents(np_item_file($item['id']),
        json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function np_load_all_items() {
    global $DATA_DIR;
    $files = glob($DATA_DIR . '/nextpost_*.json');
    if (!$files) return array();
    $items = array();
    foreach ($files as $f) {
        if (strpos($f, 'nextpost_log') !== false) continue;
        $d = json_decode(file_get_contents($f), true);
        if (is_array($d) && isset($d['id'])) { $items[] = $d; }
    }
    usort($items, function($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    return $items;
}

/* =========================================================
   ログ操作（1ファイル）
========================================================= */
function np_load_log() {
    global $LOG_FILE;
    if (!file_exists($LOG_FILE)) return array();
    $d = json_decode(file_get_contents($LOG_FILE), true);
    return is_array($d) ? $d : array();
}
function np_append_log($entry) {
    global $LOG_FILE;
    $log = np_load_log();
    array_unshift($log, $entry);
    file_put_contents($LOG_FILE, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/* =========================================================
   POST処理
========================================================= */
$msg_ok = $msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $act = isset($_POST['action']) ? $_POST['action'] : '';

    /* ---- 新規ネタ登録 ---- */
    if ($act === 'new_item') {
        $body = trim(isset($_POST['body']) ? $_POST['body'] : '');
        $tags = trim(isset($_POST['tags']) ? $_POST['tags'] : '');
        $memo = trim(isset($_POST['memo']) ? $_POST['memo'] : '');
        if ($body === '') {
            $msg_err = '本文を入力してください';
        } else {
            $item_id = date('YmdHis') . '_' . substr(md5(uniqid('',true)), 0, 6);
            np_save_item(array(
                'id'         => $item_id,
                'body'       => $body,
                'tags'       => $tags,
                'memo'       => $memo,
                'created_at' => date('Y-m-d H:i:s'),
                'posted'     => false,
                'x_post_ids' => array(),
            ));
            $msg_ok = 'ネタを登録しました（ID: '.$item_id.'）';
        }
    }

    /* ---- ネタ編集 ---- */
    if ($act === 'edit_item') {
        $item_id = trim(isset($_POST['item_id']) ? $_POST['item_id'] : '');
        $body    = trim(isset($_POST['body'])    ? $_POST['body']    : '');
        $item = np_load_item($item_id);
        if ($item && $body !== '') {
            $item['body'] = $body;
            $item['tags'] = trim(isset($_POST['tags']) ? $_POST['tags'] : '');
            $item['memo'] = trim(isset($_POST['memo']) ? $_POST['memo'] : '');
            np_save_item($item);
            $msg_ok = 'ネタを更新しました';
        }
    }

    /* ---- X投稿 ---- */
    if ($act === 'post_tweet') {
        $item_id   = trim(isset($_POST['item_id'])     ? $_POST['item_id']     : '');
        $post_type = trim(isset($_POST['post_type'])   ? $_POST['post_type']   : 'tweet');
        $reply_id  = trim(isset($_POST['reply_to_id']) ? $_POST['reply_to_id'] : '');
        $quote_url = trim(isset($_POST['quote_url'])   ? $_POST['quote_url']   : '');
        $post_text = trim(isset($_POST['post_text'])   ? $_POST['post_text']   : '');
        $item = np_load_item($item_id);
        if (!$item)          { $msg_err = 'ネタが見つかりません'; }
        elseif ($post_text === '') { $msg_err = '投稿テキストを入力してください'; }
        else {
            global $o1_key, $o1_secret, $o1_token, $o1_token_secret;
            $res = np_post_tweet($post_text,
                $post_type === 'reply' ? $reply_id  : '',
                $post_type === 'quote' ? $quote_url : '',
                $o1_key, $o1_secret, $o1_token, $o1_token_secret);

            if (!empty($res['data']['id'])) {
                $x_post_id = $res['data']['id'];
                if (!isset($item['x_post_ids'])) { $item['x_post_ids'] = array(); }
                $item['x_post_ids'][] = array(
                    'x_post_id'  => $x_post_id,
                    'post_type'  => $post_type,
                    'username'   => $session_user,
                    'posted_at'  => date('Y-m-d H:i:s'),
                );
                $item['posted'] = true;
                np_save_item($item);
                np_append_log(array(
                    'item_id'   => $item_id,
                    'x_post_id' => $x_post_id,
                    'post_type' => $post_type,
                    'username'  => $session_user,
                    'text'      => mb_substr($post_text, 0, 50),
                    'posted_at' => date('Y-m-d H:i:s'),
                ));
                $msg_ok = 'X投稿しました（投稿ID: '.$x_post_id.'）';
            } else {
                $detail = isset($res['detail']) ? $res['detail']
                        : (isset($res['title'])  ? $res['title'] : json_encode($res, JSON_UNESCAPED_UNICODE));
                $msg_err = 'X投稿に失敗: '.$detail;
            }
        }
    }
}

/* データ読み込み */
$items       = np_load_all_items();
$log         = np_load_log();
$log_by_item = array();
foreach ($log as $entry) { $log_by_item[$entry['item_id']][] = $entry; }
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NextPost — X投稿ネタ管理</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#1d9bf0;--accent-h:#1a8cd8;
    --green:#059669;--red:#dc2626;
    --text:#0f172a;--muted:#64748b;
    --mono:'JetBrains Mono',monospace;--sans:'Inter',sans-serif;
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;font-size:14px}

header{background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.logo{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:8px}
.logo svg{width:18px;height:18px;fill:var(--accent)}
.logo span{color:var(--accent)}
.userbar{display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:var(--muted)}
.userbar strong{color:var(--green)}
.btn-sm{background:none;border:1px solid var(--border2);color:var(--muted);padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-sm:hover{border-color:var(--red);color:var(--red)}

.wrap{max-width:1180px;margin:0 auto;padding:1.5rem;display:grid;grid-template-columns:1fr 300px;gap:1.5rem;align-items:start}
@media(max-width:860px){.wrap{grid-template-columns:1fr}}

.section{background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:1.25rem}
.section-header{padding:.65rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between}
.section-title{font-weight:600;font-size:.82rem;color:var(--text);display:flex;align-items:center;gap:.4rem}

/* ── ツイートカード ── */
.tweet-card{border-bottom:1px solid #f0f0f0;padding:16px;transition:background .1s}
.tweet-card:last-child{border-bottom:none}
.tweet-card:hover{background:#fafafa}
.tweet-card.is-posted{border-left:3px solid var(--green)}
.tweet-card.not-posted{border-left:3px solid var(--accent)}

.card-top{display:flex;gap:10px;margin-bottom:10px}
.avatar{width:40px;height:40px;background:linear-gradient(135deg,#1d9bf0,#7c3aed);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0}
.card-info{flex:1;min-width:0}
.card-name{font-weight:700;font-size:13px}
.card-date{font-size:11px;color:var(--muted);font-family:var(--mono)}
.card-body{font-size:14px;line-height:1.8;white-space:pre-wrap;word-break:break-word;margin-bottom:10px}
.card-tags{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px}
.tag{font-size:11px;padding:2px 8px;border-radius:10px;background:#eff6ff;color:var(--accent);border:1px solid #bfdbfe;font-weight:500}
.card-memo{font-size:11px;color:var(--muted);background:#f8fafc;border-radius:6px;padding:5px 8px;margin-bottom:8px;font-style:italic}

.posted-label{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:var(--green);background:#dcfce7;border:1px solid #bbf7d0;border-radius:6px;padding:2px 8px;margin-bottom:6px}
.x-post-chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;color:var(--accent);background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:2px 8px;font-family:var(--mono);margin:2px 2px 4px 0}
.x-post-chip a{color:var(--accent);text-decoration:none}
.x-post-chip a:hover{text-decoration:underline}

.card-actions{display:flex;gap:6px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--border);margin-top:4px}
.btn-act{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans)}
.ba-tweet{background:var(--accent);color:#fff}
.ba-tweet:hover{background:var(--accent-h)}
.ba-reply{background:#f0f9ff;color:var(--accent);border:1px solid #bfdbfe}
.ba-reply:hover{background:#dbeafe}
.ba-quote{background:#fdf4ff;color:#7c3aed;border:1px solid #e9d5ff}
.ba-quote:hover{background:#f3e8ff}
.ba-edit{background:#f8fafc;color:var(--muted);border:1px solid var(--border2)}
.ba-edit:hover{color:var(--text);background:#f1f5f9}

/* ── フォーム共通 ── */
.form-row{margin-bottom:.75rem}
.form-label{display:block;font-size:.75rem;font-weight:600;color:var(--muted);margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.04em}
textarea,input[type=text]{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.5rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;color:var(--text);resize:vertical;transition:border .15s}
textarea:focus,input[type=text]:focus{border-color:var(--accent)}
.char-counter{font-size:.72rem;color:var(--muted);text-align:right;margin-top:.2rem;font-family:var(--mono)}
.char-counter.over{color:var(--red);font-weight:700}

.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans)}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn-post{background:var(--green);color:#fff}
.btn-post:hover{background:#047857}

/* ── モーダル ── */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:14px;width:min(520px,96vw);padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative;max-height:90vh;overflow-y:auto}
.modal-title{font-size:15px;font-weight:700;margin-bottom:16px}
.modal-x{position:absolute;top:12px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);line-height:1}

.tabs{display:flex;gap:3px;background:#f1f5f9;border-radius:8px;padding:3px;margin-bottom:14px}
.tab{flex:1;padding:6px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:none;color:var(--muted);transition:all .15s;text-align:center}
.tab.on{background:#fff;color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.1)}

/* ── メッセージ ── */
.msg{padding:.6rem 1rem;border-radius:6px;font-size:.82rem;font-weight:600;margin-bottom:1rem}
.msg-ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.msg-err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}

/* ── ログ ── */
.log-tbl{width:100%;border-collapse:collapse;font-size:11px}
.log-tbl th{background:#f8fafc;padding:6px 8px;text-align:left;border-bottom:1px solid var(--border);color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.log-tbl td{padding:5px 8px;border-bottom:1px solid var(--border);vertical-align:top}
.log-tbl tr:last-child td{border-bottom:none}
.type-badge{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase}
.tb-tweet{background:#eff6ff;color:var(--accent)}
.tb-reply{background:#f0fdf4;color:var(--green)}
.tb-quote{background:#fdf4ff;color:#7c3aed}

.empty{text-align:center;color:var(--muted);padding:32px;font-size:.85rem}
</style>
</head>
<body>

<header>
    <div class="logo">
        <svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        Next<span>Post</span>
    </div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($session_user); ?></strong></span>
        <a href="?np_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?np_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<div class="wrap">
<div><!-- メインカラム -->

<?php if ($msg_ok): ?><div class="msg msg-ok">✓ <?php echo h($msg_ok); ?></div><?php endif; ?>
<?php if ($msg_err): ?><div class="msg msg-err">✗ <?php echo h($msg_err); ?></div><?php endif; ?>

<div class="section">
    <div class="section-header">
        <div class="section-title">
            投稿ネタ一覧
            <span style="font-size:11px;color:var(--muted);font-weight:400"><?php echo count($items); ?> 件</span>
        </div>
        <?php if ($is_admin): ?>
        <button class="btn btn-primary" style="font-size:.75rem;padding:.3rem .9rem" onclick="openModal('m-new')">＋ 新規登録</button>
        <?php endif; ?>
    </div>

    <?php if (empty($items)): ?>
    <div class="empty">ネタがまだありません。「＋ 新規登録」から追加してください。</div>
    <?php else: foreach ($items as $item):
        $is_posted = !empty($item['x_post_ids']);
    ?>
    <div class="tweet-card <?php echo $is_posted ? 'is-posted' : 'not-posted'; ?>">
        <div class="card-top">
            <div class="avatar"><?php echo h(mb_strtoupper(mb_substr($ADMIN, 0, 1))); ?></div>
            <div class="card-info">
                <div class="card-name"><?php echo h($ADMIN); ?></div>
                <div class="card-date"><?php echo h($item['created_at']); ?> &nbsp;#<?php echo h($item['id']); ?></div>
            </div>
        </div>

        <div class="card-body"><?php echo h($item['body']); ?></div>

        <?php if (!empty($item['tags'])): ?>
        <div class="card-tags"><?php foreach (explode(' ', $item['tags']) as $t): if (trim($t)==='') continue; ?>
            <span class="tag"><?php echo h(trim($t)); ?></span>
        <?php endforeach; ?></div>
        <?php endif; ?>

        <?php if (!empty($item['memo'])): ?>
        <div class="card-memo">📝 <?php echo h($item['memo']); ?></div>
        <?php endif; ?>

        <?php if ($is_posted): ?>
        <div style="margin-bottom:6px">
            <span class="posted-label">✓ 投稿済み</span><br>
            <?php foreach ($item['x_post_ids'] as $xp): ?>
            <span class="x-post-chip">
                <?php echo h($xp['post_type']); ?>&nbsp;
                <a href="https://x.com/<?php echo h($xp['username']); ?>/status/<?php echo h($xp['x_post_id']); ?>" target="_blank"><?php echo h($xp['x_post_id']); ?></a>
                &nbsp;<?php echo h(substr($xp['posted_at'],0,16)); ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
        <div class="card-actions">
            <button class="btn-act ba-tweet" onclick='openPostModal(<?php echo json_encode($item["id"]); ?>,<?php echo json_encode($item["body"]); ?>,"tweet")'>
                <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                投稿
            </button>
            <button class="btn-act ba-reply" onclick='openPostModal(<?php echo json_encode($item["id"]); ?>,<?php echo json_encode($item["body"]); ?>,"reply")'>💬 リプ</button>
            <button class="btn-act ba-quote" onclick='openPostModal(<?php echo json_encode($item["id"]); ?>,<?php echo json_encode($item["body"]); ?>,"quote")'>🔁 引用</button>
            <button class="btn-act ba-edit" onclick='openEditModal(<?php echo json_encode($item["id"]); ?>,<?php echo json_encode($item["body"]); ?>,<?php echo json_encode(isset($item["tags"]) ? $item["tags"] : ""); ?>,<?php echo json_encode(isset($item["memo"]) ? $item["memo"] : ""); ?>)'>✏️ 編集</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>

</div><!-- /メインカラム -->

<div><!-- サイドカラム -->

<div class="section">
    <div class="section-header">
        <div class="section-title">📜 投稿履歴</div>
    </div>
    <?php if (empty($log)): ?>
    <div class="empty">まだ投稿履歴がありません</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="log-tbl">
        <thead><tr><th>日時</th><th>種別</th><th>X投稿ID</th><th>テキスト</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($log, 0, 60) as $e): ?>
        <tr>
            <td style="white-space:nowrap"><?php echo h(substr($e['posted_at'],5,11)); ?></td>
            <td><span class="type-badge tb-<?php echo h($e['post_type']); ?>"><?php echo h($e['post_type']); ?></span></td>
            <td>
                <a href="https://x.com/<?php echo h($e['username']); ?>/status/<?php echo h($e['x_post_id']); ?>" target="_blank"
                   style="color:var(--accent);font-family:var(--mono);font-size:10px">
                    <?php echo h(substr($e['x_post_id'],-8)); ?>…
                </a>
            </td>
            <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo h($e['text']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /サイドカラム -->
</div><!-- /wrap -->

<!-- 新規登録モーダル -->
<div class="overlay" id="m-new">
    <div class="modal">
        <button class="modal-x" onclick="closeModal('m-new')">×</button>
        <div class="modal-title">＋ 新規ネタ登録</div>
        <form method="POST">
            <input type="hidden" name="action" value="new_item">
            <div class="form-row">
                <label class="form-label">本文</label>
                <textarea name="body" id="new-body" rows="5" placeholder="投稿したい内容" oninput="upCount('new-body','new-cnt')"></textarea>
                <div class="char-counter" id="new-cnt">0 / 280</div>
            </div>
            <div class="form-row">
                <label class="form-label">タグ（スペース区切り）</label>
                <input type="text" name="tags" placeholder="#AI #相互フォロー">
            </div>
            <div class="form-row">
                <label class="form-label">メモ（非公開）</label>
                <input type="text" name="memo" placeholder="投稿タイミングのメモなど">
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                <button type="button" class="btn btn-secondary" onclick="closeModal('m-new')">キャンセル</button>
                <button type="submit" class="btn btn-primary">登録する</button>
            </div>
        </form>
    </div>
</div>

<!-- 投稿モーダル -->
<div class="overlay" id="m-post">
    <div class="modal">
        <button class="modal-x" onclick="closeModal('m-post')">×</button>
        <div class="modal-title" id="post-title">X に投稿</div>
        <form method="POST">
            <input type="hidden" name="action" value="post_tweet">
            <input type="hidden" name="item_id" id="post-item-id">
            <input type="hidden" name="post_type" id="post-type-val">
            <div class="tabs">
                <button type="button" class="tab on" id="tab-tweet" onclick="setType('tweet')">投稿</button>
                <button type="button" class="tab" id="tab-reply" onclick="setType('reply')">リプライ</button>
                <button type="button" class="tab" id="tab-quote" onclick="setType('quote')">引用リポスト</button>
            </div>
            <div id="grp-reply" class="form-row" style="display:none">
                <label class="form-label">リプ先 投稿ID</label>
                <input type="text" name="reply_to_id" placeholder="1234567890123456789">
            </div>
            <div id="grp-quote" class="form-row" style="display:none">
                <label class="form-label">引用する投稿URL</label>
                <input type="text" name="quote_url" placeholder="https://x.com/user/status/...">
            </div>
            <div class="form-row">
                <label class="form-label">投稿テキスト</label>
                <textarea name="post_text" id="post-text" rows="6" oninput="upCount('post-text','post-cnt')"></textarea>
                <div class="char-counter" id="post-cnt">0 / 280</div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                <button type="button" class="btn btn-secondary" onclick="closeModal('m-post')">キャンセル</button>
                <button type="submit" class="btn btn-post">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                    X に投稿する
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 編集モーダル -->
<div class="overlay" id="m-edit">
    <div class="modal">
        <button class="modal-x" onclick="closeModal('m-edit')">×</button>
        <div class="modal-title">✏️ ネタ編集</div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="item_id" id="edit-item-id">
            <div class="form-row">
                <label class="form-label">本文</label>
                <textarea name="body" id="edit-body" rows="5" oninput="upCount('edit-body','edit-cnt')"></textarea>
                <div class="char-counter" id="edit-cnt">0 / 280</div>
            </div>
            <div class="form-row">
                <label class="form-label">タグ</label>
                <input type="text" name="tags" id="edit-tags">
            </div>
            <div class="form-row">
                <label class="form-label">メモ</label>
                <input type="text" name="memo" id="edit-memo">
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                <button type="button" class="btn btn-secondary" onclick="closeModal('m-edit')">キャンセル</button>
                <button type="submit" class="btn btn-primary">更新する</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.overlay').forEach(function(el){
    el.addEventListener('click',function(e){ if(e.target===el) el.classList.remove('open'); });
});
function upCount(taId, cntId){
    var ta=document.getElementById(taId), c=document.getElementById(cntId);
    if(!ta||!c) return;
    var n=ta.value.length;
    c.textContent=n+' / 280';
    c.className='char-counter'+(n>280?' over':'');
}
function openPostModal(itemId, body, type){
    document.getElementById('post-item-id').value=itemId;
    document.getElementById('post-text').value=body;
    upCount('post-text','post-cnt');
    setType(type);
    openModal('m-post');
}
function setType(type){
    document.getElementById('post-type-val').value=type;
    ['tweet','reply','quote'].forEach(function(t){
        document.getElementById('tab-'+t).className='tab'+(t===type?' on':'');
    });
    document.getElementById('grp-reply').style.display=type==='reply'?'':'none';
    document.getElementById('grp-quote').style.display=type==='quote'?'':'none';
    var titles={tweet:'X に投稿',reply:'リプライとして投稿',quote:'引用リポストとして投稿'};
    document.getElementById('post-title').textContent=titles[type]||'X に投稿';
}
function openEditModal(itemId,body,tags,memo){
    document.getElementById('edit-item-id').value=itemId;
    document.getElementById('edit-body').value=body;
    document.getElementById('edit-tags').value=tags;
    document.getElementById('edit-memo').value=memo;
    upCount('edit-body','edit-cnt');
    openModal('m-edit');
}
</script>
</body>
</html>
