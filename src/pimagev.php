<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set("Asia/Tokyo");

if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', AIGM_COOKIE_DOMAIN);
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
}

$DATA_DIR = __DIR__ . '/data';
$BASE_URL = AIGM_BASE_URL;
$THIS_FILE = 'pimagev.php';
$SITE_NAME = 'PImageV';
$ADMIN = AIGM_ADMIN;
$UIMAGE_X402_URL = 'https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/uimage';

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

function piv_base64url($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function piv_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return piv_base64url($bytes);
}
function piv_gen_challenge($verifier) { return piv_base64url(hash('sha256', $verifier, true)); }
function piv_x_post($url, $post_data, $headers) {
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
function piv_x_get($url, $token) {
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "Authorization: Bearer $token\r\nUser-Agent: PImageV/1.0\r\n",
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['piv_logout'])) {
    session_destroy();
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['piv_login'])) {
    $verifier = piv_gen_verifier();
    $challenge = piv_gen_challenge($verifier);
    $state = md5(uniqid('', true));
    $_SESSION['piv_code_verifier'] = $verifier;
    $_SESSION['piv_oauth_state'] = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['piv_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['piv_oauth_state']) {
        $post = http_build_query(array(
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $x_redirect_uri,
            'code_verifier' => $_SESSION['piv_code_verifier'],
            'client_id' => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = piv_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token'] = $data['access_token'];
            $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int) $data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['piv_oauth_state'], $_SESSION['piv_code_verifier']);
            $me = piv_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) {
                $_SESSION['session_username'] = $me['data']['username'];
            }
        }
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

