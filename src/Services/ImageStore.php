<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Models\Workflow;
use Illuminate\Support\Facades\Storage;

/** Validated PNG delivery on the shared private local filesystem. */
class ImageStore
{
    public static function validate(string $bytes): void
    {
        $length = strlen($bytes);
        if ($length > 20 * 1024 * 1024 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new \RuntimeException('Invalid PNG delivery');
        }
        // GD may accept truncated files. Require complete chunks, CRCs and a final IEND.
        $offset = 8;
        $data = false;
        $ended = false;
        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            if ($size > $length - $offset - 12) {
                break;
            }
            $type = substr($bytes, $offset + 4, 4);
            if ($offset === 8 && ($type !== 'IHDR' || $size !== 13)) {
                break;
            }
            $chunk = substr($bytes, $offset + 4, $size + 4);
            if (! hash_equals(hash('crc32b', $chunk, true), substr($bytes, $offset + 8 + $size, 4))) {
                break;
            }
            $offset += $size + 12;
            $data = $data || $type === 'IDAT';
            if ($type === 'IEND') {
                $ended = $size === 0 && $offset === $length;
                break;
            }
        }
        $info = @getimagesizefromstring($bytes);
        if (! $ended || ! $data || ! $info || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || ! ($decoded = @imagecreatefromstring($bytes))) {
            throw new \RuntimeException('Incomplete or invalid PNG delivery');
        }
        imagedestroy($decoded);
    }

    public function read(string $path): string
    {
        $file = Storage::disk('local')->path($path);
        $bytes = @file_get_contents($file, false, null, 0, 20 * 1024 * 1024 + 1);
        if ($bytes === false) {
            throw new \RuntimeException('Image delivery unavailable');
        }
        self::validate($bytes);

        return $bytes;
    }

    public function valid(?string $path): bool
    {
        if (! $path) {
            return false;
        }
        try {
            $this->read($path);

            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function damaged(Workflow $workflow): bool
    {
        $paths = array_column($workflow->progress ?? [], 'result_path');
        if ($workflow->result_path) {
            $paths[] = $workflow->result_path;
        }
        foreach ($paths as $path) {
            if (! $this->valid($path)) {
                return true;
            }
        }

        return false;
    }

    public function put(string $path, string $bytes): void
    {
        self::validate($bytes);
        $target = Storage::disk('local')->path($path);
        $directory = dirname($target);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Private image storage unavailable');
        }
        $temporary = tempnam($directory, '.image-');
        if ($temporary === false || dirname($temporary) !== $directory) {
            if ($temporary !== false) {
                @unlink($temporary);
            }
            throw new \RuntimeException('Private image staging unavailable');
        }
        $stream = null;
        try {
            @chmod($temporary, 0600);
            $stream = fopen($temporary, 'wb');
            if (! $stream) {
                throw new \RuntimeException('Private image staging unavailable');
            }
            $this->writeBytes($stream, $bytes);
            if (! fflush($stream) || ! fsync($stream)) {
                throw new \RuntimeException('Image write interrupted');
            }
            fclose($stream);
            $stream = null;
            $written = file_get_contents($temporary);
            if ($written === false || ! hash_equals(hash('sha256', $bytes), hash('sha256', $written))) {
                throw new \RuntimeException('Image write incomplete');
            }
            self::validate($written);
            // Publish only complete bytes; a crash before rename leaves an unreferenced temp file.
            if (! rename($temporary, $target)) {
                throw new \RuntimeException('Image publish interrupted');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    protected function writeBytes($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset, 65536));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Image write interrupted');
            }
            $offset += $written;
        }
    }
}
