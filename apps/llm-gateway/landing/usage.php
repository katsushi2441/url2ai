<?php
// LLM2API 利用状況ページ（運営用）。
// PHP 5.x で動く構文だけを使う（?? / match / 型宣言は使わない）。
// バックエンドの GET /usage を閲覧トークン付きで叩いて表示するだけ。
require_once __DIR__ . '/usage_config.php';
date_default_timezone_set('Asia/Tokyo');
session_start();

function u_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function u_n($v) { return number_format((float)$v); }

$logged_in = !empty($_SESSION['llm2api_usage_ok']);
$error = '';

if (isset($_GET['logout'])) {
    $_SESSION = array();
    session_destroy();
    header('Location: usage.php');
    exit;
}

if (!$logged_in && isset($_POST['password'])) {
    if (hash_equals(LLM2API_USAGE_PASSWORD, (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['llm2api_usage_ok'] = true;
        header('Location: usage.php');
        exit;
    }
    $error = 'パスワードが違います。';
    usleep(400000);
}

$data = null;
if ($logged_in) {
    $months = isset($_GET['months']) ? max(1, min(12, (int)$_GET['months'])) : 3;
    $ch = curl_init(rtrim(LLM2API_API_BASE, '/') . '/usage?months=' . $months);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Usage-Token: ' . LLM2API_USAGE_TOKEN));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) {
        $error = 'バックエンドから取得できません (HTTP ' . $status . ')';
    } else {
        $data = json_decode($body, true);
        if (!is_array($data)) { $error = '応答を解釈できません'; $data = null; }
    }
}
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>LLM2API 利用状況</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.kpis { display:flex; flex-wrap:wrap; gap:12px; margin:18px 0 8px; }
.kpi { flex:1 1 150px; background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:14px; }
.kpi .n { font-size:26px; font-weight:800; }
.kpi .l { color:var(--muted); font-size:12.5px; }
.kpi.err .n { color:#f85149; }
form.login { max-width:340px; margin:60px auto; background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:24px; }
form.login input { width:100%; padding:11px 12px; font-size:16px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--text); box-sizing:border-box; }
form.login button { width:100%; margin-top:12px; padding:12px; border:0; border-radius:8px; background:var(--accent); color:#07121f; font-weight:700; font-size:15px; cursor:pointer; }
.err { color:#f85149; font-size:13.5px; margin-top:10px; }
</style>
</head>
<body>

<header class="top">
  <div class="wrap">
    <div class="brand">LLM<span>2</span>API <span style="color:var(--muted);font-weight:400;font-size:13px">利用状況</span></div>
    <div class="spacer"></div>
    <?php if ($logged_in) { ?><a class="lang" href="usage.php?logout=1">ログアウト</a><?php } ?>
  </div>
</header>

<div class="wrap">
<?php if (!$logged_in) { ?>
  <form class="login" method="post">
    <h2 style="margin:0 0 14px;font-size:16px">利用状況（運営用）</h2>
    <input type="password" name="password" placeholder="パスワード" autofocus autocomplete="current-password">
    <button type="submit">開く</button>
    <?php if ($error !== '') { echo '<div class="err">' . u_h($error) . '</div>'; } ?>
  </form>
<?php } else { ?>
  <?php if ($error !== '') { ?>
    <section><h2>取得できません</h2><p class="sub"><?php echo u_h($error); ?></p></section>
  <?php } else {
      $t = $data['totals']; ?>
    <section style="border-top:0">
      <h2>集計（直近 <?php echo u_h($data['months']); ?> か月）</h2>
      <p class="sub">JST <?php echo date('Y-m-d H:i'); ?> 時点</p>
      <div class="kpis">
        <div class="kpi"><div class="n"><?php echo u_n($t['calls']); ?></div><div class="l">呼び出し回数</div></div>
        <div class="kpi"><div class="n"><?php echo u_n($t['total_tokens']); ?></div><div class="l">総トークン</div></div>
        <div class="kpi"><div class="n"><?php echo u_n($t['prompt_tokens']); ?></div><div class="l">入力トークン</div></div>
        <div class="kpi"><div class="n"><?php echo u_n($t['completion_tokens']); ?></div><div class="l">出力トークン</div></div>
        <div class="kpi <?php echo $t['errors'] > 0 ? 'err' : ''; ?>"><div class="n"><?php echo u_n($t['errors']); ?></div><div class="l">エラー</div></div>
      </div>
      <p class="note">期間: <a href="usage.php?months=1">1か月</a> / <a href="usage.php?months=3">3か月</a> / <a href="usage.php?months=12">12か月</a></p>
    </section>

    <section>
      <h2>課金レール別</h2>
      <div class="table-wrap"><table>
        <tr><th>レール</th><th>回数</th><th>トークン</th></tr>
        <?php foreach ($data['byRail'] as $r) { ?>
        <tr><td><?php echo u_h($r['key']); ?></td><td><?php echo u_n($r['calls']); ?></td><td><?php echo u_n($r['total_tokens']); ?></td></tr>
        <?php } ?>
      </table></div>
    </section>

    <section>
      <h2>呼び出し元別（上位50）</h2>
      <p class="sub">x402は支払いアドレス、RapidAPIは利用者名。</p>
      <div class="table-wrap"><table>
        <tr><th>呼び出し元</th><th>回数</th><th>トークン</th></tr>
        <?php foreach ($data['byCaller'] as $r) { ?>
        <tr><td style="word-break:break-all"><?php echo u_h($r['key']); ?></td><td><?php echo u_n($r['calls']); ?></td><td><?php echo u_n($r['total_tokens']); ?></td></tr>
        <?php } ?>
      </table></div>
    </section>

    <section>
      <h2>モデル別</h2>
      <div class="table-wrap"><table>
        <tr><th>モデル</th><th>回数</th><th>トークン</th></tr>
        <?php foreach ($data['byModel'] as $r) { ?>
        <tr><td><?php echo u_h($r['key']); ?></td><td><?php echo u_n($r['calls']); ?></td><td><?php echo u_n($r['total_tokens']); ?></td></tr>
        <?php } ?>
      </table></div>
    </section>

    <section>
      <h2>日別</h2>
      <div class="table-wrap"><table>
        <tr><th>日付(UTC)</th><th>回数</th><th>トークン</th></tr>
        <?php foreach (array_reverse($data['byDay']) as $r) { ?>
        <tr><td><?php echo u_h($r['key']); ?></td><td><?php echo u_n($r['calls']); ?></td><td><?php echo u_n($r['total_tokens']); ?></td></tr>
        <?php } ?>
      </table></div>
    </section>
  <?php } ?>
<?php } ?>
</div>

</body>
</html>
