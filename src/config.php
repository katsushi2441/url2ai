<?php
/**
 * config.php — 設定ファイル読み込み
 * 各PHPファイルの先頭で require_once __DIR__ . '/config.php'; して使用
 */

function aigm_load_config($yaml_path) {
    if (!file_exists($yaml_path)) { return array(); }
    $lines  = file($yaml_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = array();
    $section = '';
    $subsection = '';
    foreach ($lines as $line) {
        /* コメント・空行スキップ */
        if (preg_match('/^\s*#/', $line) || trim($line) === '') { continue; }
        /* セクション（インデントなし key:） */
        if (preg_match('/^(\w+):\s*$/', $line, $m)) {
            $section    = $m[1];
            $subsection = '';
            if (!isset($config[$section])) { $config[$section] = array(); }
            continue;
        }
        /* サブセクション（インデント2 key:） */
        if (preg_match('/^  (\w+):\s*$/', $line, $m)) {
            $subsection = $m[1];
            if (!isset($config[$section][$subsection])) { $config[$section][$subsection] = array(); }
            continue;
        }
        /* 値（インデント4 key: value） */
        if (preg_match('/^    (\w+):\s*(.+)$/', $line, $m)) {
            $config[$section][$subsection][$m[1]] = trim($m[2]);
            continue;
        }
        /* 値（インデント2 key: value） */
        if (preg_match('/^  (\w+):\s*(.+)$/', $line, $m)) {
            $config[$section][$m[1]] = trim($m[2]);
            continue;
        }
        /* 値（インデントなし key: value） */
        if (preg_match('/^(\w+):\s*(.+)$/', $line, $m)) {
            $config[$m[1]] = trim($m[2]);
        }
    }
    return $config;
}

$_aigm_config = aigm_load_config(__DIR__ . '/config.yaml');

/* Ollama */
define('OLLAMA_API',   isset($_aigm_config['ollama']['api_url'])       ? $_aigm_config['ollama']['api_url']       : 'https://exbridge.ddns.net/api/generate');
define('OLLAMA_MODEL', isset($_aigm_config['ollama']['default_model'])  ? $_aigm_config['ollama']['default_model']  : 'gemma4:e4b');

/* Site */
define('AIGM_BASE_URL',      isset($_aigm_config['site']['base_url'])      ? $_aigm_config['site']['base_url']      : 'https://aiknowledgecms.exbridge.jp');
define('AIGM_COOKIE_DOMAIN', isset($_aigm_config['site']['cookie_domain']) ? $_aigm_config['site']['cookie_domain'] : 'aiknowledgecms.exbridge.jp');
define('AIGM_ADMIN',         isset($_aigm_config['site']['admin'])         ? $_aigm_config['site']['admin']         : 'xb_bittensor');
define('AIGM_GTAG_ID',       isset($_aigm_config['site']['gtag_id'])       ? $_aigm_config['site']['gtag_id']       : '');

/* FinReport */
define('FINREPORT_API', isset($_aigm_config['finreport']['api_url']) ? $_aigm_config['finreport']['api_url'] : 'http://exbridge.ddns.net:8013/report');

/* Polymarket */
define('POLYMARKET_API', isset($_aigm_config['polymarket']['api_url']) ? $_aigm_config['polymarket']['api_url'] : 'http://exbridge.ddns.net:8014/report');

/* Paragraph */
define('PARAGRAPH_API_KEY', isset($_aigm_config['paragraph']['api_key']) ? $_aigm_config['paragraph']['api_key'] : '');
define('PARAGRAPH_PUBLICATION_SLUG', isset($_aigm_config['paragraph']['publication_slug']) ? $_aigm_config['paragraph']['publication_slug'] : '');
