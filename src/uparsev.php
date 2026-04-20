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
$THIS_FILE = 'uparsev.php';
$SITE_NAME = 'UParseV';
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
function pv_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function pv_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return pv_base64url($bytes);
}
function pv_gen_challenge($verifier) {
    return pv_base64url(hash('sha256', $verifier, true));
}
function pv_x_post($url, $post_data, $headers) {
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
function pv_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: UParseV/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['pv_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/',
        'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['pv_login'])) {
    $verifier  = pv_gen_verifier();
    $challenge = pv_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['pv_code_verifier'] = $verifier;
    $_SESSION['pv_oauth_state']   = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['pv_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['pv_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['pv_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = pv_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']  = $data['access_token'];
            $_SESSION['session_token_expires']  = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['pv_oauth_state'], $_SESSION['pv_code_verifier']);
            $me = pv_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
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
    $ref = pv_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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

$logged_in    = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin     = ($session_user === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function pv_format_parse_html($text) {
    $display = h($text);
    $display = preg_replace('/^(■\s*構文：.+)$/mu', '<strong class="parse-heading">$1</strong>', $display);
    $display = preg_replace('/^(■\s*意味・機能：.+)$/mu', '<strong class="parse-heading parse-meaning">$1</strong>', $display);
    $display = preg_replace('/^(■\s*例文：.+)$/mu', '<strong class="parse-heading">$1</strong>', $display);
    return $display;
}

/* =========================================================
   RSS フィード出力 (?feed)
========================================================= */
if (isset($_GET['feed'])) {
    $rss_posts = array();
    if (file_exists($DATA_DIR)) {
        $files = glob($DATA_DIR . '/xinsight_*.json');
        if ($files) {
            foreach ($files as $f) {
                $d = json_decode(file_get_contents($f), true);
                if (!is_array($d) || empty($d['parse_result'])) { continue; }
                $rss_posts[] = $d;
            }
            usort($rss_posts, function($a, $b) {
                $ta = isset($a['saved_at']) ? $a['saved_at'] : '';
                $tb = isset($b['saved_at']) ? $b['saved_at'] : '';
                return strcmp($tb, $ta);
            });
        }
    }
    $rss_items = array_slice($rss_posts, 0, 20);
    header('Access-Control-Allow-Origin: https://exbridge.jp');
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    echo '<channel>' . "\n";
    echo '<title>構文解析タイムライン | ' . $SITE_NAME . '</title>' . "\n";
    echo '<link>' . $BASE_URL . '/' . $THIS_FILE . '</link>' . "\n";
    echo '<description>X投稿の文法構文を解析し、例文を自動生成したタイムライン。</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . $BASE_URL . '/' . $THIS_FILE . '?feed" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rss_items as $p) {
        $tweet_id = isset($p['tweet_id'])     ? $p['tweet_id']     : '';
        $parse    = isset($p['parse_result']) ? $p['parse_result'] : '';
        $thread   = isset($p['thread_text'])  ? $p['thread_text']  : '';
        $date_raw = isset($p['saved_at'])     ? $p['saved_at']     : '';
        $uname    = isset($p['username'])     ? $p['username']     : '';
        $seo_text = $parse !== '' ? $parse : $thread;
        $title    = mb_substr(str_replace("\n", ' ', $seo_text), 0, 50) . '...';
        $desc     = mb_substr(str_replace("\n", ' ', $seo_text), 0, 200);
        $link     = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($tweet_id);
        $pub_date = $date_raw ? date('r', strtotime($date_raw)) : date('r');
        echo '<item>' . "\n";
        echo '<title><![CDATA[' . $title . ']]></title>' . "\n";
        echo '<link>' . htmlspecialchars($link) . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . htmlspecialchars($link) . '</guid>' . "\n";
        echo '<description><![CDATA[' . $desc . ($uname ? "\n\n@" . $uname : '') . ']]></description>' . "\n";
        echo '<pubDate>' . $pub_date . '</pubDate>' . "\n";
        echo '</item>' . "\n";
    }
    echo '</channel>' . "\n";
    echo '</rss>' . "\n";
    exit;
}

/* =========================================================
   データ読み込み（parse_result が存在するものだけ）
========================================================= */
$posts = array();
if (file_exists($DATA_DIR)) {
    $files = glob($DATA_DIR . '/xinsight_*.json');
    if ($files) {
        foreach ($files as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d)) { continue; }
            if (empty($d['parse_result'])) { continue; }
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
    $parse_raw        = isset($detail_post['parse_result']) ? $detail_post['parse_result'] : '';
    $page_title       = mb_substr($parse_raw, 0, 50) . '... | ' . $SITE_NAME;
    $page_description = mb_substr(str_replace("\n", ' ', $parse_raw), 0, 160);
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['tweet_id']);
    $page_type        = 'article';
    $published_time   = isset($detail_post['saved_at']) ? $detail_post['saved_at'] : '';
    $jsonld = array(
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'headline'      => mb_substr($parse_raw, 0, 50),
        'description'   => $page_description,
        'url'           => $page_url,
        'datePublished' => $published_time,
        'author'        => array('@type' => 'Person', 'name' => isset($detail_post['username']) ? $detail_post['username'] : 'xb_bittensor'),
        'publisher'     => array('@type' => 'Organization', 'name' => $SITE_NAME),
    );
} else {
    $page_title       = $SITE_NAME . ' — 構文解析＆例文タイムライン';
    $page_description = 'X投稿の文法構文を解析し、例文を自動生成したタイムライン。';
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
<meta property="og:image" content="<?php echo $BASE_URL; ?>/images/uparse.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?php echo $BASE_URL; ?>/images/uparse.png">
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
.badge{background:#0891b2;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;}
.back-btn{margin-left:auto;font-size:13px;color:#0891b2;text-decoration:none;padding:5px 12px;border:1px solid #0891b2;border-radius:6px;}
.back-btn:hover{background:#f0f9ff;}

/* ログイン */
.userbar{margin-left:auto;display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:#64748b;}
.userbar strong{color:#059669;}
.btn-sm{background:none;border:1px solid #cbd5e1;color:#64748b;padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;}
.btn-sm:hover{border-color:#dc2626;color:#dc2626;}

/* タイムライン */
.container{max-width:640px;margin:0 auto;padding:0 0 80px;}
.count-bar{padding:10px 20px;font-size:13px;color:#888;border-bottom:1px solid #f0f0f0;}

/* カード */
.post-card{border-bottom:1px solid #f0f0f0;padding:20px;transition:background .15s;}
.post-card:hover{background:#fafafa;}
.post-meta{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.avatar{width:40px;height:40px;background:linear-gradient(135deg,#0891b2,#0e7490);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0;}
.author-name{font-weight:700;color:#111;font-size:14px;}
.author-handle{color:#888;font-size:13px;}
.post-time{color:#aaa;font-size:12px;margin-left:auto;}

.post-id{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;color:#0891b2;margin-bottom:8px;text-decoration:none;display:block;}
.post-id:hover{text-decoration:underline;}

/* 構文解析ブロック（折りたたみ） */
.parse-block{background:#f0f9ff;border-left:3px solid #0891b2;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:12px;font-size:13px;line-height:1.9;color:#333;white-space:pre-wrap;max-height:100px;overflow:hidden;position:relative;cursor:pointer;}
.parse-block strong{display:block;margin:.15rem 0;color:#0369a1;font-weight:700;}
.parse-block .parse-meaning{color:#0f766e;}
.parse-block::after{content:'';position:absolute;bottom:0;left:0;right:0;height:28px;background:linear-gradient(transparent,#f0f9ff);pointer-events:none;}
.parse-block.expanded{max-height:none;}
.parse-block.expanded::after{display:none;}
.expand-btn{background:none;border:none;color:#0891b2;font-size:.75rem;cursor:pointer;padding:2px 0 6px;display:block;}

/* スレッドプレビュー */
.thread-block{font-size:13px;line-height:1.75;color:#888;white-space:pre-wrap;max-height:50px;overflow:hidden;position:relative;margin-bottom:8px;}
.thread-block::after{content:'';position:absolute;bottom:0;left:0;right:0;height:20px;background:linear-gradient(transparent,#fff);pointer-events:none;}

/* リンク群 */
.x-link{display:inline-flex;align-items:center;gap:6px;background:#f5f5f5;border:1px solid #e5e7eb;border-radius:8px;padding:6px 12px;text-decoration:none;color:#555;font-size:12px;transition:all .15s;margin-top:4px;}
.x-link:hover{background:#f0f9ff;border-color:#0891b2;color:#0891b2;}
.x-link svg{width:13px;height:13px;fill:currentColor;}
.card-links{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}

/* 詳細ページ */
.detail-header{padding:24px 20px 16px;border-bottom:1px solid #f0f0f0;}
.detail-meta{font-size:13px;color:#888;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px;}
.detail-body{padding:20px;}
.detail-section-title{font-size:12px;font-weight:700;color:#0891b2;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;margin-top:20px;}
.detail-thread{font-size:14px;line-height:1.8;color:#555;white-space:pre-wrap;margin-bottom:8px;}
.detail-parse{background:#f0f9ff;border-left:3px solid #0891b2;border-radius:0 8px 8px 0;padding:14px 16px;font-size:14px;line-height:1.9;color:#222;white-space:pre-wrap;margin-bottom:8px;}
.detail-parse strong{display:block;margin:.2rem 0;color:#0891b2;font-weight:700;}
.detail-parse .parse-meaning{color:#0f766e;}
.detail-url-box{background:#f7f7f7;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#555;word-break:break-all;}
.detail-url-box a{color:#0891b2;}

.empty{text-align:center;color:#bbb;padding:80px 20px;font-size:15px;}
.empty a{color:#0891b2;text-decoration:none;}
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
    <div style="font-size:22px">🔍</div>
    <?php if ($detail_post): ?>
    <h1><a href="<?php echo h($THIS_FILE); ?>">UParseV</a></h1>
    <span class="badge">Parse</span>
    <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
    <?php else: ?>
    <h1>UParseV</h1>
    <span class="badge">Parse</span>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($session_user); ?></strong></span>
        <a href="?pv_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?pv_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($detail_post): ?>
<!-- ========== 詳細ページ ========== -->
<div class="container">
    <div class="detail-header">
        <div class="detail-meta">
            <span>@<?php echo h(isset($detail_post['username']) ? $detail_post['username'] : ''); ?></span>
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

        <div class="detail-section-title">🔍 構文解析＆例文</div>
        <div class="detail-parse"><?php
            echo pv_format_parse_html(isset($detail_post['parse_result']) ? $detail_post['parse_result'] : '');
        ?></div>

        <?php if (!empty($detail_post['thread_text'])): ?>
        <div class="detail-section-title">元のスレッド</div>
        <div class="detail-thread"><?php echo h($detail_post['thread_text']); ?></div>
        <?php endif; ?>

        <?php if (!empty($detail_post['tweet_url'])): ?>
        <?php
            $has_story  = !empty($detail_post['story']);
            $sstyle = $has_story ? 'color:#7c3aed;border-color:#ddd6fe;background:#f5f3ff;' : 'color:#94a3b8;border-color:#e2e8f0;background:#f8fafc;';
            $slabel = $has_story ? '📖 UStory' : '📖 UStory ＋';
        ?>
        <div style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button id="copy-btn" onclick="copyDetail()" style="background:#0891b2;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:14px;cursor:pointer;">📋 コピー</button>
            <a href="<?php echo h($detail_post['tweet_url']); ?>" target="_blank" rel="noopener" class="x-link">
                <svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                元の投稿を開く
            </a>
            <a href="uparse.php?tweet_url=<?php echo urlencode($detail_post['tweet_url']); ?>" class="x-link" style="color:#0891b2;border-color:#bae6fd;background:#f0f9ff;">✏️ 再生成</a>
            <a href="ustory.php?tweet_url=<?php echo urlencode($detail_post['tweet_url']); ?>" class="x-link" style="<?php echo $sstyle; ?>"><?php echo $slabel; ?></a>
        </div>
        <script>
        var _dp = <?php echo json_encode($detail_post, JSON_UNESCAPED_UNICODE); ?>;
        var _dpUrl = '<?php echo $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['tweet_id']); ?>';
        function copyDetail() {
            var lines = [];
            if (_dp.parse_result) { lines.push('#UParse'); lines.push(_dp.parse_result); }
            lines.push('');
            if (_dp.tweet_url) lines.push(_dp.tweet_url);
            lines.push(_dpUrl);
            navigator.clipboard.writeText(lines.join('\n')).then(function() {
                var btn = document.getElementById('copy-btn');
                btn.textContent = '✓ コピー済';
                btn.style.background = '#059669';
                setTimeout(function() { btn.textContent = '📋 コピー'; btn.style.background = '#0891b2'; }, 2000);
            });
        }
        </script>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ========== 一覧ページ ========== -->

<div class="container">
    <div class="count-bar">
        <?php echo count($posts); ?> 件の構文解析
        <?php if ($logged_in): ?> — @<?php echo h($session_user); ?><?php endif; ?>
    </div>

    <div id="post-list"></div>
    <div id="load-sentinel" style="height:1px;"></div>
    <div id="load-indicator" style="display:none;text-align:center;padding:16px;font-size:13px;color:#888;">読み込み中...</div>
</div>
<?php endif; ?>

<script>
var pvPosts = <?php echo json_encode(array_values($posts), JSON_UNESCAPED_UNICODE); ?>;
var PAGE_SIZE = 30;
var currentPage = 0;

function pvEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function pvFormatParse(s) {
    var html = pvEsc(s || '');
    html = html.replace(/^(■\s*構文：.+)$/gm, '<strong class="parse-heading">$1</strong>');
    html = html.replace(/^(■\s*意味・機能：.+)$/gm, '<strong class="parse-heading parse-meaning">$1</strong>');
    html = html.replace(/^(■\s*例文：.+)$/gm, '<strong class="parse-heading">$1</strong>');
    return html;
}

function renderPvPosts(from, to) {
    var list = document.getElementById('post-list');
    if (!list) return;
    for (var i = from; i < to && i < pvPosts.length; i++) {
        var p      = pvPosts[i];
        var tid    = p.tweet_id     || '';
        var turl   = p.tweet_url    || '';
        var thread = p.thread_text  || '';
        var parse  = p.parse_result || '';
        var saved  = p.saved_at     || '';
        var uname  = p.username     || '';
        var avatar = uname ? uname.charAt(0).toUpperCase() : 'P';

        var xsvg = '<svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';
        var xlink = turl ? '<a href="' + pvEsc(turl) + '" target="_blank" rel="noopener" class="x-link">' + xsvg + '元の投稿</a>' : '';

        /* 構文解析ブロック（折りたたみ） */
        var parseHtml = '';
        if (parse) {
            parseHtml = '<div class="parse-block" id="parse-' + pvEsc(tid) + '" onclick="toggleParse(\'' + pvEsc(tid) + '\')">'
                + pvFormatParse(parse)
                + '</div>'
                + '<button type="button" class="expand-btn" id="expbtn-' + pvEsc(tid) + '" onclick="toggleParse(\'' + pvEsc(tid) + '\')">続きを見る ▼</button>';
        }

        /* スレッド */
        var threadHtml = '';
        if (thread) {
            threadHtml = '<div class="thread-block">' + pvEsc(thread) + '</div>';
        }

        var detailUrl = 'uparsev.php?id=' + encodeURIComponent(tid);
        var html = '<div class="post-card">'
            + '<div class="post-meta">'
            + '<div class="avatar">' + pvEsc(avatar) + '</div>'
            + '<div><div class="author-name">' + pvEsc(uname) + '</div><div class="author-handle">@' + pvEsc(uname) + '</div></div>'
            + '<div class="post-time">' + pvEsc(saved) + '</div>'
            + '</div>'
            + (tid ? '<a class="post-id" href="' + detailUrl + '">#' + pvEsc(tid) + '</a>' : '')
            + parseHtml
            + threadHtml
            + '<div class="card-links">'
            + xlink
            + '<a href="' + detailUrl + '" class="x-link" style="color:#0891b2;border-color:#bae6fd;background:#f0f9ff;">🔍 詳細</a>'
            + '<a href="uparse.php?tweet_url=' + encodeURIComponent(turl) + '" class="x-link" style="color:#0891b2;border-color:#bae6fd;background:#f0f9ff;">✏️ 再生成</a>'
            + '</div></div>';
        list.insertAdjacentHTML('beforeend', html);
    }
    currentPage++;
}

function loadMorePv() {
    var from = currentPage * PAGE_SIZE;
    if (from >= pvPosts.length) {
        document.getElementById('load-indicator').style.display = 'none';
        return;
    }
    renderPvPosts(from, from + PAGE_SIZE);
}

var sentinel = document.getElementById('load-sentinel');
if (sentinel) {
    var observer = new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) {
            document.getElementById('load-indicator').style.display = 'block';
            setTimeout(function() {
                loadMorePv();
                if (currentPage * PAGE_SIZE >= pvPosts.length) {
                    document.getElementById('load-indicator').style.display = 'none';
                }
            }, 200);
        }
    }, { rootMargin: '200px' });
    observer.observe(sentinel);
}

if (pvPosts.length === 0) {
    var pl = document.getElementById('post-list');
    if (pl) { pl.innerHTML = '<div class="empty">まだ構文解析がありません。<br><br><a href="uparse.php">UParseで構文解析する →</a></div>'; }
} else {
    loadMorePv();
}

function toggleParse(id) {
    var el  = document.getElementById('parse-' + id);
    var btn = document.getElementById('expbtn-' + id);
    if (!el) return;
    if (el.classList.contains('expanded')) {
        el.classList.remove('expanded');
        if (btn) { btn.textContent = '続きを見る ▼'; }
    } else {
        el.classList.add('expanded');
        if (btn) { btn.textContent = '閉じる ▲'; }
    }
}
</script>
</body>
</html>
