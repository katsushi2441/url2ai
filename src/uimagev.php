<?php
date_default_timezone_set("Asia/Tokyo");
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', 'aiknowledgecms.exbridge.jp');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
}

$DATA_DIR = __DIR__ . '/data';
$BASE_URL = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE = 'uimagev.php';
$SITE_NAME = 'UImageV';
$ADMIN = 'xb_bittensor';

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

function uiv_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function uiv_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return uiv_base64url($bytes);
}
function uiv_gen_challenge($verifier) {
    return uiv_base64url(hash('sha256', $verifier, true));
}
function uiv_x_post($url, $post_data, $headers) {
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
function uiv_x_get($url, $token) {
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "Authorization: Bearer $token\r\nUser-Agent: UImageV/1.0\r\n",
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}
if (isset($_GET['uiv_logout'])) {
    session_destroy();
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['uiv_login'])) {
    $verifier = uiv_gen_verifier();
    $challenge = uiv_gen_challenge($verifier);
    $state = md5(uniqid('', true));
    $_SESSION['uiv_code_verifier'] = $verifier;
    $_SESSION['uiv_oauth_state'] = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['uiv_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['uiv_oauth_state']) {
        $post = http_build_query(array(
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $x_redirect_uri,
            'code_verifier' => $_SESSION['uiv_code_verifier'],
            'client_id' => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = uiv_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token'] = $data['access_token'];
            $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int) $data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['uiv_oauth_state'], $_SESSION['uiv_code_verifier']);
            $me = uiv_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
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
    $ref = uiv_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin = ($session_user === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$posts = array();
if (file_exists($DATA_DIR)) {
    $files = glob($DATA_DIR . '/xinsight_*.json');
    if ($files) {
        foreach ($files as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d)) { continue; }
            if (empty($d['uimage_path'])) { continue; }
            $posts[] = $d;
        }
        usort($posts, function($a, $b) {
            $ta = isset($a['uimage_saved_at']) ? $a['uimage_saved_at'] : '';
            $tb = isset($b['uimage_saved_at']) ? $b['uimage_saved_at'] : '';
            return strcmp($tb, $ta);
        });
    }
}

if (isset($_GET['feed'])) {
    $rss_items = array_slice($posts, 0, 20);
    header('Access-Control-Allow-Origin: https://exbridge.jp');
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    echo '<channel>' . "\n";
    echo '<title>AI画像生成タイムライン | UImageV</title>' . "\n";
    echo '<link>' . $BASE_URL . '/uimagev.php</link>' . "\n";
    echo '<description>X投稿から生成したAI画像のタイムライン。</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . $BASE_URL . '/uimagev.php?feed" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rss_items as $p) {
        $tweet_id = isset($p['tweet_id']) ? $p['tweet_id'] : '';
        $thread = isset($p['thread_text']) ? $p['thread_text'] : '';
        $date_raw = isset($p['uimage_saved_at']) ? $p['uimage_saved_at'] : '';
        $uname = isset($p['username']) ? $p['username'] : '';
        $title = mb_substr(str_replace("\n", ' ', $thread), 0, 50) . '...';
        $desc = mb_substr(str_replace("\n", ' ', $thread), 0, 200);
        $link = $BASE_URL . '/uimagev.php?id=' . urlencode($tweet_id);
        $pub_date = $date_raw ? date('r', strtotime($date_raw)) : date('r');
        echo '<item>' . "\n";
        echo '<title><![CDATA[' . $title . ']]></title>' . "\n";
        echo '<link>' . htmlspecialchars($link) . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . htmlspecialchars($link) . '</guid>' . "\n";
        echo '<description><![CDATA[' . $desc . ($uname ? "\n\n@" . $uname : '') . ']]></description>' . "\n";
        echo '<pubDate>' . $pub_date . '</pubDate>' . "\n";
        if (!empty($p['uimage_path'])) {
            echo '<enclosure url="' . htmlspecialchars($BASE_URL . '/' . ltrim($p['uimage_path'], '/')) . '" length="0" type="image/png"/>' . "\n";
        }
        echo '</item>' . "\n";
    }
    echo '</channel>' . "\n";
    echo '</rss>' . "\n";
    exit;
}

$detail_id = isset($_GET['id']) ? trim($_GET['id']) : '';
$detail_post = null;
if ($detail_id !== '') {
    foreach ($posts as $p) {
        if (isset($p['tweet_id']) && $p['tweet_id'] === $detail_id) {
            $detail_post = $p;
            break;
        }
    }
}

