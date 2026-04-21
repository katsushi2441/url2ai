<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set("Asia/Tokyo");

/* =========================================================
   セッション長期維持設定（30日）
========================================================= */
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.cookie_path',     '/');
    ini_set('session.cookie_domain',   'aiknowledgecms.exbridge.jp');
    ini_set('session.cookie_secure',   '1');
    ini_set('session.cookie_httponly',  '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(),
            time() + $session_lifetime, '/',
            'aiknowledgecms.exbridge.jp', true, true);
    }
}

/* =========================================================
   X API キー読み込み
========================================================= */
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
$x_redirect_uri  = 'https://aiknowledgecms.exbridge.jp/umedia.php';

define('MEDIA_DIR',    __DIR__ . '/data/media');
define('MEDIA_URL',    'https://aiknowledgecms.exbridge.jp/data/media');

/* =========================================================
   OAuth2 PKCE
========================================================= */
function um_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function um_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return um_base64url($bytes);
}
function um_gen_challenge($verifier) {
    return um_base64url(hash('sha256', $verifier, true));
}
function um_x_post($url, $post_data, $headers) {
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
function um_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: UMedia/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['um_logout'])) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/',
        'aiknowledgecms.exbridge.jp', true, true);
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['um_login'])) {
    $verifier  = um_gen_verifier();
    $challenge = um_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['um_code_verifier'] = $verifier;
    $_SESSION['um_oauth_state']   = $state;
    $params = array(
        'response_type'         => 'code',
        'client_id'             => $x_client_id,
        'redirect_uri'          => $x_redirect_uri,
        'scope'                 => 'tweet.read users.read offline.access',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    );
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($params));
    exit;
}
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['um_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['um_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['um_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = um_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token']  = $data['access_token'];
            $_SESSION['session_token_expires']  = time() + (isset($data['expires_in']) ? (int)$data['expires_in'] : 7200);
            if (!empty($data['refresh_token'])) {
                $_SESSION['session_refresh_token'] = $data['refresh_token'];
            }
            unset($_SESSION['um_oauth_state'], $_SESSION['um_code_verifier']);
            $me = um_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) {
                $_SESSION['session_username'] = $me['data']['username'];
            }
        }
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

