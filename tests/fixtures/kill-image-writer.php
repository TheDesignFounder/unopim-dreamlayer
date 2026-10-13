<?php

use DreamLayer\UnoPIM\Services\ImageStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

// Run only in the isolated local test host. Exercises a real SIGKILL inside ImageStore.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_contains(config('database.connections.'.config('database.default').'.database'), '_test') || ! extension_loaded('pcntl')) {
    throw new RuntimeException('Requires an isolated test database and pcntl');
}
$store = new ImageStore;
$disk = Storage::disk('local');
$root = 'writer-crash-test-'.bin2hex(random_bytes(8));
$image = imagecreatetruecolor(32, 32);
imagealphablending($image, false);
imagesavealpha($image, true);
imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
imagefilledrectangle($image, 8, 8, 24, 24, imagecolorallocatealpha($image, 40, 80, 200, 0));
ob_start();
imagepng($image);
$png = ob_get_clean();
imagedestroy($image);
function check(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}
try {
    foreach (['new', 'replacement'] as $kind) {
        $path = $root.'/'.$kind.'/result.png';
        $marker = $disk->path($root.'/'.$kind.'/ready');
        if ($kind === 'replacement') {
            $store->put($path, $png);
        }
        $pid = pcntl_fork();
        check($pid >= 0, 'Fork failed');
        if ($pid === 0) {
            $writer = new class($marker) extends ImageStore
            {
                public function __construct(private string $marker) {}

                protected function writeBytes($stream, string $bytes): void
                {
                    fwrite($stream, substr($bytes, 0, 12));
                    fflush($stream);
                    fsync($stream);
                    file_put_contents($this->marker, (string) getmypid());
                    while (true) {
                        usleep(100000);
                    }
                }
            };
            $writer->put($path, $png);
            exit(3);
        }
        try {
            $deadline = microtime(true) + 10;
            while (! is_file($marker) && microtime(true) < $deadline) {
                usleep(20000);
            }
            check(is_file($marker), 'Writer did not reach its first 12 bytes');
            check($kind === 'replacement' ? $store->read($path) === $png : ! $disk->exists($path), 'Partial bytes became visible before termination');
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        check(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'Writer was not killed');
        check($kind === 'replacement' ? $store->read($path) === $png : ! $disk->exists($path), 'Partial bytes became visible after SIGKILL');
        $orphans = glob(dirname($marker).'/.image-*');
        check(count($orphans) === 1 && filesize($orphans[0]) === 12, 'Expected an unreferenced 12-byte staging file');
        $store->put($path, $png);
        check($store->read($path) === $png, 'Retry did not deliver the complete original PNG');
        echo 'PASS '.$kind.': writer killed after 12 bytes; target safe; retry preserved PNG/alpha bytes'.PHP_EOL;
    }
} finally {
    $disk->deleteDirectory($root);
}
