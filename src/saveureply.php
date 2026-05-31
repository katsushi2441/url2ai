<?php
require_once __DIR__ . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

if (!defined('OLLAMA_API')) {
    define('OLLAMA_API', 'https://exbridge.ddns.net/api/generate');
}
if (!defined('OLLAMA_MODEL')) {
    define('OLLAMA_MODEL', 'gemma4:e4b');
}

$DATA_DIR = __DIR__ . '/data';

function ur_json_response($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(array('ok' => false, 'error' => 'JSON生成失敗'));
    }
    echo $json;
    exit;
}

$auth = url2ai_auth_bootstrap();
if (empty($auth['is_admin'])) {
    ur_json_response(array('ok' => false, 'error' => '権限がありません'));
}

$input     = json_decode(file_get_contents('php://input'), true);
if (!$input) { $input = array(); }
$action    = isset($input['action'])    ? $input['action']    : '';
$tweet_url = isset($input['tweet_url']) ? trim($input['tweet_url']) : '';

/* ── action: generate ── */
if ($action === 'generate') {

    /* saveainews.php と同じ tweet_id 抽出 */
    if (!preg_match('/(\d{15,20})/', $tweet_url, $m)) {
        ur_json_response(array('ok' => false, 'error' => 'tweet_id取得失敗'));
    }
    $tweet_id = $m[1];

    /* saveainews.php と同じ fxtwitter 取得 */
    $fx_opts = array('http' => array(
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ));
    $fx_res = @file_get_contents('https://api.fxtwitter.com/i/status/' . $tweet_id, false, stream_context_create($fx_opts));
    if (!$fx_res) {
        ur_json_response(array('ok' => false, 'error' => 'X投稿取得失敗'));
    }
    $fx = json_decode($fx_res, true);
    if (empty($fx['tweet'])) {
        ur_json_response(array('ok' => false, 'error' => 'ツイートデータなし'));
    }
    $tweet        = $fx['tweet'];
    $tweet_text   = isset($tweet['text'])                   ? $tweet['text']                   : '';
    $tweet_author = isset($tweet['author']['screen_name'])  ? $tweet['author']['screen_name']  : '';

    /* saveainews.php と同じ Ollama 呼び出し */
    $prompt = "以下のXの投稿に対する返信を生成してください。\n\n条件：\n- 200字以内\n- 自然な日本語\n- 投稿内容に関連\n- ポジティブで建設的\n- 末尾にハッシュタグを1つだけ追加\n\n投稿：\n{$tweet_text}\n\n返信文のみを出力してください。";
    $payload = json_encode(array(
        'model'  => OLLAMA_MODEL,
        'prompt' => $prompt,
        'stream' => false,
    ), JSON_UNESCAPED_UNICODE);
    $ollama_opts = array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 120,
    ));
    $res = @file_get_contents(OLLAMA_API, false, stream_context_create($ollama_opts));
    $reply = '';
    if ($res) {
        $data  = json_decode($res, true);
        $reply = isset($data['response']) ? trim($data['response']) : '';
    }
    if ($reply === '') {
        ur_json_response(array('ok' => false, 'error' => 'AI生成に失敗しました'));
    }

    ur_json_response(array('ok' => true, 'reply' => $reply,
        'tweet_text' => $tweet_text, 'tweet_author' => $tweet_author, 'tweet_id' => $tweet_id));
}

/* ── action: post (nextpost0.php と同じ OAuth1.0a) ── */
if ($action === 'post') {
    $reply_text = trim(isset($input['reply_text']) ? $input['reply_text'] : '');
    $tweet_id   = preg_replace('/[^0-9]/', '', isset($input['tweet_id']) ? $input['tweet_id'] : '');
    $post_type  = isset($input['post_type']) ? $input['post_type'] : 'reply';

    if ($reply_text === '' || $tweet_id === '') {
        ur_json_response(array('ok' => false, 'error' => 'パラメータ不足'));
    }

    $x_keys = array();
    $xk_file = __DIR__ . '/x_api_keys.sh';
    if (file_exists($xk_file)) {
        foreach (file($xk_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
            if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $ln, $m)) {
                $x_keys[trim($m[1])] = trim($m[2]);
            }
        }
    }
    $o1_key          = isset($x_keys['X_API_KEY'])             ? $x_keys['X_API_KEY']             : '';
    $o1_secret       = isset($x_keys['X_API_KEY_SECRET'])      ? $x_keys['X_API_KEY_SECRET']      : '';
    $o1_token        = isset($x_keys['X_ACCESS_TOKEN'])        ? $x_keys['X_ACCESS_TOKEN']        : '';
    $o1_token_secret = isset($x_keys['X_ACCESS_TOKEN_SECRET']) ? $x_keys['X_ACCESS_TOKEN_SECRET'] : '';

    /* nextpost0.php と同じ OAuth1.0a 署名 */
    $api     = 'https://api.twitter.com/2/tweets';
    $payload = array('text' => $reply_text);
    if ($post_type === 'reply') {
        $payload['reply'] = array('in_reply_to_tweet_id' => $tweet_id);
    } else {
        $payload['quote_tweet_id'] = $tweet_id;
    }

    $oauth = array(
        'oauth_consumer_key'     => $o1_key,
        'oauth_nonce'            => bin2hex(openssl_random_pseudo_bytes(16)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp'        => (string)time(),
        'oauth_token'            => $o1_token,
        'oauth_version'          => '1.0',
    );
    ksort($oauth);
    $base = 'POST&' . rawurlencode($api) . '&' . rawurlencode(http_build_query($oauth));
    $key  = rawurlencode($o1_secret) . '&' . rawurlencode($o1_token_secret);
    $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));
    $parts = array();
    foreach ($oauth as $ok => $ov) { $parts[] = rawurlencode($ok) . '="' . rawurlencode($ov) . '"'; }
    $auth_header = 'OAuth ' . implode(', ', $parts);

    $post_opts = array('http' => array('method' => 'POST',
        'header'  => "Authorization: $auth_header\r\nContent-Type: application/json\r\nUser-Agent: NextPost/1.0\r\n",
        'content' => json_encode($payload), 'timeout' => 20, 'ignore_errors' => true));
    $r = @file_get_contents($api, false, stream_context_create($post_opts));
    $result = json_decode($r ? $r : '{}', true);

    if (empty($result['data']['id'])) {
        $err = isset($result['detail']) ? $result['detail'] :
               (isset($result['title']) ? $result['title'] : json_encode($result));
        ur_json_response(array('ok' => false, 'error' => $err));
    }

    $posted_id  = $result['data']['id'];
    $posted_url = 'https://x.com/i/status/' . $posted_id;

    $id   = 'ureply_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6);
    $data = array(
        'id'           => $id,
        'tweet_url'    => isset($input['tweet_url'])    ? $input['tweet_url']    : '',
        'tweet_id'     => $tweet_id,
        'tweet_text'   => isset($input['tweet_text'])   ? $input['tweet_text']   : '',
        'tweet_author' => isset($input['tweet_author']) ? $input['tweet_author'] : '',
        'reply_text'   => $reply_text,
        'post_type'    => $post_type,
        'posted_tweet_id' => $posted_id,
        'posted_url'   => $posted_url,
        'created_at'   => date('Y-m-d H:i:s'),
        'timestamp'    => time(),
    );
    file_put_contents($DATA_DIR . '/' . $id . '.json',
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    ur_json_response(array('ok' => true, 'posted_url' => $posted_url));
}

ur_json_response(array('ok' => false, 'error' => 'Unknown action'));
