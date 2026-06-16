<?php
require_once __DIR__ . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

$BASE_URL = AIGM_BASE_URL;
$THIS_FILE = 'uslideblog.php';
$SITE_NAME = 'USlideBlog';
$ADMIN = AIGM_ADMIN;
$DATA_DIR = __DIR__ . '/data/uslideblog';
$DEFAULT_IMAGE = $BASE_URL . '/images/uslideblog.png';
$RENDERER_API = (isset($_aigm_config['uslideblog']['renderer_api']) && $_aigm_config['uslideblog']['renderer_api'] !== '') ? $_aigm_config['uslideblog']['renderer_api'] : 'http://exbridge.ddns.net:8022';
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0755, true); }

if (isset($_GET['usb_logout'])) {
    header('Location: ' . url2ai_auth_logout_url('/' . $THIS_FILE));
    exit;
}
if (isset($_GET['usb_login'])) {
    header('Location: ' . url2ai_auth_login_url(url2ai_auth_current_path()));
    exit;
}

$auth = url2ai_auth_bootstrap();
$logged_in = $auth['logged_in'];
$session_user = $auth['session_user'];
$is_admin = $auth['is_admin'];

/* =========================================================
   Helpers
========================================================= */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function x($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
function usb_slug($text) {
    $text = mb_strtolower((string)$text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    $text = trim($text, '-');
    if ($text === '') { $text = 'slideblog'; }
    return mb_substr($text, 0, 60, 'UTF-8');
}
function usb_id($title) {
    return date('YmdHis') . '-' . usb_slug($title);
}
function usb_file($id) {
    global $DATA_DIR;
    $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '-', (string)$id);
    return $DATA_DIR . '/' . $safe . '.json';
}
function usb_load($id) {
    $file = usb_file($id);
    if (!file_exists($file)) { return null; }
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : null;
}
function usb_save($post) {
    $file = usb_file($post['id']);
    file_put_contents($file, json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
function usb_all_posts() {
    global $DATA_DIR;
    $posts = array();
    $files = glob($DATA_DIR . '/*.json');
    if ($files) {
        foreach ($files as $file) {
            $d = json_decode(file_get_contents($file), true);
            if (!is_array($d) || empty($d['id'])) { continue; }
            $posts[] = $d;
        }
    }
    usort($posts, function($a, $b) {
        $ta = isset($a['created_at']) ? $a['created_at'] : '';
        $tb = isset($b['created_at']) ? $b['created_at'] : '';
        return strcmp($tb, $ta);
    });
    return $posts;
}
function usb_excerpt($text, $len) {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if (mb_strlen($text, 'UTF-8') <= $len) { return $text; }
    return mb_substr($text, 0, $len, 'UTF-8') . '...';
}
function usb_abs_url($url, $base) {
    $url = html_entity_decode(trim((string)$url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') { return ''; }
    if (preg_match('#^https?://#i', $url)) { return $url; }
    if (strpos($url, '//') === 0) { return 'https:' . $url; }
    $p = parse_url($base);
    if (empty($p['scheme']) || empty($p['host'])) { return ''; }
    $root = $p['scheme'] . '://' . $p['host'];
    if (strpos($url, '/') === 0) { return $root . $url; }
    $path = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $root . $path . $url;
}
function usb_extract_tweet_id($url) {
    if (preg_match('/(?:x|twitter)\.com\/(?:i\/status|[^\/?#]+\/status(?:es)?)\/(\d{15,20})/i', (string)$url, $m)) {
        return $m[1];
    }
    if (preg_match('/(?:^|\/)status(?:es)?\/(\d{15,20})/i', (string)$url, $m)) {
        return $m[1];
    }
    return '';
}
function usb_first_url_from_text($text) {
    if (preg_match('#https?://[^\s<>"\']+#u', (string)$text, $m)) {
        return rtrim($m[0], "。、，．,.)]}\n\r\t");
    }
    return '';
}
function usb_fetch_ogp_image($url) {
    $url = trim((string)$url);
    if (!preg_match('#^https?://#i', $url)) { return ''; }
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (compatible; USlideBlog/1.0)\r\nAccept: text/html,application/xhtml+xml\r\n",
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $html = @file_get_contents($url, false, stream_context_create($opts));
    if (!$html) { return ''; }
    if (strlen($html) > 500000) { $html = substr($html, 0, 500000); }
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
        return usb_abs_url($m[1], $url);
    }
    return '';
}
function usb_x_default_image() {
    global $BASE_URL;
    return $BASE_URL . '/images/x.svg';
}
function usb_cached_display_image($url) {
    global $DATA_DIR;
    $url = trim((string)$url);
    if ($url === '') { return ''; }
    $cache_dir = $DATA_DIR . '/image_cache';
    if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }
    $cache_file = $cache_dir . '/' . sha1($url) . '.txt';
    $tweet_id = usb_extract_tweet_id($url);
    if (is_readable($cache_file) && filemtime($cache_file) > time() - 604800) {
        $cached_image = trim((string)file_get_contents($cache_file));
        if ($tweet_id === '') { return $cached_image; }
        if ($cached_image !== '' && strpos($cached_image, 'icon-ios') === false) { return $cached_image; }
    }
    $image = '';
    if ($tweet_id !== '') {
        $tweet = usb_fetch_x_tweet($url);
        if (is_array($tweet) && !empty($tweet['image'])) {
            $image = $tweet['image'];
        }
    }
    if ($image === '') {
        $image = usb_fetch_ogp_image($url);
    }
    if ($image === '' && $tweet_id !== '') {
        $image = usb_x_default_image();
    }
    if (is_dir($cache_dir) && is_writable($cache_dir)) {
        @file_put_contents($cache_file, $image, LOCK_EX);
    }
    return $image;
}
function usb_fetch_aixsns_content($url) {
    if (!preg_match('~^https?://aixec\.exbridge\.jp/sns\.php\?[^#]*\bid=(\d+)~i', (string)$url, $m)) {
        return '';
    }
    $api = 'https://aixec.exbridge.jp/api.php?path=' . rawurlencode('posts/' . $m[1]);
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "User-Agent: USlideBlog/1.0\r\nAccept: application/json\r\n",
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($api, false, stream_context_create($opts));
    if (!$res) { return ''; }
    $data = json_decode($res, true);
    $post = isset($data['item']) && is_array($data['item']) ? $data['item'] : $data;
    return isset($post['content']) ? trim(strip_tags((string)$post['content'])) : '';
}
function usb_aixsns_linked_image($url) {
    global $DATA_DIR;
    $url = trim((string)$url);
    if ($url === '') { return ''; }
    $cache_dir = $DATA_DIR . '/image_cache';
    if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }
    $cache_file = $cache_dir . '/' . sha1('aixsns-linked:' . $url) . '.txt';
    if (is_readable($cache_file) && filemtime($cache_file) > time() - 604800) {
        return trim((string)file_get_contents($cache_file));
    }
    $content = usb_fetch_aixsns_content($url);
    $linked_url = usb_first_url_from_text($content);
    $image = '';
    if ($linked_url !== '' && $linked_url !== $url) {
        $image = usb_cached_display_image($linked_url);
    }
    if (is_dir($cache_dir) && is_writable($cache_dir)) {
        @file_put_contents($cache_file, $image, LOCK_EX);
    }
    return $image;
}
function usb_display_image($post) {
    global $BASE_URL;
    $image = isset($post['image']) ? trim((string)$post['image']) : '';
    if ($image !== '') { return $image; }
    $source_url = isset($post['source_url']) ? (string)$post['source_url'] : '';
    if (usb_extract_tweet_id($source_url) !== '') {
        $image = usb_cached_display_image($source_url);
        if ($image !== '') { return $image; }
    }
    if (preg_match('~^https?://aixec\.exbridge\.jp/sns\.php\?~i', $source_url)) {
        $image = usb_aixsns_linked_image($source_url);
        if ($image !== '') { return $image; }
        return 'https://aixec.exbridge.jp/images/sns.png';
    }
    return '';
}
function usb_fetch_x_tweet($url) {
    $tweet_id = usb_extract_tweet_id($url);
    if ($tweet_id === '') { return null; }
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents('https://api.fxtwitter.com/i/status/' . $tweet_id, false, stream_context_create($opts));
    if (!$res) {
        return array('ok' => false, 'error' => 'X投稿を取得できませんでした。');
    }
    $data = json_decode($res, true);
    if (empty($data['tweet']) || empty($data['tweet']['text'])) {
        return array('ok' => false, 'error' => 'X投稿本文を取得できませんでした。');
    }
    $tweet = $data['tweet'];
    $text = trim((string)$tweet['text']);
    $author = isset($tweet['author']['screen_name']) ? '@' . $tweet['author']['screen_name'] : 'X投稿';
    $title_text = preg_replace('#https?://\S+#u', '', $text);
    $title_text = trim(preg_replace('/\s+/u', ' ', $title_text));
    $title = $title_text !== '' ? mb_substr($title_text, 0, 70, 'UTF-8') : $author . ' の投稿';
    $image = '';
    if (!empty($tweet['media']['photos'][0]['url'])) {
        $image = $tweet['media']['photos'][0]['url'];
    } else if (!empty($tweet['media']['videos'][0]['thumbnail_url'])) {
        $image = $tweet['media']['videos'][0]['thumbnail_url'];
    } else {
        $image = usb_x_default_image();
    }
    return array(
        'ok' => true,
        'title' => $title,
        'description' => mb_substr($text, 0, 220, 'UTF-8'),
        'image' => $image,
        'body' => $author . ":\n" . $text,
        'source_title' => $author . ' のX投稿',
    );
}
function usb_fetch_aixsns_post($url) {
    if (!preg_match('~^https?://aixec\.exbridge\.jp/sns\.php\?[^#]*\bid=(\d+)~i', (string)$url, $m)) {
        return null;
    }
    $id = $m[1];
    $api = 'https://aixec.exbridge.jp/api.php?path=' . rawurlencode('posts/' . $id);
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "User-Agent: USlideBlog/1.0\r\nAccept: application/json\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($api, false, stream_context_create($opts));
    if (!$res) {
        return array('ok' => false, 'error' => 'AIxSNS投稿を取得できませんでした。');
    }
    $data = json_decode($res, true);
    $post = isset($data['item']) && is_array($data['item']) ? $data['item'] : $data;
    $content = isset($post['content']) ? trim(strip_tags((string)$post['content'])) : '';
    if ($content === '') {
        return array('ok' => false, 'error' => 'AIxSNS投稿本文を取得できませんでした。');
    }
    $title = trim(strtok($content, "\n"));
    if ($title === '') { $title = 'AIxSNS投稿 #' . $id; }
    $linked_url = usb_first_url_from_text($content);
    $image = '';
    if ($linked_url !== '' && $linked_url !== $url) {
        $image = usb_cached_display_image($linked_url);
    }
    if ($image === '') {
        $image = usb_fetch_ogp_image($url);
    }
    if ($image === '') { $image = 'https://aixec.exbridge.jp/images/sns.png'; }
    return array(
        'ok' => true,
        'title' => mb_substr($title, 0, 100, 'UTF-8'),
        'description' => mb_substr($content, 0, 220, 'UTF-8'),
        'image' => $image,
        'body' => $content,
        'source_title' => 'AIxSNS投稿 #' . $id,
    );
}
function usb_fetch_url($url) {
    $sns_source = usb_fetch_aixsns_post($url);
    if ($sns_source !== null) { return $sns_source; }
    $x_source = usb_fetch_x_tweet($url);
    if ($x_source !== null) { return $x_source; }
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (compatible; USlideBlog/1.0)\r\nAccept: text/html,application/xhtml+xml,text/plain,application/pdf\r\n",
        'timeout' => 20,
        'ignore_errors' => true,
    ));
    $html = @file_get_contents($url, false, stream_context_create($opts));
    if (!$html) { return array('ok' => false, 'error' => 'URLを取得できませんでした。'); }
    if (strlen($html) > 1200000) { $html = substr($html, 0, 1200000); }
    $title = '';
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', $html, $m)) {
        $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $description = '';
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i', $html, $m)) {
        $description = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $image = '';
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
        $image = usb_abs_url($m[1], $url);
    }
    $body = '';
    if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $json_scripts)) {
        foreach ($json_scripts[1] as $json_text) {
            $json_text = html_entity_decode(trim($json_text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $json = json_decode($json_text, true);
            if (is_array($json)) {
                if (isset($json['text']) && trim($json['text']) !== '') {
                    $body = trim($json['text']);
                    break;
                }
                if (isset($json['articleBody']) && trim($json['articleBody']) !== '') {
                    $body = trim($json['articleBody']);
                    break;
                }
                if (isset($json['description']) && trim($json['description']) !== '') {
                    $body = trim($json['description']);
                }
            }
        }
    }
    if ($body === '') {
        $body = $html;
        $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $body);
        $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $body);
        $body = preg_replace('/<\/(h[1-6]|p|li|pre|blockquote|section|article|div)>/i', "\n", $body);
        $body = preg_replace('/<[^>]+>/', ' ', $body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $body = preg_replace('/[ \t]+/u', ' ', $body);
    $body = preg_replace('/\R{3,}/u', "\n\n", $body);
    $body = trim($body);
    if (preg_match('/JavaScript is not available|Something went wrong|Please enable JavaScript|supported browsers in our Help Center/i', $body)) {
        return array('ok' => false, 'error' => '本文取得に失敗しました。X投稿は投稿本文取得APIで取得してください。');
    }
    if (mb_strlen($body, 'UTF-8') < 40) {
        return array('ok' => false, 'error' => '本文が短すぎるため、スライドを生成できませんでした。');
    }
    if ($title === '') { $title = $url; }
    return array('ok' => true, 'title' => mb_substr($title, 0, 140, 'UTF-8'), 'description' => mb_substr($description, 0, 220, 'UTF-8'), 'image' => $image, 'body' => mb_substr($body, 0, 7000, 'UTF-8'));
}
function usb_ollama($prompt) {
    $payload = json_encode(array(
        'model' => OLLAMA_MODEL,
        'prompt' => $prompt,
        'stream' => false,
        'options' => array('num_ctx' => 4096, 'temperature' => 0.45, 'top_k' => 40, 'top_p' => 0.9),
    ));
    $opts = array('http' => array('method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 90, 'ignore_errors' => true));
    $res = @file_get_contents(OLLAMA_API, false, stream_context_create($opts));
    if (!$res) { return ''; }
    $d = json_decode($res, true);
    return isset($d['response']) ? trim($d['response']) : '';
}
function usb_parse_json_response($text) {
    $text = trim((string)$text);
    if ($text === '') { return null; }
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $text, $m)) { $text = $m[1]; }
    else if (preg_match('/(\{.*\})/is', $text, $m)) { $text = $m[1]; }
    $d = json_decode($text, true);
    return is_array($d) ? $d : null;
}
function usb_default_tags($title, $body) {
    return array();
}
function usb_fallback_slides($title, $description, $body) {
    $excerpt = usb_excerpt($body, 120);
    return array(
        array('title' => $title, 'body' => ($description !== '' ? usb_excerpt($description, 120) : $excerpt), 'note' => 'URLから抽出した内容の概要です。', 'layout' => 'cover'),
        array('title' => '要点', 'body' => $excerpt, 'note' => '', 'layout' => 'points'),
        array('title' => '大切な内容', 'body' => '本文から読み取れる重要な内容を短く整理します。', 'note' => '', 'layout' => 'points'),
        array('title' => 'まとめ', 'body' => '入力URLの内容をもとに、結論や印象に残る点を整理します。', 'note' => '', 'layout' => 'summary'),
    );
}
function usb_build_prompt($source) {
    $body = mb_substr($source['body'], 0, 6000, 'UTF-8');
    return "あなたは、入力URL本文だけを材料にして、プレゼンで読める短いスライドへ要約する編集者です。\n\n目的:\n- URL本文の主張、順番、結論は変えない\n- 本文をそのまま貼らず、スライドとして読める短い要点に圧縮する\n- 前半だけで終わらせず、本文全体の論点を網羅する\n\n絶対条件:\n- 入力本文にない論点、製品名、技術名、事例、結論を追加しない\n- 以前の会話や固定テーマを混ぜない\n- 原文の中心主張、具体例、結論を落とさない\n- 各段落の役割を理解し、似た内容は統合して短くする\n- 長文の文をそのままコピーしない。短い箇条書き風の文にする\n- 1枚のスライドに長文を詰め込まない\n- 最後の段落・結論段落は必ず最後のまとめに反映する\n\nスライド化ルール:\n- 6〜9枚のスライドにする\n- title は短い見出し。18文字前後を目安にする\n- body は短い要点を2〜4行。1行は25文字前後を目安にする\n- body は改行区切りの箇条書き風にする。ただし記号の乱用はしない\n- note は補足説明。空でもよい。本文の長文コピーは禁止\n- 具体例は1枚にまとめるか、要点として短く列挙する\n- 最後のスライドは、原文の結論を短く強くまとめる\n- 各スライドは title, body, note, layout を持つ\n- tags は入力本文から自然に抽出した 3〜8個にする\n- JSONのみを返す。説明文やMarkdownフェンスは禁止\n\n良いbody例:\n\"重要な論点を短く整理\\n具体例は要点だけ残す\\n最後に結論を明確化\"\n\n悪いbody例:\n原文の段落をそのまま貼り付けた長文。\n入力本文にない別テーマを混ぜた内容。\n\n出力JSON形式:\n{\"title\":\"...\",\"description\":\"...\",\"tags\":[\"...\"],\"slides\":[{\"title\":\"...\",\"body\":\"...\",\"note\":\"...\",\"layout\":\"cover\"}]}\n\nURL: " . $source['url'] . "\nタイトル: " . $source['title'] . "\n説明: " . $source['description'] . "\n本文:\n" . $body;
}
function usb_markdown($post) {
    $out = '# ' . $post['title'] . "\n\n";
    $out .= isset($post['description']) ? $post['description'] . "\n\n" : '';
    $out .= 'Source: ' . (isset($post['source_url']) ? $post['source_url'] : '') . "\n\n";
    if (!empty($post['tags'])) { $out .= 'Tags: ' . implode(', ', $post['tags']) . "\n\n"; }
    foreach ($post['slides'] as $i => $s) {
        $out .= '## ' . ($i + 1) . '. ' . (isset($s['title']) ? $s['title'] : '') . "\n\n";
        $out .= (isset($s['body']) ? $s['body'] : '') . "\n\n";
        if (!empty($s['note'])) { $out .= '> ' . $s['note'] . "\n\n"; }
    }
    return $out;
}
function usb_renderer_request($path, $post) {
    global $RENDERER_API;
    $url = rtrim($RENDERER_API, '/') . $path;
    $payload = json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $opts = array('http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 90,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return $res ? $res : '';
}

/* =========================================================
   RSS / Downloads
========================================================= */
$posts = usb_all_posts();
if (isset($_GET['feed'])) {
    header('Access-Control-Allow-Origin: https://exbridge.jp');
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n";
    echo '<title>' . x($SITE_NAME . ' | URL要約スライド') . '</title>' . "\n";
    echo '<link>' . x($BASE_URL . '/' . $THIS_FILE) . '</link>' . "\n";
    echo '<description>' . x('入力URLの内容を短いスライドに要約した記事一覧。') . '</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . x($BASE_URL . '/' . $THIS_FILE . '?feed') . '" rel="self" type="application/rss+xml"/>' . "\n";
    foreach (array_slice($posts, 0, 30) as $p) {
        if (isset($p['published']) && !$p['published'] && !$is_admin) { continue; }
        $link = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($p['id']);
        echo '<item><title>' . x($p['title']) . '</title><link>' . x($link) . '</link><guid isPermaLink="true">' . x($link) . '</guid>';
        if (!empty($p['created_at'])) { echo '<pubDate>' . date('r', strtotime($p['created_at'])) . '</pubDate>'; }
        echo '<description>' . x(isset($p['description']) ? $p['description'] : '') . '</description></item>' . "\n";
    }
    echo '</channel></rss>';
    exit;
}

$detail_id = isset($_GET['id']) ? trim($_GET['id']) : '';
$detail = $detail_id !== '' ? usb_load($detail_id) : null;
if ($detail && isset($_GET['format'])) {
    $fmt = $_GET['format'];
    if ($fmt === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $detail['id'] . '.json"');
        echo json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    if ($fmt === 'md') {
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $detail['id'] . '.md"');
        echo usb_markdown($detail);
        exit;
    }
    if ($fmt === 'html') {
        $api = usb_renderer_request('/api/uslideblog/marp-html', $detail);
        $api_json = $api !== '' ? json_decode($api, true) : null;
        if (is_array($api_json) && !empty($api_json['ok']) && !empty($api_json['html'])) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $detail['id'] . '.html"');
            echo $api_json['html'];
            exit;
        }
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $detail['id'] . '.html"');
        echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>' . h($detail['title']) . '</title><body>' . "\n";
        echo '<h1>' . h($detail['title']) . '</h1><p>' . h(isset($detail['description']) ? $detail['description'] : '') . '</p>';
        foreach ($detail['slides'] as $s) {
            echo '<section><h2>' . h(isset($s['title']) ? $s['title'] : '') . '</h2><p>' . nl2br(h(isset($s['body']) ? $s['body'] : '')) . '</p></section>';
        }
        echo '</body></html>';
        exit;
    }
    if ($fmt === 'pptx') {
        $pptx = usb_renderer_request('/api/uslideblog/pptx', $detail);
        if ($pptx !== '') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
            header('Content-Disposition: attachment; filename="' . $detail['id'] . '.pptx"');
            echo $pptx;
            exit;
        }
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PPTX renderer is not running. Start apps/uslideblog on port 8022.';
        exit;
    }
}

