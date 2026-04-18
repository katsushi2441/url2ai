<?php
session_start();
date_default_timezone_set('Asia/Tokyo');
$DATA_DIR  = __DIR__ . '/data';
$DATA_FILE = $DATA_DIR . '/ainews_posts.json'; // 旧形式（移行用）
$BASE_URL  = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE = 'ainews.php';
$SITE_NAME = 'AI News Radar';
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

function an_base64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function an_gen_verifier() {
    $b = ''; for ($i = 0; $i < 32; $i++) { $b .= chr(mt_rand(0, 255)); } return an_base64url($b);
}
function an_gen_challenge($v) { return an_base64url(hash('sha256', $v, true)); }
function an_x_post($url, $data, $headers) {
    $opts = array('http' => array('method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n", 'content' => $data, 'timeout' => 12, 'ignore_errors' => true));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    if (!$r) { $r = '{}'; } return json_decode($r, true);
}
function an_x_get($url, $token) {
    $opts = array('http' => array('method' => 'GET', 'header' => "Authorization: Bearer $token\r\nUser-Agent: AINewsRadar/1.0\r\n", 'timeout' => 12, 'ignore_errors' => true));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    if (!$r) { $r = '{}'; } return json_decode($r, true);
}

if (isset($_GET['an_logout'])) { session_destroy(); header('Location: ' . $x_redirect_uri); exit; }
if (isset($_GET['an_login'])) {
    $ver = an_gen_verifier(); $chal = an_gen_challenge($ver); $state = md5(uniqid('', true));
    $_SESSION['an_code_verifier'] = $ver; $_SESSION['an_oauth_state'] = $state;
    $p = array('response_type' => 'code', 'client_id' => $x_client_id, 'redirect_uri' => $x_redirect_uri,
               'scope' => 'tweet.read users.read', 'state' => $state, 'code_challenge' => $chal, 'code_challenge_method' => 'S256');
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($p)); exit;
}
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['an_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['an_oauth_state']) {
        $post = http_build_query(array('grant_type' => 'authorization_code', 'code' => $_GET['code'],
            'redirect_uri' => $x_redirect_uri, 'code_verifier' => $_SESSION['an_code_verifier'], 'client_id' => $x_client_id));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = an_x_post('https://api.twitter.com/2/oauth2/token', $post, array('Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . $cred));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token'] = $data['access_token'];
            unset($_SESSION['an_oauth_state'], $_SESSION['an_code_verifier']);
            $me = an_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) { $_SESSION['session_username'] = $me['data']['username']; }
        }
    }
    header('Location: ' . $x_redirect_uri); exit;
}

$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin     = ($session_user === $ADMIN);
$logged_in    = ($session_user !== '');

$posts = array();
$an_post_files = glob($DATA_DIR . '/ainews_*.json');
if ($an_post_files) {
    foreach ($an_post_files as $pf) {
        $p = json_decode(file_get_contents($pf), true);
        if (is_array($p) && !empty($p['id'])) {
            $posts[] = $p;
        }
    }
}
if (file_exists($DATA_FILE)) {
    $old = json_decode(file_get_contents($DATA_FILE), true);
    if (is_array($old)) {
        $existing_ids = array();
        foreach ($posts as $p) { $existing_ids[$p['id']] = true; }
        foreach ($old as $p) {
            if (is_array($p) && !empty($p['id']) && !isset($existing_ids[$p['id']])) {
                $posts[] = $p;
            }
        }
    }
}
usort($posts, function($a, $b) {
    return strcmp(
        isset($b['created_at']) ? $b['created_at'] : '',
        isset($a['created_at']) ? $a['created_at'] : ''
    );
});

