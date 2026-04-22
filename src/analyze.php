<?php
date_default_timezone_set("Asia/Tokyo");

$logfile = __DIR__ . "/access.log";

/* =========================
   システム判定
========================= */
function detect_system($url){
    if($url === "") return "other";
    $u = strtolower($url);

    /* 順序重要: 長いパターンを先に */
    if(strpos($u, "uparsev") !== false || strpos($u, "uparse") !== false)      return "uparse";
    if(strpos($u, "udebate") !== false)                                          return "udebate";
    if(strpos($u, "umediav") !== false || strpos($u, "umedia") !== false)        return "umedia";
    if(strpos($u, "usongv")  !== false || strpos($u, "usong")  !== false)        return "usong";
    if(strpos($u, "ustoryv") !== false || strpos($u, "ustory") !== false)        return "ustory";
    if(strpos($u, "xinsightv") !== false || strpos($u, "xinsight") !== false)   return "xinsight";
    if(strpos($u, "xview") !== false)                                            return "xview";
    if(strpos($u, "osszenn") !== false)                                          return "osszenn";
    if(strpos($u, "saveoss") !== false || strpos($u, "/oss") !== false)          return "oss";
    if(strpos($u, "ainews") !== false)                                           return "ainews";
    if(strpos($u, "aitech") !== false)                                           return "aitech";
    if(strpos($u, "aitrend") !== false)                                          return "aitrend";
    if(strpos($u, "newskeyword") !== false)                                      return "newskeyword";
    if(strpos($u, "aiknowledgecms") !== false || strpos($u, "kw=") !== false)   return "cms";
    if(strpos($u, "knowradar") !== false)                                        return "knowradar";
    if(strpos($u, "simpletrack") !== false || strpos($u, "analyze") !== false)  return "analytics";
    return "other";
}

$SYSTEM_META = array(
    "cms"         => array("label" => "AIKnowledgeCMS",  "color" => "#a855f7"),
    "aitrend"     => array("label" => "AITrend",         "color" => "#06b6d4"),
    "newskeyword" => array("label" => "NewsKeyword",     "color" => "#3b82f6"),
    "ustory"      => array("label" => "UStory",          "color" => "#7c3aed"),
    "uparse"      => array("label" => "UParse",          "color" => "#0f766e"),
    "usong"       => array("label" => "USong",           "color" => "#db2777"),
    "umedia"      => array("label" => "UMedia",          "color" => "#0891b2"),
    "udebate"     => array("label" => "UDebate",         "color" => "#6d28d9"),
    "xinsight"    => array("label" => "XInsight",        "color" => "#2563eb"),
    "xview"       => array("label" => "XView",           "color" => "#0f766e"),
    "oss"         => array("label" => "OSS",             "color" => "#6c63ff"),
    "osszenn"     => array("label" => "OSSZenn",         "color" => "#3b82f6"),
    "aitech"      => array("label" => "AITech",          "color" => "#0ea5e9"),
    "ainews"      => array("label" => "AI News Radar",   "color" => "#e11d48"),
    "knowradar"   => array("label" => "KnowRader",       "color" => "#6366f1"),
    "analytics"   => array("label" => "Analytics",       "color" => "#64748b"),
    "other"       => array("label" => "Other",           "color" => "#475569"),
);

if(!file_exists($logfile)){
    die("<pre>log not found: $logfile</pre>");
}

clearstatcache();
$lines = file($logfile);

/* =========================
   ログパース
========================= */
$pv_per_day    = array();
$system_pv     = array();
$system_daily  = array();  // [system][date] => count
$url_count     = array();
$ref_count     = array();
$ip_set        = array();
$hour_count    = array_fill(0, 24, 0);
$total         = 0;
$bot_count     = 0;
$latest_logs   = array();   // 最新50件

$bot_patterns = array("bot","crawler","spider","curl","python","wget","scrapy","headless","phantom","selenium");