/* =========================================================
   アクセストークン自動リフレッシュ
========================================================= */
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
    $ref = um_x_post('https://api.twitter.com/2/oauth2/token', $post_r, array(
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

/* =========================================================
   ヘルパー
========================================================= */
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function find_sh() {
    foreach (array('/bin/sh', '/usr/bin/sh', '/bin/bash') as $b) {
        if (file_exists($b)) return $b;
    }
    return '';
}
function run_cmd($cmd) {
    $sh = find_sh();
    if (!$sh) return array('', 1);
    $out = array(); $ret = 0;
    exec($sh . ' -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $ret);
    return array(implode("\n", $out), $ret);
}
function extract_tweet_id($input) {
    $input = trim($input);
    if (preg_match('/(\d{15,20})/', $input, $m)) return $m[1];
    return '';
}

/* =========================================================
   FxTwitterからツイート情報取得
========================================================= */
function fx_get_full($tweet_id) {
    $url = 'https://api.fxtwitter.com/i/status/' . preg_replace('/[^0-9]/', '', $tweet_id);
    list($res, $ret) = run_cmd('curl -s --max-time 10 ' . escapeshellarg($url));
    if ($ret !== 0 || !$res) return null;
    return json_decode($res, true);
}

/* =========================================================
   メディアURL抽出（画像・動画）
========================================================= */
function extract_media_urls($fx_data) {
    $media_list = array();
    if (empty($fx_data['tweet'])) return $media_list;
    $tweet = $fx_data['tweet'];

    /* 画像 */
    if (!empty($tweet['media']['photos'])) {
        foreach ($tweet['media']['photos'] as $photo) {
            if (!empty($photo['url'])) {
                $media_list[] = array('type' => 'image', 'url' => $photo['url']);
            }
        }
    }
    /* 動画 */
    if (!empty($tweet['media']['videos'])) {
        foreach ($tweet['media']['videos'] as $video) {
            $best_url = '';
            /* x.com形式のURLを優先 */
            if (!empty($video['url'])) {
                $best_url = $video['url'];
            }
            /* なければvariantsから最高品質を選ぶ */
            if ($best_url === '' && !empty($video['variants'])) {
                $best_bw = 0;
                foreach ($video['variants'] as $v) {
                    $bw = isset($v['bitrate']) ? (int)$v['bitrate'] : 0;
                    if ($bw >= $best_bw && !empty($v['url'])) {
                        $best_bw  = $bw;
                        $best_url = $v['url'];
                    }
                }
            }
            if ($best_url !== '') {
                $media_list[] = array('type' => 'video', 'url' => $best_url, 'src_url_clean' => strtok($best_url, '?'));
            }
        }
    }
    /* GIF */
    if (!empty($tweet['media']['gifs'])) {
        foreach ($tweet['media']['gifs'] as $gif) {
            if (!empty($gif['url'])) {
                $media_list[] = array('type' => 'gif', 'url' => $gif['url']);
            }
        }
    }
    return $media_list;
}

/* =========================================================
   メディアダウンロード → data/media/TWEETID/ に保存
========================================================= */
function download_media($tweet_id, $media_list) {
    $dir = MEDIA_DIR . '/' . $tweet_id;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    /* media/ 直下に .htaccess を置いてディレクトリリスティングを無効化 */
    $htaccess_media = MEDIA_DIR . '/.htaccess';
    if (!file_exists($htaccess_media)) {
        file_put_contents($htaccess_media, "Options -Indexes\n");
    }

    $saved = array();
    foreach ($media_list as $i => $m) {
        $url  = $m['url'];
        $type = $m['type'];

        /* クエリストリングを除去してURLをクリーンに（動画のtag=12等） */
        $url_clean = strtok($url, '?');

        /* 拡張子を決定 */
        $ext = 'jpg';
        if ($type === 'video') { $ext = 'mp4'; }
        elseif ($type === 'gif') { $ext = 'mp4'; }
        elseif (preg_match('/\.(png|gif|webp|jpg|jpeg)$/i', $url_clean, $em)) {
            $ext = strtolower($em[1]);
        }
        $filename = sprintf('%s_%02d.%s', $type, $i + 1, $ext);
        $filepath = $dir . '/' . $filename;

        if (file_exists($filepath) && filesize($filepath) > 0) {
            /* 既にダウンロード済み */
            $saved[] = array(
                'type'     => $type,
                'filename' => $filename,
                'url'      => MEDIA_URL . '/' . $tweet_id . '/' . $filename,
                'src_url'  => isset($m['src_url']) ? $m['src_url'] : strtok($url, '?'),
            );
            continue;
        }

        /* curl でダウンロード（元URLをそのまま使う：クエリ込みが必要な場合もある） */
        list($out, $ret) = run_cmd(
            'curl -s -L --max-time 60 -A "Mozilla/5.0" -o '
            . escapeshellarg($filepath)
            . ' ' . escapeshellarg($url)
        );
        if ($ret === 0 && file_exists($filepath) && filesize($filepath) > 0) {
            $saved[] = array(
                'type'     => $type,
                'filename' => $filename,
                'url'      => MEDIA_URL . '/' . $tweet_id . '/' . $filename,
                'src_url'  => isset($m['src_url']) ? $m['src_url'] : strtok($url, '?'),
            );
        }
    }
    return $saved;
}

/* =========================================================
   Ollama 考察プロンプト
========================================================= */
$media_insight_prompt = "以下はXの投稿内容です。添付された画像・動画の内容も踏まえて、この投稿に対する深い考察を日本語で生成してください。

【条件】
- 180〜220字程度
- 投稿者の意図・背景・社会的文脈を読み解く
- 画像や動画がある場合はその内容についても言及する
- 客観的かつ洞察のある視点で
- 冒頭に「この投稿は〜」などの定型句は使わない

---
{thread}
---

添付メディア: {media_desc}
---

考察のみを出力してください。タイトル・前置き・説明は不要です。";

/* =========================================================
   POST処理
========================================================= */
$action      = isset($_POST['action'])      ? $_POST['action']          : '';
$tweet_url   = isset($_POST['tweet_url'])   ? trim($_POST['tweet_url']) : '';
$thread_text = isset($_POST['thread_text']) ? trim($_POST['thread_text']) : '';
$insight     = '';
$media_saved = array();
$fetch_error = isset($_SESSION['um_flash_error']) ? $_SESSION['um_flash_error'] : '';
if (isset($_SESSION['um_flash_error'])) { unset($_SESSION['um_flash_error']); }

/* GETでtweet_urlが渡された場合、保存済みデータを読み込む */
if ($tweet_url === '' && isset($_GET['tweet_url']) && $_GET['tweet_url'] !== '') {
    $tweet_url    = trim($_GET['tweet_url']);
    $tweet_id_get = extract_tweet_id($tweet_url);
    if ($tweet_id_get !== '') {
        $save_file_get = __DIR__ . '/data/xinsight_' . $tweet_id_get . '.json';
        if (file_exists($save_file_get)) {
            $saved_get = json_decode(file_get_contents($save_file_get), true);
            if (is_array($saved_get)) {
                $thread_text = isset($saved_get['thread_text'])   ? $saved_get['thread_text']   : '';
                $insight     = isset($saved_get['media_insight']) ? $saved_get['media_insight'] : '';
                $media_saved = isset($saved_get['media'])         ? $saved_get['media']         : array();
                $tweet_url   = isset($saved_get['tweet_url'])     ? $saved_get['tweet_url']     : $tweet_url;
            }
        }
    }
}

/* GETでidが渡された場合 */
if ($tweet_url === '' && isset($_GET['id']) && $_GET['id'] !== '') {
    $tweet_id_get = preg_replace('/[^0-9]/', '', trim($_GET['id']));
    if ($tweet_id_get !== '') {
        $save_file_get = __DIR__ . '/data/xinsight_' . $tweet_id_get . '.json';
        if (file_exists($save_file_get)) {
            $saved_get = json_decode(file_get_contents($save_file_get), true);
            if (is_array($saved_get)) {
                $thread_text = isset($saved_get['thread_text'])   ? $saved_get['thread_text']   : '';
                $insight     = isset($saved_get['media_insight']) ? $saved_get['media_insight'] : '';
                $media_saved = isset($saved_get['media'])         ? $saved_get['media']         : array();
                $tweet_url   = isset($saved_get['tweet_url'])     ? $saved_get['tweet_url']     : '';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {

    /* ---- STEP1: スレッド取得 ---- */
    if ($action === 'fetch' && $tweet_url !== '') {
        $tweet_id = extract_tweet_id($tweet_url);
        if ($tweet_id === '') {
            $_SESSION['um_flash_error'] = 'URLからツイートIDを取得できませんでした';
            header('Location: ' . $x_redirect_uri);
            exit;
        }

        $save_file = __DIR__ . '/data/xinsight_' . $tweet_id . '.json';

        /* 保存済みメディアがあればそちらを表示 */
        if (file_exists($save_file)) {
            $saved = json_decode(file_get_contents($save_file), true);
            if (is_array($saved) && !empty($saved['media'])) {
                $thread_text = isset($saved['thread_text'])   ? $saved['thread_text']   : '';
                $insight     = isset($saved['media_insight']) ? $saved['media_insight'] : '';
                $media_saved = $saved['media'];
                $tweet_url   = isset($saved['tweet_url'])     ? $saved['tweet_url']     : $tweet_url;
                $action      = 'loaded';
            }
        }

        if ($action !== 'loaded') {
            /* FxTwitterで取得 */
            $fx_data = fx_get_full($tweet_id);
            if (empty($fx_data['tweet'])) {
                $_SESSION['um_flash_error'] = 'ツイートを取得できませんでした';
                header('Location: ' . $x_redirect_uri);
                exit;
            }
            $tweet = $fx_data['tweet'];
            $screen_name = $tweet['author']['screen_name'];
            $thread_text = '@' . $screen_name . ': ' . $tweet['text'];
            $tweet_url_r = 'https://x.com/' . $screen_name . '/status/' . $tweet_id;
            if ($tweet_url === '') { $tweet_url = $tweet_url_r; }

            /* メディア抽出 & ダウンロード */
            $media_list  = extract_media_urls($fx_data);
            /* 動画・GIFのsrc_urlをx.com形式で上書き */
            $video_count = 1;
            foreach ($media_list as &$ml) {
                if (in_array($ml['type'], array('video', 'gif'))) {
                    $ml['src_url'] = 'https://x.com/' . $screen_name . '/status/' . $tweet_id . '/video/' . $video_count;
                    $video_count++;
                }
            }
            unset($ml);
            $media_saved = download_media($tweet_id, $media_list);
        }
    }

    /* ---- STEP2: 考察生成（fetch or analyze） ---- */
    if (($action === 'fetch' || $action === 'analyze') && $thread_text !== '') {
        /* メディア説明文を生成 */
        $media_desc = '添付なし';
        if (!empty($media_saved)) {
            $descs = array();
            foreach ($media_saved as $m) {
                $descs[] = $m['type'] . ': ' . $m['filename'];
            }
            $media_desc = implode(', ', $descs);
        }
        $prompt  = str_replace(
            array('{thread}', '{media_desc}'),
            array($thread_text, $media_desc),
            $media_insight_prompt
        );
        $payload = json_encode(array(
            'model'  => OLLAMA_MODEL,
            'prompt' => $prompt,
            'stream' => false,
            'options' => array(
                'num_ctx'     => 2048,
                'temperature' => 0.75,
                'top_k'       => 40,
                'top_p'       => 0.9,
            )
        ));
        $opts = array('http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 120,
            'ignore_errors' => true,
        ));
        $res = @file_get_contents(OLLAMA_API, false, stream_context_create($opts));
        if ($res) {
            $resp    = json_decode($res, true);
            $insight = isset($resp['response']) ? trim($resp['response']) : '応答が取得できませんでした';
        } else {
            $insight = 'Ollama APIに接続できませんでした';
        }

        /* xinsight_TWEETID.json に media / media_insight キーを追加保存 */
        if ($tweet_url !== '') {
            $tweet_id_save = extract_tweet_id($tweet_url);
            if ($tweet_id_save !== '') {
                $save_file = __DIR__ . '/data/xinsight_' . $tweet_id_save . '.json';
                if (file_exists($save_file)) {
                    $save_data = json_decode(file_get_contents($save_file), true);
                    if (!is_array($save_data)) { $save_data = array(); }
                } else {
                    $save_data = array(
                        'tweet_id'    => $tweet_id_save,
                        'tweet_url'   => $tweet_url,
                        'username'    => $username,
                        'thread_text' => $thread_text,
                        'saved_at'    => date('Y-m-d H:i:s'),
                    );
                }
                /* mediaは既存JSONから引き継ぐ（analyzeでは上書きしない） */
                if (empty($save_data['media'])) {
                    $save_data['media'] = $media_saved;
                }
                $save_data['media_insight']  = $insight;
                $save_data['media_saved_at'] = date('Y-m-d H:i:s');
                file_put_contents($save_file,
                    json_encode($save_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }

    /* PRGリダイレクト */
    if ($tweet_url !== '') {
        header('Location: ' . $x_redirect_uri . '?tweet_url=' . urlencode($tweet_url));
        exit;
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UMedia — メディア取得＆考察</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#0891b2;--accent-h:#0e7490;
    --green:#059669;--red:#dc2626;
    --text:#0f172a;--muted:#64748b;
    --mono:'JetBrains Mono',monospace;
    --sans:'Inter',sans-serif;
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

.container{max-width:1100px;margin:0 auto;padding:1.5rem}

.section{background:var(--surface);border:1px solid var(--border);border-radius:10px;margin-bottom:1rem;overflow:hidden}
.section-header{padding:.75rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between}
.section-title{font-weight:600;font-size:.85rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.step{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700}
.section-body{padding:1rem}

.row{display:flex;gap:.6rem;align-items:flex-start}
input[type=text]{flex:1;border:1px solid var(--border2);border-radius:6px;padding:.55rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;transition:border .15s;color:var(--text)}
input[type=text]:focus{border-color:var(--accent)}
textarea.code-area{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.75rem;font-family:var(--mono);font-size:.8rem;line-height:1.7;outline:none;resize:vertical;color:var(--text);min-height:100px}
textarea.insight-area{background:#f0f9ff;min-height:160px;font-family:var(--sans);font-size:.85rem;line-height:1.8}

.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans)}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-h)}
.btn-secondary{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}
.btn-secondary:hover{background:#e2e8f0}
.btn-generate{background:linear-gradient(135deg,#0891b2,#0e7490);color:#fff;padding:.65rem 2.5rem;font-size:.9rem}
.btn-generate:hover{background:linear-gradient(135deg,#0e7490,#155e75)}
.btn:disabled{opacity:.5;cursor:not-allowed}

.msg-error{color:var(--red);font-size:.8rem;margin-top:.4rem}
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading .spinner{display:inline-block}
.loading .btn-label{display:none}

#generating-msg{display:none;text-align:center;padding:12px 16px;font-size:.82rem;color:#0c4a6e;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;margin-bottom:1rem;font-weight:600;}

/* ---- メディアグリッド ---- */
.media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;padding:.5rem 0}
.media-card{border:1px solid var(--border);border-radius:8px;overflow:hidden;background:#f8fafc;}
.media-card img{width:100%;height:160px;object-fit:cover;display:block;}
.media-card video{width:100%;height:160px;object-fit:cover;display:block;}
.media-card-info{padding:8px 10px;font-size:11px;color:var(--muted);font-family:var(--mono);}
.media-card-info a{color:var(--accent);text-decoration:none;word-break:break-all;}
.media-card-info a:hover{text-decoration:underline;}
.media-badge{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px;}
.media-badge--image{background:#dbeafe;color:#1e40af;}
.media-badge--video{background:#dcfce7;color:#166534;}
.media-badge--gif{background:#fef3c7;color:#92400e;}
.no-media{color:var(--muted);font-size:.82rem;padding:.5rem 0;}

@media(max-width:600px){
    .row{flex-wrap:wrap}
    .row input[type=text]{flex:1 1 100%}
    .container{padding:1rem}
}
</style>
</head>
<body>

<header>
    <div class="logo-group"><div class="logo">U<span>Media</span></div><span class="u2a-badge">URL2AI</span>Media</div>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?um_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?um_login=1" class="btn-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">

    <!-- STEP 1: URL入力 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">1</span> XのURLを入力してメディアを取得</div>
        </div>
        <div class="section-body">
            <form method="POST" id="form-fetch">
                <input type="hidden" name="action" value="fetch">
                <div class="row">
                    <input type="text" name="tweet_url" id="tweet_url_input"
                           placeholder="https://x.com/user/status/..."
                           value="<?php echo h($tweet_url); ?>">
                    <button type="button" class="btn btn-primary" id="btn-fetch"
                        <?php if (!$is_admin): ?>disabled title="ログインが必要です"<?php endif; ?>
                        onclick="submitFetch()">
                        <span class="btn-label">取得</span>
                        <span class="spinner"></span>
                    </button>
                    <?php if ($tweet_url !== ''): ?>
                    <a href="<?php echo h($tweet_url); ?>" target="_blank" class="btn btn-secondary">元の投稿 ↗</a>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary" id="btn-open"
                            onclick="openTweetUrl()" disabled>元の投稿 ↗</button>
                    <?php endif; ?>
                </div>
                <?php if ($fetch_error): ?>
                <div class="msg-error"><?php echo h($fetch_error); ?></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- STEP 2: スレッド本文 -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">2</span> 投稿本文</div>
            <button type="button" class="btn btn-secondary"
                    style="font-size:.75rem;padding:.3rem .7rem"
                    onclick="document.getElementById('thread_text').value=''">クリア</button>
        </div>
        <div class="section-body">
            <textarea class="code-area" id="thread_text" name="thread_text"
                      rows="4" form="form-analyze"
                      placeholder="投稿本文がここに表示されます。直接編集も可能です。"><?php echo h($thread_text); ?></textarea>
        </div>
    </div>

    <!-- STEP 3: 取得済みメディア -->
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step">3</span> 取得済みメディア</div>
            <?php if (!empty($media_saved)): ?>
            <span style="font-size:.75rem;color:var(--muted)"><?php echo count($media_saved); ?> 件</span>
            <?php endif; ?>
        </div>
        <div class="section-body">
            <?php if (empty($media_saved)): ?>
            <p class="no-media">まだメディアがありません。URLを入力して「取得」を押してください。</p>
            <?php else: ?>
            <div class="media-grid">
                <?php foreach ($media_saved as $m): ?>
                <div class="media-card">
                    <?php if ($m['type'] === 'image'): ?>
                    <img src="<?php echo h($m['url']); ?>"
                         alt="<?php echo h($m['filename']); ?>"
                         loading="lazy">
                    <?php elseif ($m['type'] === 'video' || $m['type'] === 'gif'): ?>
                    <video src="<?php echo h($m['url']); ?>"
                           controls muted playsinline
                           style="width:100%;height:160px;object-fit:cover;"></video>
                    <?php endif; ?>
                    <div class="media-card-info">
                        <span class="media-badge media-badge--<?php echo h($m['type']); ?>"><?php echo h($m['type']); ?></span><br>
                        <a href="<?php echo h($m['url']); ?>" target="_blank" download="<?php echo h($m['filename']); ?>">
                            ⬇ <?php echo h($m['filename']); ?>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 生成中メッセージ -->
    <div id="generating-msg">
        🔍 考察生成中です。1〜2分かかります。ページを閉じないでください...
    </div>

    <!-- STEP 4: 考察生成 -->
    <form method="POST" id="form-analyze">
        <input type="hidden" name="action" value="analyze">
        <input type="hidden" name="tweet_url" value="<?php echo h($tweet_url); ?>">
        <div style="display:flex;justify-content:center;margin-bottom:1rem">
            <button type="button" class="btn btn-generate" id="btn-analyze"
                <?php if (!$is_admin): ?>disabled title="ログインが必要です"<?php endif; ?>
                onclick="submitAnalyze()">
                <span class="btn-label">🔍 考察を生成</span>
                <span class="spinner"></span>
            </button>
        </div>
    </form>

    <!-- STEP 5: 考察結果 -->
    <?php if ($insight !== ''): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:var(--green)">✓</span> AI考察</div>
            <button type="button" class="btn btn-secondary"
                    style="font-size:.75rem;padding:.3rem .7rem"
                    onclick="copyInsight()">コピー</button>
        </div>
        <div class="section-body">
            <textarea class="code-area insight-area" id="insight_area"
                      rows="8" readonly><?php echo h($insight); ?></textarea>
        </div>
    </div>

    <?php
    /* 動画・GIFのsrc_urlを収集 */
    $video_urls = array();
    foreach ($media_saved as $m) {
        if (in_array($m['type'], array('video', 'gif')) && !empty($m['src_url'])) {
            $clean = strtok($m['src_url'], '?');
            $video_urls[] = array('type' => $m['type'], 'src_url' => $clean, 'filename' => $m['filename']);
        }
    }
    ?>
    <?php if (!empty($video_urls)): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title"><span class="step" style="background:#0891b2">🎬</span> 動画URL（元のX配信URL）</div>
        </div>
        <div class="section-body">
            <?php foreach ($video_urls as $v): ?>
            <div style="margin-bottom:.75rem;">
                <div style="font-size:10px;font-weight:700;color:#0891b2;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">
                    <?php echo h($v['type']); ?> — <?php echo h($v['filename']); ?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                    <code style="flex:1;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:6px 10px;font-size:11px;font-family:var(--mono);color:#0c4a6e;word-break:break-all;"><?php echo h($v['src_url']); ?></code>
                    <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .7rem;flex-shrink:0;"
                        onclick="copyVideoUrl(this, <?php echo h(json_encode($v['src_url'])); ?>)">コピー</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div><!-- /container -->

<script>
function openTweetUrl() {
    var url = document.getElementById('tweet_url_input').value.trim();
    if (url) window.open(url, '_blank');
}
var urlInput = document.getElementById('tweet_url_input');
var btnOpen  = document.getElementById('btn-open');
if (urlInput && btnOpen) {
    urlInput.addEventListener('input', function() {
        btnOpen.disabled = this.value.trim() === '';
    });
}
function lockUI() {
    var btnF = document.getElementById('btn-fetch');
    var btnA = document.getElementById('btn-analyze');
    var msg  = document.getElementById('generating-msg');
    if (btnF) { btnF.disabled = true; btnF.style.opacity = '0.5'; }
    if (btnA) { btnA.disabled = true; btnA.style.opacity = '0.5'; }
    if (msg)  { msg.style.display = 'block'; }
}
function submitFetch() {
    lockUI();
    var btn = document.getElementById('btn-fetch');
    if (btn) { btn.classList.add('loading'); }
    document.getElementById('form-fetch').submit();
}
function submitAnalyze() {
    lockUI();
    var btn = document.getElementById('btn-analyze');
    if (btn) { btn.classList.add('loading'); }
    document.getElementById('form-analyze').submit();
}
function copyInsight() {
    var el = document.getElementById('insight_area');
    if (!el) return;
    var tweetUrl = '<?php echo addslashes($tweet_url); ?>';
    var tid = tweetUrl.match(/(\d{15,20})/);
    var umediavUrl = tid ? 'https://aiknowledgecms.exbridge.jp/umediav.php?id=' + tid[1] : '';
    var videoUrls = <?php
        $vurls = array();
        foreach ($media_saved as $m) {
            if (in_array($m['type'], array('video','gif')) && !empty($m['src_url'])) {
                $vurls[] = strtok($m['src_url'], '?');
            }
        }
        echo json_encode($vurls, JSON_UNESCAPED_UNICODE);
    ?>;

    var text = el.value;
    text += '\n\nX投稿の画像・動画を保存して投稿をAIが考察\n';
    if (umediavUrl) { text += umediavUrl + '\n'; }
    if (videoUrls.length > 0) {
        text += '\n';
        videoUrls.forEach(function(u) { text += u + '\n'; });
    }
    text += '\n元の投稿\n';
    if (tweetUrl) { text += tweetUrl; }

    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        el.select();
        document.execCommand('copy');
    }
}
function copyVideoUrl(btn, url) {
    navigator.clipboard.writeText(url).then(function() {
        var orig = btn.textContent;
        btn.textContent = '✓ コピー済';
        setTimeout(function() { btn.textContent = orig; }, 2000);
    });
}
</script>
</body>
</html>
