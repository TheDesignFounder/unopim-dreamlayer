<?php

$host = getenv('UNOPIM_HOST_ROOT') ?: getcwd();
if (! is_file($host.'/tests/bootstrap.php')) {
    throw new RuntimeException('Set UNOPIM_HOST_ROOT to the isolated UnoPIM host.');
}
if (! str_contains(strtolower(getenv('DB_DATABASE') ?: ''), 'test')) {
    throw new RuntimeException('DreamLayer tests require an explicitly named test database.');
}
require $host.'/tests/bootstrap.php';
