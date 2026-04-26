<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');

if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime',  $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.cookie_path',     '/');
    ini_set('session.cookie_domain',   'aiknowledgecms.exbridge.jp');
    ini_set('session.cookie_secure',   '1');
    ini_set('session.cookie_httponly', '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(),
            time() + $session_lifetime, '/',
            'aiknowledgecms.exbridge.jp', true, true);
    }
}

$BASE_URL  = AIGM_BASE_URL;
$THIS_FILE = 'polymarket.php';
$ADMIN     = AIGM_ADMIN;
$DATA_DIR  = __DIR__ . '/data';

$x_keys_file = __DIR__ . '/x_api_keys.sh';
$x_keys = array();
if (file_exists($x_keys_file)) {
    $lines = file($x_keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m)) {
            $x_keys[trim($m[1])] = trim($m[2]);
        }
    }
}
$x_client_id     = isset($x_keys['X_API_KEY'])    ? $x_keys['X_API_KEY']    : '';
$x_client_secret = isset($x_keys['X_API_SECRET']) ? $x_keys['X_API_SECRET'] : '';
$x_redirect_uri  = 'https://aiknowledgecms.exbridge.jp/polymarket.php';

function pm_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function pm_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return pm_base64url($bytes);
}
function pm_gen_challenge($verifier) {
    return pm_base64url(hash('sha256', $verifier, true));
}
function pm_x_post($url, $post_data, $headers) {
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'content'       => $post_data,
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}
function pm_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: PolymarketIntel/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['pm_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/',
        'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['pm_login'])) {
    $verifier  = pm_gen_verifier();
    $challenge = pm_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['pm_code_verifier'] = $verifier;
    $_SESSION['pm_oauth_state']   = $state;
    $params = array(
        'response_type'         => 'code',
        'client_id'             => $x_client_id,
        'redirect_uri'          => $x_redirect_uri,
        'scope'                 => 'tweet.read users.read offline.access',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    );
    session_write_close();
    $auth_url = 'https://twitter.com/i/oauth2/authorize?' . http_build_query($params);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if (strpos($ua, 'Android') !== false) {
        $intent_url = 'intent://twitter.com/i/oauth2/authorize?' . http_build_query($params)
            . '#Intent;scheme=https;package=com.android.chrome;'
            . 'S.browser_fallback_url=' . urlencode($auth_url) . ';end';
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
        echo '<script>window.location.href=' . json_encode($intent_url) . ';</script>';
        echo '</body></html>';
    } else {
        header('Location: ' . $auth_url);
    }
    exit;
}
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['pm_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['pm_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['pm_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = pm_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']  = $data['access_token'];
            $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['pm_oauth_state'], $_SESSION['pm_code_verifier']);
            $me = pm_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) {
                $_SESSION['session_username'] = $me['data']['username'];
            }
        }
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

if (
    !empty($_SESSION['session_refresh_token']) &&
    !empty($_SESSION['session_token_expires']) &&
    time() > $_SESSION['session_token_expires'] - 300
) {
    $cred_r = base64_encode($x_client_id . ':' . $x_client_secret);
    $post_r = http_build_query(array(
        'grant_type'    => 'refresh_token',
        'refresh_token' => $_SESSION['session_refresh_token'],
        'client_id'     => $x_client_id,
    ));
    $ref = pm_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . $cred_r,
    ));
    if (!empty($ref['access_token'])) {
        $_SESSION['session_access_token']  = $ref['access_token'];
        $_SESSION['session_token_expires'] = time() + (isset($ref['expires_in']) ? (int)$ref['expires_in'] : 7200);
        if (!empty($ref['refresh_token'])) {
            $_SESSION['session_refresh_token'] = $ref['refresh_token'];
        }
    } else {
        unset($_SESSION['session_access_token'], $_SESSION['session_refresh_token'],
              $_SESSION['session_token_expires'], $_SESSION['session_username']);
    }
}