/* =========================================================
   RSS フィード出力 (?feed)
========================================================= */
if (isset($_GET['feed'])) {
    $rss_items = array_slice($posts, 0, 20);
    header('Access-Control-Allow-Origin: https://exbridge.jp');
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    echo '<channel>' . "\n";
    echo '<title>' . $SITE_NAME . '</title>' . "\n";
    echo '<link>' . $BASE_URL . '/' . $THIS_FILE . '</link>' . "\n";
    echo '<description>XのニュースメディアアカウントのAI考察。</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . $BASE_URL . '/' . $THIS_FILE . '?feed" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rss_items as $p) {
        $title    = isset($p['title'])      ? $p['title']      : '(no title)';
        $summary  = isset($p['summary'])    ? $p['summary']    : '';
        $id       = isset($p['id'])         ? $p['id']         : '';
        $site_url = isset($p['tweet_url'])        ? $p['tweet_url']        : '';
        $date_raw = isset($p['created_at']) ? $p['created_at'] : '';
        $desc     = mb_substr(str_replace("\n", ' ', $summary), 0, 200);
        $link     = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($id);
        $pub_date = $date_raw ? date('r', strtotime($date_raw)) : date('r');
        echo '<item>' . "\n";
        echo '<title><![CDATA[' . $title . ']]></title>' . "\n";
        echo '<link>' . htmlspecialchars($link) . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . htmlspecialchars($link) . '</guid>' . "\n";
        echo '<description><![CDATA[' . $desc . ($site_url ? "\n\n" . $site_url : '') . ']]></description>' . "\n";
        echo '<pubDate>' . $pub_date . '</pubDate>' . "\n";
        echo '</item>' . "\n";
    }
    echo '</channel>' . "\n";
    echo '</rss>' . "\n";
    exit;
}

$detail_id   = isset($_GET['id'])  ? trim($_GET['id'])  : '';
$filter_tag  = isset($_GET['tag']) ? trim($_GET['tag']) : '';
$detail_post = null;

if ($detail_id) {
    foreach ($posts as $p) {
        if (isset($p['id']) && $p['id'] === $detail_id) { $detail_post = $p; break; }
    }
}

$all_tags = array();
foreach ($posts as $post) {
    if (!empty($post['tags'])) {
        foreach ($post['tags'] as $tag) {
            $all_tags[$tag] = isset($all_tags[$tag]) ? $all_tags[$tag] + 1 : 1;
        }
    }
}
arsort($all_tags);

/* SEO */
if ($detail_post) {
    $page_title       = htmlspecialchars($detail_post['title']) . ' | ' . $SITE_NAME;
    $page_description = htmlspecialchars(mb_substr(str_replace("\n", ' ', isset($detail_post['summary']) ? $detail_post['summary'] : ''), 0, 160));
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['id']);
    $page_type        = 'article';
    $published_time   = isset($detail_post['created_at']) ? $detail_post['created_at'] : '';
    $keywords         = !empty($detail_post['tags']) ? implode(', ', $detail_post['tags']) : 'AI, 技術';
} elseif ($filter_tag) {
    $page_title       = '#' . htmlspecialchars($filter_tag) . ' の技術リンク | ' . $SITE_NAME;
    $page_description = htmlspecialchars($filter_tag) . ' に関連するニュース記事のAI考察リンク集。';
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?tag=' . urlencode($filter_tag);
    $page_type        = 'website';
    $published_time   = '';
    $keywords         = htmlspecialchars($filter_tag) . ', AI, 技術';
} else {
    $page_title       = $SITE_NAME . ' — XニュースのAI考察リンク集';
    $page_description = 'Xのニュース投稿URLを登録してAIが自動考察。タグで分類して管理できます。';
    $page_url         = $BASE_URL . '/' . $THIS_FILE;
    $page_type        = 'website';
    $published_time   = '';
    $keywords         = 'AI, 技術, LLM, 機械学習, プログラミング, 要約';
}
$jsonld = $detail_post ? array(
    '@context' => 'https://schema.org', '@type' => 'TechArticle',
    'headline' => $detail_post['title'],
    'description' => mb_substr(str_replace("\n", ' ', isset($detail_post['summary']) ? $detail_post['summary'] : ''), 0, 160),
    'url' => $page_url, 'datePublished' => $published_time,
    'author' => array('@type' => 'Person', 'name' => 'xb_bittensor'),
    'publisher' => array('@type' => 'Organization', 'name' => $SITE_NAME),
) : array(
    '@context' => 'https://schema.org', '@type' => 'CollectionPage',
    'name' => $page_title, 'description' => $page_description, 'url' => $page_url,
    'publisher' => array('@type' => 'Organization', 'name' => $SITE_NAME),
);
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $page_title; ?></title>
<meta name="description" content="<?php echo $page_description; ?>">
<meta name="keywords" content="<?php echo $keywords; ?>">
<meta name="author" content="xb_bittensor">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo $page_url; ?>">
<link rel="alternate" type="application/rss+xml" title="<?php echo htmlspecialchars($SITE_NAME); ?> RSS" href="<?php echo $BASE_URL . '/' . $THIS_FILE . '?feed'; ?>">
<meta property="og:type" content="<?php echo $page_type; ?>">
<meta property="og:title" content="<?php echo $page_title; ?>">
<meta property="og:description" content="<?php echo $page_description; ?>">
<meta property="og:url" content="<?php echo $page_url; ?>">
<meta property="og:site_name" content="<?php echo htmlspecialchars($SITE_NAME); ?>">
<meta property="og:locale" content="ja_JP">
<meta property="og:image" content="https://aiknowledgecms.exbridge.jp/images/ainewsradar.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://aiknowledgecms.exbridge.jp/images/ainewsradar.png">
<meta name="twitter:site" content="@xb_bittensor">
<meta name="twitter:title" content="<?php echo $page_title; ?>">
<meta name="twitter:description" content="<?php echo $page_description; ?>">
<script type="application/ld+json">
<?php echo json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>
</script>
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
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #fff; color: #222; font-family: -apple-system, 'Helvetica Neue', sans-serif; }

