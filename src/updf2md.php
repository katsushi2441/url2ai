<?php
session_start();
date_default_timezone_set('Asia/Tokyo');
require_once __DIR__ . '/config.php';

$SITE_NAME = 'UPDF2MD Demo';
$THIS_FILE = 'updf2md.php';
$PAGE_URL = AIGM_BASE_URL . '/' . $THIS_FILE;
$API_URL = getenv('UPDF2MD_API_URL') ?: 'http://exbridge.ddns.net:8010/pdf/convert';
$MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
$RESULT = null;
$ERROR_MESSAGE = '';
$DOWNLOAD_URL = '';
$PDF_URL_INPUT = '';

function udm_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function udm_random_hex($length) {
    $length = (int) $length;
    if ($length < 1) {
        $length = 16;
    }
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($length));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes($length, $strong);
        if ($bytes !== false) {
            return bin2hex($bytes);
        }
    }
    $buffer = '';
    while (strlen($buffer) < ($length * 2)) {
        $buffer .= md5(uniqid(mt_rand(), true));
    }
    return substr($buffer, 0, $length * 2);
}

function udm_slugify_filename($filename) {
    $filename = preg_replace('/\.[^.]+$/', '', $filename);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $filename);
    $filename = trim((string) $filename, '._-');
    if ($filename === '') {
        $filename = 'document';
    }
    return substr($filename, 0, 80);
}

function udm_format_bytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = array('KB', 'MB', 'GB');
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'GB') {
            return number_format($value, $unit === 'KB' ? 0 : 2) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}

function udm_cleanup_downloads() {
    if (!isset($_SESSION['updf2md_downloads']) || !is_array($_SESSION['updf2md_downloads'])) {
        $_SESSION['updf2md_downloads'] = array();
        return;
    }
    $now = time();
    foreach ($_SESSION['updf2md_downloads'] as $token => $item) {
        $expired = !isset($item['expires']) || (int) $item['expires'] < $now;
        $missing = empty($item['path']) || !is_file($item['path']);
        if ($expired || $missing) {
            if (!empty($item['path']) && is_file($item['path'])) {
                @unlink($item['path']);
            }
            unset($_SESSION['updf2md_downloads'][$token]);
        }
    }
}

function udm_store_markdown_download($originalName, $markdown) {
    udm_cleanup_downloads();
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'updf2md_demo';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $token = udm_random_hex(16);
    $base = udm_slugify_filename($originalName);
    $path = $dir . DIRECTORY_SEPARATOR . $token . '.md';
    file_put_contents($path, (string) $markdown);
    $_SESSION['updf2md_downloads'][$token] = array(
        'path' => $path,
        'filename' => $base . '.md',
        'expires' => time() + 3600,
    );
    return $token;
}

function udm_handle_download() {
    if (!isset($_GET['download'])) {
        return;
    }
    udm_cleanup_downloads();
    $token = preg_replace('/[^a-f0-9]/', '', (string) $_GET['download']);
    if ($token === '' || empty($_SESSION['updf2md_downloads'][$token])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Download not found or expired.';
        exit;
    }
    $item = $_SESSION['updf2md_downloads'][$token];
    if (empty($item['path']) || !is_file($item['path'])) {
        unset($_SESSION['updf2md_downloads'][$token]);
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Download file not found.';
        exit;
    }
    $asciiFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $item['filename']);
    if ($asciiFilename === '') {
        $asciiFilename = 'document.md';
    }
    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Length: ' . filesize($item['path']));
    header('Content-Disposition: attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($item['filename']));
    readfile($item['path']);
    exit;
}

function udm_call_api($apiUrl, $tmpFilePath, $originalName, $pages) {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is not available on this server.');
    }

    $postFields = array(
        'file' => new CURLFile($tmpFilePath, 'application/pdf', $originalName),
        'include_markdown' => 'true',
        'save_output' => 'false',
    );
    if ($pages !== '') {
        $postFields['pages'] = $pages;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('API request failed: ' . $curlErr);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('API returned an invalid JSON response.');
    }
    if ($httpCode >= 400) {
        $detail = isset($decoded['detail']) ? $decoded['detail'] : ('HTTP ' . $httpCode);
        throw new RuntimeException('Conversion API error: ' . $detail);
    }
    return $decoded;
}