foreach($lines as $line){
    $parts = explode(" | ", trim($line));
    if(count($parts) < 5) continue;

    $datetime = $parts[0];
    $ip       = $parts[1];
    $url      = $parts[2];
    $ref      = $parts[3];
    $ua       = isset($parts[4]) ? $parts[4] : "";

    $date = substr($datetime, 0, 10);
    $hour = (int)substr($datetime, 11, 2);

    // bot判定
    $ua_lower = strtolower($ua);
    $is_bot = false;
    foreach($bot_patterns as $bp){
        if(strpos($ua_lower, $bp) !== false){ $is_bot = true; break; }
    }
    if($is_bot){ $bot_count++; continue; }

    // admin除外
    if(strpos($url, "admin") !== false) continue;

    $system = detect_system($url);

    // PV集計
    if(!isset($pv_per_day[$date])) $pv_per_day[$date] = 0;
    $pv_per_day[$date]++;

    if(!isset($system_pv[$system])) $system_pv[$system] = 0;
    $system_pv[$system]++;

    if(!isset($system_daily[$system][$date])) $system_daily[$system][$date] = 0;
    $system_daily[$system][$date]++;

    // URL集計
    if($url !== ""){
        if(!isset($url_count[$url])) $url_count[$url] = 0;
        $url_count[$url]++;
    }

    // Ref集計
    if($ref !== ""){
        // 自サイト内の無意味なrefは除外（kw=なしのexbridge.jp）
        $allow_ref = true;
        if(strpos($ref, "exbridge.jp") !== false && strpos($ref, "kw=") === false){
            $allow_ref = false;
        }
        if(strpos($ref, "admin") !== false) $allow_ref = false;
        if($allow_ref){
            if(!isset($ref_count[$ref])) $ref_count[$ref] = 0;
            $ref_count[$ref]++;
        }
    }

    // UU
    $ip_set[$ip] = true;

    // 時間帯
    if($hour >= 0 && $hour <= 23) $hour_count[$hour]++;

    $total++;

    // 最新ログ
    $latest_logs[] = array(
        "datetime" => $datetime,
        "ip"       => $ip,
        "url"      => urldecode($url),
        "ref"      => urldecode($ref),
        "system"   => $system,
    );
}

ksort($pv_per_day);
arsort($url_count);
arsort($ref_count);
arsort($system_pv);

// 最新50件（逆順）
$latest_logs = array_slice(array_reverse($latest_logs), 0, 100);

$uu = count($ip_set);

// 日付軸（全システム共通）
$all_dates = array_keys($pv_per_day);

// システム別daily seriesをJSON化
$system_series = array();
foreach($system_daily as $sys => $daily){
    $values = array();
    foreach($all_dates as $d){
        $values[] = isset($daily[$d]) ? $daily[$d] : 0;
    }
    $meta = isset($SYSTEM_META[$sys]) ? $SYSTEM_META[$sys] : array("label"=>$sys,"color"=>"#888");
    $system_series[] = array(
        "label"           => $meta["label"],
        "data"            => $values,
        "borderColor"     => $meta["color"],
        "backgroundColor" => $meta["color"] . "33",
        "tension"         => 0.3,
        "fill"            => false,
        "pointRadius"     => 3,
    );
}

usort($system_series, function($a, $b){
    $sumA = array_sum($a["data"]);
    $sumB = array_sum($b["data"]);
    if($sumA === $sumB) return strcmp($a["label"], $b["label"]);
    return ($sumB > $sumA) ? 1 : -1;
});

$top_system_series = array_slice($system_series, 0, 8);

// Top URLs per system (kw= filter for cms/aitrend)
$system_urls = array();
foreach($url_count as $u => $c){
    $sys = detect_system($u);
    if(!isset($system_urls[$sys])) $system_urls[$sys] = array();
    if(count($system_urls[$sys]) < 20) $system_urls[$sys][$u] = $c;
}

// Top Refs (overall top20)
$top_refs = array_slice($ref_count, 0, 20, true);

// JSON出力用
$j_dates         = json_encode($all_dates);
$j_pv_counts     = json_encode(array_values($pv_per_day));
$j_hour_labels   = json_encode(range(0,23));
$j_hour_counts   = json_encode(array_values($hour_count));
$j_system_series = json_encode($system_series, JSON_UNESCAPED_UNICODE);
$j_top_system_series = json_encode($top_system_series, JSON_UNESCAPED_UNICODE);

