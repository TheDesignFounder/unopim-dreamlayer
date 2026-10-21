<?php

use DreamLayer\UnoPIM\Jobs\RunBatch;
use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class IndependentBatchTest extends TestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->admin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        Settings::current()->update(['enabled' => true, 'credential' => 'test-independent-key', 'credential_version' => (string) Str::uuid()]);
    }

    private function batch(int $count = 5): Batch
    {
        $id = (string) Str::uuid();
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $w = Workflow::create(['id' => (string) Str::uuid(), 'batch_id' => $id, 'user_id' => $this->admin->id,
                'conversation_id'       => 'parallel-test', 'request_id' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64),
                'api_url'               => Client::BASE_URL, 'credential_version' => Settings::current()->credential_version,
                'execution_path'        => 'workflow_v1', 'input_asset_id' => (string) Str::uuid(), 'aspect_ratio' => '1:1',
                'steps'                 => [['operation' => 'image_to_image', 'instruction' => 'Place car in jungle']],
                'target'                => ['sku' => 'CAR-'.$i]]);
            $children[] = $w->id;
        }

        return Batch::create(['id' => $id, 'user_id' => $this->admin->id, 'conversation_id' => 'parallel-test',
            'request_id'           => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'api_url' => Client::BASE_URL,
            'credential_version'   => Settings::current()->credential_version, 'children' => $children,
            'dispatch_mode'        => 'independent', 'concurrency' => 2]);
    }

    public function test_two_images_are_submitted_before_either_finishes_without_remote_batch(): void
    {
        $b = $this->batch();
        $posts = [];
        Http::fake(function ($r) use (&$posts) {
            $this->assertSame(Client::BASE_URL.'/image-workflows', $r->url());
            $this->assertSame('POST', $r->method());
            $posts[] = $r->header('Idempotency-Key')[0];

            return Http::response(['workflow_id' => (string) Str::uuid()]);
        });
        (new RunBatch($b->id))->handle();
        (new RunBatch($b->id))->handle(); // Duplicate delivery must not admit more slots.
        Queue::assertPushed(RunWorkflow::class, 2);
        foreach ($b->children as $i => $id) {
            $w = Workflow::findOrFail($id);
            $this->assertSame($i < 2 ? 'running' : 'queued', $w->status);
            $this->assertSame($i < 2 ? 'running' : 'waiting', $w->phase());
            (new RunWorkflow($id))->handle();
        }
        $this->assertSame(array_map(fn ($id) => 'unopim-'.$id, array_slice($b->children, 0, 2)), $posts);
        $this->assertNull($b->fresh()->upstream_id);
        $this->assertSame(2, Workflow::where('batch_id', $b->id)->whereNotNull('upstream_workflow_id')->count());
    }

    public function test_failed_image_frees_slot_and_remaining_results_complete_independently(): void
    {
        $b = $this->batch(3);
        (new RunBatch($b->id))->handle();
        Workflow::findOrFail($b->children[0])->update(['status' => 'failed', 'error_code' => 422]);
        (new RunBatch($b->id))->handle();
        $this->assertSame('running', Workflow::findOrFail($b->children[2])->status);
        Queue::assertPushed(RunWorkflow::class, 3);
        Workflow::whereIn('id', array_slice($b->children, 1))->update(['status' => 'completed']);
        (new RunBatch($b->id))->handle();
        $this->assertSame('partial', $b->fresh()->status);
        $this->assertSame(0, Workflow::where('batch_id', $b->id)->whereNotNull('applied_at')->count());
        Http::assertNothingSent();
    }

    public function test_account_failure_stops_only_unstarted_children(): void
    {
        $b = $this->batch(3);
        (new RunBatch($b->id))->handle();
        Workflow::findOrFail($b->children[0])->update(['status' => 'failed', 'error_code' => 402]);
        (new RunBatch($b->id))->handle();
        $this->assertSame('running', Workflow::findOrFail($b->children[1])->status);
        $this->assertSame('failed', Workflow::findOrFail($b->children[2])->status);
        Queue::assertPushed(RunWorkflow::class, 2);
    }

    public function test_lost_submission_ack_reuses_same_workflow_and_idempotency_key(): void
    {
        $b = $this->batch(2);
        (new RunBatch($b->id))->handle();
        $remote = (string) Str::uuid();
        $keys = [];
        Http::fake(function ($r) use (&$keys, $remote) {
            $keys[] = $r->header('Idempotency-Key')[0];
            if (count($keys) === 1) {
                throw new ConnectionException('Acknowledgement lost');
            }

            return Http::response(['workflow_id' => $remote]);
        });
        (new RunWorkflow($b->children[0]))->handle();
        (new RunWorkflow($b->children[0]))->handle();
        $this->assertSame(['unopim-'.$b->children[0], 'unopim-'.$b->children[0]], $keys);
        $this->assertSame($remote, Workflow::findOrFail($b->children[0])->upstream_workflow_id);
        $this->assertSame(2, Workflow::where('batch_id', $b->id)->count());
    }

    public function test_recovery_requeues_admitted_child_before_upstream_ack_but_not_waiting_child(): void
    {
        $b = $this->batch(3);
        (new RunBatch($b->id))->handle();
        Queue::fake();
        Workflow::where('batch_id', $b->id)->update(['updated_at' => now()->subMinutes(3)]);
        $this->artisan('dreamlayer:recover')->assertSuccessful();
        Queue::assertPushed(RunWorkflow::class, 2);
        Queue::assertNotPushed(RunWorkflow::class, fn ($j) => $j->workflowId === $b->children[2]);
    }

    public function test_legacy_batch_children_never_admit_independently(): void
    {
        $b = $this->batch(2);
        $b->update(['dispatch_mode' => 'upstream']);
        Workflow::where('batch_id', $b->id)->update(['status' => 'running']);
        foreach ($b->children as $id) {
            (new RunWorkflow($id))->handle();
        }
        Http::assertNothingSent();
    }
}
