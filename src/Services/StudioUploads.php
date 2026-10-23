<?php

namespace DreamLayer\UnoPIM\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Private, expiring sources. Selecting a file never uploads it to DreamLayer. */
class StudioUploads
{
    public function store(UploadedFile $file, int $owner, string $conversation): array
    {
        $bytes = $file->get();
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            && $info[0] * $info[1] <= 40000000, 422, trans('dreamlayer::studio.upload-invalid'));
        $image = @imagecreatefromstring($bytes);
        abort_unless($image, 422, trans('dreamlayer::studio.upload-invalid'));
        imagedestroy($image);
        $id = (string) Str::uuid();
        $path = 'dreamlayer/uploads/'.$owner.'/'.$conversation.'/'.$id;
        abort_unless(Storage::disk('local')->put($path, $bytes), 503);
        $token = Crypt::encryptString(json_encode(['id' => $id, 'owner' => $owner, 'conversation' => $conversation, 'expires' => now()->addDay()->timestamp]));

        return ['token' => $token, 'name' => Str::limit(basename($file->getClientOriginalName()), 100),
            'image_url' => route('dreamlayer.studio.upload.image', ['token' => $token, 'conversation_id' => $conversation])];
    }

    public function resolve(string $token, int $owner, string $conversation): string
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, trans('dreamlayer::studio.upload-expired'));
        }
        abort_unless(($data['owner'] ?? null) === $owner && ($data['conversation'] ?? null) === $conversation, 404);
        abort_unless(Str::isUuid($data['id'] ?? '') && Str::isUuid($conversation) && ($data['expires'] ?? 0) > now()->timestamp, 422, trans('dreamlayer::studio.upload-expired'));
        $path = Storage::disk('local')->path('dreamlayer/uploads/'.$owner.'/'.$conversation.'/'.$data['id']);
        abort_unless(is_file($path), 422, trans('dreamlayer::studio.upload-expired'));

        return $path;
    }
}
