<?php
declare(strict_types=1);

function outputPlaceholderImage(): void
{
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: public, max-age=300');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="220" viewBox="0 0 320 220">'
        . '<rect width="320" height="220" fill="#f3f4f6"/>'
        . '<g fill="#9ca3af">'
        . '<rect x="86" y="92" width="148" height="46" rx="7"/>'
        . '<circle cx="118" cy="150" r="14"/>'
        . '<circle cx="202" cy="150" r="14"/>'
        . '</g>'
        . '<text x="160" y="198" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" fill="#6b7280">Image unavailable</text>'
        . '</svg>';
    echo $svg;
}

function detectImageMimeType(string $filePath): string
{
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($filePath);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }
    }

    if (function_exists('finfo_open') && defined('FILEINFO_MIME_TYPE')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = @finfo_file($finfo, $filePath);
            @finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }
    }

    if (function_exists('getimagesize')) {
        $info = @getimagesize($filePath);
        if (is_array($info) && !empty($info['mime'])) {
            return (string)$info['mime'];
        }
    }

    $ext = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));
    $byExtension = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'avif' => 'image/avif'
    ];

    return $byExtension[$ext] ?? 'application/octet-stream';
}

$rawPath = isset($_GET['path']) ? trim((string)$_GET['path']) : '';
if ($rawPath === '') {
    outputPlaceholderImage();
    exit;
}

if (preg_match('#^(https?:)?//#i', $rawPath) || str_starts_with($rawPath, 'data:')) {
    outputPlaceholderImage();
    exit;
}

$normalized = str_replace('\\', '/', $rawPath);
$normalized = explode('?', $normalized)[0];
$normalized = explode('#', $normalized)[0];
$normalized = preg_replace('#^\./#', '', $normalized);
$normalized = ltrim((string)$normalized, '/');

if (str_starts_with($normalized, 'uploads/')) {
    $normalized = substr($normalized, strlen('uploads/'));
}

if (str_contains($normalized, '/uploads/')) {
    $parts = explode('/uploads/', $normalized);
    $normalized = end($parts) ?: '';
}

$segments = array_filter(explode('/', $normalized), static function (string $segment): bool {
    if ($segment === '' || $segment === '.' || $segment === '..') {
        return false;
    }
    return true;
});

if (empty($segments)) {
    outputPlaceholderImage();
    exit;
}

$uploadsDir = realpath(__DIR__ . '/../uploads');
if ($uploadsDir === false) {
    outputPlaceholderImage();
    exit;
}

$relativePath = implode(DIRECTORY_SEPARATOR, $segments);
$targetPath = $uploadsDir . DIRECTORY_SEPARATOR . $relativePath;
$targetRealPath = realpath($targetPath);

if ($targetRealPath === false || !str_starts_with($targetRealPath, $uploadsDir) || !is_file($targetRealPath)) {
    outputPlaceholderImage();
    exit;
}

$mimeType = detectImageMimeType($targetRealPath);
if (!str_starts_with($mimeType, 'image/')) {
    outputPlaceholderImage();
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($targetRealPath));
header('Cache-Control: public, max-age=86400');
readfile($targetRealPath);