.header {
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    padding: 14px 20px;
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    gap: 12px;
}
.header h1 { font-size: 17px; font-weight: 700; color: #111; }
.logo { font-size: 17px; font-weight: 700; letter-spacing: -.02em; color: #111; }
.logo span { color: #e11d48; }
.badge { background: #e11d48; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.back-btn { margin-left: auto; font-size: 13px; color: #e11d48; text-decoration: none; padding: 5px 12px; border: 1px solid #e11d48; border-radius: 6px; }
.back-btn:hover { background: #fff1f2; }
.userbar { display: flex; align-items: center; gap: .75rem; font-size: .8rem; margin-left: auto; }
.userbar strong { color: #059669; }
.btn-sm { border: 1px solid #cbd5e1; padding: 3px 10px; border-radius: 4px; color: #64748b; text-decoration: none; font-size: .75rem; }
.btn-sm:hover { border-color: #dc2626; color: #dc2626; }
.btn-login-sm { border: 1px solid #e11d48; padding: 4px 12px; border-radius: 4px; color: #e11d48; text-decoration: none; font-size: .75rem; }
.btn-login-sm:hover { background: #fff1f2; }
.rss-link { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; color: #c44f00; background: #fff5ef; border: 1px solid #f5d0b8; border-radius: 4px; padding: 2px 7px; text-decoration: none; }

/* 管理者フォーム */
.admin-form {
    background: #fff1f2;
    border-bottom: 2px solid #e11d48;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.admin-form-label { font-size: 12px; color: #e11d48; font-weight: 700; white-space: nowrap; }
.admin-form input[type=text] {
    flex: 1; min-width: 260px;
    border: 1px solid #7dd3fc; border-radius: 6px;
    padding: 7px 12px; font-size: 13px; outline: none;
}
.admin-form input[type=text]:focus { border-color: #e11d48; }
.admin-register-btn {
    background: #e11d48; color: #fff; border: none; border-radius: 6px;
    padding: 7px 18px; font-size: 13px; font-weight: 600;
    cursor: pointer; white-space: nowrap; transition: background 0.15s;
}
.admin-register-btn:hover { background: #0284c7; }
.admin-register-btn:disabled { background: #bbb; cursor: not-allowed; }
.admin-status { font-size: 12px; padding: 4px 10px; border-radius: 4px; display: none; }
.admin-status.ok      { background: #dcfce7; color: #166534; display: inline-block; }
.admin-status.err     { background: #fee2e2; color: #991b1b; display: inline-block; }
.admin-status.loading { background: #fff1f2;  color: #e11d48;  display: inline-block; }

/* タグフィルター */
.tag-filter {
    background: #fafafa;
    border-bottom: 1px solid #f0f0f0;
    padding: 10px 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: flex-start;
    max-height: 148px;
    overflow-y: auto;
    overflow-x: hidden;
}
.tag-filter-label { font-size: 12px; color: #888; margin-right: 2px; white-space: nowrap; }
.tag-btn {
    background: #f0f0f0; border: 1px solid #e5e7eb; border-radius: 20px;
    padding: 3px 12px; font-size: 12px; color: #555;
    text-decoration: none; display: inline-block; transition: all 0.15s;
}
.tag-btn:hover { border-color: #e11d48; color: #e11d48; }
.tag-btn.active { background: #e11d48; border-color: #e11d48; color: #fff; }

.container { max-width: 640px; margin: 0 auto; padding: 0 0 80px; }
.count-bar { padding: 10px 20px; font-size: 13px; color: #888; border-bottom: 1px solid #f0f0f0; }

/* カード */
.post-card { border-bottom: 1px solid #f0f0f0; padding: 20px; transition: background 0.15s; }
.post-card:hover { background: #fafafa; }
.post-meta { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.avatar {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, #e11d48, #6366f1);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 14px; color: #fff; flex-shrink: 0;
}
.author-name { font-weight: 700; color: #111; font-size: 14px; }
.author-handle { color: #888; font-size: 13px; }
.post-time { color: #aaa; font-size: 12px; margin-left: auto; }
.btn-group { display: flex; gap: 6px; flex-shrink: 0; }
.copy-btn {
    background: none; border: 1px solid #e5e7eb; border-radius: 6px;
    padding: 4px 10px; font-size: 12px; color: #888;
    cursor: pointer; transition: all 0.15s; white-space: nowrap;
}
.copy-btn:hover { border-color: #e11d48; color: #e11d48; }
.copy-btn.copied { border-color: #22c55e; color: #22c55e; }

.post-title { font-size: 15px; font-weight: 700; color: #111; margin-bottom: 6px; }
.post-title a { color: #111; text-decoration: none; }
.post-title a:hover { color: #e11d48; }

/* AI考察ブロック */
.summary-block {
    background: #fff1f2;
    border-left: 3px solid #e11d48;
    border-radius: 0 8px 8px 0;
    padding: 12px 14px;
    margin-bottom: 12px;
    font-size: 13px;
    line-height: 1.75;
    color: #334155;
    white-space: pre-wrap;
    max-height: 100px;
    overflow: hidden;
    position: relative;
    cursor: pointer;
}
.summary-block::after {
    content: '';
    position: absolute; bottom: 0; left: 0; right: 0;
    height: 28px;
    background: linear-gradient(transparent, #fff1f2);
    pointer-events: none;
}
.summary-block.expanded { max-height: none; }
.summary-block.expanded::after { display: none; }
.summary-label { font-size: 11px; color: #e11d48; font-weight: 700; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
.expand-btn { background: none; border: none; color: #e11d48; font-size: .75rem; cursor: pointer; padding: 2px 0 6px; display: block; }

/* 件 */
.site-link {
    display: inline-flex; align-items: center; gap: 6px;
    background: #f5f5f5; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 7px 14px; text-decoration: none; color: #e11d48;
    font-size: 12px; font-weight: 500; transition: all 0.15s;
    margin-bottom: 10px; word-break: break-all;
}
.site-link:hover { background: #fff1f2; border-color: #e11d48; }
.detail-link {
    display: inline-flex; align-items: center;
    background: #f5f5f5; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 7px 14px; text-decoration: none; color: #888;
    font-size: 12px; transition: all 0.15s; margin-bottom: 10px; margin-left: 8px;
}
.detail-link:hover { background: #fff1f2; border-color: #e11d48; color: #e11d48; }

.tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.tag {
    background: #f0f0f0; color: #666; font-size: 12px;
    padding: 3px 10px; border-radius: 20px; text-decoration: none; display: inline-block;
}
.tag:hover { background: #ffe4e6; color: #e11d48; }

.empty { text-align: center; color: #bbb; padding: 80px 20px; font-size: 15px; }

/* 詳細ページ */
.detail-header { padding: 24px 20px 16px; border-bottom: 1px solid #f0f0f0; }
.detail-header h1 { font-size: 20px; font-weight: 700; color: #111; margin-bottom: 8px; }
.detail-meta { font-size: 13px; color: #888; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.detail-body { padding: 20px; }
.detail-url-box {
    background: #fff1f2; border: 1px solid #fca5a5; border-radius: 8px;
    padding: 12px 16px; margin-bottom: 16px; font-size: 13px; color: #555; word-break: break-all;
}
.detail-url-box a { color: #e11d48; }
.detail-section-title { font-size: 12px; font-weight: 700; color: #e11d48; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; margin-top: 20px; }
.detail-summary {
    background: #fff1f2; border-left: 3px solid #e11d48;
    border-radius: 0 8px 8px 0; padding: 14px 16px;
    font-size: 14px; line-height: 1.85; color: #222; white-space: pre-wrap;
}
.detail-btn-group { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
.detail-copy-btn {
    background: #e11d48; border: none; border-radius: 8px;
    padding: 10px 20px; font-size: 14px; color: #fff;
    cursor: pointer; transition: background 0.15s;
}
.detail-copy-btn:hover { background: #0284c7; }
.detail-copy-btn.copied { background: #22c55e; }
.detail-x-btn {
    background: #000; border: none; border-radius: 8px;
    padding: 10px 20px; font-size: 14px; color: #fff;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background 0.15s;
}
.detail-x-btn:hover { background: #333; }

#copy-toast {
    position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%);
    background: #111; color: #fff; padding: 10px 22px;
    border-radius: 20px; font-size: 13px;
    opacity: 0; pointer-events: none; transition: opacity 0.3s; z-index: 999;
}
#copy-toast.show { opacity: 1; }
</style>
</head>
<body>

<div class="header">
    <div style="font-size:22px">📰</div>
    <?php if ($detail_post): ?>
    <div class="logo"><a href="<?php echo $THIS_FILE; ?>" style="text-decoration:none;color:inherit;">AI News <span>Radar</span></a></div>
    <a class="back-btn" href="<?php echo $THIS_FILE; ?>">← 一覧</a>
    <?php elseif ($filter_tag): ?>
    <div class="logo"><a href="<?php echo $THIS_FILE; ?>" style="text-decoration:none;color:inherit;">AI News <span>Radar</span></a></div>
    <span class="badge">#<?php echo htmlspecialchars($filter_tag); ?></span>
    <a class="back-btn" href="<?php echo $THIS_FILE; ?>">← 一覧</a>
    <?php else: ?>
    <div class="logo">AI News <span>Radar</span></div>
    <a href="<?php echo $THIS_FILE . '?feed'; ?>" class="rss-link" title="RSSフィード">
        <svg width="10" height="10" viewBox="0 0 8 8"><circle cx="1.5" cy="6.5" r="1.5" fill="#c44f00"/><path d="M0 4.5A3.5 3.5 0 013.5 8" stroke="#c44f00" stroke-width="1.2" fill="none"/><path d="M0 2A6 6 0 016 8" stroke="#c44f00" stroke-width="1.2" fill="none"/></svg>
        RSS
    </a>
    <?php endif; ?>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <strong>@<?php echo htmlspecialchars($session_user); ?></strong>
        <a href="?an_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?an_login=1" class="btn-login-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_admin && !$detail_post): ?>
<!-- ========== 管理者：URL登録フォーム ========== -->
<div class="admin-form">
    <span class="admin-form-label">X投稿URL</span>
    <input type="text" id="admin-url-input" placeholder="https://x.com/newsaccount/status/...">
    <button class="admin-register-btn" id="admin-register-btn" type="button" onclick="adminRegister()">登録</button>
</div>
<div style="padding:6px 20px;display:none;" id="admin-status-wrap">
    <span class="admin-status" id="admin-status"></span>
</div>
<?php endif; ?>

<?php if ($detail_post): ?>
<!-- ========== 詳細ページ ========== -->
<div class="container">
    <div class="detail-header">
        <h1><?php echo htmlspecialchars($detail_post['title']); ?></h1>
        <div class="detail-meta">
            <span>@<?php echo htmlspecialchars(isset($detail_post['author']) ? $detail_post['author'] : ''); ?></span>
            <span><?php echo htmlspecialchars(isset($detail_post['created_at']) ? $detail_post['created_at'] : ''); ?></span>
        </div>
    </div>
    <div class="detail-body">

        <div class="detail-url-box">
            🔗 <a href="<?php echo htmlspecialchars($detail_post['tweet_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($detail_post['tweet_url']); ?></a>
        </div>

        <?php if (!empty($detail_post['summary'])): ?>
        <div class="detail-section-title">🤖 AI考察</div>
        <div class="detail-summary"><?php echo htmlspecialchars($detail_post['summary']); ?></div>
        <?php endif; ?>

        <?php if (!empty($detail_post['tags'])): ?>
        <div class="detail-section-title">タグ</div>
        <div class="tags" style="margin-top:8px;">
            <?php foreach ($detail_post['tags'] as $tag): ?>
            <a class="tag" href="<?php echo $THIS_FILE; ?>?tag=<?php echo urlencode($tag); ?>" rel="tag">#<?php echo htmlspecialchars($tag); ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="detail-btn-group">
            <button class="detail-copy-btn" type="button" onclick="copyDetail()">📋 コピー</button>
            <a class="detail-x-btn" id="detail-x-link" href="#" target="_blank" rel="noopener">𝕏 Xに投稿</a>
            <a class="detail-link" href="<?php echo htmlspecialchars($detail_post['tweet_url']); ?>" target="_blank" rel="noopener">🌐 元の記事を開く</a>
        </div>
    </div>
</div>

<script>
var detailPost    = <?php echo json_encode($detail_post, JSON_UNESCAPED_UNICODE); ?>;
var detailPageUrl = '<?php echo $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['id']); ?>';

function buildDetailText(post) {
    var lines = [];
    lines.push(<?php echo json_encode('#URL2AI ニュース'); ?>);
    lines.push(post.title);
    lines.push('');
    if (post.summary) {
        var s = post.summary.replace(/https?:\/\/\S+/g, '').trim();
        if (s) lines.push(s);
    }
    lines.push('');
    lines.push(post.tweet_url);
    lines.push(detailPageUrl);
    if (post.tags && post.tags.length) {
        lines.push(post.tags.map(function(t){ return '#' + t; }).join(' '));
    }
    return lines.join('\n');
}

function buildXText(post) {
    var lines = [];
    if (post.summary) {
        var s = post.summary.replace(/https?:\/\/\S+/g, '').trim();
        var short = s.length > 80 ? s.substring(0, 80) + '…' : s;
        if (short) lines.push(short);
    }
    lines.push('');
    lines.push(post.tweet_url);
    lines.push(detailPageUrl);
    if (post.tags && post.tags.length) {
        lines.push(post.tags.slice(0, 3).map(function(t){ return '#' + t; }).join(' '));
    }
    return lines.join('\n');
}

function copyDetail() {
    navigator.clipboard.writeText(buildDetailText(detailPost)).then(function() {
        var btn = document.querySelector('.detail-copy-btn');
        btn.textContent = '✓ コピー済'; btn.classList.add('copied');
        setTimeout(function() { btn.textContent = '📋 コピー'; btn.classList.remove('copied'); }, 2000);
        showToast('コピーしました');
    });
}

(function() {
    document.getElementById('detail-x-link').href =
        'https://twitter.com/intent/tweet?text=' + encodeURIComponent(buildXText(detailPost));
})();

function showToast(msg) {
    var t = document.getElementById('copy-toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
</script>

<?php else: ?>
<!-- ========== 一覧ページ ========== -->
<?php
$filtered_posts = $posts;
if ($filter_tag) {
    $filtered_posts = array_filter($posts, function($p) use ($filter_tag) {
        return !empty($p['tags']) && in_array($filter_tag, $p['tags']);
    });
    $filtered_posts = array_values($filtered_posts);
}
?>

<?php if (!empty($all_tags)): ?>
<div class="tag-filter">
    <span class="tag-filter-label">タグ:</span>
    <a class="tag-btn <?php echo !$filter_tag ? 'active' : ''; ?>" href="<?php echo $THIS_FILE; ?>">すべて</a>
    <?php foreach ($all_tags as $tag => $count): ?>
    <a class="tag-btn <?php echo $filter_tag === $tag ? 'active' : ''; ?>" href="<?php echo $THIS_FILE; ?>?tag=<?php echo urlencode($tag); ?>" rel="tag">
        #<?php echo htmlspecialchars($tag); ?> <span style="opacity:0.6"><?php echo $count; ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="container">
<div class="count-bar">
    <?php echo count($filtered_posts); ?> 件
    <?php if ($filter_tag): ?>
    — #<?php echo htmlspecialchars($filter_tag); ?>
    <?php else: ?>
    by @xb_bittensor
    <?php endif; ?>
</div>

<div id="post-list"></div>
<div id="load-sentinel" style="height:1px;"></div>
<div id="load-indicator" style="display:none;text-align:center;padding:16px;font-size:13px;color:#888;">読み込み中...</div>
</div>

<script>
var posts    = <?php echo json_encode(array_values($filtered_posts), JSON_UNESCAPED_UNICODE); ?>;
var BASE_URL = '<?php echo $BASE_URL; ?>';
var THIS_FILE = '<?php echo $THIS_FILE; ?>';
var PAGE_SIZE = 30;
var currentPage = 0;

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function getDetailUrl(post) {
    return BASE_URL + '/' + THIS_FILE + '?id=' + encodeURIComponent(post.id);
}

function buildPostText(post) {
    var lines = [];
    lines.push(<?php echo json_encode('#URL2AI ニュース'); ?>);
    lines.push(post.title);
    lines.push('');
    if (post.summary) {
        var s = post.summary.replace(/https?:\/\/\S+/g, '').trim();
        if (s) lines.push(s);
    }
    lines.push('');
    lines.push(post.tweet_url);
    lines.push(getDetailUrl(post));
    if (post.tags && post.tags.length) {
        lines.push(post.tags.map(function(t){ return '#' + t; }).join(' '));
    }
    return lines.join('\n');
}

function buildXText(post) {
    var lines = [];
    if (post.summary) {
        var s = post.summary.replace(/https?:\/\/\S+/g, '').trim();
        var short = s.length > 80 ? s.substring(0, 80) + '…' : s;
        if (short) lines.push(short);
    }
    lines.push('');
    lines.push(post.tweet_url);
    lines.push(getDetailUrl(post));
    if (post.tags && post.tags.length) {
        lines.push(post.tags.slice(0, 3).map(function(t){ return '#' + t; }).join(' '));
    }
    return lines.join('\n');
}

function renderPosts(from, to) {
    var list = document.getElementById('post-list');
    for (var i = from; i < to && i < posts.length; i++) {
        var post = posts[i];
        var idx  = i;
        var tags = '';
        if (post.tags && post.tags.length) {
            for (var t = 0; t < post.tags.length; t++) {
                tags += '<a class="tag" href="' + THIS_FILE + '?tag=' + encodeURIComponent(post.tags[t]) + '" rel="tag">#' + esc(post.tags[t]) + '</a>';
            }
        }
        var summaryHtml = '';
        if (post.summary) {
            summaryHtml = '<div class="summary-block" id="sum-' + esc(post.id) + '" onclick="toggleSum(\'' + esc(post.id) + '\')">'
                + '<div class="summary-label">🤖 AI考察</div>'
                + esc(post.summary)
                + '</div>'
                + '<button type="button" class="expand-btn" id="expbtn-' + esc(post.id) + '" onclick="toggleSum(\'' + esc(post.id) + '\')">続きを見る ▼</button>';
        }
        var html = '<div class="post-card" data-idx="' + idx + '">'
            + '<div class="post-meta">'
            + '<div class="avatar">AI</div>'
            + '<div><div class="author-name">' + esc(post.author || 'xb_bittensor') + '</div><div class="author-handle">@' + esc(post.author || 'xb_bittensor') + '</div></div>'
            + '<div class="post-time">' + esc(post.created_at || '') + '</div>'
            + '<div class="btn-group">'
            + '<button class="copy-btn" type="button" onclick="copyPost(' + idx + ')">コピー</button>'
            + '<a class="copy-btn" id="x-btn-' + idx + '" href="#" target="_blank" rel="noopener" style="text-decoration:none;display:inline-flex;align-items:center;">𝕏</a>'
            + '</div></div>'
            + '<div class="post-title"><a href="' + THIS_FILE + '?id=' + encodeURIComponent(post.id) + '">' + esc(post.title) + '</a></div>'
            + summaryHtml
            + '<a class="site-link" href="' + esc(post.tweet_url) + '" target="_blank" rel="noopener">🌐 ' + esc(post.tweet_url) + '</a>'
            + '<a class="detail-link" href="' + THIS_FILE + '?id=' + encodeURIComponent(post.id) + '">🔖 詳細</a>'
            + (tags ? '<div class="tags">' + tags + '</div>' : '')
            + '</div>';
        list.insertAdjacentHTML('beforeend', html);
        var xLink = document.getElementById('x-btn-' + idx);
        if (xLink) { xLink.href = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(buildXText(post)); }
    }
    currentPage++;
}

function loadMore() {
    var from = currentPage * PAGE_SIZE;
    if (from >= posts.length) { document.getElementById('load-indicator').style.display = 'none'; return; }
    renderPosts(from, from + PAGE_SIZE);
}

var sentinel = document.getElementById('load-sentinel');
var observer = new IntersectionObserver(function(entries) {
    if (entries[0].isIntersecting) {
        document.getElementById('load-indicator').style.display = 'block';
        setTimeout(function() {
            loadMore();
            if (currentPage * PAGE_SIZE >= posts.length) { document.getElementById('load-indicator').style.display = 'none'; }
        }, 200);
    }
}, { rootMargin: '200px' });
observer.observe(sentinel);

if (posts.length === 0) {
    document.getElementById('post-list').innerHTML = '<div class="empty">まだニュースがありません</div>';
} else { loadMore(); }

function toggleSum(id) {
    var el  = document.getElementById('sum-' + id);
    var btn = document.getElementById('expbtn-' + id);
    if (!el) return;
    if (el.classList.contains('expanded')) {
        el.classList.remove('expanded');
        if (btn) btn.textContent = '続きを見る ▼';
    } else {
        el.classList.add('expanded');
        if (btn) btn.textContent = '閉じる ▲';
    }
}

function copyPost(idx) {
    var post = posts[idx];
    if (!post) return;
    navigator.clipboard.writeText(buildPostText(post)).then(function() {
        var btn = document.querySelector('[data-idx="' + idx + '"] .copy-btn');
        if (btn) {
            btn.textContent = '✓ コピー済'; btn.classList.add('copied');
            setTimeout(function() { btn.textContent = 'コピー'; btn.classList.remove('copied'); }, 2000);
        }
        showToast('コピーしました');
    });
}

<?php if ($is_admin): ?>
function adminRegister() {
    var urlInput = document.getElementById('admin-url-input');
    var btn      = document.getElementById('admin-register-btn');
    var status   = document.getElementById('admin-status');
    var wrap     = document.getElementById('admin-status-wrap');
    var url      = urlInput.value.trim();

    if (!url || url.indexOf('x.com') === -1) {
        status.textContent = '有効なX投稿URLを入力してください';
        status.className   = 'admin-status err';
        wrap.style.display = 'block';
        return;
    }

    btn.disabled       = true;
    status.textContent = 'AI考察生成中... (1〜2分かかります)';
    status.className   = 'admin-status loading';
    wrap.style.display = 'block';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'saveainews.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.status === 'ok') {
                status.textContent = '登録完了: ' + res.title;
                status.className   = 'admin-status ok';
                urlInput.value     = '';
                setTimeout(function() { location.reload(); }, 1500);
            } else if (res.status === 'duplicate') {
                status.textContent = '既に登録済みです';
                status.className   = 'admin-status err';
            } else {
                status.textContent = 'エラー: ' + (res.error || '不明');
                status.className   = 'admin-status err';
            }
        } catch(e) {
            alert('通信エラー\nHTTP: ' + xhr.status + '\n' + xhr.responseText.substring(0, 500));
            status.textContent = '通信エラー';
            status.className   = 'admin-status err';
        }
    };
    xhr.send(JSON.stringify({ action: 'register', tweet_url: url }));
}

document.getElementById('admin-url-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') adminRegister();
});
<?php endif; ?>

function showToast(msg) {
    var t = document.getElementById('copy-toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
</script>

<?php endif; ?>

<div id="copy-toast">コピーしました</div>
</body>
</html>
