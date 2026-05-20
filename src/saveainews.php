<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

define('DATA_FILE', __DIR__ . '/data/ainews_posts.json');
define('AIXEC_SNS_API_URL', 'https://aixec.exbridge.jp/api.php?path=posts');
define('AINEWS_BASE_URL', 'https://aiknowledgecms.exbridge.jp/ainews.php');

function ainews_build_sns_notice($post) {
    $title = isset($post['title']) ? trim((string)$post['title']) : '';
    $summary = isset($post['summary']) ? trim((string)$post['summary']) : '';
    $article_url = isset($post['article_url']) ? trim((string)$post['article_url']) : '';
    $tweet_url = isset($post['tweet_url']) ? trim((string)$post['tweet_url']) : '';
    $id = isset($post['id']) ? trim((string)$post['id']) : '';
    $detail_url = $id !== '' ? AINEWS_BASE_URL . '?id=' . rawurlencode($id) : AINEWS_BASE_URL;

    $lines = array('📰 AI News Radarに新しいニュースを登録しました');
    if ($title !== '') {
        $lines[] = '';
        $lines[] = $title;
    }
    if ($summary !== '') {
        $lines[] = '';
        $lines[] = $summary;
    }
    $lines[] = '';
    $lines[] = '詳細:';
    $lines[] = $detail_url;
    if ($article_url !== '') {
        $lines[] = '';
        $lines[] = '記事:';
        $lines[] = $article_url;
    }
    if ($tweet_url !== '') {
        $lines[] = '';
        $lines[] = '元投稿:';
        $lines[] = $tweet_url;
    }
    return trim(implode("\n", $lines));
}

function ainews_post_sns_notice($post) {
    $content = ainews_build_sns_notice($post);
    if ($content === '') {
        return array('ok' => false, 'error' => 'empty notice');
    }

    $payload = json_encode(array(
        'author' => 'ainews',
        'content' => $content,
    ), JSON_UNESCAPED_UNICODE);
    $opts = array('http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json; charset=utf-8\r\n",
        'content' => $payload,
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents(AIXEC_SNS_API_URL, false, stream_context_create($opts));
    $data = $res ? json_decode($res, true) : null;
    if (!is_array($data) || empty($data['ok'])) {
        return array(
            'ok' => false,
            'error' => is_array($data) && isset($data['error']) ? $data['error'] : 'sns post failed',
        );
    }
    return array(
        'ok' => true,
        'id' => isset($data['item']['id']) ? $data['item']['id'] : null,
    );
}

function ainews_extract_urls_from_text($text) {
    preg_match_all('/https?:\/\/[^\s<>"\']+/u', (string)$text, $matches);
    $urls = isset($matches[0]) ? $matches[0] : array();
    $clean = array();
    foreach ($urls as $url) {
        $url = rtrim($url, "。、，,.)]}>\"'");
        if ($url !== '') {
            $clean[] = $url;
        }
    }
    return array_values(array_unique($clean));
}

function ainews_strip_urls($text) {
    $text = preg_replace('/https?:\/\/[^\s<>"\']+/u', '', (string)$text);
    return trim(preg_replace('/[ \t]+/u', ' ', $text));
}

function ainews_clean_utf8($value) {
    if (is_array($value)) {
        $clean = array();
        foreach ($value as $k => $v) {
            $clean[$k] = ainews_clean_utf8($v);
        }
        return $clean;
    }
    if (is_string($value)) {
        $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);
        return $value === false ? '' : $value;
    }
    return $value;
}

function ainews_json_response($data) {
    $data = ainews_clean_utf8($data);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(array(
            'status' => 'error',
            'error' => 'JSON生成に失敗しました: ' . json_last_error_msg(),
        ));
    }
    echo $json;
    exit;
}

