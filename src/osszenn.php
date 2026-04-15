<?php
session_start();
date_default_timezone_set("Asia/Tokyo");
$DATA_FILE    = __DIR__ . '/data/oss_posts.json';
$BASE_URL     = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE    = 'osszenn.php';
$SITE_NAME    = 'OSSZenn';
$ADMIN        = 'xb_bittensor';

/* X API キー読み込み */
$x_keys_file = __DIR__ . '/x_api_keys.sh';
$x_keys = array();
if (file_exists($x_keys_file)) {
    $lines = file($x_keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/(?:export\\s+)?(\\w+)=["\']?([^"\'#\\r\\n]*)["\']?/', $line, $m)) {
            $x_keys[trim($m[1])] = trim($m[2]);
        }
    }
}
$x_client_id     = isset($x_keys['X_API_KEY'])    ? $x_keys['X_API_KEY']    : '';
$x_client_secret = isset($x_keys['X_API_SECRET']) ? $x_keys['X_API_SECRET'] : '';
$x_redirect_uri  = $BASE_URL . '/' . $THIS_FILE;

function oss_base64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function oss_gen_verifier() {
    $b = ''; for ($i = 0; $i < 32; $i++) { $b .= chr(mt_rand(0, 255)); } return oss_base64url($b);
}
function oss_gen_challenge($v) { return oss_base64url(hash('sha256', $v, true)); }
function oss_x_post($url, $data, $headers) {
    $opts = array('http' => array('method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n", 'content' => $data, 'timeout' => 12, 'ignore_errors' => true));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    if (!$r) { $r = '{}'; } return json_decode($r, true);
}
function oss_x_get($url, $token) {
    $opts = array('http' => array('method' => 'GET', 'header' => "Authorization: Bearer $token\r\nUser-Agent: OSSTimeline/1.0\r\n", 'timeout' => 12, 'ignore_errors' => true));
    $r = @file_get_contents($url, false, stream_context_create($opts));
    if (!$r) { $r = '{}'; } return json_decode($r, true);
}

if (isset($_GET['oss_logout'])) { session_destroy(); header('Location: ' . $x_redirect_uri); exit; }
if (isset($_GET['oss_login'])) {
    $ver = oss_gen_verifier();
    $chal = oss_gen_challenge($ver);
    $state = md5(uniqid('', true));
    $_SESSION['oss_code_verifier'] = $ver;
    $_SESSION['oss_oauth_state']   = $state;
    $p = array('response_type' => 'code', 'client_id' => $x_client_id, 'redirect_uri' => $x_redirect_uri,
               'scope' => 'tweet.read users.read', 'state' => $state, 'code_challenge' => $chal, 'code_challenge_method' => 'S256');
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($p)); exit;
}
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['oss_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['oss_oauth_state']) {
        $post = http_build_query(array('grant_type' => 'authorization_code', 'code' => $_GET['code'],
            'redirect_uri' => $x_redirect_uri, 'code_verifier' => $_SESSION['oss_code_verifier'], 'client_id' => $x_client_id));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = oss_x_post('https://api.twitter.com/2/oauth2/token', $post, array('Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . $cred));
        if (isset($data['access_token'])) {
            $_SESSION['session_access_token'] = $data['access_token'];
            unset($_SESSION['oss_oauth_state'], $_SESSION['oss_code_verifier']);
            $me = oss_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) { $_SESSION['session_username'] = $me['data']['username']; }
        }
    }
    header('Location: ' . $x_redirect_uri); exit;
}

$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin     = ($session_user === $ADMIN);
$logged_in    = ($session_user !== '');

/* oss_posts.json 読み込み */
$posts = array();
if (file_exists($DATA_FILE)) {
    $posts = json_decode(file_get_contents($DATA_FILE), true);
    if (!$posts) $posts = array();
}

function osszenn_collect_zenn_users($data_dir) {
    $zenn_users = array();
    foreach (glob(rtrim($data_dir, '/') . '/keyword_*.json') as $kf) {
        $kdata = @json_decode(file_get_contents($kf), true);
        if (!$kdata) { continue; }
        $account   = isset($kdata['account']) ? $kdata['account'] : '';
        $keywords  = isset($kdata['keywords']) && is_array($kdata['keywords']) ? $kdata['keywords'] : array();
        $sources   = isset($kdata['sources']) && is_array($kdata['sources']) ? $kdata['sources'] : array();
        $zenn_info = null;
        if (!isset($sources[0]) && isset($sources['zenn']) && is_array($sources['zenn'])) {
            $zenn_info = $sources['zenn'];
        }
        if (!$account || !$zenn_info || empty($zenn_info['username'])) { continue; }
        $zenn_users[] = array(
            'account'       => $account,
            'zenn_username' => $zenn_info['username'],
            'keywords'      => $keywords,
        );
    }
    return $zenn_users;
}

function osszenn_load_rss_cache($zenn_users, $data_dir) {
    $rss_opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "User-Agent: Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)\r\nAccept: application/rss+xml,application/xml,text/xml\r\n",
        'timeout'       => 8,
        'ignore_errors' => true,
    ));
    $rss_cache = array();
    foreach ($zenn_users as $zu) {
        $zun        = $zu['zenn_username'];
        $cache_file = rtrim($data_dir, '/') . '/zenn_rss_cache_' . preg_replace('/[^a-zA-Z0-9_]/', '', $zun) . '.json';
        $cache_ttl  = 3600;
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
            $cached = @json_decode(file_get_contents($cache_file), true);
            if (is_array($cached)) {
                $rss_cache[$zun] = $cached;
                continue;
            }
        }
        $rss_raw  = @file_get_contents('https://zenn.dev/' . rawurlencode($zun) . '/feed', false, stream_context_create($rss_opts));
        $articles = array();
        if ($rss_raw) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($rss_raw);
            if ($xml && isset($xml->channel->item)) {
                foreach ($xml->channel->item as $item) {
                    $liked = 0;
                    if (isset($item->children('http://zenn.dev/ns#')->liked_count)) {
                        $liked = intval($item->children('http://zenn.dev/ns#')->liked_count);
                    }
                    $pub = isset($item->pubDate) ? date('Y-m-d', strtotime((string)$item->pubDate)) : '';
                    $articles[] = array(
                        'title'   => (string)$item->title,
                        'link'    => (string)$item->link,
                        'pubDate' => $pub,
                        'liked'   => $liked,
                    );
                }
            }
            @file_put_contents($cache_file, json_encode($articles, JSON_UNESCAPED_UNICODE));
        } elseif (file_exists($cache_file)) {
            $cached = @json_decode(file_get_contents($cache_file), true);
            if (is_array($cached)) { $articles = $cached; }
        }
        $rss_cache[$zun] = $articles;
    }
    return $rss_cache;
}

