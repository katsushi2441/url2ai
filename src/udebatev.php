<?php
date_default_timezone_set("Asia/Tokyo");
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$DATA_DIR  = __DIR__ . '/data';
$BASE_URL  = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE = 'udebatev.php';
$SITE_NAME = 'UDebateV';
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
function dv_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function dv_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return dv_base64url($bytes);
}
function dv_gen_challenge($verifier) {
    return dv_base64url(hash('sha256', $verifier, true));
}
function dv_x_post($url, $post_data, $headers) {
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
function dv_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: UDebateV/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['dv_logout'])) {
    session_destroy();
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['dv_login'])) {
    $verifier  = dv_gen_verifier();
    $challenge = dv_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['dv_code_verifier'] = $verifier;
    $_SESSION['dv_oauth_state']   = $state;
    $params = array(
        'response_type'         => 'code',
        'client_id'             => $x_client_id,
        'redirect_uri'          => $x_redirect_uri,
        'scope'                 => 'tweet.read users.read',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    );
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($params));
    exit;
}
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['dv_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['dv_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['dv_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = dv_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token'] = $data['access_token'];
            unset($_SESSION['dv_oauth_state'], $_SESSION['dv_code_verifier']);
            $me = dv_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) {
                $_SESSION['session_username'] = $me['data']['username'];
            }
        }
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

