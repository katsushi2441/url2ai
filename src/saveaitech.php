<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

define('DATA_DIR',  __DIR__ . '/data');
define('DATA_FILE', DATA_DIR . '/aitech_posts.json'); // 旧形式（読み込み専用・移行用）

session_start();
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
if ($session_user !== AIGM_ADMIN) {
    echo json_encode(array('status' => 'error', 'error' => '権限がありません'));
    exit;
}

function normalize_utf8_text($text) {
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

function detect_html_encoding($html, $response_headers) {
    if (!is_string($html) || $html === '') {
        return 'UTF-8';
    }

    if (is_array($response_headers)) {
        foreach ($response_headers as $header_line) {
            if (preg_match('/charset\s*=\s*["\']?([A-Za-z0-9._-]+)/i', $header_line, $m)) {
                return strtoupper($m[1]);
            }
        }
    }

    if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([A-Za-z0-9._-]+)/i', $html, $m)) {
        return strtoupper($m[1]);
    }

    if (preg_match('/<meta[^>]+content\s*=\s*["\'][^"\']*charset\s*=\s*([A-Za-z0-9._-]+)/i', $html, $m)) {
        return strtoupper($m[1]);
    }

    $detected = @mb_detect_encoding($html, array('UTF-8', 'SJIS-win', 'EUC-JP', 'ISO-2022-JP', 'ASCII'), true);
    return $detected ? strtoupper($detected) : 'UTF-8';
}

function convert_html_to_utf8($html, $response_headers) {
    if (!is_string($html) || $html === '') {
        return '';
    }

    $encoding = detect_html_encoding($html, $response_headers);
    $encoding_map = array(
        'SHIFT_JIS' => 'SJIS-win',
        'SHIFT-JIS' => 'SJIS-win',
        'SJIS'      => 'SJIS-win',
        'X-SJIS'    => 'SJIS-win',
        'CP932'     => 'SJIS-win',
        'EUCJP'     => 'EUC-JP',
        'EUC_JP'    => 'EUC-JP',
        'ISO2022JP' => 'ISO-2022-JP',
    );
    if (isset($encoding_map[$encoding])) {
        $encoding = $encoding_map[$encoding];
    }

    if ($encoding !== 'UTF-8' && $encoding !== 'ASCII') {
        $converted = @mb_convert_encoding($html, 'UTF-8', $encoding);
        if (is_string($converted) && $converted !== '') {
            $html = $converted;
        }
    }

    return normalize_utf8_text($html);
}

function safe_json_encode_value($value) {
    $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return json_encode($value, $flags);
}