$logged_in = isset($_SESSION['session_access_token']) && $_SESSION['session_access_token'] !== '';
$username  = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin  = ($username === $ADMIN);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function pm_slug($query) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($query)));
}
function pm_data_file($query) {
    global $DATA_DIR;
    return $DATA_DIR . '/polymarket_' . pm_slug($query) . '_' . date('Ymd') . '.json';
}
function pm_load($query) {
    global $DATA_DIR;
    $slug  = pm_slug($query);
    $files = glob($DATA_DIR . '/polymarket_' . $slug . '_*.json');
    if (!$files) $files = array();
    if (empty($files)) return null;
    rsort($files);
    foreach ($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (is_array($d) && !empty($d['report'])) return $d;
    }
    return null;
}
function pm_save($query, $data) {
    global $DATA_DIR;
    if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);
    file_put_contents(pm_data_file($query), json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}
function pm_find_latest_file($query) {
    global $DATA_DIR;
    $slug  = pm_slug($query);
    $files = glob($DATA_DIR . '/polymarket_' . $slug . '_*.json');
    if (!$files) $files = array();
    if (empty($files)) return null;
    rsort($files);
    return $files[0];
}
function pm_update_latest($query, $updates) {
    $path = pm_find_latest_file($query);
    if (!$path || !is_array($updates)) return false;
    $raw = @file_get_contents($path);
    if (!$raw) return false;
    $data = json_decode($raw, true);
    if (!is_array($data)) return false;
    foreach ($updates as $key => $value) {
        $data[$key] = $value;
    }
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    return $data;
}
function pm_read_json_file($path) {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['report'])) return null;
    return $data;
}
function pm_created_ts($item) {
    if (!empty($item['created_at'])) {
        $ts = strtotime($item['created_at']);
        if ($ts !== false) return $ts;
    }
    return time();
}
function pm_json_response($data, $status_code) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
function pm_load_all_reports($with_report, $limit, $since_ts) {
    global $DATA_DIR, $BASE_URL;
    $files = glob($DATA_DIR . '/polymarket_*.json');
    if (!$files) $files = array();
    $items = array();
    foreach ($files as $path) {
        $data = pm_read_json_file($path);
        if (!$data) continue;
        $created_ts = pm_created_ts($data);
        if ($since_ts > 0 && $created_ts <= $since_ts) continue;
        $query = isset($data['query']) ? trim($data['query']) : '';
        if ($query === '') continue;
        $item = array(
            'id'              => pm_slug($query) . '-' . date('YmdHis', $created_ts),
            'query'           => $query,
            'slug'            => pm_slug($query),
            'depth'           => isset($data['depth']) ? $data['depth'] : 'medium',
            'summary'         => isset($data['summary']) ? $data['summary'] : '',
            'matched_markets' => isset($data['matched_markets']) && is_array($data['matched_markets']) ? $data['matched_markets'] : array(),
            'sources'         => isset($data['sources']) && is_array($data['sources']) ? $data['sources'] : array(),
            'created_at'      => date('c', $created_ts),
            'created_ts'      => $created_ts,
            'detail_url'      => $BASE_URL . '/polymarket.php?query=' . urlencode($query),
            'paragraph_url'   => isset($data['paragraph_url']) ? $data['paragraph_url'] : '',
            'paragraph_post_id' => isset($data['paragraph_post_id']) ? $data['paragraph_post_id'] : '',
        );
        if ($with_report) {
            $item['report'] = isset($data['report']) ? $data['report'] : '';
        }
        $items[] = $item;
    }
    usort($items, function ($a, $b) {
        $a_ts = isset($a['created_ts']) ? (int) $a['created_ts'] : 0;
        $b_ts = isset($b['created_ts']) ? (int) $b['created_ts'] : 0;
        if ($a_ts === $b_ts) return 0;
        return ($a_ts < $b_ts) ? 1 : -1;
    });
    if ($limit > 0) {
        $items = array_slice($items, 0, $limit);
    }
    return $items;
}

$flash_error = isset($_SESSION['pm_flash_error']) ? $_SESSION['pm_flash_error'] : '';
if (isset($_SESSION['pm_flash_error'])) unset($_SESSION['pm_flash_error']);