function ainews_normalize_encoding($enc) {
    $enc = strtoupper(trim((string)$enc, " \t\r\n\"'"));
    $enc = str_replace('_', '-', $enc);
    if ($enc === 'SHIFT-JIS' || $enc === 'SJIS' || $enc === 'WINDOWS-31J' || $enc === 'CP932') {
        return 'SJIS-win';
    }
    if ($enc === 'UTF8') {
        return 'UTF-8';
    }
    if ($enc === 'EUCJP') {
        return 'EUC-JP';
    }
    if ($enc === 'ISO-2022JP') {
        return 'ISO-2022-JP';
    }
    return $enc;
}

session_start();
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
if ($session_user !== AIGM_ADMIN) {
    ainews_json_response(array('status' => 'error', 'error' => '権限がありません'));
}

$input     = json_decode(file_get_contents('php://input'), true);
if (!$input) { $input = array(); }
$action    = isset($input['action'])    ? $input['action']          : '';
$tweet_url = isset($input['tweet_url']) ? trim($input['tweet_url']) : '';

if ($action !== 'register' || $tweet_url === '') {
    ainews_json_response(array('status' => 'error', 'error' => '無効なリクエスト'));
}

/* 既存データ読み込み */
$posts = array();
if (file_exists(DATA_FILE)) {
    $posts = json_decode(file_get_contents(DATA_FILE), true);
    if (!is_array($posts)) { $posts = array(); }
}

/* 重複チェック */
foreach ($posts as $p) {
    if (isset($p['tweet_url']) && $p['tweet_url'] === $tweet_url) {
        ainews_json_response(array('status' => 'duplicate', 'title' => isset($p['title']) ? $p['title'] : $tweet_url));
    }
}

/* fxtwitter でX投稿取得 */
if (!preg_match('/(\d{15,20})/', $tweet_url, $m)) {
    ainews_json_response(array('status' => 'error', 'error' => 'tweet_id取得失敗'));
}
$tweet_id = $m[1];

$fx_opts = array('http' => array(
    'method' => 'GET',
    'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
    'timeout' => 15,
    'ignore_errors' => true
));

$fx_res = @file_get_contents('https://api.fxtwitter.com/i/status/' . $tweet_id, false, stream_context_create($fx_opts));
if (!$fx_res) {
    ainews_json_response(array('status' => 'error', 'error' => 'X投稿取得失敗'));
}

$fx = json_decode($fx_res, true);
if (empty($fx['tweet'])) {
    ainews_json_response(array('status' => 'error', 'error' => 'ツイートデータなし'));
}

$tweet      = $fx['tweet'];
$tweet_text = isset($tweet['text']) ? $tweet['text'] : '';
$author     = isset($tweet['author']['screen_name']) ? $tweet['author']['screen_name'] : '';

/* 記事URL抽出 */
$article_urls = array();
if (!empty($tweet['entities']['urls'])) {
    foreach ($tweet['entities']['urls'] as $eu) {
        $exp = !empty($eu['expanded_url']) ? $eu['expanded_url'] : '';
        if ($exp && !preg_match('/twimg\.com|x\.com|twitter\.com|t\.co/', $exp)) {
            $article_urls[] = $exp;
        }
    }
}
foreach (ainews_extract_urls_from_text($tweet_text) as $exp) {
    if (!preg_match('/twimg\.com|x\.com|twitter\.com|t\.co/', $exp)) {
        $article_urls[] = $exp;
    }
}
$article_urls = array_values(array_unique($article_urls));
$article_url = !empty($article_urls) ? $article_urls[0] : '';

/* 記事HTML取得 */
$title = '';
$body  = '';

