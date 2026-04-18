<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

define('DATA_DIR',  __DIR__ . '/data');
define('DATA_FILE', DATA_DIR . '/ainews_posts.json'); // 旧形式（移行用）

function an_normalize_utf8_text($text) {
    if (!is_string($text) || $text === '') {
        return '';
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, SJIS-win, EUC-JP, ISO-2022-JP, ASCII');
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }
    $text = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
    if ($text === false) {
        return '';
    }
    return preg_replace('/\p{C}+/u', ' ', $text);
}

function an_post_file($id) {
    return DATA_DIR . '/ainews_' . preg_replace('/[^a-zA-Z0-9]/', '', $id) . '.json';
}

function an_load_all_posts() {
    $posts = array();
    $files = glob(DATA_DIR . '/ainews_*.json');
    if ($files) {
        foreach ($files as $f) {
            $p = json_decode(file_get_contents($f), true);
            if (is_array($p) && !empty($p['id'])) {
                $posts[] = $p;
            }
        }
    }
    if (file_exists(DATA_FILE)) {
        $old = json_decode(file_get_contents(DATA_FILE), true);
        if (is_array($old)) {
            $existing_ids = array();
            foreach ($posts as $p) { $existing_ids[$p['id']] = true; }
            foreach ($old as $p) {
                if (is_array($p) && !empty($p['id']) && !isset($existing_ids[$p['id']])) {
                    $posts[] = $p;
                }
            }
        }
    }
    return $posts;
}

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
$post_id   = isset($input['post_id'])   ? trim($input['post_id'])   : '';

if (!in_array($action, array('register', 'reanalyze')) || $tweet_url === '') {
    echo json_encode(array('status' => 'error', 'error' => '無効なリクエスト'));
    exit;
}

/* 再考察モード：既存データを保持しつつollamaのみ再実行 */
$existing_post = null;
foreach (an_load_all_posts() as $p) {
    if (isset($p['tweet_url']) && $p['tweet_url'] === $tweet_url) {
        $existing_post = $p;
        break;
    }
}

if ($action === 'register' && $existing_post) {
    echo json_encode(array('status' => 'duplicate', 'title' => isset($existing_post['title']) ? $existing_post['title'] : $tweet_url));
    exit;
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
    'stream'  => false,
    'options' => array(
        'num_ctx'     => 4096,
        'temperature' => 0.4,
        'top_k'       => 40,
        'top_p'       => 0.9,
    )
), JSON_UNESCAPED_UNICODE);

$ollama_opts = array('http' => array(
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => $payload,
    'timeout' => 120,
    'ignore_errors' => true
));

$res = @file_get_contents(OLLAMA_API, false, stream_context_create($ollama_opts));

$summary = '';
$tags    = array();

if ($res) {
    $data = json_decode($res, true);
    $response = isset($data['response']) ? an_normalize_utf8_text(trim($data['response'])) : '';

    if (preg_match('/考察[:：]\s*(.+?)(?=タグ[:：]|$)/su', $response, $sm)) {
        $summary = an_normalize_utf8_text(trim($sm[1]));
    } else {
        $summary = an_normalize_utf8_text(mb_substr($response, 0, 400));
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
if ($existing_post) {
    $id    = $existing_post['id'];
    $title = !empty($existing_post['title']) ? $existing_post['title'] : $title;
    $new_post = array_merge($existing_post, array(
        'summary'     => $summary,
        'tags'        => $tags,
        'reanalyzed_at' => date('Y-m-d H:i:s')
    ));
} else {
    $id       = md5($tweet_url . date('YmdHis'));
    $new_post = array(
        'id'         => $id,
        'tweet_url'  => $tweet_url,
        'author'     => $author,
        'title'      => $title,
        'summary'    => $summary,
        'tags'       => $tags,
        'created_at' => date('Y-m-d H:i:s')
    );
}

$post_file = an_post_file($id);
if (file_put_contents($post_file, json_encode($new_post, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
    echo json_encode(array('status' => 'error', 'error' => '保存に失敗しました'));
    exit;
}

echo json_encode(array('status' => 'ok', 'title' => $title), JSON_UNESCAPED_UNICODE);
exit;