// systemPv (ドーナツ用)
$donut_labels = array();
$donut_data   = array();
$donut_colors = array();
foreach($system_pv as $sys => $cnt){
    $meta = isset($SYSTEM_META[$sys]) ? $SYSTEM_META[$sys] : array("label"=>$sys,"color"=>"#888");
    $donut_labels[] = $meta["label"];
    $donut_data[]   = $cnt;
    $donut_colors[] = $meta["color"];
}
$j_donut_labels = json_encode($donut_labels, JSON_UNESCAPED_UNICODE);
$j_donut_data   = json_encode($donut_data);
$j_donut_colors = json_encode($donut_colors);

$j_ref_labels   = json_encode(array_map('urldecode', array_keys($top_refs)), JSON_UNESCAPED_UNICODE);
$j_ref_counts   = json_encode(array_values($top_refs));

$j_latest       = json_encode($latest_logs, JSON_UNESCAPED_UNICODE);

// システム別URL JSON (タブ切り替え用)
$j_system_urls  = json_encode($system_urls, JSON_UNESCAPED_UNICODE);
$j_system_meta  = json_encode($SYSTEM_META, JSON_UNESCAPED_UNICODE);

$period_start = count($all_dates) ? $all_dates[0] : "-";
$period_end   = count($all_dates) ? $all_dates[count($all_dates)-1] : "-";
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AIGM Analyze</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root{
    --bg0:   #f4f6f9;
    --bg1:   #ffffff;
    --bg2:   #f8fafc;
    --bg3:   #eef2f7;
    --border:#d1dae6;
    --text:  #1e293b;
    --muted: #64748b;
    --acc1:  #0284c7;
    --acc2:  #7c3aed;
    --acc3:  #16a34a;
    --font-mono: 'JetBrains Mono','Fira Code','Courier New',monospace;
    --font-ui:   'IBM Plex Sans JP','Noto Sans JP',sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}

body{
    background:var(--bg0);
    color:var(--text);
    font-family:var(--font-ui);
    font-size:14px;
    min-height:100vh;
}

/* ── グリッドノイズ背景 ── */
body::before{
    content:'';
    position:fixed;inset:0;
    background-image:
        linear-gradient(rgba(2,132,199,.04) 1px,transparent 1px),
        linear-gradient(90deg,rgba(2,132,199,.04) 1px,transparent 1px);
    background-size:40px 40px;
    pointer-events:none;
    z-index:0;
}

.wrap{position:relative;z-index:1;max-width:1400px;margin:0 auto;padding:32px 24px;}

/* ── ヘッダー ── */
.topbar{
    display:flex;align-items:center;gap:16px;
    border-bottom:1px solid var(--border);
    padding-bottom:20px;margin-bottom:32px;
}
.topbar .logo{
    font-family:var(--font-mono);
    font-size:22px;letter-spacing:.08em;
    color:var(--acc1);
    text-shadow:0 0 20px rgba(2,132,199,.2);
}
.topbar .logo span{color:var(--acc2);}
.topbar .sub{font-size:11px;color:var(--muted);margin-left:auto;font-family:var(--font-mono);}

/* ── KPI カード ── */
.kpi-row{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
    gap:12px;
    margin-bottom:32px;
}
.kpi{
    background:var(--bg2);
    border:1px solid var(--border);
    border-radius:8px;
    padding:16px 20px;
    position:relative;
    overflow:hidden;
}
.kpi::after{
    content:'';
    position:absolute;top:0;left:0;right:0;height:2px;
    background:var(--kpi-color,var(--acc1));
}
.kpi .label{font-size:11px;color:var(--muted);letter-spacing:.05em;text-transform:uppercase;margin-bottom:6px;}
.kpi .val{font-size:28px;font-family:var(--font-mono);color:#0f172a;font-weight:700;}
.kpi .sub{font-size:10px;color:var(--muted);margin-top:4px;}

/* ── セクション ── */
.section{
    background:var(--bg1);
    border:1px solid var(--border);
    border-radius:10px;
    padding:24px;
    margin-bottom:24px;
}
.section-sub{
    font-size:12px;
    color:var(--muted);
    margin:-6px 0 16px;
    line-height:1.7;
}
.section h2{
    font-size:13px;
    font-family:var(--font-mono);
    letter-spacing:.1em;
    color:var(--acc1);
    text-transform:uppercase;
    margin-bottom:16px;
    display:flex;align-items:center;gap:8px;
}
.section h2::before{content:'▎';color:var(--acc2);}

/* ── 2カラムグリッド ── */
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:24px;}
@media(max-width:900px){.grid2{grid-template-columns:1fr;}}

