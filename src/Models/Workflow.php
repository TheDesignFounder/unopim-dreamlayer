<?php

namespace DreamLayer\UnoPIM\Models;

use Illuminate\Database\Eloquent\Model;

class Workflow extends Model
{
    protected $table = 'dreamlayer_workflows';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['source_path', 'result_path', 'credential_version', 'fingerprint'];

    protected function casts(): array
    {
        return ['progress' => 'array', 'steps' => 'array', 'target' => 'array', 'applied_at' => 'datetime', 'error_code' => 'integer'];
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    public function delayed(): bool
    {
        return ! $this->terminal() && $this->updated_at->lt(now()->subMinutes(2));
    }
}