function write_json_atomic($path, $value) {
    $json = safe_json_encode_value($value);
    if ($json === false) {
        return false;
    }
    $dir = dirname($path);
    $tmp = tempnam($dir, 'aitech_');
    if ($tmp === false) {
        return false;
    }
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function clean_tag($tag) {
    $tag = normalize_utf8_text($tag);
    $tag = trim($tag, " \t\n\r\0\x0B#＃,、，.。!！?？:：;；\"'`“”‘’()（）[]［］{}｛｝<>＜＞");
    $tag = preg_replace('/^[#＃]+/u', '', $tag);
    $tag = preg_replace('/[^\p{L}\p{N}_+\-.]/u', '', $tag);
    if ($tag === '') {
        return '';
    }
    if (preg_match('/[�\?]{2,}/u', $tag)) {
        return '';
    }
    return mb_substr($tag, 0, 30);
}

function normalize_tags($tags) {
    if (!is_array($tags)) {
        return array();
    }
    $clean = array();
    foreach ($tags as $tag) {
        $tag = clean_tag($tag);
        if ($tag !== '') {
            $clean[] = $tag;
        }
    }
    return array_values(array_slice(array_unique($clean), 0, 8));
}

function extract_tags_from_text($text) {
    $tags = array();
    if (!is_string($text) || $text === '') {
        return $tags;
    }
    $parts = preg_split('/[,、，\n\r\t ]+/u', $text);
    foreach ($parts as $part) {
        $tag = clean_tag($part);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }
    return normalize_tags($tags);
}

/* =========================================================
   入力取得
========================================================= */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { $input = array(); }
$action = isset($input['action']) ? $input['action'] : '';
$url    = isset($input['url'])    ? normalize_utf8_text(trim($input['url'])) : '';

if ($action !== 'register' || $url === '') {
    echo json_encode(array('status' => 'error', 'error' => '無効なリクエスト'));
    exit;
}
if (!preg_match('/^https?:\/\/.+/', $url)) {
    echo json_encode(array('status' => 'error', 'error' => '有効なURLを入力してください'));
    exit;
}

/* =========================================================
   重複チェック（個別ファイルを走査）
========================================================= */
function at_load_all_posts() {
    $files = glob(DATA_DIR . '/aitech_*.json');
    $posts = array();
    if ($files) {
        foreach ($files as $f) {
            $p = json_decode(file_get_contents($f), true);
            if (is_array($p) && !empty($p['id'])) {
                $posts[] = $p;
            }
        }
    }
    /* 旧形式の一括ファイルが残っている場合は読み込む（移行用） */
    if (file_exists(DATA_FILE)) {
        $old = json_decode(file_get_contents(DATA_FILE), true);
        if (is_array($old)) {
            foreach ($old as $p) {
                if (is_array($p) && !empty($p['id'])) {
                    $posts[] = $p;
                }
            }
        }
    }
    return $posts;
}

foreach (at_load_all_posts() as $p) {
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
$response_headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : array();
$html = convert_html_to_utf8($html, $response_headers);

/* タイトル抽出 */
$title = '';
if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $tm)) {
    $title = html_entity_decode(trim($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = normalize_utf8_text($title);
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
$body = normalize_utf8_text($body);
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
- 文字化けした語、記号列、文の断片は絶対に含めない
- ハッシュタグ記号 # は付けず、タグ本体だけを書く
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
$fallback_tag_prompt = "以下のタイトルと要約から、技術記事向けのタグを3〜6個だけ作ってください。

タイトル: {$title}
要約: {$summary}

条件:
- 文字化けした語を含めない
- ハッシュタグ記号 # は付けない
- 英語または日本語の短い技術キーワードのみ
- カンマ区切りのみで出力

出力例:
AI,LLM,機械学習";

if ($res) {
    $data = json_decode($res, true);
    $response = isset($data['response']) ? normalize_utf8_text(trim($data['response'])) : '';

    /* 要約抽出 */
    if (preg_match('/要約[:：]\s*(.+?)(?=タグ[:：]|$)/su', $response, $sm)) {
        $summary = normalize_utf8_text(trim($sm[1]));
    } else {
        $summary = normalize_utf8_text(mb_substr($response, 0, 400));
    }

    /* タグ抽出 */
    if (preg_match('/タグ[:：]\s*(.+)/u', $response, $tm2)) {
        $tag_str = trim($tm2[1]);
        $tags = extract_tags_from_text($tag_str);
    }
}

if ($summary === '') {
    echo json_encode(array('status' => 'error', 'error' => 'AI要約の生成に失敗しました'));
    exit;
}

if (count($tags) < 2) {
    $tag_payload = json_encode(array(
        'model'   => OLLAMA_MODEL,
        'prompt'  => $fallback_tag_prompt,
        'stream'  => false,
        'options' => array(
            'num_ctx'     => 2048,
            'temperature' => 0.2,
            'top_k'       => 20,
            'top_p'       => 0.8,
        )
    ));
    $tag_opts = array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $tag_payload,
        'timeout'       => 60,
        'ignore_errors' => true,
    ));
    $tag_res = @file_get_contents(OLLAMA_API, false, stream_context_create($tag_opts));
    if ($tag_res) {
        $tag_data = json_decode($tag_res, true);
        $tag_response = isset($tag_data['response']) ? normalize_utf8_text(trim($tag_data['response'])) : '';
        $tags = normalize_tags(array_merge($tags, extract_tags_from_text($tag_response)));
    }
}

/* =========================================================
   保存（1投稿1ファイル）
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

$post_file = DATA_DIR . '/aitech_' . preg_replace('/[^a-zA-Z0-9]/', '', $id) . '.json';
if (!write_json_atomic($post_file, $new_post)) {
    echo json_encode(array('status' => 'error', 'error' => '保存に失敗しました'));
    exit;
}

echo json_encode(array(
    'status' => 'ok',
    'title'  => $title,
    'id'     => $id,
), JSON_UNESCAPED_UNICODE);
exit;
