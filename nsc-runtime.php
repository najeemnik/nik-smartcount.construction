<?php
/**
 * سرو باندل کلاینت با اعتبارسنجی مجدد (ETag + 304).
 * چرا: پیش از این max-age=3600 بود؛ پس از هر آپدیت کاربر تا یک ساعت کلاینت قدیمی
 * اجرا می‌کرد و باگ‌های رفع‌شده «نامرئی» می‌ماندند. اکنون مرورگر با ETag پرس‌وجو می‌کند
 * و در صورت تغییر فایل، نسخهٔ تازه را می‌گیرد (پهنای باند تقریباً همان، تازگی فوری).
 */
ini_set('zlib.output_compression', '0');
header('X-Content-Type-Options: nosniff');

if (($_GET['asset'] ?? '') !== 'accounting') {
    http_response_code(404);
    exit;
}

$file = __DIR__ . '/nsc-accounting-r3.js.gz';
if (!is_file($file)) {
    http_response_code(503);
    header('Content-Type: text/javascript; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'throw new Error("Accounting asset missing; extract every file from the update.");';
    exit;
}

$acceptsGzip = strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false;
$encoding = $acceptsGzip ? 'gzip' : 'identity';

// ETag به نسخهٔ فایل و روش کدگذاری وابسته است تا کش‌ها نسخهٔ gzip و متن ساده را قاطی نکنند.
$stat = @stat($file) ?: ['mtime' => 0, 'size' => 0];
$etag = '"' . substr(hash('sha256', $stat['mtime'] . ':' . $stat['size'] . ':' . $encoding), 0, 32) . '"';

header('Content-Type: text/javascript; charset=UTF-8');
header('Vary: Accept-Encoding');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$stat['mtime']) . ' GMT');
header('Cache-Control: public, max-age=0, must-revalidate');

$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

if ($acceptsGzip) {
    header('Content-Encoding: gzip');
    header('Content-Length: ' . $stat['size']);
    readfile($file);
    exit;
}

$decoded = @gzdecode((string)file_get_contents($file));
if ($decoded === false) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo 'throw new Error("Accounting asset could not be decoded.");';
    exit;
}
header('Content-Length: ' . strlen($decoded));
echo $decoded;
