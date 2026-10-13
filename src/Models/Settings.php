<?php

namespace DreamLayer\UnoPIM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Settings extends Model
{
    protected $table = 'dreamlayer_settings';

    protected $guarded = [];

    protected $hidden = ['credential'];

    protected function casts(): array
    {
        return ['credential' => 'encrypted', 'enabled' => 'boolean'];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['installation_id' => (string) Str::uuid()]);
    }

    public function connected(): bool
    {
        return $this->enabled && filled($this->credential);
    }
}