if (isset($_GET['api']) && $_GET['api'] !== '') {
    $api = trim($_GET['api']);

    if ($api === 'recent') {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        if ($limit < 1) $limit = 20;
        if ($limit > 100) $limit = 100;
        $with_report = !empty($_GET['with_report']) && $_GET['with_report'] !== '0';
        $since_ts = isset($_GET['since']) ? (int) $_GET['since'] : 0;
        $items = pm_load_all_reports($with_report, $limit, $since_ts);
        pm_json_response(array(
            'ok'           => true,
            'count'        => count($items),
            'generated_at' => date('c'),
            'items'        => $items,
        ), 200);
    }

    if ($api === 'detail') {
        $query = isset($_GET['query']) ? trim($_GET['query']) : '';
        if ($query === '') {
            pm_json_response(array('ok' => false, 'error' => 'query is required'), 400);
        }
        $saved = pm_load($query);
        if (!$saved) {
            pm_json_response(array('ok' => false, 'error' => 'report not found'), 404);
        }
        $created_ts = pm_created_ts($saved);
        pm_json_response(array(
            'ok' => true,
            'item' => array(
                'id'              => pm_slug($query) . '-' . date('YmdHis', $created_ts),
                'query'           => $saved['query'],
                'slug'            => pm_slug($saved['query']),
                'depth'           => isset($saved['depth']) ? $saved['depth'] : 'medium',
                'summary'         => isset($saved['summary']) ? $saved['summary'] : '',
                'report'          => isset($saved['report']) ? $saved['report'] : '',
                'matched_markets' => isset($saved['matched_markets']) ? $saved['matched_markets'] : array(),
                'sources'         => isset($saved['sources']) ? $saved['sources'] : array(),
                'created_at'      => date('c', $created_ts),
                'created_ts'      => $created_ts,
                'detail_url'      => $BASE_URL . '/polymarket.php?query=' . urlencode($saved['query']),
                'paragraph_url'   => isset($saved['paragraph_url']) ? $saved['paragraph_url'] : '',
                'paragraph_post_id' => isset($saved['paragraph_post_id']) ? $saved['paragraph_post_id'] : '',
            ),
        ), 200);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            pm_json_response(array('ok' => false, 'error' => 'POST required'), 405);
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            pm_json_response(array('ok' => false, 'error' => 'invalid json'), 400);
        }
        $query  = isset($body['query'])  ? trim($body['query'])  : '';
        $report = isset($body['report']) ? trim($body['report']) : '';
        if ($query === '' || $report === '') {
            pm_json_response(array('ok' => false, 'error' => 'query and report are required'), 400);
        }
        $created_at = isset($body['created_at']) && trim($body['created_at']) !== ''
            ? trim($body['created_at'])
            : date('Y-m-d H:i:s');
        $save_data = array(
            'query'           => $query,
            'depth'           => isset($body['depth']) ? $body['depth'] : 'medium',
            'report'          => $report,
            'summary'         => isset($body['summary']) ? $body['summary'] : '',
            'matched_markets' => isset($body['matched_markets']) && is_array($body['matched_markets']) ? $body['matched_markets'] : array(),
            'sources'         => isset($body['sources']) && is_array($body['sources']) ? $body['sources'] : array(),
            'created_at'      => $created_at,
            'paragraph_url'   => isset($body['paragraph_url']) ? trim((string) $body['paragraph_url']) : '',
            'paragraph_post_id' => isset($body['paragraph_post_id']) ? trim((string) $body['paragraph_post_id']) : '',
        );
        pm_save($query, $save_data);
        $saved = pm_load($query);
        $created_ts = $saved ? pm_created_ts($saved) : time();
        pm_json_response(array(
            'ok' => true,
            'item' => array(
                'id'              => pm_slug($query) . '-' . date('YmdHis', $created_ts),
                'query'           => $query,
                'slug'            => pm_slug($query),
                'summary'         => $save_data['summary'],
                'report'          => $report,
                'matched_markets' => $save_data['matched_markets'],
                'sources'         => $save_data['sources'],
                'created_at'      => date('c', $created_ts),
                'created_ts'      => $created_ts,
                'detail_url'      => $BASE_URL . '/polymarket.php?query=' . urlencode($query),
                'paragraph_url'   => $save_data['paragraph_url'],
                'paragraph_post_id' => $save_data['paragraph_post_id'],
            ),
        ), 200);
    }

    if ($api === 'mark_paragraph') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            pm_json_response(array('ok' => false, 'error' => 'POST required'), 405);
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            pm_json_response(array('ok' => false, 'error' => 'invalid json'), 400);
        }
        $query             = isset($body['query'])             ? trim($body['query'])             : '';
        $paragraph_url     = isset($body['paragraph_url'])     ? trim($body['paragraph_url'])     : '';
        $paragraph_post_id = isset($body['paragraph_post_id']) ? trim((string)$body['paragraph_post_id']) : '';
        if ($query === '' || ($paragraph_url === '' && $paragraph_post_id === '')) {
            pm_json_response(array('ok' => false, 'error' => 'query and paragraph_url or paragraph_post_id are required'), 400);
        }
        $updated = pm_update_latest($query, array(
            'paragraph_url'       => $paragraph_url,
            'paragraph_post_id'   => $paragraph_post_id,
            'paragraph_posted_at' => date('c'),
        ));
        if (!$updated) {
            pm_json_response(array('ok' => false, 'error' => 'report not found'), 404);
        }
        pm_json_response(array(
            'ok'                  => true,
            'query'               => $updated['query'],
            'paragraph_url'       => $updated['paragraph_url'],
            'paragraph_post_id'   => isset($updated['paragraph_post_id']) ? $updated['paragraph_post_id'] : '',
            'paragraph_posted_at' => isset($updated['paragraph_posted_at']) ? $updated['paragraph_posted_at'] : '',
        ), 200);
    }

    if ($api === 'paragraph_post') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            pm_json_response(array('ok' => false, 'error' => 'POST required'), 405);
        }
        $session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
        if ($session_user !== $ADMIN) {
            pm_json_response(array('ok' => false, 'error' => 'unauthorized'), 403);
        }
        if (!PARAGRAPH_API_KEY) {
            pm_json_response(array('ok' => false, 'error' => 'PARAGRAPH_API_KEY not configured'), 500);
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            pm_json_response(array('ok' => false, 'error' => 'invalid json'), 400);
        }
        $query = isset($body['query']) ? trim($body['query']) : '';
        if ($query === '') {
            pm_json_response(array('ok' => false, 'error' => 'query is required'), 400);
        }
        $saved = pm_load($query);
        if (!$saved || empty($saved['report'])) {
            pm_json_response(array('ok' => false, 'error' => 'report not found'), 404);
        }
        $existing_url = isset($saved['paragraph_url'])     ? trim((string) $saved['paragraph_url'])     : '';
        $existing_id  = isset($saved['paragraph_post_id']) ? trim((string) $saved['paragraph_post_id']) : '';
        if ($existing_url !== '' || $existing_id !== '') {
            pm_json_response(array(
                'ok'                => true,
                'paragraph_url'     => $existing_url,
                'paragraph_post_id' => $existing_id,
            ), 200);
        }
        $title      = 'Polymarket Intelligence: ' . $query;
        $summary    = isset($saved['summary']) ? trim((string) $saved['summary']) : '';
        $detail_url = $BASE_URL . '/polymarket.php?query=' . urlencode($query);
        $markdown   = "# " . $title . "\n\n";
        if ($summary !== '') {
            $markdown .= "> " . str_replace("\n", "\n> ", $summary) . "\n\n";
        }
        $markdown .= trim((string) $saved['report']) . "\n\n---\n";
        $markdown .= "Source:\n- Polymarket Intelligence: " . $detail_url . "\n";
        $markdown .= "Bankr / URL2AI:\n- https://bankr.bot/discover/0xDaecDda6AD112f0E1E4097fB735dD01D9C33cBA3\n";
        $payload = json_encode(array(
            'title'    => $title,
            'markdown' => $markdown,
            'status'   => 'published',
        ), JSON_UNESCAPED_UNICODE);
        $opts = array('http' => array(
            'method'        => 'POST',
            'header'        => "Authorization: Bearer " . PARAGRAPH_API_KEY . "\r\nContent-Type: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 60,
            'ignore_errors' => true,
        ));
        $res     = @file_get_contents('https://public.api.paragraph.com/api/v1/posts', false, stream_context_create($opts));
        $res_arr = $res ? json_decode($res, true) : array();
        $para_url     = isset($res_arr['url']) ? $res_arr['url'] : (isset($res_arr['canonicalUrl']) ? $res_arr['canonicalUrl'] : '');
        $para_post_id = isset($res_arr['id']) ? (string)$res_arr['id'] : (isset($res_arr['postId']) ? (string)$res_arr['postId'] : '');
        if (!$para_url && !$para_post_id) {
            pm_json_response(array('ok' => false, 'error' => 'Paragraph API failed', 'detail' => $res_arr), 500);
        }
        $updated = pm_update_latest($query, array(
            'paragraph_url'       => $para_url,
            'paragraph_post_id'   => $para_post_id,
            'paragraph_posted_at' => date('c'),
        ));
        if (!$updated) {
            pm_json_response(array('ok' => false, 'error' => 'report update failed'), 500);
        }
        pm_json_response(array(
            'ok'                  => true,
            'paragraph_url'       => $para_url,
            'paragraph_post_id'   => $para_post_id,
            'paragraph_posted_at' => isset($updated['paragraph_posted_at']) ? $updated['paragraph_posted_at'] : '',
        ), 200);
    }

    pm_json_response(array('ok' => false, 'error' => 'unknown api'), 404);
}

