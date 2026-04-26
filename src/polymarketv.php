<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$DATA_DIR  = __DIR__ . '/data';
$BASE_URL  = AIGM_BASE_URL;
$THIS_FILE = 'polymarketv.php';
$SITE_NAME = 'PolyMarket Intel';
$ADMIN     = AIGM_ADMIN;

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function pmv_is_valid_paragraph_url($url) {
    $url = trim((string) $url);
    if ($url === '') return false;
    if (strpos($url, 'aiknowledgecms.exbridge.jp') !== false) return false;
    return (strpos($url, 'paragraph.com') !== false || strpos($url, 'paragraph.xyz') !== false);
}
function pmv_slug($query) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($query)));
}

/* =========================================================
   データ読み込み（polymarket_*.json）
========================================================= */
$reports = array();
if (is_dir($DATA_DIR)) {
    $files = glob($DATA_DIR . '/polymarket_*.json');
    if ($files) {
        rsort($files);
        $query_seen = array();
        foreach ($files as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d) || empty($d['query']) || empty($d['report'])) { continue; }
            $slug = pmv_slug($d['query']);
            if (isset($query_seen[$slug])) { continue; }
            $query_seen[$slug] = true;
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
    echo '<title>' . $SITE_NAME . ' — 予測市場インテリジェンス</title>' . "\n";
    echo '<link>' . $BASE_URL . '/' . $THIS_FILE . '</link>' . "\n";
    echo '<description>AI生成のPolymarket予測市場分析レポート一覧</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . $BASE_URL . '/' . $THIS_FILE . '?feed" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rss_items as $r) {
        $query   = isset($r['query'])      ? $r['query']      : '';
        $summary = isset($r['summary'])    ? $r['summary']    : '';
        $date    = isset($r['created_at']) ? $r['created_at'] : '';
        $link    = $BASE_URL . '/' . $THIS_FILE . '?query=' . urlencode($query);
        echo '<item><title><![CDATA[' . $query . ' — Polymarket Analysis]]></title>' . "\n";
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
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin     = ($session_user === $ADMIN);

$detail_query  = isset($_GET['query']) ? trim($_GET['query']) : '';
$detail_report = null;
if ($detail_query !== '') {
    foreach ($reports as $r) {
        if (isset($r['query']) && strtolower(pmv_slug($r['query'])) === strtolower(pmv_slug($detail_query))) {
            $detail_report = $r;
            break;
        }
    }
}

if (!$detail_report) {
    foreach ($reports as &$r) {
        $r = array(
            'query'           => isset($r['query'])           ? $r['query']           : '',
            'depth'           => isset($r['depth'])           ? $r['depth']           : 'medium',
            'summary'         => isset($r['summary'])         ? $r['summary']         : '',
            'matched_markets' => isset($r['matched_markets']) ? $r['matched_markets'] : array(),
            'created_at'      => isset($r['created_at'])      ? $r['created_at']      : '',
            'paragraph_url'   => isset($r['paragraph_url'])   ? $r['paragraph_url']   : '',
            'paragraph_post_id' => isset($r['paragraph_post_id']) ? $r['paragraph_post_id'] : '',
        );
    }
    unset($r);
}

/* SEO */
if ($detail_report) {
    $page_title       = h($detail_report['query']) . ' | ' . $SITE_NAME;
    $page_description = h(mb_substr(str_replace("\n", ' ', isset($detail_report['summary']) ? $detail_report['summary'] : ''), 0, 160));
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?query=' . urlencode($detail_report['query']);
    $page_type        = 'article';
} else {
    $page_title       = $SITE_NAME . ' — Polymarket予測市場分析一覧';
    $page_description = '自然言語クエリから生成したPolymarket予測市場インテリジェンスレポートの一覧。';
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
<meta property="og:image" content="<?php echo $BASE_URL; ?>/images/polymarket.png?v=<?php echo date('Ymd'); ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@xb_bittensor">
<meta name="twitter:title" content="<?php echo $page_title; ?>">
<meta name="twitter:description" content="<?php echo $page_description; ?>">
<meta name="twitter:image" content="<?php echo $BASE_URL; ?>/images/polymarket.png?v=<?php echo date('Ymd'); ?>">
<link rel="alternate" type="application/rss+xml" title="<?php echo h($SITE_NAME); ?> RSS" href="<?php echo $BASE_URL . '/' . $THIS_FILE . '?feed'; ?>">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo h(AIGM_GTAG_ID); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo h(AIGM_GTAG_ID); ?>');</script>
<script>(function(){var s=document.createElement('script');s.src='https://aiknowledgecms.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s);})();</script>
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
*{box-sizing:border-box;margin:0;padding:0}
:root{
    --accent:#6d28d9;--accent-h:#7c3aed;--accent-bg:#f5f3ff;--accent-light:#ede9fe;
    --border:#e2e8f0;--muted:#64748b;--text:#0f172a;--surface:#fff;--bg:#f8fafc;
    --green:#059669;--red:#dc2626;
    --mono:'JetBrains Mono',monospace;
}
body{background:var(--bg);color:var(--text);font-family:-apple-system,'Inter',sans-serif;font-size:14px;}
body.page-busy{cursor:progress}
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
.btn-sm:hover{border-color:var(--red);color:var(--red);}
.rss-link{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;color:#c44f00;background:#fff5ef;border:1px solid #f5d0b8;border-radius:4px;padding:2px 7px;text-decoration:none;}

/* 一覧 */
.container{max-width:700px;margin:0 auto;padding:0 0 80px;}
.count-bar{padding:10px 20px;font-size:13px;color:var(--muted);border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;}
.post-card{border-bottom:1px solid #f0f0f0;padding:20px;transition:background .15s;cursor:pointer;}
.post-card:hover{background:#fafafa;}
.card-top{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;}
.query-badge{background:var(--accent);color:#fff;font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.depth-pill{background:var(--accent-light);color:var(--accent);font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;}
.market-count{background:#f1f5f9;color:var(--muted);font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;}
.card-date{color:#aaa;font-size:12px;margin-left:auto;}
.card-title{font-size:15px;font-weight:700;color:var(--text);margin-bottom:8px;}
.card-title a{color:inherit;text-decoration:none;}
.card-title a:hover{color:var(--accent);}
.summary-block{background:var(--accent-bg);border-left:3px solid var(--accent);border-radius:0 8px 8px 0;padding:10px 14px;font-size:13px;line-height:1.75;color:#2e1065;margin-bottom:10px;max-height:80px;overflow:hidden;position:relative;}
.summary-block::after{content:'';position:absolute;bottom:0;left:0;right:0;height:24px;background:linear-gradient(transparent,var(--accent-bg));pointer-events:none;}
.card-links{display:flex;gap:6px;flex-wrap:wrap;}
.card-link{display:inline-flex;align-items:center;gap:5px;background:#f5f5f5;border:1px solid var(--border);border-radius:6px;padding:5px 11px;text-decoration:none;color:#555;font-size:12px;transition:all .15s;cursor:pointer;}
.card-link:hover{background:var(--accent-bg);border-color:var(--accent);color:var(--accent);}
.empty{text-align:center;color:#bbb;padding:80px 20px;font-size:15px;}
.empty a{color:var(--accent);text-decoration:none;}

/* 詳細 */
.detail-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);background:var(--surface);}
.detail-query{font-size:20px;font-weight:700;color:var(--accent);margin-bottom:6px;}
.detail-meta{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.detail-depth{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600;background:var(--accent-light);color:var(--accent);}
.detail-summary{background:var(--accent-bg);border-left:3px solid var(--accent);border-radius:0 8px 8px 0;padding:12px 16px;font-size:.88rem;line-height:1.8;color:#2e1065;margin:16px 24px 0;}
/* マーケットテーブル */
.markets-wrap{margin:16px 24px 0;overflow-x:auto;}
.markets-table{width:100%;border-collapse:collapse;font-size:.8rem;}
.markets-table th{text-align:left;padding:.4rem .6rem;border-bottom:2px solid var(--border);color:var(--muted);font-weight:600;font-size:.75rem;white-space:nowrap;}
.markets-table td{padding:.5rem .6rem;border-bottom:1px solid var(--border);vertical-align:top;}
.markets-table tr:last-child td{border-bottom:none;}
.market-title-cell{font-weight:600;color:var(--text);line-height:1.4;}
.market-slug-cell{font-family:var(--mono);font-size:.7rem;color:var(--muted);margin-top:2px;}
.odds-bar{display:flex;gap:4px;flex-wrap:wrap;}
.odds-item{padding:2px 6px;border-radius:10px;font-size:.72rem;font-weight:600;}
.odds-high{background:#dcfce7;color:#166534;}
.odds-mid{background:#fef9c3;color:#854d0e;}
.odds-low{background:#f1f5f9;color:#64748b;}
.vol-cell{font-family:var(--mono);font-size:.75rem;color:var(--muted);}
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
.btn-purple{background:var(--accent);color:#fff;border:none;}
.btn-purple:hover{background:var(--accent-h);}
.btn-outline{background:#f1f5f9;color:var(--text);border:1px solid var(--border);}
.btn-outline:hover{background:var(--accent-bg);border-color:var(--accent);color:var(--accent);}
.btn-para{background:#4f46e5;color:#fff;border:none;}
.btn-para:hover{background:#4338ca;}
.para-badge{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;}
.para-badge:hover{background:#e0e7ff;}
.sources-list{margin-top:20px;}
.sources-title{font-size:12px;font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;}
.sources-list ol{padding-left:1.2rem;font-size:.78rem;line-height:1.9;}
.sources-list a{color:var(--accent);text-decoration:none;word-break:break-all;}
.sources-list a:hover{text-decoration:underline;}
#copy-toast{position:fixed;left:50%;bottom:20px;transform:translateX(-50%) translateY(10px);background:#0f172a;color:#fff;padding:10px 16px;border-radius:10px;font-size:13px;box-shadow:0 10px 30px rgba(15,23,42,.2);opacity:0;pointer-events:none;transition:all .18s ease;z-index:9999}
#copy-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
#busy-overlay{position:fixed;inset:0;background:rgba(248,250,252,.7);backdrop-filter:blur(1px);z-index:9998;display:none}
body.page-busy #busy-overlay{display:block}
</style>
</head>
<body>

<div class="header">
    <div style="font-size:22px">🔮</div>
    <?php if ($detail_report): ?>
    <div class="logo-group"><div class="logo"><a href="<?php echo h($THIS_FILE); ?>" style="text-decoration:none;color:inherit;">Poly<span>Market</span> Intel</a></div><span class="u2a-badge">URL2AI</span></div>
    <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
    <?php else: ?>
    <div class="logo-group"><div class="logo">Poly<span>Market</span> Intel</div><span class="u2a-badge">URL2AI</span></div>
    <span class="badge">予測市場分析</span>
    <a href="<?php echo h($THIS_FILE . '?feed'); ?>" class="rss-link" title="RSSフィード">
        <svg width="10" height="10" viewBox="0 0 8 8"><circle cx="1.5" cy="6.5" r="1.5" fill="#c44f00"/><path d="M0 4.5A3.5 3.5 0 013.5 8" stroke="#c44f00" stroke-width="1.2" fill="none"/><path d="M0 2A6 6 0 016 8" stroke="#c44f00" stroke-width="1.2" fill="none"/></svg>
        RSS
    </a>
    <div class="userbar">
        <a href="polymarket.php" class="btn-sm">+ 新規生成</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($detail_report): ?>
<!-- ========== 詳細ページ ========== -->
<div class="detail-header">
    <div class="detail-query"><?php echo h($detail_report['query']); ?></div>
    <div class="detail-meta">
        <span class="detail-depth"><?php echo h(isset($detail_report['depth']) ? $detail_report['depth'] : 'medium'); ?></span>
        <span>生成日: <?php echo h(isset($detail_report['created_at']) ? $detail_report['created_at'] : ''); ?></span>
        <?php if (!empty($detail_report['matched_markets'])): ?>
        <span>マーケット: <?php echo count($detail_report['matched_markets']); ?> 件</span>
        <?php endif; ?>
        <?php if (!empty($detail_report['sources'])): ?>
        <span>参照ソース: <?php echo count($detail_report['sources']); ?> 件</span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($detail_report['summary'])): ?>
<div class="detail-summary"><?php echo h($detail_report['summary']); ?></div>
<?php endif; ?>

<?php if (!empty($detail_report['matched_markets'])): ?>
<div class="markets-wrap">
    <table class="markets-table">
        <thead>
            <tr>
                <th>マーケット</th>
                <th>オッズ</th>
                <th>ボリューム</th>
                <th>流動性</th>
                <th>終了日</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($detail_report['matched_markets'] as $mkt): ?>
        <tr>
            <td>
                <div class="market-title-cell"><?php echo h(isset($mkt['title']) ? $mkt['title'] : ''); ?></div>
                <?php if (!empty($mkt['slug'])): ?>
                <div class="market-slug-cell"><?php echo h($mkt['slug']); ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($mkt['odds']) && is_array($mkt['odds'])): ?>
                <div class="odds-bar">
                    <?php foreach ($mkt['odds'] as $label => $val):
                        $pct = (float)$val * 100;
                        $cls = $pct >= 60 ? 'odds-high' : ($pct >= 30 ? 'odds-mid' : 'odds-low');
                    ?>
                    <span class="odds-item <?php echo $cls; ?>"><?php echo h($label); ?>: <?php echo number_format($pct, 0); ?>%</span>
                    <?php endforeach; ?>
                </div>
                <?php elseif (!empty($mkt['top_outcome'])): ?>
                <span class="odds-item odds-high"><?php echo h($mkt['top_outcome']); ?></span>
                <?php endif; ?>
            </td>
            <td class="vol-cell"><?php echo h(isset($mkt['volume'])    ? $mkt['volume']    : '-'); ?></td>
            <td class="vol-cell"><?php echo h(isset($mkt['liquidity']) ? $mkt['liquidity'] : '-'); ?></td>
            <td class="vol-cell"><?php echo h(isset($mkt['end_date'])  ? $mkt['end_date']  : '-'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="padding:0 24px;">
    <div class="detail-actions">
        <button class="btn-purple" type="button" onclick="copyReport()">📋 Markdownコピー</button>
        <?php if (pmv_is_valid_paragraph_url(isset($detail_report['paragraph_url']) ? $detail_report['paragraph_url'] : '')): ?>
        <a class="para-badge" href="<?php echo h($detail_report['paragraph_url']); ?>" target="_blank" rel="noopener">✅ Paragraph</a>
        <?php elseif (!empty($detail_report['paragraph_post_id'])): ?>
        <span class="para-badge">✅ Paragraph</span>
        <?php elseif ($is_admin): ?>
        <button class="btn-para" id="para-post-btn" type="button" onclick="postToParagraph(<?php echo json_encode($detail_report['query']); ?>)">📝 Paragraph</button>
        <?php endif; ?>
        <?php if ($is_admin): ?>
        <a class="btn-outline" href="polymarket.php?query=<?php echo urlencode($detail_report['query']); ?>">🔄 再生成</a>
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
        showToast('Markdownをコピーしました');
    });
}
function showToast(msg) {
    var t = document.getElementById('copy-toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2200);
}
function setPageBusy(busy, msg) {
    if (busy) {
        document.body.classList.add('page-busy');
        if (msg) showToast(msg);
    } else {
        document.body.classList.remove('page-busy');
    }
}
function postToParagraph(query) {
    var btn = document.getElementById('para-post-btn');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = '投稿中...';
    setPageBusy(true, 'Paragraphへ投稿中...');
    var xhr = new XMLHttpRequest();
    xhr.timeout = 20000;
    xhr.open('POST', 'polymarket.php?api=paragraph_post', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        setPageBusy(false);
        try {
            var res = JSON.parse(xhr.responseText || '{}');
            if (xhr.status >= 200 && xhr.status < 300 && res.ok && (res.paragraph_url || res.paragraph_post_id)) {
                if (res.paragraph_url) {
                    btn.outerHTML = '<a class="para-badge" href="' + encodeURI(res.paragraph_url) + '" target="_blank" rel="noopener">✅ Paragraph</a>';
                } else {
                    btn.outerHTML = '<span class="para-badge">✅ Paragraph</span>';
                }
                showToast('Paragraphに投稿しました');
                return;
            }
            showToast((res && res.error) ? res.error : 'Paragraph投稿に失敗しました');
        } catch (e) {
            showToast('Paragraph投稿に失敗しました');
        }
        btn.textContent = '📝 Paragraph';
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.textContent = '📝 Paragraph';
        setPageBusy(false);
        showToast('通信エラー');
    };
    xhr.ontimeout = function() {
        btn.disabled = false;
        btn.textContent = '📝 Paragraph';
        setPageBusy(false);
        showToast('投稿がタイムアウトしました');
    };
    xhr.send(JSON.stringify({ query: query }));
}
</script>

<?php else: ?>
<!-- ========== 一覧ページ ========== -->
<div class="container">
    <div class="count-bar">
        🔮 <?php echo count($reports); ?> 件のレポート
    </div>
    <div id="report-list"></div>
</div>

<script>
var pmReports = <?php echo json_encode(array_values($reports), JSON_UNESCAPED_UNICODE); ?>;
var IS_ADMIN  = <?php echo $is_admin ? 'true' : 'false'; ?>;

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showToast(msg) {
    var t = document.getElementById('copy-toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2200);
}
function setPageBusy(busy, msg) {
    if (busy) {
        document.body.classList.add('page-busy');
        if (msg) showToast(msg);
    } else {
        document.body.classList.remove('page-busy');
    }
}

function buildParaBtn(report, idx) {
    var url = report.paragraph_url || '';
    var isValid = url !== '' && url.indexOf('aiknowledgecms.exbridge.jp') === -1 && (url.indexOf('paragraph.com') !== -1 || url.indexOf('paragraph.xyz') !== -1);
    if (isValid) {
        return '<a class="card-link" href="' + esc(url) + '" target="_blank" rel="noopener">✅ Paragraph</a>';
    }
    if (report.paragraph_post_id) {
        return '<span class="card-link">✅ Paragraph</span>';
    }
    if (IS_ADMIN) {
        return '<button class="card-link" type="button" id="pm-para-btn-' + idx + '" onclick="pmParaPost(' + idx + ')">📝 Paragraph</button>';
    }
    return '';
}
function pmParaPost(idx) {
    var report = pmReports[idx];
    if (!report) return;
    var btn = document.getElementById('pm-para-btn-' + idx);
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = '投稿中...';
    setPageBusy(true, 'Paragraphへ投稿中...');
    var xhr = new XMLHttpRequest();
    xhr.timeout = 20000;
    xhr.open('POST', 'polymarket.php?api=paragraph_post', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        setPageBusy(false);
        try {
            var res = JSON.parse(xhr.responseText || '{}');
            if (xhr.status >= 200 && xhr.status < 300 && res.ok && (res.paragraph_url || res.paragraph_post_id)) {
                report.paragraph_url     = res.paragraph_url     || '';
                report.paragraph_post_id = res.paragraph_post_id || '';
                if (res.paragraph_url) {
                    btn.outerHTML = '<a class="card-link" href="' + esc(res.paragraph_url) + '" target="_blank" rel="noopener">✅ Paragraph</a>';
                } else {
                    btn.outerHTML = '<span class="card-link">✅ Paragraph</span>';
                }
                showToast('Paragraphに投稿しました');
                return;
            }
            showToast((res && res.error) ? res.error : 'Paragraph投稿に失敗しました');
        } catch (e) {
            showToast('Paragraph投稿に失敗しました');
        }
        btn.textContent = '📝 Paragraph';
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.textContent = '📝 Paragraph';
        setPageBusy(false);
        showToast('通信エラー');
    };
    xhr.ontimeout = function() {
        btn.disabled = false;
        btn.textContent = '📝 Paragraph';
        setPageBusy(false);
        showToast('投稿がタイムアウトしました');
    };
    xhr.send(JSON.stringify({ query: report.query }));
}
function copyListReport(idx) {
    var report = pmReports[idx];
    if (!report) return;
    var btn = document.getElementById('pm-copy-btn-' + idx);
    var detailUrl = 'https://aiknowledgecms.exbridge.jp/polymarketv.php?query=' + encodeURIComponent(report.query || '');
    var text = '#URL2AI Polymarket Intel: ' + (report.query || '') + '\n\n' + (report.summary || '') + '\n\n' + detailUrl;
    navigator.clipboard.writeText(text).then(function() {
        if (btn) {
            btn.textContent = '✓ コピー済';
            setTimeout(function() { btn.textContent = '📋 コピー'; }, 2000);
        }
        showToast('コピーしました');
    }, function() {
        showToast('コピーに失敗しました');
    });
}

function renderReports() {
    var list = document.getElementById('report-list');
    if (!list) return;
    for (var i = 0; i < pmReports.length; i++) {
        var r         = pmReports[i];
        var query     = r.query      || '';
        var depth     = r.depth      || 'medium';
        var summary   = r.summary    || '';
        var date      = r.created_at || '';
        var mkts      = r.matched_markets || [];
        var detailUrl = 'polymarketv.php?query=' + encodeURIComponent(query);
        var paraHtml  = buildParaBtn(r, i);
        var mktBadge  = mkts.length ? '<span class="market-count">市場 ' + mkts.length + '件</span>' : '';

        var html = '<div class="post-card" onclick="location.href=\'' + esc(detailUrl) + '\'">'
            + '<div class="card-top">'
            + '<span class="query-badge">' + esc(query) + '</span>'
            + '<span class="depth-pill">' + esc(depth) + '</span>'
            + mktBadge
            + '<span class="card-date">' + esc(date) + '</span>'
            + '</div>'
            + '<div class="card-title"><a href="' + esc(detailUrl) + '" onclick="event.stopPropagation()">' + esc(query) + '</a></div>'
            + (summary ? '<div class="summary-block">' + esc(summary) + '</div>' : '')
            + '<div class="card-links" onclick="event.stopPropagation()">'
            + '<a class="card-link" href="' + esc(detailUrl) + '" onclick="event.stopPropagation()">📄 詳細を見る</a>'
            + '<button class="card-link" type="button" id="pm-copy-btn-' + i + '" onclick="copyListReport(' + i + '); return false;">📋 コピー</button>'
            + paraHtml
            + (IS_ADMIN ? '<a class="card-link" href="polymarket.php?query=' + encodeURIComponent(query) + '" onclick="event.stopPropagation()">🔄 再生成</a>' : '')
            + '</div>'
            + '</div>';
        list.insertAdjacentHTML('beforeend', html);
    }
}

if (pmReports.length === 0) {
    document.getElementById('report-list').innerHTML = '<div class="empty">まだレポートがありません。<br><br><a href="polymarket.php">Polymarket Intelでレポートを生成する →</a></div>';
} else {
    renderReports();
}
</script>

<?php endif; ?>

<div id="busy-overlay"></div>
<div id="copy-toast">処理中...</div>
</body>
</html>
