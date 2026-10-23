<?php

namespace DreamLayer\UnoPIM\Support;

class Brand
{
    public static function logo(): string
    {
        static $logo;

        return $logo ??= 'data:image/png;base64,'.base64_encode(file_get_contents(__DIR__.'/../../resources/images/dreamlayer-logo.png'));
    }
}
