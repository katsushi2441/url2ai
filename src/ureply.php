<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');
require_once __DIR__ . '/config.php';

$BASE_URL  = AIGM_BASE_URL;
$THIS_FILE = 'ureply.php';
$ADMIN     = AIGM_ADMIN;
$DATA_DIR  = __DIR__ . '/data';

/* ── セッション ── */
if (session_status() === PHP_SESSION_NONE) {
    $sl = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string)$sl);
    ini_set('session.cookie_lifetime', (string)$sl);
    ini_set('session.cookie_path',    '/');
    ini_set('session.cookie_domain',  'aiknowledgecms.exbridge.jp');
    ini_set('session.cookie_secure',  '1');
    ini_set('session.cookie_httponly','1');
    session_cache_expire(60 * 24 * 30);
    session_start();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), time() + $sl, '/',
            'aiknowledgecms.exbridge.jp', true, true);
    }
}

/* ── X API キー読み込み ── */
$x_keys = [];
$xk_file = __DIR__ . '/x_api_keys.sh';
if (file_exists($xk_file)) {
    foreach (file($xk_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $ln, $m)) {
            $x_keys[trim($m[1])] = trim($m[2]);
        }
    }
}
$x_client_id     = $x_keys['X_API_KEY']             ?? '';
$x_client_secret = $x_keys['X_API_SECRET']          ?? '';
$o1_key          = $x_keys['X_API_KEY']              ?? '';
$o1_secret       = $x_keys['X_API_KEY_SECRET']       ?? '';
$o1_token        = $x_keys['X_ACCESS_TOKEN']         ?? '';
$o1_token_secret = $x_keys['X_ACCESS_TOKEN_SECRET']  ?? '';

