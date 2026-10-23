<?php

use DreamLayer\UnoPIM\Compatibility\HostSupport;

$host = getenv('UNOPIM_HOST_ROOT') ?: getcwd();
if (! is_file($host.'/vendor/autoload.php')) {
    fwrite(STDERR, "Run from an installed UnoPim application directory.\n");
    exit(1);
}
require $host.'/vendor/autoload.php';
$available = HostSupport::available();
echo ($available ? 'PASS' : 'MISSING').": required product APIs\n";
echo (HostSupport::nativeChat() ? 'AVAILABLE' : 'UNAVAILABLE').": optional native chat bridge\n";
echo "This read-only capability check does not certify an untested UnoPim release. See docs/COMPATIBILITY.md.\n";
exit($available ? 0 : 1);
