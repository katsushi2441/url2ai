<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$DATA_DIR  = __DIR__ . '/data';
$BASE_URL  = AIGM_BASE_URL;
$THIS_FILE = 'finreportv.php';
$SITE_NAME = 'FinReportV';
$ADMIN     = AIGM_ADMIN;

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* =========================================================
   データ読み込み（finreport_*.json）
========================================================= */
$reports = array();
if (is_dir($DATA_DIR)) {
    $files = glob($DATA_DIR . '/finreport_*.json');
    if ($files) {
        rsort($files);
        $ticker_seen = array();
        foreach ($files as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d) || empty($d['ticker']) || empty($d['report'])) { continue; }
            $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($d['ticker']));
            if (isset($ticker_seen[$slug])) { continue; }
            $ticker_seen[$slug] = true;
            $reports[] = $d;
        }
        usort($reports, function($a, $b) {
            $ta = isset($a['created_at']) ? $a['created_at'] : '';
            $tb = isset($b['created_at']) ? $b['created_at'] : '';
            return strcmp($tb, $ta);
        });
    }
}

/* =========================================================
   RSS フィード
========================================================= */
if (isset($_GET['feed'])) {
    header('Access-Control-Allow-Origin: https://exbridge.jp');
    header('Content-Type: application/rss+xml; charset=UTF-8');
    $rss_items = array_slice($reports, 0, 20);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n";
    echo '<title>' . $SITE_NAME . ' — 金融投資レポート</title>' . "\n";
    echo '<link>' . $BASE_URL . '/' . $THIS_FILE . '</link>' . "\n";
    echo '<description>AI生成の金融投資レポート一覧</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . $BASE_URL . '/' . $THIS_FILE . '?feed" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rss_items as $r) {
        $ticker  = isset($r['ticker'])     ? $r['ticker']     : '';
        $summary = isset($r['summary'])    ? $r['summary']    : '';
        $date    = isset($r['created_at']) ? $r['created_at'] : '';
        $slug    = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($ticker));
        $link    = $BASE_URL . '/' . $THIS_FILE . '?ticker=' . urlencode($ticker);
        echo '<item><title><![CDATA[' . $ticker . ' 投資レポート]]></title>' . "\n";
        echo '<link>' . h($link) . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . h($link) . '</guid>' . "\n";
        echo '<description><![CDATA[' . mb_substr(str_replace("\n", ' ', $summary), 0, 200) . ']]></description>' . "\n";
        echo '<pubDate>' . ($date ? date('r', strtotime($date)) : date('r')) . '</pubDate></item>' . "\n";
    }
    echo '</channel></rss>' . "\n";
    exit;
}

/* =========================================================
   詳細 / 一覧
========================================================= */
$session_user  = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin      = ($session_user === $ADMIN);

$detail_ticker = isset($_GET['ticker']) ? trim($_GET['ticker']) : '';
$detail_report = null;
if ($detail_ticker !== '') {
    foreach ($reports as $r) {
        if (isset($r['ticker']) && strtolower($r['ticker']) === strtolower($detail_ticker)) {
            $detail_report = $r;
            break;
        }
    }
}

