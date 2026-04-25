<?php
session_start();
date_default_timezone_set("Asia/Tokyo");
$DATA_DIR     = __DIR__ . '/data';
$DATA_FILE    = $DATA_DIR . '/oss_posts.json'; // 旧形式（移行用）
$BASE_URL     = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE    = 'oss.php';
$SITE_NAME    = 'AIGM OSS Timeline';
$ADMIN        = 'xb_bittensor';

/* X API キー読み込み */
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
$x_redirect_uri  = $BASE_URL . '/oss.php';

function oss_base64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function oss_gen_verifier() {
    $b = ''; for ($i = 0; $i < 32; $i++) { $b .= chr(mt_rand(0, 255)); } return oss_base64url($b);
}
function oss_gen_challenge($v) { return oss_base64url(hash('sha256', $v, true)); }
function oss_x_post($url, $data, $headers) {
    $opts = array('http' => array('method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n", 'content' => $data, 'timeout' => 12, 'ignore_errors' => true));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    if (!$r) { $r = '{}'; } return json_decode($r, true);
}
function oss_x_get($url, $token) {
    $opts = array('http' => array('method' => 'GET', 'header' => "Authorization: Bearer $token\r\nUser-Agent: OSSTimeline/1.0\r\n", 'timeout' => 12, 'ignore_errors' => true));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    if (!$r) { $r = '{}'; } return json_decode($r, true);
}
function oss_is_valid_paragraph_url($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return false;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return false;
    }
    $host = strtolower($host);
    if ($host === 'aiknowledgecms.exbridge.jp') {
        return false;
    }
    return (strpos($host, 'paragraph.com') !== false || strpos($host, 'paragraph.xyz') !== false);
}

if (isset($_GET['oss_logout'])) { session_destroy(); header('Location: ' . $x_redirect_uri); exit; }
if (isset($_GET['oss_login'])) {
    $ver = oss_gen_verifier();
    $chal = oss_gen_challenge($ver);
    $state = md5(uniqid('', true));
    $_SESSION['oss_code_verifier'] = $ver;
    $_SESSION['oss_oauth_state']   = $state;
    $p = array('response_type' => 'code', 'client_id' => $x_client_id, 'redirect_uri' => $x_redirect_uri,
               'scope' => 'tweet.read users.read', 'state' => $state, 'code_challenge' => $chal, 'code_challenge_method' => 'S256');
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($p)); exit;
}
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['oss_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['oss_oauth_state']) {
        $post = http_build_query(array('grant_type' => 'authorization_code', 'code' => $_GET['code'],
            'redirect_uri' => $x_redirect_uri, 'code_verifier' => $_SESSION['oss_code_verifier'], 'client_id' => $x_client_id));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = oss_x_post('https://api.twitter.com/2/oauth2/token', $post, array('Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . $cred));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token'] = $data['access_token'];
            unset($_SESSION['oss_oauth_state'], $_SESSION['oss_code_verifier']);
            $me = oss_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) { $_SESSION['session_username'] = $me['data']['username']; }
        }
    }
    header('Location: ' . $x_redirect_uri); exit;
}

$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin     = ($session_user === $ADMIN);
$logged_in    = ($session_user !== '');

