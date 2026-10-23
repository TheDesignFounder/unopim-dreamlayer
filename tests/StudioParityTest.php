<?php

use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class StudioParityTest extends TestCase
{
    private Admin $owner;
    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->owner = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($this->owner, 'admin');
        Settings::current()->update(['enabled' => true, 'credential' => 'fixture-key', 'managed_chat' => false, 'save_chat_history' => false]);
        $this->input = ['conversation_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'message' => 'Put it by the river', 'operation' => 'image_to_image', 'images' => [], 'history' => [], 'channel' => 'default', 'locale' => 'en_US'];
    }

    private function attach(): array
    {
        return $this->post(route('dreamlayer.studio.upload'), ['conversation_id' => $this->input['conversation_id'], 'image' => UploadedFile::fake()->image('private-car.png', 64, 64)], ['Accept' => 'application/json'])->assertOk()->json();
    }

    public function test_private_upload_is_owner_and_conversation_scoped_and_copied_before_dispatch(): void
    {
        $file = $this->attach();
        $this->assertStringNotContainsString('storage/', $file['image_url']);
        $this->get($file['image_url'])->assertOk();
        $this->input['upload_token'] = $file['token'];
        $data = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $workflow = Workflow::findOrFail($data['workflow_ids'][0]);
        Storage::disk('local')->assertExists($workflow->source_path);
        $this->assertNull($workflow->target);
        $this->assertSame($data, $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json());
        Queue::assertPushed(RunWorkflow::class, 1);
        $this->input['conversation_id'] = (string) Str::uuid();
        $this->input['request_id'] = (string) Str::uuid();
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertNotFound();
        $other = Admin::factory()->create(['role_id' => $this->owner->role_id]);
        $this->actingAs($other, 'admin');
        $this->get($file['image_url'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_upload_validation_expiry_and_product_edit_permission(): void
    {
        $this->post(route('dreamlayer.studio.upload'), ['conversation_id' => $this->input['conversation_id'], 'image' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
        $file = $this->attach();
        $this->travel(25)->hours();
        $this->getJson($file['image_url'])->assertUnprocessable();
        $this->travelBack();
        $other = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dreamlayer_results.choose']])->id]);
        $this->actingAs($other, 'admin');
        $this->postJson(route('dreamlayer.studio.upload'), [])->assertForbidden();
        $this->get($file['image_url'])->assertForbidden();
    }

    public function test_image_editor_with_minimum_permissions_does_not_need_settings_or_native_ai(): void
    {
        $permissions = ['catalog.products.edit', 'dreamlayer_results.studio_config', 'dreamlayer_results.editor_send',
            'dreamlayer_results.workflow', 'dreamlayer_results.image', 'dreamlayer_results.studio_upload', 'dreamlayer_results.studio_upload_image'];
        $editor = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions])->id]);
        $this->actingAs($editor, 'admin');
        $this->getJson(route('dreamlayer.studio.config'))->assertOk()->assertJsonPath('permissions.settings', false)
            ->assertJsonPath('permissions.apply', false)->assertJsonPath('permissions.feedback', false)->assertJsonPath('permissions.upload', true);
        $file = $this->attach();
        $this->get($file['image_url'])->assertOk();
        $response = $this->postJson(route('dreamlayer.editor.send'), $this->input + ['upload_token' => $file['token']])->assertOk();
        $id = $response->json('workflow_ids.0');
        $this->getJson(route('dreamlayer.workflow', $id))->assertOk();
        $this->postJson(route('dreamlayer.apply', $id))->assertForbidden();
        $this->patchJson(route('dreamlayer.feedback', $id), ['feedback' => 'helpful'])->assertForbidden();
        $this->getJson(route('dreamlayer.settings'))->assertForbidden();
        $this->postJson(route('dreamlayer.settings.save'), [])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_retry_uses_original_source_and_feedback_is_private(): void
    {
        $file = $this->attach();
        $data = $this->postJson(route('dreamlayer.editor.send'), $this->input + ['upload_token' => $file['token']])->assertOk()->json();
        $old = Workflow::findOrFail($data['workflow_ids'][0]);
        $bytes = Storage::disk('local')->get($old->source_path);
        $old->update(['status' => 'failed']);
        $this->input['request_id'] = (string) Str::uuid();
        $this->input['retry_result'] = $old->id;
        $again = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $new = Workflow::findOrFail($again['workflow_ids'][0]);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($bytes, Storage::disk('local')->get($new->source_path));
        $this->assertNull($new->applied_at);
        $new->update(['status' => 'completed', 'completed_at' => now()]);
        $completed = $new->completed_at->toISOString();
        $this->travel(3)->minutes();
        $this->patchJson(route('dreamlayer.feedback', $new->id), ['feedback' => 'helpful'])->assertOk()->assertJsonPath('feedback', 'helpful')->assertJsonPath('updated_at', $completed);
        $this->patchJson(route('dreamlayer.feedback', $new->id), ['feedback' => 'bad'])->assertUnprocessable();
        $this->patchJson(route('dreamlayer.feedback', $new->id), ['feedback' => null])->assertOk()->assertJsonPath('feedback', null);
        $other = Admin::factory()->create(['role_id' => $this->owner->role_id]);
        $this->actingAs($other, 'admin');
        $this->patchJson(route('dreamlayer.feedback', $new->id), ['feedback' => 'helpful'])->assertNotFound();
        $this->input['request_id'] = (string) Str::uuid();
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_explicit_logout_rotates_session_and_does_not_keep_an_iframe_return_url(): void
    {
        $this->getJson(route('dreamlayer.studio.config'))->assertOk();
        $session = app('session.store');
        $old = $session->getId();
        $session->put('url.intended', route('dreamlayer.studio'));
        $this->delete(route('admin.session.destroy'))->assertRedirect(route('admin.session.create'));
        $this->assertNotSame($old, $session->getId());
        $this->assertNull($session->get('url.intended'));
        $this->assertGuest('admin');
        $this->getJson(route('dreamlayer.studio.config'))->assertRedirect(route('admin.session.create'));
        $this->get(route('dreamlayer.studio'), ['Sec-Fetch-Dest' => 'iframe'])->assertUnauthorized();
    }

    public function test_new_single_step_prefers_direct_execution_and_ready_checkpoints_do_not_wait(): void
    {
        $file = $this->attach();
        $data = $this->postJson(route('dreamlayer.editor.send'), $this->input + ['upload_token' => $file['token']])->assertOk()->json();
        $w = Workflow::findOrFail($data['workflow_ids'][0]);
        $execution = (string) Str::uuid();
        Http::fake([
            Client::BASE_URL.'/capabilities' => Http::response(['operations' => ['text_to_image'], 'managed_execute' => true, 'image_workflows' => true, 'image_workflow_versions' => [1]]),
            Client::BASE_URL.'/input-assets' => Http::response(['input_asset_id' => (string) Str::uuid()]),
            Client::BASE_URL.'/execute' => Http::response(['execution_id' => $execution]),
            Client::BASE_URL.'/executions/'.$execution => Http::response(['status' => 'running']),
        ]);
        foreach ([0, 0, 5, 5] as $delay) {
            $queue = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
            $queue->shouldReceive('release')->once()->with($delay);
            $job = new RunWorkflow($w->id);
            $job->setJob($queue);
            $job->handle();
        }
        $this->assertSame('execute_v1', $w->fresh()->execution_path);
        $this->assertSame($execution, $w->fresh()->upstream_execution_id);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'image-workflows'));
        Http::assertSent(fn ($r) => $r->url() === Client::BASE_URL.'/execute' && $r->header('Idempotency-Key')[0] === 'unopim-execute-'.$w->id);
    }
}