/* SEO */
if ($detail_report) {
    $page_title       = h($detail_report['ticker']) . ' 投資レポート | ' . $SITE_NAME;
    $page_description = h(mb_substr(str_replace("\n", ' ', isset($detail_report['summary']) ? $detail_report['summary'] : ''), 0, 160));
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?ticker=' . urlencode($detail_report['ticker']);
    $page_type        = 'article';
} else {
    $page_title       = $SITE_NAME . ' — AI金融投資レポート一覧';
    $page_description = 'コイン名・ティッカー・証券コードから生成したAI金融投資レポートの一覧。';
    $page_url         = $BASE_URL . '/' . $THIS_FILE;
    $page_type        = 'website';
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $page_title; ?></title>
<meta name="description" content="<?php echo $page_description; ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo h($page_url); ?>">
<meta property="og:type" content="<?php echo $page_type; ?>">
<meta property="og:title" content="<?php echo $page_title; ?>">
<meta property="og:description" content="<?php echo $page_description; ?>">
<meta property="og:url" content="<?php echo h($page_url); ?>">
<meta property="og:site_name" content="<?php echo h($SITE_NAME); ?>">
<meta property="og:locale" content="ja_JP">
<meta property="og:image" content="<?php echo $BASE_URL; ?>/images/finreport.png?v=<?php echo date('Ymd'); ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@xb_bittensor">
<meta name="twitter:image" content="<?php echo $BASE_URL; ?>/images/finreport.png?v=<?php echo date('Ymd'); ?>">
<meta name="twitter:title" content="<?php echo $page_title; ?>">
<meta name="twitter:description" content="<?php echo $page_description; ?>">
<link rel="alternate" type="application/rss+xml" title="<?php echo h($SITE_NAME); ?> RSS" href="<?php echo $BASE_URL . '/' . $THIS_FILE . '?feed'; ?>">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<!-- Google tag -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo h(AIGM_GTAG_ID); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo h(AIGM_GTAG_ID); ?>');</script>
<script>(function(){var s=document.createElement('script');s.src='https://aiknowledgecms.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s);})();</script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
    --accent:#0f766e;--accent-h:#0d9488;--accent-bg:#f0fdfa;--accent-light:#ccfbf1;
    --border:#e2e8f0;--muted:#64748b;--text:#0f172a;--surface:#fff;--bg:#f8fafc;
    --mono:'JetBrains Mono',monospace;
}
body{background:var(--bg);color:var(--text);font-family:-apple-system,'Inter',sans-serif;font-size:14px;}
.header{background:var(--surface);border-bottom:1px solid var(--border);padding:14px 20px;position:sticky;top:0;z-index:100;display:flex;align-items:center;gap:12px;}
.logo{font-size:17px;font-weight:700;color:var(--text);}
.logo span{color:var(--accent);}
.logo-group{display:flex;align-items:center;gap:6px}
.u2a-badge{background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;letter-spacing:.03em}
.badge{background:var(--accent);color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;}
.back-btn{margin-left:auto;font-size:13px;color:var(--accent);text-decoration:none;padding:5px 12px;border:1px solid var(--accent);border-radius:6px;}
.back-btn:hover{background:var(--accent-bg);}
.userbar{margin-left:auto;display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:var(--muted);}
.btn-sm{background:none;border:1px solid var(--border);color:var(--muted);padding:.2rem .7rem;border-radius:4px;font-size:.75rem;text-decoration:none;}
.btn-sm:hover{border-color:#dc2626;color:#dc2626;}
.rss-link{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;color:#c44f00;background:#fff5ef;border:1px solid #f5d0b8;border-radius:4px;padding:2px 7px;text-decoration:none;}

/* 一覧 */
.container{max-width:700px;margin:0 auto;padding:0 0 80px;}
.count-bar{padding:10px 20px;font-size:13px;color:var(--muted);border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;}
.post-card{border-bottom:1px solid #f0f0f0;padding:20px;transition:background .15s;cursor:pointer;}
.post-card:hover{background:#fafafa;}
.card-top{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
.ticker-badge{background:var(--accent);color:#fff;font-size:13px;font-weight:700;padding:4px 12px;border-radius:20px;font-family:var(--mono);letter-spacing:.03em;}
.card-date{color:#aaa;font-size:12px;margin-left:auto;}
.card-title{font-size:15px;font-weight:700;color:var(--text);margin-bottom:8px;}
.card-title a{color:inherit;text-decoration:none;}
.card-title a:hover{color:var(--accent);}
.summary-block{background:var(--accent-bg);border-left:3px solid var(--accent);border-radius:0 8px 8px 0;padding:10px 14px;font-size:13px;line-height:1.75;color:#134e4a;margin-bottom:10px;max-height:80px;overflow:hidden;position:relative;}
.summary-block::after{content:'';position:absolute;bottom:0;left:0;right:0;height:24px;background:linear-gradient(transparent,var(--accent-bg));pointer-events:none;}
.card-links{display:flex;gap:6px;flex-wrap:wrap;}
.card-link{display:inline-flex;align-items:center;gap:5px;background:#f5f5f5;border:1px solid var(--border);border-radius:6px;padding:5px 11px;text-decoration:none;color:#555;font-size:12px;transition:all .15s;}
.card-link:hover{background:var(--accent-bg);border-color:var(--accent);color:var(--accent);}
.empty{text-align:center;color:#bbb;padding:80px 20px;font-size:15px;}
.empty a{color:var(--accent);text-decoration:none;}

/* 詳細 */
.detail-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);background:var(--surface);}
.detail-ticker{font-size:22px;font-weight:700;font-family:var(--mono);color:var(--accent);margin-bottom:6px;}
.detail-meta{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.detail-summary{background:var(--accent-bg);border-left:3px solid var(--accent);border-radius:0 8px 8px 0;padding:12px 16px;font-size:.88rem;line-height:1.8;color:#134e4a;margin:16px 24px 0;}
.detail-body{padding:20px 24px;}
.report-body{font-size:.88rem;line-height:1.85;color:var(--text);}
.report-body h1{font-size:1.3rem;font-weight:700;margin:1.2rem 0 .6rem;}
.report-body h2{font-size:1.05rem;font-weight:700;margin:1rem 0 .5rem;padding-bottom:.3rem;border-bottom:1px solid var(--border);}
.report-body h3{font-size:.95rem;font-weight:600;margin:.8rem 0 .4rem;}
.report-body p{margin-bottom:.75rem;}
.report-body ul,.report-body ol{margin:.5rem 0 .75rem 1.2rem;}
.report-body li{margin-bottom:.3rem;}
.report-body table{width:100%;border-collapse:collapse;margin:.75rem 0;font-size:.83rem;}
.report-body th,.report-body td{border:1px solid var(--border);padding:6px 10px;text-align:left;}
.report-body th{background:#f1f5f9;font-weight:600;}
.report-body hr{border:none;border-top:1px solid var(--border);margin:1rem 0;}
.report-body code{background:#f1f5f9;padding:.1rem .3rem;border-radius:3px;font-family:var(--mono);font-size:.8rem;}
.report-body strong{color:var(--text);}
.detail-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px;}
.detail-actions a,.detail-actions button{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-teal{background:var(--accent);color:#fff;border:none;}
.btn-teal:hover{background:var(--accent-h);}
.btn-outline{background:#f1f5f9;color:var(--text);border:1px solid var(--border);}
.btn-outline:hover{background:var(--accent-bg);border-color:var(--accent);color:var(--accent);}
.sources-list{margin-top:20px;}
.sources-title{font-size:12px;font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;}
.sources-list ol{padding-left:1.2rem;font-size:.78rem;line-height:1.9;}
.sources-list a{color:var(--accent);text-decoration:none;word-break:break-all;}
.sources-list a:hover{text-decoration:underline;}
</style>
</head>
<body>

<div class="header">
    <div style="font-size:22px">📊</div>
    <?php if ($detail_report): ?>
    <div class="logo-group"><div class="logo"><a href="<?php echo h($THIS_FILE); ?>" style="text-decoration:none;color:inherit;">Fin<span>ReportV</span></a></div><span class="u2a-badge">URL2AI</span></div>
    <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
    <?php else: ?>
    <div class="logo-group"><div class="logo">Fin<span>ReportV</span></div><span class="u2a-badge">URL2AI</span></div>
    <span class="badge">AI投資レポート</span>
    <a href="<?php echo h($THIS_FILE . '?feed'); ?>" class="rss-link" title="RSSフィード">
        <svg width="10" height="10" viewBox="0 0 8 8"><circle cx="1.5" cy="6.5" r="1.5" fill="#c44f00"/><path d="M0 4.5A3.5 3.5 0 013.5 8" stroke="#c44f00" stroke-width="1.2" fill="none"/><path d="M0 2A6 6 0 016 8" stroke="#c44f00" stroke-width="1.2" fill="none"/></svg>
        RSS
    </a>
    <div class="userbar">
        <a href="finreport.php" class="btn-sm">+ 新規生成</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($detail_report): ?>
<!-- ========== 詳細ページ ========== -->
<div class="detail-header">
    <div class="detail-ticker"><?php echo h($detail_report['ticker']); ?></div>
    <div class="detail-meta">
        <span>生成日: <?php echo h(isset($detail_report['created_at']) ? $detail_report['created_at'] : ''); ?></span>
        <?php if (!empty($detail_report['sources'])): ?>
        <span>参照ソース: <?php echo count($detail_report['sources']); ?> 件</span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($detail_report['summary'])): ?>
<div class="detail-summary"><?php echo h($detail_report['summary']); ?></div>
<?php endif; ?>

<div style="padding:0 24px;">
    <div class="detail-actions">
        <button class="btn-teal" type="button" onclick="copyReport()">📋 Markdownコピー</button>
        <?php if ($is_admin): ?>
        <a class="btn-outline" href="finreport.php?ticker=<?php echo urlencode($detail_report['ticker']); ?>">🔄 再生成</a>
        <?php endif; ?>
    </div>
</div>

<div class="detail-body">
    <div class="report-body" id="report-render"></div>
    <textarea id="report-raw" style="display:none"><?php echo h(isset($detail_report['report']) ? $detail_report['report'] : ''); ?></textarea>

    <?php if (!empty($detail_report['sources'])): ?>
    <div class="sources-list">
        <div class="sources-title">🔗 参照ソース</div>
        <ol>
            <?php foreach ($detail_report['sources'] as $src): ?>
            <li><a href="<?php echo h($src); ?>" target="_blank" rel="noopener"><?php echo h($src); ?></a></li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php endif; ?>
</div>

<script>
var raw = document.getElementById('report-raw').value;
document.getElementById('report-render').innerHTML = marked.parse(raw);
function copyReport() {
    navigator.clipboard.writeText(raw).then(function() {
        alert('Markdownをコピーしました');
    });
}
</script>

<?php else: ?>
<!-- ========== 一覧ページ ========== -->
<div class="container">
    <div class="count-bar">
        📊 <?php echo count($reports); ?> 件のレポート
    </div>

    <div id="report-list"></div>
    <div id="load-sentinel" style="height:1px;"></div>
    <div id="load-indicator" style="display:none;text-align:center;padding:16px;font-size:13px;color:#888;">読み込み中...</div>
</div>

<script>
var frReports = <?php echo json_encode(array_values($reports), JSON_UNESCAPED_UNICODE); ?>;
var IS_ADMIN   = <?php echo $is_admin ? 'true' : 'false'; ?>;
var PAGE_SIZE  = 20;
var curPage    = 0;

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderReports(from, to) {
    var list = document.getElementById('report-list');
    if (!list) return;
    for (var i = from; i < to && i < frReports.length; i++) {
        var r       = frReports[i];
        var ticker  = r.ticker    || '';
        var summary = r.summary   || '';
        var date    = r.created_at || '';
        var detailUrl = 'finreportv.php?ticker=' + encodeURIComponent(ticker);

        var html = '<div class="post-card" onclick="location.href=\'' + esc(detailUrl) + '\'">'
            + '<div class="card-top">'
            + '<span class="ticker-badge">' + esc(ticker) + '</span>'
            + '<span class="card-date">' + esc(date) + '</span>'
            + '</div>'
            + '<div class="card-title"><a href="' + esc(detailUrl) + '" onclick="event.stopPropagation()">' + esc(ticker) + ' 投資レポート</a></div>'
            + (summary ? '<div class="summary-block">' + esc(summary) + '</div>' : '')
            + '<div class="card-links">'
            + '<a class="card-link" href="' + esc(detailUrl) + '" onclick="event.stopPropagation()">📄 詳細を見る</a>'
            + (IS_ADMIN ? '<a class="card-link" href="finreport.php?ticker=' + encodeURIComponent(ticker) + '" onclick="event.stopPropagation()">🔄 再生成</a>' : '')
            + '</div>'
            + '</div>';
        list.insertAdjacentHTML('beforeend', html);
    }
    curPage++;
}

function loadMore() {
    var from = curPage * PAGE_SIZE;
    if (from >= frReports.length) { document.getElementById('load-indicator').style.display = 'none'; return; }
    renderReports(from, from + PAGE_SIZE);
}

var sentinel = document.getElementById('load-sentinel');
if (sentinel) {
    new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) {
            document.getElementById('load-indicator').style.display = 'block';
            setTimeout(function() {
                loadMore();
                if (curPage * PAGE_SIZE >= frReports.length) { document.getElementById('load-indicator').style.display = 'none'; }
            }, 150);
        }
    }, { rootMargin: '200px' }).observe(sentinel);
}

if (frReports.length === 0) {
    document.getElementById('report-list').innerHTML = '<div class="empty">まだレポートがありません。<br><br><a href="finreport.php">FinReportでレポートを生成する →</a></div>';
} else {
    loadMore();
}
</script>

<?php endif; ?>

</body>
</html>
