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
$THIS_FILE = 'finreport.php';
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
$x_redirect_uri  = 'https://aiknowledgecms.exbridge.jp/finreport.php';

function fr_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function fr_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return fr_base64url($bytes);
}
function fr_gen_challenge($verifier) {
    return fr_base64url(hash('sha256', $verifier, true));
}
function fr_x_post($url, $post_data, $headers) {
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
function fr_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: FinReport/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}
if (isset($_GET['fr_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/',
        'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['fr_login'])) {
    $verifier  = fr_gen_verifier();
    $challenge = fr_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['fr_code_verifier'] = $verifier;
    $_SESSION['fr_oauth_state']   = $state;
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
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['fr_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['fr_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['fr_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = fr_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']  = $data['access_token'];
            $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['fr_oauth_state'], $_SESSION['fr_code_verifier']);
            $me = fr_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
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
    $ref = fr_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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
$is_admin  = ($username === 'xb_bittensor');

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function fr_slug($ticker) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($ticker)));
}
function fr_data_file($ticker) {
    global $DATA_DIR;
    return $DATA_DIR . '/finreport_' . fr_slug($ticker) . '_' . date('Ymd') . '.json';
}
function fr_load($ticker) {
    global $DATA_DIR;
    $slug  = fr_slug($ticker);
    $files = glob($DATA_DIR . '/finreport_' . $slug . '_*.json');
    if (!$files) $files = array();
    $old = $DATA_DIR . '/finreport_' . $slug . '.json';
    if (file_exists($old)) $files[] = $old;
    if (empty($files)) return null;
    rsort($files);
    foreach ($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!is_array($d) || empty($d['report'])) continue;
        // Guard against slug collisions (Japanese chars all become _)
        if (isset($d['ticker']) && strcasecmp(trim($d['ticker']), trim($ticker)) !== 0) continue;
        return $d;
    }
    return null;
}
function fr_save($ticker, $data) {
    global $DATA_DIR;
    if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);
    file_put_contents(fr_data_file($ticker), json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function fr_find_latest_file($ticker) {
    global $DATA_DIR;
    $slug  = fr_slug($ticker);
    $files = glob($DATA_DIR . '/finreport_' . $slug . '_*.json');
    if (!$files) $files = array();
    $old = $DATA_DIR . '/finreport_' . $slug . '.json';
    if (file_exists($old)) $files[] = $old;
    if (empty($files)) return null;
    rsort($files);
    foreach ($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!is_array($d)) continue;
        if (isset($d['ticker']) && strcasecmp(trim($d['ticker']), trim($ticker)) !== 0) continue;
        return $f;
    }
    return null;
}

function fr_update_latest($ticker, $updates) {
    $path = fr_find_latest_file($ticker);
    if (!$path || !is_array($updates)) return false;
    $data = fr_read_json_file($path);
    if (!$data) return false;
    foreach ($updates as $key => $value) {
        $data[$key] = $value;
    }
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    return $data;
}

function fr_paragraph_api_get_json($url, $headers = array()) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'timeout'       => 30,
        'ignore_errors' => true,
    ));
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function fr_paragraph_resolve_post_url_from_publication($paragraph_post_id) {
    $publication_slug = trim((string) PARAGRAPH_PUBLICATION_SLUG);
    $paragraph_post_id = trim((string) $paragraph_post_id);
    if ($publication_slug === '' || $paragraph_post_id === '') return '';

    $publication = fr_paragraph_api_get_json(
        'https://public.api.paragraph.com/api/v1/publications/slug/' . rawurlencode($publication_slug)
    );
    if (!is_array($publication) || empty($publication['id'])) return '';
    $publication_id = (string) $publication['id'];
    $publication_slug_clean = isset($publication['slug']) ? ltrim((string)$publication['slug'], '@') : ltrim($publication_slug, '@');
    $custom_domain = isset($publication['customDomain']) ? trim((string)$publication['customDomain']) : '';
    $base_url = $custom_domain !== '' ? rtrim($custom_domain, '/') : ('https://paragraph.com/@' . $publication_slug_clean);
    if ($publication_id === '') return '';

    $posts_data = fr_paragraph_api_get_json(
        'https://public.api.paragraph.com/api/v1/publications/' . rawurlencode($publication_id) . '/posts?limit=100'
    );
    if (!is_array($posts_data) || empty($posts_data['items']) || !is_array($posts_data['items'])) return '';

    foreach ($posts_data['items'] as $post) {
        $post_id = isset($post['id']) ? (string)$post['id'] : '';
        $post_slug = isset($post['slug']) ? trim((string)$post['slug']) : '';
        if ($post_id === $paragraph_post_id && $post_slug !== '') {
            return $base_url . '/' . ltrim($post_slug, '/');
        }
    }
    return '';
}

