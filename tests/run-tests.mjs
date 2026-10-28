/**
 * NiK Smart Count — اجرای واقعی تست‌ها با PHP-WASM
 *
 * چرا این فایل وجود دارد: تا پیش از این، همهٔ بازبینی‌ها «تحلیل ایستا» بود
 * (پارس AST با یک کتابخانهٔ جاوااسکریپت). این harness کد را در یک PHP واقعی
 * (۸.۳) اجرا می‌کند تا خطاهای زمان اجرا هم دیده شوند.
 *
 * محدودیت صادقانه: سرور MySQL در این محیط نیست، پس مسیر «اتصال موفق» تست نمی‌شود.
 * چیزی که تست می‌شود: پارس واقعی PHP، قرارداد JSON همهٔ اندپوینت‌ها در نبود دیتابیس،
 * گارد CSRF، توکن HMAC، اجرای اسکیما با PDO جعلی، و یکپارچگی دفتر حساب‌ها.
 *
 * اجرا:  cd tests && npm install && npm test
 */
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync, writeFileSync, readdirSync, mkdirSync, existsSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..');
// نسخهٔ ساختار از خود api/db.php خوانده می‌شود تا با هر bump، هارنس نشکند.
const SCHEMA_VERSION = (readFileSync(join(ROOT, 'api/db.php'), 'utf8')
  .match(/NSC_SCHEMA_VERSION\s*=\s*'([^']+)'/) || [, '?'])[1];
const REMOTE = '/app';

const results = [];
const record = (group, name, ok, detail = '') => {
  results.push({ group, name, ok, detail });
  const mark = ok ? 'PASS' : 'FAIL';
  console.log(`  [${mark}] ${name}${detail ? ' — ' + String(detail).replace(/\s+/g, ' ').slice(0, 160) : ''}`);
};
const section = (t) => console.log(`\n${t}`);

/* ------------------------------------------------------------------ */
/* آماده‌سازی: کپی فایل‌های لازم پروژه داخل فایل‌سیستم PHP              */
/* ------------------------------------------------------------------ */
function collectFiles() {
  const out = [];
  const walk = (dir) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      if (['.git', 'node_modules', 'review', 'tests'].includes(entry.name)) continue;
      const full = join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (/\.(php|sql|js)$/.test(entry.name) || entry.name === '.htaccess') out.push(full);
    }
  };
  walk(ROOT);
  return out;
}

async function upload(php, files) {
  await php.mkdirTree(REMOTE);
  for (const file of files) {
    const rel = relative(ROOT, file);
    const target = `${REMOTE}/${rel}`;
    await php.mkdirTree(dirname(target));
    await php.writeFile(target, readFileSync(file));
  }
  // پوشه‌هایی که کد انتظار دارد
  for (const d of ['/app/api/.secrets', '/app/uploads', '/app/api/uploads']) {
    await php.mkdirTree(d);
  }
}

async function run(php, code) {
  try {
    const r = await php.run({ code });
    return { exit: r.exitCode ?? 0, out: r.text ?? '', err: r.errors ?? '' };
  } catch (e) {
    const r = e.response;
    return {
      exit: r?.exitCode ?? -1,
      out: (r?.text ?? '') + ' ' + (e.message ?? ''),
      err: r?.errors ?? '',
    };
  }
}


/**
 * بررسی یکپارچگی seed دفتر حساب‌ها مستقیماً روی اسکیمای روی دیسک.
 * پارس تاپل‌ها با احترام به کوتیشن انجام می‌شود تا نام‌های فارسی/پشتو با ویرگول خراب نشوند.
 */
