<?php
/**
 * NSC Project File Manager & Bills Management API (Phase 6)
 * - Enforces project-level permissions and tenant isolation
 * - Strictly blocks executable/script files (.php, .phtml, .phar, .exe, .sh, .py, .js, double-extensions, script headers)
 * - Enforces safe filenames, private storage keys, configurable permitted extensions, and size limits
 * - Provides protected downloads with Content-Disposition: attachment & X-Content-Type-Options: nosniff
 * - Supports Folder CRUD, File CRUD (Upload, Rename, Copy, Move, Download, Delete), and Bills Management (Upload, Rename, Link, Delete)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
$user = authenticateUser(false);
$userId = (string)($user['id'] ?? $user['sub'] ?? 'unknown');
$tenantId = currentTenantId($user);

const FORBIDDEN_EXECUTABLE_EXTENSIONS = [
    'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'pgif', 'inc',
    'cgi', 'pl', 'py', 'pyc', 'pyo', 'rb', 'sh', 'bash', 'zsh', 'ksh', 'csh',
    'exe', 'dll', 'so', 'dylib', 'bat', 'cmd', 'com', 'msi', 'msp', 'scr',
    'vbs', 'vbe', 'js', 'jse', 'mjs', 'cjs', 'wsf', 'wsh', 'ps1', 'psm1',
    'jar', 'war', 'ear', 'class', 'jsp', 'jspx', 'asp', 'aspx', 'asa', 'asax', 'cer',
    'htaccess', 'htpasswd', 'ini', 'config', 'env', 'swf', 'html', 'htm', 'xhtml', 'shtml', 'svg',
    'bin', 'elf', 'run', 'app', 'deb', 'rpm', 'apk', 'dmg', 'iso', 'reg', 'hta', 'cpl'
];

const DEFAULT_PERMITTED_EXTENSIONS = [
    'pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp',
    'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx',
    'txt', 'rtf', 'odt', 'ods', 'dwg', 'dxf', 'zip'
];

const FILE_MANAGER_MAX_BYTES = 15 * 1024 * 1024; // 15 MB

function assertProjectAccess(PDO $db, $projectId, $tenantId): array {
    if (!$projectId) {
        jsonResponse(false, null, 'شناسه پروژه الزامی است.', 400);
    }
    $stmt = $db->prepare("SELECT id, name FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
    $stmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
    $proj = $stmt->fetch();
    if (!$proj) {
        jsonResponse(false, null, 'پروژه یافت نشد یا شما اجازه دسترسی به این پروژه را ندارید.', 403);
    }
    return $proj;
}

function sanitizeSafeFileName(string $rawName, string $fallbackExt = ''): string {
    $clean = str_replace(["\0", "\r", "\n"], '', trim($rawName));
    $parts = preg_split('/[\/\\\\]+/', $clean);
    $base = end($parts) ?: 'file';
    $base = ltrim($base, '.');

    $lastDot = strrpos($base, '.');
    $stem = ($lastDot !== false && $lastDot > 0) ? substr($base, 0, $lastDot) : $base;
    $ext = ($lastDot !== false && $lastDot > 0) ? strtolower(substr($base, $lastDot + 1)) : strtolower($fallbackExt);

    // Replace dots and special chars in stem to prevent double-extension execution attacks
    $stem = preg_replace('/[^a-zA-Z0-9_\-\x{0600}-\x{06FF}]/u', '_', str_replace('.', '_', $stem));
    $stem = preg_replace('/_+/', '_', trim($stem, '_'));
    if ($stem === '') {
        $stem = 'document';
    }
    $characters=preg_split('//u',$stem,-1,PREG_SPLIT_NO_EMPTY);
    if(is_array($characters) && count($characters)>100)$stem=implode('',array_slice($characters,0,100));

    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    if (in_array($ext, FORBIDDEN_EXECUTABLE_EXTENSIONS, true)) {
        $ext = 'txt';
    }
    return $ext !== '' ? "{$stem}.{$ext}" : $stem;
}

function assertNotExecutable(string $fileName, string $mimeType = '', string $rawPayload = ''): void {
    $base = basename(str_replace('\\', '/', trim($fileName)));
    $tokens = array_values(array_filter(explode('.', strtolower($base))));
    if (count($tokens) >= 2) {
        for ($i = 1; $i < count($tokens); $i++) {
            if (in_array($tokens[$i], FORBIDDEN_EXECUTABLE_EXTENSIONS, true)) {
                jsonResponse(false, null, "پسوند اجرایی یا اسکریپتی (.{$tokens[$i]}) به دلایل امنیتی مسدود است.", 400);
            }
        }
    } elseif (in_array(strtolower($base), FORBIDDEN_EXECUTABLE_EXTENSIONS, true)) {
        jsonResponse(false, null, 'فایل سیستمی یا اجرایی مجاز نیست.', 400);
    }

    $lowerMime = strtolower($mimeType);
    $forbiddenMimes = [
        'application/x-httpd-php', 'text/x-php', 'application/x-php',
        'application/x-msdownload', 'application/x-executable', 'application/x-dosexec',
        'application/x-sh', 'text/javascript', 'application/javascript', 'text/html'
    ];
    foreach ($forbiddenMimes as $fm) {
        if (strpos($lowerMime, $fm) !== false) {
            jsonResponse(false, null, "نوع محتوای اجرایی ({$mimeType}) مجاز نیست.", 400);
        }
    }

    $head = substr($rawPayload, 0, 1024);
    if (
        stripos($head, '<?php') !== false ||
        stripos($head, '<script') !== false ||
        stripos($head, '<!doctype html') !== false ||
        stripos($head, '<svg') !== false ||
        strpos($head, '#!') === 0 ||
        strpos($head, ';base64,PD9waHA') !== false ||
        strpos($head, ';base64,TVqQ') !== false
    ) {
        jsonResponse(false, null, 'محتوای فایل حاوی هدر اجرایی یا اسکریپت سرور است و مسدود شد.', 400);
    }

    // Also inspect decoded prefix if data URL is base64-encoded
    $b64Pos = strpos($head, ';base64,');
    if ($b64Pos !== false) {
        $b64Slice = substr($rawPayload, $b64Pos + 8, 512);
        $decodedHead = (string)base64_decode($b64Slice, false);
        if (
            stripos($decodedHead, '<?php') !== false ||
            stripos($decodedHead, '<?=') !== false ||
            stripos($decodedHead, '<script') !== false ||
            stripos($decodedHead, '<!doctype html') !== false ||
            stripos($decodedHead, '<svg') !== false ||
            strpos($decodedHead, '#!') === 0 ||
            substr($decodedHead, 0, 2) === 'MZ' ||
            substr($decodedHead, 0, 4) === "\x7fELF"
        ) {
            jsonResponse(false, null, 'محتوای باینری فایل حاوی امضای فایل اجرایی یا اسکریپت مخرب است و مسدود شد.', 400);
        }
    }
}

/** Verify actual decoded content and size rather than client-supplied metadata. */
function validatedUpload(string $name, string $url): array {
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if(!in_array($ext,DEFAULT_PERMITTED_EXTENSIONS,true))jsonResponse(false,null,'فرمت فایل مجاز نیست.',400);
    if(!preg_match('~^data:([a-zA-Z0-9.+/-]+);base64,([A-Za-z0-9+/=\r\n]+)$~D',$url,$parts))jsonResponse(false,null,'محتوای فایل معتبر نیست.',400);
    $raw=base64_decode($parts[2],true);
    if($raw===false || strlen($raw)===0 || strlen($raw)>FILE_MANAGER_MAX_BYTES)jsonResponse(false,null,'حجم یا محتوای فایل معتبر نیست.',400);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
    assertNotExecutable($name,$mime,$raw);
    $types=[
      'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp'],
      'gif'=>['image/gif'],'bmp'=>['image/bmp','image/x-ms-bmp'],'pdf'=>['application/pdf'],
      'txt'=>['text/plain'],'csv'=>['text/plain','text/csv'],
      'zip'=>['application/zip','application/x-zip-compressed'],
      'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
      'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
      'pptx'=>['application/zip','application/vnd.openxmlformats-officedocument.presentationml.presentation'],
      'odt'=>['application/zip','application/vnd.oasis.opendocument.text'],
      'ods'=>['application/zip','application/vnd.oasis.opendocument.spreadsheet'],
      'doc'=>['application/msword','application/CDFV2'],'xls'=>['application/vnd.ms-excel','application/CDFV2'],
      'ppt'=>['application/vnd.ms-powerpoint','application/CDFV2'],'rtf'=>['text/rtf','application/rtf'],
      'dwg'=>['image/vnd.dwg','application/acad','application/octet-stream'],
      'dxf'=>['image/vnd.dxf','text/plain','application/octet-stream']
    ];
    if(!in_array($mime,$types[$ext]??[],true))jsonResponse(false,null,'نوع واقعی فایل با پسوند آن مطابقت ندارد.',400);
    return ['mime'=>$mime,'size'=>strlen($raw),'url'=>'data:'.$mime.';base64,'.base64_encode($raw)];
}
function assertFolderScope(PDO $db, $id, string $projectId, string $tenantId): void {
    if($id===null || $id==='')return;
    $s=$db->prepare('SELECT id FROM project_folders WHERE id=:id AND project_id=:pid AND tenant_id=:tid');
    $s->execute(['id'=>$id,'pid'=>$projectId,'tid'=>$tenantId]);
    if(!$s->fetch())jsonResponse(false,null,'پوشه یافت نشد یا متعلق به این پروژه نیست.',403);
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    $projectId = $_GET['projectId'] ?? null;
    assertProjectAccess($db, $projectId, $tenantId);
    assertModulePermission($user, 'documents', 'view', (string)$projectId);

    // Protected Download Streaming
    if ($action === 'download') {
        $fileId = $_GET['fileId'] ?? '';
        $stmt = $db->prepare("SELECT * FROM project_files WHERE id = :id AND project_id = :pid AND tenant_id = :tid LIMIT 1");
        $stmt->execute(['id' => $fileId, 'pid' => $projectId, 'tid' => $tenantId]);
        $file = $stmt->fetch();
        if (!$file) {
            jsonResponse(false, null, 'فایل یافت نشد یا دسترسی ندارید.', 404);
        }
        $safeName = sanitizeSafeFileName($file['safe_name'] ?? 'download.bin');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        header('Content-Disposition: attachment; filename="' . addslashes($safeName) . '"');
        jsonResponse(true, [
            'id' => $file['id'],
            'safeName' => $safeName,
            'mimeType' => $file['mime_type'],
            'dataUrl' => $file['file_url'],
        ]);
    }

    // List project folders, files, and bills
    $folders = [];
    $files = [];
    $bills = [];

    try {
        $fStmt = $db->prepare("SELECT * FROM project_folders WHERE project_id = :pid AND tenant_id = :tid ORDER BY name ASC");
        $fStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        $folders = $fStmt->fetchAll();

        $flStmt = $db->prepare("SELECT * FROM project_files WHERE project_id = :pid AND tenant_id = :tid ORDER BY created_at DESC");
        $flStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        $files = $flStmt->fetchAll();
    } catch (Exception $e) {
        // Fallback if migration has not yet run
    }

    $bStmt = $db->prepare("SELECT * FROM project_documents WHERE project_id = :pid AND tenant_id = :tid ORDER BY doc_date DESC, id DESC");
    $bStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
    $bills = $bStmt->fetchAll();

    jsonResponse(true, [
        'folders' => $folders,
        'files' => $files,
        'bills' => $bills,
    ]);
}

