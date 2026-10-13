<?php

namespace DreamLayer\UnoPIM\Models;

use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    protected $table = 'dreamlayer_batches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credential_version', 'fingerprint'];

    protected function casts(): array
    {
        return ['children' => 'array'];
    }
}
