<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');

if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.cookie_path',     '/');
    ini_set('session.cookie_domain',   AIGM_COOKIE_DOMAIN);
    ini_set('session.cookie_secure',   '1');
    ini_set('session.cookie_httponly', '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), time() + $session_lifetime, '/', AIGM_COOKIE_DOMAIN, true, true);
    }
}

$BASE_URL  = AIGM_BASE_URL;
$THIS_FILE = 'finreport.php';
$ADMIN     = AIGM_ADMIN;
$DATA_DIR  = __DIR__ . '/data';

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

function fr_base64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function fr_gen_verifier() { $b=''; for($i=0;$i<32;$i++){$b.=chr(mt_rand(0,255));} return fr_base64url($b); }
function fr_gen_challenge($v) { return fr_base64url(hash('sha256',$v,true)); }
function fr_x_post($url,$data,$headers) {
    $opts=array('http'=>array('method'=>'POST','header'=>implode("\r\n",$headers)."\r\n",'content'=>$data,'timeout'=>12,'ignore_errors'=>true));
    $r=@file_get_contents($url,false,stream_context_create($opts));
    if(!$r){$r='{}';}return json_decode($r,true);
}
function fr_x_get($url,$token) {
    $opts=array('http'=>array('method'=>'GET','header'=>"Authorization: Bearer $token\r\nUser-Agent: FinReport/1.0\r\n",'timeout'=>12,'ignore_errors'=>true));
    $r=@file_get_contents($url,false,stream_context_create($opts));
    if(!$r){$r='{}';}return json_decode($r,true);
}