if ($method === 'POST' || $method === 'PUT') {
    $data = getJsonInput();
    $action = $data['action'] ?? 'upload_file';
    $projectId = $data['projectId'] ?? null;
    assertProjectAccess($db, $projectId, $tenantId);
    $reqPerm = in_array($action, ['rename_bill', 'link_bill', 'rename_file', 'move_file'], true) ? 'edit' : 'create';
    assertModulePermission($user, 'documents', $reqPerm, (string)$projectId);
    enforceIdempotencyGuard($db, $user, 'files:' . $action, $data);

    if ($action === 'create_folder') {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            jsonResponse(false, null, 'نام پوشه الزامی است.', 400);
        }
        assertFolderScope($db,$data['parentFolderId']??null,(string)$projectId,$tenantId);
        $stmt = $db->prepare("INSERT INTO project_folders (tenant_id, project_id, parent_folder_id, name, category, description, created_by)
                              VALUES (:tid, :pid, :parent, :name, :cat, :desc, :uid)");
        $stmt->execute([
            'tid' => $tenantId,
            'pid' => $projectId,
            'parent' => $data['parentFolderId'] ?? null,
            'name' => $name,
            'cat' => $data['category'] ?? 'other',
            'desc' => $data['description'] ?? null,
            'uid' => $userId,
        ]);
        jsonResponse(true, ['id' => $db->lastInsertId()], 'پوشه با موفقیت ایجاد شد.', 201);
    }

    if ($action === 'upload_file') {
        $rawName = $data['fileName'] ?? '';
        $mimeType = $data['mimeType'] ?? 'application/octet-stream';
        $dataUrl = $data['dataUrl'] ?? '';
        $verified=validatedUpload($rawName,$dataUrl);
        $sizeBytes=$verified['size'];$mimeType=$verified['mime'];$dataUrl=$verified['url'];
        assertFolderScope($db,$data['folderId']??null,(string)$projectId,$tenantId);

        assertNotExecutable($rawName, $mimeType, $dataUrl);
        $safeName = sanitizeSafeFileName($rawName);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));

        if (!in_array($ext, DEFAULT_PERMITTED_EXTENSIONS, true)) {
            jsonResponse(false, null, "فرمت (.{$ext}) مجاز نیست.", 400);
        }
        if ($sizeBytes <= 0 || $sizeBytes > FILE_MANAGER_MAX_BYTES) {
            jsonResponse(false, null, 'حجم فایل خارج از محدوده مجاز است.', 400);
        }

        $storageKey = bin2hex(random_bytes(16)) . '.' . $ext;
        $stmt = $db->prepare("INSERT INTO project_files
            (tenant_id, project_id, folder_id, safe_name, original_name, storage_key, extension, mime_type, size_bytes, category, file_url, description, uploaded_by)
            VALUES (:tid, :pid, :fid, :sname, :oname, :skey, :ext, :mime, :size, :cat, :url, :desc, :uid)");
        $stmt->execute([
            'tid' => $tenantId,
            'pid' => $projectId,
            'fid' => $data['folderId'] ?? null,
            'sname' => $safeName,
            'oname' => $rawName,
            'skey' => $storageKey,
            'ext' => $ext,
            'mime' => $mimeType,
            'size' => $sizeBytes,
            'cat' => $data['category'] ?? 'other',
            'url' => $dataUrl,
            'desc' => $data['description'] ?? null,
            'uid' => $userId,
        ]);
        jsonResponse(true, ['id' => $db->lastInsertId(), 'safeName' => $safeName], 'فایل با موفقیت ذخیره شد.', 201);
    }

    if ($action === 'upload_bill' || $action === 'link_bill' || $action === 'rename_bill') {
        if ($action === 'upload_bill') {
            $rawName = $data['fileName'] ?? 'bill.jpg';
            $mimeType = $data['fileType'] ?? 'image/jpeg';
            $fileUrl = $data['fileUrl'] ?? '';
            $verified=validatedUpload($rawName,$fileUrl);
            $mimeType=$verified['mime'];$fileUrl=$verified['url'];
            assertNotExecutable($rawName, $mimeType, $fileUrl);
            $safeName = sanitizeSafeFileName($rawName, 'jpg');

            $stmt = $db->prepare("INSERT INTO project_documents
                (tenant_id, project_id, title, category, file_url, file_name, file_type, file_size, related_type, related_id, related_label, amount, doc_date, notes, uploaded_by)
                VALUES (:tid, :pid, :title, :cat, :url, :fname, :ftype, :fsize, :rtype, :rid, :rlabel, :amt, :ddate, :notes, :uid)");
            $stmt->execute([
                'tid' => $tenantId,
                'pid' => $projectId,
                'title' => trim($data['title'] ?? $safeName),
                'cat' => $data['category'] ?? 'bill',
                'url' => $fileUrl,
                'fname' => $safeName,
                'ftype' => $mimeType,
                'fsize' => (string)$verified['size'],
                'rtype' => $data['relatedType'] ?? 'expense',
                'rid' => $data['relatedId'] ?? null,
                'rlabel' => $data['relatedLabel'] ?? null,
                'amt' => $data['amount'] ?? null,
                'ddate' => $data['date'] ?? date('Y-m-d'),
                'notes' => $data['notes'] ?? null,
                'uid' => $userId,
            ]);
            jsonResponse(true, ['id' => $db->lastInsertId()], 'بل با موفقیت ثبت شد.', 201);
        }

        if ($action === 'rename_bill') {
            $docId = $data['docId'] ?? null;
            $newTitle = trim($data['title'] ?? '');
            $newFileName = isset($data['fileName']) ? sanitizeSafeFileName($data['fileName'], 'jpg') : null;
            if (!$docId || $newTitle === '') {
                jsonResponse(false, null, 'عنوان جدید بل الزامی است.', 400);
            }
            $stmt = $db->prepare("UPDATE project_documents SET title = :title, file_name = COALESCE(:fname, file_name)
                                  WHERE id = :id AND project_id = :pid AND tenant_id = :tid");
            $stmt->execute([
                'title' => $newTitle,
                'fname' => $newFileName,
                'id' => $docId,
                'pid' => $projectId,
                'tid' => $tenantId,
            ]);
            jsonResponse(true, null, 'نام بل بروزرسانی شد.');
        }

        if ($action === 'link_bill') {
            $docId = $data['docId'] ?? null;
            $stmt = $db->prepare("UPDATE project_documents
                                  SET related_type = :rtype, related_id = :rid, related_label = :rlabel, amount = COALESCE(:amt, amount)
                                  WHERE id = :id AND project_id = :pid AND tenant_id = :tid");
            $stmt->execute([
                'rtype' => $data['relatedType'] ?? 'expense',
                'rid' => $data['relatedId'] ?? null,
                'rlabel' => $data['relatedLabel'] ?? null,
                'amt' => $data['amount'] ?? null,
                'id' => $docId,
                'pid' => $projectId,
                'tid' => $tenantId,
            ]);
            jsonResponse(true, null, 'اتصال بل بروزرسانی شد.');
        }
    }
}

if ($method === 'DELETE') {
    $projectId = $_GET['projectId'] ?? null;
    assertProjectAccess($db, $projectId, $tenantId);
    assertModulePermission($user, 'documents', 'del', (string)$projectId);
    $kind = $_GET['kind'] ?? 'file';
    $id = $_GET['id'] ?? null;
    if (!$id) {
        jsonResponse(false, null, 'شناسه الزامی است.', 400);
    }
    if ($kind === 'folder') {
        $stmt = $db->prepare("DELETE FROM project_folders WHERE id = :id AND project_id = :pid AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'pid' => $projectId, 'tid' => $tenantId]);
    } elseif ($kind === 'bill') {
        $stmt = $db->prepare("DELETE FROM project_documents WHERE id = :id AND project_id = :pid AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'pid' => $projectId, 'tid' => $tenantId]);
    } else {
        $stmt = $db->prepare("DELETE FROM project_files WHERE id = :id AND project_id = :pid AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'pid' => $projectId, 'tid' => $tenantId]);
    }
    jsonResponse(true, null, 'حذف با موفقیت انجام شد.');
}

jsonResponse(false,null,'روش یا عملیات پشتیبانی نمی‌شود.',405);