/* =========================================================
   POST actions
========================================================= */
$flash = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if (!$is_admin) {
        $error = '生成・編集には管理者ログインが必要です。';
    } else if ($action === 'generate') {
        @set_time_limit(300);
        $url = isset($_POST['source_url']) ? trim($_POST['source_url']) : '';
        if (!preg_match('#^https?://#i', $url)) {
            $error = 'URLを入力してください。';
        } else {
            $src = usb_fetch_url($url);
            if (empty($src['ok'])) {
                $error = isset($src['error']) ? $src['error'] : 'URL解析に失敗しました。';
            } else {
                $src['url'] = $url;
                $ai = usb_ollama(usb_build_prompt($src));
                $parsed = usb_parse_json_response($ai);
                $title = ($parsed && !empty($parsed['title'])) ? $parsed['title'] : $src['title'];
                $desc = ($parsed && !empty($parsed['description'])) ? $parsed['description'] : ($src['description'] !== '' ? $src['description'] : usb_excerpt($src['body'], 160));
                $slides = ($parsed && !empty($parsed['slides']) && is_array($parsed['slides'])) ? $parsed['slides'] : usb_fallback_slides($title, $desc, $src['body']);
                $tags = ($parsed && !empty($parsed['tags']) && is_array($parsed['tags'])) ? $parsed['tags'] : usb_default_tags($title, $src['body']);
                $id = usb_id($title);
                $post = array(
                    'id' => $id,
                    'title' => $title,
                    'description' => $desc,
                    'source_url' => $url,
                    'source_title' => isset($src['source_title']) ? $src['source_title'] : $src['title'],
                    'image' => $src['image'],
                    'tags' => array_values($tags),
                    'slides' => array_values($slides),
                    'published' => 1,
                    'views' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_by' => $session_user,
                    'raw_excerpt' => usb_excerpt($src['body'], 1400),
                );
                usb_save($post);
                header('Location: ' . $THIS_FILE . '?id=' . urlencode($id) . '&edit=1');
                exit;
            }
        }
    } else if ($action === 'save') {
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $post = usb_load($id);
        if (!$post) {
            $error = '保存対象が見つかりません。';
        } else {
            $post['title'] = isset($_POST['title']) ? trim($_POST['title']) : $post['title'];
            $post['description'] = isset($_POST['description']) ? trim($_POST['description']) : '';
            $post['source_url'] = isset($_POST['source_url']) ? trim($_POST['source_url']) : '';
            $post['image'] = isset($_POST['image']) ? trim($_POST['image']) : '';
            $post['published'] = !empty($_POST['published']) ? 1 : 0;
            $tag_text = isset($_POST['tags']) ? trim($_POST['tags']) : '';
            $tags = preg_split('/[,、\s]+/u', $tag_text);
            $clean_tags = array();
            foreach ($tags as $t) { $t = trim($t, "# \t\r\n"); if ($t !== '') { $clean_tags[] = $t; } }
            $post['tags'] = $clean_tags;
            $slides = array();
            $titles = isset($_POST['slide_title']) && is_array($_POST['slide_title']) ? $_POST['slide_title'] : array();
            $bodies = isset($_POST['slide_body']) && is_array($_POST['slide_body']) ? $_POST['slide_body'] : array();
            $notes = isset($_POST['slide_note']) && is_array($_POST['slide_note']) ? $_POST['slide_note'] : array();
            $layouts = isset($_POST['slide_layout']) && is_array($_POST['slide_layout']) ? $_POST['slide_layout'] : array();
            for ($i = 0; $i < count($titles); $i++) {
                $st = trim($titles[$i]);
                $sb = isset($bodies[$i]) ? trim($bodies[$i]) : '';
                if ($st === '' && $sb === '') { continue; }
                $slides[] = array(
                    'title' => $st,
                    'body' => $sb,
                    'note' => isset($notes[$i]) ? trim($notes[$i]) : '',
                    'layout' => isset($layouts[$i]) ? trim($layouts[$i]) : 'points',
                );
            }
            if ($slides) { $post['slides'] = $slides; }
            $post['updated_at'] = date('Y-m-d H:i:s');
            usb_save($post);
            header('Location: ' . $THIS_FILE . '?id=' . urlencode($id) . '&edit=1&saved=1');
            exit;
        }
    } else if ($action === 'delete') {
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $file = usb_file($id);
        if ($id !== '' && file_exists($file)) { @unlink($file); }
        header('Location: ' . $THIS_FILE);
        exit;
    }
}