$logged_in = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin = ($session_user === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function piv_x402_payload_json($prompt_text) {
    $payload = array(
        'input_type' => 'prompt',
        'prompt' => $prompt_text,
        'width' => 1024,
        'height' => 1024,
    );
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

$posts = array();
$files = glob($DATA_DIR . '/pimage_*.json');
if ($files) {
    foreach ($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!is_array($d) || empty($d['pimage_path']) || empty($d['prompt_id'])) { continue; }
        $posts[] = $d;
    }
    usort($posts, function($a, $b) {
        $ta = isset($a['pimage_saved_at']) ? $a['pimage_saved_at'] : '';
        $tb = isset($b['pimage_saved_at']) ? $b['pimage_saved_at'] : '';
        return strcmp($tb, $ta);
    });
}

$detail_id = isset($_GET['id']) ? trim($_GET['id']) : '';
$detail_post = null;
if ($detail_id !== '') {
    foreach ($posts as $p) {
        if (isset($p['prompt_id']) && $p['prompt_id'] === $detail_id) {
            $detail_post = $p;
            break;
        }
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($SITE_NAME); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#fff;color:#222;font-family:-apple-system,'Helvetica Neue',sans-serif;font-size:14px}
.header{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 20px;position:sticky;top:0;z-index:100;display:flex;align-items:center;gap:12px}
.header h1{font-size:17px;font-weight:700;color:#111}
.header h1 a{text-decoration:none;color:inherit}
.badge{background:#2563eb;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px}
.back-btn{margin-left:auto;font-size:13px;color:#2563eb;text-decoration:none;padding:5px 12px;border:1px solid #2563eb;border-radius:6px}
.back-btn:hover{background:#eff6ff}
.userbar{margin-left:auto;display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:#64748b}
.userbar strong{color:#059669}
.btn-sm{background:none;border:1px solid #cbd5e1;color:#64748b;padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none}
.btn-sm:hover{border-color:#dc2626;color:#dc2626}
.container{max-width:840px;margin:0 auto;padding:0 0 80px}
.count-bar{padding:10px 20px;font-size:13px;color:#888;border-bottom:1px solid #f0f0f0}
.post-card{border-bottom:1px solid #f0f0f0;padding:20px;transition:background .15s}
.post-card:hover{background:#fafafa}
.post-meta{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.avatar{width:40px;height:40px;background:linear-gradient(135deg,#2563eb,#06b6d4);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0}
.author-name{font-weight:700;color:#111;font-size:14px}
.author-handle{color:#888;font-size:13px}
.post-time{color:#aaa;font-size:12px;margin-left:auto}
.post-id{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;color:#2563eb;margin-bottom:8px;text-decoration:none;display:block}
.post-id:hover{text-decoration:underline}
.preview-image{display:block;width:100%;border-radius:12px;border:1px solid #e5e7eb;margin-bottom:12px}
.x-link{display:inline-flex;align-items:center;gap:6px;background:#f5f5f5;border:1px solid #e5e7eb;border-radius:8px;padding:6px 12px;text-decoration:none;color:#555;font-size:12px;transition:all .15s;margin-top:4px}
.x-link:hover{background:#eff6ff;border-color:#2563eb;color:#2563eb}
.card-links{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.detail-header{padding:24px 20px 16px;border-bottom:1px solid #f0f0f0}
.detail-meta{font-size:13px;color:#888;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px}
.detail-body{padding:20px}
.detail-section-title{font-size:12px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;margin-top:20px}
.detail-prompt{background:#eff6ff;border-left:3px solid #2563eb;border-radius:0 8px 8px 0;padding:16px 18px;font-size:14px;line-height:1.9;color:#222;white-space:pre-wrap;margin-bottom:8px}
.detail-image{display:block;width:100%;border-radius:14px;border:1px solid #e5e7eb;box-shadow:0 10px 30px rgba(15,23,42,.08)}
.x402-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:14px}
.x402-box strong{color:#1d4ed8}
.x402-box code,.x402-box pre{font-family:'JetBrains Mono',monospace}
.x402-box pre{margin-top:8px;background:#fff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;overflow:auto;font-size:12px;line-height:1.6;color:#374151;white-space:pre-wrap}
.empty{text-align:center;color:#bbb;padding:80px 20px;font-size:15px}
.empty a{color:#2563eb;text-decoration:none}
</style>
</head>
<body>
<div class="header">
    <div style="font-size:22px">🧩</div>
    <?php if ($detail_post): ?>
    <h1><a href="<?php echo h($THIS_FILE); ?>">PImageV</a></h1>
    <span class="badge">URL2AI</span>Prompt
    <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
    <?php else: ?>
    <h1>PImageV</h1>
    <span class="badge">URL2AI</span>Prompt
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($session_user); ?></strong></span>
        <a href="?piv_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?piv_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($detail_post): ?>
<div class="container">
    <div class="detail-header">
        <div class="detail-meta">
            <span>@<?php echo h(isset($detail_post['username']) ? $detail_post['username'] : ''); ?></span>
            <span><?php echo h(isset($detail_post['pimage_saved_at']) ? $detail_post['pimage_saved_at'] : ''); ?></span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#ccc;"><?php echo h($detail_post['prompt_id']); ?></span>
        </div>
    </div>
        <div class="detail-body">
        <div class="detail-section-title">🎨 Generated Image by URL2AI Prompt Image</div>
        <?php if (!empty($detail_post['prompt_text'])): ?>
        <div class="x402-box">
            <div><strong>Bankr x402 AIエージェントでの使い方</strong></div>
            <div style="margin-top:6px;font-size:13px;color:#555;">この prompt を `UImage` endpoint に `input_type: "prompt"` で渡します。endpoint: <code><?php echo h($UIMAGE_X402_URL); ?></code></div>
            <pre><?php echo h(piv_x402_payload_json($detail_post['prompt_text'])); ?></pre>
        </div>
        <?php endif; ?>
        <img class="detail-image" src="<?php echo h($BASE_URL . '/' . $detail_post['pimage_path']); ?>" alt="Generated image">
        <?php if (!empty($detail_post['prompt_text'])): ?>
        <div class="detail-section-title">保存済みプロンプト</div>
        <div class="detail-prompt"><?php echo h($detail_post['prompt_text']); ?></div>
        <?php endif; ?>
        <div style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <a href="pimage.php?id=<?php echo urlencode($detail_post['prompt_id']); ?>" class="x-link" style="color:#2563eb;border-color:#bfdbfe;background:#eff6ff;">✏️ 編集して再生成</a>
        </div>
    </div>
</div>
<?php else: ?>
<div class="container">
    <div class="count-bar">
        <?php echo count($posts); ?> 件の Prompt Image 生成画像
        <?php if ($logged_in): ?> — @<?php echo h($session_user); ?><?php endif; ?>
    </div>
    <?php if (empty($posts)): ?>
    <div class="empty">まだ画像がありません。<br><br><a href="pimage.php">PImageで生成する →</a></div>
    <?php else: ?>
    <?php foreach ($posts as $p): ?>
    <div class="post-card">
        <div class="post-meta">
            <div class="avatar"><?php echo h(substr(isset($p['prompt_text']) && $p['prompt_text'] !== '' ? $p['prompt_text'] : 'P', 0, 1)); ?></div>
            <div>
                <div class="author-name"><?php echo h(mb_substr(isset($p['prompt_text']) ? str_replace(array("\r","\n"), ' ', $p['prompt_text']) : $p['prompt_id'], 0, 40)); ?></div>
                <div class="author-handle">@<?php echo h(isset($p['username']) ? $p['username'] : ''); ?></div>
            </div>
            <div class="post-time"><?php echo h(isset($p['pimage_saved_at']) ? $p['pimage_saved_at'] : ''); ?></div>
        </div>
        <a class="post-id" href="pimagev.php?id=<?php echo urlencode($p['prompt_id']); ?>">#<?php echo h($p['prompt_id']); ?></a>
        <?php if (!empty($p['prompt_text'])): ?>
        <div class="x402-box">
            <div><strong>Bankr x402 AIエージェントでの使い方</strong></div>
            <div style="margin-top:6px;font-size:13px;color:#555;">この prompt を `UImage` endpoint に送ります。</div>
            <pre><?php echo h(piv_x402_payload_json($p['prompt_text'])); ?></pre>
        </div>
        <?php endif; ?>
        <a href="pimagev.php?id=<?php echo urlencode($p['prompt_id']); ?>"><img class="preview-image" src="<?php echo h($BASE_URL . '/' . $p['pimage_path']); ?>" alt="Generated image"></a>
        <div class="card-links">
            <a href="pimage.php?id=<?php echo urlencode($p['prompt_id']); ?>" class="x-link">✏️ 編集</a>
            <a href="pimagev.php?id=<?php echo urlencode($p['prompt_id']); ?>" class="x-link" style="color:#2563eb;border-color:#bfdbfe;background:#eff6ff;">🎨 詳細</a>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