function udm_resolve_remote_filename($url, $contentType) {
    $filename = '';
    $path = parse_url((string) $url, PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
        $filename = basename($path);
    }
    $filename = trim((string) $filename);
    if ($filename === '' || strpos($filename, '.') === false) {
        $filename = 'remote-document.pdf';
    }
    if (strtolower(substr($filename, -4)) !== '.pdf' && stripos((string) $contentType, 'pdf') !== false) {
        $filename .= '.pdf';
    }
    return $filename;
}

function udm_fetch_remote_pdf($url, $maxBytes) {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is not available on this server.');
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'updf2md_url_');
    if ($tmpPath === false) {
        throw new RuntimeException('Failed to allocate temporary file.');
    }

    $handle = fopen($tmpPath, 'wb');
    if ($handle === false) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to open temporary file for remote PDF.');
    }

    $meta = array(
        'content_type' => '',
        'downloaded_bytes' => 0,
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_FILE => $handle,
        CURLOPT_FAILONERROR => false,
        CURLOPT_HEADER => false,
        CURLOPT_USERAGENT => 'UPDF2MD/1.0',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($maxBytes) {
            if ($downloaded > $maxBytes) {
                return 1;
            }
            return 0;
        },
        CURLOPT_HEADERFUNCTION => function ($resource, $header) use (&$meta, $maxBytes) {
            $length = strlen($header);
            $header = trim($header);
            if ($header === '') {
                return $length;
            }
            if (stripos($header, 'Content-Type:') === 0) {
                $meta['content_type'] = trim(substr($header, 13));
            } elseif (stripos($header, 'Content-Length:') === 0) {
                $contentLength = (int) trim(substr($header, 15));
                if ($contentLength > $maxBytes) {
                    return -1;
                }
            }
            return $length;
        },
    ));

    $ok = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    fclose($handle);
    clearstatcache(true, $tmpPath);
    $meta['downloaded_bytes'] = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
    curl_close($ch);

    if ($ok === false) {
        @unlink($tmpPath);
        if ($curlErr === 'Callback aborted') {
            throw new RuntimeException('Remote PDF exceeds the upload limit of ' . udm_format_bytes($maxBytes) . '.');
        }
        throw new RuntimeException('Failed to fetch PDF URL: ' . $curlErr);
    }
    if ($httpCode >= 400) {
        @unlink($tmpPath);
        throw new RuntimeException('Remote server returned HTTP ' . $httpCode . '.');
    }
    if ($meta['downloaded_bytes'] <= 0) {
        @unlink($tmpPath);
        throw new RuntimeException('The remote PDF is empty or could not be downloaded.');
    }
    if ($meta['downloaded_bytes'] > $maxBytes) {
        @unlink($tmpPath);
        throw new RuntimeException('Remote PDF exceeds the upload limit of ' . udm_format_bytes($maxBytes) . '.');
    }

    $contentType = strtolower((string) $meta['content_type']);
    $filename = udm_resolve_remote_filename($url, $contentType);
    if (substr(strtolower($filename), -4) !== '.pdf' && strpos($contentType, 'pdf') === false) {
        @unlink($tmpPath);
        throw new RuntimeException('The URL does not appear to point to a PDF file.');
    }

    return array(
        'tmp_path' => $tmpPath,
        'filename' => $filename,
        'size' => $meta['downloaded_bytes'],
        'content_type' => $contentType,
    );
}

