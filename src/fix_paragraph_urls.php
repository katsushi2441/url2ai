<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

$DATA_DIR = __DIR__ . '/data';

function paragraph_api_get_json($url, $headers = array()) {
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

function resolve_paragraph_post_url($paragraph_post_id) {
    $publication_slug = trim((string) PARAGRAPH_PUBLICATION_SLUG);
    $paragraph_post_id = trim((string) $paragraph_post_id);
    if ($publication_slug === '' || $paragraph_post_id === '') {
        return '';
    }

    $publication = paragraph_api_get_json(
        'https://public.api.paragraph.com/api/v1/publications/slug/' . rawurlencode($publication_slug)
    );
    if (!is_array($publication) || empty($publication['id'])) {
        return '';
    }

    $publication_id = (string) $publication['id'];
    $publication_slug_clean = isset($publication['slug']) ? ltrim((string)$publication['slug'], '@') : ltrim($publication_slug, '@');
    $custom_domain = isset($publication['customDomain']) ? trim((string)$publication['customDomain']) : '';
    $base_url = $custom_domain !== '' ? rtrim($custom_domain, '/') : ('https://paragraph.com/@' . $publication_slug_clean);

    $cursor = '';
    for ($i = 0; $i < 10; $i++) {
        $url = 'https://public.api.paragraph.com/api/v1/publications/' . rawurlencode($publication_id) . '/posts?limit=100';
        if ($cursor !== '') {
            $url .= '&cursor=' . rawurlencode($cursor);
        }
        $posts_data = paragraph_api_get_json($url);
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
        $cursor = isset($posts_data['pagination']['cursor']) ? (string)$posts_data['pagination']['cursor'] : '';
        $has_more = !empty($posts_data['pagination']['hasMore']);
        if (!$has_more || $cursor === '') {
            break;
        }
    }
    return '';
}

function save_post_file($path, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

$result = array(
    'ok' => true,
    'updated' => 0,
    'checked' => 0,
    'errors' => array(),
);

if (!is_dir($DATA_DIR)) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'data dir not found'), JSON_UNESCAPED_UNICODE);
    exit;
}

$files = glob($DATA_DIR . '/oss_*.json');
if ($files) {
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            continue;
        }
        $result['checked']++;
        if (!empty($data['paragraph_url'])) {
            continue;
        }
        if (empty($data['paragraph_post_id'])) {
            continue;
        }
        $resolved = resolve_paragraph_post_url((string)$data['paragraph_post_id']);
        if ($resolved === '') {
            $result['errors'][] = array(
                'id' => isset($data['id']) ? $data['id'] : '',
                'paragraph_post_id' => $data['paragraph_post_id'],
            );
            continue;
        }
        $data['paragraph_url'] = $resolved;
        if (save_post_file($file, $data)) {
            $result['updated']++;
        }
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