if ($detail && empty($_GET['edit'])) {
    $detail['views'] = isset($detail['views']) ? ((int)$detail['views'] + 1) : 1;
    usb_save($detail);
}

/* =========================================================
   Page metadata
========================================================= */
$tag_filter = isset($_GET['tag']) ? trim($_GET['tag']) : '';
if ($tag_filter !== '') {
    $filtered = array();
    foreach ($posts as $p) {
        $tags = isset($p['tags']) && is_array($p['tags']) ? $p['tags'] : array();
        if (in_array($tag_filter, $tags)) { $filtered[] = $p; }
    }
    $posts = $filtered;
}

if ($detail) {
    $page_title = $detail['title'] . ' | ' . $SITE_NAME;
    $page_description = isset($detail['description']) ? $detail['description'] : '';
    $page_url = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail['id']);
    $page_type = 'article';
    $display_image = usb_display_image($detail);
    $page_image = $display_image !== '' ? $display_image : $DEFAULT_IMAGE;
} else {
    $page_title = $tag_filter !== '' ? '#' . $tag_filter . ' の要約スライド | ' . $SITE_NAME : $SITE_NAME . ' | URL要約スライド';
    $page_description = '入力したURLの内容を、短く読みやすいスライド形式に要約するWebシステムです。';
    $page_url = $BASE_URL . '/' . $THIS_FILE . ($tag_filter !== '' ? '?tag=' . urlencode($tag_filter) : '');
    $page_type = 'website';
    $page_image = $DEFAULT_IMAGE;
}
$jsonld = $detail ? array(
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $detail['title'],
    'description' => $page_description,
    'url' => $page_url,
    'image' => $page_image,
    'datePublished' => isset($detail['created_at']) ? $detail['created_at'] : '',
    'dateModified' => isset($detail['updated_at']) ? $detail['updated_at'] : '',
    'keywords' => isset($detail['tags']) ? implode(',', $detail['tags']) : '',
    'author' => array('@type' => 'Person', 'name' => isset($detail['created_by']) ? $detail['created_by'] : 'url2ai'),
) : array(
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $SITE_NAME,
    'description' => $page_description,
    'url' => $page_url,
);
$is_embed = ($detail && isset($_GET['embed']));
$embed_url = $detail ? $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail['id']) . '&embed=1' : '';
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h(usb_excerpt($page_description, 160)); ?>">
<link rel="canonical" href="<?php echo h($page_url); ?>">
<link rel="alternate" type="application/rss+xml" title="USlideBlog RSS" href="<?php echo h($BASE_URL . '/' . $THIS_FILE . '?feed'); ?>">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h(usb_excerpt($page_description, 160)); ?>">
<meta property="og:type" content="<?php echo h($page_type); ?>">
<meta property="og:url" content="<?php echo h($page_url); ?>">
<meta property="og:image" content="<?php echo h($page_image); ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="stylesheet" href="https://unpkg.com/reveal.js@5/dist/reveal.css">
<link rel="stylesheet" href="https://unpkg.com/reveal.js@5/dist/theme/white.css">
<script type="application/ld+json"><?php echo json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?></script>
<?php if (AIGM_GTAG_ID !== ''): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo h(AIGM_GTAG_ID); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo h(AIGM_GTAG_ID); ?>');</script>
<?php endif; ?>
<style>
/* ── USlideBlog デザイン強化 ── */

