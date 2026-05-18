<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function bp_att_recognize_json(array $payload, int $statusCode = 200)
{
    http_response_code($statusCode);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"status":false,"message":"Failed to encode JSON response"}';
    }
    echo $json;
    exit;
}

function bp_att_recognize_target_urls(): array
{
    $urls = [];
    foreach ([
        defined('BP_FACE_RECOGNITION_BASE_URL') ? BP_FACE_RECOGNITION_BASE_URL : '',
        'http://125.17.238.158:5001',
        'http://zigfly.in:5001',
    ] as $baseUrl) {
        $baseUrl = rtrim(trim((string)$baseUrl), '/');
        if ($baseUrl !== '') {
            $urls[$baseUrl . '/recognize_bp'] = true;
        }
    }

    return array_keys($urls);
}

function bp_att_recognize_jpeg_upload(string $field): ?CURLFile
{
    if (!isset($_FILES[$field]['tmp_name']) || !is_uploaded_file((string)$_FILES[$field]['tmp_name'])) {
        return null;
    }

    $errorCode = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_OK);
    if ($errorCode !== UPLOAD_ERR_OK) {
        bp_att_recognize_json([
            'status' => false,
            'message' => 'Attendance image upload failed',
            'field' => $field,
            'error_code' => $errorCode,
        ], 400);
    }

    $tmpName = (string)$_FILES[$field]['tmp_name'];
    $sourceBytes = @file_get_contents($tmpName);
    if ($sourceBytes === false || $sourceBytes === '') {
        bp_att_recognize_json([
            'status' => false,
            'message' => 'Attendance image is empty',
            'field' => $field,
        ], 400);
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        $fileName = trim((string)($_FILES[$field]['name'] ?? basename($tmpName)));
        return new CURLFile($tmpName, 'image/jpeg', $fileName !== '' ? $fileName : ($field . '.jpg'));
    }

    $source = @imagecreatefromstring($sourceBytes);
    if (!$source) {
        bp_att_recognize_json([
            'status' => false,
            'message' => 'Unsupported attendance image format',
            'field' => $field,
        ], 400);
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $canvas = imagecreatetruecolor($width, $height);
    if (!$canvas) {
        imagedestroy($source);
        bp_att_recognize_json([
            'status' => false,
            'message' => 'Failed to prepare attendance image',
            'field' => $field,
        ], 500);
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

    $outputPath = tempnam(sys_get_temp_dir(), 'bp_att_');
    if ($outputPath === false) {
        imagedestroy($source);
        imagedestroy($canvas);
        bp_att_recognize_json([
            'status' => false,
            'message' => 'Failed to allocate attendance image',
            'field' => $field,
        ], 500);
    }
    $jpegPath = $outputPath . '.jpg';
    @rename($outputPath, $jpegPath);

    if (!imagejpeg($canvas, $jpegPath, 85)) {
        imagedestroy($source);
        imagedestroy($canvas);
        @unlink($jpegPath);
        bp_att_recognize_json([
            'status' => false,
            'message' => 'Failed to convert attendance image to JPEG',
            'field' => $field,
        ], 500);
    }

    imagedestroy($source);
    imagedestroy($canvas);

    $GLOBALS['bp_att_recognize_temp_files'][] = $jpegPath;
    $rawName = trim((string)($_FILES[$field]['name'] ?? $field));
    $safeName = preg_replace('/\.[A-Za-z0-9]{2,6}$/', '', basename($rawName)) ?: $field;

    return new CURLFile($jpegPath, 'image/jpeg', $safeName . '.jpg');
}

$GLOBALS['bp_att_recognize_temp_files'] = [];
register_shutdown_function(static function (): void {
    foreach ((array)($GLOBALS['bp_att_recognize_temp_files'] ?? []) as $path) {
        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }
});

if (!function_exists('curl_init')) {
    bp_att_recognize_json([
        'status' => false,
        'message' => 'cURL extension is unavailable',
    ], 500);
}

$postFields = [];

foreach ($_POST as $key => $value) {
    $postFields[$key] = is_scalar($value) ? (string)$value : '';
}

foreach (['targeted_image', 'captured_image'] as $field) {
    $upload = bp_att_recognize_jpeg_upload($field);
    if ($upload !== null) {
        $postFields[$field] = $upload;
    }
}

$lastResponse = null;

foreach (bp_att_recognize_target_urls() as $targetUrl) {
    $curl = curl_init($targetUrl);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS + 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);

    if ($raw === false) {
        $lastResponse = [
            'status' => false,
            'message' => 'Face recognition service is unreachable',
            'error' => $error,
            'http_code' => 502,
            'content_type' => 'application/json',
            'raw' => '',
        ];
        continue;
    }

    $lastResponse = [
        'status' => $httpCode >= 200 && $httpCode < 300,
        'message' => 'Face recognition response received',
        'http_code' => $httpCode > 0 ? $httpCode : 200,
        'content_type' => $contentType,
        'raw' => (string)$raw,
    ];

    if ($httpCode < 500) {
        break;
    }
}

if ($lastResponse === null) {
    bp_att_recognize_json([
        'status' => false,
        'message' => 'Face recognition service is not configured',
    ], 500);
}

http_response_code((int)$lastResponse['http_code']);
if (stripos((string)$lastResponse['content_type'], 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    echo (string)$lastResponse['raw'];
    exit;
}

bp_att_recognize_json([
    'status' => (bool)$lastResponse['status'],
    'message' => (string)$lastResponse['message'],
    'raw' => (string)$lastResponse['raw'],
], (int)$lastResponse['http_code']);
