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
    return preg_match('#^/[^\r\n]*$#', $uri) ? $uri : '/aiknowledgesns.php';
}

function url2ai_auth_safe_return($return) {
    $return = trim((string)$return);
    if ($return === '') { return '/aiknowledgesns.php'; }
    if (preg_match('#^https?://#i', $return)) {
        $host = parse_url($return, PHP_URL_HOST);
        $base_host = parse_url(AIGM_BASE_URL, PHP_URL_HOST);
        if ($host === $base_host) {
            $path = parse_url($return, PHP_URL_PATH);
            $query = parse_url($return, PHP_URL_QUERY);
            return ($path ? $path : '/') . ($query ? '?' . $query : '');
        }
        return '/aiknowledgesns.php';
    }
    if (strpos($return, '/') === 0 && strpos($return, '//') !== 0) { return $return; }
    return '/aiknowledgesns.php';
}

function url2ai_auth_login_url($return = '') {
    $return = $return !== '' ? $return : url2ai_auth_current_path();
    return AIGM_BASE_URL . '/aiknowledgesns.php?aks_login=1&return=' . urlencode(url2ai_auth_safe_return($return));
}

function url2ai_auth_logout_url($return = '') {
    $return = $return !== '' ? $return : url2ai_auth_current_path();
    return AIGM_BASE_URL . '/aiknowledgesns.php?aks_logout=1&return=' . urlencode(url2ai_auth_safe_return($return));
}

function url2ai_auth_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function url2ai_auth_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return url2ai_auth_base64url($bytes);
}

function url2ai_auth_gen_challenge($verifier) {
    return url2ai_auth_base64url(hash('sha256', $verifier, true));
}

function url2ai_auth_get_json($url, $token) {
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => "Authorization: Bearer $token\r\nUser-Agent: AIKnowledgeSNS/1.0\r\n",
        'timeout' => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return json_decode($res ? $res : '{}', true);
}

function url2ai_auth_handle_login_flow($return_default = '/aiknowledgesns.php') {
    url2ai_auth_start_session();
    if (isset($_GET['aks_logout'])) {
        $return_to = isset($_GET['return']) ? url2ai_auth_safe_return($_GET['return']) : $return_default;
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/', AIGM_COOKIE_DOMAIN, true, true);
        header('Location: ' . AIGM_BASE_URL . $return_to);
        exit;
    }
    if (isset($_GET['aks_login'])) {
        $keys = url2ai_auth_load_x_keys();
        $client_id = isset($keys['X_API_KEY']) ? $keys['X_API_KEY'] : '';
        $verifier = url2ai_auth_gen_verifier();
        $challenge = url2ai_auth_gen_challenge($verifier);
        $state = md5(uniqid('', true));
        $_SESSION['aks_code_verifier'] = $verifier;
        $_SESSION['aks_oauth_state'] = $state;
        $_SESSION['aks_return_to'] = isset($_GET['return']) ? url2ai_auth_safe_return($_GET['return']) : url2ai_auth_safe_return($return_default);
        $params = array(
            'response_type' => 'code',
            'client_id' => $client_id,
            'redirect_uri' => AIGM_BASE_URL . '/aiknowledgesns.php',
            'scope' => 'tweet.read users.read offline.access',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        );
        header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($params));
        exit;
    }
    if (isset($_GET['code'], $_GET['state'], $_SESSION['aks_oauth_state'])) {
        if ($_GET['state'] === $_SESSION['aks_oauth_state']) {
            $keys = url2ai_auth_load_x_keys();
            $client_id = isset($keys['X_API_KEY']) ? $keys['X_API_KEY'] : '';
            $client_secret = isset($keys['X_API_SECRET']) ? $keys['X_API_SECRET'] : '';
            $post = http_build_query(array(
                'grant_type' => 'authorization_code',
                'code' => $_GET['code'],
                'redirect_uri' => AIGM_BASE_URL . '/aiknowledgesns.php',
                'code_verifier' => isset($_SESSION['aks_code_verifier']) ? $_SESSION['aks_code_verifier'] : '',
                'client_id' => $client_id,
            ));
            $cred = base64_encode($client_id . ':' . $client_secret);
            $data = url2ai_auth_post_form('https://api.twitter.com/2/oauth2/token', $post, array(
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . $cred,
            ));
            if (isset($data['access_token'])) {
                $_SESSION['session_access_token'] = $data['access_token'];
                $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
                if (!empty($data['refresh_token'])) { $_SESSION['session_refresh_token'] = $data['refresh_token']; }
                $me = url2ai_auth_get_json('https://api.twitter.com/2/users/me', $data['access_token']);
                if (isset($me['data']['username'])) { $_SESSION['session_username'] = $me['data']['username']; }
            }
            unset($_SESSION['aks_oauth_state'], $_SESSION['aks_code_verifier']);
        }
        $return_to = isset($_SESSION['aks_return_to']) ? $_SESSION['aks_return_to'] : $return_default;
        unset($_SESSION['aks_return_to']);
        header('Location: ' . AIGM_BASE_URL . url2ai_auth_safe_return($return_to));
        exit;
    }
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
    $opts = array('http' => array(
        'method' => 'POST',
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $data,
        'timeout' => 12,
        'ignore_errors' => true,
    ));
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
    $post = http_build_query(array(
        'grant_type' => 'refresh_token',
        'refresh_token' => $_SESSION['session_refresh_token'],
        'client_id' => $client_id,
    ));
    $cred = base64_encode($client_id . ':' . $client_secret);
    $ref = url2ai_auth_post_form('https://api.twitter.com/2/oauth2/token', $post, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . $cred,
    ));
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