/* ── システムタブ ── */
.sys-tabs{
    display:flex;flex-wrap:wrap;gap:6px;
    margin-bottom:16px;
}
.sys-tab{
    padding:4px 12px;
    border-radius:20px;
    border:1px solid var(--border);
    background:var(--bg3);
    color:var(--muted);
    font-size:11px;
    font-family:var(--font-mono);
    cursor:pointer;
    transition:all .15s;
}
.sys-tab:hover{border-color:var(--acc1);color:var(--acc1);}
.sys-tab.active{background:var(--acc1);color:#000;border-color:var(--acc1);font-weight:700;}

/* ── テーブル ── */
.tbl{width:100%;border-collapse:collapse;}
.tbl th{
    background:var(--bg3);
    color:var(--muted);
    font-size:10px;
    letter-spacing:.08em;
    text-transform:uppercase;
    padding:8px 10px;
    text-align:left;
    border-bottom:1px solid var(--border);
    font-family:var(--font-mono);
}
.tbl td{
    padding:6px 10px;
    border-bottom:1px solid var(--border);
    font-size:12px;
    word-break:break-all;
    color:var(--text);
}
.tbl tr:hover td{background:var(--bg3);}
.tbl .num{
    font-family:var(--font-mono);
    color:#0f172a;
    text-align:right;
    white-space:nowrap;
}
.tbl .rank{color:var(--muted);font-family:var(--font-mono);text-align:center;width:36px;}

/* ── バッジ ── */
.badge{
    display:inline-block;
    padding:1px 7px;
    border-radius:10px;
    font-size:10px;
    font-family:var(--font-mono);
    font-weight:700;
    white-space:nowrap;
}

/* ── リアルタイムログ ── */
.log-row{
    display:grid;
    grid-template-columns:120px 100px 1fr auto;
    gap:8px;
    padding:5px 8px;
    border-bottom:1px solid var(--border);
    font-family:var(--font-mono);
    font-size:11px;
    align-items:center;
}
.log-row:hover{background:var(--bg3);}
.log-dt{color:var(--muted);}
.log-ip{color:var(--muted);}
.log-url{color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.log-sys{font-size:10px;}
.log-ref{font-size:10px;color:var(--muted);grid-column:3;padding-left:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

canvas{width:100%!important;}
.chart-wrap{position:relative;min-height:360px;}
.chart-note{margin-top:12px;font-size:11px;color:var(--muted);font-family:var(--font-mono);}

/* ── スクロールバー ── */
::-webkit-scrollbar{width:4px;height:4px;}
::-webkit-scrollbar-track{background:var(--bg3);}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px;}
</style>
</head>
<body>
<div class="wrap">

<!-- ヘッダー -->
<div class="topbar">
    <div class="logo">AIGM<span>::</span>Analyze</div>
    <div class="sub">
        <?php echo $period_start; ?> → <?php echo $period_end; ?>
        &nbsp;|&nbsp;
        <?php echo date("Y-m-d H:i:s"); ?> JST
    </div>
</div>

<!-- KPI -->
<div class="kpi-row">
    <div class="kpi" style="--kpi-color:#38bdf8">
        <div class="label">Total PV</div>
        <div class="val"><?php echo number_format($total); ?></div>
        <div class="sub">bot除外後</div>
    </div>
    <div class="kpi" style="--kpi-color:#a855f7">
        <div class="label">Unique IP</div>
        <div class="val"><?php echo number_format($uu); ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#22c55e">
        <div class="label">Active Days</div>
        <div class="val"><?php echo count($pv_per_day); ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#f59e0b">
        <div class="label">Avg PV/Day</div>
        <div class="val"><?php echo count($pv_per_day) ? round($total/count($pv_per_day),1) : 0; ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#64748b">
        <div class="label">Bot Filtered</div>
        <div class="val"><?php echo number_format($bot_count); ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#ec4899">
        <div class="label">Systems</div>
        <div class="val"><?php echo count($system_pv); ?></div>
    </div>
</div>

<!-- Daily PV (全体) + システム別積み上げ -->
<div class="section">
    <h2>Daily PV — システム別推移</h2>
    <div class="section-sub">線が重なりすぎないよう、PV 上位 8 システムのみを表示しています。</div>
    <div class="chart-wrap">
        <canvas id="pvChart"></canvas>
    </div>
    <div class="chart-note">凡例をクリックすると系列の表示を切り替えられます。</div>
</div>

<!-- システム構成 + 時間帯 -->
<div class="grid2">
    <div class="section">
        <h2>システム別 PV 構成</h2>
        <canvas id="donutChart" height="220"></canvas>
    </div>
    <div class="section">
        <h2>時間帯別アクセス分布</h2>
        <canvas id="hourChart" height="220"></canvas>
    </div>
</div>

<!-- システム別 Top URLs -->
<div class="section">
    <h2>システム別 Top URLs</h2>
    <div class="sys-tabs" id="urlTabs"></div>
    <div id="urlTableWrap"></div>
</div>

<!-- Top Referrers -->
<div class="section">
    <h2>Top Referrers</h2>
    <canvas id="refChart" height="120"></canvas>
</div>

<!-- 最新アクセスログ -->
<div class="section">
    <h2>最新アクセスログ（最新100件）</h2>
    <div id="logWrap"></div>
</div>

</div><!-- /wrap -->

<script>
Chart.defaults.color = '#64748b';
Chart.defaults.borderColor = '#d1dae6';

const SYSTEM_META = <?php echo $j_system_meta; ?>;

/* ── Daily PV (システム別折れ線) ── */
new Chart(document.getElementById('pvChart'),{
    type:'line',
    data:{
        labels: <?php echo $j_dates; ?>,
        datasets: <?php echo $j_top_system_series; ?>
    },
    options:{
        responsive:true,
        maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{
            legend:{
                position:'bottom',
                labels:{
                    font:{size:11},
                    boxWidth:12,
                    usePointStyle:true,
                    padding:16
                }
            },
            tooltip:{
                backgroundColor:'#0f172a',
                titleColor:'#f8fafc',
                bodyColor:'#e2e8f0',
                padding:12
            }
        },
        scales:{
            x:{
                ticks:{
                    font:{size:10},
                    maxRotation:0,
                    autoSkip:true,
                    maxTicksLimit:12
                },
                grid:{display:false}
            },
            y:{
                beginAtZero:true,
                ticks:{font:{size:10},precision:0},
                grid:{color:'#d1dae6'}
            }
        },
        elements:{
            line:{borderWidth:2.5},
            point:{radius:2,hoverRadius:5}
        }
    }
});

/* ── ドーナツ ── */
new Chart(document.getElementById('donutChart'),{
    type:'doughnut',
    data:{
        labels: <?php echo $j_donut_labels; ?>,
        datasets:[{
            data: <?php echo $j_donut_data; ?>,
            backgroundColor: <?php echo $j_donut_colors; ?>,
            borderColor:'#f4f6f9',
            borderWidth:2,
            hoverOffset:6
        }]
    },
    options:{
        responsive:true,
        plugins:{
            legend:{
                position:'right',
                labels:{font:{size:11},boxWidth:12,padding:8}
            }
        }
    }
});

/* ── 時間帯 ── */
new Chart(document.getElementById('hourChart'),{
    type:'bar',
    data:{
        labels: <?php echo $j_hour_labels; ?>,
        datasets:[{
            label:'PV',
            data: <?php echo $j_hour_counts; ?>,
            backgroundColor: <?php echo $j_hour_labels; ?>.map(function(h){
                if(h>=9&&h<=21) return '#0284c7bb';
                return '#d1dae699';
            }),
            borderRadius:3
        }]
    },
    options:{
        responsive:true,
        plugins:{legend:{display:false}},
        scales:{
            x:{ticks:{font:{size:10}},grid:{display:false}},
            y:{beginAtZero:true,ticks:{font:{size:10}}}
        }
    }
});

/* ── Referrer ── */
new Chart(document.getElementById('refChart'),{
    type:'bar',
    data:{
        labels: <?php echo $j_ref_labels; ?>,
        datasets:[{
            label:'流入数',
            data: <?php echo $j_ref_counts; ?>,
            backgroundColor:'#f59e0b99',
            borderRadius:3
        }]
    },
    options:{
        indexAxis:'y',
        responsive:true,
        plugins:{legend:{display:false}},
        scales:{
            x:{beginAtZero:true,ticks:{font:{size:10}}},
            y:{ticks:{font:{size:10},callback:function(v,i,a){
                const s=this.getLabelForValue(v);
                return s.length>60?s.slice(0,60)+'…':s;
            }}}
        }
    }
});

/* ── システム別URLタブ ── */
const sysUrls  = <?php echo $j_system_urls; ?>;
const tabsEl   = document.getElementById('urlTabs');
const tableWrap= document.getElementById('urlTableWrap');

const sysKeys = Object.keys(sysUrls);
let activeSys = sysKeys.length ? sysKeys[0] : null;

function renderUrlTab(sys){
    activeSys = sys;
    // タブ状態更新
    Array.from(tabsEl.children).forEach(function(btn){
        btn.classList.toggle('active', btn.dataset.sys===sys);
    });
    const data = sysUrls[sys];
    const meta = SYSTEM_META[sys] || {label:sys,color:'#888'};
    const rows = Object.entries(data);
    if(!rows.length){
        tableWrap.innerHTML='<p style="color:#4a6080;padding:16px;">データなし</p>';
        return;
    }
    let html = '<table class="tbl"><thead><tr>'
        +'<th class="rank">#</th>'
        +'<th>URL</th>'
        +'<th style="text-align:right">PV</th>'
        +'</tr></thead><tbody>';
    rows.forEach(function(kv, i){
        const u = kv[0]; const c = kv[1];
        html += '<tr>'
            +'<td class="rank">'+(i+1)+'</td>'
            +'<td><a href="'+u+'" target="_blank" style="color:'+meta.color+';text-decoration:none;font-size:12px">'+decodeURIComponent(u)+'</a></td>'
            +'<td class="num">'+c+'</td>'
            +'</tr>';
    });
    html += '</tbody></table>';
    tableWrap.innerHTML = html;
}

sysKeys.forEach(function(sys){
    const meta = SYSTEM_META[sys] || {label:sys,color:'#888'};
    const btn = document.createElement('button');
    btn.className = 'sys-tab';
    btn.dataset.sys = sys;
    btn.textContent = meta.label;
    btn.style.setProperty('--tc', meta.color);
    btn.addEventListener('click', function(){ renderUrlTab(sys); });
    tabsEl.appendChild(btn);
});

if(activeSys) renderUrlTab(activeSys);

/* ── 最新ログ ── */
const logs = <?php echo $j_latest; ?>;
const logWrap = document.getElementById('logWrap');

function buildLogs(){
    let html = '';
    logs.forEach(function(row){
        const meta = SYSTEM_META[row.system] || {label:row.system,color:'#888'};
        const badge = '<span class="badge" style="background:'+meta.color+'22;color:'+meta.color+';border:1px solid '+meta.color+'44">'+meta.label+'</span>';
        html += '<div class="log-row">'
            +'<span class="log-dt">'+row.datetime+'</span>'
            +'<span class="log-ip">'+row.ip+'</span>'
            +'<span class="log-url" title="'+row.url+'">'+row.url+'</span>'
            +'<span class="log-sys">'+badge+'</span>';
        if(row.ref){
            html += '<span style="grid-column:2/5;padding:0 8px 4px;font-size:10px;color:#4a6080;font-family:var(--font-mono);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">↳ '+row.ref+'</span>';
        }
        html += '</div>';
    });
    logWrap.innerHTML = html;
}
buildLogs();
</script>
</body>
</html>
