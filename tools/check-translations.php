<?php

$root = __DIR__.'/../resources/lang';
$english = require $root.'/en_US/app.php';
$files = glob($root.'/*/app.php');
if (count($files) !== 33) {
    throw new RuntimeException('Expected all 33 locale files, found '.count($files));
}
foreach ($files as $file) {
    $map = require $file;
    if (array_keys($map) !== array_keys($english)) {
        throw new RuntimeException('Key mismatch: '.$file);
    }
    foreach ($english as $key => $source) {
        preg_match_all('/:(?:count|name|time|status)\b/', $source, $a);
        preg_match_all('/:(?:count|name|time|status)\b/', $map[$key], $b);
        sort($a[0]);
        sort($b[0]);
        if ($a[0] !== $b[0] || ! is_string($map[$key]) || trim($map[$key]) === '' || preg_match('/DLITEM|ZZPH/i', $map[$key])) {
            throw new RuntimeException('Invalid translation: '.$file.' '.$key);
        }
    }
}
echo 'PASS: '.count($files).' locales, '.count($english)." matching keys and placeholders\n";
