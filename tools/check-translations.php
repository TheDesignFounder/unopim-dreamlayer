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
        // Shared brand names and a few naturally identical labels are valid.
        $sameAllowed = ['settings', 'ui-df37e16ca0', 'ui-953da4c231'];
        $locale = basename(dirname($file));
        $sameAllowed = array_merge($sameAllowed, match ($locale) {
            'fr_FR' => ['image'], 'da_DK' => ['download'], 'nl_NL' => ['ui-a020541168'], default => [],
        });
        if (! str_starts_with($locale, 'en_') && $map[$key] === $source && ! in_array($key, $sameAllowed, true)) {
            throw new RuntimeException('Untranslated English label: '.$file.' '.$key);
        }
        if (! str_starts_with($locale, 'en_') && str_contains($map[$key], 'Your key is stored encrypted.')) {
            throw new RuntimeException('Untranslated English sentence: '.$file.' '.$key);
        }
        preg_match_all('/:(?:count|name|time|status)\b/', $source, $a);
        preg_match_all('/:(?:count|name|time|status)\b/', $map[$key], $b);
        sort($a[0]);
        sort($b[0]);
        if ($a[0] !== $b[0] || ! is_string($map[$key]) || trim($map[$key]) === '' || preg_match('/DLITEM|ZZPH/i', $map[$key]) || (! str_contains($source, "\n") && str_contains($map[$key], "\n"))) {
            throw new RuntimeException('Invalid translation: '.$file.' '.$key);
        }
    }
}
echo 'PASS: '.count($files).' locales, '.count($english)." matching keys and placeholders\n";