udm_handle_download();
udm_cleanup_downloads();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pages = isset($_POST['pages']) ? trim((string) $_POST['pages']) : '';
    $PDF_URL_INPUT = isset($_POST['pdf_url']) ? trim((string) $_POST['pdf_url']) : '';
    if ($pages !== '' && !preg_match('/^[0-9,\-\s]+$/', $pages)) {
        $ERROR_MESSAGE = 'ページ指定は 1,3,5-8 のような形式で入力してください。';
    } else {
        $hasUpload = !empty($_FILES['pdf_file']) && isset($_FILES['pdf_file']['error']) && (int) $_FILES['pdf_file']['error'] !== UPLOAD_ERR_NO_FILE;
        $hasUrl = $PDF_URL_INPUT !== '';
        if (!$hasUpload && !$hasUrl) {
            $ERROR_MESSAGE = 'PDFファイルを選択するか、PDF URL を入力してください。';
        } elseif ($hasUpload && $hasUrl) {
            $ERROR_MESSAGE = 'PDFファイルか PDF URL のどちらか一方だけを指定してください。';
        } else {
            $tmpPath = null;
            $originalName = 'document.pdf';
            $cleanupTmp = false;
            if ($hasUpload) {
                $upload = $_FILES['pdf_file'];
                if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
                    $ERROR_MESSAGE = 'アップロードに失敗しました。';
                } elseif ((int) $upload['size'] <= 0) {
                    $ERROR_MESSAGE = '空のファイルは処理できません。';
                } elseif ((int) $upload['size'] > $MAX_UPLOAD_BYTES) {
                    $ERROR_MESSAGE = 'アップロード上限は ' . udm_format_bytes($MAX_UPLOAD_BYTES) . ' です。';
                } else {
                    $originalName = isset($upload['name']) ? (string) $upload['name'] : 'document.pdf';
                    $lowerName = strtolower($originalName);
                    $mime = isset($upload['type']) ? strtolower((string) $upload['type']) : '';
                    if (substr($lowerName, -4) !== '.pdf' && $mime !== 'application/pdf') {
                        $ERROR_MESSAGE = 'PDFファイルのみアップロードできます。';
                    } else {
                        $tmpPath = $upload['tmp_name'];
                    }
                }
            } else {
                try {
                    if (!preg_match('/^https?:\/\//i', $PDF_URL_INPUT)) {
                        throw new RuntimeException('PDF URL は http:// または https:// で始めてください。');
                    }
                    $remotePdf = udm_fetch_remote_pdf($PDF_URL_INPUT, $MAX_UPLOAD_BYTES);
                    $tmpPath = $remotePdf['tmp_path'];
                    $originalName = $remotePdf['filename'];
                    $cleanupTmp = true;
                } catch (Exception $e) {
                    $ERROR_MESSAGE = $e->getMessage();
                }
            }

            if ($ERROR_MESSAGE === '' && $tmpPath !== null) {
                try {
                    $RESULT = udm_call_api($API_URL, $tmpPath, $originalName, $pages);
                    if (!empty($RESULT['markdown'])) {
                        $token = udm_store_markdown_download($originalName, $RESULT['markdown']);
                        $DOWNLOAD_URL = $THIS_FILE . '?download=' . rawurlencode($token);
                    }
                } catch (Exception $e) {
                    $ERROR_MESSAGE = $e->getMessage();
                }
                if ($cleanupTmp && is_file($tmpPath)) {
                    @unlink($tmpPath);
                }
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UPDF2MD Demo | AI Knowledge CMS</title>
<meta name="description" content="PDF をアップロードすると Markdown に変換する updf2md の公開デモ。MCP・AI ワークフロー向けの PDF to Markdown 変換を体験できます。">
<link rel="canonical" href="<?php echo udm_escape($PAGE_URL); ?>">
<style>
:root {
    --ink: #172033;
    --muted: #5d6a82;
    --line: #d9e0ea;
    --bg: #f3f6fb;
    --card: rgba(255,255,255,0.92);
    --brand: #0b6bcb;
    --brand-deep: #083e7a;
    --accent: #f97316;
    --success: #0f9f67;
    --shadow: 0 24px 70px rgba(16, 37, 63, 0.12);
}
* { box-sizing: border-box; }
body {
    margin: 0;
    color: var(--ink);
    background:
        radial-gradient(circle at top left, rgba(14, 165, 233, 0.16), transparent 28%),
        radial-gradient(circle at top right, rgba(249, 115, 22, 0.12), transparent 24%),
        linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
a { color: var(--brand); }
.shell {
    max-width: 1180px;
    margin: 0 auto;
    padding: 32px 18px 56px;
}
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 24px;
}
.crumb {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--muted);
    text-decoration: none;
    font-size: 14px;
}
.tagline {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border: 1px solid rgba(11, 107, 203, 0.18);
    border-radius: 999px;
    background: rgba(255,255,255,0.72);
    color: var(--brand-deep);
    font-size: 12px;
    font-weight: 700;
}
.hero {
    display: grid;
    grid-template-columns: 1.3fr 0.9fr;
    gap: 22px;
    margin-bottom: 24px;
}
.hero-main, .hero-side, .panel, .result-card {
    background: var(--card);
    border: 1px solid rgba(217, 224, 234, 0.95);
    border-radius: 24px;
    box-shadow: var(--shadow);
}
.hero-main {
    padding: 28px;
}
.hero-side {
    padding: 24px;
    background:
        linear-gradient(165deg, rgba(8, 62, 122, 0.96), rgba(11, 107, 203, 0.94)),
        var(--card);
    color: #fff;
}
.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    background: #e9f3ff;
    color: var(--brand-deep);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.02em;
}
h1 {
    margin: 18px 0 14px;
    font-size: 44px;
    line-height: 1.02;
    letter-spacing: -0.05em;
}
.hero-main p {
    margin: 0;
    font-size: 17px;
    line-height: 1.85;
    color: var(--muted);
}
.hero-list {
    margin: 22px 0 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 10px;
}
.hero-list li {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    color: #e7f2ff;
    line-height: 1.7;
}
.hero-list strong {
    color: #fff;
}
.mcp-promo {
    margin-top: 22px;
    padding: 18px;
    border-radius: 18px;
    background: rgba(255,255,255,0.10);
    border: 1px solid rgba(255,255,255,0.18);
}
.mcp-promo h3 {
    margin: 0 0 10px;
    font-size: 18px;
    letter-spacing: -0.03em;
}
.mcp-promo p {
    margin: 0;
    color: #e7f2ff;
    font-size: 14px;
    line-height: 1.8;
}
.mcp-promo ul {
    margin: 12px 0 0;
    padding-left: 18px;
    color: #e7f2ff;
    font-size: 14px;
    line-height: 1.8;
}
.mcp-promo li + li {
    margin-top: 6px;
}
.mcp-promo code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12px;
    color: #fff;
}
.grid {
    display: grid;
    grid-template-columns: minmax(0, 440px) minmax(0, 1fr);
    gap: 22px;
    align-items: start;
}
.panel {
    padding: 24px;
}
.panel h2, .result-card h2 {
    margin: 0 0 16px;
    font-size: 24px;
    letter-spacing: -0.03em;
}
.helper {
    margin: 0 0 18px;
    color: var(--muted);
    font-size: 14px;
    line-height: 1.7;
}
.field {
    margin-bottom: 16px;
}
.label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 700;
    color: var(--brand-deep);
}
.input, .file-input {
    width: 100%;
    border: 1px solid #c7d2e1;
    border-radius: 14px;
    padding: 13px 14px;
    font-size: 15px;
    background: #fff;
}
.hint {
    margin-top: 7px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.6;
}
.submit-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    margin-top: 22px;
}
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 14px;
    padding: 13px 20px;
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-deep) 100%);
    color: #fff;
    font-size: 15px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
    box-shadow: 0 16px 36px rgba(11, 107, 203, 0.26);
}
.ghost {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 13px 18px;
    border-radius: 14px;
    border: 1px solid #c7d2e1;
    color: var(--brand-deep);
    text-decoration: none;
    font-weight: 700;
    background: #fff;
}
.notice, .error {
    padding: 14px 16px;
    border-radius: 16px;
    margin-bottom: 18px;
    font-size: 14px;
    line-height: 1.7;
}
.notice {
    color: #0b4f37;
    background: #eafbf3;
    border: 1px solid #b7efcf;
}
.error {
    color: #9b1c1c;
    background: #fff1f2;
    border: 1px solid #fecdd3;
}
.stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.stat {
    padding: 14px;
    border-radius: 16px;
    background: #f7faff;
    border: 1px solid #dbe7f7;
}
.stat .k {
    color: var(--muted);
    font-size: 12px;
    margin-bottom: 6px;
}
.stat .v {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.03em;
}
.result-card {
    padding: 24px;
}
.meta-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.meta-item {
    padding: 14px;
    background: #fbfcfe;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
}
.meta-item dt {
    margin: 0 0 6px;
    color: var(--muted);
    font-size: 12px;
}
.meta-item dd {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.6;
}
.code-box {
    padding: 18px;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 18px;
    overflow: auto;
    max-height: 460px;
    font: 13px/1.75 ui-monospace, SFMono-Regular, Menlo, monospace;
    white-space: pre-wrap;
    word-break: break-word;
}
.endpoint-box {
    margin-top: 18px;
    padding: 18px;
    background: #0b1220;
    color: #e2e8f0;
    border-radius: 18px;
    overflow: auto;
    font: 13px/1.75 ui-monospace, SFMono-Regular, Menlo, monospace;
    white-space: pre-wrap;
    word-break: break-word;
}
.chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
}
.chip {
    display: inline-flex;
    align-items: center;
    padding: 7px 11px;
    border-radius: 999px;
    background: #eef6ff;
    border: 1px solid #cfe3fb;
    color: var(--brand-deep);
    font-size: 12px;
    font-weight: 700;
}
.footer-note {
    margin-top: 20px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
}
.promo-note {
    margin-top: 18px;
    padding: 16px 18px;
    border-radius: 16px;
    background: #f5fbff;
    border: 1px solid #d8eefe;
    color: #355070;
    font-size: 13px;
    line-height: 1.8;
}
.pricing-box {
    margin-top: 18px;
    padding: 18px;
    border-radius: 18px;
    background: #effaf3;
    border: 1px solid #bfe6cb;
}
.pricing-box h3 {
    margin: 0 0 10px;
    font-size: 18px;
    letter-spacing: -0.03em;
}
.pricing-box ul {
    margin: 0;
    padding-left: 18px;
    color: #23513a;
    line-height: 1.85;
}
.pricing-box li + li {
    margin-top: 8px;
}
.promo-steps {
    margin-top: 18px;
    padding: 18px;
    border-radius: 18px;
    background: #fffaf2;
    border: 1px solid #fde3ba;
}
.promo-steps h3 {
    margin: 0 0 10px;
    font-size: 18px;
    letter-spacing: -0.03em;
}
.promo-steps ol {
    margin: 0;
    padding-left: 20px;
    color: #6b4f1d;
    line-height: 1.85;
}
.promo-steps li + li {
    margin-top: 8px;
}
@media (max-width: 960px) {
    .hero, .grid {
        grid-template-columns: 1fr;
    }
    h1 {
        font-size: 36px;
    }
}
@media (max-width: 640px) {
    .shell {
        padding-left: 14px;
        padding-right: 14px;
    }
    h1 {
        font-size: 30px;
    }
    .stats, .meta-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="shell">
    <div class="topbar">
        <a class="crumb" href="<?php echo udm_escape(AIGM_BASE_URL . '/url2ai.html'); ?>">← URL2AI に戻る</a>
        <div class="tagline">PDF to Markdown Demo for MCP Promotion</div>
    </div>

    <div class="hero">
        <section class="hero-main">
            <div class="eyebrow">UPDF2MD • PDF to Markdown</div>
            <h1>PDFをそのまま<br>Markdownへ変換</h1>
            <p>
                `updf2md` は、アップロードした PDF を Markdown に変換する公開デモです。
                テキストベース PDF は高速に処理し、複雑なレイアウトや OCR 必要ページの情報も一緒に返します。
                MCP サーバーや AI ワークフローに組み込む前提で、実際の変換体験をそのまま見せるページです。
            </p>
        </section>
        <aside class="hero-side">
            <h2 style="margin:0 0 14px;font-size:24px;letter-spacing:-0.03em;">デモで見せるポイント</h2>
            <ul class="hero-list">
                <li><strong>Upload</strong> Web から PDF を投げるだけで変換開始</li>
                <li><strong>Metadata</strong> `pdf_type`, `pages_needing_ocr`, table/column 情報を表示</li>
                <li><strong>Download</strong> 生成 Markdown をその場で `.md` として保存</li>
                <li><strong>MCP Ready</strong> PDF 解析パイプラインの入口として使える構成</li>
            </ul>
            <div class="mcp-promo">
                <h3>Hosted MCP / x402 Endpoint</h3>
                <p>
                    このページは無料デモですが、UPDF2MD 自体は hosted MCP / paid API としても公開しています。
                    `pdf_url` を渡すだけで PDF to Markdown 変換ができる x402 endpoint を用意しており、document extraction や agent workflow にそのまま組み込めます。
                </p>
                <ul>
                    <li>Free demo for humans</li>
                    <li>Hosted endpoint for MCP / agents</li>
                    <li>Pay-per-request via Bankr x402 Cloud</li>
                </ul>
            </div>
        </aside>
    </div>

    <div class="grid">
        <section class="panel">
            <h2>アップロード</h2>
            <p class="helper">
                最大 <?php echo udm_escape(udm_format_bytes($MAX_UPLOAD_BYTES)); ?> までの PDF をアップロードできます。
                もしくはネット上の PDF URL を指定できます。
                バックエンドの変換 API を通じて Markdown 化します。
            </p>

            <?php if ($ERROR_MESSAGE !== ''): ?>
                <div class="error"><?php echo nl2br(udm_escape($ERROR_MESSAGE)); ?></div>
            <?php elseif (is_array($RESULT)): ?>
                <div class="notice">変換が完了しました。右側で結果を確認して、Markdown をダウンロードできます。</div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="field">
                    <label class="label" for="pdf_file">PDF ファイル</label>
                    <input class="file-input" id="pdf_file" type="file" name="pdf_file" accept="application/pdf,.pdf">
                    <div class="hint">技術資料、論文、仕様書、ホワイトペーパーなどの PDF を想定しています。下の URL 入力と同時指定はできません。</div>
                </div>

                <div class="field">
                    <label class="label" for="pdf_url">PDF URL</label>
                    <input class="input" id="pdf_url" type="url" name="pdf_url" placeholder="https://example.com/document.pdf" value="<?php echo udm_escape($PDF_URL_INPUT); ?>">
                    <div class="hint">公開アクセス可能な PDF URL を指定できます。ファイル upload と同時指定はできません。</div>
                </div>

                <div class="field">
                    <label class="label" for="pages">ページ指定</label>
                    <input class="input" id="pages" type="text" name="pages" placeholder="例: 1,3,5-8" value="<?php echo isset($_POST['pages']) ? udm_escape($_POST['pages']) : ''; ?>">
                    <div class="hint">空欄なら全ページを処理します。1-indexed です。</div>
                </div>

                <div class="submit-row">
                    <button class="button" type="submit">Markdown に変換</button>
                    <a class="ghost" href="<?php echo udm_escape($THIS_FILE); ?>">フォームをリセット</a>
                </div>
            </form>

            <div class="footer-note">
                この公開ページはデモ用途です。Markdown はブラウザセッション単位の一時ファイルとして扱われ、ダウンロード用リンクは一定時間で期限切れになります。
            </div>
            <div class="promo-note">
                Web デモは無料で試せます。継続利用やエージェント連携を行う場合は、URL2AI の hosted MCP / paid API を利用する想定です。
                MCP 利用者は Bankr docs から x402 Cloud / CLI の導線を確認し、その後 hosted endpoint へ接続してください。
            </div>
            <div class="pricing-box">
                <h3>Pricing</h3>
                <ul>
                    <li>Web demo: free</li>
                    <li>Hosted MCP / x402 endpoint: paid</li>
                    <li>Current price: 0.001 USDC per request</li>
                    <li>Billing is handled by Bankr x402 and the client receives a 402 Payment Required challenge before execution</li>
                </ul>
            </div>
            <div class="promo-steps">
                <h3>For MCP Users</h3>
                <ol>
                    <li>このページで PDF to Markdown の変換結果を確認する</li>
                    <li><a href="https://docs.bankr.bot/" target="_blank" rel="noopener">Bankr Docs</a> から x402 Cloud / CLI の流れを確認する</li>
                    <li>URL2AI の hosted endpoint を MCP サーバーや agent workflow へ接続する</li>
                </ol>
            </div>
            <div class="endpoint-box">Endpoint:
https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/updf2md

Pricing:
0.001 USDC / request
The endpoint returns a 402 Payment Required challenge before paid execution.

CLI:
bankr x402 schema https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/updf2md
bankr x402 call https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/updf2md \
  -X POST \
  -H 'content-type: application/json' \
  -d '{"pdf_url":"https://example.com/document.pdf"}'</div>
            <div class="submit-row" style="margin-top:14px;">
                <a class="ghost" href="https://docs.bankr.bot/" target="_blank" rel="noopener">Bankr Docs</a>
                <a class="ghost" href="https://github.com/katsushi2441/url2ai" target="_blank" rel="noopener">URL2AI on GitHub</a>
            </div>
        </section>

        <section class="result-card">
            <h2>変換結果</h2>
            <?php if (!is_array($RESULT)): ?>
                <p class="helper">
                    変換後はここに PDF タイプ、処理時間、OCR が必要なページ、Markdown プレビューが表示されます。
                </p>
                <div class="chip-row">
                    <span class="chip">/pdf/convert API</span>
                    <span class="chip">Markdown preview</span>
                    <span class="chip">.md download</span>
                    <span class="chip">MCP promotion demo</span>
                </div>
            <?php else: ?>
                <div class="stats">
                    <div class="stat">
                        <div class="k">PDF Type</div>
                        <div class="v"><?php echo udm_escape(isset($RESULT['pdf_type']) ? $RESULT['pdf_type'] : '-'); ?></div>
                    </div>
                    <div class="stat">
                        <div class="k">Processing Time</div>
                        <div class="v"><?php echo udm_escape(isset($RESULT['processing_time_ms']) ? $RESULT['processing_time_ms'] . ' ms' : '-'); ?></div>
                    </div>
                    <div class="stat">
                        <div class="k">Pages</div>
                        <div class="v"><?php echo udm_escape(isset($RESULT['page_count']) ? $RESULT['page_count'] : '-'); ?></div>
                    </div>
                    <div class="stat">
                        <div class="k">Confidence</div>
                        <div class="v"><?php echo udm_escape(isset($RESULT['confidence']) ? number_format((float) $RESULT['confidence'] * 100, 1) . '%' : '-'); ?></div>
                    </div>
                </div>

                <dl class="meta-grid">
                    <div class="meta-item">
                        <dt>Filename</dt>
                        <dd><?php echo udm_escape(isset($RESULT['filename']) ? $RESULT['filename'] : '-'); ?></dd>
                    </div>
                    <div class="meta-item">
                        <dt>OCR Needed Pages</dt>
                        <dd><?php echo udm_escape(!empty($RESULT['pages_needing_ocr']) ? implode(', ', $RESULT['pages_needing_ocr']) : 'none'); ?></dd>
                    </div>
                    <div class="meta-item">
                        <dt>Tables Detected</dt>
                        <dd><?php echo udm_escape(!empty($RESULT['pages_with_tables']) ? implode(', ', $RESULT['pages_with_tables']) : 'none'); ?></dd>
                    </div>
                    <div class="meta-item">
                        <dt>Columns Detected</dt>
                        <dd><?php echo udm_escape(!empty($RESULT['pages_with_columns']) ? implode(', ', $RESULT['pages_with_columns']) : 'none'); ?></dd>
                    </div>
                    <div class="meta-item">
                        <dt>Complex Layout</dt>
                        <dd><?php echo udm_escape(!empty($RESULT['is_complex_layout']) ? 'yes' : 'no'); ?></dd>
                    </div>
                    <div class="meta-item">
                        <dt>Encoding Issues</dt>
                        <dd><?php echo udm_escape(!empty($RESULT['has_encoding_issues']) ? 'detected' : 'none'); ?></dd>
                    </div>
                </dl>

                <?php if ($DOWNLOAD_URL !== ''): ?>
                    <div class="submit-row" style="margin-top:0;margin-bottom:18px;">
                        <a class="button" href="<?php echo udm_escape($DOWNLOAD_URL); ?>">Markdown をダウンロード</a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($RESULT['markdown'])): ?>
                    <div class="code-box"><?php echo udm_escape($RESULT['markdown']); ?></div>
                <?php else: ?>
                    <p class="helper">Markdown 本文は返っていません。API の応答メタ情報のみ表示しています。</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>