$query  = '';
$depth  = 'medium';
$saved  = null;
$action = isset($_POST['action']) ? $_POST['action'] : '';

if (isset($_GET['query']) && $_GET['query'] !== '') {
    $query = trim($_GET['query']);
    $depth = isset($_GET['depth']) ? trim($_GET['depth']) : 'medium';
    $saved = pm_load($query);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'view_or_generate') {
    $query = isset($_POST['query']) ? trim($_POST['query']) : '';
    $depth = isset($_POST['depth']) ? trim($_POST['depth']) : 'medium';
    if ($query === '') { header('Location: ' . $x_redirect_uri); exit; }
    if (pm_load($query)) {
        header('Location: ' . $x_redirect_uri . '?query=' . urlencode($query) . '&depth=' . urlencode($depth)); exit;
    }
    if (!$is_admin) {
        header('Location: ' . $x_redirect_uri . '?query=' . urlencode($query)); exit;
    }
    $_POST['action'] = 'generate';
    $action = 'generate';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && $action === 'generate') {
    $query = isset($_POST['query']) ? trim($_POST['query']) : '';
    $depth = isset($_POST['depth']) ? trim($_POST['depth']) : 'medium';
    if ($query === '') {
        $_SESSION['pm_flash_error'] = 'クエリを入力してください';
        header('Location: ' . $x_redirect_uri); exit;
    }

    $payload = json_encode(array('query' => $query, 'depth' => $depth), JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init(POLYMARKET_API);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_POSTFIELDS     => $payload,
        ));
        $raw       = curl_exec($ch);
        $curl_err  = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $opts = array('http' => array('method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 600, 'ignore_errors' => true));
        $raw  = @file_get_contents(POLYMARKET_API, false, stream_context_create($opts));
        $curl_err  = '';
        $http_code = 200;
    }
    if (!$raw || $curl_err) {
        $_SESSION['pm_flash_error'] = 'Polymarket APIに接続できませんでした: ' . $curl_err;
        header('Location: ' . $x_redirect_uri); exit;
    }
    $res = json_decode($raw, true);
    if (!is_array($res) || empty($res['report'])) {
        $_SESSION['pm_flash_error'] = 'レポート生成に失敗しました (HTTP ' . $http_code . ')';
        header('Location: ' . $x_redirect_uri); exit;
    }
    pm_save($query, array(
        'query'           => $query,
        'depth'           => $depth,
        'report'          => $res['report'],
        'summary'         => isset($res['summary'])         ? $res['summary']         : '',
        'matched_markets' => isset($res['matched_markets']) ? $res['matched_markets'] : array(),
        'sources'         => isset($res['sources'])         ? $res['sources']         : array(),
        'created_at'      => date('Y-m-d H:i:s'),
    ));
    header('Location: ' . $x_redirect_uri . '?query=' . urlencode($query)); exit;
}