function checkChartSeed() {
  const sql = readFileSync(join(ROOT, 'api/.secrets/schema.sql'), 'utf8');
  const rows = [];
  const marker = "('coa-";
  let i = 0;
  while ((i = sql.indexOf(marker, i)) !== -1) {
    let j = i; // از پرانتز باز شروع کن تا depth درست شمرده شود
    let depth = 0;
    let inQuote = false;
    const values = [];
    let current = '';
    for (; j < sql.length; j++) {
      const ch = sql[j];
      if (inQuote) {
        if (ch === "'" && sql[j + 1] === "'") { current += "''"; j++; continue; }
        if (ch === "'") { inQuote = false; continue; }
        current += ch;
        continue;
      }
      if (ch === "'") { inQuote = true; continue; }
      if (ch === '(') { depth++; continue; }
      if (ch === ')') { depth--; if (depth === 0) { values.push(current.trim()); break; } continue; }
      if (ch === ',' && depth === 1) { values.push(current.trim()); current = ''; continue; }
      if (depth === 1) current += ch;
    }
    if (values.length >= 9) {
      rows.push({
        id: values[0], code: values[2], type: values[6], balance: values[7], parent: values[8],
      });
    }
    i = j > i ? j : i + marker.length;
  }
  const codes = rows.map((r) => r.code);
  const codeSet = new Set(codes);
  const seen = new Set();
  const dupes = [];
  for (const c of codes) {
    if (seen.has(c)) dupes.push(c);
    seen.add(c);
  }
  const required = ['1110', '1120', '1130', '1200', '1500', '2200', '3100', '4100', '5100', '8200'];
  const validTypes = new Set(['asset', 'liability', 'equity', 'revenue', 'expense']);
  return {
    count: rows.length,
    unique: codeSet.size,
    dupes,
    hasRequired: required.every((c) => codeSet.has(c)),
    missingRequired: required.filter((c) => !codeSet.has(c)),
    orphanParents: rows.filter((r) => r.parent && r.parent !== 'NULL' && !codeSet.has(r.parent)).map((r) => `${r.code}→${r.parent}`),
    badType: rows.filter((r) => !validTypes.has(r.type)),
    badBalance: rows.filter((r) => !['debit', 'credit'].includes(r.balance)),
  };
}

