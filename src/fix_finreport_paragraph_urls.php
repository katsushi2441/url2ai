<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Tokyo');

$DATA_DIR = __DIR__ . '/data';

function ff_paragraph_api_get_json($url, $headers = array()) {
    $opts = array('http' => array(
        'method' => 'GET',
        'header' => implode("\r\n", $headers) . "\r\n",
        'timeout' => 30,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) {
        return null;
    }
    $decoded = json_decode($res, true);
    return is_array($decoded) ? $decoded : null;
}

function ff_resolve_paragraph_url($paragraph_post_id) {
    $publication_slug = trim((string) PARAGRAPH_PUBLICATION_SLUG);
    $paragraph_post_id = trim((string) $paragraph_post_id);
    if ($publication_slug === '' || $paragraph_post_id === '') {
        return '';
    }

    $publication = ff_paragraph_api_get_json(
        'https://public.api.paragraph.com/api/v1/publications/slug/' . rawurlencode($publication_slug)
    );
    if (!is_array($publication) || empty($publication['id'])) {
        return '';
    }

    $publication_id = (string) $publication['id'];
    $publication_slug_clean = isset($publication['slug']) ? ltrim((string)$publication['slug'], '@') : ltrim($publication_slug, '@');
    $custom_domain = isset($publication['customDomain']) ? trim((string)$publication['customDomain']) : '';
    $base_url = $custom_domain !== '' ? rtrim($custom_domain, '/') : ('https://paragraph.com/@' . $publication_slug_clean);

    $posts_data = ff_paragraph_api_get_json(
        'https://public.api.paragraph.com/api/v1/publications/' . rawurlencode($publication_id) . '/posts?limit=100'
    );
    if (!is_array($posts_data) || empty($posts_data['items']) || !is_array($posts_data['items'])) {
        return '';
    }

    foreach ($posts_data['items'] as $item) {
        $post_id = isset($item['id']) ? (string)$item['id'] : '';
        $post_slug = isset($item['slug']) ? trim((string)$item['slug']) : '';
        if ($post_id === $paragraph_post_id && $post_slug !== '') {
            return $base_url . '/' . ltrim($post_slug, '/');
        }
    }
    return '';
}

function ff_is_valid_paragraph_url($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return false;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return false;
    }
    $host = strtolower($host);
    if ($host === 'aiknowledgecms.exbridge.jp') {
        return false;
    }
    return (strpos($host, 'paragraph.com') !== false || strpos($host, 'paragraph.xyz') !== false);
}

$files = glob($DATA_DIR . '/finreport_*.json');
if (!$files) {
    $files = array();
}

$checked = 0;
$updated = 0;
$results = array();

foreach ($files as $path) {
    $raw = @file_get_contents($path);
    if (!$raw) continue;
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['ticker'])) continue;

    $checked++;
    $paragraph_url = isset($data['paragraph_url']) ? trim((string)$data['paragraph_url']) : '';
    $paragraph_post_id = isset($data['paragraph_post_id']) ? trim((string)$data['paragraph_post_id']) : '';
    if (ff_is_valid_paragraph_url($paragraph_url) || $paragraph_post_id === '') {
        continue;
    }

    $resolved = ff_resolve_paragraph_url($paragraph_post_id);
    if ($resolved === '') {
        $results[] = array(
            'ticker' => $data['ticker'],
            'status' => 'not_found',
            'paragraph_post_id' => $paragraph_post_id,
        );
        continue;
    }

    $data['paragraph_url'] = $resolved;
    if (empty($data['paragraph_posted_at'])) {
        $data['paragraph_posted_at'] = date('c');
    }
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $updated++;
    $results[] = array(
        'ticker' => $data['ticker'],
        'status' => 'updated',
        'paragraph_url' => $resolved,
    );
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(array(
    'ok' => true,
    'checked' => $checked,
    'updated' => $updated,
    'results' => $results,
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

