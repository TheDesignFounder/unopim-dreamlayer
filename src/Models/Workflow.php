<?php

namespace DreamLayer\UnoPIM\Models;

use Illuminate\Database\Eloquent\Model;

class Workflow extends Model
{
    protected $table = 'dreamlayer_workflows';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['source_path', 'result_path', 'credential_version', 'fingerprint', 'delivery'];

    protected $casts = ['lease_expires_at' => 'datetime', 'poll_deadline' => 'datetime', 'progress' => 'array', 'delivery' => 'array', 'steps' => 'array', 'target' => 'array', 'applied_at' => 'datetime', 'error_code' => 'integer'];

    public function terminal(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    public function delayed(): bool
    {
        if ($this->waitingForBatch()) {
            return false;
        }

        return ! $this->terminal() && $this->updated_at->lt(now()->subMinutes(2));
    }

    public function waitingForBatch(): bool
    {
        if ($this->terminal() || ! $this->batch_id || $this->upstream_workflow_id) {
            return false;
        }
        $batch = Batch::find($this->batch_id);

        if ($batch?->dispatch_mode === 'independent' && $this->status !== 'queued') {
            return false;
        }

        return $batch && in_array($batch->status, ['queued', 'running'], true)
            && ! $batch->error && $batch->updated_at->gte(now()->subMinutes(2));
    }

    public function independentlyAdmitted(): bool
    {
        return $this->batch_id && $this->status === 'running'
            && Batch::whereKey($this->batch_id)->where('dispatch_mode', 'independent')->exists();
    }

    public function phase(): string
    {
        if ($this->terminal()) {
            return $this->status;
        }
        if ($this->delivery) {
            return $this->error || $this->delayed() ? 'retrying_delivery' : 'downloading';
        }
        if ($this->waitingForBatch()) {
            $batch = Batch::find($this->batch_id);
            if ($batch->dispatch_mode === 'independent') {
                return $batch->status === 'queued' ? 'queued' : 'waiting';
            }

            return ($batch->children[0] ?? null) === $this->id ? 'queued' : 'waiting';
        }

        return $this->status;
    }
}