?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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
<title>Polymarket Intelligence — 予測市場リサーチ</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#6d28d9;--accent-h:#7c3aed;
    --green:#059669;--red:#dc2626;--amber:#d97706;
    --text:#0f172a;--muted:#64748b;
    --mono:'JetBrains Mono',monospace;--sans:'Inter',sans-serif;
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;font-size:14px}
header{background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.logo{font-size:1.1rem;font-weight:700;letter-spacing:-.02em}
.logo span{color:var(--accent)}
.logo-group{display:flex;align-items:center;gap:6px}
.u2a-badge{background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;letter-spacing:.03em}
.userbar{display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:var(--muted)}
.userbar strong{color:var(--green)}
.btn-sm{background:none;border:1px solid var(--border2);color:var(--muted);padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-sm:hover{border-color:var(--red);color:var(--red)}
.container{max-width:900px;margin:0 auto;padding:1.5rem}
.section{background:var(--surface);border:1px solid var(--border);border-radius:10px;margin-bottom:1rem;overflow:hidden}
.section-header{padding:.75rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.section-title{font-weight:600;font-size:.85rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.step{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700}
.section-body{padding:1rem}
.row{display:flex;gap:.6rem;align-items:flex-start}
input[type=text]{flex:1;border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.9rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text)}
input[type=text]:focus{border-color:var(--accent)}
select{border:1px solid var(--border2);border-radius:6px;padding:.55rem .6rem;font-size:.85rem;font-family:var(--sans);outline:none;background:var(--surface);color:var(--text);cursor:pointer}
select:focus{border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans);text-decoration:none}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn:disabled{opacity:.5;cursor:not-allowed}
.msg-error{color:var(--red);font-size:.8rem;margin-top:.4rem;padding:.4rem .6rem;background:#fef2f2;border-radius:4px;border:1px solid #fca5a5}
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}
.loading-msg{display:none;text-align:center;padding:12px 16px;font-size:.82rem;color:#4c1d95;background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;margin-bottom:1rem;font-weight:600}
.summary-box{background:#f5f3ff;border-left:3px solid var(--accent);border-radius:0 8px 8px 0;padding:12px 16px;font-size:.88rem;line-height:1.8;color:#2e1065;margin-bottom:1rem}
.report-body{font-size:.88rem;line-height:1.85;color:var(--text)}
.report-body h1{font-size:1.3rem;font-weight:700;margin:1.2rem 0 .6rem;color:#0f172a}
.report-body h2{font-size:1.05rem;font-weight:700;margin:1rem 0 .5rem;color:#0f172a;padding-bottom:.3rem;border-bottom:1px solid var(--border)}
.report-body h3{font-size:.95rem;font-weight:600;margin:.8rem 0 .4rem;color:#1e293b}
.report-body p{margin-bottom:.75rem}
.report-body ul,.report-body ol{margin:.5rem 0 .75rem 1.2rem}
.report-body li{margin-bottom:.3rem}
.report-body strong{color:#0f172a}
.report-body hr{border:none;border-top:1px solid var(--border);margin:1rem 0}
.report-body code{background:#f1f5f9;padding:.1rem .3rem;border-radius:3px;font-family:var(--mono);font-size:.8rem}
.sources-list{font-size:.78rem;line-height:1.8}
.sources-list a{color:var(--accent);text-decoration:none;word-break:break-all}
.sources-list a:hover{text-decoration:underline}
.meta-bar{font-size:.75rem;color:var(--muted);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.hint{font-size:.82rem;color:var(--muted);line-height:1.8;margin-top:.5rem}
.markets-table{width:100%;border-collapse:collapse;font-size:.8rem}
.markets-table th{text-align:left;padding:.4rem .6rem;border-bottom:2px solid var(--border);color:var(--muted);font-weight:600;font-size:.75rem;white-space:nowrap}
.markets-table td{padding:.5rem .6rem;border-bottom:1px solid var(--border);vertical-align:top}
.markets-table tr:last-child td{border-bottom:none}
.markets-table tr:hover td{background:#f8fafc}
.market-title{font-weight:600;color:var(--text);line-height:1.4}
.market-slug{font-family:var(--mono);font-size:.7rem;color:var(--muted);margin-top:2px}
.odds-bar{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px}
.odds-item{padding:2px 6px;border-radius:10px;font-size:.72rem;font-weight:600}
.odds-high{background:#dcfce7;color:#166534}
.odds-mid{background:#fef9c3;color:#854d0e}
.odds-low{background:#f1f5f9;color:#64748b}
.vol-badge{font-family:var(--mono);font-size:.75rem;color:var(--muted)}
.depth-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600;background:#ede9fe;color:#6d28d9}
@media (max-width:600px){
    .row{flex-wrap:wrap}
    .row input[type=text]{flex:1 1 100%}
    .container{padding:1rem}
    .section-body{padding:.75rem}
    .markets-table{font-size:.75rem}
}
</style>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo h(AIGM_GTAG_ID); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo h(AIGM_GTAG_ID); ?>');</script>
</head>
<body>
<header>
    <div class="logo-group"><div class="logo">Poly<span>Market</span> Intel</div><span class="u2a-badge">URL2AI</span></div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?pm_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?pm_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">

    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">1</span> 予測市場クエリを入力</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-gen">
                <input type="hidden" name="action" id="form-action" value="view_or_generate">
                <div class="row">
                    <input type="text" name="query" id="query-input"
                           placeholder="例: BTC price 2026, Will Trump win 2028, ETH $10k"
                           value="<?php echo h($query); ?>">
                    <select name="depth" id="depth-select">
                        <option value="shallow"<?php echo $depth === 'shallow' ? ' selected' : ''; ?>>Shallow（高速）</option>
                        <option value="medium"<?php echo ($depth === 'medium' || $depth === '') ? ' selected' : ''; ?>>Medium</option>
                        <option value="deep"<?php echo $depth === 'deep' ? ' selected' : ''; ?>>Deep（詳細）</option>
                    </select>
                    <button type="button" class="btn btn-primary" id="btn-gen"<?php if (!$is_admin): ?> disabled title="ログインが必要です"<?php endif; ?> onclick="submitGen()">
                        <span class="btn-label">🔮 レポート生成</span>
                        <span class="spinner"></span>
                    </button>
                    <button type="button" class="btn btn-secondary" id="btn-regen"<?php if (!$is_admin): ?> disabled title="ログインが必要です"<?php endif; ?> onclick="submitRegen()">
                        <span class="btn-label">🔄 再生成</span>
                        <span class="spinner"></span>
                    </button>
                </div>
                <?php if ($flash_error): ?>
                <div class="msg-error"><?php echo h($flash_error); ?></div>
                <?php endif; ?>
                <div class="hint">
                    自然言語でクエリを入力してください。Shallow: 市場データのみ（数秒）、Medium: AI要約付き、Deep: GPT Researcherによる詳細分析（数分）。
                </div>
            </form>
        </div>
    </div>

    <div id="loading-msg" class="loading-msg">
        ⏳ Polymarket APIを検索・分析中です。しばらくお待ちください...
    </div>

    <?php if ($saved): ?>

    <?php if (!empty($saved['matched_markets'])): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--accent)">📊</span> マッチした予測市場</div>
            <div class="meta-bar">
                <span class="depth-badge"><?php echo h(isset($saved['depth']) ? $saved['depth'] : 'medium'); ?></span>
                <span><?php echo h(isset($saved['created_at']) ? $saved['created_at'] : ''); ?></span>
            </div>
        </div>
        <div class="section-body" style="overflow-x:auto">
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
                <?php foreach ($saved['matched_markets'] as $mkt): ?>
                <tr>
                    <td>
                        <div class="market-title"><?php echo h(isset($mkt['title']) ? $mkt['title'] : ''); ?></div>
                        <?php if (!empty($mkt['slug'])): ?>
                        <div class="market-slug"><?php echo h($mkt['slug']); ?></div>
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
                    <td class="vol-badge"><?php echo h(isset($mkt['volume']) ? $mkt['volume'] : '-'); ?></td>
                    <td class="vol-badge"><?php echo h(isset($mkt['liquidity']) ? $mkt['liquidity'] : '-'); ?></td>
                    <td class="vol-badge"><?php echo h(isset($mkt['end_date']) ? $mkt['end_date'] : '-'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($saved['summary'])): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--green)">✓</span> AI要約</div>
            <div class="meta-bar">
                <span><?php echo h($saved['query']); ?></span>
                <button type="button" class="btn-sm" onclick="copyShare()">📋 コピー</button>
                <button type="button" class="btn-sm" onclick="copyReport()">Markdownコピー</button>
            </div>
        </div>
        <div class="section-body">
            <div class="summary-box"><?php echo h($saved['summary']); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($saved['report'])): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--accent)">📄</span> マーケット インテリジェンス レポート</div>
        </div>
        <div class="section-body">
            <div class="report-body" id="report-render"></div>
            <textarea id="report-raw" style="display:none"><?php echo h($saved['report']); ?></textarea>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($saved['sources'])): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title">🔗 参照ソース</div>
        </div>
        <div class="section-body">
            <ol class="sources-list">
                <?php foreach ($saved['sources'] as $src): ?>
                <li><a href="<?php echo h($src); ?>" target="_blank" rel="noopener"><?php echo h($src); ?></a></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<script>
function submitGen() {
    var q = document.getElementById('query-input').value.trim();
    if (!q) { return; }
    document.getElementById('form-action').value = 'view_or_generate';
    var btn = document.getElementById('btn-gen');
    var msg = document.getElementById('loading-msg');
    if (btn) { btn.disabled = true; btn.classList.add('loading'); }
    if (msg) { msg.style.display = 'block'; }
    document.getElementById('form-gen').submit();
}
function submitRegen() {
    var q = document.getElementById('query-input').value.trim();
    if (!q) { return; }
    document.getElementById('form-action').value = 'generate';
    var btn = document.getElementById('btn-regen');
    var msg = document.getElementById('loading-msg');
    if (btn) { btn.disabled = true; btn.classList.add('loading'); }
    if (msg) { msg.style.display = 'block'; }
    document.getElementById('form-gen').submit();
}
document.getElementById('query-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') submitGen();
});

<?php if ($saved && !empty($saved['report'])): ?>
var raw = document.getElementById('report-raw').value;
document.getElementById('report-render').innerHTML = marked.parse(raw);
<?php endif; ?>

<?php if ($saved): ?>
function copyShare() {
    var summary = <?php echo json_encode(isset($saved['summary']) ? $saved['summary'] : '', JSON_UNESCAPED_UNICODE); ?>;
    var detailUrl = <?php echo json_encode($BASE_URL . '/polymarket.php?query=' . urlencode($saved['query']), JSON_UNESCAPED_UNICODE); ?>;
    var query = <?php echo json_encode(isset($saved['query']) ? $saved['query'] : '', JSON_UNESCAPED_UNICODE); ?>;
    var text = '#URL2AI Polymarket Intel: ' + query + '\n\n' + summary + '\n\n' + detailUrl;
    navigator.clipboard.writeText(text).then(function() { alert('コピーしました'); });
}
<?php endif; ?>
function copyReport() {
    var raw = document.getElementById('report-raw');
    if (!raw) return;
    navigator.clipboard.writeText(raw.value).then(function() { alert('Markdownをコピーしました'); });
}
</script>
</body>
</html>
