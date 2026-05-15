<?php
require_once __DIR__ . '/config.php';

function url2ai_auth_start_session() {
    if (session_status() !== PHP_SESSION_NONE) { return; }
    $session_lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', AIGM_COOKIE_DOMAIN);
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), time() + $session_lifetime, '/', AIGM_COOKIE_DOMAIN, true, true);
    }
}

function url2ai_auth_current_path() {
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    return preg_match('#^/[^\r\n]*$#', $uri) ? $uri : '/knowradar.php';
}

function url2ai_auth_safe_return($return) {
    $return = trim((string)$return);
    if ($return === '') { return '/knowradar.php'; }
    if (preg_match('#^https?://#i', $return)) {
        $host = parse_url($return, PHP_URL_HOST);
        $base_host = parse_url(AIGM_BASE_URL, PHP_URL_HOST);
        if ($host === $base_host) {
            $path = parse_url($return, PHP_URL_PATH);
            $query = parse_url($return, PHP_URL_QUERY);
            return ($path ? $path : '/') . ($query ? '?' . $query : '');
        }
        return '/knowradar.php';
    }
    if (strpos($return, '/') === 0 && strpos($return, '//') !== 0) { return $return; }
    return '/knowradar.php';
}

function url2ai_auth_login_url($return = '') {
    $return = $return !== '' ? $return : url2ai_auth_current_path();
    return AIGM_BASE_URL . '/knowradar.php?kr_login=1&return=' . urlencode(url2ai_auth_safe_return($return));
}

function url2ai_auth_logout_url($return = '') {
    $return = $return !== '' ? $return : url2ai_auth_current_path();
    return AIGM_BASE_URL . '/knowradar.php?kr_logout=1&return=' . urlencode(url2ai_auth_safe_return($return));
}

function url2ai_auth_load_x_keys() {
    $keys = array();
    $file = __DIR__ . '/x_api_keys.sh';
    if (!file_exists($file)) { return $keys; }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m)) {
            $keys[trim($m[1])] = trim($m[2]);
        }
    }
    return $keys;
}

function url2ai_auth_post_form($url, $data, $headers) {
    $opts = array('http' => array('method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n", 'content' => $data, 'timeout' => 12, 'ignore_errors' => true));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return json_decode($res ? $res : '{}', true);
}

function url2ai_auth_refresh_if_needed() {
    if (empty($_SESSION['session_refresh_token']) || empty($_SESSION['session_token_expires']) || time() <= $_SESSION['session_token_expires'] - 300) {
        return;
    }
    $keys = url2ai_auth_load_x_keys();
    $client_id = isset($keys['X_API_KEY']) ? $keys['X_API_KEY'] : '';
    $client_secret = isset($keys['X_API_SECRET']) ? $keys['X_API_SECRET'] : '';
    if ($client_id === '' || $client_secret === '') { return; }
    $post = http_build_query(array('grant_type' => 'refresh_token', 'refresh_token' => $_SESSION['session_refresh_token'], 'client_id' => $client_id));
    $cred = base64_encode($client_id . ':' . $client_secret);
    $ref = url2ai_auth_post_form('https://api.twitter.com/2/oauth2/token', $post, array('Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . $cred));
    if (!empty($ref['access_token'])) {
        $_SESSION['session_access_token'] = $ref['access_token'];
        $_SESSION['session_token_expires'] = time() + (isset($ref['expires_in']) ? (int)$ref['expires_in'] : 7200);
        if (!empty($ref['refresh_token'])) { $_SESSION['session_refresh_token'] = $ref['refresh_token']; }
    } else {
        unset($_SESSION['session_access_token'], $_SESSION['session_refresh_token'], $_SESSION['session_token_expires'], $_SESSION['session_username']);
    }
}

function url2ai_auth_bootstrap() {
    url2ai_auth_start_session();
    url2ai_auth_refresh_if_needed();
    $session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
    $logged_in = !empty($_SESSION['session_access_token']) && $session_user !== '';
    return array(
        'logged_in' => $logged_in,
        'session_user' => $session_user,
        'is_admin' => ($session_user === AIGM_ADMIN),
        'login_url' => url2ai_auth_login_url(),
        'logout_url' => url2ai_auth_logout_url(),
    );
}
