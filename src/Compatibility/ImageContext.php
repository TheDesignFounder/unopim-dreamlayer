<?php

namespace DreamLayer\UnoPIM\Compatibility;

/** Image requests do not require UnoPim's optional AI Agent package. */
final class ImageContext
{
    public function __construct(
        public string $message,
        public array $history,
        public ?int $productId,
        public ?string $productSku,
        public ?string $productName,
        public string $locale,
        public string $channel,
        public ?object $platform = null,
        public string $model = '',
        public array $uploadedImagePaths = [],
        public array $uploadedFilePaths = [],
        public ?string $currentPage = null,
        public ?object $user = null,
        public ?string $conversationId = null,
        public ?string $requestId = null,
        public bool $hasNewImages = false,
        public array $extensions = [],
    ) {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $locale) || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $channel)) {
            throw new \InvalidArgumentException('Invalid image locale or channel.');
        }
    }

    public static function from(object $context): self
    {
        if ($context instanceof self) {
            return $context;
        }
        $fields = array_map(fn ($p) => $p->getName(), (new \ReflectionMethod(self::class, '__construct'))->getParameters());

        return new self(...array_intersect_key(get_object_vars($context), array_flip($fields)));
    }

    public function hasPermission(string $permission): bool
    {
        return $this->user && ($this->user->role?->permission_type === 'all' || $this->user->hasPermission($permission));
    }

    public function firstImagePath(): ?string
    {
        return $this->uploadedImagePaths[0] ?? null;
    }
}
