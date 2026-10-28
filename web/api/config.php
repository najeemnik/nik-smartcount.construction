<?php
/**
 * NSC ERP & Construction Finance System
 * Shahhost Server Configuration (SSD Silver Package)
 * 
 * تمام مقادیر اتصال دیتابیس را طبق اطلاعاتی که در cPanel هاست ساختید تنظیم کنید.
 */

// جلوگیری از نمایش خطاهای حساس سرور برای کاربران (Security Hardening)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kabul');

// Saved private settings take priority. Empty environment values do not erase DB names.
$credentialFile=__DIR__.'/.secrets/database.php';
$nscDatabase=is_file($credentialFile)?require $credentialFile:[];
if(!is_array($nscDatabase))$nscDatabase=[];
foreach(['HOST'=>'localhost','PORT'=>'3306','NAME'=>'','USER'=>'','PASS'=>''] as $field=>$fallback){
 $env=getenv('DB_'.$field);
 $value=array_key_exists($field,$nscDatabase)?$nscDatabase[$field]:(($env!==false&&$env!=='')?$env:$fallback);
 define('DB_'.$field,(string)$value);
}
define('DB_CHARSET','utf8mb4');unset($nscDatabase);

// کلید امضای توکن‌ها: از ENV، وگرنه کلید یکتای خودکار و ماندگار همین نصب
// (در api/.secrets/jwt_secret.key ذخیره می‌شود و هرگز کامیت نکنید)
if (!defined('JWT_SECRET_KEY')) {
    $envSecret = getenv('JWT_SECRET') ?: '';
    if ($envSecret !== '') {
        define('JWT_SECRET_KEY', $envSecret);
    } else {
        $secretDir = __DIR__ . '/.secrets';
        $secretFile = $secretDir . '/jwt_secret.key';
        if (!is_dir($secretDir) && !mkdir($secretDir, 0700, true) && !is_dir($secretDir)) {
            throw new RuntimeException('Secret directory is not writable.');
        }
        $secretHandle = fopen($secretFile, 'c+');
        if (!$secretHandle || !flock($secretHandle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock the signing key.');
        }
        $secret = trim((string)stream_get_contents($secretHandle));
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            rewind($secretHandle);
            if (!ftruncate($secretHandle, 0) || fwrite($secretHandle, $secret) !== strlen($secret) || !fflush($secretHandle)) {
                throw new RuntimeException('Cannot persist the signing key.');
            }
            chmod($secretFile, 0600);
        }
        flock($secretHandle, LOCK_UN);
        fclose($secretHandle);
        define('JWT_SECRET_KEY', $secret);
    }
}

// تنظیمات پوشه آپلودها (اسناد، بل‌ها، عکس‌ها)
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE_BYTES', 10 * 1024 * 1024); // حداکثر ۱۰ مگابایت برای هر سند
define('ALLOWED_FILE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf', 'webp']);

// تنظیمات امنیتی هدرها، CSP، CORS و محافظت در برابر CSRF
function sendSecurityHeaders() {
    // جلوگیری از کلیک‌جکینگ، XSS و Sniffing
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store, private");
    
    // هدرهای CORS — فقط برای آدرس‌های مجاز در ALLOWED_ORIGINS (پیش‌فرض: بسته)
    // درخواست‌های same-origin بدون این هدرها هم کار می‌کنند.
    $allowedOrigins = array_filter(array_map('trim', explode(',', getenv('ALLOWED_ORIGINS') ?: '')));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: " . $origin);
        header("Access-Control-Allow-Credentials: true");
        header("Vary: Origin");
    }
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Office7-Gate-Key, X-Idempotency-Key");

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    enforceCsrfAndOriginGuard($allowedOrigins);
}

/**
 * Phase 10 Security Audit: CSRF & Cross-Origin Mutation Guard
 * Blocks cross-site form-POST / CSRF attacks on state-changing HTTP methods.
 */
function enforceCsrfAndOriginGuard(array $allowedOrigins = []): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return;
    }
    // Skip CLI invocations (automated test suites)
    if (PHP_SAPI === 'cli') {
        return;
    }

    // 1. If Origin header is present, it must match current Host or ALLOWED_ORIGINS
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($origin !== '') {
        $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
        $originPort = parse_url($origin, PHP_URL_PORT);
        $originHostWithPort = $originPort ? "{$originHost}:{$originPort}" : $originHost;
        $sameHost = ($host !== '' && ($originHost === $host || $originHostWithPort === $host));
        if (!$sameHost && !in_array($origin, $allowedOrigins, true)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'درخواست از مبدأ غیرمجاز مسدود شد (CSRF / Cross-Origin Blocked).'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 2. Require a custom header or JSON content-type to block simple HTML <form> CSRF
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $gate = (string)($_SERVER['HTTP_X_OFFICE7_GATE_KEY'] ?? '');

    $isJson = strpos($contentType, 'application/json') !== false;
    $hasCustomHeader = ($xhr === 'xmlhttprequest' || $auth !== '' || $gate !== '');

    if (!$isJson && !$hasCustomHeader) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'درخواست فاقد هدرهای امنیتی ضد CSRF است.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