if (isset($_GET['fr_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time()-3600, '/', AIGM_COOKIE_DOMAIN, true, true);
    header('Location: ' . $x_redirect_uri); exit;
}
if (isset($_GET['fr_login'])) {
    $ver=$ver=fr_gen_verifier(); $chal=fr_gen_challenge($ver); $state=md5(uniqid('',true));
    $_SESSION['fr_code_verifier']=$ver; $_SESSION['fr_oauth_state']=$state;
    $p=array('response_type'=>'code','client_id'=>$x_client_id,'redirect_uri'=>$x_redirect_uri,
             'scope'=>'tweet.read users.read offline.access','state'=>$state,'code_challenge'=>$chal,'code_challenge_method'=>'S256');
    header('Location: https://twitter.com/i/oauth2/authorize?'.http_build_query($p)); exit;
}
if (isset($_GET['code'])&&isset($_GET['state'])&&isset($_SESSION['fr_oauth_state'])) {
    if ($_GET['state']===$_SESSION['fr_oauth_state']) {
        $post=http_build_query(array('grant_type'=>'authorization_code','code'=>$_GET['code'],'redirect_uri'=>$x_redirect_uri,'code_verifier'=>$_SESSION['fr_code_verifier'],'client_id'=>$x_client_id));
        $cred=base64_encode($x_client_id.':'.$x_client_secret);
        $data=fr_x_post('https://api.twitter.com/2/oauth2/token',$post,array('Content-Type: application/x-www-form-urlencoded','Authorization: Basic '.$cred));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']=$data['access_token'];
            $_SESSION['session_token_expires']=time()+(isset($data['expires_in'])?(int)$data['expires_in']:7200);
            if (!empty($data['refresh_token'])) { $_SESSION['session_refresh_token']=$data['refresh_token']; }
            unset($_SESSION['fr_oauth_state'],$_SESSION['fr_code_verifier']);
            $me=fr_x_get('https://api.twitter.com/2/users/me',$data['access_token']);
            if (isset($me['data']['username'])) { $_SESSION['session_username']=$me['data']['username']; }
        }
    }
    header('Location: ' . $x_redirect_uri); exit;
}

if (!empty($_SESSION['session_refresh_token'])&&!empty($_SESSION['session_token_expires'])&&time()>$_SESSION['session_token_expires']-300) {
    $cred_r=base64_encode($x_client_id.':'.$x_client_secret);
    $post_r=http_build_query(array('grant_type'=>'refresh_token','refresh_token'=>$_SESSION['session_refresh_token'],'client_id'=>$x_client_id));
    $ref=fr_x_post('https://api.twitter.com/2/oauth2/token',$post_r,array('Content-Type: application/x-www-form-urlencoded','Authorization: Basic '.$cred_r));
    if (!empty($ref['access_token'])) {
        $_SESSION['session_access_token']=$ref['access_token'];
        $_SESSION['session_token_expires']=time()+(isset($ref['expires_in'])?(int)$ref['expires_in']:7200);
        if (!empty($ref['refresh_token'])) { $_SESSION['session_refresh_token']=$ref['refresh_token']; }
    } else {
        unset($_SESSION['session_access_token'],$_SESSION['session_refresh_token'],$_SESSION['session_token_expires'],$_SESSION['session_username']);
    }
}

$logged_in = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$username  = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin  = ($username === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function fr_slug($ticker) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($ticker)));
}
function fr_data_file($ticker) {
    global $DATA_DIR;
    return $DATA_DIR . '/finreport_' . fr_slug($ticker) . '.json';
}
function fr_load($ticker) {
    $f = fr_data_file($ticker);
    if (!file_exists($f)) return null;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function fr_save($ticker, $data) {
    global $DATA_DIR;
    if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);
    file_put_contents(fr_data_file($ticker), json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

$flash_error = isset($_SESSION['fr_flash_error']) ? $_SESSION['fr_flash_error'] : '';
if (isset($_SESSION['fr_flash_error'])) unset($_SESSION['fr_flash_error']);

$ticker      = '';
$saved       = null;
$action      = isset($_POST['action']) ? $_POST['action'] : '';

/* GET ?ticker=BTC */
if (isset($_GET['ticker']) && $_GET['ticker'] !== '') {
    $ticker = trim($_GET['ticker']);
    $saved  = fr_load($ticker);
}

/* POST 生成 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && $action === 'generate') {
    $ticker = isset($_POST['ticker']) ? trim($_POST['ticker']) : '';
    if ($ticker === '') {
        $_SESSION['fr_flash_error'] = 'ティッカー・コイン名を入力してください';
        header('Location: ' . $x_redirect_uri); exit;
    }

    $payload = json_encode(array('ticker' => $ticker), JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init(FINREPORT_API);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_POSTFIELDS     => $payload,
        ));
        $raw      = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $opts = array('http' => array('method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$payload,'timeout'=>600,'ignore_errors'=>true));
        $raw  = @file_get_contents(FINREPORT_API, false, stream_context_create($opts));
        $curl_err  = '';
        $http_code = 200;
    }

    if (!$raw || $curl_err) {
        $_SESSION['fr_flash_error'] = 'FinReport APIに接続できませんでした: ' . $curl_err;
        header('Location: ' . $x_redirect_uri); exit;
    }
    $res = json_decode($raw, true);
    if (!is_array($res) || empty($res['report'])) {
        $_SESSION['fr_flash_error'] = 'レポート生成に失敗しました (HTTP ' . $http_code . ')';
        header('Location: ' . $x_redirect_uri); exit;
    }

    $save_data = array(
        'ticker'     => $ticker,
        'report'     => $res['report'],
        'summary'    => isset($res['summary'])  ? $res['summary']  : '',
        'sources'    => isset($res['sources'])  ? $res['sources']  : array(),
        'created_at' => date('Y-m-d H:i:s'),
    );
    fr_save($ticker, $save_data);
    header('Location: ' . $x_redirect_uri . '?ticker=' . urlencode($ticker)); exit;
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FinReport — 金融投資レポート</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#0f766e;--accent-h:#0d9488;
    --green:#059669;--red:#dc2626;--amber:#d97706;
    --text:#0f172a;--muted:#64748b;
    --mono:'JetBrains Mono',monospace;--sans:'Inter',sans-serif;
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;font-size:14px}
header{background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.logo{font-size:1.1rem;font-weight:700;letter-spacing:-.02em}
.logo span{color:var(--accent)}
.userbar{display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:var(--muted)}
.userbar strong{color:var(--green)}
.btn-sm{background:none;border:1px solid var(--border2);color:var(--muted);padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-sm:hover{border-color:var(--red);color:var(--red)}
.container{max-width:900px;margin:0 auto;padding:1.5rem}
.section{background:var(--surface);border:1px solid var(--border);border-radius:10px;margin-bottom:1rem;overflow:hidden}
.section-header{padding:.75rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.section-title{font-weight:600;font-size:.85rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.step{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700}
.section-body{padding:1rem}
.row{display:flex;gap:.6rem;align-items:flex-start}
input[type=text]{flex:1;border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.9rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text)}
input[type=text]:focus{border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans);text-decoration:none}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn:disabled{opacity:.5;cursor:not-allowed}
.msg-error{color:var(--red);font-size:.8rem;margin-top:.4rem;padding:.4rem .6rem;background:#fef2f2;border-radius:4px;border:1px solid #fca5a5}
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}
.loading-msg{display:none;text-align:center;padding:12px 16px;font-size:.82rem;color:#065f46;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;margin-bottom:1rem;font-weight:600}
.summary-box{background:#f0fdfa;border-left:3px solid var(--accent);border-radius:0 8px 8px 0;padding:12px 16px;font-size:.88rem;line-height:1.8;color:#134e4a;margin-bottom:1rem}
.report-body{font-size:.88rem;line-height:1.85;color:var(--text)}
.report-body h1{font-size:1.3rem;font-weight:700;margin:1.2rem 0 .6rem;color:#0f172a}
.report-body h2{font-size:1.05rem;font-weight:700;margin:1rem 0 .5rem;color:#0f172a;padding-bottom:.3rem;border-bottom:1px solid var(--border)}
.report-body h3{font-size:.95rem;font-weight:600;margin:.8rem 0 .4rem;color:#1e293b}
.report-body p{margin-bottom:.75rem}
.report-body ul,.report-body ol{margin:.5rem 0 .75rem 1.2rem}
.report-body li{margin-bottom:.3rem}
.report-body strong{color:#0f172a}
.report-body hr{border:none;border-top:1px solid var(--border);margin:1rem 0}
.report-body code{background:#f1f5f9;padding:.1rem .3rem;border-radius:3px;font-family:var(--mono);font-size:.8rem}
.sources-list{font-size:.78rem;line-height:1.8}
.sources-list a{color:var(--accent);text-decoration:none;word-break:break-all}
.sources-list a:hover{text-decoration:underline}
.meta-bar{font-size:.75rem;color:var(--muted);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.hint{font-size:.82rem;color:var(--muted);line-height:1.8;margin-top:.5rem}
@media (max-width:600px){
    .row{flex-wrap:wrap}
    .row input[type=text]{flex:1 1 100%}
    .container{padding:1rem}
    .section-body{padding:.75rem}
}
</style>
<!-- Google tag -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo h(AIGM_GTAG_ID); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo h(AIGM_GTAG_ID); ?>');</script>
</head>
<body>
<header>
    <div class="logo">Fin<span>Report</span></div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?fr_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?fr_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">

    <!-- STEP 1: 入力 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">1</span> コイン名・ティッカー・証券コードを入力</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-gen">
                <input type="hidden" name="action" value="generate">
                <div class="row">
                    <input type="text" name="ticker" id="ticker-input"
                           placeholder="例: BTC, ETH, SOL, NVIDIA, 7203.T"
                           value="<?php echo h($ticker); ?>">
                    <button type="button" class="btn btn-primary" id="btn-gen"
                            <?php if (!$is_admin): ?> disabled title="管理者のみ生成できます"<?php endif; ?>
                            onclick="submitGen()">
                        <span class="btn-label">レポート生成</span>
                        <span class="spinner"></span>
                    </button>
                    <?php if ($saved && $ticker): ?>
                    <button type="button" class="btn btn-secondary" onclick="submitRegen()">再生成</button>
                    <?php endif; ?>
                </div>
                <?php if ($flash_error): ?>
                <div class="msg-error"><?php echo h($flash_error); ?></div>
                <?php endif; ?>
                <div class="hint">
                    入力後「レポート生成」を押すと、Web検索＋AI分析で金融投資家向けレポートを生成します（2〜5分かかります）。現在生成できるのは <strong><?php echo h($ADMIN); ?></strong> のみです。
                </div>
            </form>
        </div>
    </div>

    <div id="loading-msg" class="loading-msg">
        ⏳ Web検索とAI分析を実行中です。2〜5分かかります。ページを閉じないでください...
    </div>

    <?php if ($saved): ?>

    <!-- サマリー -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--green)">✓</span> AI要約</div>
            <div class="meta-bar">
                <span><?php echo h($saved['ticker']); ?></span>
                <span><?php echo h(isset($saved['created_at']) ? $saved['created_at'] : ''); ?></span>
                <button type="button" class="btn-sm" onclick="copyReport()">Markdownコピー</button>
            </div>
        </div>
        <div class="section-body">
            <div class="summary-box"><?php echo h(isset($saved['summary']) ? $saved['summary'] : ''); ?></div>
        </div>
    </div>

    <!-- レポート本文 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--accent)">📄</span> 投資レポート</div>
        </div>
        <div class="section-body">
            <div class="report-body" id="report-render"></div>
            <textarea id="report-raw" style="display:none"><?php echo h(isset($saved['report']) ? $saved['report'] : ''); ?></textarea>
        </div>
    </div>

    <!-- ソース -->
    <?php if (!empty($saved['sources'])): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title">🔗 参照ソース</div>
        </div>
        <div class="section-body">
            <ol class="sources-list">
                <?php foreach ($saved['sources'] as $src): ?>
                <li><a href="<?php echo h($src); ?>" target="_blank" rel="noopener"><?php echo h($src); ?></a></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<script>
function submitGen() {
    var ticker = document.getElementById('ticker-input').value.trim();
    if (!ticker) { return; }
    var btn = document.getElementById('btn-gen');
    var msg = document.getElementById('loading-msg');
    if (btn) { btn.disabled = true; btn.classList.add('loading'); }
    if (msg) { msg.style.display = 'block'; }
    document.getElementById('form-gen').submit();
}
function submitRegen() {
    if (!confirm('再生成しますか？（上書き保存されます）')) return;
    submitGen();
}
document.getElementById('ticker-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') submitGen();
});

<?php if ($saved && !empty($saved['report'])): ?>
var raw = document.getElementById('report-raw').value;
document.getElementById('report-render').innerHTML = marked.parse(raw);
<?php endif; ?>

function copyReport() {
    var raw = document.getElementById('report-raw');
    if (!raw) return;
    navigator.clipboard.writeText(raw.value).then(function() {
        alert('Markdownをコピーしました');
    });
}
</script>
</body>
</html>
