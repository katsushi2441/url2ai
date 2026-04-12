<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

define('DATA_FILE',    __DIR__ . '/data/aitech_posts.json');

session_start();
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
if ($session_user !== AIGM_ADMIN) {
    echo json_encode(array('status' => 'error', 'error' => '権限がありません'));
    exit;
}

/* =========================================================
   入力取得
========================================================= */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { $input = array(); }
$action = isset($input['action']) ? $input['action'] : '';
$url    = isset($input['url'])    ? trim($input['url']) : '';

if ($action !== 'register' || $url === '') {
    echo json_encode(array('status' => 'error', 'error' => '無効なリクエスト'));
    exit;
}
if (!preg_match('/^https?:\/\/.+/', $url)) {
    echo json_encode(array('status' => 'error', 'error' => '有効なURLを入力してください'));
    exit;
}

/* =========================================================
   既存データ読み込み
========================================================= */
$posts = array();
if (file_exists(DATA_FILE)) {
    $posts = json_decode(file_get_contents(DATA_FILE), true);
    if (!is_array($posts)) { $posts = array(); }
}

/* 重複チェック */
foreach ($posts as $p) {
    if (isset($p['url']) && $p['url'] === $url) {
        echo json_encode(array('status' => 'duplicate', 'title' => isset($p['title']) ? $p['title'] : $url));
        exit;
    }
}

/* =========================================================
   URLからHTML取得
========================================================= */
$opts = array('http' => array(
    'method'        => 'GET',
    'header'        => "User-Agent: Mozilla/5.0 (compatible; AITechBot/1.0)\r\nAccept: text/html\r\n",
    'timeout'       => 15,
    'ignore_errors' => true,
));
$html = @file_get_contents($url, false, stream_context_create($opts));
if (!$html) {
    echo json_encode(array('status' => 'error', 'error' => 'URLを取得できませんでした'));
    exit;
}

/* タイトル抽出 */
$title = '';
if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $tm)) {
    $title = html_entity_decode(trim($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = preg_replace('/\s+/', ' ', $title);
    $title = mb_substr($title, 0, 120);
}
if ($title === '') { $title = $url; }

/* 本文テキスト抽出（簡易）*/
$body = $html;
$body = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $body);
$body = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $body);
$body = preg_replace('/<[^>]+>/', ' ', $body);
$body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$body = preg_replace('/\s+/', ' ', $body);
$body = trim($body);
$body = mb_substr($body, 0, 3000);

/* =========================================================
   Ollama: 要約 + タグ抽出
========================================================= */
$prompt = "以下は技術系Webサイトのタイトルと本文です。

タイトル: {$title}
URL: {$url}
本文（抜粋）:
{$body}

以下の2つを日本語で出力してください。

【要約】
- この記事・サイトの内容を3〜5文で要約する
- 技術的なポイントを具体的に説明する
- 読者がX（旧Twitter）に投稿したくなる表現で

【タグ】
- 記事に関連する技術キーワードを3〜6個
- 英語または日本語の短い単語のみ
- カンマ区切りで出力

出力形式（必ずこの形式で）:
要約: （要約文）
タグ: （tag1,tag2,tag3）";

$payload = json_encode(array(
    'model'   => OLLAMA_MODEL,
    'prompt'  => $prompt,
    'stream'  => false,
    'options' => array(
        'num_ctx'     => 4096,
        'temperature' => 0.5,
        'top_k'       => 40,
        'top_p'       => 0.9,
    )
));
$ollama_opts = array('http' => array(
    'method'        => 'POST',
    'header'        => "Content-Type: application/json\r\n",
    'content'       => $payload,
    'timeout'       => 120,
    'ignore_errors' => true,
));
$res = @file_get_contents(OLLAMA_API, false, stream_context_create($ollama_opts));
$summary = '';
$tags    = array();

if ($res) {
    $data = json_decode($res, true);
    $response = isset($data['response']) ? trim($data['response']) : '';

    /* 要約抽出 */
    if (preg_match('/要約[:：]\s*(.+?)(?=タグ[:：]|$)/su', $response, $sm)) {
        $summary = trim($sm[1]);
    } else {
        $summary = mb_substr($response, 0, 400);
    }

    /* タグ抽出 */
    if (preg_match('/タグ[:：]\s*(.+)/u', $response, $tm2)) {
        $tag_str = trim($tm2[1]);
        $raw_tags = preg_split('/[,、，\s]+/u', $tag_str);
        foreach ($raw_tags as $t) {
            $t = trim($t, " \t\n\r\0\x0B#「」『』【】()（）");
            if ($t !== '' && mb_strlen($t) <= 30) {
                $tags[] = $t;
            }
        }
        $tags = array_slice(array_unique($tags), 0, 8);
    }
}

if ($summary === '') {
    echo json_encode(array('status' => 'error', 'error' => 'AI要約の生成に失敗しました'));
    exit;
}

/* =========================================================
   保存
========================================================= */
$id = md5($url . date('YmdHis'));
$new_post = array(
    'id'         => $id,
    'url'        => $url,
    'title'      => $title,
    'summary'    => $summary,
    'tags'       => $tags,
    'author'     => $session_user,
    'created_at' => date('Y-m-d H:i:s'),
);

array_unshift($posts, $new_post);
file_put_contents(DATA_FILE, json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode(array(
    'status' => 'ok',
    'title'  => $title,
    'id'     => $id,
), JSON_UNESCAPED_UNICODE);
exit;