<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

define('DATA_FILE', __DIR__ . '/data/ainews_posts.json');

session_start();
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
if ($session_user !== AIGM_ADMIN) {
    echo json_encode(array('status' => 'error', 'error' => '権限がありません'));
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true);
if (!$input) { $input = array(); }
$action    = isset($input['action'])    ? $input['action']          : '';
$tweet_url = isset($input['tweet_url']) ? trim($input['tweet_url']) : '';

if ($action !== 'register' || $tweet_url === '') {
    echo json_encode(array('status' => 'error', 'error' => '無効なリクエスト'));
    exit;
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
        echo json_encode(array('status' => 'duplicate', 'title' => isset($p['title']) ? $p['title'] : $tweet_url));
        exit;
    }
}

/* fxtwitter でX投稿取得 */
if (!preg_match('/(\d{15,20})/', $tweet_url, $m)) {
    echo json_encode(array('status' => 'error', 'error' => 'tweet_id取得失敗'));
    exit;
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
    echo json_encode(array('status' => 'error', 'error' => 'X投稿取得失敗'));
    exit;
}

$fx = json_decode($fx_res, true);
if (empty($fx['tweet'])) {
    echo json_encode(array('status' => 'error', 'error' => 'ツイートデータなし'));
    exit;
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
$article_urls = array_values(array_unique($article_urls));

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
                if (preg_match('/charset=([a-zA-Z0-9\-]+)/i', $h, $m2)) {
                    $enc = strtoupper($m2[1]);
                    break;
                }
            }
        }

        // === ② 自動判定 ===
        if (!$enc) {
            $enc = mb_detect_encoding($html, 'UTF-8, SJIS-win, EUC-JP, ISO-2022-JP', true);
        }

        // === ③ UTF-8に統一 ===
        if ($enc) {
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
    $title = mb_substr($tweet_text, 0, 80);
}

/* Ollama */
$article_context = $body ? "\n\n【記事本文】\n{$body}" : '';

$prompt = "以下はX投稿と記事内容です。

【X投稿】
@{$author}: {$tweet_text}
{$article_context}

考察とタグを出力してください。

形式:
考察: xxx
タグ: a,b,c";

$payload = json_encode(array(
    'model'   => OLLAMA_MODEL,
    'prompt'  => $prompt,
    'stream'  => false
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
}

if ($summary === '') {
    $summary = '考察できませんでした。';
}

/* 保存 */
$id = md5($tweet_url . date('YmdHis'));

$new_post = array(
    'id'         => $id,
    'tweet_url'  => $tweet_url,
    'author'     => $author,
    'title'      => $title,
    'summary'    => $summary,
    'tags'       => $tags,
    'created_at' => date('Y-m-d H:i:s')
);

array_unshift($posts, $new_post);

file_put_contents(DATA_FILE, json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode(array('status' => 'ok', 'title' => $title), JSON_UNESCAPED_UNICODE);
exit;
