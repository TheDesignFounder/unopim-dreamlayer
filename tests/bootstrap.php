<?php

$host = getenv('UNOPIM_HOST_ROOT') ?: getcwd();
if (! is_file($host.'/bootstrap/app.php') || ! is_file($host.'/vendor/autoload.php')) {
    throw new RuntimeException('Set UNOPIM_HOST_ROOT to the isolated UnoPIM host.');
}
if (! str_contains(strtolower(getenv('DB_DATABASE') ?: ''), 'test')) {
    throw new RuntimeException('DreamLayer tests require an explicitly named test database.');
}
if (is_file($host.'/tests/bootstrap.php')) {
    require $host.'/tests/bootstrap.php';
} else {
    // Older released tags provide TestCase but no PHPUnit bootstrap. Keep this
    // harness in the extension, without adding files to the host application.
    foreach (['APP_ENV' => 'testing', 'BCRYPT_ROUNDS' => '4', 'CACHE_STORE' => 'array', 'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    require $host.'/vendor/autoload.php';
}