$logged_in    = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin     = ($session_user === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

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
                if (!is_array($d) || empty($d['debate_turns'])) { continue; }
                $rss_posts[] = $d;
            }
            usort($rss_posts, function($a, $b) {
                $ta = isset($a['debate_at']) ? $a['debate_at'] : (isset($a['saved_at']) ? $a['saved_at'] : '');
                $tb = isset($b['debate_at']) ? $b['debate_at'] : (isset($b['saved_at']) ? $b['saved_at'] : '');
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
    echo '<title>AI議論タイムライン | ' . $SITE_NAME . '</title>' . "\n";
    echo '<link>' . $BASE_URL . '/' . $THIS_FILE . '</link>' . "\n";
    echo '<description>X投稿についてAI同士が議論し司会AIがまとめるタイムライン。</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . $BASE_URL . '/' . $THIS_FILE . '?feed" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rss_items as $p) {
        $tweet_id  = isset($p['tweet_id'])           ? $p['tweet_id']           : '';
        $conclusion= isset($p['debate_conclusion'])  ? $p['debate_conclusion']  : '';
        $thread    = isset($p['thread_text'])         ? $p['thread_text']        : '';
        $date_raw  = isset($p['debate_at'])           ? $p['debate_at']          : (isset($p['saved_at']) ? $p['saved_at'] : '');
        $uname     = isset($p['username'])            ? $p['username']           : '';
        $seo_text  = $conclusion !== '' ? $conclusion : $thread;
        $title     = mb_substr(str_replace("\n", ' ', $seo_text), 0, 50) . '...';
        $desc      = mb_substr(str_replace("\n", ' ', $seo_text), 0, 200);
        $link      = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($tweet_id);
        $pub_date  = $date_raw ? date('r', strtotime($date_raw)) : date('r');
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
   データ読み込み（debate_turnsフィールドが存在するものだけ）
========================================================= */
$posts = array();
if (file_exists($DATA_DIR)) {
    $files = glob($DATA_DIR . '/xinsight_*.json');
    if ($files) {
        foreach ($files as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d)) { continue; }
            if (empty($d['debate_turns'])) { continue; }
            $posts[] = $d;
        }
        usort($posts, function($a, $b) {
            $ta = isset($a['debate_at']) ? $a['debate_at'] : (isset($a['saved_at']) ? $a['saved_at'] : '');
            $tb = isset($b['debate_at']) ? $b['debate_at'] : (isset($b['saved_at']) ? $b['saved_at'] : '');
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
    $conclusion_raw   = isset($detail_post['debate_conclusion']) ? $detail_post['debate_conclusion'] : '';
    $thread_raw       = isset($detail_post['thread_text'])       ? $detail_post['thread_text']       : '';
    $seo_text         = $conclusion_raw !== '' ? $conclusion_raw : $thread_raw;
    $page_title       = mb_substr($seo_text, 0, 50) . '... | ' . $SITE_NAME;
    $page_description = mb_substr(str_replace("\n", ' ', $seo_text), 0, 160);
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['tweet_id']);
    $page_type        = 'article';
    $published_time   = isset($detail_post['debate_at']) ? $detail_post['debate_at'] : (isset($detail_post['saved_at']) ? $detail_post['saved_at'] : '');
    $jsonld = array(
        '@context'      => 'https://schema.org',
        '@type'         => 'DiscussionForumPosting',
        'headline'      => mb_substr($seo_text, 0, 50),
        'description'   => $page_description,
        'url'           => $page_url,
        'datePublished' => $published_time,
        'author'        => array('@type' => 'Person', 'name' => isset($detail_post['username']) ? $detail_post['username'] : 'xb_bittensor'),
        'publisher'     => array('@type' => 'Organization', 'name' => $SITE_NAME),
    );
} else {
    $page_title       = $SITE_NAME . ' — AI議論タイムライン';
    $page_description = 'X投稿についてAI同士が議論し司会AIがまとめるタイムライン。';
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
<link rel="alternate" type="application/rss+xml" title="<?php echo h($SITE_NAME); ?> RSS" href="<?php echo h($BASE_URL . '/' . $THIS_FILE . '?feed'); ?>">
<meta property="og:type" content="<?php echo h($page_type); ?>">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h($page_description); ?>">
<meta property="og:url" content="<?php echo h($page_url); ?>">
<meta property="og:site_name" content="<?php echo h($SITE_NAME); ?>">
<meta property="og:locale" content="ja_JP">
<meta property="og:image" content="<?php echo h($BASE_URL); ?>/images/udebate.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php if ($page_type === 'article' && $published_time): ?>
<meta property="article:published_time" content="<?php echo h($published_time); ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@xb_bittensor">
<meta name="twitter:title" content="<?php echo h($page_title); ?>">
<meta name="twitter:description" content="<?php echo h($page_description); ?>">
<meta name="twitter:image" content="<?php echo h($BASE_URL); ?>/images/udebate.png">
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
.logo{font-size:1.1rem;font-weight:700;letter-spacing:-.02em}
.logo span{color:#7c3aed;}
.badge{background:#7c3aed;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;}
.back-btn{margin-left:auto;font-size:13px;color:#7c3aed;text-decoration:none;padding:5px 12px;border:1px solid #7c3aed;border-radius:6px;}
.back-btn:hover{background:#f5f3ff;}

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
.avatar{width:40px;height:40px;background:linear-gradient(135deg,#7c3aed,#2563eb);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0;}
.author-name{font-weight:700;color:#111;font-size:14px;}
.author-handle{color:#888;font-size:13px;}
.post-time{color:#aaa;font-size:12px;margin-left:auto;}

.post-id{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;color:#7c3aed;margin-bottom:8px;text-decoration:none;display:block;}
.post-id:hover{text-decoration:underline;}

/* 司会まとめブロック（一覧） */
.conclusion-block{background:#fefce8;border-left:3px solid #f59e0b;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:12px;font-size:14px;line-height:1.9;color:#333;white-space:pre-wrap;max-height:100px;overflow:hidden;position:relative;cursor:pointer;}
.conclusion-block::after{content:'';position:absolute;bottom:0;left:0;right:0;height:28px;background:linear-gradient(transparent,#fefce8);pointer-events:none;}
.conclusion-block.expanded{max-height:none;}
.conclusion-block.expanded::after{display:none;}
.expand-btn{background:none;border:none;color:#d97706;font-size:.75rem;cursor:pointer;padding:2px 0 6px;display:block;}

/* スレッドプレビュー */
.thread-block{font-size:13px;line-height:1.75;color:#888;white-space:pre-wrap;max-height:50px;overflow:hidden;position:relative;margin-bottom:8px;}
.thread-block::after{content:'';position:absolute;bottom:0;left:0;right:0;height:20px;background:linear-gradient(transparent,#fff);pointer-events:none;}

/* リンク群 */
.x-link{display:inline-flex;align-items:center;gap:6px;background:#f5f5f5;border:1px solid #e5e7eb;border-radius:8px;padding:6px 12px;text-decoration:none;color:#555;font-size:12px;transition:all .15s;margin-top:4px;}
.x-link:hover{background:#f5f3ff;border-color:#7c3aed;color:#7c3aed;}
.x-link svg{width:13px;height:13px;fill:currentColor;}
.card-links{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}

/* 詳細ページ */
.detail-header{padding:24px 20px 16px;border-bottom:1px solid #f0f0f0;}
.detail-meta{font-size:13px;color:#888;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px;}
.detail-body{padding:20px;}
.detail-section-title{font-size:12px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;margin-top:20px;}
.detail-thread{font-size:14px;line-height:1.8;color:#555;white-space:pre-wrap;margin-bottom:8px;}
.detail-url-box{background:#f7f7f7;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#555;word-break:break-all;}
.detail-url-box a{color:#7c3aed;}

/* 詳細：LINE風議論 */
.debate-wrap{background:#dde5ed;border-radius:12px;padding:16px 12px;display:flex;flex-direction:column;gap:14px;margin-bottom:8px;}
.msg-row{display:flex;flex-direction:column;max-width:80%;}
.msg-row.side-a{align-self:flex-end;align-items:flex-end;}
.msg-row.side-b{align-self:flex-start;align-items:flex-start;}
.msg-row.side-judge{align-self:center;align-items:center;max-width:94%;width:94%;}
.speaker-label{font-size:10px;font-weight:600;color:#6b7c8f;margin-bottom:3px;padding:0 4px;letter-spacing:.04em;}
.round-badge{font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;margin-bottom:3px;display:inline-block;}
.round-badge-a{background:#dbeafe;color:#1d4ed8;}
.round-badge-b{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}
.bubble{padding:10px 14px;border-radius:18px;font-size:13px;line-height:1.8;white-space:pre-wrap;word-break:break-word;max-width:100%;}
.bubble-a{background:#2563eb;color:#fff;border-bottom-right-radius:4px;}
.bubble-b{background:#fff;color:#0f172a;border:1px solid #e2e8f0;border-bottom-left-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,.05);}
.debate-divider{display:flex;align-items:center;gap:8px;font-size:10px;color:#6b7c8f;font-weight:700;letter-spacing:.1em;text-transform:uppercase;}
.debate-divider::before,.debate-divider::after{content:'';flex:1;height:1px;background:#b0bec5;}
.judge-card-detail{background:#fefce8;border:1px solid #fde68a;border-radius:12px;padding:14px 16px;width:100%;}
.judge-header-detail{font-size:11px;font-weight:700;color:#92400e;margin-bottom:8px;letter-spacing:.06em;text-transform:uppercase;}
.judge-body-detail{font-size:14px;line-height:1.85;color:#1c1917;white-space:pre-wrap;}

/* RSS badge */
.rss-link{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;color:#c44f00;background:#fff5ef;border:1px solid #f5d0b8;border-radius:4px;padding:2px 7px;text-decoration:none;margin-left:8px;}

.empty{text-align:center;color:#bbb;padding:80px 20px;font-size:15px;}
.empty a{color:#7c3aed;text-decoration:none;}
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
    <div style="font-size:22px">⚔️</div>
    <?php if ($detail_post): ?>
    <div class="logo"><a href="<?php echo h($THIS_FILE); ?>" style="text-decoration:none;color:inherit;">UDebateV <span>AI</span></a></div>
    <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
    <?php else: ?>
    <div class="logo">UDebateV <span>AI</span></div>
    <a href="<?php echo h($THIS_FILE . '?feed'); ?>" class="rss-link" title="RSSフィード">
        <svg width="10" height="10" viewBox="0 0 8 8"><circle cx="1.5" cy="6.5" r="1.5" fill="#c44f00"/><path d="M0 4.5A3.5 3.5 0 013.5 8" stroke="#c44f00" stroke-width="1.2" fill="none"/><path d="M0 2A6 6 0 016 8" stroke="#c44f00" stroke-width="1.2" fill="none"/></svg>
        RSS
    </a>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($session_user); ?></strong></span>
        <a href="?dv_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?dv_login=1" class="btn-sm">X でログイン</a>
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
            <span><?php echo h(isset($detail_post['debate_at']) ? $detail_post['debate_at'] : (isset($detail_post['saved_at']) ? $detail_post['saved_at'] : '')); ?></span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#ccc;"><?php echo h($detail_post['tweet_id']); ?></span>
        </div>
        <?php if (!empty($detail_post['tweet_url'])): ?>
        <div class="detail-url-box">
            元の投稿: <a href="<?php echo h($detail_post['tweet_url']); ?>" target="_blank" rel="noopener"><?php echo h($detail_post['tweet_url']); ?></a>
        </div>
        <?php endif; ?>
    </div>
    <div class="detail-body">

        <?php if (!empty($detail_post['thread_text'])): ?>
        <div class="detail-section-title">元のスレッド</div>
        <div class="detail-thread"><?php echo h($detail_post['thread_text']); ?></div>
        <?php endif; ?>

        <?php if (!empty($detail_post['debate_turns'])): ?>
        <div class="detail-section-title">⚔️ AI議論タイムライン</div>
        <div class="debate-wrap">
        <?php
        $ra = 0; $rb = 0;
        $la = array('冒頭主張', '再反論', '最終主張');
        $lb = array('反論①',   '再反論', '最終主張');
        foreach ($detail_post['debate_turns'] as $turn):
            $spk = isset($turn['speaker']) ? $turn['speaker'] : '';
            $txt = isset($turn['text'])    ? $turn['text']    : '';
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
            <div class="judge-card-detail">
                <div class="judge-header-detail">⚖️ 司会AI — まとめ</div>
                <div class="judge-body-detail"><?php echo h($txt); ?></div>
            </div>
        </div>
        <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($detail_post['tweet_url'])): ?>
        <div style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button id="copy-btn" onclick="copyDetail()" style="background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:14px;cursor:pointer;">📋 コピー</button>
            <a href="<?php echo h($detail_post['tweet_url']); ?>" target="_blank" rel="noopener" class="x-link">
                <svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                元の投稿を開く
            </a>
            <a href="udebate.php?tweet_url=<?php echo urlencode($detail_post['tweet_url']); ?>" class="x-link" style="color:#7c3aed;border-color:#ddd6fe;background:#f5f3ff;">⚔️ 再議論</a>
        </div>
        <script>
        var _dp = <?php echo json_encode($detail_post, JSON_UNESCAPED_UNICODE); ?>;
        var _dpUrl = '<?php echo $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['tweet_id']); ?>';
        function copyDetail() {
            var lines = [];
            if (_dp.debate_conclusion) { lines.push(<?php echo json_encode('#URL2AI 議論'); ?>); lines.push(_dp.debate_conclusion); }
            lines.push('');
            if (_dp.tweet_url) lines.push(_dp.tweet_url);
            lines.push(_dpUrl);
            navigator.clipboard.writeText(lines.join('\n')).then(function() {
                var btn = document.getElementById('copy-btn');
                btn.textContent = '✓ コピー済';
                btn.style.background = '#059669';
                setTimeout(function() { btn.textContent = '📋 コピー'; btn.style.background = '#7c3aed'; }, 2000);
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
        <?php echo count($posts); ?> 件のAI議論
        <?php if ($logged_in): ?> — @<?php echo h($session_user); ?><?php endif; ?>
    </div>

    <div id="post-list"></div>
    <div id="load-sentinel" style="height:1px;"></div>
    <div id="load-indicator" style="display:none;text-align:center;padding:16px;font-size:13px;color:#888;">読み込み中...</div>
</div>
<?php endif; ?>

<script>
var dvPosts = <?php echo json_encode(array_values($posts), JSON_UNESCAPED_UNICODE); ?>;
var PAGE_SIZE = 30;
var currentPage = 0;

function dvEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderDvPosts(from, to) {
    var list = document.getElementById('post-list');
    if (!list) return;
    for (var i = from; i < to && i < dvPosts.length; i++) {
        var p          = dvPosts[i];
        var tid        = p.tweet_id          || '';
        var turl       = p.tweet_url         || '';
        var thread     = p.thread_text       || '';
        var conclusion = p.debate_conclusion || '';
        var saved      = p.debate_at         || p.saved_at || '';
        var uname      = p.username          || '';
        var avatar     = uname ? uname.charAt(0).toUpperCase() : 'D';

        var xsvg = '<svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';
        var xlink = turl ? '<a href="' + dvEsc(turl) + '" target="_blank" rel="noopener" class="x-link">' + xsvg + '元の投稿</a>' : '';

        /* 司会まとめブロック（折りたたみ） */
        var conclusionHtml = '';
        if (conclusion) {
            conclusionHtml = '<div class="conclusion-block" id="conc-' + dvEsc(tid) + '" onclick="toggleConc(\'' + dvEsc(tid) + '\')">'
                + dvEsc(conclusion)
                + '</div>'
                + '<button type="button" class="expand-btn" id="expbtn-' + dvEsc(tid) + '" onclick="toggleConc(\'' + dvEsc(tid) + '\')">続きを見る ▼</button>';
        }

        /* スレッドプレビュー */
        var threadHtml = '';
        if (thread) {
            threadHtml = '<div class="thread-block">' + dvEsc(thread) + '</div>';
        }

        var detailUrl = 'udebatev.php?id=' + encodeURIComponent(tid);
        var html = '<div class="post-card">'
            + '<div class="post-meta">'
            + '<div class="avatar">' + dvEsc(avatar) + '</div>'
            + '<div><div class="author-name">' + dvEsc(uname) + '</div><div class="author-handle">@' + dvEsc(uname) + '</div></div>'
            + '<div class="post-time">' + dvEsc(saved) + '</div>'
            + '</div>'
            + (tid ? '<a class="post-id" href="' + detailUrl + '">#' + dvEsc(tid) + '</a>' : '')
            + conclusionHtml
            + threadHtml
            + '<div class="card-links">'
            + xlink
            + '<a href="' + detailUrl + '" class="x-link" style="color:#7c3aed;border-color:#ddd6fe;background:#f5f3ff;">⚔️ 詳細</a>'
            + '<a href="udebate.php?tweet_url=' + encodeURIComponent(turl) + '" class="x-link" style="color:#7c3aed;border-color:#ddd6fe;background:#f5f3ff;">🔄 再議論</a>'
            + '</div></div>';
        list.insertAdjacentHTML('beforeend', html);
    }
    currentPage++;
}

function loadMoreDv() {
    var from = currentPage * PAGE_SIZE;
    if (from >= dvPosts.length) {
        document.getElementById('load-indicator').style.display = 'none';
        return;
    }
    renderDvPosts(from, from + PAGE_SIZE);
}

var sentinel = document.getElementById('load-sentinel');
if (sentinel) {
    var observer = new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) {
            document.getElementById('load-indicator').style.display = 'block';
            setTimeout(function() {
                loadMoreDv();
                if (currentPage * PAGE_SIZE >= dvPosts.length) {
                    document.getElementById('load-indicator').style.display = 'none';
                }
            }, 200);
        }
    }, { rootMargin: '200px' });
    observer.observe(sentinel);
}

if (dvPosts.length === 0) {
    var pl = document.getElementById('post-list');
    if (pl) { pl.innerHTML = '<div class="empty">まだAI議論がありません。<br><br><a href="udebate.php">UDebateで議論を生成する →</a></div>'; }
} else {
    loadMoreDv();
}

function toggleConc(id) {
    var el  = document.getElementById('conc-' + id);
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