$posts = array();
/* 個別ファイル読み込み */
$oss_post_files = glob($DATA_DIR . '/oss_*.json');
if ($oss_post_files) {
    foreach ($oss_post_files as $pf) {
        $p = json_decode(file_get_contents($pf), true);
        if (is_array($p) && !empty($p['id'])) {
            $posts[] = $p;
        }
    }
}
/* 旧形式の一括ファイルが残っている場合も取り込む（移行用） */
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
foreach ($posts as &$p) {
    if (!empty($p['paragraph_url']) && !oss_is_valid_paragraph_url($p['paragraph_url'])) {
        $p['paragraph_url'] = '';
    }
}
unset($p);
usort($posts, function($a, $b) {
    $ta = isset($a['timestamp']) ? $a['timestamp'] : (isset($a['created_at']) ? strtotime($a['created_at']) : 0);
    $tb = isset($b['timestamp']) ? $b['timestamp'] : (isset($b['created_at']) ? strtotime($b['created_at']) : 0);
    return $tb - $ta;
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
    echo '<title>AI OSS Timeline | AIGM OSS Timeline</title>' . "\n";
    echo '<link>' . $BASE_URL . '/oss.php</link>' . "\n";
    echo '<description>GitHub厳選AI系OSSプロジェクトの紹介とAI考察。毎日更新。</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . $BASE_URL . '/oss.php?feed" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rss_items as $p) {
        $title    = isset($p['title'])      ? $p['title']      : '(no title)';
        $text     = isset($p['post_text'])  ? $p['post_text']  : '';
        $id       = isset($p['id'])         ? $p['id']         : '';
        $date_raw = isset($p['created_at']) ? $p['created_at'] : '';
        $github   = isset($p['github_url']) ? $p['github_url'] : '';
        $desc     = mb_substr(strip_tags($text), 0, 200);
        $link     = $BASE_URL . '/oss.php?id=' . urlencode($id);
        $pub_date = $date_raw ? date('r', strtotime($date_raw)) : date('r');
        echo '<item>' . "\n";
        echo '<title><![CDATA[' . $title . ']]></title>' . "\n";
        echo '<link>' . htmlspecialchars($link) . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . htmlspecialchars($link) . '</guid>' . "\n";
        echo '<description><![CDATA[' . $desc . ($github ? "\n\nGitHub: " . $github : '') . ']]></description>' . "\n";
        echo '<pubDate>' . $pub_date . '</pubDate>' . "\n";
        echo '</item>' . "\n";
    }
    echo '</channel>' . "\n";
    echo '</rss>' . "\n";
    exit;
}

$detail_id   = isset($_GET['id']) ? trim($_GET['id']) : '';
$filter_tag  = isset($_GET['tag']) ? trim($_GET['tag']) : '';
$detail_post = null;