function osszenn_match_posts($posts, $zenn_users, $rss_cache, $limit_per_post) {
    $result = array();
    foreach ($posts as $post) {
        $oss_tags  = !empty($post['tags']) && is_array($post['tags']) ? $post['tags'] : array();
        $oss_title = isset($post['title']) ? $post['title'] : '';
        $oss_id    = isset($post['id']) ? $post['id'] : '';
        if (!$oss_title || !$oss_id) { continue; }
        $repo_name  = preg_replace('/^.*\//', '', $oss_title);
        if (mb_strlen($repo_name) < 3) { continue; }
        $repo_lower = mb_strtolower($repo_name);
        $matched    = array();
        foreach ($zenn_users as $zu) {
            $zun      = $zu['zenn_username'];
            $articles = isset($rss_cache[$zun]) ? $rss_cache[$zun] : array();
            foreach ($articles as $art) {
                $art_lower = mb_strtolower(isset($art['title']) ? $art['title'] : '');
                if (mb_strpos($art_lower, $repo_lower) === false) { continue; }
                $score = 10;
                foreach ($oss_tags as $tag) {
                    foreach ($zu['keywords'] as $kw) {
                        if (mb_strtolower($kw) === mb_strtolower($tag)) {
                            $score += 2;
                            break;
                        }
                    }
                }
                $matched[] = array(
                    'article'       => $art,
                    'account'       => $zu['account'],
                    'zenn_username' => $zun,
                    'score'         => $score,
                );
            }
        }
        if (empty($matched)) { continue; }
        usort($matched, function($a, $b) {
            $pd = strcmp($b['article']['pubDate'], $a['article']['pubDate']);
            return $pd !== 0 ? $pd : ($b['score'] - $a['score']);
        });
        $seen  = array();
        $dedup = array();
        foreach ($matched as $ma) {
            $link = isset($ma['article']['link']) ? $ma['article']['link'] : '';
            if ($link === '' || isset($seen[$link])) { continue; }
            $seen[$link] = true;
            $dedup[] = $ma;
            if (count($dedup) >= $limit_per_post) { break; }
        }
        if (!empty($dedup)) {
            $result[$oss_id] = $dedup;
        }
    }
    return $result;
}

/* =========================================================
   RSS フィード出力 (?feed)
========================================================= */
if (isset($_GET['feed'])) {
    $zenn_users = osszenn_collect_zenn_users(__DIR__ . '/data');
    $rss_cache  = osszenn_load_rss_cache($zenn_users, __DIR__ . '/data');
    $matched_map = osszenn_match_posts($posts, $zenn_users, $rss_cache, 5);
    $feed_pool = array();
    foreach ($posts as $p) {
        $oss_id = isset($p['id']) ? $p['id'] : '';
        if ($oss_id === '' || empty($matched_map[$oss_id])) { continue; }
        foreach ($matched_map[$oss_id] as $ma) {
            $art  = isset($ma['article']) ? $ma['article'] : array();
            $link = isset($art['link']) ? $art['link'] : '';
            if ($link === '') { continue; }
            $item = array(
                'title'         => isset($art['title']) ? $art['title'] : '(no title)',
                'link'          => $link,
                'pubDate'       => isset($art['pubDate']) ? $art['pubDate'] : '',
                'liked'         => isset($art['liked']) ? intval($art['liked']) : 0,
                'account'       => isset($ma['account']) ? $ma['account'] : '',
                'zenn_username' => isset($ma['zenn_username']) ? $ma['zenn_username'] : '',
                'score'         => isset($ma['score']) ? intval($ma['score']) : 0,
                'oss_title'     => isset($p['title']) ? $p['title'] : '',
                'oss_id'        => $oss_id,
                'github_url'    => isset($p['github_url']) ? $p['github_url'] : '',
            );
            if (!isset($feed_pool[$link])) {
                $feed_pool[$link] = $item;
                continue;
            }
            $existing = $feed_pool[$link];
            $replace = false;
            if ($item['pubDate'] > $existing['pubDate']) {
                $replace = true;
            } elseif ($item['pubDate'] === $existing['pubDate'] && $item['score'] > $existing['score']) {
                $replace = true;
            }
            if ($replace) {
                $feed_pool[$link] = $item;
            }
        }
    }
    $rss_items = array_values($feed_pool);
    usort($rss_items, function($a, $b) {
        $pd = strcmp($b['pubDate'], $a['pubDate']);
        return $pd !== 0 ? $pd : ($b['score'] - $a['score']);
    });
    $rss_items = array_slice($rss_items, 0, 20);
    header('Access-Control-Allow-Origin: https://exbridge.jp');
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    echo '<channel>' . "\n";
    echo '<title>OSSZenn | Zenn RSS Feed</title>' . "\n";
    echo '<link>' . $BASE_URL . '/osszenn.php</link>' . "\n";
    echo '<description>OSSZennで収集したZenn RSS由来の記事一覧。関連するOSS情報とあわせて配信。</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<atom:link href="' . $BASE_URL . '/osszenn.php?feed" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rss_items as $p) {
        $title    = isset($p['title']) ? $p['title'] : '(no title)';
        $link     = isset($p['link']) ? $p['link'] : ($BASE_URL . '/osszenn.php');
        $pub_raw  = isset($p['pubDate']) ? $p['pubDate'] : '';
        $pub_date = $pub_raw ? date('r', strtotime($pub_raw)) : date('r');
        $desc_parts = array();
        if (!empty($p['oss_title'])) {
            $desc_parts[] = '関連OSS: ' . $p['oss_title'];
        }
        if (!empty($p['zenn_username'])) {
            $desc_parts[] = 'Zenn: ' . $p['zenn_username'];
        }
        if (!empty($p['account'])) {
            $desc_parts[] = 'X: @' . $p['account'];
        }
        if (!empty($p['liked'])) {
            $desc_parts[] = 'Liked: ' . intval($p['liked']);
        }
        if (!empty($p['github_url'])) {
            $desc_parts[] = 'GitHub: ' . $p['github_url'];
        }
        $desc = implode("\n", $desc_parts);
        echo '<item>' . "\n";
        echo '<title><![CDATA[' . $title . ']]></title>' . "\n";
        echo '<link>' . htmlspecialchars($link) . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . htmlspecialchars($link) . '</guid>' . "\n";
        echo '<description><![CDATA[' . $desc . ']]></description>' . "\n";
        echo '<pubDate>' . $pub_date . '</pubDate>' . "\n";
        echo '</item>' . "\n";
    }
    echo '</channel>' . "\n";
    echo '</rss>' . "\n";
    exit;
}

/* qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
   API: action=zenn_data — Zennマッチング結果をJSON返却
   （重い処理をAjaxに分離して画面の初期表示を速くする）
   qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq */
if (isset($_GET['action']) && $_GET['action'] === 'zenn_data') {
    header('Content-Type: application/json; charset=UTF-8');
    @set_time_limit(120);
    @ini_set('max_execution_time', 120);

    /* keyword_*.json スキャン */
    $sns_data_dir = __DIR__ . '/data/';
    $zenn_users   = array();
    foreach (glob($sns_data_dir . 'keyword_*.json') as $kf) {
        $kdata = @json_decode(file_get_contents($kf), true);
        if (!$kdata) { continue; }
        $account  = isset($kdata['account'])  ? $kdata['account']  : '';
        $keywords = isset($kdata['keywords']) ? $kdata['keywords'] : array();
        $sources  = isset($kdata['sources'])  ? $kdata['sources']  : array();
        $zenn_info = null;
        if (is_array($sources) && !isset($sources[0]) && isset($sources['zenn'])) {
            $zenn_info = $sources['zenn'];
        }
        if (!$account || !$zenn_info || empty($zenn_info['username'])) { continue; }
        $zenn_users[] = array(
            'account'       => $account,
            'zenn_username' => $zenn_info['username'],
            'keywords'      => $keywords,
        );
    }

    /* RSS取得（ファイルキャッシュ1時間） */
    $rss_opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "User-Agent: Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)\r\nAccept: application/rss+xml,application/xml,text/xml\r\n",
        'timeout'       => 8,
        'ignore_errors' => true,
    ));
    $rss_cache = array();
    foreach ($zenn_users as $zu) {
        $zun        = $zu['zenn_username'];
        $cache_file = __DIR__ . '/data/zenn_rss_cache_' . preg_replace('/[^a-zA-Z0-9_]/', '', $zun) . '.json';
        $cache_ttl  = 3600;
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
            $cached = @json_decode(file_get_contents($cache_file), true);
            if (is_array($cached)) { $rss_cache[$zun] = $cached; continue; }
        }
        $rss_raw  = @file_get_contents('https://zenn.dev/' . rawurlencode($zun) . '/feed', false, stream_context_create($rss_opts));
        $articles = array();
        if ($rss_raw) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($rss_raw);
            if ($xml && isset($xml->channel->item)) {
                foreach ($xml->channel->item as $item) {
                    $liked = 0;
                    if (isset($item->children('http://zenn.dev/ns#')->liked_count)) {
                        $liked = intval($item->children('http://zenn.dev/ns#')->liked_count);
                    }
                    $pub = isset($item->pubDate) ? date('Y-m-d', strtotime((string)$item->pubDate)) : '';
                    $articles[] = array(
                        'title'   => (string)$item->title,
                        'link'    => (string)$item->link,
                        'pubDate' => $pub,
                        'liked'   => $liked,
                    );
                }
            }
            @file_put_contents($cache_file, json_encode($articles, JSON_UNESCAPED_UNICODE));
        } elseif (file_exists($cache_file)) {
            $cached = @json_decode(file_get_contents($cache_file), true);
            if (is_array($cached)) { $articles = $cached; }
        }
        $rss_cache[$zun] = $articles;
    }

    /* OSSごとにマッチング */
    $result = array(); /* oss_id => array of matched_articles */
    foreach ($posts as $post) {
        $oss_tags  = !empty($post['tags']) ? $post['tags'] : array();
        $oss_title = isset($post['title']) ? $post['title'] : '';
        $oss_id    = isset($post['id'])    ? $post['id']    : '';
        if (!$oss_title || !$oss_id) { continue; }
        $repo_name = preg_replace('/^.*\//', '', $oss_title);
        if (mb_strlen($repo_name) < 3) { continue; }
        $repo_lower = mb_strtolower($repo_name);

        $matched = array();
        foreach ($zenn_users as $zu) {
            $zun      = $zu['zenn_username'];
            $articles = isset($rss_cache[$zun]) ? $rss_cache[$zun] : array();
            foreach ($articles as $art) {
                $art_lower = mb_strtolower($art['title']);
                if (mb_strpos($art_lower, $repo_lower) === false) { continue; }
                $score = 10;
                foreach ($oss_tags as $tag) {
                    foreach ($zu['keywords'] as $kw) {
                        if (mb_strtolower($kw) === mb_strtolower($tag)) { $score += 2; break; }
                    }
                }
                $matched[] = array(
                    'article'       => $art,
                    'account'       => $zu['account'],
                    'zenn_username' => $zun,
                    'score'         => $score,
                );
            }
        }
        if (empty($matched)) { continue; }
        usort($matched, function($a, $b) { return $b['score'] - $a['score']; });
        $seen  = array();
        $dedup = array();
        foreach ($matched as $ma) {
            $link = $ma['article']['link'];
            if (!isset($seen[$link])) {
                $seen[$link] = true;
                $dedup[] = $ma;
            }
            if (count($dedup) >= 5) { break; }
        }
        $result[$oss_id] = $dedup;
    }

    /* デバッグ */
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        echo json_encode(array(
            'zenn_users_count'   => count($zenn_users),
            'rss_cache_keys'     => array_keys($rss_cache),
            'rss_articles_count' => array_map(function($a){ return count($a); }, $rss_cache),
            'matched_oss_count'  => count($result),
        ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode(array('ok' => true, 'data' => $result), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ページ制御 */
$detail_id   = isset($_GET['id']) ? trim($_GET['id']) : '';
$filter_tag  = isset($_GET['tag']) ? trim($_GET['tag']) : '';
$detail_post = null;

if ($detail_id) {
    foreach ($posts as $p) {
        if ($p['id'] === $detail_id) { $detail_post = $p; break; }
    }
}

/* qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
   詳細ページ: サーバーサイドでZennマッチングを実行
   （SEOクローラーがJSを実行しないため必須）
   qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq */
if ($detail_post && empty($detail_post['zenn_articles'])) {
    $sns_data_dir_d = __DIR__ . '/data/';
    $zenn_users_d   = array();
    foreach (glob($sns_data_dir_d . 'keyword_*.json') as $kf) {
        $kdata = @json_decode(file_get_contents($kf), true);
        if (!$kdata) { continue; }
        $account_d  = isset($kdata['account'])  ? $kdata['account']  : '';
        $keywords_d = isset($kdata['keywords']) ? $kdata['keywords'] : array();
        $sources_d  = isset($kdata['sources'])  ? $kdata['sources']  : array();
        $zenn_info_d = null;
        if (is_array($sources_d) && !isset($sources_d[0]) && isset($sources_d['zenn'])) {
            $zenn_info_d = $sources_d['zenn'];
        }
        if (!$account_d || !$zenn_info_d || empty($zenn_info_d['username'])) { continue; }
        $zenn_users_d[] = array(
            'account'       => $account_d,
            'zenn_username' => $zenn_info_d['username'],
            'keywords'      => $keywords_d,
        );
    }
    $rss_opts_d = array('http' => array(
        'method'        => 'GET',
        'header'        => "User-Agent: Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)\r\nAccept: application/rss+xml,application/xml,text/xml\r\n",
        'timeout'       => 8,
        'ignore_errors' => true,
    ));
    $oss_tags_d  = !empty($detail_post['tags']) ? $detail_post['tags'] : array();
    $oss_title_d = isset($detail_post['title']) ? $detail_post['title'] : '';
    $repo_name_d = preg_replace('/^.*\//', '', $oss_title_d);
    $repo_lower_d = mb_strtolower($repo_name_d);
    $matched_d   = array();
    if (mb_strlen($repo_name_d) >= 3) {
        foreach ($zenn_users_d as $zu) {
            $zun_d      = $zu['zenn_username'];
            $cache_file_d = __DIR__ . '/data/zenn_rss_cache_' . preg_replace('/[^a-zA-Z0-9_]/', '', $zun_d) . '.json';
            $articles_d = array();
            if (file_exists($cache_file_d) && (time() - filemtime($cache_file_d)) < 3600) {
                $cached_d = @json_decode(file_get_contents($cache_file_d), true);
                if (is_array($cached_d)) { $articles_d = $cached_d; }
            } else {
                $rss_raw_d = @file_get_contents('https://zenn.dev/' . rawurlencode($zun_d) . '/feed', false, stream_context_create($rss_opts_d));
                if ($rss_raw_d) {
                    libxml_use_internal_errors(true);
                    $xml_d = simplexml_load_string($rss_raw_d);
                    if ($xml_d && isset($xml_d->channel->item)) {
                        foreach ($xml_d->channel->item as $item_d) {
                            $liked_d = 0;
                            if (isset($item_d->children('http://zenn.dev/ns#')->liked_count)) {
                                $liked_d = intval($item_d->children('http://zenn.dev/ns#')->liked_count);
                            }
                            $pub_d = isset($item_d->pubDate) ? date('Y-m-d', strtotime((string)$item_d->pubDate)) : '';
                            $articles_d[] = array(
                                'title'   => (string)$item_d->title,
                                'link'    => (string)$item_d->link,
                                'pubDate' => $pub_d,
                                'liked'   => $liked_d,
                            );
                        }
                    }
                    @file_put_contents($cache_file_d, json_encode($articles_d, JSON_UNESCAPED_UNICODE));
                } elseif (file_exists($cache_file_d)) {
                    $cached_d = @json_decode(file_get_contents($cache_file_d), true);
                    if (is_array($cached_d)) { $articles_d = $cached_d; }
                }
            }
            foreach ($articles_d as $art_d) {
                if (mb_strpos(mb_strtolower($art_d['title']), $repo_lower_d) === false) { continue; }
                $score_d = 10;
                foreach ($oss_tags_d as $tag_d) {
                    foreach ($zu['keywords'] as $kw_d) {
                        if (mb_strtolower($kw_d) === mb_strtolower($tag_d)) { $score_d += 2; break; }
                    }
                }
                $matched_d[] = array(
                    'article'       => $art_d,
                    'account'       => $zu['account'],
                    'zenn_username' => $zun_d,
                    'score'         => $score_d,
                );
            }
        }
        usort($matched_d, function($a, $b) {
            $pd = strcmp($b['article']['pubDate'], $a['article']['pubDate']);
            return $pd !== 0 ? $pd : $b['score'] - $a['score'];
        });
        $seen_d  = array();
        $dedup_d = array();
        foreach ($matched_d as $ma_d) {
            $link_d = $ma_d['article']['link'];
            if (!isset($seen_d[$link_d])) {
                $seen_d[$link_d] = true;
                $dedup_d[] = $ma_d;
            }
            if (count($dedup_d) >= 5) { break; }
        }
        $detail_post['zenn_articles'] = $dedup_d;
    }
}

$all_tags = array();
foreach ($posts as $post) {
    if (!empty($post['tags'])) {
        foreach ($post['tags'] as $tag) {
            $all_tags[$tag] = isset($all_tags[$tag]) ? $all_tags[$tag] + 1 : 1;
        }
    }
}
uksort($all_tags, function($a, $b) {
    return strcmp(mb_convert_encoding($a, 'UTF-32', 'UTF-8'),
                  mb_convert_encoding($b, 'UTF-32', 'UTF-8'));
});

if ($detail_post) {
    /* Zenn記事タイトルをdescriptionに活用 */
    $zenn_titles_for_desc = array();
    if (!empty($detail_post['zenn_articles'])) {
        foreach ($detail_post['zenn_articles'] as $ma) {
            $zenn_titles_for_desc[] = $ma['article']['title'];
            if (count($zenn_titles_for_desc) >= 3) { break; }
        }
    }
    $base_desc    = mb_substr(strip_tags($detail_post['post_text']), 0, 80);
    $zenn_desc    = !empty($zenn_titles_for_desc) ? '関連Zenn記事: ' . implode('、', $zenn_titles_for_desc) : '';
    $full_desc    = $base_desc . ($zenn_desc ? ' ' . mb_substr($zenn_desc, 0, 80) : '');

    $page_title       = htmlspecialchars($detail_post['title']) . ' | OSSZenn';
    $page_description = htmlspecialchars(mb_substr($full_desc, 0, 160));
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['id']);
    $page_type        = 'article';
    $published_time   = isset($detail_post['created_at']) ? $detail_post['created_at'] : '';
    $keywords         = !empty($detail_post['tags'])
        ? implode(', ', $detail_post['tags']) . ', Zenn, OSS, GitHub, AI'
        : 'Zenn, OSS, GitHub, AI';

    /* Zenn記事著者のXアカウントを収集 */
    $zenn_authors = array();
    if (!empty($detail_post['zenn_articles'])) {
        foreach ($detail_post['zenn_articles'] as $ma) {
            $zenn_authors[] = '@' . $ma['account'];
        }
    }

    /* JSON-LD: TechArticle + relatedLink */
    $jsonld_mentions = array();
    if (!empty($detail_post['zenn_articles'])) {
        foreach ($detail_post['zenn_articles'] as $ma) {
            $jsonld_mentions[] = array(
                '@type' => 'Article',
                'name'  => $ma['article']['title'],
                'url'   => $ma['article']['link'],
                'author'=> array('@type' => 'Person', 'name' => $ma['account']),
            );
        }
    }
    $jsonld = array(
        '@context'      => 'https://schema.org',
        '@type'         => 'TechArticle',
        'headline'      => $detail_post['title'] . ' | OSSZenn',
        'description'   => mb_substr($full_desc, 0, 160),
        'url'           => $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_post['id']),
        'datePublished' => isset($detail_post['created_at']) ? $detail_post['created_at'] : '',
        'dateModified'  => date('Y-m-d'),
        'author'        => array('@type' => 'Person', 'name' => 'xb_bittensor'),
        'publisher'     => array('@type' => 'Organization', 'name' => 'OSSZenn',
                                 'url'   => $BASE_URL . '/osszenn.php'),
        'keywords'      => $keywords,
        'sameAs'        => isset($detail_post['github_url']) ? $detail_post['github_url'] : '',
        'about'         => array('@type' => 'SoftwareApplication', 'name' => $detail_post['title'],
                                 'url'   => isset($detail_post['github_url']) ? $detail_post['github_url'] : ''),
        'mentions'      => $jsonld_mentions,
    );
} elseif ($filter_tag) {
    $page_title       = '#' . htmlspecialchars($filter_tag) . ' の OSS一覧 | OSSZenn';
    $page_description = htmlspecialchars($filter_tag) . ' に関連するAI系OSSプロジェクトとZenn記事の一覧です。';
    $page_url         = $BASE_URL . '/' . $THIS_FILE . '?tag=' . urlencode($filter_tag);
    $page_type        = 'website';
    $published_time   = '';
    $keywords         = htmlspecialchars($filter_tag) . ', AI, OSS, GitHub, Zenn';
    $jsonld = array(
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        'name'        => $page_title,
        'description' => $page_description,
        'url'         => $page_url,
        'publisher'   => array('@type' => 'Organization', 'name' => 'OSSZenn', 'url' => $BASE_URL . '/osszenn.php')
    );
} else {
    $page_title       = 'OSSZenn | AI系OSSとZenn記事まとめ';
    $page_description = 'GitHub TrendingのAI系OSSに関連するZenn記事と投稿者まとめ。Zenn最新記事順で表示。毎日更新。';
    $page_url         = $BASE_URL . '/' . $THIS_FILE;
    $page_type        = 'website';
    $published_time   = '';
    $keywords         = 'OSSZenn, AI, OSS, GitHub, Zenn, オープンソース, 機械学習, LLM, エージェント';
    $jsonld = array(
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        'name'        => $page_title,
        'description' => $page_description,
        'url'         => $page_url,
        'publisher'   => array('@type' => 'Organization', 'name' => 'OSSZenn', 'url' => $BASE_URL . '/osszenn.php')
    );
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $page_title; ?></title>
<meta name="description" content="<?php echo $page_description; ?>">
<meta name="keywords" content="<?php echo $keywords; ?>">
<meta name="author" content="xb_bittensor">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo $page_url; ?>">
<meta property="og:type" content="<?php echo $page_type; ?>">
<meta property="og:title" content="<?php echo $page_title; ?>">
<meta property="og:description" content="<?php echo $page_description; ?>">
<meta property="og:url" content="<?php echo $page_url; ?>">
<meta property="og:site_name" content="<?php echo $SITE_NAME; ?>">
<meta property="og:locale" content="ja_JP">
<?php if ($detail_post && $published_time): ?>
<meta property="article:published_time" content="<?php echo $published_time; ?>">
<meta property="article:author" content="xb_bittensor">
<?php if (!empty($detail_post['tags'])): ?>
<?php foreach ($detail_post['tags'] as $tag): ?>
<meta property="article:tag" content="<?php echo htmlspecialchars($tag); ?>">
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
<meta name="twitter:card" content="summary">
<meta name="twitter:site" content="@xb_bittensor">
<meta name="twitter:title" content="<?php echo $page_title; ?>">
<meta name="twitter:description" content="<?php echo $page_description; ?>">
<script type="application/ld+json">
<?php echo json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>
</script>
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
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #fff; color: #222; font-family: -apple-system, 'Helvetica Neue', sans-serif; }

.header {
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    padding: 14px 20px;
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    gap: 12px;
}
.header h1 { font-size: 17px; font-weight: 700; color: #111; }
.header .badge { background: #6c63ff; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.header .badge-zenn { background: #3ea8ff; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.header a { text-decoration: none; color: inherit; }
.back-btn {
    margin-left: auto;
    font-size: 13px;
    color: #6c63ff;
    text-decoration: none;
    padding: 5px 12px;
    border: 1px solid #6c63ff;
    border-radius: 6px;
}
.back-btn:hover { background: #f0eeff; }
.userbar { display: flex; align-items: center; gap: .75rem; font-size: .8rem; margin-left: auto; }
.userbar strong { color: #059669; }
.btn-sm { border: 1px solid #cbd5e1; padding: 3px 10px; border-radius: 4px; color: #64748b; text-decoration: none; font-size: .75rem; }
.btn-sm:hover { border-color: #dc2626; color: #dc2626; }
.btn-login-sm { border: 1px solid #6c63ff; padding: 4px 12px; border-radius: 4px; color: #6c63ff; text-decoration: none; font-size: .75rem; }
.btn-login-sm:hover { background: #f0eeff; }

/* 管理者フォーム */
.admin-form {
    background: #f7f6ff;
    border-bottom: 2px solid #6c63ff;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.admin-form-label { font-size: 12px; color: #6c63ff; font-weight: 700; white-space: nowrap; }
.admin-form input[type=text] {
    flex: 1;
    min-width: 200px;
    border: 1px solid #c0b8f0;
    border-radius: 6px;
    padding: 7px 12px;
    font-size: 13px;
    outline: none;
}
.admin-form input[type=text]:focus { border-color: #6c63ff; }
.admin-register-btn {
    background: #6c63ff;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 7px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s;
}
.admin-register-btn:hover { background: #5a52d5; }
.admin-register-btn:disabled { background: #bbb; cursor: not-allowed; }
.admin-status { font-size: 12px; padding: 4px 10px; border-radius: 4px; display: none; }
.admin-status.ok { background: #dcfce7; color: #166534; display: inline-block; }
.admin-status.err { background: #fee2e2; color: #991b1b; display: inline-block; }
.admin-status.loading { background: #f0eeff; color: #6c63ff; display: inline-block; }

.tag-filter {
    background: #fafafa;
    border-bottom: 1px solid #f0f0f0;
    padding: 10px 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}
.tag-filter-label { font-size: 12px; color: #888; margin-right: 2px; white-space: nowrap; }
.tag-btn {
    background: #f0f0f0;
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    padding: 3px 12px;
    font-size: 12px;
    color: #555;
    text-decoration: none;
    display: inline-block;
    transition: all 0.15s;
}
.tag-btn:hover { border-color: #6c63ff; color: #6c63ff; }
.tag-btn.active { background: #6c63ff; border-color: #6c63ff; color: #fff; }

.container { max-width: 640px; margin: 0 auto; padding: 0 0 80px; }
.count-bar { padding: 10px 20px; font-size: 13px; color: #888; border-bottom: 1px solid #f0f0f0; }

.post-card { border-bottom: 1px solid #f0f0f0; padding: 20px; transition: background 0.15s; }
.post-card:hover { background: #fafafa; }

.post-meta { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.avatar {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, #6c63ff, #3ecfcf);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 14px; color: #fff;
    flex-shrink: 0;
}
.author-name { font-weight: 700; color: #111; font-size: 14px; }
.author-handle { color: #888; font-size: 13px; }
.post-time { color: #aaa; font-size: 12px; margin-left: auto; }
.btn-group { display: flex; gap: 6px; flex-shrink: 0; }

.copy-btn {
    background: none;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 12px;
    color: #888;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.copy-btn:hover { border-color: #6c63ff; color: #6c63ff; }
.copy-btn.copied { border-color: #22c55e; color: #22c55e; }

.x-btn {
    background: #000;
    border: 1px solid #000;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 12px;
    color: #fff;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: background 0.15s;
    white-space: nowrap;
}
.x-btn:hover { background: #333; }

.post-title { font-size: 15px; font-weight: 700; color: #111; margin-bottom: 8px; }
.post-title a { color: #111; text-decoration: none; }
.post-title a:hover { color: #6c63ff; }

.post-text { font-size: 14px; line-height: 1.75; color: #333; margin-bottom: 12px; white-space: pre-wrap; }

.analysis-block {
    background: #f7f6ff;
    border-left: 3px solid #6c63ff;
    border-radius: 0 8px 8px 0;
    padding: 12px 14px;
    margin-bottom: 12px;
    font-size: 13px;
    line-height: 1.75;
    color: #444;
    white-space: pre-line;
}
.analysis-label { font-size: 11px; color: #6c63ff; font-weight: 700; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }

.github-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f5f5f5;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 7px 14px;
    text-decoration: none;
    color: #6c63ff;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.15s;
    margin-bottom: 12px;
    word-break: break-all;
}
.github-link:hover { background: #eeecff; border-color: #6c63ff; }

.detail-link {
    display: inline-flex;
    align-items: center;
    background: #f5f5f5;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 7px 14px;
    text-decoration: none;
    color: #888;
    font-size: 12px;
    transition: all 0.15s;
    margin-bottom: 12px;
    margin-left: 8px;
}
.detail-link:hover { background: #f0eeff; border-color: #6c63ff; color: #6c63ff; }

.tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.tag {
    background: #f0f0f0;
    color: #666;
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 20px;
    text-decoration: none;
    display: inline-block;
}
.tag:hover { background: #eeecff; color: #6c63ff; }

/* ========== Zennブロック ========== */
.zenn-block {
    margin-top: 14px;
    border: 1px solid #dbeafe;
    border-radius: 10px;
    overflow: hidden;
    background: #f0f7ff;
}
.zenn-block-header {
    background: #3ea8ff;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    padding: 7px 14px;
    display: flex;
    align-items: center;
    gap: 6px;
    letter-spacing: 0.3px;
}
.zenn-article-row {
    padding: 10px 14px;
    border-bottom: 1px solid #dbeafe;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.zenn-article-row:last-child { border-bottom: none; }
.zenn-article-link {
    font-size: 13px;
    font-weight: 600;
    color: #1a56db;
    text-decoration: none;
    line-height: 1.5;
}
.zenn-article-link:hover { text-decoration: underline; color: #1e40af; }
.zenn-article-meta-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.zenn-account-link {
    font-size: 11px;
    color: #3ea8ff;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.zenn-account-link:hover { text-decoration: underline; }
.zenn-x-link {
    font-size: 11px;
    color: #555;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #f0f0f0;
    border-radius: 4px;
    padding: 1px 6px;
}
.zenn-x-link:hover { background: #e0e0e0; color: #111; }
.zenn-article-date { font-size: 11px; color: #888; }
.zenn-article-liked { font-size: 11px; color: #e87777; }
.zenn-no-match {
    padding: 10px 14px;
    font-size: 12px;
    color: #93c5fd;
    font-style: italic;
}

.empty { text-align: center; color: #bbb; padding: 80px 20px; font-size: 15px; }

.detail-header { padding: 24px 20px 16px; border-bottom: 1px solid #f0f0f0; }
.detail-header h1 { font-size: 20px; font-weight: 700; color: #111; margin-bottom: 8px; }
.detail-meta { font-size: 13px; color: #888; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.detail-body { padding: 20px; }
.detail-url-box {
    background: #f7f6ff;
    border: 1px solid #e0dcff;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
    font-size: 13px;
    color: #555;
    word-break: break-all;
}
.detail-url-box a { color: #6c63ff; }
.detail-section-title { font-size: 12px; font-weight: 700; color: #6c63ff; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; margin-top: 20px; }
.detail-zenn-title { font-size: 12px; font-weight: 700; color: #3ea8ff; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; margin-top: 20px; }
.detail-post-text { font-size: 15px; line-height: 1.8; color: #222; white-space: pre-wrap; margin-bottom: 8px; }
.detail-analysis {
    background: #f7f6ff;
    border-left: 3px solid #6c63ff;
    border-radius: 0 8px 8px 0;
    padding: 14px 16px;
    font-size: 14px;
    line-height: 1.8;
    color: #444;
    white-space: pre-line;
}
.detail-zenn-block {
    border: 1px solid #dbeafe;
    border-radius: 10px;
    overflow: hidden;
    background: #f0f7ff;
    margin-top: 8px;
}
.detail-zenn-article-row {
    padding: 12px 16px;
    border-bottom: 1px solid #dbeafe;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.detail-zenn-article-row:last-child { border-bottom: none; }
.detail-zenn-article-link {
    font-size: 14px;
    font-weight: 600;
    color: #1a56db;
    text-decoration: none;
    line-height: 1.5;
}
.detail-zenn-article-link:hover { text-decoration: underline; }
.detail-btn-group { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
.detail-copy-btn {
    background: #6c63ff;
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-size: 14px;
    color: #fff;
    cursor: pointer;
    transition: background 0.15s;
}
.detail-copy-btn:hover { background: #5a52d5; }
.detail-copy-btn.copied { background: #22c55e; }
.detail-x-btn {
    background: #000;
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-size: 14px;
    color: #fff;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s;
}
.detail-x-btn:hover { background: #333; }

#copy-toast {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    background: #111;
    color: #fff;
    padding: 10px 22px;
    border-radius: 20px;
    font-size: 13px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
    z-index: 999;
}
#copy-toast.show { opacity: 1; }
</style>
</head>
<body>

<div class="header">
    <div style="font-size:22px">🦉</div>
    <?php if ($detail_post): ?>
    <h1 style="font-size:17px;font-weight:700;color:#111;"><a href="osszenn.php" style="text-decoration:none;color:inherit;">OSSZenn</a></h1>
    <span class="badge-zenn">Zenn</span>
    <a class="back-btn" href="osszenn.php">← 一覧</a>
    <?php elseif ($filter_tag): ?>
    <h1 style="font-size:17px;font-weight:700;color:#111;"><a href="osszenn.php" style="text-decoration:none;color:inherit;">OSSZenn</a></h1>
    <span class="badge">#<?php echo htmlspecialchars($filter_tag); ?></span>
    <span class="badge-zenn">Zenn</span>
    <a class="back-btn" href="osszenn.php">← 一覧</a>
    <?php else: ?>
    <h1>OSSZenn</h1>
    <span class="badge">AI</span>
    <span class="badge-zenn">Zenn</span>
    <?php endif; ?>
    <div class="userbar">
        <?php if ($logged_in): ?>
        <strong>@<?php echo htmlspecialchars($session_user); ?></strong>
        <a href="?oss_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?oss_login=1" class="btn-login-sm">X でログイン</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_admin && !$detail_post): ?>
<!-- ========== 管理者：手動登録フォーム ========== -->
<div class="admin-form">
    <span class="admin-form-label">🔧 手動登録</span>
    <input type="text" id="admin-url-input" placeholder="https://github.com/user/repo">
    <button class="admin-register-btn" id="admin-register-btn" onclick="adminRegister()">登録</button>
    <span class="admin-status" id="admin-status"></span>
</div>
<?php endif; ?>

<?php if ($detail_post): ?>
<!-- ========== 詳細ページ ========== -->
<div class="container">
    <div class="detail-header">
        <h1><?php echo htmlspecialchars($detail_post['title']); ?></h1>
        <div class="detail-meta">
            <span style="background:#3ea8ff;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:700;">OSSZenn</span>
            <?php if (!empty($detail_post['zenn_articles'])): ?>
            <span style="color:#3ea8ff;font-size:12px;">📘 Zenn記事 <?php echo count($detail_post['zenn_articles']); ?>件</span>
            <?php endif; ?>
            <span>@<?php echo htmlspecialchars($detail_post['author']); ?></span>
            <span><?php echo htmlspecialchars($detail_post['created_at']); ?></span>
        </div>
    </div>
    <div class="detail-body">

        <div class="detail-url-box">
            🔗 GitHub: <a href="<?php echo htmlspecialchars($detail_post['github_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($detail_post['github_url']); ?></a>
        </div>

        <?php if (!empty($detail_post['post_text'])): ?>
        <div class="detail-section-title">📢 X投稿文</div>
        <div class="detail-post-text"><?php echo htmlspecialchars($detail_post['post_text']); ?></div>
        <?php endif; ?>

        <!-- ===== Zenn関連記事（詳細ページ） ===== -->
        <?php if (!empty($detail_post['zenn_articles'])): ?>
        <div class="detail-zenn-title">📘 関連 Zenn 記事</div>
        <div class="detail-zenn-block">
            <?php foreach ($detail_post['zenn_articles'] as $ma): ?>
            <?php $art = $ma['article']; ?>
            <div class="detail-zenn-article-row">
                <a class="detail-zenn-article-link" href="<?php echo htmlspecialchars($art['link']); ?>" target="_blank" rel="noopener">
                    <?php echo htmlspecialchars($art['title']); ?>
                </a>
                <div class="zenn-article-meta-row">
                    <a class="zenn-account-link" href="https://zenn.dev/<?php echo htmlspecialchars($ma['zenn_username']); ?>" target="_blank" rel="noopener">
                        📘 <?php echo htmlspecialchars($ma['zenn_username']); ?>
                    </a>
                    <a class="zenn-x-link" href="https://x.com/<?php echo htmlspecialchars($ma['account']); ?>" target="_blank" rel="noopener">
                        𝕏 @<?php echo htmlspecialchars($ma['account']); ?>
                    </a>
                    <?php if ($art['pubDate']): ?>
                    <span class="zenn-article-date"><?php echo htmlspecialchars($art['pubDate']); ?></span>
                    <?php endif; ?>
                    <?php if ($art['liked'] > 0): ?>
                    <span class="zenn-article-liked">♥ <?php echo intval($art['liked']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($detail_post['tags'])): ?>
        <div class="detail-section-title">タグ</div>
        <div class="tags" style="margin-top:8px;">
            <?php foreach ($detail_post['tags'] as $tag): ?>
            <a class="tag" href="osszenn.php?tag=<?php echo urlencode($tag); ?>" rel="tag">#<?php echo htmlspecialchars($tag); ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="detail-btn-group">
            <button class="detail-copy-btn" onclick="copyDetail()">📋 コピー</button>
            <a class="detail-x-btn" id="detail-x-link" href="#" target="_blank" rel="noopener">𝕏 Xに投稿</a>
        </div>

    </div>
</div>

<script>
var detailPost    = <?php echo json_encode($detail_post, JSON_UNESCAPED_UNICODE); ?>;
var detailPageUrl = '<?php echo $BASE_URL; ?>/osszenn.php?id=<?php echo urlencode($detail_post['id']); ?>';

function buildDetailText(post) {
    var lines = [];
    lines.push(post.title);
    lines.push('');
    if (post.post_text) {
        var textOnly = post.post_text.replace(/https?:\/\/\S+/g, '').trim();
        if (textOnly) lines.push(textOnly);
    }
    lines.push('');
    lines.push(post.github_url);
    lines.push(detailPageUrl);
    if (post.zenn_articles && post.zenn_articles.length) {
        lines.push('');
        lines.push('Zenn記事');
        for (var i = 0; i < post.zenn_articles.length; i++) {
            var ma = post.zenn_articles[i];
            lines.push(ma.article.title + ' @' + ma.account);
            lines.push(ma.article.link);
            lines.push('');
        }
    }
    return lines.join('\n');
}

function buildXText(post) {
    var lines = [];
    if (post.post_text) {
        var textOnly = post.post_text.replace(/https?:\/\/\S+/g, '').trim();
        if (textOnly) lines.push(textOnly);
    }
    lines.push(detailPageUrl);
    return lines.join('\n');
}

function copyDetail() {
    var text = buildDetailText(detailPost);
    navigator.clipboard.writeText(text).then(function() {
        var btn = document.querySelector('.detail-copy-btn');
        btn.textContent = '✓ コピー済';
        btn.classList.add('copied');
        setTimeout(function() {
            btn.textContent = '📋 コピー';
            btn.classList.remove('copied');
        }, 2000);
        showToast('コピーしました');
    });
}

(function() {
    var xText = buildXText(detailPost);
    document.getElementById('detail-x-link').href =
        'https://twitter.com/intent/tweet?text=' + encodeURIComponent(xText);
})();

function showToast(msg) {
    var t = document.getElementById('copy-toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
</script>

<?php else: ?>
<!-- ========== 一覧ページ ========== -->
<?php
$filtered_posts = $posts;
if ($filter_tag) {
    $filtered_posts = array_filter($posts, function($p) use ($filter_tag) {
        return !empty($p['tags']) && in_array($filter_tag, $p['tags']);
    });
    $filtered_posts = array_values($filtered_posts);
}
?>

<div id="tag-filter-area"></div>

<div class="container">

<div class="count-bar" id="count-bar">
    <?php echo count($filtered_posts); ?> posts (読み込み中...)
    <?php if ($filter_tag): ?>
    — #<?php echo htmlspecialchars($filter_tag); ?>
    <?php endif; ?>
    <span style="margin-left:8px;font-size:11px;color:#3ea8ff;">📘 Zenn関連記事付き</span>
</div>

<div id="post-list"></div>
<div id="load-sentinel" style="height:1px;"></div>
<div id="load-indicator" style="display:none;text-align:center;padding:16px;font-size:13px;color:#888;">読み込み中...</div>

</div>

<!-- ローディングオーバーレイ -->
<div id="zenn-overlay" style="
    display:none;
    position:fixed;top:0;left:0;right:0;bottom:0;
    background:rgba(255,255,255,0.85);
    z-index:200;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:14px;
">
    <div style="font-size:32px;">📘</div>
    <div style="font-size:14px;color:#3ea8ff;font-weight:700;">Zenn記事を収集中...</div>
    <div style="font-size:12px;color:#888;">キャッシュがない場合は少し時間がかかります</div>
</div>

<script>
var allPosts = <?php echo json_encode(array_values($filtered_posts), JSON_UNESCAPED_UNICODE); ?>;
var posts    = []; /* Zennマッチ確定後に入る */
var BASE_URL = '<?php echo $BASE_URL; ?>';
var PAGE_SIZE = 30;
var currentPage = 0;
var zennLoaded  = false;

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderZennBlock(post) {
    if (!post.zenn_articles || post.zenn_articles.length === 0) { return ''; }
    var html = '<div class="zenn-block">'
        + '<div class="zenn-block-header">📘 関連 Zenn 記事 <span style="font-weight:400;opacity:0.85;">(' + post.zenn_articles.length + '件)</span></div>';
    for (var i = 0; i < post.zenn_articles.length; i++) {
        var ma  = post.zenn_articles[i];
        var art = ma.article;
        html += '<div class="zenn-article-row">'
            + '<a class="zenn-article-link" href="' + esc(art.link) + '" target="_blank" rel="noopener">' + esc(art.title) + '</a>'
            + '<div class="zenn-article-meta-row">'
            + '<a class="zenn-account-link" href="https://zenn.dev/' + esc(ma.zenn_username) + '" target="_blank" rel="noopener">📘 ' + esc(ma.zenn_username) + '</a>'
            + '<a class="zenn-x-link" href="https://x.com/' + esc(ma.account) + '" target="_blank" rel="noopener">𝕏 @' + esc(ma.account) + '</a>'
            + (art.pubDate ? '<span class="zenn-article-date">' + esc(art.pubDate) + '</span>' : '')
            + (art.liked > 0 ? '<span class="zenn-article-liked">♥ ' + parseInt(art.liked) + '</span>' : '')
            + '</div>'
            + '</div>';
    }
    html += '</div>';
    return html;
}

function renderPosts(from, to) {
    var list = document.getElementById('post-list');
    for (var i = from; i < to && i < posts.length; i++) {
        var post = posts[i];
        var idx  = i;
        var tags = '';
        if (post.tags && post.tags.length) {
            for (var t = 0; t < post.tags.length; t++) {
                tags += '<a class="tag" href="osszenn.php?tag=' + encodeURIComponent(post.tags[t]) + '" rel="tag">#' + esc(post.tags[t]) + '</a>';
            }
        }
        var postText  = post.post_text ? '<div class="post-text">' + esc(post.post_text) + '</div>' : '';
        var zennBlock = renderZennBlock(post);
        var html = '<div class="post-card" data-idx="' + idx + '" data-id="' + esc(post.id) + '">'
            + '<div class="post-meta">'
            + '<div class="avatar">X</div>'
            + '<div><div class="author-name">' + esc(post.author) + '</div><div class="author-handle">@' + esc(post.author) + '</div></div>'
            + '<div class="post-time">' + (post.zenn_latest || esc(post.created_at)) + '</div>'
            + '<div class="btn-group">'
            + '<button class="copy-btn" onclick="copyPost(' + idx + ')">コピー</button>'
            + '<a class="x-btn" id="x-btn-' + idx + '" href="#" target="_blank" rel="noopener">𝕏</a>'
            + '</div></div>'
            + '<div class="post-title"><a href="osszenn.php?id=' + encodeURIComponent(post.id) + '">' + esc(post.title) + '</a></div>'
            + postText
            + zennBlock
            + '<a class="github-link" href="' + esc(post.github_url) + '" target="_blank" rel="noopener">⌥ ' + esc(post.github_url) + '</a>'
            + '<a class="detail-link" href="osszenn.php?id=' + encodeURIComponent(post.id) + '">🔖 詳細</a>'
            + (tags ? '<div class="tags">' + tags + '</div>' : '')
            + '</div>';
        list.insertAdjacentHTML('beforeend', html);
        var xLink = document.getElementById('x-btn-' + idx);
        if (xLink) { xLink.href = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(buildXText(post)); }
    }
    currentPage++;
}

function loadMore() {
    var from = currentPage * PAGE_SIZE;
    if (from >= posts.length) {
        document.getElementById('load-indicator').style.display = 'none';
        return;
    }
    renderPosts(from, from + PAGE_SIZE);
}

/* IntersectionObserver で最下部検知 */
var sentinel = document.getElementById('load-sentinel');
var observer = new IntersectionObserver(function(entries) {
    if (entries[0].isIntersecting && zennLoaded) {
        document.getElementById('load-indicator').style.display = 'block';
        setTimeout(function() {
            loadMore();
            if (currentPage * PAGE_SIZE >= posts.length) {
                document.getElementById('load-indicator').style.display = 'none';
            }
        }, 200);
    }
}, { rootMargin: '200px' });
observer.observe(sentinel);

/* Zennデータ取得後にpostsを確定してレンダリング */
function applyZennData(zennData) {
    var matched = [];
    for (var i = 0; i < allPosts.length; i++) {
        var post = allPosts[i];
        var articles = zennData[post.id];
        if (!articles || articles.length === 0) { continue; }
        post.zenn_articles = articles.sort(function(a, b) {
            return (b.article.pubDate || '') > (a.article.pubDate || '') ? 1 : -1;
        });
        /* Zenn記事の中で最新のpubDateをソートキーに */
        var latest = '';
        for (var j = 0; j < articles.length; j++) {
            var d = articles[j].article.pubDate || '';
            if (d > latest) { latest = d; }
        }
        post.zenn_latest = latest;
        matched.push(post);
    }
    /* Zenn最新日の降順ソート */
    matched.sort(function(a, b) {
        return (b.zenn_latest || '') > (a.zenn_latest || '') ? 1 : -1;
    });
    posts = matched;

    /* タグ集計（Zennマッチがあるpostのみ対象）してフィルターを描画 */
    var currentTag = '<?php echo addslashes($filter_tag); ?>';
    var tagCount = {};
    for (var i = 0; i < posts.length; i++) {
        var ptags = posts[i].tags || [];
        for (var t = 0; t < ptags.length; t++) {
            tagCount[ptags[t]] = (tagCount[ptags[t]] || 0) + 1;
        }
    }
    var tagKeys = Object.keys(tagCount).sort();
    var filterArea = document.getElementById('tag-filter-area');
    if (filterArea && tagKeys.length > 0) {
        var fhtml = '<div class="tag-filter"><span class="tag-filter-label">タグ:</span>'
            + '<a class="tag-btn' + (!currentTag ? ' active' : '') + '" href="osszenn.php">すべて</a>';
        for (var k = 0; k < tagKeys.length; k++) {
            var tg = tagKeys[k];
            fhtml += '<a class="tag-btn' + (currentTag === tg ? ' active' : '') + '" href="osszenn.php?tag=' + encodeURIComponent(tg) + '" rel="tag">'
                + '#' + esc(tg) + ' <span style="opacity:0.6">' + tagCount[tg] + '</span></a>';
        }
        fhtml += '</div>';
        filterArea.innerHTML = fhtml;
    }

    /* count-bar更新 */
    var cb = document.getElementById('count-bar');
    if (cb) {
        cb.innerHTML = posts.length + ' posts'
            <?php if ($filter_tag): ?>
            + ' — #<?php echo htmlspecialchars($filter_tag); ?>'
            <?php endif; ?>
            + ' <span style="margin-left:8px;font-size:11px;color:#3ea8ff;">📘 Zenn最新順</span>';
    }

    if (posts.length === 0) {
        document.getElementById('post-list').innerHTML = '<div class="empty">Zenn記事が見つかりませんでした</div>';
    } else {
        loadMore();
    }
    zennLoaded = true;
}

/* オーバーレイ表示してからAjax */
document.getElementById('zenn-overlay').style.display = 'flex';
var xhr = new XMLHttpRequest();
xhr.open('GET', 'osszenn.php?action=zenn_data<?php echo $filter_tag ? "&tag=" . urlencode($filter_tag) : ""; ?>', true);
xhr.timeout = 60000;
xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    document.getElementById('zenn-overlay').style.display = 'none';
    if (xhr.status === 0) {
        /* タイムアウトまたはネットワークエラー */
        document.getElementById('post-list').innerHTML = '<div class="empty">Zenn取得タイムアウト。ページを再読み込みしてください。</div>';
        return;
    }
    try {
        var res = JSON.parse(xhr.responseText);
        if (res.ok) {
            applyZennData(res.data);
        } else {
            document.getElementById('post-list').innerHTML = '<div class="empty">Zennデータの取得に失敗しました</div>';
        }
    } catch(e) {
        document.getElementById('post-list').innerHTML = '<div class="empty">レスポンスエラー: ' + esc(xhr.responseText.substring(0, 300)) + '</div>';
    }
};
xhr.ontimeout = function() {
    document.getElementById('zenn-overlay').style.display = 'none';
    document.getElementById('post-list').innerHTML = '<div class="empty">Zenn取得タイムアウト。ページを再読み込みしてください。</div>';
};
xhr.onerror = function() {
    document.getElementById('zenn-overlay').style.display = 'none';
    document.getElementById('post-list').innerHTML = '<div class="empty">ネットワークエラーが発生しました。ページを再読み込みしてください。</div>';
};
xhr.send();

function getDetailUrl(post) {
    return BASE_URL + '/osszenn.php?id=' + encodeURIComponent(post.id);
}

function buildPostText(post) {
    var lines = [];
    lines.push(post.title);
    lines.push('');
    if (post.post_text) {
        var textOnly = post.post_text.replace(/https?:\/\/\S+/g, '').trim();
        if (textOnly) lines.push(textOnly);
    }
    lines.push('');
    lines.push(post.github_url);
    lines.push(getDetailUrl(post));
    if (post.zenn_articles && post.zenn_articles.length) {
        lines.push('');
        lines.push('Zenn記事');
        for (var i = 0; i < post.zenn_articles.length; i++) {
            var ma = post.zenn_articles[i];
            lines.push(ma.article.title + ' @' + ma.account);
            lines.push(ma.article.link);
            lines.push('');
        }
    }
    return lines.join('\n');
}

function buildXText(post) {
    var lines = [];
    if (post.post_text) {
        var textOnly = post.post_text.replace(/https?:\/\/\S+/g, '').trim();
        if (textOnly) lines.push(textOnly);
    }
    lines.push(getDetailUrl(post));
    return lines.join('\n');
}

function copyPost(idx) {
    var post = posts[idx];
    if (!post) return;
    navigator.clipboard.writeText(buildPostText(post)).then(function() {
        var btn = document.querySelector('[data-idx="' + idx + '"] .copy-btn');
        if (btn) {
            btn.textContent = '✓ コピー済';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = 'コピー';
                btn.classList.remove('copied');
            }, 2000);
        }
        showToast('コピーしました');
    });
}

<?php if ($is_admin): ?>
function adminRegister() {
    var urlInput = document.getElementById('admin-url-input');
    var btn      = document.getElementById('admin-register-btn');
    var status   = document.getElementById('admin-status');
    var url      = urlInput.value.trim();

    if (!url || url.indexOf('github.com/') === -1) {
        status.textContent = 'GitHubのURLを入力してください';
        status.className   = 'admin-status err';
        return;
    }

    btn.disabled       = true;
    status.textContent = 'AI考察生成中... (1〜2分かかります)';
    status.className   = 'admin-status loading';

    var xhr2 = new XMLHttpRequest();
    xhr2.open('POST', 'saveoss.php', true);
    xhr2.setRequestHeader('Content-Type', 'application/json');
    xhr2.onreadystatechange = function() {
        if (xhr2.readyState !== 4) return;
        btn.disabled = false;
        try {
            var res = JSON.parse(xhr2.responseText);
            if (res.status === 'ok' || res.status === 'updated') {
                var msg = res.status === 'updated' ? '更新完了: ' : '登録完了: ';
                status.textContent = msg + res.title;
                status.className   = 'admin-status ok';
                urlInput.value     = '';
                setTimeout(function() { location.href = 'oss.php'; }, 1500);
            } else if (res.status === 'duplicate') {
                status.textContent = '既に登録済みです';
                status.className   = 'admin-status err';
            } else {
                status.textContent = 'エラー: ' + (res.error || '不明');
                status.className   = 'admin-status err';
            }
        } catch(e) {
            status.textContent = '通信エラー';
            status.className   = 'admin-status err';
        }
    };
    xhr2.send(JSON.stringify({ action: 'manual_register', github_url: url }));
}

document.getElementById('admin-url-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') adminRegister();
});
<?php endif; ?>

function showToast(msg) {
    var t = document.getElementById('copy-toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
</script>

<?php endif; ?>

<div id="copy-toast">コピーしました</div>
</body>
</html>
