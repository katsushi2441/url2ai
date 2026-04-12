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

$BASE_URL  = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE = 'knowradar.php';
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
function kr_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function kr_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return kr_base64url($bytes);
}
function kr_gen_challenge($verifier) {
    return kr_base64url(hash('sha256', $verifier, true));
}
function kr_x_post($url, $post_data, $headers) {
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
function kr_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: KnowRader/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['kr_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/',
        'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['kr_login'])) {
    $verifier  = kr_gen_verifier();
    $challenge = kr_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['kr_code_verifier'] = $verifier;
    $_SESSION['kr_oauth_state']   = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['kr_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['kr_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['kr_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = kr_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']  = $data['access_token'];
            $_SESSION['session_token_expires']  = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['kr_oauth_state'], $_SESSION['kr_code_verifier']);
            $me = kr_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) {
                $_SESSION['session_username'] = $me['data']['username'];
            }
        }
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

/* アクセストークン自動リフレッシュ */
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
    $ref = kr_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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
$is_admin  = ($username === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* =========================================================
   サービス定義
========================================================= */
$services = array(
    array(
        'id'       => 'ustory',
        'name'     => 'UStory',
        'name_ja'  => 'AI短編小説',
        'emoji'    => '📖',
        'color'    => '#7c3aed',
        'bg'       => '#f5f3ff',
        'view_url' => $BASE_URL . '/ustoryv.php',
        'edit_url' => $BASE_URL . '/ustory.php',
        'feed_url' => $BASE_URL . '/ustoryv.php?feed',
        'desc'     => 'X投稿をAIが短編小説に変換',
    ),
    array(
        'id'       => 'udebate',
        'name'     => 'UDebate AI',
        'name_ja'  => 'AI議論',
        'emoji'    => '⚔️',
        'color'    => '#6d28d9',
        'bg'       => '#ede9fe',
        'view_url' => $BASE_URL . '/udebatev.php',
        'edit_url' => $BASE_URL . '/udebate.php',
        'feed_url' => $BASE_URL . '/udebatev.php?feed',
        'desc'     => '肯定・否定AIが議論し司会AIがまとめ',
    ),
    array(
        'id'       => 'umedia',
        'name'     => 'UMedia',
        'name_ja'  => 'AIメディア考察',
        'emoji'    => '📸',
        'color'    => '#0891b2',
        'bg'       => '#e0f2fe',
        'view_url' => $BASE_URL . '/umediav.php',
        'edit_url' => $BASE_URL . '/umedia.php',
        'feed_url' => $BASE_URL . '/umediav.php?feed',
        'desc'     => 'X投稿の画像・動画をAIが考察',
    ),
    array(
        'id'       => 'xinsight',
        'name'     => 'XInsight',
        'name_ja'  => 'AI考察',
        'emoji'    => '💬',
        'color'    => '#2563eb',
        'bg'       => '#eff6ff',
        'view_url' => $BASE_URL . '/xinsightv.php',
        'edit_url' => $BASE_URL . '/xinsight.php',
        'feed_url' => $BASE_URL . '/xinsightv.php?feed',
        'desc'     => 'X投稿についてAIが深く考察',
    ),
    array(
        'id'       => 'oss',
        'name'     => 'OSS Timeline',
        'name_ja'  => 'AI OSS',
        'emoji'    => '🦉',
        'color'    => '#6c63ff',
        'bg'       => '#f0eeff',
        'view_url' => $BASE_URL . '/oss.php',
        'edit_url' => $BASE_URL . '/oss.php',
        'feed_url' => $BASE_URL . '/oss.php?feed',
        'desc'     => 'GitHub厳選AI系OSSのAI考察',
    ),
    array(
        'id'       => 'osszenn',
        'name'     => 'OSSZenn',
        'name_ja'  => 'OSS × Zenn',
        'emoji'    => '📚',
        'color'    => '#3b82f6',
        'bg'       => '#eff6ff',
        'view_url' => $BASE_URL . '/osszenn.php',
        'edit_url' => $BASE_URL . '/osszenn.php',
        'feed_url' => $BASE_URL . '/osszenn.php?feed',
        'desc'     => 'GitHub OSSとZenn記事のマッチング',
    ),
    array(
        'id'       => 'aitech',
        'name'     => 'AITech Links',
        'name_ja'  => 'AI技術リンク',
        'emoji'    => '🔗',
        'color'    => '#0ea5e9',
        'bg'       => '#f0f9ff',
        'view_url' => $BASE_URL . '/aitech.php',
        'edit_url' => $BASE_URL . '/aitech.php',
        'feed_url' => $BASE_URL . '/aitech.php?feed',
        'desc'     => '技術系サイトのAI要約リンク集',
    ),
    array(
        'id'       => 'usong',
        'name'     => 'USong',
        'name_ja'  => 'AI作詞',
        'emoji'    => '🎵',
        'color'    => '#db2777',
        'bg'       => '#fdf2f8',
        'view_url' => $BASE_URL . '/usongv.php',
        'edit_url' => $BASE_URL . '/usong.php',
        'feed_url' => $BASE_URL . '/usongv.php?feed',
        'desc'     => 'X投稿からAIが歌詞を生成',
    ),
);
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KnowRader — AIGM エコシステムポータル</title>
<meta name="description" content="AIが自動生成・蓄積するコンテンツのポータルサイト。短編小説・AI議論・メディア考察・技術リンクなどを一覧で閲覧できます。">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo h($BASE_URL . '/' . $THIS_FILE); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="KnowRader — AIGM エコシステムポータル">
<meta property="og:description" content="AIが自動生成・蓄積するコンテンツのポータルサイト。">
<meta property="og:url" content="<?php echo h($BASE_URL . '/' . $THIS_FILE); ?>">
<meta property="og:locale" content="ja_JP">
<meta property="og:image" content="<?php echo h($BASE_URL); ?>/images/knowradar.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?php echo h($BASE_URL); ?>/images/knowradar.png">
<meta name="twitter:site" content="@xb_bittensor">
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f8fafc;color:#0f172a;font-family:'Inter',-apple-system,sans-serif;font-size:14px;min-height:100vh;}

/* ヘッダー */
.header{background:#fff;border-bottom:1px solid #e2e8f0;padding:14px 20px;position:sticky;top:0;z-index:100;display:flex;align-items:center;gap:12px;}
.logo{font-size:19px;font-weight:700;letter-spacing:-.03em;color:#0f172a;}
.logo span{color:#6366f1;}
.tagline{font-size:11px;color:#94a3b8;letter-spacing:.04em;}
.userbar{margin-left:auto;display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:#64748b;}
.userbar strong{color:#059669;}
.btn-sm{background:none;border:1px solid #cbd5e1;color:#64748b;padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;}
.btn-sm:hover{border-color:#dc2626;color:#dc2626;}
.btn-login-sm{border:1px solid #6366f1;padding:4px 12px;border-radius:4px;color:#6366f1;text-decoration:none;font-size:.75rem;}
.btn-login-sm:hover{background:#eef2ff;}

/* ヒーロー */
.hero{background:linear-gradient(135deg,#1e1b4b 0%,#312e81 50%,#1e3a5f 100%);color:#fff;padding:40px 20px 36px;text-align:center;}
.hero h2{font-size:22px;font-weight:700;margin-bottom:8px;letter-spacing:-.02em;}
.hero p{font-size:13px;color:rgba(255,255,255,.65);max-width:480px;margin:0 auto;line-height:1.7;}

/* グリッド */
.container{max-width:960px;margin:0 auto;padding:28px 16px 80px;}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;}

/* サービスカード */
.service-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;transition:box-shadow .2s,transform .2s;}
.service-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.08);transform:translateY(-2px);}

.card-header{padding:16px 18px 12px;display:flex;align-items:flex-start;gap:12px;border-bottom:1px solid #f1f5f9;}
.card-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.card-title{flex:1;}
.card-name{font-size:15px;font-weight:700;color:#0f172a;line-height:1.2;margin-bottom:2px;}
.card-name-ja{font-size:11px;color:#64748b;letter-spacing:.03em;}
.card-desc{font-size:11px;color:#94a3b8;margin-top:4px;line-height:1.5;}

.card-links{display:flex;gap:6px;padding:10px 18px;background:#f8fafc;border-bottom:1px solid #f1f5f9;}
.card-link{font-size:11px;font-weight:500;padding:4px 10px;border-radius:6px;text-decoration:none;border:1px solid;transition:all .15s;}
.card-link-view{color:#fff;border-color:transparent;}
.card-link-view:hover{opacity:.85;}
.card-link-edit{color:#64748b;border-color:#e2e8f0;background:#fff;}
.card-link-edit:hover{border-color:#94a3b8;color:#0f172a;}
.rss-badge{font-size:10px;font-weight:700;color:#c44f00;background:#fff5ef;border:1px solid #f5d0b8;border-radius:4px;padding:2px 7px;text-decoration:none;display:inline-flex;align-items:center;gap:3px;margin-left:auto;}

/* フィード */
.feed-body{padding:10px 18px 14px;}
.feed-list{list-style:none;margin:0;padding:0;}
.feed-list li{padding:5px 0;border-bottom:1px solid #f1f5f9;font-size:11px;line-height:1.5;display:flex;align-items:baseline;gap:6px;}
.feed-list li:last-child{border-bottom:none;}
.feed-date{color:#94a3b8;font-size:10px;flex-shrink:0;font-family:'JetBrains Mono',monospace;}
.feed-link{color:#0f172a;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;}
.feed-link:hover{color:#6366f1;}
.feed-loading{font-size:11px;color:#94a3b8;padding:6px 0;}

/* 管理者リンク */
.admin-link{display:inline-flex;align-items:center;gap:4px;font-size:10px;color:#6366f1;background:#eef2ff;border:1px solid #c7d2fe;border-radius:4px;padding:2px 8px;text-decoration:none;margin-left:4px;}
.admin-link:hover{background:#e0e7ff;}

@media(max-width:600px){
    .grid{grid-template-columns:1fr;}
    .hero h2{font-size:18px;}
    .container{padding:20px 12px 60px;}
}
</style>
</head>
<body>

<div class="header">
    <div class="logo">Know<span>Rader</span></div>
    <div class="tagline">AIGM Ecosystem Portal</div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?kr_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?kr_login=1" class="btn-login-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</div>

<div class="hero">
    <h2>🤖 AIGM エコシステム</h2>
    <p>AIが自動生成・蓄積するコンテンツのポータル。短編小説・議論・メディア考察・技術リンクなどを一覧で閲覧できます。</p>
</div>

<div class="container">
    <div class="grid" id="service-grid">

    <?php foreach ($services as $svc): ?>
    <div class="service-card">

        <div class="card-header">
            <div class="card-icon" style="background:<?php echo h($svc['bg']); ?>">
                <?php echo $svc['emoji']; ?>
            </div>
            <div class="card-title">
                <div class="card-name"><?php echo h($svc['name']); ?></div>
                <div class="card-name-ja"><?php echo h($svc['name_ja']); ?></div>
                <div class="card-desc"><?php echo h($svc['desc']); ?></div>
            </div>
        </div>

        <div class="card-links">
            <a class="card-link card-link-view"
               href="<?php echo h($svc['view_url']); ?>"
               style="background:<?php echo h($svc['color']); ?>;"
               target="_blank">一覧を見る</a>
            <?php if ($is_admin && $svc['edit_url'] !== $svc['view_url']): ?>
            <a class="admin-link"
               href="<?php echo h($svc['edit_url']); ?>"
               target="_blank">✏️ 登録</a>
            <?php endif; ?>
            <a class="rss-badge"
               href="<?php echo h($svc['feed_url']); ?>"
               target="_blank">
                <svg width="9" height="9" viewBox="0 0 8 8"><circle cx="1.5" cy="6.5" r="1.5" fill="#c44f00"/><path d="M0 4.5A3.5 3.5 0 013.5 8" stroke="#c44f00" stroke-width="1.2" fill="none"/><path d="M0 2A6 6 0 016 8" stroke="#c44f00" stroke-width="1.2" fill="none"/></svg>
                RSS
            </a>
        </div>

        <div class="feed-body">
            <ul class="feed-list" id="feed-<?php echo h($svc['id']); ?>">
                <li><span class="feed-loading">読み込み中...</span></li>
            </ul>
        </div>

    </div>
    <?php endforeach; ?>

    </div><!-- /.grid -->
</div><!-- /.container -->

<script>
var services = <?php echo json_encode(array_map(function($s) {
    return array('id' => $s['id'], 'feed_url' => $s['feed_url'], 'view_url' => $s['view_url']);
}, $services), JSON_UNESCAPED_UNICODE); ?>;

function krLoadFeed(id, feedUrl) {
    var ul = document.getElementById('feed-' + id);
    if (!ul) return;
    fetch(feedUrl)
        .then(function(r) { return r.text(); })
        .then(function(txt) {
            var parser = new DOMParser();
            var xml = parser.parseFromString(txt, 'application/xml');
            var items = xml.querySelectorAll('item');
            var html = '';
            var count = 0;
            for (var i = 0; i < items.length && count < 5; i++) {
                var titleEl = items[i].querySelector('title');
                var linkEl  = items[i].querySelector('link');
                var pubEl   = items[i].querySelector('pubDate');
                if (!titleEl || !linkEl) continue;
                var t = titleEl.textContent.trim();
                var l = linkEl.textContent.trim();
                var d = '';
                if (pubEl) {
                    var dt = new Date(pubEl.textContent.trim());
                    if (!isNaN(dt)) { d = (dt.getMonth()+1) + '/' + dt.getDate(); }
                }
                if (t.length > 38) t = t.substring(0, 38) + '…';
                html += '<li>'
                    + (d ? '<span class="feed-date">' + d + '</span>' : '')
                    + '<a class="feed-link" href="' + l + '" target="_blank">' + t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</a>'
                    + '</li>';
                count++;
            }
            if (html === '') {
                html = '<li><span class="feed-loading">データなし</span></li>';
            }
            ul.innerHTML = html;
        })
        .catch(function() {
            ul.innerHTML = '<li><span class="feed-loading">取得できませんでした</span></li>';
        });
}

/* 少しずつ遅延して読み込み（サーバー負荷分散） */
services.forEach(function(svc, i) {
    setTimeout(function() {
        krLoadFeed(svc.id, svc.feed_url);
    }, i * 300);
});
</script>
</body>
</html>