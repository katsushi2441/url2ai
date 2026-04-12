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

$DATA_DIR  = __DIR__ . '/data';
$BASE_URL  = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE = 'xview.php';
$SITE_NAME = 'XView';
$ADMIN     = 'xb_bittensor';

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
$x_redirect_uri  = $BASE_URL . '/' . $THIS_FILE;

/* =========================================================
   OAuth2 PKCE
========================================================= */
function xv_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function xv_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return xv_base64url($bytes);
}
function xv_gen_challenge($verifier) {
    return xv_base64url(hash('sha256', $verifier, true));
}
function xv_x_post($url, $post_data, $headers) {
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
function xv_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: XView/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['xv_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/',
        'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['xv_login'])) {
    $verifier  = xv_gen_verifier();
    $challenge = xv_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['xv_code_verifier'] = $verifier;
    $_SESSION['xv_oauth_state']   = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['xv_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['xv_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['xv_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = xv_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']  = $data['access_token'];
            $_SESSION['session_token_expires']  = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['xv_oauth_state'], $_SESSION['xv_code_verifier']);
            $me = xv_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
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
    $ref = xv_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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

$logged_in    = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin     = ($session_user === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* =========================================================
   管理者：新規登録処理（AJAX）
========================================================= */
/* =========================================================
   管理者：新規登録処理（POST）
========================================================= */
$register_msg = '';
$register_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && isset($_POST['register_url'])) {
    $input    = trim($_POST['register_url']);
    $tweet_id = '';
    if (preg_match('/(\d{15,20})/', $input, $m)) { $tweet_id = $m[1]; }
    if ($tweet_id === '') {
        $register_err = 'URLからIDが取得できませんでした';
    } else {
        $save_file = $DATA_DIR . '/xinsight_' . $tweet_id . '.json';
        if (file_exists($save_file)) {
            /* 登録済み：表示のみ（詳細ページにリダイレクト） */
            header('Location: xview.php?id=' . urlencode($tweet_id));
            exit;
        }
        /* 未登録：fxtwitterで取得して保存 */
        $sh_bins = array('/bin/sh', '/usr/bin/sh', '/bin/bash');
        $sh = '';
        foreach ($sh_bins as $b) { if (file_exists($b)) { $sh = $b; break; } }
        function xv_fetch_thread($tweet_id, $depth, $sh) {
            if ($depth > 15) return array();
            $url = 'https://api.fxtwitter.com/i/status/' . $tweet_id;
            $cmd = 'curl -s --max-time 10 ' . escapeshellarg($url);
            $out = array(); $ret = 0;
            exec($sh . ' -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $ret);
            $fx = json_decode(implode('', $out), true);
            if (!$fx || empty($fx['tweet'])) return array();
            $tweet  = $fx['tweet'];
            $result = array();
            if (!empty($tweet['replying_to_status'])) {
                $result = xv_fetch_thread($tweet['replying_to_status'], $depth + 1, $sh);
            }
            $result[] = '@' . $tweet['author']['screen_name'] . ': ' . $tweet['text'];
            return $result;
        }
        $fx_url  = 'https://api.fxtwitter.com/i/status/' . $tweet_id;
        $cmd     = 'curl -s --max-time 10 ' . escapeshellarg($fx_url);
        $out     = array(); $ret = 0;
        exec($sh . ' -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $ret);
        $fx = json_decode(implode('', $out), true);
        if (!$fx || empty($fx['tweet'])) {
            $register_err = 'ツイートを取得できませんでした';
        } else {
            $thread_lines = xv_fetch_thread($tweet_id, 0, $sh);
            $thread_text  = implode("

", $thread_lines);
            $tweet_url_r  = 'https://x.com/' . $fx['tweet']['author']['screen_name'] . '/status/' . $tweet_id;
            $save_data = array(
                'tweet_id'    => $tweet_id,
                'tweet_url'   => $tweet_url_r,
                'username'    => $session_user,
                'thread_text' => $thread_text,
                'insight'     => '',
                'saved_at'    => date('Y-m-d H:i:s'),
            );
            file_put_contents($save_file, json_encode($save_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            header('Location: xview.php?id=' . urlencode($tweet_id));
            exit;
        }
    }
}

/* =========================================================
   データ読み込み
========================================================= */
$posts = array();
if (file_exists($DATA_DIR)) {
    $files = glob($DATA_DIR . '/xinsight_*.json');
    if ($files) {
        foreach ($files as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d)) { continue; }
            $posts[] = $d;
        }
        usort($posts, function($a, $b) {
            $ta = isset($a['saved_at']) ? $a['saved_at'] : '';
            $tb = isset($b['saved_at']) ? $b['saved_at'] : '';
            return strcmp($tb, $ta);
        });
    }
}

/* =========================================================
   詳細表示
========================================================= */
$detail_id   = isset($_GET['id']) ? trim($_GET['id']) : '';
$detail_post = null;
if ($detail_id) {
    foreach ($posts as $p) {
        if (isset($p['tweet_id']) && $p['tweet_id'] === $detail_id) {
            $detail_post = $p;
            break;
        }
    }
}

/* SEO */
if ($detail_post) {
    $thread_text_raw  = isset($detail_post['thread_text']) ? $detail_post['thread_text'] : '';
    $page_title       = mb_substr($thread_text_raw, 0, 50) . '... | ' . $SITE_NAME;
    $page_description = mb_substr(str_replace("\n", ' ', $thread_text_raw), 0, 160);
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['tweet_id']);
    $page_type        = 'article';
    $published_time   = isset($detail_post['saved_at']) ? $detail_post['saved_at'] : '';
    $jsonld = array(
        '@context'      => 'https://schema.org',
        '@type'         => 'SocialMediaPosting',
        'headline'      => mb_substr($thread_text_raw, 0, 50),
        'description'   => $page_description,
        'url'           => $page_url,
        'datePublished' => $published_time,
        'author'        => array('@type' => 'Person', 'name' => isset($detail_post['username']) ? $detail_post['username'] : 'xb_bittensor'),
        'publisher'     => array('@type' => 'Organization', 'name' => $SITE_NAME),
    );
} else {
    $page_title       = $SITE_NAME;
    $page_description = 'XのスレッドをAIで考察・保存するタイムライン。';
    $page_url         = $BASE_URL . '/' . $THIS_FILE;
    $page_type        = 'website';
    $published_time   = '';
    $jsonld = array(
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        'name'        => $page_title,
        'description' => $page_description,
        'url'         => $page_url,
        'publisher'   => array('@type' => 'Organization', 'name' => $SITE_NAME),
    );
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($page_description); ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo h($page_url); ?>">
<meta property="og:type" content="<?php echo h($page_type); ?>">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h($page_description); ?>">
<meta property="og:url" content="<?php echo h($page_url); ?>">
<meta property="og:site_name" content="<?php echo h($SITE_NAME); ?>">
<meta property="og:locale" content="ja_JP">
<?php if ($page_type === 'article' && $published_time): ?>
<meta property="article:published_time" content="<?php echo h($published_time); ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary">
<meta name="twitter:site" content="@xb_bittensor">
<meta name="twitter:title" content="<?php echo h($page_title); ?>">
<meta name="twitter:description" content="<?php echo h($page_description); ?>">
<script type="application/ld+json">
<?php echo json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>
</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#fff;color:#222;font-family:-apple-system,'Helvetica Neue',sans-serif;font-size:14px;}

/* ヘッダー */
.header{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 20px;position:sticky;top:0;z-index:100;display:flex;align-items:center;gap:12px;}
.header h1{font-size:17px;font-weight:700;color:#111;}
.header h1 a{text-decoration:none;color:inherit;}
.badge{background:#2563eb;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;}
.back-btn{margin-left:auto;font-size:13px;color:#2563eb;text-decoration:none;padding:5px 12px;border:1px solid #2563eb;border-radius:6px;}
.back-btn:hover{background:#eff6ff;}
.new-btn{margin-left:auto;font-size:13px;color:#fff;background:#2563eb;text-decoration:none;padding:5px 14px;border-radius:6px;font-weight:600;}
.new-btn:hover{background:#1d4ed8;}

/* ログイン */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:80vh;}
.login-card{text-align:center;padding:2.5rem;border:1px solid #e2e8f0;border-radius:12px;background:#fff;width:320px;box-shadow:0 4px 16px rgba(0,0,0,.06);}
.login-card h2{font-size:1.3rem;font-weight:700;margin-bottom:.4rem;}
.login-card p{color:#64748b;font-size:.82rem;margin-bottom:1.8rem;}
.btn-login{display:inline-flex;align-items:center;gap:.5rem;background:#2563eb;color:#fff;padding:.65rem 1.6rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:.88rem;}
.btn-login:hover{background:#1d4ed8;}
.btn-login svg{width:16px;height:16px;fill:white;}

/* userbar */
.userbar{margin-left:auto;display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:#64748b;}
.userbar strong{color:#059669;}
.btn-sm{background:none;border:1px solid #cbd5e1;color:#64748b;padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;}
.btn-sm:hover{border-color:#dc2626;color:#dc2626;}

/* タイムライン */
.container{max-width:640px;margin:0 auto;padding:0 0 80px;}
.count-bar{padding:10px 20px;font-size:13px;color:#888;border-bottom:1px solid #f0f0f0;}

/* カード */
.post-card{border-bottom:1px solid #f0f0f0;padding:20px;transition:background .15s;cursor:pointer;}
.post-card:hover{background:#fafafa;}
.post-meta{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.avatar{width:40px;height:40px;background:linear-gradient(135deg,#2563eb,#7c3aed);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0;}
.author-name{font-weight:700;color:#111;font-size:14px;}
.author-handle{color:#888;font-size:13px;}
.post-time{color:#aaa;font-size:12px;margin-left:auto;}

.post-id{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;color:#2563eb;margin-bottom:8px;text-decoration:none;display:block;}
.post-id:hover{text-decoration:underline;}

.post-thread{font-size:14px;line-height:1.75;color:#333;margin-bottom:12px;white-space:pre-wrap;max-height:100px;overflow:hidden;position:relative;}
.post-thread::after{content:'';position:absolute;bottom:0;left:0;right:0;height:28px;background:linear-gradient(transparent,#fff);pointer-events:none;}
.post-thread.expanded{max-height:none;}
.post-thread.expanded::after{display:none;}

.insight-block{background:#f0f7ff;border-left:3px solid #2563eb;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:12px;font-size:13px;line-height:1.75;color:#444;white-space:pre-line;}
.insight-label{font-size:11px;color:#2563eb;font-weight:700;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;}

.x-link{display:inline-flex;align-items:center;gap:6px;background:#f5f5f5;border:1px solid #e5e7eb;border-radius:8px;padding:6px 12px;text-decoration:none;color:#555;font-size:12px;transition:all .15s;margin-top:4px;}
.x-link:hover{background:#eff6ff;border-color:#2563eb;color:#2563eb;}
.x-link svg{width:13px;height:13px;fill:currentColor;}
.card-links{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.thread-fade{position:absolute;bottom:0;left:0;right:0;height:24px;background:linear-gradient(transparent,#fff);pointer-events:none;}
.expand-btn{background:none;border:none;color:#2563eb;font-size:.75rem;cursor:pointer;padding:2px 0 6px;display:block;}

.no-insight{font-size:12px;color:#aaa;margin-bottom:8px;}

/* 詳細ページ */
.detail-header{padding:24px 20px 16px;border-bottom:1px solid #f0f0f0;}
.detail-meta{font-size:13px;color:#888;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px;}
.detail-body{padding:20px;}
.detail-section-title{font-size:12px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;margin-top:20px;}
.detail-thread{font-size:15px;line-height:1.8;color:#222;white-space:pre-wrap;margin-bottom:8px;}
.detail-insight{background:#f0f7ff;border-left:3px solid #2563eb;border-radius:0 8px 8px 0;padding:14px 16px;font-size:14px;line-height:1.8;color:#444;white-space:pre-line;}
.detail-url-box{background:#f7f7f7;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#555;word-break:break-all;}
.detail-url-box a{color:#2563eb;}

.empty{text-align:center;color:#bbb;padding:80px 20px;font-size:15px;}
.empty a{color:#2563eb;text-decoration:none;}

/* 管理者フォーム */
.admin-form{background:#f0f7ff;border-bottom:2px solid #2563eb;padding:12px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.admin-form-label{font-size:12px;color:#2563eb;font-weight:700;white-space:nowrap;}
.admin-form input[type=text]{flex:1;min-width:220px;border:1px solid #bfdbfe;border-radius:6px;padding:7px 12px;font-size:13px;outline:none;}
.admin-form input[type=text]:focus{border-color:#2563eb;}
.admin-register-btn{background:#2563eb;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .15s;}
.admin-register-btn:hover{background:#1d4ed8;}
.admin-register-btn:disabled{background:#bbb;cursor:not-allowed;}
.admin-status{font-size:12px;padding:4px 10px;border-radius:4px;display:none;}
.admin-status.ok{background:#dcfce7;color:#166534;display:inline-block;}
.admin-status.err{background:#fee2e2;color:#991b1b;display:inline-block;}
.admin-status.loading{background:#eff6ff;color:#2563eb;display:inline-block;}
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

<div class="header">
    <div style="font-size:22px">💬</div>
    <?php if ($detail_post): ?>
    <h1><a href="<?php echo h($THIS_FILE); ?>">XView</a></h1>
    <span class="badge">Timeline</span>
    <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
    <?php else: ?>
    <h1>XView</h1>
    <span class="badge">Timeline</span>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($session_user); ?></strong></span>
        <a href="?xv_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?xv_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($detail_post): ?>
<!-- ========== 詳細ページ ========== -->
<div class="container">
    <div class="detail-header">
        <div class="detail-meta">
            <span>@<?php echo h($detail_post['username']); ?></span>
            <span><?php echo h(isset($detail_post['saved_at']) ? $detail_post['saved_at'] : ''); ?></span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#ccc;"><?php echo h($detail_post['tweet_id']); ?></span>
        </div>
        <?php if (!empty($detail_post['tweet_url'])): ?>
        <div class="detail-url-box">
            元の投稿: <a href="<?php echo h($detail_post['tweet_url']); ?>" target="_blank" rel="noopener"><?php echo h($detail_post['tweet_url']); ?></a>
        </div>
        <?php endif; ?>
    </div>
    <div class="detail-body">
        <div class="detail-section-title">投稿内容</div>
        <div class="detail-thread"><?php echo h(isset($detail_post['thread_text']) ? $detail_post['thread_text'] : ''); ?></div>

        <?php if (!empty($detail_post['insight'])): ?>
        <div class="detail-section-title">AI分析</div>
        <div class="detail-insight"><?php echo h($detail_post['insight']); ?></div>
        <?php endif; ?>

        <?php if (!empty($detail_post['tweet_url'])): ?>
        <?php
            $has_insight = !empty($detail_post['insight']);
            $has_story   = !empty($detail_post['story']);
            $has_lyrics  = !empty($detail_post['lyrics']);
            $istyle  = $has_insight ? 'color:#2563eb;border-color:#bfdbfe;background:#eff6ff;' : 'color:#94a3b8;border-color:#e2e8f0;background:#f8fafc;';
            $ilabel  = $has_insight ? '💬 XInsight' : '💬 XInsight ＋';
            $sstyle  = $has_story   ? 'color:#7c3aed;border-color:#ddd6fe;background:#f5f3ff;' : 'color:#94a3b8;border-color:#e2e8f0;background:#f8fafc;';
            $slabel  = $has_story   ? '📖 UStory'   : '📖 UStory ＋';
            $lystyle = $has_lyrics  ? 'color:#be185d;border-color:#fbcfe8;background:#fdf2f8;' : 'color:#94a3b8;border-color:#e2e8f0;background:#f8fafc;';
            $lylabel = $has_lyrics  ? '🎵 USong'    : '🎵 USong ＋';
        ?>
        <div style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button id="copy-btn" onclick="copyDetail()" style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:14px;cursor:pointer;">📋 コピー</button>
            <a href="<?php echo h($detail_post['tweet_url']); ?>" target="_blank" rel="noopener" class="x-link">
                <svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                元の投稿を開く
            </a>
            <a href="xinsight.php?tweet_url=<?php echo urlencode($detail_post['tweet_url']); ?>" class="x-link" style="<?php echo $istyle; ?>"><?php echo $ilabel; ?></a>
            <a href="ustory.php?tweet_url=<?php echo urlencode($detail_post['tweet_url']); ?>" class="x-link" style="<?php echo $sstyle; ?>"><?php echo $slabel; ?></a>
            <a href="usong.php?tweet_url=<?php echo urlencode($detail_post['tweet_url']); ?>" class="x-link" style="<?php echo $lystyle; ?>"><?php echo $lylabel; ?></a>
        </div>
        <script>
        var _dp = <?php echo json_encode($detail_post, JSON_UNESCAPED_UNICODE); ?>;
        var _dpUrl = '<?php echo $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['tweet_id']); ?>';
        function copyDetail() {
            var lines = [];
            if (_dp.thread_text) lines.push(_dp.thread_text);
            if (_dp.insight) { lines.push(''); lines.push('【AI考察】'); lines.push(_dp.insight); }
            lines.push('');
            if (_dp.tweet_url) lines.push(_dp.tweet_url);
            lines.push(_dpUrl);
            navigator.clipboard.writeText(lines.join('\n')).then(function() {
                var btn = document.getElementById('copy-btn');
                btn.textContent = '✓ コピー済';
                btn.style.background = '#059669';
                setTimeout(function() { btn.textContent = '📋 コピー'; btn.style.background = '#2563eb'; }, 2000);
            });
        }
        </script>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ========== 一覧ページ ========== -->

<?php if ($is_admin): ?>
<form method="POST" id="form-register" class="admin-form">
    <span class="admin-form-label">🔧 新規登録</span>
    <input type="text" name="register_url" id="admin-input" placeholder="https://x.com/.../status/...">
    <button type="submit" class="admin-register-btn" id="admin-btn">登録</button>
    <?php if ($register_err): ?>
    <span class="admin-status err" style="display:inline-block;"><?php echo h($register_err); ?></span>
    <?php endif; ?>
</form>
<?php endif; ?>

<div class="container">
    <div class="count-bar">
        <?php echo count($posts); ?> 件 — @<?php echo h($session_user); ?>
    </div>

    <div id="post-list"></div>
    <div id="load-sentinel" style="height:1px;"></div>
    <div id="load-indicator" style="display:none;text-align:center;padding:16px;font-size:13px;color:#888;">読み込み中...</div>
</div>
<?php endif; ?>

<script>
var xvPosts = <?php echo json_encode(array_values($posts), JSON_UNESCAPED_UNICODE); ?>;
var PAGE_SIZE = 30;
var currentPage = 0;

function xvEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderXvPosts(from, to) {
    var list = document.getElementById('post-list');
    if (!list) return;
    for (var i = from; i < to && i < xvPosts.length; i++) {
        var p       = xvPosts[i];
        var tid     = p.tweet_id    || '';
        var turl    = p.tweet_url   || '';
        var thread  = p.thread_text || '';
        var insight = p.insight     || '';
        var story   = p.story       || '';
        var saved   = p.saved_at    || '';
        var uname   = p.username    || '';
        var avatar  = uname ? uname.charAt(0).toUpperCase() : 'X';

        var xsvg = '<svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';

        var xlink = turl ? '<a href="' + xvEsc(turl) + '" target="_blank" rel="noopener" class="x-link">' + xsvg + '元の投稿</a>' : '';

        var istyle  = insight ? 'color:#2563eb;border-color:#bfdbfe;background:#eff6ff;' : 'color:#94a3b8;border-color:#e2e8f0;background:#f8fafc;';
        var ilabel  = insight ? '💬 XInsight' : '💬 XInsight ＋';
        var sstyle  = story   ? 'color:#7c3aed;border-color:#ddd6fe;background:#f5f3ff;' : 'color:#94a3b8;border-color:#e2e8f0;background:#f8fafc;';
        var slabel  = story   ? '📖 UStory'   : '📖 UStory ＋';
        var lyrics  = p.lyrics || '';
        var lystyle = lyrics  ? 'color:#be185d;border-color:#fbcfe8;background:#fdf2f8;' : 'color:#94a3b8;border-color:#e2e8f0;background:#f8fafc;';
        var lylabel = lyrics  ? '🎵 USong'    : '🎵 USong ＋';

        var threadHtml = '';
        if (thread) {
            threadHtml = '<div class="post-thread" id="thread-' + xvEsc(tid) + '" style="max-height:60px;overflow:hidden;cursor:pointer;position:relative;" onclick="toggleThread(' + "'" + xvEsc(tid) + "'" + ')">'
                + xvEsc(thread)
                + '<div class="thread-fade" id="fade-' + xvEsc(tid) + '"></div></div>'
                + '<button type="button" class="expand-btn" id="expbtn-' + xvEsc(tid) + '" onclick="toggleThread(' + "'" + xvEsc(tid) + "'" + ')">続きを見る ▼</button>';
        }

        var detailUrl = 'xview.php?id=' + encodeURIComponent(tid);
        var html = '<div class="post-card">'
            + '<div class="post-meta">'
            + '<div class="avatar">' + xvEsc(avatar) + '</div>'
            + '<div><div class="author-name">' + xvEsc(uname) + '</div><div class="author-handle">@' + xvEsc(uname) + '</div></div>'
            + '<div class="post-time">' + xvEsc(saved) + '</div>'
            + '</div>'
            + (tid ? '<a class="post-id" href="' + detailUrl + '">#' + xvEsc(tid) + '</a>' : '')
            + threadHtml
            + '<div class="card-links">'
            + xlink
            + '<a href="' + detailUrl + '" class="x-link" style="color:#0f766e;border-color:#99f6e4;background:#f0fdfa;">🔖 詳細</a>'
            + '<a href="xinsight.php?tweet_url=' + encodeURIComponent(turl) + '" class="x-link" style="' + istyle + '">' + ilabel + '</a>'
            + '<a href="ustory.php?tweet_url=' + encodeURIComponent(turl) + '" class="x-link" style="' + sstyle + '">' + slabel + '</a>'
            + '<a href="usong.php?tweet_url=' + encodeURIComponent(turl) + '" class="x-link" style="' + lystyle + '">' + lylabel + '</a>'
            + '</div></div>';
        list.insertAdjacentHTML('beforeend', html);
    }
    currentPage++;
}

function loadMoreXv() {
    var from = currentPage * PAGE_SIZE;
    if (from >= xvPosts.length) {
        document.getElementById('load-indicator').style.display = 'none';
        return;
    }
    renderXvPosts(from, from + PAGE_SIZE);
}

var sentinel = document.getElementById('load-sentinel');
if (sentinel) {
    var observer = new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) {
            document.getElementById('load-indicator').style.display = 'block';
            setTimeout(function() {
                loadMoreXv();
                if (currentPage * PAGE_SIZE >= xvPosts.length) {
                    document.getElementById('load-indicator').style.display = 'none';
                }
            }, 200);
        }
    }, { rootMargin: '200px' });
    observer.observe(sentinel);
}

if (xvPosts.length === 0) {
    var pl = document.getElementById('post-list');
    if (pl) { pl.innerHTML = '<div class="empty">まだ保存された投稿がありません。<br><br><a href="xinsight.php">XInsightで投稿を保存する →</a></div>'; }
} else {
    loadMoreXv();
}

function toggleThread(id) {
    var el   = document.getElementById('thread-' + id);
    var fade = document.getElementById('fade-' + id);
    var btn  = document.getElementById('expbtn-' + id);
    if (!el) return;
    if (el.style.maxHeight === 'none' || el.style.maxHeight === '') {
        el.style.maxHeight = '60px';
        if (fade) { fade.style.display = ''; }
        if (btn)  { btn.textContent = '続きを見る ▼'; }
    } else {
        el.style.maxHeight = 'none';
        if (fade) { fade.style.display = 'none'; }
        if (btn)  { btn.textContent = '閉じる ▲'; }
    }
}

document.getElementById('form-register') && document.getElementById('form-register').addEventListener('submit', function() {
    var btn = document.getElementById('admin-btn');
    btn.disabled = true;
    btn.textContent = '取得中...';
});
</script>
</body>
</html>