/* ── OAuth2 PKCE（ログイン用） ── */
function ur_b64url(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function ur_http_post(string $url, string $data, array $headers): array {
    $opts = ['http' => ['method' => 'POST',
        'header'  => implode("\r\n", $headers) . "\r\n",
        'content' => $data, 'timeout' => 15, 'ignore_errors' => true]];
    $r = @file_get_contents($url, false, stream_context_create($opts));
    return json_decode($r ?: '{}', true) ?: [];
}
function ur_http_get(string $url, string $token): array {
    $opts = ['http' => ['method' => 'GET',
        'header'  => "Authorization: Bearer $token\r\nUser-Agent: UReply/1.0\r\n",
        'timeout' => 12, 'ignore_errors' => true]];
    $r = @file_get_contents($url, false, stream_context_create($opts));
    return json_decode($r ?: '{}', true) ?: [];
}

$redir = $BASE_URL . '/' . $THIS_FILE;
if (isset($_GET['ur_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/', 'aiknowledgecms.exbridge.jp', true, true);
    header("Location: $redir"); exit;
}
if (isset($_GET['ur_login'])) {
    $ver = ur_b64url(random_bytes(32));
    $chal = ur_b64url(hash('sha256', $ver, true));
    $state = bin2hex(random_bytes(8));
    $_SESSION['ur_ver'] = $ver; $_SESSION['ur_state'] = $state;
    $p = http_build_query([
        'response_type' => 'code', 'client_id' => $x_client_id,
        'redirect_uri' => $redir, 'scope' => 'tweet.read users.read offline.access',
        'state' => $state, 'code_challenge' => $chal, 'code_challenge_method' => 'S256',
    ]);
    header("Location: https://twitter.com/i/oauth2/authorize?$p"); exit;
}
if (isset($_GET['code'], $_GET['state'], $_SESSION['ur_state']) && $_GET['state'] === $_SESSION['ur_state']) {
    $cred = base64_encode("$x_client_id:$x_client_secret");
    $data = ur_http_post('https://api.twitter.com/2/oauth2/token',
        http_build_query(['grant_type' => 'authorization_code', 'code' => $_GET['code'],
            'redirect_uri' => $redir, 'code_verifier' => $_SESSION['ur_ver'], 'client_id' => $x_client_id]),
        ['Content-Type: application/x-www-form-urlencoded', "Authorization: Basic $cred"]);
    if (!empty($data['access_token'])) {
        $_SESSION['session_access_token']  = $data['access_token'];
        $_SESSION['session_token_expires'] = time() + (int)($data['expires_in'] ?? 7200);
        $_SESSION['session_refresh_token'] = $data['refresh_token'] ?? '';
        $me = ur_http_get('https://api.twitter.com/2/users/me', $data['access_token']);
        $_SESSION['session_username'] = $me['data']['username'] ?? '';
    }
    unset($_SESSION['ur_ver'], $_SESSION['ur_state']);
    header("Location: $redir"); exit;
}
if (!empty($_SESSION['session_refresh_token']) && !empty($_SESSION['session_token_expires'])
    && time() > $_SESSION['session_token_expires'] - 300) {
    $cred_r = base64_encode("$x_client_id:$x_client_secret");
    $ref = ur_http_post('https://api.twitter.com/2/oauth2/token',
        http_build_query(['grant_type' => 'refresh_token',
            'refresh_token' => $_SESSION['session_refresh_token'], 'client_id' => $x_client_id]),
        ['Content-Type: application/x-www-form-urlencoded', "Authorization: Basic $cred_r"]);
    if (!empty($ref['access_token'])) {
        $_SESSION['session_access_token']  = $ref['access_token'];
        $_SESSION['session_token_expires'] = time() + (int)($ref['expires_in'] ?? 7200);
        $_SESSION['session_refresh_token'] = $ref['refresh_token'] ?? '';
    }
}

$logged_in    = !empty($_SESSION['session_access_token']);
$session_user = $_SESSION['session_username'] ?? '';
$is_admin     = ($session_user === $ADMIN);

/* ── OAuth1.0a 署名（投稿用） ── */
function ur_oauth1_header(string $method, string $url,
    string $k, string $s, string $t, string $ts): string {
    $o = [
        'oauth_consumer_key'     => $k,
        'oauth_nonce'            => bin2hex(openssl_random_pseudo_bytes(16)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp'        => (string)time(),
        'oauth_token'            => $t,
        'oauth_version'          => '1.0',
    ];
    ksort($o);
    $base = $method . '&' . rawurlencode($url) . '&' . rawurlencode(http_build_query($o));
    $key  = rawurlencode($s) . '&' . rawurlencode($ts);
    $o['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));
    $parts = [];
    foreach ($o as $ok => $ov) { $parts[] = rawurlencode($ok) . '="' . rawurlencode($ov) . '"'; }
    return 'OAuth ' . implode(', ', $parts);
}

function ur_post_tweet(string $text, string $reply_id, string $quote_id,
    string $k, string $s, string $t, string $ts): array {
    $api = 'https://api.twitter.com/2/tweets';
    $payload = ['text' => $text];
    if ($reply_id !== '')  { $payload['reply'] = ['in_reply_to_tweet_id' => $reply_id]; }
    if ($quote_id  !== '')  { $payload['quote_tweet_id'] = $quote_id; }
    $auth = ur_oauth1_header('POST', $api, $k, $s, $t, $ts);
    $opts = ['http' => ['method' => 'POST',
        'header'  => "Authorization: $auth\r\nContent-Type: application/json\r\nUser-Agent: UReply/1.0\r\n",
        'content' => json_encode($payload), 'timeout' => 20, 'ignore_errors' => true]];
    $r = @file_get_contents($api, false, stream_context_create($opts));
    return json_decode($r ?: '{}', true) ?: [];
}

/* ── データ保存 ── */
function ur_save(array $d): void {
    global $DATA_DIR;
    $id = 'ureply_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6);
    $d['id'] = $id;
    $path = $DATA_DIR . '/' . $id . '.json';
    file_put_contents($path, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/* ── AJAX ハンドラー ── */
if (isset($_POST['action'])) {
    header('Content-Type: application/json; charset=UTF-8');

    if (!$is_admin) {
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    /* 返信文生成 */
    if ($_POST['action'] === 'generate') {
        $tweet_url = trim($_POST['tweet_url'] ?? '');
        if (!preg_match('/(\d{10,20})/', $tweet_url, $m)) {
            echo json_encode(['ok' => false, 'error' => 'URLが不正です']); exit;
        }
        $tweet_id = $m[1];

        /* fxtwitter でツイート取得 */
        $fx_url = 'https://api.fxtwitter.com/i/status/' . $tweet_id;
        $fx_raw = @file_get_contents($fx_url, false,
            stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true,
                'header' => "User-Agent: UReply/1.0\r\n"]]));
        $fx = json_decode($fx_raw ?: '{}', true) ?: [];
        $tweet_text   = $fx['tweet']['text']                   ?? '';
        $tweet_author = $fx['tweet']['author']['screen_name']  ?? '';
        if (!$tweet_text) {
            echo json_encode(['ok' => false, 'error' => 'ツイートを取得できませんでした']); exit;
        }

        /* Ollama で返信生成 */
        $prompt = <<<PROMPT
以下のXの投稿に対する返信を生成してください。

条件：
- 200字以内
- 自然な日本語
- 投稿内容に関連した内容
- ポジティブで建設的
- 末尾にハッシュタグを1つだけ追加

投稿：
{$tweet_text}

返信文のみを出力してください。前置きや説明は不要です。
PROMPT;

        $payload = json_encode([
            'model'   => OLLAMA_MODEL,
            'prompt'  => $prompt,
            'stream'  => false,
            'options' => ['temperature' => 0.7, 'num_ctx' => 2048],
        ]);
        $ol_raw = @file_get_contents(OLLAMA_API, false,
            stream_context_create(['http' => ['method' => 'POST', 'timeout' => 60,
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload, 'ignore_errors' => true]]));
        $ol = json_decode($ol_raw ?: '{}', true) ?: [];
        $reply = trim($ol['response'] ?? '');
        if (!$reply) {
            echo json_encode(['ok' => false, 'error' => 'AI生成に失敗しました']); exit;
        }

        echo json_encode([
            'ok'           => true,
            'reply'        => $reply,
            'tweet_text'   => $tweet_text,
            'tweet_author' => $tweet_author,
            'tweet_id'     => $tweet_id,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* 投稿 */
    if ($_POST['action'] === 'post') {
        $reply_text = trim($_POST['reply_text'] ?? '');
        $tweet_id   = preg_replace('/[^0-9]/', '', $_POST['tweet_id'] ?? '');
        $post_type  = $_POST['post_type'] ?? 'reply'; // reply | quote

        if (!$reply_text || !$tweet_id) {
            echo json_encode(['ok' => false, 'error' => 'パラメータ不足']); exit;
        }

        $reply_id = ($post_type === 'reply') ? $tweet_id : '';
        $quote_id = ($post_type === 'quote') ? $tweet_id : '';
        $result = ur_post_tweet($reply_text, $reply_id, $quote_id,
            $o1_key, $o1_secret, $o1_token, $o1_token_secret);

        if (empty($result['data']['id'])) {
            $err = $result['detail'] ?? $result['title'] ?? json_encode($result);
            echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE); exit;
        }

        $posted_id  = $result['data']['id'];
        $posted_url = "https://x.com/i/status/$posted_id";

        ur_save([
            'tweet_url'     => $_POST['tweet_url'] ?? '',
            'tweet_id'      => $tweet_id,
            'tweet_text'    => $_POST['tweet_text'] ?? '',
            'tweet_author'  => $_POST['tweet_author'] ?? '',
            'reply_text'    => $reply_text,
            'post_type'     => $post_type,
            'posted_tweet_id' => $posted_id,
            'posted_url'    => $posted_url,
            'created_at'    => date('Y-m-d H:i:s'),
            'timestamp'     => time(),
        ]);

        echo json_encode(['ok' => true, 'posted_url' => $posted_url], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

/* ── 過去データ読み込み ── */
$posts = [];
foreach (glob($DATA_DIR . '/ureply_*.json') ?: [] as $f) {
    $p = json_decode(file_get_contents($f), true);
    if (is_array($p) && !empty($p['id'])) { $posts[] = $p; }
}
usort($posts, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$gtag = defined('AIGM_GTAG_ID') ? AIGM_GTAG_ID : '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>UReply — AI返信生成</title>
<?php if ($gtag): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= h($gtag) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= h($gtag) ?>');</script>
<?php endif; ?>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Noto Sans JP',system-ui,sans-serif;background:#f4f6f9;color:#1a1a2e;font-size:15px}
.wrap{max-width:760px;margin:0 auto;padding:16px}
h1{font-size:1.3rem;font-weight:800;color:#0f172a;margin-bottom:4px}
.sub{font-size:.8rem;color:#64748b;margin-bottom:20px}
.card{background:#fff;border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.card-head{font-size:.75rem;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.06em;margin-bottom:12px}
.input-row{display:flex;gap:8px;margin-bottom:10px}
.input-row input{flex:1;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem}
.btn{padding:9px 16px;border:none;border-radius:8px;cursor:pointer;font-size:.85rem;font-weight:600;transition:.15s}
.btn-primary{background:#1d9bf0;color:#fff}
.btn-primary:hover{background:#0d8de1}
.btn-reply{background:#16a34a;color:#fff}
.btn-reply:hover{background:#15803d}
.btn-quote{background:#7c3aed;color:#fff}
.btn-quote:hover{background:#6d28d9}
.btn:disabled{opacity:.5;cursor:not-allowed}
.tweet-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:10px 0;font-size:.85rem;color:#334155}
.tweet-author{font-weight:700;color:#0f172a;margin-bottom:4px}
textarea{width:100%;min-height:100px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;resize:vertical;font-family:inherit}
.counter{font-size:.75rem;color:#94a3b8;text-align:right;margin:4px 0 10px}
.counter.over{color:#ef4444}
.action-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.result-box{margin-top:10px;padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:.85rem}
.result-box a{color:#166534;font-weight:600}
.err-box{margin-top:10px;padding:10px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-size:.85rem;color:#991b1b}
.auth-bar{display:flex;justify-content:flex-end;gap:8px;margin-bottom:14px;font-size:.8rem;align-items:center}
.auth-bar a{color:#1d9bf0;text-decoration:none;font-weight:600}

/* 一覧 */
.post-card{background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.07)}
.post-meta{font-size:.72rem;color:#94a3b8;margin-bottom:6px;display:flex;gap:12px;align-items:center}
.post-meta a{color:#1d9bf0;text-decoration:none}
.badge{display:inline-block;padding:1px 8px;border-radius:20px;font-size:.68rem;font-weight:700}
.badge-reply{background:#dcfce7;color:#166534}
.badge-quote{background:#ede9fe;color:#5b21b6}
.orig-tweet{font-size:.78rem;color:#64748b;background:#f8fafc;border-radius:6px;padding:8px 10px;margin-bottom:8px;border-left:3px solid #cbd5e1}
.orig-tweet strong{color:#0f172a}
.reply-text{font-size:.88rem;color:#1a1a2e;line-height:1.6}
.empty{text-align:center;color:#94a3b8;padding:40px 0;font-size:.9rem}
</style>
</head>
<body>
<div class="wrap">
  <div class="auth-bar">
    <?php if ($logged_in): ?>
      <span>@<?= h($session_user) ?></span>
      <a href="?ur_logout=1">ログアウト</a>
    <?php else: ?>
      <a href="?ur_login=1">Xでログイン</a>
    <?php endif; ?>
  </div>

  <h1>UReply</h1>
  <p class="sub">X投稿URLを入れてAI返信を生成 → そのままXへ返信・引用RT</p>

<?php if ($is_admin): ?>
  <!-- 投稿フォーム（管理者のみ） -->
  <div class="card">
    <div class="card-head">返信を生成して投稿</div>
    <div class="input-row">
      <input type="text" id="tweet-url" placeholder="https://x.com/user/status/..." autocomplete="off">
      <button class="btn btn-primary" id="btn-gen" onclick="doGenerate()">返信生成</button>
    </div>

    <div id="orig-box" style="display:none">
      <div class="tweet-box">
        <div class="tweet-author" id="orig-author"></div>
        <div id="orig-text"></div>
      </div>
      <textarea id="reply-text" placeholder="返信文を編集できます" oninput="updateCounter()"></textarea>
      <div class="counter" id="counter">0 / 280</div>
      <div class="action-row">
        <button class="btn btn-reply" id="btn-reply" onclick="doPost('reply')">返信する</button>
        <button class="btn btn-quote" id="btn-quote" onclick="doPost('quote')">引用RTする</button>
      </div>
      <div id="result-box" style="display:none"></div>
    </div>
  </div>
<?php endif; ?>

  <!-- 過去の返信一覧 -->
  <div class="card">
    <div class="card-head">過去の返信・引用RT（<?= count($posts) ?> 件）</div>
    <?php if (empty($posts)): ?>
      <div class="empty">まだ投稿はありません</div>
    <?php else: ?>
      <?php foreach ($posts as $p): ?>
        <div class="post-card">
          <div class="post-meta">
            <span class="badge <?= ($p['post_type'] ?? 'reply') === 'quote' ? 'badge-quote' : 'badge-reply' ?>">
              <?= ($p['post_type'] ?? 'reply') === 'quote' ? '引用RT' : '返信' ?>
            </span>
            <span><?= h($p['created_at'] ?? '') ?></span>
            <?php if (!empty($p['posted_url'])): ?>
              <a href="<?= h($p['posted_url']) ?>" target="_blank" rel="noopener">Xで見る →</a>
            <?php endif; ?>
          </div>
          <?php if (!empty($p['tweet_text'])): ?>
            <div class="orig-tweet">
              <strong>@<?= h($p['tweet_author'] ?? '') ?></strong>：<?= h(mb_substr($p['tweet_text'], 0, 80)) ?>…
              <?php if (!empty($p['tweet_url'])): ?>
                <a href="<?= h($p['tweet_url']) ?>" target="_blank" rel="noopener" style="font-size:.72rem;color:#1d9bf0">元投稿</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="reply-text"><?= h($p['reply_text'] ?? '') ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
var currentTweetId   = '';
var currentTweetUrl  = '';
var currentTweetText = '';
var currentAuthor    = '';

function doGenerate() {
  var url = document.getElementById('tweet-url').value.trim();
  if (!url) { alert('URLを入力してください'); return; }
  var btn = document.getElementById('btn-gen');
  btn.disabled = true; btn.textContent = '生成中...';
  document.getElementById('orig-box').style.display = 'none';
  document.getElementById('result-box').style.display = 'none';

  fetch(location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=generate&tweet_url=' + encodeURIComponent(url),
  })
  .then(r => r.json())
  .then(d => {
    btn.disabled = false; btn.textContent = '返信生成';
    if (!d.ok) { alert('エラー: ' + d.error); return; }
    currentTweetId   = d.tweet_id;
    currentTweetUrl  = url;
    currentTweetText = d.tweet_text;
    currentAuthor    = d.tweet_author;
    document.getElementById('orig-author').textContent = '@' + d.tweet_author;
    document.getElementById('orig-text').textContent   = d.tweet_text;
    document.getElementById('reply-text').value        = d.reply;
    document.getElementById('orig-box').style.display  = 'block';
    updateCounter();
  })
  .catch(e => { btn.disabled = false; btn.textContent = '返信生成'; alert('通信エラー: ' + e); });
}

function updateCounter() {
  var t = document.getElementById('reply-text').value;
  var el = document.getElementById('counter');
  el.textContent = t.length + ' / 280';
  el.className = 'counter' + (t.length > 280 ? ' over' : '');
}

function doPost(type) {
  var text = document.getElementById('reply-text').value.trim();
  if (!text) { alert('返信文を入力してください'); return; }
  if (text.length > 280) { alert('280字を超えています'); return; }
  var label = type === 'quote' ? '引用RTします' : '返信します';
  if (!confirm(label + '。よろしいですか？')) return;

  var btnId = type === 'quote' ? 'btn-quote' : 'btn-reply';
  var btn = document.getElementById(btnId);
  btn.disabled = true; btn.textContent = '投稿中...';

  fetch(location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=post'
      + '&reply_text=' + encodeURIComponent(text)
      + '&tweet_id='   + encodeURIComponent(currentTweetId)
      + '&tweet_url='  + encodeURIComponent(currentTweetUrl)
      + '&tweet_text=' + encodeURIComponent(currentTweetText)
      + '&tweet_author='+ encodeURIComponent(currentAuthor)
      + '&post_type='  + encodeURIComponent(type),
  })
  .then(r => r.json())
  .then(d => {
    btn.disabled = false;
    btn.textContent = type === 'quote' ? '引用RTする' : '返信する';
    var rb = document.getElementById('result-box');
    rb.style.display = 'block';
    if (d.ok) {
      rb.className = 'result-box';
      rb.innerHTML = '投稿完了！ <a href="' + d.posted_url + '" target="_blank" rel="noopener">' + d.posted_url + '</a>';
      setTimeout(() => location.reload(), 2500);
    } else {
      rb.className = 'err-box';
      rb.textContent = 'エラー: ' + d.error;
    }
  })
  .catch(e => {
    btn.disabled = false;
    btn.textContent = type === 'quote' ? '引用RTする' : '返信する';
    alert('通信エラー: ' + e);
  });
}
</script>
</body>
</html>