if ($detail_post) {
    $page_title = 'UImage | ' . $detail_post['tweet_id'];
    $page_description = mb_substr(str_replace("\n", ' ', isset($detail_post['thread_text']) ? $detail_post['thread_text'] : ''), 0, 160);
    $page_url = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['tweet_id']);
} else {
    $page_title = $SITE_NAME . ' — X投稿から生成した画像ビュー';
    $page_description = 'X投稿URLをもとに生成した画像を公開表示するビューアです。';
    $page_url = $BASE_URL . '/' . $THIS_FILE;
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($page_description); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h($page_description); ?>">
<meta property="og:url" content="<?php echo h($page_url); ?>">
<meta property="og:site_name" content="<?php echo h($SITE_NAME); ?>">
<meta property="og:image" content="<?php echo h($BASE_URL); ?>/images/uimage.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?php echo h($BASE_URL); ?>/images/uimage.png">
<meta name="twitter:site" content="@xb_bittensor">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#fff;color:#222;font-family:-apple-system,'Helvetica Neue',sans-serif;font-size:14px}
.header{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 20px;position:sticky;top:0;z-index:100;display:flex;align-items:center;gap:12px}
.header h1{font-size:17px;font-weight:700;color:#111}
.header h1 a{text-decoration:none;color:inherit}
.badge{background:#ec4899;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px}
.back-btn{margin-left:auto;font-size:13px;color:#ec4899;text-decoration:none;padding:5px 12px;border:1px solid #ec4899;border-radius:6px}
.back-btn:hover{background:#fdf2f8}
.userbar{margin-left:auto;display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:#64748b}
.userbar strong{color:#059669}
.btn-sm{background:none;border:1px solid #cbd5e1;color:#64748b;padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none}
.btn-sm:hover{border-color:#dc2626;color:#dc2626}
.container{max-width:840px;margin:0 auto;padding:0 0 80px}
.count-bar{padding:10px 20px;font-size:13px;color:#888;border-bottom:1px solid #f0f0f0}
.post-card{border-bottom:1px solid #f0f0f0;padding:20px;transition:background .15s}
.post-card:hover{background:#fafafa}
.post-meta{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.avatar{width:40px;height:40px;background:linear-gradient(135deg,#ec4899,#f97316);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0}
.author-name{font-weight:700;color:#111;font-size:14px}
.author-handle{color:#888;font-size:13px}
.post-time{color:#aaa;font-size:12px;margin-left:auto}
.post-id{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;color:#ec4899;margin-bottom:8px;text-decoration:none;display:block}
.post-id:hover{text-decoration:underline}
.preview-image{display:block;width:100%;border-radius:12px;border:1px solid #e5e7eb;margin-bottom:12px}
.x-link{display:inline-flex;align-items:center;gap:6px;background:#f5f5f5;border:1px solid #e5e7eb;border-radius:8px;padding:6px 12px;text-decoration:none;color:#555;font-size:12px;transition:all .15s;margin-top:4px}
.x-link:hover{background:#fdf2f8;border-color:#ec4899;color:#ec4899}
.card-links{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.detail-header{padding:24px 20px 16px;border-bottom:1px solid #f0f0f0}
.detail-meta{font-size:13px;color:#888;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px}
.detail-body{padding:20px}
.detail-section-title{font-size:12px;font-weight:700;color:#ec4899;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;margin-top:20px}
.detail-prompt{background:#fff7fb;border-left:3px solid #ec4899;border-radius:0 8px 8px 0;padding:16px 18px;font-size:14px;line-height:1.9;color:#222;white-space:pre-wrap;margin-bottom:8px}
.detail-thread{font-size:14px;line-height:1.8;color:#555;white-space:pre-wrap;margin-bottom:8px}
.detail-image{display:block;width:100%;border-radius:14px;border:1px solid #e5e7eb;box-shadow:0 10px 30px rgba(15,23,42,.08)}
.detail-url-box{background:#f7f7f7;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#555;word-break:break-all}
.detail-url-box a{color:#ec4899}
.empty{text-align:center;color:#bbb;padding:80px 20px;font-size:15px}
.empty a{color:#ec4899;text-decoration:none}
.rss-badge{font-size:10px;font-weight:700;color:#c44f00;background:#fff5ef;border:1px solid #f5d0b8;border-radius:4px;padding:2px 7px;text-decoration:none;display:inline-flex;align-items:center;gap:3px}
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
    <div style="font-size:22px">🎨</div>
    <?php if ($detail_post): ?>
    <h1><a href="<?php echo h($THIS_FILE); ?>">UImageV</a></h1>
    <span class="badge">URL2AI Images</span>
    <a class="rss-badge" href="<?php echo h($THIS_FILE); ?>?feed" target="_blank">
        <svg width="9" height="9" viewBox="0 0 8 8"><circle cx="1.5" cy="6.5" r="1.5" fill="#c44f00"/><path d="M0 4.5A3.5 3.5 0 013.5 8" stroke="#c44f00" stroke-width="1.2" fill="none"/><path d="M0 2A6 6 0 016 8" stroke="#c44f00" stroke-width="1.2" fill="none"/></svg>
        RSS
    </a>
    <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
    <?php else: ?>
    <h1>UImageV</h1>
    <span class="badge">URL2AI Images</span>
    <div class="userbar">
        <a class="rss-badge" href="<?php echo h($THIS_FILE); ?>?feed" target="_blank">
            <svg width="9" height="9" viewBox="0 0 8 8"><circle cx="1.5" cy="6.5" r="1.5" fill="#c44f00"/><path d="M0 4.5A3.5 3.5 0 013.5 8" stroke="#c44f00" stroke-width="1.2" fill="none"/><path d="M0 2A6 6 0 016 8" stroke="#c44f00" stroke-width="1.2" fill="none"/></svg>
            RSS
        </a>
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($session_user); ?></strong></span>
        <a href="?uiv_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?uiv_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($detail_post): ?>
<div class="container">
    <div class="detail-header">
        <div class="detail-meta">
            <span>@<?php echo h(isset($detail_post['username']) ? $detail_post['username'] : ''); ?></span>
            <span><?php echo h(isset($detail_post['uimage_saved_at']) ? $detail_post['uimage_saved_at'] : ''); ?></span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#ccc;"><?php echo h($detail_post['tweet_id']); ?></span>
        </div>
        <?php if (!empty($detail_post['tweet_url'])): ?>
        <div class="detail-url-box">
            元の投稿: <a href="<?php echo h($detail_post['tweet_url']); ?>" target="_blank" rel="noopener"><?php echo h($detail_post['tweet_url']); ?></a>
        </div>
        <?php endif; ?>
    </div>
    <div class="detail-body">
        <div class="detail-section-title">🎨 Generated Image</div>
        <img class="detail-image" src="<?php echo h($BASE_URL . '/' . $detail_post['uimage_path']); ?>" alt="Generated image">

        <?php if ($logged_in): ?>
        <div style="margin-top:14px">
            <a class="x-link" href="<?php echo h($BASE_URL . '/' . $detail_post['uimage_path']); ?>" download>画像を保存</a>
        </div>
        <?php endif; ?>

        <?php if (!empty($detail_post['thread_text'])): ?>
        <div class="detail-section-title">元のスレッド</div>
        <div class="detail-thread"><?php echo h($detail_post['thread_text']); ?></div>
        <?php endif; ?>

        <div style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php if (!empty($detail_post['tweet_url'])): ?>
            <a href="<?php echo h($detail_post['tweet_url']); ?>" target="_blank" rel="noopener" class="x-link">元の投稿を開く</a>
            <a href="uimage.php?tweet_url=<?php echo urlencode($detail_post['tweet_url']); ?>" class="x-link" style="color:#ec4899;border-color:#fbcfe8;background:#fdf2f8;">✏️ 再生成</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="container">
    <div class="count-bar">
        <?php echo count($posts); ?> 件の生成画像
        <?php if ($logged_in): ?> — @<?php echo h($session_user); ?><?php endif; ?>
    </div>
    <?php if (empty($posts)): ?>
    <div class="empty">まだ画像がありません。<br><br><a href="uimage.php">UImageで画像を生成する →</a></div>
    <?php else: ?>
    <?php foreach ($posts as $p): ?>
    <div class="post-card">
        <div class="post-meta">
            <div class="avatar"><?php echo h(substr(isset($p['username']) ? $p['username'] : 'U', 0, 1)); ?></div>
            <div>
                <div class="author-name"><?php echo h(isset($p['username']) ? $p['username'] : ''); ?></div>
                <div class="author-handle">@<?php echo h(isset($p['username']) ? $p['username'] : ''); ?></div>
            </div>
            <div class="post-time"><?php echo h(isset($p['uimage_saved_at']) ? $p['uimage_saved_at'] : ''); ?></div>
        </div>
        <a class="post-id" href="uimagev.php?id=<?php echo urlencode($p['tweet_id']); ?>">#<?php echo h($p['tweet_id']); ?></a>
        <a href="uimagev.php?id=<?php echo urlencode($p['tweet_id']); ?>"><img class="preview-image" src="<?php echo h($BASE_URL . '/' . $p['uimage_path']); ?>" alt="Generated image"></a>
        <div class="card-links">
            <?php if (!empty($p['tweet_url'])): ?>
            <a href="<?php echo h($p['tweet_url']); ?>" target="_blank" rel="noopener" class="x-link">元の投稿</a>
            <a href="uimage.php?tweet_url=<?php echo urlencode($p['tweet_url']); ?>" class="x-link">✏️ 再生成</a>
            <?php endif; ?>
            <a href="uimagev.php?id=<?php echo urlencode($p['tweet_id']); ?>" class="x-link" style="color:#ec4899;border-color:#fbcfe8;background:#fdf2f8;">🎨 詳細</a>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
