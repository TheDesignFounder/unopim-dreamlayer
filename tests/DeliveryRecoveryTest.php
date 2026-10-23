<?php

use DreamLayer\UnoPIM\Jobs\RunBatch;
use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\ImageStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class DeliveryRecoveryTest extends TestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->admin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($this->admin, 'admin');
        Settings::current()->update(['enabled' => true, 'credential' => 'private-recovery-fixture-key', 'credential_version' => (string) Str::uuid()]);
    }

    private function workflow(): Workflow
    {
        return Workflow::create(['id' => (string) Str::uuid(), 'user_id' => $this->admin->id,
            'conversation_id' => 'delivery-recovery', 'request_id' => (string) Str::uuid(),
            'fingerprint' => str_repeat('a', 64), 'credential_version' => Settings::current()->credential_version,
            'api_url' => Client::BASE_URL, 'execution_path' => 'workflow_v1',
            'upstream_workflow_id' => (string) Str::uuid(), 'status' => 'running',
            'steps' => [['operation' => 'image_to_image', 'instruction' => 'Private fixture prompt']]]);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return ob_get_clean();
    }

    private function remote(Workflow $w, int $count = 1): array
    {
        $steps = [];
        for ($i = 0; $i < $count; $i++) {
            $steps[] = ['description' => 'Edit '.($i + 1), 'status' => 'completed',
                'image' => ['download_url' => Client::BASE_URL.'/image-jobs/'.$w->id.'/asset-'.$i]];
        }

        return ['workflow_id' => $w->upstream_workflow_id, 'contract_version' => 1,
            'status' => 'completed', 'steps' => $steps, 'image' => $steps[array_key_last($steps)]['image']];
    }

    private function tick(Workflow $w): void
    {
        (new RunWorkflow($w->id))->handle();
        $w->refresh();
    }

    public function test_status_is_committed_before_download_and_identical_final_image_is_reused(): void
    {
        $w = $this->workflow();
        Http::fake(['*/image-workflows/*' => Http::response($this->remote($w)), '*/asset-0' => Http::response($this->png())]);
        $this->tick($w);
        $this->assertSame('downloading', $w->phase());
        $this->assertSame('completed', $w->delivery['status']);
        Http::assertSentCount(1);
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertJsonPath('phase', 'downloading')->assertJsonPath('image_url', null)->assertJsonMissingPath('delivery');
        $this->tick($w);
        $this->assertSame('completed', $w->status);
        $this->assertSame($w->progress[0]['result_path'], $w->result_path);
        $this->assertNull($w->delivery);
        $this->assertNull($w->applied_at);
        $this->get(route('dreamlayer.image', $w->id))->assertOk()->assertContent($this->png());
        $this->tick($w);
        Http::assertSentCount(2); // One status GET + one image GET, including repeated queue delivery.
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_second_download_failure_keeps_the_first_checkpoint_and_retries_only_missing_bytes(): void
    {
        $w = $this->workflow();
        $remote = $this->remote($w, 2);
        $downloads = [0, 0];
        Http::fake(function ($request) use ($remote, &$downloads) {
            if (str_contains($request->url(), '/image-workflows/')) {
                return Http::response($remote);
            }
            $index = str_ends_with($request->url(), 'asset-0') ? 0 : 1;
            $downloads[$index]++;
            if ($index === 1 && $downloads[$index] === 1) {
                throw new ConnectionException('Simulated slow download');
            }

            return Http::response($this->png());
        });
        $this->tick($w); // Plan.
        $this->tick($w); // First image only.
        $this->assertSame([1, 0], $downloads);
        $this->assertNotEmpty($w->progress[0]['result_path']);
        $this->tick($w); // Second image interrupted.
        $this->assertSame('retrying_delivery', $w->phase());
        $this->assertNotEmpty($w->delivery);
        $this->tick($w); // Fresh job resumes the durable plan.
        $this->assertSame([1, 2], $downloads);
        $this->assertSame('completed', $w->status);
        $this->assertSame($remote['workflow_id'], $w->upstream_workflow_id);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_published_file_survives_interruption_before_database_checkpoint(): void
    {
        $w = $this->workflow();
        Http::fake(['*/image-workflows/*' => Http::response($this->remote($w)), '*/asset-0' => Http::response($this->png())]);
        $this->tick($w);
        app()->instance(ImageStore::class, new class extends ImageStore
        {
            public function put(string $path, string $bytes): void
            {
                parent::put($path, $bytes);
                throw new RuntimeException('Interrupted after atomic file publication');
            }
        });
        $this->tick($w);
        $this->assertSame('retrying_delivery', $w->phase());
        app()->instance(ImageStore::class, new ImageStore);
        $this->tick($w);
        $this->assertSame('completed', $w->status);
        Http::assertSentCount(2); // Complete bytes are reused after the interruption.
    }

    public function test_waiting_child_uses_parent_heartbeat_but_stalled_parent_is_still_reported(): void
    {
        $w = $this->workflow();
        $first = $this->workflow();
        $first->update(['input_asset_id' => (string) Str::uuid()]);
        $batch = Batch::create(['id' => (string) Str::uuid(), 'user_id' => $this->admin->id,
            'conversation_id' => $w->conversation_id, 'request_id' => (string) Str::uuid(),
            'fingerprint' => $w->fingerprint, 'credential_version' => $w->credential_version,
            'api_url' => Client::BASE_URL, 'upstream_id' => (string) Str::uuid(),
            'children' => [$first->id, $w->id], 'status' => 'running']);
        $w->update(['batch_id' => $batch->id, 'upstream_workflow_id' => null, 'input_asset_id' => (string) Str::uuid(),
            'status' => 'queued', 'updated_at' => now()->subMinutes(5)]);
        $this->assertFalse($w->delayed());
        $this->assertSame('waiting', $w->phase());
        $this->travel(121)->seconds();
        $this->assertTrue($w->delayed());
        Http::fake(['*/image-batches/*' => Http::response(['contract_version' => 1, 'batch_id' => $batch->upstream_id, 'status' => 'running', 'children' => []])]);
        (new RunBatch($batch->id))->handle();
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertJsonPath('phase', 'waiting')->assertJsonPath('delayed', false);
        Queue::assertNothingPushed();
        $batch->update(['children' => [$w->id, $first->id]]);
        $this->assertSame('queued', $w->fresh()->phase(), 'The first image is waiting to start, not waiting behind another image');
        $this->assertFalse($w->fresh()->delayed());
    }

    public function test_batch_expiry_stops_waiting_children_and_recovery_respects_admission_limit(): void
    {
        config(['dreamlayer.queue_connection' => 'database', 'dreamlayer.batch_concurrency' => 1]);
        $first = $this->workflow();
        $second = $this->workflow();
        $batch = Batch::create(['id' => (string) Str::uuid(), 'user_id' => $this->admin->id,
            'conversation_id' => $first->conversation_id, 'request_id' => (string) Str::uuid(),
            'fingerprint' => $first->fingerprint, 'credential_version' => $first->credential_version,
            'api_url' => Client::BASE_URL, 'children' => [$first->id, $second->id],
            'status' => 'running', 'dispatch_mode' => 'independent', 'concurrency' => 1, 'poll_deadline' => now()->subSecond()]);
        foreach ([$first, $second] as $child) {
            $child->update(['batch_id' => $batch->id, 'status' => 'queued', 'upstream_workflow_id' => null]);
        }
        (new RunBatch($batch->id))->handle();
        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertSame('failed', $first->fresh()->status);
        $this->assertSame('failed', $second->fresh()->status);
        $this->artisan('dreamlayer:recover', ['--resume-expired' => true])->assertExitCode(0);
        $this->assertSame('queued', $first->fresh()->status);
        $this->assertSame('queued', $second->fresh()->status);
        Queue::assertNotPushed(RunWorkflow::class, fn ($job) => in_array($job->workflowId, [$first->id, $second->id], true));
        (new RunBatch($batch->id))->handle();
        $this->assertSame('running', $first->fresh()->status);
        $this->assertSame('queued', $second->fresh()->status);
        Queue::assertPushed(RunWorkflow::class, fn ($job) => $job->workflowId === $first->id);
        Queue::assertNotPushed(RunWorkflow::class, fn ($job) => $job->workflowId === $second->id);
        Http::assertNothingSent();
    }

    public function test_expired_delivery_cannot_fail_an_explicitly_recovered_attempt(): void
    {
        $workflow = $this->workflow();
        $oldJob = new RunWorkflow($workflow->id);
        $oldJob->retryDeadline = time() - 30;
        $workflow->update(['poll_deadline' => now()->addMinutes(30)]);
        $oldJob->failed(new RuntimeException('Old delivery expired'));
        $this->assertSame('running', $workflow->fresh()->status);
    }

    public function test_storage_redirects_share_one_download_deadline_and_receive_no_api_credential(): void
    {
        $timeouts = [];
        Http::fake(function ($request, $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'];
            usleep(20000);
            if (str_contains($request->url(), 'api.dreamlayer.io')) {
                return Http::response('', 302, ['Location' => 'https://storage.googleapis.com/fixture/redirect']);
            }
            $this->assertFalse($request->hasHeader('Authorization'));
            if (str_ends_with($request->url(), '/redirect')) {
                return Http::response('', 302, ['Location' => 'https://storage.googleapis.com/fixture/image.png']);
            }

            return Http::response($this->png());
        });
        $this->assertSame($this->png(), (new Client('private-fixture-key'))->image(Client::BASE_URL.'/image-jobs/fixture/asset'));
        $this->assertCount(3, $timeouts);
        $this->assertLessThan(60, $timeouts[1]);
        $this->assertLessThan($timeouts[1], $timeouts[2], 'Redirects must use the remaining budget instead of restarting a timeout');
    }

    public function test_expired_download_reference_is_refreshed_without_new_generation(): void
    {
        $w = $this->workflow();
        Http::fake(['*/image-workflows/*' => Http::response($this->remote($w)),
            '*/asset-0' => Http::sequence()->push([], 403)->push($this->png())]);
        for ($i = 0; $i < 4; $i++) {
            $this->tick($w);
            if ($i === 1) {
                $this->assertSame('retrying_delivery', $w->phase(), 'Refreshing an expired link is still delivery recovery');
            }
        }
        $this->assertSame('completed', $w->status);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_connection_change_stops_a_pending_delivery_before_any_download(): void
    {
        $w = $this->workflow();
        Http::fake(['*/image-workflows/*' => Http::response($this->remote($w))]);
        $this->tick($w);
        Settings::current()->update(['credential_version' => (string) Str::uuid()]);
        $this->tick($w);
        $this->assertSame('failed', $w->status);
        Http::assertSentCount(1);
    }

    public function test_diagnostics_identify_operation_without_logging_credentials_prompt_or_asset_urls(): void
    {
        Log::spy();
        $w = $this->workflow();
        Http::fake(['*/image-workflows/*' => Http::response($this->remote($w)), '*/asset-0' => Http::response($this->png())]);
        $this->tick($w);
        $this->tick($w);
        foreach (['workflow_status', 'download_image', 'save_image'] as $operation) {
            Log::shouldHaveReceived('info')->with('DreamLayer workflow operation finished', Mockery::on(fn ($context) => $context['workflow_id'] === $w->id && $context['operation'] === $operation
                && array_keys($context) === ['workflow_id', 'operation', 'duration_ms']
                && $context['duration_ms'] >= 0));
        }
    }
}