if ($detail_id) {
    foreach ($posts as $p) {
        if ($p['id'] === $detail_id) { $detail_post = $p; break; }
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
uksort($all_tags, function($a, $b) {
    return strcmp(mb_convert_encoding($a, 'UTF-32', 'UTF-8'),
                  mb_convert_encoding($b, 'UTF-32', 'UTF-8'));
});

if ($detail_post) {
    $page_title       = htmlspecialchars($detail_post['title']) . ' | ' . $SITE_NAME;
    $page_description = htmlspecialchars(mb_substr(strip_tags($detail_post['post_text']), 0, 160));
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['id']);
    $page_type        = 'article';
    $published_time   = isset($detail_post['created_at']) ? $detail_post['created_at'] : '';
    $keywords         = !empty($detail_post['tags']) ? implode(', ', $detail_post['tags']) : 'AI, OSS, GitHub';
} elseif ($filter_tag) {
    $page_title       = '#' . htmlspecialchars($filter_tag) . ' の OSS一覧 | ' . $SITE_NAME;
    $page_description = htmlspecialchars($filter_tag) . ' に関連するAI系OSSプロジェクトの一覧です。';
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?tag=' . urlencode($filter_tag);
    $page_type        = 'website';
    $published_time   = '';
    $keywords         = htmlspecialchars($filter_tag) . ', AI, OSS, GitHub';
} else {
    $page_title       = 'AI OSS Timeline | ' . $SITE_NAME;
    $page_description = 'GitHub Trendingから厳選したAI系OSSプロジェクトの紹介とAI考察。毎日更新。';
    $page_url         = $BASE_URL . '/' . $THIS_FILE;
    $page_type        = 'website';
    $published_time   = '';
    $keywords         = 'AI, OSS, GitHub, オープンソース, 機械学習, LLM, エージェント';
}

if ($detail_post) {
    $jsonld = array(
        '@context'      => 'https://schema.org',
        '@type'         => 'TechArticle',
        'headline'      => $detail_post['title'],
        'description'   => mb_substr(strip_tags($detail_post['post_text']), 0, 160),
        'url'           => $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['id']),
        'datePublished' => isset($detail_post['created_at']) ? $detail_post['created_at'] : '',
        'author'        => array('@type' => 'Person', 'name' => 'xb_bittensor'),
        'publisher'     => array('@type' => 'Organization', 'name' => $SITE_NAME),
        'keywords'      => !empty($detail_post['tags']) ? implode(', ', $detail_post['tags']) : '',
        'sameAs'        => isset($detail_post['github_url']) ? $detail_post['github_url'] : ''
    );
} else {
    $jsonld = array(
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        'name'        => $page_title,
        'description' => $page_description,
        'url'         => $page_url,
        'publisher'   => array('@type' => 'Organization', 'name' => $SITE_NAME)
    );
}
?>
<!DOCTYPE html>
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
<meta property="og:type" content="<?php echo $page_type; ?>">
<meta property="og:title" content="<?php echo $page_title; ?>">
<meta property="og:description" content="<?php echo $page_description; ?>">
<meta property="og:url" content="<?php echo $page_url; ?>">
<meta property="og:site_name" content="<?php echo $SITE_NAME; ?>">
<meta property="og:locale" content="ja_JP">
<?php if ($detail_post && $published_time): ?>
<meta property="article:published_time" content="<?php echo $published_time; ?>">
<meta property="article:author" content="xb_bittensor">
<?php if (!empty($detail_post['tags'])): ?>
<?php foreach ($detail_post['tags'] as $tag): ?>
<meta property="article:tag" content="<?php echo htmlspecialchars($tag); ?>">
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
<meta property="og:image" content="<?php echo $BASE_URL; ?>/images/oss.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?php echo $BASE_URL; ?>/images/oss.png">
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
.header .badge { background: #6c63ff; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.header a { text-decoration: none; color: inherit; }
.back-btn {
    margin-left: auto;
    font-size: 13px;
    color: #6c63ff;
    text-decoration: none;
    padding: 5px 12px;
    border: 1px solid #6c63ff;
    border-radius: 6px;
}
.back-btn:hover { background: #f0eeff; }
.userbar { display: flex; align-items: center; gap: .75rem; font-size: .8rem; margin-left: auto; }
.userbar strong { color: #059669; }
.btn-sm { border: 1px solid #cbd5e1; padding: 3px 10px; border-radius: 4px; color: #64748b; text-decoration: none; font-size: .75rem; }
.btn-sm:hover { border-color: #dc2626; color: #dc2626; }
.btn-login-sm { border: 1px solid #6c63ff; padding: 4px 12px; border-radius: 4px; color: #6c63ff; text-decoration: none; font-size: .75rem; }
.btn-login-sm:hover { background: #f0eeff; }

/* 管理者フォーム */
.admin-form {
    background: #f7f6ff;
    border-bottom: 2px solid #6c63ff;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.admin-form-label { font-size: 12px; color: #6c63ff; font-weight: 700; white-space: nowrap; }
.admin-form input[type=text] {
    flex: 1;
    min-width: 200px;
    border: 1px solid #c0b8f0;
    border-radius: 6px;
    padding: 7px 12px;
    font-size: 13px;
    outline: none;
}
.admin-form input[type=text]:focus { border-color: #6c63ff; }
.admin-register-btn {
    background: #6c63ff;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 7px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s;
}
.admin-register-btn:hover { background: #5a52d5; }
.admin-register-btn:disabled { background: #bbb; cursor: not-allowed; }
.admin-status { font-size: 12px; padding: 4px 10px; border-radius: 4px; display: none; }
.admin-status.ok { background: #dcfce7; color: #166534; display: inline-block; }
.admin-status.err { background: #fee2e2; color: #991b1b; display: inline-block; }
.admin-status.loading { background: #f0eeff; color: #6c63ff; display: inline-block; }

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
    background: #f0f0f0;
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    padding: 3px 12px;
    font-size: 12px;
    color: #555;
    text-decoration: none;
    display: inline-block;
    transition: all 0.15s;
}
.tag-btn:hover { border-color: #6c63ff; color: #6c63ff; }
.tag-btn.active { background: #6c63ff; border-color: #6c63ff; color: #fff; }

.container { max-width: 640px; margin: 0 auto; padding: 0 0 80px; }
.count-bar { padding: 10px 20px; font-size: 13px; color: #888; border-bottom: 1px solid #f0f0f0; }

.post-card { border-bottom: 1px solid #f0f0f0; padding: 20px; transition: background 0.15s; }
.post-card:hover { background: #fafafa; }

.post-meta { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.avatar {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, #6c63ff, #3ecfcf);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 14px; color: #fff;
    flex-shrink: 0;
}
.author-name { font-weight: 700; color: #111; font-size: 14px; }
.author-handle { color: #888; font-size: 13px; }
.post-time { color: #aaa; font-size: 12px; margin-left: auto; }
.btn-group { display: flex; gap: 6px; flex-shrink: 0; }

.copy-btn {
    background: none;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 12px;
    color: #888;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.copy-btn:hover { border-color: #6c63ff; color: #6c63ff; }
.copy-btn.copied { border-color: #22c55e; color: #22c55e; }

.x-btn {
    background: #000;
    border: 1px solid #000;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 12px;
    color: #fff;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: background 0.15s;
    white-space: nowrap;
}
.x-btn:hover { background: #333; }

.post-title { font-size: 15px; font-weight: 700; color: #111; margin-bottom: 8px; }
.post-title a { color: #111; text-decoration: none; }
.post-title a:hover { color: #6c63ff; }

.post-text { font-size: 14px; line-height: 1.75; color: #333; margin-bottom: 12px; white-space: pre-wrap; }

.analysis-block {
    background: #f7f6ff;
    border-left: 3px solid #6c63ff;
    border-radius: 0 8px 8px 0;
    padding: 12px 14px;
    margin-bottom: 12px;
    font-size: 13px;
    line-height: 1.75;
    color: #444;
    white-space: pre-line;
}
.analysis-label { font-size: 11px; color: #6c63ff; font-weight: 700; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }

.github-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f5f5f5;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 7px 14px;
    text-decoration: none;
    color: #6c63ff;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.15s;
    margin-bottom: 12px;
    word-break: break-all;
}
.github-link:hover { background: #eeecff; border-color: #6c63ff; }

.detail-link {
    display: inline-flex;
    align-items: center;
    background: #f5f5f5;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 7px 14px;
    text-decoration: none;
    color: #888;
    font-size: 12px;
    transition: all 0.15s;
    margin-bottom: 12px;
    margin-left: 8px;
}
.detail-link:hover { background: #f0eeff; border-color: #6c63ff; color: #6c63ff; }

.tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.tag {
    background: #f0f0f0;
    color: #666;
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 20px;
    text-decoration: none;
    display: inline-block;
}
.tag:hover { background: #eeecff; color: #6c63ff; }

.empty { text-align: center; color: #bbb; padding: 80px 20px; font-size: 15px; }

.detail-header { padding: 24px 20px 16px; border-bottom: 1px solid #f0f0f0; }
.detail-header h1 { font-size: 20px; font-weight: 700; color: #111; margin-bottom: 8px; }
.detail-meta { font-size: 13px; color: #888; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.detail-body { padding: 20px; }
.detail-url-box {
    background: #f7f6ff;
    border: 1px solid #e0dcff;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
    font-size: 13px;
    color: #555;
    word-break: break-all;
}
.detail-url-box a { color: #6c63ff; }
.detail-section-title { font-size: 12px; font-weight: 700; color: #6c63ff; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; margin-top: 20px; }
.detail-post-text { font-size: 15px; line-height: 1.8; color: #222; white-space: pre-wrap; margin-bottom: 8px; }
.detail-analysis {
    background: #f7f6ff;
    border-left: 3px solid #6c63ff;
    border-radius: 0 8px 8px 0;
    padding: 14px 16px;
    font-size: 14px;
    line-height: 1.8;
    color: #444;
    white-space: pre-line;
}
.detail-btn-group { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
.detail-copy-btn {
    background: #6c63ff;
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-size: 14px;
    color: #fff;
    cursor: pointer;
    transition: background 0.15s;
}
.detail-copy-btn:hover { background: #5a52d5; }
.detail-copy-btn.copied { background: #22c55e; }
.detail-x-btn {
    background: #000;
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-size: 14px;
    color: #fff;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s;
}
.detail-x-btn:hover { background: #333; }

.para-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 12px;
    color: #166534;
    text-decoration: none;
    white-space: nowrap;
}
.para-badge:hover { background: #dcfce7; }
.para-post-btn {
    background: none;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 12px;
    color: #888;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.para-post-btn:hover { border-color: #16a34a; color: #16a34a; }
.para-post-btn:disabled { opacity: 0.5; cursor: not-allowed; }

#copy-toast {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    background: #111;
    color: #fff;
    padding: 10px 22px;
    border-radius: 20px;
    font-size: 13px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
    z-index: 999;
}
#copy-toast.show { opacity: 1; }
</style>
</head>
<body>

<div class="header">
    <div style="font-size:22px">🦉</div>
    <?php if ($detail_post): ?>
    <h1 style="font-size:17px;font-weight:700;color:#111;"><a href="oss.php" style="text-decoration:none;color:inherit;">OSS</a></h1>
    <span class="badge">URL2AI</span>
    <a class="back-btn" href="oss.php">← 一覧</a>
    <?php elseif ($filter_tag): ?>
    <h1 style="font-size:17px;font-weight:700;color:#111;"><a href="oss.php" style="text-decoration:none;color:inherit;">OSS</a></h1>
    <span class="badge">#<?php echo htmlspecialchars($filter_tag); ?></span>
    <a class="back-btn" href="oss.php">← 一覧</a>
    <?php else: ?>
    <h1>OSS</h1>
    <span class="badge">URL2AI</span>
    <?php endif; ?>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <strong>@<?php echo htmlspecialchars($session_user); ?></strong>
        <a href="?oss_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?oss_login=1" class="btn-login-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_admin && !$detail_post): ?>
<!-- ========== 管理者：手動登録フォーム ========== -->
<div class="admin-form">
    <span class="admin-form-label">🔧 手動登録</span>
    <input type="text" id="admin-url-input" placeholder="https://github.com/user/repo">
    <button class="admin-register-btn" id="admin-register-btn" onclick="adminRegister()">登録</button>
    <span class="admin-status" id="admin-status"></span>
</div>
<?php endif; ?>

<?php if ($detail_post): ?>
<!-- ========== 詳細ページ ========== -->
<div class="container">
    <div class="detail-header">
        <h1><?php echo htmlspecialchars($detail_post['title']); ?></h1>
        <div class="detail-meta">
            <span>@<?php echo htmlspecialchars($detail_post['author']); ?></span>
            <span><?php echo htmlspecialchars($detail_post['created_at']); ?></span>
            <span style="font-size:11px;color:#ccc;"><?php echo htmlspecialchars($detail_post['id']); ?></span>
        </div>
    </div>
    <div class="detail-body">

        <div class="detail-url-box">
            🔗 GitHub: <a href="<?php echo htmlspecialchars($detail_post['github_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($detail_post['github_url']); ?></a>
        </div>

        <?php if (!empty($detail_post['post_text'])): ?>
        <div class="detail-section-title">📢 X投稿文</div>
        <div class="detail-post-text"><?php echo htmlspecialchars($detail_post['post_text']); ?></div>
        <?php endif; ?>

        <?php if (!empty($detail_post['analysis'])): ?>
        <div class="detail-section-title">🤖 AI考察</div>
        <div class="detail-analysis"><?php echo htmlspecialchars($detail_post['analysis']); ?></div>
        <?php endif; ?>

        <?php if (!empty($detail_post['tags'])): ?>
        <div class="detail-section-title">タグ</div>
        <div class="tags" style="margin-top:8px;">
            <?php foreach ($detail_post['tags'] as $tag): ?>
            <a class="tag" href="oss.php?tag=<?php echo urlencode($tag); ?>" rel="tag">#<?php echo htmlspecialchars($tag); ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="detail-btn-group">
            <button class="detail-copy-btn" onclick="copyDetail()">📋 コピー</button>
            <a class="detail-x-btn" id="detail-x-link" href="#" target="_blank" rel="noopener">𝕏 Xに投稿</a>
        </div>

    </div>
</div>

<script>
var detailPost    = <?php echo json_encode($detail_post, JSON_UNESCAPED_UNICODE); ?>;
var detailPageUrl = '<?php echo $BASE_URL; ?>/oss.php?id=<?php echo urlencode($detail_post['id']); ?>';

function buildDetailText(post) {
    var lines = [];
    lines.push('#URL2AI OSS');
    lines.push(post.title);
    lines.push('');
    if (post.post_text) {
        var textOnly = post.post_text.replace(/https?:\/\/\S+/g, '').trim();
        if (textOnly) lines.push(textOnly);
    }
    if (post.analysis) {
        lines.push('');
        lines.push('【AI考察】');
        var analysisOnly = post.analysis.replace(/https?:\/\/\S+/g, '').trim();
        if (analysisOnly) lines.push(analysisOnly);
    }
    lines.push('');
    lines.push(post.github_url);
    lines.push(detailPageUrl);
    if (post.tags && post.tags.length) {
        lines.push(post.tags.map(function(t){ return '#' + t; }).join(' '));
    }
    return lines.join('\n');
}

function buildXText(post) {
    var lines = [];
    if (post.post_text) {
        var textOnly = post.post_text.replace(/https?:\/\/\S+/g, '').trim();
        if (textOnly) lines.push(textOnly);
    }
    lines.push(detailPageUrl);
    return lines.join('\n');
}

function copyDetail() {
    var text = buildDetailText(detailPost);
    navigator.clipboard.writeText(text).then(function() {
        var btn = document.querySelector('.detail-copy-btn');
        btn.textContent = '✓ コピー済';
        btn.classList.add('copied');
        setTimeout(function() {
            btn.textContent = '📋 コピー';
            btn.classList.remove('copied');
        }, 2000);
        showToast('コピーしました');
    });
}

(function() {
    var xText = buildXText(detailPost);
    document.getElementById('detail-x-link').href =
        'https://twitter.com/intent/tweet?text=' + encodeURIComponent(xText);
})();

function showToast(msg) {
    var t = document.getElementById('copy-toast');
    t.textContent = msg;
    t.classList.add('show');
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
    <a class="tag-btn <?php echo !$filter_tag ? 'active' : ''; ?>" href="oss.php">すべて</a>
    <?php foreach ($all_tags as $tag => $count): ?>
    <a class="tag-btn <?php echo $filter_tag === $tag ? 'active' : ''; ?>" href="oss.php?tag=<?php echo urlencode($tag); ?>" rel="tag">
        #<?php echo htmlspecialchars($tag); ?> <span style="opacity:0.6"><?php echo $count; ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="container">

<div class="count-bar">
    <?php echo count($filtered_posts); ?> posts
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
var IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
var PAGE_SIZE = 30;
var currentPage = 0;

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderPosts(from, to) {
    var list = document.getElementById('post-list');
    for (var i = from; i < to && i < posts.length; i++) {
        var post = posts[i];
        var idx  = i;
        var tags = '';
        if (post.tags && post.tags.length) {
            for (var t = 0; t < post.tags.length; t++) {
                tags += '<a class="tag" href="oss.php?tag=' + encodeURIComponent(post.tags[t]) + '" rel="tag">#' + esc(post.tags[t]) + '</a>';
            }
        }
        var analysis = post.analysis ? '<div class="analysis-block"><div class="analysis-label">🤖 AI考察</div>' + esc(post.analysis) + '</div>' : '';
        var postText = post.post_text ? '<div class="post-text">' + esc(post.post_text) + '</div>' : '';
        var html = '<div class="post-card" data-idx="' + idx + '">'
            + '<div class="post-meta">'
            + '<div class="avatar">X</div>'
            + '<div><div class="author-name">' + esc(post.author) + '</div><div class="author-handle">@' + esc(post.author) + '</div></div>'
            + '<div class="post-time">' + esc(post.created_at) + '</div>'
            + '<div class="btn-group">'
            + '<button class="copy-btn" onclick="copyPost(' + idx + ')">コピー</button>'
            + '<a class="x-btn" id="x-btn-' + idx + '" href="#" target="_blank" rel="noopener">𝕏</a>'
            + buildParaBtn(post, idx)
            + '</div></div>'
            + '<div class="post-title"><a href="oss.php?id=' + encodeURIComponent(post.id) + '">' + esc(post.title) + '</a></div>'
            + postText
            + analysis
            + '<a class="github-link" href="' + esc(post.github_url) + '" target="_blank" rel="noopener">⌥ ' + esc(post.github_url) + '</a>'
            + '<a class="detail-link" href="oss.php?id=' + encodeURIComponent(post.id) + '">🔖 詳細</a>'
            + (tags ? '<div class="tags">' + tags + '</div>' : '')
            + '</div>';
        list.insertAdjacentHTML('beforeend', html);
        /* Xボタンのhref設定 */
        var xLink = document.getElementById('x-btn-' + idx);
        if (xLink) { xLink.href = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(buildXText(post)); }
    }
    currentPage++;
}

function loadMore() {
    var from = currentPage * PAGE_SIZE;
    if (from >= posts.length) {
        document.getElementById('load-indicator').style.display = 'none';
        return;
    }
    renderPosts(from, from + PAGE_SIZE);
}

/* IntersectionObserver で最下部検知 */
var sentinel = document.getElementById('load-sentinel');
var observer = new IntersectionObserver(function(entries) {
    if (entries[0].isIntersecting) {
        document.getElementById('load-indicator').style.display = 'block';
        setTimeout(function() {
            loadMore();
            if (currentPage * PAGE_SIZE >= posts.length) {
                document.getElementById('load-indicator').style.display = 'none';
            }
        }, 200);
    }
}, { rootMargin: '200px' });
observer.observe(sentinel);

/* 初回ロード */
if (posts.length === 0) {
    document.getElementById('post-list').innerHTML = '<div class="empty">投稿がありません</div>';
} else {
    loadMore();
}

function getDetailUrl(post) {
    return BASE_URL + '/oss.php?id=' + encodeURIComponent(post.id);
}

function buildPostText(post) {
    var lines = [];
    lines.push('#URL2AI OSS');
    lines.push(post.title);
    lines.push('');
    if (post.post_text) {
        var textOnly = post.post_text.replace(/https?:\/\/\S+/g, '').trim();
        if (textOnly) lines.push(textOnly);
    }
    if (post.analysis) {
        lines.push('');
        lines.push('【AI考察】');
        var analysisOnly = post.analysis.replace(/https?:\/\/\S+/g, '').trim();
        if (analysisOnly) lines.push(analysisOnly);
    }
    lines.push('');
    lines.push(post.github_url);
    lines.push(getDetailUrl(post));
    if (post.tags && post.tags.length) {
        lines.push(post.tags.map(function(t){ return '#' + t; }).join(' '));
    }
    return lines.join('\n');
}

function buildXText(post) {
    var lines = [];
    if (post.post_text) {
        var textOnly = post.post_text.replace(/https?:\/\/\S+/g, '').trim();
        if (textOnly) lines.push(textOnly);
    }
    lines.push(getDetailUrl(post));
    return lines.join('\n');
}

function copyPost(idx) {
    var post = posts[idx];
    if (!post) return;
    navigator.clipboard.writeText(buildPostText(post)).then(function() {
        var btn = document.querySelector('[data-idx="' + idx + '"] .copy-btn');
        if (btn) {
            btn.textContent = '✓ コピー済';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = 'コピー';
                btn.classList.remove('copied');
            }, 2000);
        }
        showToast('コピーしました');
    });
}

<?php if ($is_admin): ?>
function adminRegister() {
    var urlInput = document.getElementById('admin-url-input');
    var btn      = document.getElementById('admin-register-btn');
    var status   = document.getElementById('admin-status');
    var url      = urlInput.value.trim();

    if (!url || url.indexOf('github.com/') === -1) {
        status.textContent = 'GitHubのURLを入力してください';
        status.className   = 'admin-status err';
        return;
    }

    btn.disabled       = true;
    status.textContent = 'AI考察生成中... (1〜2分かかります)';
    status.className   = 'admin-status loading';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'saveoss.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.status === 'ok' || res.status === 'updated') {
                var msg = res.status === 'updated' ? '更新完了: ' : '登録完了: ';
                status.textContent = msg + res.title;
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
            status.textContent = '通信エラー';
            status.className   = 'admin-status err';
        }
    };
    xhr.send(JSON.stringify({ action: 'manual_register', github_url: url }));
}

document.getElementById('admin-url-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') adminRegister();
});
<?php endif; ?>

function buildParaBtn(post, idx) {
    if (post.paragraph_url && post.paragraph_url.indexOf('aiknowledgecms.exbridge.jp') === -1 && (post.paragraph_url.indexOf('paragraph.com') !== -1 || post.paragraph_url.indexOf('paragraph.xyz') !== -1)) {
        return '<a class="para-badge" href="' + esc(post.paragraph_url) + '" target="_blank" rel="noopener">✅ Paragraph</a>';
    }
    if (post.paragraph_post_id) {
        return '<span class="para-badge">✅ Paragraph</span>';
    }
    if (IS_ADMIN) {
        return '<button class="para-post-btn" id="para-btn-' + idx + '" onclick="paraPost(' + idx + ')">📝 Paragraph</button>';
    }
    return '';
}

function paraPost(idx) {
    var post = posts[idx];
    if (!post) return;
    var btn = document.getElementById('para-btn-' + idx);
    btn.disabled = true;
    btn.textContent = '生成中...';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'saveoss.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.status === 'ok' && (res.paragraph_url || res.paragraph_post_id)) {
                post.paragraph_url = res.paragraph_url;
                post.paragraph_post_id = res.paragraph_post_id || '';
                if (res.paragraph_url) {
                    btn.outerHTML = '<a class="para-badge" href="' + esc(res.paragraph_url) + '" target="_blank" rel="noopener">✅ Paragraph</a>';
                } else {
                    btn.outerHTML = '<span class="para-badge">✅ Paragraph</span>';
                }
                showToast('Paragraphに投稿しました');
            } else {
                btn.disabled = false;
                btn.textContent = '📝 Paragraph';
                showToast('投稿失敗: ' + (res.error || '不明'));
            }
        } catch(e) {
            btn.disabled = false;
            btn.textContent = '📝 Paragraph';
            showToast('通信エラー');
        }
    };
    xhr.send(JSON.stringify({ action: 'paragraph_post', id: post.id }));
}

function showToast(msg) {
    var t = document.getElementById('copy-toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
</script>

<?php endif; ?>

<div id="copy-toast">コピーしました</div>
</body>
</html>
