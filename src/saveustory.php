<?php
require_once __DIR__ . '/auth_common.php';
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

$DATA_DIR = __DIR__ . '/data';
$BASE_URL = AIGM_BASE_URL;

function su_json($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function su_mode($mode) {
    return trim((string)$mode) === 'analysis' ? 'analysis' : 'story';
}

function su_clean_tweet_id($tweet_id) {
    return preg_replace('/[^0-9]/', '', (string)$tweet_id);
}

function su_is_failed_ai_text($text) {
    $text = trim((string)$text);
    if ($text === '') { return true; }
    if (preg_match('/^(申し訳|すみません|解析できません|分析できません|考察できません|生成できません|エラー)/u', $text)) {
        return true;
    }
    if (stripos($text, 'failed') !== false && (stripos($text, 'Ollama') !== false || stripos($text, 'Claude') !== false || stripos($text, 'Codex') !== false)) {
        return true;
    }
    return false;
}

function su_file($tweet_id) {
    global $DATA_DIR;
    return $DATA_DIR . '/xinsight_' . su_clean_tweet_id($tweet_id) . '.json';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    su_json(array('error' => 'POST only'), 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    su_json(array('error' => 'Invalid JSON'), 400);
}

$tweet_id = su_clean_tweet_id(isset($input['tweet_id']) ? $input['tweet_id'] : '');
$tweet_url = trim((string)(isset($input['tweet_url']) ? $input['tweet_url'] : ''));
$thread_text = trim((string)(isset($input['thread_text']) ? $input['thread_text'] : ''));
$mode = su_mode(isset($input['generation_mode']) ? $input['generation_mode'] : 'story');
$output = trim((string)(isset($input['output']) ? $input['output'] : (isset($input['story']) ? $input['story'] : '')));
$username = trim((string)(isset($input['username']) ? $input['username'] : ''));
$ai_provider = trim((string)(isset($input['ai_provider']) ? $input['ai_provider'] : ''));
$ai_model = trim((string)(isset($input['ai_model']) ? $input['ai_model'] : ''));

if ($tweet_id === '' || !preg_match('/^\d{10,25}$/', $tweet_id)) {
    su_json(array('error' => 'invalid tweet_id'), 400);
}
if ($tweet_url === '') {
    su_json(array('error' => 'tweet_url required'), 400);
}
if ($thread_text === '') {
    su_json(array('error' => 'thread_text required'), 400);
}
if (su_is_failed_ai_text($output)) {
    su_json(array('error' => 'invalid generated output'), 400);
}

if (!is_dir($DATA_DIR) && !mkdir($DATA_DIR, 0775, true)) {
    su_json(array('error' => 'cannot create data directory'), 500);
}

$file = su_file($tweet_id);
$existing = array();
if (file_exists($file)) {
    $decoded = json_decode(file_get_contents($file), true);
    if (is_array($decoded)) { $existing = $decoded; }
}

$outputs = array();
if (!empty($existing['outputs']) && is_array($existing['outputs'])) {
    $outputs = $existing['outputs'];
}
$outputs[$mode] = $output;

$post = $existing;
$post['tweet_id'] = $tweet_id;
$post['tweet_url'] = $tweet_url;
$post['username'] = $username;
$post['thread_text'] = $thread_text;
$post['generation_mode'] = $mode;
$post['outputs'] = $outputs;
$post['saved_at'] = date('Y-m-d H:i:s');
if ($ai_provider !== '') { $post['ustory_ai_provider'] = $ai_provider; }
if ($ai_model !== '') { $post['ustory_ai_model'] = $ai_model; }
if ($mode === 'analysis') {
    $post['story_analysis'] = $output;
} else {
    $post['story'] = $output;
}

$json = json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (file_put_contents($file, $json, LOCK_EX) === false) {
    su_json(array('error' => 'save failed'), 500);
}

su_json(array(
    'status' => empty($existing) ? 'ok' : 'updated',
    'id' => $tweet_id,
    'mode' => $mode,
    'url' => rtrim($BASE_URL, '/') . '/ustoryv.php?id=' . rawurlencode($tweet_id) . '&mode=' . rawurlencode($mode),
));
