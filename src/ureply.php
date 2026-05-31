<?php
require_once __DIR__ . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

$DATA_DIR  = __DIR__ . '/data';
$BASE_URL  = AIGM_BASE_URL;
$THIS_FILE = 'ureply.php';
$ADMIN     = AIGM_ADMIN;

if (isset($_GET['ur_logout'])) {
    header('Location: ' . url2ai_auth_logout_url('/' . $THIS_FILE));
    exit;
}
if (isset($_GET['ur_login'])) {
    header('Location: ' . url2ai_auth_login_url('/' . $THIS_FILE));
    exit;
}

$auth         = url2ai_auth_bootstrap();
$logged_in    = $auth['logged_in'];
$session_user = $auth['session_user'];
$is_admin     = $auth['is_admin'];

/* ── 過去データ ── */
$posts = array();
$pfiles = glob($DATA_DIR . '/ureply_*.json');
if ($pfiles) {
    foreach ($pfiles as $f) {
        $p = json_decode(file_get_contents($f), true);
        if (is_array($p) && !empty($p['id'])) { $posts[] = $p; }
    }
}
usort($posts, function($a, $b) {
    $ta = isset($a['timestamp']) ? $a['timestamp'] : 0;
    $tb = isset($b['timestamp']) ? $b['timestamp'] : 0;
    return $tb - $ta;
});

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$gtag = defined('AIGM_GTAG_ID') ? AIGM_GTAG_ID : '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>UReply — AI返信生成</title>
<?php if ($gtag): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo h($gtag); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo h($gtag); ?>');</script>
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
.btn-primary{background:#1d9bf0;color:#fff}.btn-primary:hover{background:#0d8de1}
.btn-reply{background:#16a34a;color:#fff}.btn-reply:hover{background:#15803d}
.btn-quote{background:#7c3aed;color:#fff}.btn-quote:hover{background:#6d28d9}
.btn:disabled{opacity:.5;cursor:not-allowed}
.tweet-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:10px 0;font-size:.85rem;color:#334155}
.tweet-author{font-weight:700;color:#0f172a;margin-bottom:4px}
textarea{width:100%;min-height:100px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;resize:vertical;font-family:inherit}
.counter{font-size:.75rem;color:#94a3b8;text-align:right;margin:4px 0 10px}
.counter.over{color:#ef4444}
.action-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.result-ok{margin-top:10px;padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:.85rem}
.result-ok a{color:#166534;font-weight:600}
.result-err{margin-top:10px;padding:10px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-size:.85rem;color:#991b1b}
.auth-bar{display:flex;justify-content:flex-end;gap:8px;margin-bottom:14px;font-size:.8rem;align-items:center}
.auth-bar a{color:#1d9bf0;text-decoration:none;font-weight:600}
.admin-status{font-size:.8rem;margin-top:6px}
.admin-status.loading{color:#64748b}
.admin-status.ok{color:#16a34a}
.admin-status.err{color:#dc2626}
.post-card{background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.07)}
.post-meta{font-size:.72rem;color:#94a3b8;margin-bottom:6px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
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
      <span>@<?php echo h($session_user); ?></span>
      <a href="?ur_logout=1">ログアウト</a>
    <?php else: ?>
      <a href="?ur_login=1">Xでログイン</a>
    <?php endif; ?>
  </div>

  <h1>UReply</h1>
  <p class="sub">X投稿URLを入れてAI返信を生成 → そのままXへ返信・引用RT</p>

<?php if ($is_admin): ?>
  <div class="card">
    <div class="card-head">返信を生成して投稿</div>
    <div class="input-row">
      <input type="text" id="admin-url-input" placeholder="https://x.com/user/status/..." autocomplete="off">
      <button class="btn btn-primary" id="btn-gen" onclick="doGenerate()">返信生成</button>
    </div>
    <div id="gen-status" class="admin-status"></div>

    <div id="orig-box" style="display:none;margin-top:12px">
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
      <div id="post-status" class="admin-status" style="margin-top:8px"></div>
    </div>
  </div>
<?php elseif (!$logged_in): ?>
  <div class="card" style="text-align:center;padding:32px">
    <a href="?ur_login=1" class="btn btn-primary">Xでログインして使う</a>
  </div>
<?php endif; ?>

  <div class="card">
    <div class="card-head">過去の返信・引用RT（<?php echo count($posts); ?> 件）</div>
    <?php if (empty($posts)): ?>
      <div class="empty">まだ投稿はありません</div>
    <?php else: ?>
      <?php foreach ($posts as $p): ?>
        <div class="post-card">
          <div class="post-meta">
            <?php $pt = isset($p['post_type']) ? $p['post_type'] : 'reply'; ?>
            <span class="badge <?php echo $pt === 'quote' ? 'badge-quote' : 'badge-reply'; ?>">
              <?php echo $pt === 'quote' ? '引用RT' : '返信'; ?>
            </span>
            <span><?php echo h(isset($p['created_at']) ? $p['created_at'] : ''); ?></span>
            <?php if (!empty($p['posted_url'])): ?>
              <a href="<?php echo h($p['posted_url']); ?>" target="_blank" rel="noopener">Xで見る →</a>
            <?php endif; ?>
          </div>
          <?php if (!empty($p['tweet_text'])): ?>
            <div class="orig-tweet">
              <strong>@<?php echo h(isset($p['tweet_author']) ? $p['tweet_author'] : ''); ?></strong>：<?php echo h(mb_substr(isset($p['tweet_text']) ? $p['tweet_text'] : '', 0, 80)); ?>…
              <?php if (!empty($p['tweet_url'])): ?>
                <a href="<?php echo h($p['tweet_url']); ?>" target="_blank" rel="noopener" style="font-size:.72rem;margin-left:6px">元投稿</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="reply-text"><?php echo h(isset($p['reply_text']) ? $p['reply_text'] : ''); ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
var currentTweetId = '', currentTweetUrl = '', currentTweetText = '', currentAuthor = '';

function doGenerate() {
    var url = document.getElementById('admin-url-input').value.trim();
    if (!url) { alert('URLを入力してください'); return; }
    var btn = document.getElementById('btn-gen');
    var status = document.getElementById('gen-status');
    btn.disabled = true;
    status.textContent = 'AI生成中...（少々お待ちください）';
    status.className = 'admin-status loading';
    document.getElementById('orig-box').style.display = 'none';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'saveureply.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.ok) {
                currentTweetId   = res.tweet_id;
                currentTweetUrl  = url;
                currentTweetText = res.tweet_text;
                currentAuthor    = res.tweet_author;
                document.getElementById('orig-author').textContent = '@' + res.tweet_author;
                document.getElementById('orig-text').textContent   = res.tweet_text;
                document.getElementById('reply-text').value        = res.reply;
                document.getElementById('orig-box').style.display  = 'block';
                status.textContent = '生成完了。編集して投稿してください。';
                status.className = 'admin-status ok';
                updateCounter();
            } else {
                status.textContent = 'エラー: ' + (res.error || '不明');
                status.className = 'admin-status err';
            }
        } catch(e) {
            status.textContent = '通信エラー: ' + xhr.responseText.substring(0, 200);
            status.className = 'admin-status err';
        }
    };
    xhr.send(JSON.stringify({ action: 'generate', tweet_url: url }));
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
    if (!confirm((type === 'quote' ? '引用RT' : '返信') + 'します。よろしいですか？')) return;

    var btnId = type === 'quote' ? 'btn-quote' : 'btn-reply';
    var btn = document.getElementById(btnId);
    var status = document.getElementById('post-status');
    btn.disabled = true;
    status.textContent = '投稿中...';
    status.className = 'admin-status loading';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'saveureply.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.ok) {
                status.textContent = '投稿完了！ ' + res.posted_url;
                status.className = 'admin-status ok';
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                status.textContent = 'エラー: ' + (res.error || '不明');
                status.className = 'admin-status err';
            }
        } catch(e) {
            status.textContent = '通信エラー: ' + xhr.responseText.substring(0, 200);
            status.className = 'admin-status err';
        }
    };
    xhr.send(JSON.stringify({
        action:       'post',
        reply_text:   text,
        tweet_id:     currentTweetId,
        tweet_url:    currentTweetUrl,
        tweet_text:   currentTweetText,
        tweet_author: currentAuthor,
        post_type:    type,
    }));
}

document.getElementById('admin-url-input') && document.getElementById('admin-url-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') doGenerate();
});
</script>
</body>
</html>