if (!empty($article_urls)) {

    $art_opts = array('http' => array(
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (compatible; AINewsBot/1.0)\r\nAccept: text/html\r\n",
        'timeout' => 15,
        'ignore_errors' => true
    ));

    $html = @file_get_contents($article_urls[0], false, stream_context_create($art_opts));

    if ($html) {

        // === ① HTTPヘッダから charset ===
        $enc = '';
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/charset\s*=\s*["\']?([a-zA-Z0-9_\-]+)/i', $h, $m2)) {
                    $enc = ainews_normalize_encoding($m2[1]);
                    break;
                }
            }
        }

        // === ② 自動判定 ===
        if (!$enc) {
            $enc = mb_detect_encoding($html, 'UTF-8, SJIS-win, EUC-JP, ISO-2022-JP', true);
        }
        $enc = ainews_normalize_encoding($enc);

        // === ③ UTF-8に統一 ===
        if ($enc && in_array($enc, mb_list_encodings(), true)) {
            $html = mb_convert_encoding($html, 'UTF-8', $enc);
        } else {
            $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        }

        // === ④ 不正バイト除去 ===
        $html = iconv('UTF-8', 'UTF-8//IGNORE', $html);

        // タイトル
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $tm)) {
            $title = html_entity_decode(trim($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = mb_substr(preg_replace('/\s+/', ' ', $title), 0, 120);
        }

        // 本文抽出
        $body = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $body = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $body);
        $body = preg_replace('/<[^>]+>/', ' ', $body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $body = iconv('UTF-8', 'UTF-8//IGNORE', $body);
        $body = trim(mb_substr(preg_replace('/\s+/', ' ', $body), 0, 3000));
    }
}

if ($title === '') {
    $title_source = ainews_strip_urls($tweet_text);
    $title = mb_substr($title_source !== '' ? $title_source : $tweet_text, 0, 120);
}

/* Ollama */
$article_context = $body ? "\n\n【記事本文】\n{$body}" : '';

$prompt = "以下はX投稿と記事内容です。

【X投稿】
@{$author}: {$tweet_text}
{$article_context}

考察とタグを出力してください。前置き、表、Markdown見出し、追加説明は禁止です。

形式:
考察: xxx
タグ: a,b,c";

$payload = json_encode(array(
    'model'   => OLLAMA_MODEL,
    'prompt'  => $prompt,
    'stream'  => false,
    'options' => array(
        'temperature' => 0.2,
        'num_predict' => 512,
    )
), JSON_UNESCAPED_UNICODE);

$ollama_opts = array('http' => array(
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => $payload,
    'timeout' => 120
));

$res = @file_get_contents(OLLAMA_API, false, stream_context_create($ollama_opts));

$summary = '';
$tags    = array();

if ($res) {
    $data = json_decode($res, true);
    $response = isset($data['response']) ? trim($data['response']) : '';

    if (preg_match('/考察[:：]\s*(.+?)(?=タグ[:：]|$)/su', $response, $sm)) {
        $summary = trim($sm[1]);
    }

    if (preg_match('/タグ[:：]\s*(.+)/u', $response, $tm2)) {
        $raw_tags = preg_split('/[,、，\s]+/u', $tm2[1]);
        foreach ($raw_tags as $t) {
            $t = preg_replace('/^#+/u', '', trim($t));
            if ($t !== '') $tags[] = $t;
        }
    }

    if ($summary === '' && $response !== '') {
        $summary = trim(mb_substr($response, 0, 800));
    }
}

if ($summary === '') {
    $summary = '考察できませんでした。';
}
$tags = array_slice(array_values(array_unique(array_filter($tags, function($t) {
    return trim((string)$t) !== '';
}))), 0, 1);

/* 保存 */
$id = md5($tweet_url . date('YmdHis'));

$new_post = array(
    'id'         => $id,
    'tweet_url'  => $tweet_url,
    'article_url'=> $article_url,
    'author'     => $author,
    'title'      => $title,
    'summary'    => $summary,
    'tags'       => $tags,
    'created_at' => date('Y-m-d H:i:s')
);

array_unshift($posts, $new_post);

$save_json = json_encode(ainews_clean_utf8($posts), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($save_json === false || file_put_contents(DATA_FILE, $save_json) === false) {
    ainews_json_response(array('status' => 'error', 'error' => '保存に失敗しました'));
}

$sns_notice = ainews_post_sns_notice($new_post);

ainews_json_response(array('status' => 'ok', 'title' => $title, 'sns_notice' => $sns_notice));