/* 背景の nth-child ランダムグラデーションをリセット */
.slide-page,
.slide-page:nth-child(3n+1),
.slide-page:nth-child(3n+2),
.slide-page:nth-child(3n) {
  background: #F8FAFF;
}

/* タイトルを大きく・左アクセントバー付き */
.slide-page h2,
.slide-page .title-inline {
  font-size: clamp(28px, 4vw, 48px);
  line-height: 1.2;
  margin: 0 0 16px;
  color: #0F172A;
  font-weight: 900;
  padding: .12em 0 .15em .6em;
  border-left: 7px solid #3B82F6;
  letter-spacing: -.01em;
}

/* 本文フォント */
.slide-page p,
.slide-page .body-inline {
  font-size: clamp(16px, 1.6vw, 21px);
  line-height: 1.8;
  color: #475569;
}

.slide-page .slide-body-md {
  font-size: clamp(14px, 1.35vw, 18px);
  line-height: 1.72;
  color: #475569;
}

/* テーブルヘッダーをブランドカラーに */
.slide-page .slide-body-md th {
  background: linear-gradient(90deg, #3B82F6, #6366F1);
  color: #fff;
  font-weight: 700;
}
.slide-page .slide-body-md td { color: #475569; }
.slide-page .slide-body-md tr:nth-child(even) td { background: #EEF4FF; }

/* blockquote */
.slide-page .slide-body-md blockquote {
  border-left: 5px solid #3B82F6;
  background: #EFF6FF;
  color: #1E40AF;
  font-weight: 700;
  border-radius: 0 8px 8px 0;
}

/* strong を濃く */
.slide-page .slide-body-md strong { color: #0F172A; font-weight: 900; }

/* ul bullet をブランド色 */
.slide-page .slide-body-md ul li::marker { color: #3B82F6; font-size: 1.1em; }

/* ── カバースライド（1枚目） ── */
.slide-page[data-layout="cover"] {
  background: #fff !important;
}
.slide-page[data-layout="cover"] h2,
.slide-page[data-layout="cover"] .title-inline {
  color: #0F172A;
  border-left-color: #2563EB;
  font-size: clamp(30px, 4.5vw, 56px);
}
.slide-page[data-layout="cover"] p,
.slide-page[data-layout="cover"] .body-inline,
.slide-page[data-layout="cover"] .slide-body-md { color: #475569; }
.slide-page[data-layout="cover"] .slide-body-md strong { color: #2563EB; }

/* ── チャプタースライド（layout=cover 2枚目以降） ── */
.slide-page[data-layout="chapter"] {
  background: #fff !important;
  position: relative;
  overflow: hidden;
}
.slide-page[data-layout="chapter"]::before {
  content: '';
  position: absolute;
  left: 0; top: 0;
  width: 8px; height: 100%;
  background: linear-gradient(180deg, #3B82F6, #6366F1);
}
.slide-page[data-layout="chapter"] h2,
.slide-page[data-layout="chapter"] .title-inline {
  color: #1D4ED8;
  border: none;
  padding-left: 1.2em;
  font-size: clamp(30px, 4.5vw, 54px);
}
.slide-page[data-layout="chapter"] p,
.slide-page[data-layout="chapter"] .body-inline,
.slide-page[data-layout="chapter"] .slide-body-md { color: #475569; }
.slide-page[data-layout="chapter"] .slide-body-md strong { color: #2563EB; }
.slide-page[data-layout="chapter"] .slide-body-md blockquote {
  border-left-color: #2563EB;
  background: #eff6ff;
  color: #1e40af;
}

/* スライドカウント: 全スライド共通の薄グレー */
.slide-page[data-layout="cover"] .slide-count,
.slide-page[data-layout="chapter"] .slide-count { color: #94a3b8; }
</style>
<script>
(function () {
    var s = document.createElement('script');
    s.src = '<?php echo h($BASE_URL); ?>/simpletrack.php'
        + '?url=' + encodeURIComponent(location.href)
        + '&ref=' + encodeURIComponent(document.referrer);
    document.head.appendChild(s);
})();
</script>
<style>
:root{--ink:#172033;--muted:#64748b;--line:#dbe3ef;--soft:#f6f8fb;--paper:#fff;--accent:#2563eb;--accent2:#14b8a6;--danger:#dc2626}
*{box-sizing:border-box}body{margin:0;background:var(--soft);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Yu Gothic",Meiryo,sans-serif;letter-spacing:0;line-height:1.75}a{color:inherit}.top{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.94);border-bottom:1px solid var(--line);backdrop-filter:blur(10px)}.wrap{max-width:1180px;margin:0 auto;padding:12px 18px}.bar{display:flex;align-items:center;justify-content:space-between;gap:14px}.brand{text-decoration:none;display:flex;align-items:center;gap:10px;min-width:0}.logo{width:38px;height:38px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}.brand b{display:block;font-size:19px;line-height:1}.brand span{display:block;font-size:12px;color:var(--muted);white-space:nowrap}.nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);background:#fff;color:var(--ink);text-decoration:none;border-radius:6px;padding:8px 12px;font-size:13px;font-weight:700;cursor:pointer;min-height:36px}.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn.sub{background:#eef6ff;border-color:#bfdbfe;color:#1d4ed8}.btn.danger{background:#fff1f2;border-color:#fecdd3;color:#be123c}.hero{max-width:1180px;margin:0 auto;padding:34px 18px 18px}.hero h1{font-size:34px;line-height:1.35;margin:0 0 10px}.lead{max-width:780px;color:var(--muted);font-size:15px;margin:0}.genbox{margin-top:20px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:16px}.genbox form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px}.input,.textarea,select{width:100%;border:1px solid var(--line);border-radius:6px;padding:10px 12px;background:#fff;font:inherit}.textarea{min-height:88px}.chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}.chip{display:inline-flex;border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 9px;font-size:12px;text-decoration:none;font-weight:700}.main{max-width:1180px;margin:0 auto;padding:10px 18px 20px}.msg{border-radius:8px;padding:12px 14px;margin-bottom:14px}.err{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}.ok{background:#ecfdf5;border:1px solid #bbf7d0;color:#047857}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.card{background:#fff;border:1px solid var(--line);border-radius:8px;text-decoration:none;overflow:hidden;display:flex;flex-direction:column;min-height:250px}.thumb{height:128px;background:linear-gradient(135deg,#e0f2fe,#f0fdfa);display:flex;align-items:center;justify-content:center;color:#1d4ed8;font-weight:800;text-align:center;padding:14px}.thumb img{width:100%;height:100%;object-fit:cover}.cardbody{padding:14px;display:flex;flex-direction:column;gap:8px;flex:1}.card h2{font-size:16px;line-height:1.5;margin:0}.meta{font-size:12px;color:var(--muted)}.tagrow{display:flex;flex-wrap:wrap;gap:5px}.tag{font-size:11px;color:#0f766e;background:#f0fdfa;border:1px solid #99f6e4;border-radius:999px;padding:1px 7px;text-decoration:none}.slide-shell{display:grid;grid-template-columns:220px minmax(0,1fr);gap:18px;align-items:start}.toc{position:sticky;top:76px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px}.toc a{display:block;text-decoration:none;font-size:13px;color:var(--muted);padding:7px 8px;border-radius:5px}.toc a:hover{background:#f1f5f9;color:var(--ink)}.slides{display:grid;gap:16px}.slide{min-height:430px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:34px;display:flex;flex-direction:column;justify-content:center;box-shadow:0 10px 24px rgba(15,23,42,.05)}.slide.cover{background:linear-gradient(135deg,#eff6ff,#f0fdfa)}.slide h2{font-size:32px;line-height:1.35;margin:0 0 18px}.slide p{font-size:18px;line-height:1.9;margin:0;white-space:pre-wrap}.note{margin-top:20px;border-left:3px solid var(--accent2);padding-left:12px;color:var(--muted);font-size:14px}.article-tools{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 18px}.article-title{font-size:23px;line-height:1.3;margin:0 0 6px}.article-head{display:flex;align-items:center;flex-wrap:wrap;gap:6px 14px;margin:6px 0 10px}.article-head .meta,.article-head .tagrow{margin:0}.article-head .article-tools{margin:0 0 0 auto}.article-head .btn{padding:6px 10px;min-height:32px;font-size:12px}.editor{background:#fff;border:1px solid var(--line);border-radius:8px;padding:16px;margin-bottom:16px}.editor-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.slide-edit{border:1px solid var(--line);border-radius:8px;padding:12px;background:#fbfdff}.slide-edit h3{font-size:13px;margin:0 0 8px;color:var(--muted)}.footer{text-align:center;padding:12px 18px;color:var(--muted);font-size:12px}@media(max-width:860px){.bar{align-items:flex-start;flex-direction:column}.nav{justify-content:flex-start;overflow-x:auto;flex-wrap:nowrap;width:100%}.genbox form{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.slide-shell{grid-template-columns:1fr}.toc{position:static}.editor-grid{grid-template-columns:1fr}.hero h1{font-size:28px}.slide{min-height:360px;padding:24px}.slide h2{font-size:25px}.slide p{font-size:16px}.brand span{white-space:normal}}@media print{.top,.genbox,.toc,.article-tools,.editor .article-tools,.footer{display:none}.hero,.main{max-width:none;padding:0}.slide-shell{display:block}.slide{page-break-after:always;border:0;box-shadow:none;min-height:auto;height:90vh}.slide h2{font-size:30px}}
</style>
<style>
.generate-status{display:none;grid-column:1/-1;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:6px;padding:10px 12px;font-size:13px}
.is-generating .generate-status{display:block}
.is-generating button[type=submit]{opacity:.7;cursor:wait}
.slide-player{position:relative;background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden;min-height:380px;height:min(560px,calc(100vh - 240px));height:min(560px,calc(100dvh - 240px));max-width:900px;margin:0 auto}
.slide-feed{height:100%;overflow-y:scroll;scroll-snap-type:y mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;background:#fff}
.slide-feed::-webkit-scrollbar{display:none}
.slide-page{height:100%;min-height:400px;scroll-snap-align:start;display:flex;align-items:center;justify-content:center;padding:22px;background:#fff;color:#172033;position:relative}
.slide-page:nth-child(3n+1){background:#fff}
.slide-page:nth-child(3n+2){background:#fff}
.slide-page:nth-child(3n){background:#fff}
.slide-content{width:min(760px,100%);min-height:42%;display:flex;flex-direction:column;justify-content:center}
.slide-count{position:absolute;top:18px;right:20px;font-size:13px;color:#64748b;font-weight:700}
.slide-page h2,.slide-page .title-inline{font-size:clamp(22px,3vw,36px);line-height:1.25;margin:0 0 14px;color:#172033;font-weight:800}
.slide-page p,.slide-page .body-inline{font-size:clamp(14px,1.35vw,18px);line-height:1.66;margin:0;white-space:pre-wrap;color:#334155}.slide-page .slide-body-md{font-size:clamp(13px,1.25vw,17px);line-height:1.6;color:#334155;white-space:normal}.slide-page .slide-body-md p{margin:.25em 0;font-size:inherit}.slide-page .slide-body-md h3,.slide-page .slide-body-md h4{margin:.5em 0 .2em;font-size:clamp(13px,1.1vw,16px);font-weight:700;color:#172033}.slide-page .slide-body-md ul,.slide-page .slide-body-md ol{margin:.3em 0;padding-left:1.5em}.slide-page .slide-body-md li{margin:.1em 0}.slide-page .slide-body-md table{border-collapse:collapse;width:100%;margin:.4em 0;font-size:clamp(11px,1vw,15px)}.slide-page .slide-body-md th,.slide-page .slide-body-md td{border:1px solid #cbd5e1;padding:.3em .6em;text-align:left;vertical-align:middle}.slide-page .slide-body-md th{background:#dbeafe;color:#1e3a8a;font-weight:700}.slide-page .slide-body-md tr:nth-child(even) td{background:#f8fafc}.slide-page .slide-body-md blockquote{border-left:4px solid #2563eb;background:#eff6ff;margin:.4em 0;padding:.35em .8em;color:#1e40af;font-weight:600;border-radius:0 6px 6px 0}.slide-page .slide-body-md code{background:#f1f5f9;border-radius:3px;padding:.1em .3em;font-size:.85em}.slide-page .slide-body-md strong{font-weight:700;color:#0f172a}.slide-page .slide-body-md del{color:#94a3b8;text-decoration:line-through}
.slide-page .note{font-size:14px;margin-top:22px;color:#64748b}
.slide-side{position:absolute;right:14px;bottom:24px;display:flex;flex-direction:column;gap:12px;z-index:4}
.slide-side button,.pc-slide-nav button{background:rgba(15,23,42,.7);border:1px solid rgba(255,255,255,.18);border-radius:50%;width:38px;height:38px;color:#fff;font-size:18px;cursor:pointer;backdrop-filter:blur(4px)}
.pc-slide-nav{position:absolute;left:14px;top:50%;transform:translateY(-50%);display:flex;flex-direction:column;gap:12px;z-index:4}
.slide-hint{display:none}
@media(max-width:860px){.slide-player{border-radius:0;margin-left:-18px;margin-right:-18px;min-height:calc(100vh - 124px);min-height:calc(100dvh - 124px);height:calc(100vh - 124px);height:calc(100dvh - 124px)}.slide-page{min-height:calc(100vh - 124px);min-height:calc(100dvh - 124px);padding:26px 22px}.pc-slide-nav{display:none}.slide-side{right:10px;bottom:18px}.slide-hint{display:none}.slide-page h2,.slide-page .title-inline{font-size:clamp(25px,8vw,38px)}.slide-page p,.slide-page .body-inline{font-size:clamp(16px,4.8vw,21px)}}
.slide-edit-form{margin:0 0 18px}.slide-edit-form .article-tools{margin:0 0 8px}.slide-edit-form .oss-note{display:none}.slide-edit-form .slide-player{border:2px solid var(--accent);min-height:360px;height:min(450px,calc(100vh - 210px));height:min(450px,calc(100dvh - 210px));max-width:820px}.slide-edit-form .slide-page{min-height:0;padding:16px 20px;overflow:hidden}.slide-edit-form .slide-content{width:min(700px,100%);height:100%;min-height:0;overflow:hidden}.slide-edit-form .title-inline{font-size:clamp(18px,2.4vw,28px);line-height:1.2;margin-bottom:8px;max-height:72px;overflow:auto}.slide-edit-form .body-inline{font-size:clamp(12px,1.08vw,15px);line-height:1.48;max-height:210px;overflow:auto}.slide-edit-form .note{font-size:11px;line-height:1.4;margin-top:8px;max-height:54px;overflow:auto}.slide-edit-form .slide-side button,.slide-edit-form .pc-slide-nav button{width:34px;height:34px;font-size:16px}
.fabric-wrap{width:100%;height:100%;min-height:320px;background:rgba(255,255,255,.28);border:1px dashed rgba(37,99,235,.28);border-radius:8px;overflow:hidden}
.fabric-wrap canvas{display:block}
.tiptap-source{display:none}
.oss-note{background:#f8fafc;border:1px solid var(--line);border-radius:8px;padding:12px 14px;color:var(--muted);font-size:13px;margin:10px 0 16px}
body.embed{background:#fff}.embed .main{max-width:none;padding:0}.embed .slide-player{border-radius:0;max-width:none;width:100%;height:520px;min-height:420px}.embed .slide-page{min-height:420px}.embed .slide-hint{display:none}
</style>
</head>
<body class="<?php echo $is_embed ? 'embed' : ''; ?>">
<?php if (!$is_embed): ?>
<header class="top"><div class="wrap"><div class="bar">
  <a class="brand" href="<?php echo h($THIS_FILE); ?>"><div class="logo">US</div><div><b>USlideBlog</b><span>URLを要約スライドへ</span></div></a>
  <nav class="nav">
    <a class="btn" href="knowradar.php">KnowRadar</a>
    <a class="btn" href="url2ai.html">URL2AI</a>
    <a class="btn sub" href="<?php echo h($THIS_FILE); ?>?feed">RSS</a>
    <?php if ($logged_in): ?><span class="btn">@<?php echo h($session_user); ?></span><a class="btn" href="<?php echo h($auth['logout_url']); ?>">logout</a><?php else: ?><a class="btn primary" href="<?php echo h($auth['login_url']); ?>">X login</a><?php endif; ?>
  </nav>
</div></div></header>

<section class="hero">
  <h1>URLの内容を、短いスライドに要約。</h1>
  <p class="lead">ブログ、記事、解説ページ、日常生活のノウハウなど、入力したURLの内容を読みやすいスライド形式にまとめます。</p>
  <?php if ($is_admin): ?>
  <div class="genbox">
    <form method="post" id="generate-form">
      <input type="hidden" name="action" value="generate">
      <input class="input" type="url" name="source_url" placeholder="https://example.com/tech-article" required>
      <button class="btn primary" type="submit" id="generate-button">スライドブログ生成</button>
      <div class="generate-status" id="generate-status">URLを取得してAIでスライド構成を生成しています。30秒から90秒ほどかかることがあります。この画面のままお待ちください。</div>
    </form>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<main class="main">
<?php if ($error !== ''): ?><div class="msg err"><?php echo h($error); ?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="msg ok">保存しました。</div><?php endif; ?>

<?php if ($detail): ?>
  <?php if (isset($_GET['edit']) && $is_admin): ?>
  <form class="slide-edit-form" method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo h($detail['id']); ?>">
    <input type="hidden" name="title" value="<?php echo h($detail['title']); ?>">
    <input type="hidden" name="description" value="<?php echo h(isset($detail['description']) ? $detail['description'] : ''); ?>">
    <input type="hidden" name="source_url" value="<?php echo h(isset($detail['source_url']) ? $detail['source_url'] : ''); ?>">
    <input type="hidden" name="image" value="<?php echo h(isset($detail['image']) ? $detail['image'] : ''); ?>">
    <input type="hidden" name="tags" value="<?php echo h(!empty($detail['tags']) ? implode(',', $detail['tags']) : ''); ?>">
    <input type="hidden" name="published" value="<?php echo !empty($detail['published']) ? '1' : '0'; ?>">
    <div class="article-tools">
      <button class="btn primary" type="submit">保存</button>
      <a class="btn" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id'])); ?>">公開表示</a>
    </div>
    <div class="oss-note">ローカル配置したFabric.jsで、スライド上の文字を直接編集します。上下ボタンでページを切り替えて、編集後に保存してください。</div>
    <div class="slide-player">
      <div class="pc-slide-nav">
        <button type="button" onclick="goSlide(currentSlide-1)" aria-label="前のスライド">&#8593;</button>
        <button type="button" onclick="goSlide(currentSlide+1)" aria-label="次のスライド">&#8595;</button>
      </div>
      <div class="slide-feed" id="slide-feed">
      <?php foreach ($detail['slides'] as $i => $s): $layout = isset($s['layout']) ? $s['layout'] : 'points'; ?>
        <section class="slide-page" data-layout="<?php echo h($i === 0 ? 'cover' : ($layout === 'cover' ? 'chapter' : $layout)); ?>" id="s<?php echo (int)$i; ?>">
          <input type="hidden" name="slide_layout[]" value="<?php echo h($layout); ?>">
          <div class="slide-count"><?php echo (int)($i + 1); ?> / <?php echo (int)count($detail['slides']); ?></div>
          <div class="slide-content">
            <textarea class="tiptap-source" name="slide_title[]"><?php echo h(isset($s['title']) ? $s['title'] : ''); ?></textarea>
            <textarea class="tiptap-source" name="slide_body[]"><?php echo h(isset($s['body']) ? $s['body'] : ''); ?></textarea>
            <textarea class="tiptap-source" name="slide_note[]"><?php echo h(isset($s['note']) ? $s['note'] : ''); ?></textarea>
            <div class="fabric-wrap"><canvas class="slide-canvas" data-slide="<?php echo (int)$i; ?>"></canvas></div>
          </div>
        </section>
      <?php endforeach; ?>
      </div>
      <div class="slide-side">
        <button type="button" onclick="goSlide(currentSlide-1)" aria-label="前のスライド">&#8593;</button>
        <button type="button" onclick="goSlide(currentSlide+1)" aria-label="次のスライド">&#8595;</button>
      </div>
      <div class="slide-hint">上下キー / スクロールで切替</div>
    </div>
  </form>
  <?php if (!$is_embed): ?>
  <form method="post" onsubmit="return confirm('削除しますか？')" style="margin-top:-8px">
    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo h($detail['id']); ?>"><button class="btn danger" type="submit">削除</button>
  </form>
  <?php endif; ?>
  <?php else: ?>
  <article>
    <?php if (!$is_embed): ?>
    <h1 class="article-title"><?php echo h($detail['title']); ?></h1>
    <div class="article-head">
      <div class="meta"><?php echo h(isset($detail['created_at']) ? $detail['created_at'] : ''); ?> / <?php echo (int)(isset($detail['views']) ? $detail['views'] : 0); ?> views<?php if ($logged_in): ?> / Source: <a href="<?php echo h(isset($detail['source_url']) ? $detail['source_url'] : ''); ?>" target="_blank" rel="noopener"><?php echo h(isset($detail['source_title']) && $detail['source_title'] !== '' ? $detail['source_title'] : (isset($detail['source_url']) ? $detail['source_url'] : '')); ?></a><?php endif; ?></div>
      <?php if (!empty($detail['tags'])): ?><div class="tagrow"><?php foreach ($detail['tags'] as $t): ?><a class="tag" href="?tag=<?php echo urlencode($t); ?>">#<?php echo h($t); ?></a><?php endforeach; ?></div><?php endif; ?>
      <div class="article-tools">
        <?php if ($is_admin): ?><a class="btn primary" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id']) . '&edit=1'); ?>">編集</a><?php endif; ?>
        <button class="btn sub" type="button" id="copy-share">コピー</button>
        <button class="btn sub" type="button" id="copy-embed">&lt;/&gt; 埋め込み</button>
        <button class="btn" type="button" onclick="window.print()">PDF出力</button>
        <a class="btn" href="<?php echo h($THIS_FILE . '?id=' . urlencode($detail['id']) . '&format=pptx'); ?>">PPTX出力</a>
      </div>
    </div>
    <?php endif; ?>
    <div class="slide-player">
      <div class="pc-slide-nav">
        <button type="button" onclick="goSlide(currentSlide-1)" aria-label="前のスライド">&#8593;</button>
        <button type="button" onclick="goSlide(currentSlide+1)" aria-label="次のスライド">&#8595;</button>
      </div>
      <div class="slide-feed" id="slide-feed">
      <?php foreach ($detail['slides'] as $i => $s): $layout = isset($s['layout']) ? $s['layout'] : 'points'; ?>
        <section class="slide-page" data-layout="<?php echo h($i === 0 ? 'cover' : ($layout === 'cover' ? 'chapter' : $layout)); ?>" id="s<?php echo (int)$i; ?>">
          <div class="slide-count"><?php echo (int)($i + 1); ?> / <?php echo (int)count($detail['slides']); ?></div>
          <div class="slide-content">
            <h2><?php echo h(isset($s['title']) ? $s['title'] : ''); ?></h2>
            <p class="slide-body-raw"><?php echo h(isset($s['body']) ? $s['body'] : ''); ?></p>
            <?php if (!empty($s['note'])): ?><div class="note"><?php echo h($s['note']); ?></div><?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>
      </div>
      <div class="slide-side">
        <button type="button" onclick="goSlide(currentSlide-1)" aria-label="前のスライド">&#8593;</button>
        <button type="button" onclick="goSlide(currentSlide+1)" aria-label="次のスライド">&#8595;</button>
      </div>
      <div class="slide-hint">上下キー / スクロールで切替</div>
    </div>
  </article>
  <?php endif; ?>
<?php else: ?>
  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:14px">
    <div><h2 style="margin:0;font-size:22px"><?php echo $tag_filter !== '' ? '#' . h($tag_filter) : '公開要約スライド'; ?></h2><div class="meta">URLから生成した要約スライド一覧</div></div>
  </div>
  <?php if (!$posts): ?><div class="msg err">まだスライドブログがありません。</div><?php endif; ?>
  <div class="grid">
  <?php foreach ($posts as $p): if (empty($p['published']) && !$is_admin) { continue; } ?>
    <a class="card" href="<?php echo h($THIS_FILE . '?id=' . urlencode($p['id'])); ?>">
      <?php $card_image = usb_display_image($p); ?>
      <div class="thumb"><?php if ($card_image !== ''): ?><img src="<?php echo h($card_image); ?>" alt=""><?php else: ?>USlideBlog<?php endif; ?></div>
      <div class="cardbody">
        <h2><?php echo h($p['title']); ?></h2>
        <div class="meta"><?php echo h(isset($p['created_at']) ? $p['created_at'] : ''); ?> / <?php echo (int)(isset($p['views']) ? $p['views'] : 0); ?> views</div>
        <div><?php echo h(usb_excerpt(isset($p['description']) ? $p['description'] : '', 92)); ?></div>
        <div class="tagrow"><?php if (!empty($p['tags'])): foreach (array_slice($p['tags'], 0, 4) as $t): ?><span class="tag">#<?php echo h($t); ?></span><?php endforeach; endif; ?></div>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</main>
<?php if (!$is_embed): ?><footer class="footer">USlideBlog / URL2AI series</footer><?php endif; ?>
<script>
var currentSlide = 0;
var slidePages = Array.from(document.querySelectorAll('.slide-page'));
var slideFeed = document.getElementById('slide-feed');
<?php if ($detail): ?>
var USLIDE_SHARE_TEXT = <?php echo json_encode(trim($detail['title'] . "\n\n" . usb_excerpt(isset($detail['description']) ? $detail['description'] : '', 120) . "\n\n" . $page_url), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var USLIDE_EMBED_CODE = <?php echo json_encode('<div class="uslideblog-embed">' . "\n" . '  <div style="font-weight:bold;margin-bottom:8px;">USlideBlog：' . h($detail['title']) . '</div>' . "\n" . '  <iframe src="' . h($embed_url) . '" width="100%" height="520" frameborder="0" allowfullscreen></iframe>' . "\n" . '</div>', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
<?php endif; ?>
function copyToClipboard(text, btn) {
  if (!text) return;
  var oldText = btn ? btn.textContent : '';
  function done(){
    if (!btn) return;
    btn.textContent = 'コピーしました';
    btn.disabled = true;
    setTimeout(function(){ btn.textContent = oldText; btn.disabled = false; }, 1200);
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(function(){ fallbackCopy(text, done); });
  } else {
    fallbackCopy(text, done);
  }
}
function fallbackCopy(text, done) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.left = '-9999px';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  try { document.execCommand('copy'); if (done) done(); }
  catch(e) {}
  document.body.removeChild(ta);
}
function goSlide(idx) {
  if (!slidePages.length) return;
  if (idx < 0) idx = 0;
  if (idx >= slidePages.length) idx = slidePages.length - 1;
  currentSlide = idx;
  slidePages[idx].scrollIntoView({ behavior: 'smooth', block: 'start' });
  if (history.replaceState) history.replaceState(null, '', '#s' + idx);
}
(function(){
  if (!slideFeed || !slidePages.length) return;
  var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        currentSlide = slidePages.indexOf(entry.target);
      }
    });
  }, { root: slideFeed, threshold: 0.62 });
  slidePages.forEach(function(page) { observer.observe(page); });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowDown' || e.key === 'PageDown' || e.key === ' ') {
      e.preventDefault();
      goSlide(currentSlide + 1);
    }
    if (e.key === 'ArrowUp' || e.key === 'PageUp') {
      e.preventDefault();
      goSlide(currentSlide - 1);
    }
  });
  if (location.hash && /^#s\d+$/.test(location.hash)) {
    goSlide(parseInt(location.hash.substring(2), 10));
  }
})();
(function(){
  var share = document.getElementById('copy-share');
  var embed = document.getElementById('copy-embed');
  if (share) share.onclick = function(){ copyToClipboard(USLIDE_SHARE_TEXT, share); };
  if (embed) embed.onclick = function(){ copyToClipboard(USLIDE_EMBED_CODE, embed); };
})();
(function(){
  if (document.querySelector('.slide-edit-form')) return;
  function inlineMd(t){
    t=t.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');
    t=t.replace(/~~(.+?)~~/g,'<del>$1</del>');
    t=t.replace(/`([^`]+)`/g,'<code>$1</code>');
    return t;
  }
  function mdToHtml(raw){
    var lines=raw.split('\n'),out='',inUl=false,inOl=false,inTbl=false,tblHead=false,i=0,n=lines.length;
    while(i<n){
      var ln=lines[i],tr=ln.replace(/\r$/,'');
      if(/^\|.+\|$/.test(tr)){
        if(/^\|[\s\-:|]+\|$/.test(tr)){i++;continue;}
        if(!inTbl){
          if(inUl){out+='</ul>';inUl=false;}
          if(inOl){out+='</ol>';inOl=false;}
          out+='<table><thead><tr>';
          tr.replace(/^\||\|$/g,'').split('|').forEach(function(c){out+='<th>'+inlineMd(c.trim())+'</th>';});
          out+='</tr></thead><tbody>';
          inTbl=true;i++;continue;
        }
        out+='<tr>';
        tr.replace(/^\||\|$/g,'').split('|').forEach(function(c){out+='<td>'+inlineMd(c.trim())+'</td>';});
        out+='</tr>';i++;continue;
      }
      if(inTbl){out+='</tbody></table>';inTbl=false;}
      var hm=tr.match(/^(#{1,3})\s+(.+)$/);
      if(hm){if(inUl){out+='</ul>';inUl=false;}if(inOl){out+='</ol>';inOl=false;}out+='<h'+(hm[1].length+2)+'>'+inlineMd(hm[2])+'</h'+(hm[1].length+2)+'>';i++;continue;}
      var bq=tr.match(/^>\s*(.+)$/);
      if(bq){if(inUl){out+='</ul>';inUl=false;}if(inOl){out+='</ol>';inOl=false;}out+='<blockquote>'+inlineMd(bq[1])+'</blockquote>';i++;continue;}
      var ul=tr.match(/^[-*]\s+(.+)$/);
      if(ul){if(inOl){out+='</ol>';inOl=false;}if(!inUl){out+='<ul>';inUl=true;}out+='<li>'+inlineMd(ul[1])+'</li>';i++;continue;}
      var ol=tr.match(/^\d+\.\s+(.+)$/);
      if(ol){if(inUl){out+='</ul>';inUl=false;}if(!inOl){out+='<ol>';inOl=true;}out+='<li>'+inlineMd(ol[1])+'</li>';i++;continue;}
      if(inUl){out+='</ul>';inUl=false;}if(inOl){out+='</ol>';inOl=false;}
      if(tr.trim()===''){out+='<br>';i++;continue;}
      out+='<p>'+inlineMd(tr)+'</p>';i++;
    }
    if(inTbl)out+='</tbody></table>';
    if(inUl)out+='</ul>';
    if(inOl)out+='</ol>';
    return out;
  }
  document.querySelectorAll('.slide-body-raw').forEach(function(el){
    var raw=el.textContent;
    var div=document.createElement('div');
    div.className='slide-body-md';
    div.innerHTML=mdToHtml(raw);
    el.parentNode.replaceChild(div,el);
  });
})();
(function(){
  var form = document.getElementById('generate-form');
  if (!form) return;
  form.onsubmit = function(){
    form.className += ' is-generating';
    var btn = document.getElementById('generate-button');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '生成中...';
    }
    var status = document.getElementById('generate-status');
    if (status) {
      status.style.display = 'block';
      status.innerHTML = 'URLを取得してAIでスライド構成を生成しています。完了すると編集画面へ移動します。';
    }
    return true;
  };
})();
</script>
<?php if ($detail && isset($_GET['edit']) && $is_admin): ?>
<script src="vendor/fabric.min.js"></script>
<script>
(function(){
  if (!window.fabric) return;
  var editors = [];
  function fitText(obj, maxHeight, minSize) {
    while (obj.calcTextHeight && obj.calcTextHeight() > maxHeight && obj.fontSize > minSize) {
      obj.set('fontSize', obj.fontSize - 1);
    }
  }
  document.querySelectorAll('.slide-canvas').forEach(function(node) {
    var page = node.closest('.slide-page');
    var wrap = node.closest('.fabric-wrap');
    var textareas = page ? page.querySelectorAll('textarea') : [];
    if (!wrap || textareas.length < 3) return;
    var w = Math.max(320, wrap.clientWidth || 700);
    var h = Math.max(260, wrap.clientHeight || 340);
    node.width = w;
    node.height = h;
    var canvas = new fabric.Canvas(node, {
      backgroundColor: 'rgba(255,255,255,0)',
      selection: false,
      preserveObjectStacking: true
    });
    var title = new fabric.Textbox(textareas[0].value || 'タイトル', {
      left: 28, top: 26, width: w - 56,
      fontSize: Math.max(20, Math.min(30, w / 24)),
      fontWeight: 800, fill: '#172033', lineHeight: 1.16
    });
    var body = new fabric.Textbox(textareas[1].value || '本文', {
      left: 32, top: Math.min(112, h * 0.28), width: w - 64,
      fontSize: Math.max(13, Math.min(17, w / 46)),
      fill: '#334155', lineHeight: 1.34
    });
    var note = new fabric.Textbox(textareas[2].value || '', {
      left: 32, top: h - 64, width: w - 64,
      fontSize: 11, fill: '#64748b', lineHeight: 1.25
    });
    fitText(title, 78, 16);
    fitText(body, Math.max(120, h - 190), 11);
    canvas.add(title, body, note);
    canvas.setActiveObject(body);
    function sync() {
      textareas[0].value = title.text || '';
      textareas[1].value = body.text || '';
      textareas[2].value = note.text || '';
    }
    canvas.on('text:changed', function(e) {
      if (e.target === title) fitText(title, 78, 16);
      if (e.target === body) fitText(body, Math.max(120, h - 190), 11);
      sync();
    });
    editors.push(sync);
  });
  window.usbSyncFabricEditors = function() { editors.forEach(function(fn) { fn(); }); };
})();
document.querySelectorAll('.slide-edit-form').forEach(function(form) {
  form.addEventListener('submit', function() {
    if (window.usbSyncFabricEditors) window.usbSyncFabricEditors();
    var firstTitle = form.querySelector('textarea[name="slide_title[]"]');
    var title = form.querySelector('input[name="title"]');
    if (firstTitle && title) title.value = firstTitle.value;
  });
});
</script>
<?php endif; ?>
</body>
</html>
