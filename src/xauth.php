<?php
/**
 * xauth.php — X (Twitter) OAuth2 PKCE 共通認証モジュール
 * 各PHPファイルで require_once __DIR__ . '/xauth.php'; して使用
 *
 * 使用方法:
 *   require_once __DIR__ . '/xauth.php';
 *   xauth_init('https://example.com/yourfile.php');  // redirect_uri を渡す
 *   $logged_in    = xauth_logged_in();
 *   $session_user = xauth_username();
 *   $is_admin     = xauth_is_admin();
 */

if (!function_exists('xauth_init')) {

function xauth_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function xauth_gen_verifier() {
    $b = '';
    for ($i = 0; $i < 32; $i++) { $b .= chr(mt_rand(0, 255)); }
    return xauth_base64url($b);
}
function xauth_gen_challenge($v) {
    return xauth_base64url(hash('sha256', $v, true));
}
function xauth_http_post($url, $data, $headers) {
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'content'       => $data,
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    if (!$r) { $r = '{}'; }
    return json_decode($r, true);
}
function xauth_http_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: AIGM/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    if (!$r) { $r = '{}'; }
    return json_decode($r, true);
}

function xauth_init($redirect_uri) {
    /* X APIキー読み込み */
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
    $client_id     = isset($x_keys['X_API_KEY'])    ? $x_keys['X_API_KEY']    : '';
    $client_secret = isset($x_keys['X_API_SECRET']) ? $x_keys['X_API_SECRET'] : '';

    /* ログアウト */
    if (isset($_GET['xauth_logout'])) {
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/', '', true, true);
        header('Location: ' . $redirect_uri);
        exit;
    }

    /* ログイン開始 */
    if (isset($_GET['xauth_login'])) {
        $ver   = xauth_gen_verifier();
        $chal  = xauth_gen_challenge($ver);
        $state = md5(uniqid('', true));
        $_SESSION['xauth_verifier'] = $ver;
        $_SESSION['xauth_state']    = $state;
        $p = array(
            'response_type'         => 'code',
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'scope'                 => 'tweet.read users.read offline.access',
            'state'                 => $state,
            'code_challenge'        => $chal,
            'code_challenge_method' => 'S256',
        );
        header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($p));
        exit;
    }

    /* OAuthコールバック */
    if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['xauth_state'])) {
        if ($_GET['state'] === $_SESSION['xauth_state']) {
            $post = http_build_query(array(
                'grant_type'    => 'authorization_code',
                'code'          => $_GET['code'],
                'redirect_uri'  => $redirect_uri,
                'code_verifier' => $_SESSION['xauth_verifier'],
                'client_id'     => $client_id,
            ));
            $cred = base64_encode($client_id . ':' . $client_secret);
            $data = xauth_http_post('https://api.twitter.com/2/oauth2/token', $post, array(
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . $cred,
            ));
            if (!empty($data['access_token'])) {
                $_SESSION['session_access_token']  = $data['access_token'];
                $_SESSION['session_token_expires'] = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
                if (!empty($data['refresh_token'])) {
                    $_SESSION['session_refresh_token'] = $data['refresh_token'];
                }
                unset($_SESSION['xauth_state'], $_SESSION['xauth_verifier']);
                $me = xauth_http_get('https://api.twitter.com/2/users/me', $data['access_token']);
                if (!empty($me['data']['username'])) {
                    $_SESSION['session_username'] = $me['data']['username'];
                }
            }
        }
        header('Location: ' . $redirect_uri);
        exit;
    }

    /* アクセストークン自動リフレッシュ */
    if (
        !empty($_SESSION['session_refresh_token']) &&
        !empty($_SESSION['session_token_expires']) &&
        time() > $_SESSION['session_token_expires'] - 300
    ) {
        $post_r = http_build_query(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $_SESSION['session_refresh_token'],
            'client_id'     => $client_id,
        ));
        $cred_r = base64_encode($client_id . ':' . $client_secret);
        $ref = xauth_http_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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
}

function xauth_logged_in() {
    return !empty($_SESSION['session_access_token']);
}
function xauth_username() {
    return isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
}
function xauth_is_admin($admin = 'xb_bittensor') {
    return xauth_username() === $admin;
}

/* ヘッダーuserbarのHTML出力ヘルパー */
function xauth_userbar_html($login_param = 'xauth_login', $logout_param = 'xauth_logout') {
    $logged_in = xauth_logged_in();
    $username  = xauth_username();
    if ($logged_in) {
        return '<span>@<strong>' . htmlspecialchars($username) . '</strong></span>'
             . '<a href="?' . $logout_param . '=1" class="btn-sm">logout</a>';
    } else {
        return '<a href="?' . $login_param . '=1" class="btn-login-sm">X でログイン</a>';
    }
}

} /* end function_exists guard */