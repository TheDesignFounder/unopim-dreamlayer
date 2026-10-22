<?php

namespace DreamLayer\UnoPIM\Models;

use Illuminate\Database\Eloquent\Model;

class Settings extends Model
{
    protected $table = 'dreamlayer_settings';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['credential'];

    protected $casts = ['credential' => 'encrypted', 'enabled' => 'boolean', 'managed_chat' => 'boolean', 'save_chat_history' => 'boolean', 'native_chat_enabled' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(function () {
            app()->forgetInstance('dreamlayer.settings.current');
        });
        static::deleted(function () {
            app()->forgetInstance('dreamlayer.settings.current');
        });
    }

    public static function current(): self
    {
        return app('dreamlayer.settings.current');
    }

    public function connected(): bool
    {
        return $this->enabled && filled($this->credential);
    }
}
