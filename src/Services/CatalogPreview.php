<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Models\Workflow;
use Webkul\Attribute\Models\Attribute;

/** A reviewed PNG that fits the host attribute; the original stays untouched. */
class CatalogPreview
{
    public function limit(Attribute $attribute): ?int
    {
        if ($attribute->type === 'gallery') {
            $fallback = (int) config('media.gallery.max_file_size_kilobytes', 15360);

            return method_exists($attribute, 'resolvedGalleryMaxKilobytes')
                ? $attribute->resolvedGalleryMaxKilobytes($fallback) : $fallback;
        }
        $fallback = (int) (core()->getConfigData('catalog.products.attribute.image_attribute_upload_size') ?? 2048);

        return method_exists($attribute, 'resolvedMaxKilobytes') ? $attribute->resolvedMaxKilobytes($fallback) : $fallback;
    }

    public function prepare(Workflow $workflow): array
    {
        $store = app(ImageStore::class);
        if ($workflow->applied_at && $workflow->catalog_preview) {
            $store->read($workflow->catalog_preview['path']);

            return $workflow->catalog_preview;
        }
        $bytes = $store->read($workflow->result_path);
        $attribute = $workflow->target ? Attribute::find($workflow->target['attribute_id']) : null;
        $limit = $attribute ? $this->limit($attribute) : null;
        [$width, $height] = getimagesizefromstring($bytes);
        $preview = ['path' => $workflow->result_path, 'token' => hash('sha256', $bytes), 'width' => $width, 'height' => $height,
            'original_width' => $width, 'original_height' => $height, 'limit_kb' => $limit, 'adjusted' => false];
        if (! $limit || $limit < 0 || strlen($bytes) <= $limit * 1024) {
            return $preview;
        }
        // Cache by content and host limit. A changed limit never silently changes
        // the bytes approved by an already open result card.
        $path = 'dreamlayer/catalog-previews/'.$workflow->id.'/'.hash('sha256', $preview['token'].'|'.$limit.'|v1').'.png';
        if ($store->valid($path)) {
            $fitted = $store->read($path);
        } else {
            $source = imagecreatefromstring($bytes);
            imagesavealpha($source, true);
            $image = $source;
            try {
                for ($attempt = 0; $attempt < 20; $attempt++) {
                    ob_start();
                    // Maximum PNG compression is disproportionately expensive
                    // for generated photos. The normal level remains lossless;
                    // the size check below still enforces the attribute limit.
                    imagepng($image, null, 6);
                    $fitted = ob_get_clean();
                    if (strlen($fitted) <= $limit * 1024) break;
                    $ratio = min(0.85, sqrt($limit * 1024 / strlen($fitted)) * 0.95);
                    $nextWidth = max(1, (int) floor(imagesx($image) * $ratio));
                    $nextHeight = max(1, (int) floor($nextWidth * $height / $width));
                    $next = imagecreatetruecolor($nextWidth, $nextHeight);
                    imagealphablending($next, false);
                    imagesavealpha($next, true);
                    imagefilledrectangle($next, 0, 0, $nextWidth, $nextHeight, imagecolorallocatealpha($next, 0, 0, 0, 127));
                    imagecopyresampled($next, $source, 0, 0, 0, 0, $nextWidth, $nextHeight, $width, $height);
                    if ($image !== $source) imagedestroy($image);
                    $image = $next;
                }
                abort_if(strlen($fitted) > $limit * 1024, 422, trans('dreamlayer::app.message-ff711ff54d'));
                $store->put($path, $fitted);
            } finally {
                if ($image !== $source) imagedestroy($image);
                imagedestroy($source);
            }
        }
        [$fittedWidth, $fittedHeight] = getimagesizefromstring($fitted);

        return array_replace($preview, ['path' => $path, 'token' => hash('sha256', $fitted), 'width' => $fittedWidth, 'height' => $fittedHeight, 'adjusted' => true]);
    }

    public function notice(array $preview): ?string
    {
        return $preview['adjusted'] ? trans('dreamlayer::studio.catalog-preview', [
            'width' => $preview['width'], 'height' => $preview['height'],
            'original' => $preview['original_width'].' × '.$preview['original_height'],
            'limit' => round($preview['limit_kb'] / 1024, 2),
        ]) : null;
    }
}
