<?php
/** Shared PDO connection and safe diagnostics; never returns credentials. */
function nscConnect(array &$settings): PDO {
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('NSC_DRIVER_MISSING');
    $open = function (string $host) use ($settings): PDO {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $settings['PORT'], $settings['NAME']);
        return new PDO($dsn, $settings['USER'], $settings['PASS'], [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>8
        ]);
    };
    try { return $open($settings['HOST']); }
    catch (PDOException $e) {
        $number=(int)($e->errorInfo[1]??$e->getCode());
        if ($settings['HOST']!=='localhost' || !in_array($number,[2002,2003],true)) throw $e;
        $connection=$open('127.0.0.1');$settings['HOST']='127.0.0.1';return $connection;
    }
}
function nscConnectionError(Throwable $e): array {
    $code=$e instanceof PDOException?(int)($e->errorInfo[1]??$e->getCode()):0;
    $messages=[
      1045=>'ورود MySQL رد شد. نام کامل یوزر و رمز همان یوزر دیتابیس را در cPanel بررسی کنید.',
      1044=>'یوزر به این دیتابیس دسترسی ندارد. Add User To Database و ALL PRIVILEGES را ثبت کنید.',
      1049=>'نام دیتابیس پیدا نشد. نام کامل همراه پیشوند cPanel را وارد کنید.',
      2002=>'اتصال به سرور MySQL برقرار نشد. آدرس سرور و پورت را از هاست تأیید کنید.',
      2003=>'سرور MySQL یا پورت در دسترس نیست.',
      2005=>'نام سرور MySQL پیدا نشد. Database host نام دیتابیس نیست.'
    ];
    if ($e->getMessage()==='NSC_DRIVER_MISSING') return ['code'=>'NSC-DRIVER','message'=>'افزونه pdo_mysql در PHP هاست فعال نیست؛ از پشتیبانی هاست بخواهید فعال کند.'];
    return ['code'=>'NSC-DB-'.($code?:'OTHER'),'message'=>$messages[$code]??'اتصال یا ذخیره تنظیمات ناکام شد. دسترسی PHP و تنظیمات هاست بررسی شود.'];
}