function fr_is_valid_paragraph_url($url) {
    $url = trim((string) $url);
    if ($url === '') return false;
    if (strpos($url, 'aiknowledgecms.exbridge.jp') !== false) return false;
    return (strpos($url, 'paragraph.com') !== false || strpos($url, 'paragraph.xyz') !== false);
}

function fr_json_response($data, $status_code) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function fr_read_json_file($path) {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['report'])) return null;
    return $data;
}

function fr_created_ts($item) {
    if (!empty($item['created_at'])) {
        $ts = strtotime($item['created_at']);
        if ($ts !== false) return $ts;
    }
    return time();
}

function fr_load_all_reports($with_report, $limit, $since_ts) {
    global $DATA_DIR, $BASE_URL;
    $files = glob($DATA_DIR . '/finreport_*.json');
    if (!$files) $files = array();

    $items = array();
    foreach ($files as $path) {
        $data = fr_read_json_file($path);
        if (!$data) continue;

        $created_ts = fr_created_ts($data);
        if ($since_ts > 0 && $created_ts <= $since_ts) continue;

        $ticker = isset($data['ticker']) ? trim($data['ticker']) : '';
        if ($ticker === '') continue;

        $item = array(
            'id'              => fr_slug($ticker) . '-' . date('YmdHis', $created_ts),
            'ticker'          => $ticker,
            'company_name'    => isset($data['company_name'])    ? $data['company_name']    : '',
            'resolved_symbol' => isset($data['resolved_symbol']) ? $data['resolved_symbol'] : '',
            'slug'            => fr_slug($ticker),
            'summary'         => isset($data['summary']) ? $data['summary'] : '',
            'sources'         => isset($data['sources']) && is_array($data['sources']) ? $data['sources'] : array(),
            'created_at'      => date('c', $created_ts),
            'created_ts'      => $created_ts,
            'detail_url'      => $BASE_URL . '/finreportv.php?ticker=' . urlencode($ticker),
            'paragraph_url'   => isset($data['paragraph_url']) ? $data['paragraph_url'] : '',
            'paragraph_post_id'   => isset($data['paragraph_post_id']) ? $data['paragraph_post_id'] : '',
            'paragraph_posted_at' => isset($data['paragraph_posted_at']) ? $data['paragraph_posted_at'] : '',
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

$flash_error = isset($_SESSION['fr_flash_error']) ? $_SESSION['fr_flash_error'] : '';
if (isset($_SESSION['fr_flash_error'])) unset($_SESSION['fr_flash_error']);

if (isset($_GET['api']) && $_GET['api'] !== '') {
    $api = trim($_GET['api']);
    if ($api === 'recent') {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        if ($limit < 1) $limit = 20;
        if ($limit > 100) $limit = 100;
        $with_report = !empty($_GET['with_report']) && $_GET['with_report'] !== '0';
        $since_ts = isset($_GET['since']) ? (int) $_GET['since'] : 0;
        $items = fr_load_all_reports($with_report, $limit, $since_ts);
        fr_json_response(array(
            'ok'          => true,
            'count'       => count($items),
            'generated_at'=> date('c'),
            'items'       => $items,
        ), 200);
    }

    if ($api === 'detail') {
        $ticker = isset($_GET['ticker']) ? trim($_GET['ticker']) : '';
        if ($ticker === '') {
            fr_json_response(array('ok' => false, 'error' => 'ticker is required'), 400);
        }
        $saved = fr_load($ticker);
        if (!$saved) {
            fr_json_response(array('ok' => false, 'error' => 'report not found'), 404);
        }
        $created_ts = fr_created_ts($saved);
        fr_json_response(array(
            'ok' => true,
            'item' => array(
                'id'         => fr_slug($ticker) . '-' . date('YmdHis', $created_ts),
                'ticker'     => $saved['ticker'],
                'slug'       => fr_slug($saved['ticker']),
                'summary'    => isset($saved['summary']) ? $saved['summary'] : '',
                'report'     => isset($saved['report']) ? $saved['report'] : '',
                'sources'    => isset($saved['sources']) && is_array($saved['sources']) ? $saved['sources'] : array(),
                'created_at' => date('c', $created_ts),
                'created_ts' => $created_ts,
                'detail_url' => $BASE_URL . '/finreportv.php?ticker=' . urlencode($saved['ticker']),
                'paragraph_url' => isset($saved['paragraph_url']) ? $saved['paragraph_url'] : '',
                'paragraph_post_id' => isset($saved['paragraph_post_id']) ? $saved['paragraph_post_id'] : '',
                'paragraph_posted_at' => isset($saved['paragraph_posted_at']) ? $saved['paragraph_posted_at'] : '',
            ),
        ), 200);
    }

    if ($api === 'mark_paragraph') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            fr_json_response(array('ok' => false, 'error' => 'POST required'), 405);
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            fr_json_response(array('ok' => false, 'error' => 'invalid json'), 400);
        }
        $ticker = isset($body['ticker']) ? trim($body['ticker']) : '';
        $paragraph_url = isset($body['paragraph_url']) ? trim($body['paragraph_url']) : '';
        $paragraph_post_id = isset($body['paragraph_post_id']) ? trim((string)$body['paragraph_post_id']) : '';
        if ($ticker === '' || ($paragraph_url === '' && $paragraph_post_id === '')) {
            fr_json_response(array('ok' => false, 'error' => 'ticker and paragraph_url or paragraph_post_id are required'), 400);
        }
        $updated = fr_update_latest($ticker, array(
            'paragraph_url' => $paragraph_url,
            'paragraph_post_id' => $paragraph_post_id,
            'paragraph_posted_at' => date('c'),
        ));
        if (!$updated) {
            fr_json_response(array('ok' => false, 'error' => 'report not found'), 404);
        }
        fr_json_response(array(
            'ok' => true,
            'ticker' => $updated['ticker'],
            'paragraph_url' => $updated['paragraph_url'],
            'paragraph_post_id' => isset($updated['paragraph_post_id']) ? $updated['paragraph_post_id'] : '',
            'paragraph_posted_at' => isset($updated['paragraph_posted_at']) ? $updated['paragraph_posted_at'] : '',
        ), 200);
    }

    if ($api === 'paragraph_resolve') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            fr_json_response(array('ok' => false, 'error' => 'POST required'), 405);
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            fr_json_response(array('ok' => false, 'error' => 'invalid json'), 400);
        }
        $ticker = isset($body['ticker']) ? trim($body['ticker']) : '';
        if ($ticker === '') {
            fr_json_response(array('ok' => false, 'error' => 'ticker is required'), 400);
        }
        $saved = fr_load($ticker);
        if (!$saved) {
            fr_json_response(array('ok' => false, 'error' => 'report not found'), 404);
        }
        $paragraph_url = isset($saved['paragraph_url']) ? trim((string) $saved['paragraph_url']) : '';
        $paragraph_post_id = isset($saved['paragraph_post_id']) ? trim((string) $saved['paragraph_post_id']) : '';
        if (!fr_is_valid_paragraph_url($paragraph_url) && $paragraph_post_id !== '') {
            $paragraph_url = fr_paragraph_resolve_post_url_from_publication($paragraph_post_id);
            if ($paragraph_url !== '') {
                fr_update_latest($ticker, array(
                    'paragraph_url' => $paragraph_url,
                    'paragraph_post_id' => $paragraph_post_id,
                    'paragraph_posted_at' => !empty($saved['paragraph_posted_at']) ? $saved['paragraph_posted_at'] : date('c'),
                ));
            }
        }
        fr_json_response(array(
            'ok' => true,
            'paragraph_url' => fr_is_valid_paragraph_url($paragraph_url) ? $paragraph_url : '',
            'paragraph_post_id' => $paragraph_post_id,
        ), 200);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            fr_json_response(array('ok' => false, 'error' => 'POST required'), 405);
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            fr_json_response(array('ok' => false, 'error' => 'invalid json'), 400);
        }
        $ticker = isset($body['ticker']) ? trim($body['ticker']) : '';
        $report = isset($body['report']) ? trim($body['report']) : '';
        if ($ticker === '' || $report === '') {
            fr_json_response(array('ok' => false, 'error' => 'ticker and report are required'), 400);
        }
        $created_at = isset($body['created_at']) && trim($body['created_at']) !== ''
            ? trim($body['created_at'])
            : date('Y-m-d H:i:s');
        $save_data = array(
            'ticker'          => $ticker,
            'company_name'    => isset($body['company_name'])    ? trim((string) $body['company_name'])    : '',
            'resolved_symbol' => isset($body['resolved_symbol']) ? trim((string) $body['resolved_symbol']) : '',
            'report'          => $report,
            'summary'         => isset($body['summary']) ? $body['summary'] : '',
            'sources'         => isset($body['sources']) && is_array($body['sources']) ? $body['sources'] : array(),
            'created_at'      => $created_at,
            'news_kind'       => isset($body['news_kind']) ? $body['news_kind'] : '',
            'news_title'      => isset($body['news_title']) ? $body['news_title'] : '',
            'news_link'       => isset($body['news_link']) ? $body['news_link'] : '',
            'news_summary'    => isset($body['news_summary']) ? $body['news_summary'] : '',
            'paragraph_url'   => isset($body['paragraph_url']) ? trim((string) $body['paragraph_url']) : '',
            'paragraph_post_id' => isset($body['paragraph_post_id']) ? trim((string) $body['paragraph_post_id']) : '',
            'paragraph_posted_at' => isset($body['paragraph_posted_at']) ? trim((string) $body['paragraph_posted_at']) : '',
        );
        fr_save($ticker, $save_data);
        $saved = fr_load($ticker);
        $created_ts = $saved ? fr_created_ts($saved) : time();
        fr_json_response(array(
            'ok' => true,
            'item' => array(
                'id'         => fr_slug($ticker) . '-' . date('YmdHis', $created_ts),
                'ticker'     => $ticker,
                'slug'       => fr_slug($ticker),
                'summary'    => isset($save_data['summary']) ? $save_data['summary'] : '',
                'report'     => $report,
                'sources'    => isset($save_data['sources']) ? $save_data['sources'] : array(),
                'created_at' => date('c', $created_ts),
                'created_ts' => $created_ts,
                'detail_url' => $BASE_URL . '/finreportv.php?ticker=' . urlencode($ticker),
                'paragraph_url' => isset($saved['paragraph_url']) ? $saved['paragraph_url'] : '',
                'paragraph_post_id' => isset($saved['paragraph_post_id']) ? $saved['paragraph_post_id'] : '',
                'paragraph_posted_at' => isset($saved['paragraph_posted_at']) ? $saved['paragraph_posted_at'] : '',
            ),
        ), 200);
    }

    if ($api === 'paragraph_post') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            fr_json_response(array('ok' => false, 'error' => 'POST required'), 405);
        }
        $session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
        if ($session_user !== $ADMIN) {
            fr_json_response(array('ok' => false, 'error' => 'unauthorized'), 403);
        }
        if (!PARAGRAPH_API_KEY) {
            fr_json_response(array('ok' => false, 'error' => 'PARAGRAPH_API_KEY not configured'), 500);
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            fr_json_response(array('ok' => false, 'error' => 'invalid json'), 400);
        }
        $ticker = isset($body['ticker']) ? trim($body['ticker']) : '';
        if ($ticker === '') {
            fr_json_response(array('ok' => false, 'error' => 'ticker is required'), 400);
        }
        $saved = fr_load($ticker);
        if (!$saved || empty($saved['report'])) {
            fr_json_response(array('ok' => false, 'error' => 'report not found'), 404);
        }

        $existing_url = isset($saved['paragraph_url']) ? trim((string) $saved['paragraph_url']) : '';
        $existing_id  = isset($saved['paragraph_post_id']) ? trim((string) $saved['paragraph_post_id']) : '';
        if (fr_is_valid_paragraph_url($existing_url) || $existing_id !== '') {
            fr_json_response(array(
                'ok' => true,
                'paragraph_url' => fr_is_valid_paragraph_url($existing_url) ? $existing_url : '',
                'paragraph_post_id' => $existing_id,
            ), 200);
        }

        $title = $ticker . ' Investment Report';
        $summary = isset($saved['summary']) ? trim((string) $saved['summary']) : '';
        $detail_url = $BASE_URL . '/finreportv.php?ticker=' . urlencode($ticker);
        $markdown = "# " . $title . "\n\n";
        if ($summary !== '') {
            $markdown .= "> " . str_replace("\n", "\n> ", $summary) . "\n\n";
        }
        $markdown .= trim((string) $saved['report']) . "\n\n---\n";
        $markdown .= "Source:\n- FinReport demo: " . $detail_url . "\n";
        $markdown .= "Bankr / URL2AI:\n- Discover URL2AI on Bankr: https://bankr.bot/discover/0xDaecDda6AD112f0E1E4097fB735dD01D9C33cBA3\n";

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
        $res = @file_get_contents('https://public.api.paragraph.com/api/v1/posts', false, stream_context_create($opts));
        $res_arr = $res ? json_decode($res, true) : array();
        $para_url = isset($res_arr['url']) ? $res_arr['url'] : (isset($res_arr['canonicalUrl']) ? $res_arr['canonicalUrl'] : '');
        $para_post_id = isset($res_arr['id']) ? (string)$res_arr['id'] : (isset($res_arr['postId']) ? (string)$res_arr['postId'] : '');
        if (!$para_url && $para_post_id) {
            $para_url = fr_paragraph_resolve_post_url_from_publication($para_post_id);
        }
        if (!$para_url && !$para_post_id) {
            fr_json_response(array('ok' => false, 'error' => 'Paragraph API failed', 'detail' => $res_arr), 500);
        }

        $updated = fr_update_latest($ticker, array(
            'paragraph_url' => $para_url,
            'paragraph_post_id' => $para_post_id,
            'paragraph_posted_at' => date('c'),
        ));
        if (!$updated) {
            fr_json_response(array('ok' => false, 'error' => 'report update failed'), 500);
        }
        fr_json_response(array(
            'ok' => true,
            'paragraph_url' => $para_url,
            'paragraph_post_id' => $para_post_id,
            'paragraph_posted_at' => isset($updated['paragraph_posted_at']) ? $updated['paragraph_posted_at'] : '',
        ), 200);
    }

    fr_json_response(array('ok' => false, 'error' => 'unknown api'), 404);
}