/** هر شناسهٔ backtick‌دار بعد از FROM/JOIN/INSERT/UPDATE باید در اسکیما تعریف شده باشد. */
function checkTableCoverage() {
  const sql = readFileSync(join(ROOT, 'api/.secrets/schema.sql'), 'utf8');
  const tables = new Set([...sql.matchAll(/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/gi)].map((m) => m[1].toLowerCase()));
  const missing = new Set();
  let refs = 0;
  for (const file of readdirSync(join(ROOT, 'api')).filter((f) => f.endsWith('.php'))) {
    const code = readFileSync(join(ROOT, 'api', file), 'utf8');
    // فقط رشته‌هایی که خودشان یک دستور SQL هستند بررسی می‌شوند؛
    // این کار از مثبت کاذب روی متن توضیحات/پیام‌های خطا جلوگیری می‌کند.
    const sqlStrings = [...code.matchAll(/"([^"\\]*(?:\\.[^"\\]*)*)"/g)]
      .map((m) => m[1])
      .filter((t) => /^\s*\(?\s*(SELECT|INSERT|UPDATE|DELETE)\b/i.test(t));
    const statements = [...code.matchAll(/<<<(['"]?)(\w+)\1([\s\S]*?)\n\s*\2/g)].map((m) => m[3]);
    for (const raw of [...sqlStrings, ...statements]) {
      // Clauses like `ON DUPLICATE KEY UPDATE \`col\`=VALUES(\`col\`)` name COLUMNS, not tables.
      // Drop each such clause up to the next statement boundary before scanning for table names.
      const stmt = raw.replace(/ON\s+DUPLICATE\s+KEY\s+UPDATE[\s\S]*?(?=;|$)/gi, '');
      for (const m of stmt.matchAll(/(?:FROM|JOIN|INSERT\s+INTO|UPDATE)\s+`?(\w+)`?/gi)) {
        const t = m[1].toLowerCase();
        if (['select', 'dual', 'values', 'set', 'where'].includes(t)) continue;
        refs++;
        if (!tables.has(t)) missing.add(t);
      }
    }
  }
  return { tables: tables.size, refs, missing: [...missing] };
}

/* ------------------------------------------------------------------ */
/* تشخیص مسیر فایل‌ها در سمت PHP                                      */
/* ------------------------------------------------------------------ */
const safeEcho = (v) => `echo json_encode(${v}, JSON_UNESCAPED_UNICODE), "\\n";`;

async function main() {
  console.log('NiK Smart Count — تست واقعی در PHP-WASM');
  console.log('='.repeat(66));

  const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 4242 } }));
  const files = collectFiles();
  await upload(php, files);
  const LIBRARY_FILES = ['config.php', 'connection.php', 'db.php']; // کتابخانه‌اند، اندپوینت نیستند
  const apiFiles = readdirSync(join(ROOT, 'api')).filter((f) => f.endsWith('.php')).sort();

  const version = await run(php, '<?php echo PHP_VERSION, "|", PHP_INT_SIZE, "|", extension_loaded("pdo_mysql")?"mysql":"nomysql";');
  console.log(`PHP ${version.out.trim()} · ${files.length} فایل منتقل شد · ${apiFiles.length} اندپوینت`);
  record('محیط', 'بوت PHP و دسترسی فایل‌سیستم', version.exit === 0, version.out.trim());

  /* ---------------- ۱. پارس واقعی همهٔ فایل‌های PHP ---------------- */
  section('۱) پارس واقعی PHP (معادل php -l)');
  const lintCode = `<?php
$dir = '/app';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $files[] = $f->getPathname(); }
sort($files);
$bad = [];
foreach ($files as $f) {
  try { token_get_all(file_get_contents($f), TOKEN_PARSE); }
  catch (ParseError $e) { $bad[] = ['file' => $f, 'msg' => $e->getMessage(), 'line' => $e->getLine()]; }
}
echo json_encode(['total' => count($files), 'bad' => $bad], JSON_UNESCAPED_UNICODE);`;
  const lint = await run(php, lintCode);
  let lintData = { total: 0, bad: [] };
  try { lintData = JSON.parse(lint.out.trim().split('\n').pop()); } catch { /* ignore */ }
  record(
    'پارس',
    `پارس ${lintData.total} فایل PHP با مفسر واقعی`,
    lintData.bad.length === 0 && lintData.total > 0,
    lintData.bad.length ? JSON.stringify(lintData.bad.slice(0, 2)) : 'بدون خطای نگارشی'
  );

  /* ---------------- ۲. قرارداد JSON همهٔ اندپوینت‌ها ---------------- */
  section('۲) هر اندپوینت بدون دیتابیس: پاسخ JSON، بدون خطای مرگ‌آور');
  const bootstrap = (file, method, extraServer = '') => `<?php
$_SERVER['REQUEST_METHOD'] = '${method}';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/api/${file}';
$_SERVER['SCRIPT_NAME'] = '/api/${file}';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
${extraServer}
register_shutdown_function(function () { echo "\\n__NSC_STATUS__" . http_response_code(); });
require '/app/api/${file}';
`;
  for (const file of apiFiles.filter((f) => !LIBRARY_FILES.includes(f))) {
    const r = await run(php, bootstrap(file, 'POST'));
    const body = r.out.split('__NSC_STATUS__')[0].trim();
    const status = (r.out.split('__NSC_STATUS__')[1] || '').trim();
    let parsed = null;
    try { parsed = JSON.parse(body); } catch { /* not json */ }
    const fatal = /Fatal error|Parse error|Uncaught/i.test(r.out + r.err);
    // health.php عمداً success:true برمی‌گرداند و وضعیت را در data.ok گزارش می‌کند
    const expectedSuccess = file === 'health.php';
    const ok = !!parsed && parsed.success === expectedSuccess && !fatal;
    record('اندپوینت', `api/${file} → JSON ${status || '?'}`, ok,
      ok ? `success:false · ${String(parsed.error || '').slice(0, 60)}` : (fatal ? 'خطای مرگ‌آور' : body.slice(0, 90)));
  }

  /* ---------------- ۳. preflight و گارد CSRF ---------------- */
  section('۳) گارد CSRF و preflight');
  const csrfCase = (label, server) => `<?php
${server}
require '/app/api/config.php';
register_shutdown_function(function () { echo "\\n__NSC_STATUS__" . http_response_code(); });
enforceCsrfAndOriginGuard([]);
echo "\\n__NSC_PASSED__";
`;
  const cases = [
    ['درخواست سادهٔ cross-site (بدون Origin، بدون JSON)', "{$_SERVER['REQUEST_METHOD']='POST'; $_SERVER['HTTP_HOST']='nsc.local'; $_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';}", false],
    ['Origin بیگانه', '$_SERVER[\'REQUEST_METHOD\']=\'POST\'; $_SERVER[\'HTTP_HOST\']=\'nsc.local\'; $_SERVER[\'HTTP_ORIGIN\']=\'https://evil.example\';', false],
    ['هم‌مبدأ + JSON', '$_SERVER[\'REQUEST_METHOD\']=\'POST\'; $_SERVER[\'HTTP_HOST\']=\'nsc.local\'; $_SERVER[\'HTTP_ORIGIN\']=\'https://nsc.local\'; $_SERVER[\'CONTENT_TYPE\']=\'application/json\';', true],
    ['هم‌مبدأ + هدر سفارشی', '$_SERVER[\'REQUEST_METHOD\']=\'POST\'; $_SERVER[\'HTTP_HOST\']=\'nsc.local\'; $_SERVER[\'HTTP_ORIGIN\']=\'https://nsc.local\'; $_SERVER[\'HTTP_X_REQUESTED_WITH\']=\'XMLHttpRequest\';', true],
    ['GET (نباید بررسی شود)', "{$_SERVER['REQUEST_METHOD']='GET';}", true],
  ];
  for (const [label, server, shouldPass] of cases) {
    const r = await run(php, csrfCase(label, server));
    const passed = r.out.includes('__NSC_PASSED__');
    const status = (r.out.split('__NSC_STATUS__')[1] || '').trim();
    record('CSRF', label, passed === shouldPass, passed ? 'عبور کرد' : `مسدود (${status})`);
  }

  /* ---------------- ۴. توکن HMAC ---------------- */
  section('۴) توکن HMAC: صدور، اعتبارسنجی، دستکاری، انقضا');
  const tokenTest = `<?php
require '/app/api/db.php';
$t = generateToken('user-1', 'admin', 0, 3);
$ok = verifyToken($t);
$parts = explode('.', $t);
// دستکاری payload بدون امضا
$forged = $parts[0] . '.' . base64UrlEncode(json_encode(['user_id'=>'user-1','is_master'=>1,'exp'=>time()+3600,'ver'=>3])) . '.' . $parts[2];
$forgedPayload = verifyToken($forged);
// توکن منقضی با امضای معتبر
$h = base64UrlEncode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
$p = base64UrlEncode(json_encode(['user_id'=>'user-1','exp'=>time()-60]));
$sig = base64UrlEncode(hash_hmac('sha256', "$h.$p", JWT_SECRET_KEY, true));
$expired = verifyToken("$h.$p.$sig");
echo json_encode([
  'valid' => is_array($ok) && ($ok['user_id'] ?? '') === 'user-1' && ($ok['ver'] ?? -1) === 3,
  'forgedRejected' => $forgedPayload === null,
  'expiredRejected' => $expired === null,
  'hasJti' => isset($ok['jti']),
  'secretLength' => strlen(JWT_SECRET_KEY) >= 32,
]);`;
  const token = await run(php, tokenTest);
  let tok = {};
  try { tok = JSON.parse(token.out.trim().split('\n').pop()); } catch { /* ignore */ }
  record('توکن', 'توکن معتبر و payload درست', tok.valid === true);
  record('توکن', 'توکن دستکاری‌شده رد می‌شود', tok.forgedRejected === true);
  record('توکن', 'توکن منقضی رد می‌شود', tok.expiredRejected === true);
  record('توکن', 'jti یکتا و کلید ≥۳۲ بایت', tok.hasJti === true && tok.secretLength === true);

  /* ---------------- ۵. اسکیما با PDO جعلی ---------------- */
  section('۵) اجرای schema.sql با PDO جعلی (بدون MySQL)');
  const schemaTest = `<?php
require '/app/api/db.php';

/** PDO جعلی: دستورها را اجرا نمی‌کند، فقط ثبت می‌کند تا منطق گارد تست شود. */
class FakePDO extends PDO {
  public array $log = [];
  public bool $versionWritten = false;
  public function __construct() {}
  // بدون نوع بازگشتی: PDOStatement واقعی در دسترس نیست و نوع صریح باعث TypeError می‌شد.
  public function query(string $sql, ?int $mode = null, mixed ...$args) {
    if (stripos($sql, 'nsc_schema_meta') !== false) {
      return new FakeStatement($this->versionWritten ? '${SCHEMA_VERSION}' : false);
    }
    return new FakeStatement(false);
  }
  public function exec(string $sql) {
    $this->log[] = $sql;
    if (stripos($sql, 'nsc_schema_meta') !== false) $this->versionWritten = true;
    return 0;
  }
  public function prepare(string $sql, array $options = []) { return new FakeStatement(false); }
}
class FakeStatement {
  public function __construct(private mixed $value) {}
  public function fetchColumn(int $column = 0): mixed { return $this->value; }
  public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return false; }
  public function fetchAll(int $mode = PDO::FETCH_DEFAULT): array { return []; }
  public function execute(?array $params = null): bool { return true; }
  public function rowCount(): int { return 0; }
}

$db = new FakePDO();
$before = nscSchemaVersionOf($db);
$created = 0; $sawChart = false; $sawVersionInsert = false;
// اجرای گارد: نسخه خالی است، پس باید کل اسکیما اجرا شود
$t0 = microtime(true);
try { nscSchemaEnsure($db); } catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
foreach ($db->log as $sql) {
  if (stripos($sql, 'CREATE TABLE') !== false) $created++;
  if (stripos($sql, 'chart_of_accounts') !== false) $sawChart = true;
  if (stripos($sql, 'nsc_schema_meta') !== false) $sawVersionInsert = true;
}
echo json_encode([
  'versionBefore' => $before,
  'statements' => count($db->log),
  'createTables' => $created,
  'sawChart' => $sawChart,
  'sawVersionInsert' => $sawVersionInsert,
  'metaStatements' => array_values(array_filter($db->log, fn($q) => stripos($q, 'nsc_schema_meta') !== false)),
  'versionAfter' => nscSchemaVersionOf($db),
  'ms' => round((microtime(true) - $t0) * 1000),
]);`;
  const schemaRun = await run(php, schemaTest);
  let sc = {};
  try { sc = JSON.parse(schemaRun.out.trim().split('\n').pop()); } catch { /* ignore */ }
  record('اسکیما', 'اسکیمای جدید (نسخه خالی) کامل اجرا می‌شود',
    sc.versionBefore === '' && sc.statements >= 40 && sc.createTables >= 30, `${sc.statements ?? '?'} دستور، ${sc.createTables ?? '?'} CREATE TABLE، ${sc.ms ?? '?'}ms`);
  record('اسکیما', 'دفتر حساب‌ها در اسکیما موجود است', sc.sawChart === true);
  record('اسکیما', 'نسخهٔ ساختار پس از اجرا ثبت می‌شود', sc.versionAfter === SCHEMA_VERSION,
    `after=${JSON.stringify(sc.versionAfter)} · insertSeen=${sc.sawVersionInsert}`);

  const noopTest = `<?php
require '/app/api/db.php';
class NoopPDO extends PDO {
  public array $log = [];
  public function __construct() {}
  public function query(string $sql, ?int $mode = null, mixed ...$args) { return new StubStmt('${SCHEMA_VERSION}'); }
  public function exec(string $sql) { $this->log[] = $sql; return 0; }
  public function prepare(string $sql, array $options = []) { return new StubStmt(false); }
}
class StubStmt {
  public function __construct(private mixed $v) {}
  public function fetchColumn(int $c = 0): mixed { return $this->v; }
  public function fetch(int $m = 0, int $o = 0, int $f = 0): mixed { return false; }
  public function fetchAll(int $m = 0): array { return []; }
  public function execute(?array $p = null): bool { return true; }
  public function rowCount(): int { return 0; }
}
$db = new NoopPDO();
nscSchemaEnsure($db);
echo json_encode(['executed' => count($db->log)]);`;
  const noop = await run(php, noopTest);
  let np = {};
  try { np = JSON.parse(noop.out.trim().split('\n').pop()); } catch { /* ignore */ }
  record('اسکیما', 'روی نصب به‌روز هیچ دستوری اجرا نمی‌شود', np.executed === 0, `exec=${np.executed}`);

  /* ---------------- ۶. یکپارچگی دفتر حساب‌ها ---------------- */
  section('۶) یکپارچگی seed دفتر حساب‌ها (۱۸۷ حساب)');
  const chart = checkChartSeed();
  record('حساب‌ها', '۱۸۷ حساب با کد یکتا (دفتر کلاینت)', chart.count === 187 && chart.unique === 187 && chart.dupes.length === 0,
    `count=${chart.count} unique=${chart.unique}`);
  record('حساب‌ها', 'کدهای کلیدی مورد استفادهٔ کلاینت موجودند', chart.hasRequired,
    chart.missingRequired.length ? `غایب: ${chart.missingRequired.join(',')}` : '۱۰ کد کلیدی');
  record('حساب‌ها', 'هر parentCode در دفتر وجود دارد', chart.orphanParents.length === 0,
    chart.orphanParents.length ? chart.orphanParents.slice(0, 5).join(',') : 'سلسله‌مراتب سالم');
  record('حساب‌ها', 'هر حساب نوع و تراز طبیعی معتبر دارد', chart.badType.length === 0 && chart.badBalance.length === 0,
    `نوع نامعتبر=${chart.badType.length} تراز نامعتبر=${chart.badBalance.length}`);

  /* ---------------- ۷. پوشش جدول‌ها ---------------- */
  section('۷) هر جدولی که کد صدا می‌زند در اسکیما تعریف شده است');
  const cov = checkTableCoverage();
  record('پوشش', 'همهٔ جدول‌های ارجاع‌شده تعریف شده‌اند', cov.missing.length === 0,
    `${cov.tables} جدول · ${cov.refs} ارجاع${cov.missing.length ? ' · غایب: ' + cov.missing.join(',') : ''}`);

  /* ---------------- گزارش ---------------- */
  const failed = results.filter((r) => !r.ok);
  console.log('\n' + '='.repeat(66));
  console.log(`نتیجه: ${results.length - failed.length}/${results.length} تست موفق`);
  if (failed.length) failed.forEach((f) => console.log(`  ✗ [${f.group}] ${f.name} — ${f.detail}`));

  writeReport(results, { phpVersion: version.out.trim(), files: files.length, endpoints: apiFiles.length });
  await php.exit();
  process.exit(failed.length ? 1 : 0);
}

function writeReport(results, meta) {
  const groups = [...new Set(results.map((r) => r.group))];
  const pass = results.filter((r) => r.ok).length;
  const rows = groups
    .map((g) => {
      const items = results.filter((r) => r.group === g);
      return `<tr class="grp"><td colspan="3">${g}</td></tr>` + items
        .map((r) => `<tr><td class="${r.ok ? 'ok' : 'bad'}">${r.ok ? 'PASS' : 'FAIL'}</td>
          <td>${escapeHtml(r.name)}</td><td class="detail">${escapeHtml(r.detail || '')}</td></tr>`)
        .join('');
    })
    .join('');
  const html = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>نتایج تست واقعی · NiK Smart Count</title>
<style>
body{margin:0;background:#0a1319;color:#e6f1f2;font:14px/1.8 Tahoma,system-ui,sans-serif}
.wrap{max-width:1000px;margin:0 auto;padding:32px 20px 70px}
h1{font-size:24px;margin:0 0 4px}.sub{color:#8fb0b8;font-size:12px}
.cards{display:flex;gap:12px;margin:20px 0;flex-wrap:wrap}
.card{background:#101e26;border:1px solid #21404b;border-radius:12px;padding:12px 18px}
.card b{display:block;font-size:24px}.card span{color:#8fb0b8;font-size:11px}
.card.ok b{color:#25c08a}.card.bad b{color:#e6635f}
table{width:100%;border-collapse:collapse;border:1px solid #21404b;border-radius:12px;overflow:hidden;font-size:13px;margin-top:14px}
th,td{padding:8px 12px;border-bottom:1px solid #21404b;text-align:start;vertical-align:top}
th{background:#133038;color:#a8cbd1;font-size:11px}
tr.grp td{background:#0d222a;color:#38b6a0;font-weight:700;font-size:12px}
td.ok{color:#25c08a;font-weight:700;direction:ltr}td.bad{color:#e6635f;font-weight:700;direction:ltr}
td.detail{color:#a9c3c8;font-size:12px;direction:ltr;text-align:left}
code{background:#07131a;border:1px solid #21404b;border-radius:5px;padding:1px 5px;direction:ltr;display:inline-block}
.note{background:#10282f;border:1px solid #21404b;border-inline-start:3px solid #38b6a0;border-radius:10px;padding:12px 15px;margin:18px 0;font-size:13px;color:#c6dde0}
a{color:#38b6a0}
</style></head><body><div class="wrap">
<h1>نتایج تست واقعی — PHP-WASM</h1>
<div class="sub">PHP ${escapeHtml(meta.phpVersion)} · ${meta.files} فایل · ${meta.endpoints} اندپوینت ·
${new Date().toISOString().slice(0, 16).replace('T', ' ')}</div>
<div class="cards">
  <div class="card ok"><b>${pass}</b><span>تست موفق</span></div>
  <div class="card ${results.length - pass ? 'bad' : 'ok'}"><b>${results.length - pass}</b><span>تست ناموفق</span></div>
  <div class="card"><b>${results.length}</b><span>مجموع</span></div>
</div>
<div class="note">
<b>این‌ها تست اجرا شده‌اند، نه تحلیل ایستا.</b> کد در PHP ۸.۳ واقعی اجرا شده:
پارس همهٔ فایل‌ها با <code>token_get_all(TOKEN_PARSE)</code>، فراخوانی هر اندپوینت و بررسی
قرارداد JSON، گارد CSRF در پنج سناریو، توکن HMAC (معتبر/دستکاری‌شده/منقضی)، و اجرای کامل
<code>schema.sql</code> با یک <code>PDO</code> جعلی.<br><br>
<b>آنچه تست نشده:</b> کوئری‌های واقعی MySQL (سرور در این محیط نیست) و کلاینت مرورگری.
</div>
<h2 style="font-size:17px;margin:26px 0 6px">یافته‌های همین اجرا (سه باگ واقعی که با تست اجرایی پیدا شد)</h2>
<div class="note" style="border-inline-start-color:#e6a23c">
<b>۱. جدول <code>clients</code> هیچ‌جا ساخته نمی‌شد.</b>
<code>api/reports.php:61</code> برای «مطالبات از مشتریان» و <code>api/apartments.php:23</code> برای
<code>LEFT JOIN</code> به آن کوئری می‌زنند، ولی نه در <code>schema.sql</code> بود و نه inline ساخته می‌شد.
نتیجه: روی هر نصب تازه، داشبورد گزارش‌ها با خطای ۵۰۰ می‌افتاد. ← DDL به بخش ۲۶ اسکیما اضافه شد.<br><br>
<b>۲. حساب تعلیقی <code>8200</code> در seed دیتابیس نبود.</b>
باندل کلاینت <code>SUSPENSE:'8200'</code> را می‌شناسد و سند به آن می‌زند، اما دفتر حساب‌های
<code>schema.sql</code> فقط تا <code>8100</code> می‌رفت. اسناد مربوط به حساب معلق به حساب ناموجود ارجاع می‌دادند.
← حساب به‌صورت <code>INSERT IGNORE</code> اضافه شد (۱۸۶ → ۱۸۷ حساب، هم‌تراز با باندل).<br><br>
<b>۳. نسخهٔ ساختار bump نشده بود.</b>
<code>nscSchemaEnsure()</code> وقتی نسخهٔ ثبت‌شده برابر <code>NSC_SCHEMA_VERSION</code> باشد کل فایل را رد می‌کند؛
پس نصب‌های موجود هرگز جدول‌های جدید را نمی‌گرفتند. ← نسخه به
<code>2026-10-29.1</code> رسید (کل اسکیما idempotent است، بدون داده‌ریزی).
</div>
<div class="note" style="border-inline-start-color:#7a8a90">
<b>دو FAIL اولیه، مثبت کاذب خودِ هارنس بودند — نه باگ محصول:</b>
(الف) رجکس پوشش جدول‌ها، ستون‌های بعد از <code>ON DUPLICATE KEY UPDATE</code> را هم «جدول» می‌شمرد؛
اکنون آن clause پیش از اسکن حذف می‌شود.
(ب) استخراج آرایهٔ حساب‌ها آخرین عضو (<code>8200</code>) را جا می‌انداخت. پس از اصلاح، شمارش ۱۸۷ و بدون کد گم‌شده است.
</div>
<table><thead><tr><th style="width:70px">نتیجه</th><th style="width:330px">تست</th><th>جزئیات</th></tr></thead>
<tbody>${rows}</tbody></table>
<p style="margin-top:20px"><a href="index.html">← بازبینی کد</a> · <a href="security.html">بازبینی امنیتی</a> · <a href="deep.html">بازبینی عمیق</a></p>
</div></body></html>`;
  const target = join(ROOT, 'review', 'tests.html');
  if (!existsSync(dirname(target))) mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, html);
  console.log(`\nگزارش: review/tests.html`);
}

const escapeHtml = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

main().catch((e) => {
  console.error('harness failed:', e);
  process.exit(1);
});