$ticker      = '';
$saved       = null;
$action      = isset($_POST['action']) ? $_POST['action'] : '';

/* GET ?ticker=BTC */
if (isset($_GET['ticker']) && $_GET['ticker'] !== '') {
    $ticker = trim($_GET['ticker']);
    $saved  = fr_load($ticker);
}

/* POST レポート生成（あれば表示、なければ生成） */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'view_or_generate') {
    $ticker = isset($_POST['ticker']) ? trim($_POST['ticker']) : '';
    if ($ticker === '') { header('Location: ' . $x_redirect_uri); exit; }
    if (fr_load($ticker)) {
        header('Location: ' . $x_redirect_uri . '?ticker=' . urlencode($ticker)); exit;
    }
    if (!$is_admin) {
        header('Location: ' . $x_redirect_uri . '?ticker=' . urlencode($ticker)); exit;
    }
    // なければ生成へ fall through
    $_POST['action'] = 'generate';
    $action = 'generate';
}

/* POST 再生成 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && $action === 'generate') {
    $ticker = isset($_POST['ticker']) ? trim($_POST['ticker']) : '';
    if ($ticker === '') {
        $_SESSION['fr_flash_error'] = 'ティッカー・コイン名を入力してください';
        header('Location: ' . $x_redirect_uri); exit;
    }

    $payload = json_encode(array('ticker' => $ticker), JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init(FINREPORT_API);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_POSTFIELDS     => $payload,
        ));
        $raw      = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $opts = array('http' => array('method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$payload,'timeout'=>600,'ignore_errors'=>true));
        $raw  = @file_get_contents(FINREPORT_API, false, stream_context_create($opts));
        $curl_err  = '';
        $http_code = 200;
    }

    if (!$raw || $curl_err) {
        $_SESSION['fr_flash_error'] = 'FinReport APIに接続できませんでした: ' . $curl_err;
        header('Location: ' . $x_redirect_uri); exit;
    }
    $res = json_decode($raw, true);
    if (!is_array($res) || empty($res['report'])) {
        $_SESSION['fr_flash_error'] = 'レポート生成に失敗しました (HTTP ' . $http_code . ')';
        header('Location: ' . $x_redirect_uri); exit;
    }

    $save_data = array(
        'ticker'          => $ticker,
        'company_name'    => isset($res['company_name'])    ? trim($res['company_name'])    : '',
        'resolved_symbol' => isset($res['resolved_symbol']) ? trim($res['resolved_symbol']) : '',
        'report'          => $res['report'],
        'summary'         => isset($res['summary'])  ? $res['summary']  : '',
        'sources'         => isset($res['sources'])  ? $res['sources']  : array(),
        'created_at'      => date('Y-m-d H:i:s'),
    );
    fr_save($ticker, $save_data);
    header('Location: ' . $x_redirect_uri . '?ticker=' . urlencode($ticker)); exit;
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
<title>FinReport — 金融投資レポート</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#0f766e;--accent-h:#0d9488;
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
.loading-msg{display:none;text-align:center;padding:12px 16px;font-size:.82rem;color:#065f46;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;margin-bottom:1rem;font-weight:600}
.summary-box{background:#f0fdfa;border-left:3px solid var(--accent);border-radius:0 8px 8px 0;padding:12px 16px;font-size:.88rem;line-height:1.8;color:#134e4a;margin-bottom:1rem}
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
@media (max-width:600px){
    .row{flex-wrap:wrap}
    .row input[type=text]{flex:1 1 100%}
    .container{padding:1rem}
    .section-body{padding:.75rem}
}
</style>
<!-- Google tag -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo h(AIGM_GTAG_ID); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo h(AIGM_GTAG_ID); ?>');</script>
</head>
<body>
<header>
    <div class="logo-group"><div class="logo">Fin<span>Report</span></div><span class="u2a-badge">URL2AI</span></div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?fr_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?fr_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">

    <!-- STEP 1: 入力 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">1</span> 会社名・コイン名・ティッカー・証券コードを入力</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-gen">
                <input type="hidden" name="action" id="form-action" value="view_or_generate">
                <div class="row">
                    <input type="text" name="ticker" id="ticker-input"
                           placeholder="例: BTC, NVIDIA, Apple, トヨタ, 7203.T"
                           value="<?php echo h($ticker); ?>">
                    <button type="button" class="btn btn-primary" id="btn-gen"<?php if (!$is_admin): ?> disabled title="ログインが必要です"<?php endif; ?> onclick="submitGen()">
                        <span class="btn-label">📊 レポート生成</span>
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
                    「レポート表示」は保存済みレポートを表示します。「再生成」はWeb検索＋AI分析で最新レポートを生成します（2〜5分）。
                </div>
            </form>
        </div>
    </div>

    <div id="loading-msg" class="loading-msg">
        ⏳ Web検索とAI分析を実行中です。2〜5分かかります。ページを閉じないでください...
    </div>

    <?php if ($saved): ?>

    <!-- サマリー -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--green)">✓</span> AI要約</div>
            <div class="meta-bar">
                <?php
                $cn = isset($saved['company_name']) && $saved['company_name'] !== '' ? $saved['company_name'] : '';
                $rs = isset($saved['resolved_symbol']) && $saved['resolved_symbol'] !== '' ? $saved['resolved_symbol'] : $saved['ticker'];
                if ($cn !== '' && $cn !== $rs): ?>
                <strong><?php echo h($cn); ?></strong> <span style="color:var(--muted)"><?php echo h($rs); ?></span>
                <?php else: ?>
                <span><?php echo h($saved['ticker']); ?></span>
                <?php endif; ?>
                <span><?php echo h(isset($saved['created_at']) ? $saved['created_at'] : ''); ?></span>
                <button type="button" class="btn-sm" onclick="copyShare()">📋 コピー</button>
                <button type="button" class="btn-sm" onclick="copyReport()">Markdownコピー</button>
            </div>
        </div>
        <div class="section-body">
            <div class="summary-box"><?php echo h(isset($saved['summary']) ? $saved['summary'] : ''); ?></div>
        </div>
    </div>

    <!-- レポート本文 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--accent)">📄</span> 投資レポート</div>
        </div>
        <div class="section-body">
            <div class="report-body" id="report-render"></div>
            <textarea id="report-raw" style="display:none"><?php echo h(isset($saved['report']) ? $saved['report'] : ''); ?></textarea>
        </div>
    </div>

    <!-- ソース -->
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
    var ticker = document.getElementById('ticker-input').value.trim();
    if (!ticker) { return; }
    document.getElementById('form-action').value = 'view_or_generate';
    var btn = document.getElementById('btn-gen');
    var msg = document.getElementById('loading-msg');
    if (btn) { btn.disabled = true; btn.classList.add('loading'); }
    if (msg) { msg.style.display = 'block'; }
    document.getElementById('form-gen').submit();
}
function submitRegen() {
    var ticker = document.getElementById('ticker-input').value.trim();
    if (!ticker) { return; }
    document.getElementById('form-action').value = 'generate';
    var btn = document.getElementById('btn-regen');
    var msg = document.getElementById('loading-msg');
    if (btn) { btn.disabled = true; btn.classList.add('loading'); }
    if (msg) { msg.style.display = 'block'; }
    document.getElementById('form-gen').submit();
}
document.getElementById('ticker-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') submitGen();
});

<?php if ($saved && !empty($saved['report'])): ?>
var raw = document.getElementById('report-raw').value;
document.getElementById('report-render').innerHTML = marked.parse(raw);
<?php endif; ?>

<?php if ($saved): ?>
function copyShare() {
    var summary = <?php echo json_encode(isset($saved['summary']) ? $saved['summary'] : '', JSON_UNESCAPED_UNICODE); ?>;
    var detailUrl = <?php echo json_encode($BASE_URL . '/finreportv.php?ticker=' . urlencode($saved['ticker']), JSON_UNESCAPED_UNICODE); ?>;
    var ticker = <?php echo json_encode(isset($saved['ticker']) ? $saved['ticker'] : '', JSON_UNESCAPED_UNICODE); ?>;
    var text = '#URL2AI ' + ticker + ' 投資レポート\n\n' + summary + '\n\n' + detailUrl;
    navigator.clipboard.writeText(text).then(function() {
        alert('コピーしました');
    });
}
<?php endif; ?>
function copyReport() {
    var raw = document.getElementById('report-raw');
    if (!raw) return;
    navigator.clipboard.writeText(raw.value).then(function() {
        alert('Markdownをコピーしました');
    });
}
</script>
</body>
</html>
