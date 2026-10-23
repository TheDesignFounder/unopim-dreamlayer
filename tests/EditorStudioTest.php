<?php

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\EditorHistory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class EditorStudioTest extends TestCase
{
    private Admin $owner;
    private string $conversation;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->owner = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($this->owner, 'admin');
        $this->conversation = (string) Str::uuid();
        $this->requestId = (string) Str::uuid();
        Settings::current()->update(['enabled' => true, 'credential' => 'fixture-key', 'save_chat_history' => true, 'managed_chat' => false]);
    }

    private function record(): void
    {
        app(EditorHistory::class)->record($this->owner->id, $this->conversation, $this->requestId, 'Private product edit', 'Preview queued');
    }

    public function test_saved_history_reopens_with_encrypted_titles_and_messages(): void
    {
        $this->record();
        $row = DB::table('dreamlayer_editor_threads')->where('user_id', $this->owner->id)->first();
        $this->assertStringNotContainsString('Private product', $row->title);
        $this->assertSame('Private product edit', Crypt::decryptString($row->title));
        $this->getJson(route('dreamlayer.studio.threads'))->assertOk()->assertJsonPath('items.0.id', $this->conversation);
        $this->getJson(route('dreamlayer.studio.thread', $this->conversation))->assertOk()->assertJsonPath('turns.0.message', 'Private product edit');
        $this->patchJson(route('dreamlayer.studio.update', $this->conversation), ['title' => 'Renamed private conversation', 'bookmarked' => true])->assertOk();
        $this->getJson(route('dreamlayer.studio.threads', ['bookmarked' => 1]))->assertJsonPath('items.0.title', 'Renamed private conversation');
        $this->assertStringNotContainsString('Renamed', DB::table('dreamlayer_editor_threads')->where('id', $row->id)->value('title'));
    }

    public function test_history_off_does_not_save_new_turns_or_remove_existing_history(): void
    {
        $this->record();
        Settings::current()->update(['save_chat_history' => false]);
        app(EditorHistory::class)->record($this->owner->id, $this->conversation, (string) Str::uuid(), 'Do not save this message', 'Queued');
        $this->assertSame(1, DB::table('dreamlayer_editor_history')->where('user_id', $this->owner->id)->count());
        $this->getJson(route('dreamlayer.studio.thread', $this->conversation))->assertOk()->assertJsonCount(1, 'turns');
        app(EditorHistory::class)->record($this->owner->id, (string) Str::uuid(), (string) Str::uuid(), 'Temporary chat', 'Queued');
        $this->assertSame(1, DB::table('dreamlayer_editor_threads')->where('user_id', $this->owner->id)->count());
    }

    public function test_other_users_cannot_read_rename_bookmark_delete_or_apply_owner_data(): void
    {
        $this->record();
        $workflow = Workflow::create(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'conversation_id' => $this->conversation, 'request_id' => $this->requestId, 'fingerprint' => str_repeat('a', 64), 'status' => 'completed', 'steps' => [['operation' => 'text_to_image']]]);
        $other = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($other, 'admin');
        $this->getJson(route('dreamlayer.studio.threads'))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(route('dreamlayer.studio.images'))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(route('dreamlayer.studio.thread', $this->conversation))->assertNotFound();
        $this->patchJson(route('dreamlayer.studio.update', $this->conversation), ['bookmarked' => true])->assertNotFound();
        $this->deleteJson(route('dreamlayer.studio.delete', $this->conversation))->assertNotFound();
        $this->getJson(route('dreamlayer.workflow', $workflow->id))->assertNotFound();
        $this->getJson(route('dreamlayer.image', $workflow->id))->assertNotFound();
        $this->postJson(route('dreamlayer.apply', $workflow->id))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_product_edit_permission_is_required_even_with_dreamlayer_permissions(): void
    {
        $this->record();
        $permissions = collect(config('acl'))->filter(fn ($acl) => str_starts_with($acl['key'], 'dreamlayer'))->pluck('key')->all();
        $other = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions])->id]);
        $this->actingAs($other, 'admin');
        foreach (['studio', 'studio.config', 'studio.threads', 'studio.images'] as $route) $this->getJson(route('dreamlayer.'.$route))->assertForbidden();
        $this->getJson(route('dreamlayer.studio.thread', $this->conversation))->assertForbidden();
        $this->patchJson(route('dreamlayer.studio.update', $this->conversation), ['title' => 'Denied'])->assertForbidden();
        $this->deleteJson(route('dreamlayer.studio.delete', $this->conversation))->assertForbidden();
        $this->postJson(route('dreamlayer.apply', (string) Str::uuid()))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_delete_keeps_image_jobs_and_blocks_stale_tab_resurrection_before_paid_work(): void
    {
        $this->record();
        $w = Workflow::create(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'conversation_id' => $this->conversation, 'request_id' => $this->requestId, 'fingerprint' => str_repeat('b', 64), 'status' => 'completed', 'steps' => [['operation' => 'text_to_image']]]);
        $this->deleteJson(route('dreamlayer.studio.delete', $this->conversation))->assertOk();
        $this->assertDatabaseHas('dreamlayer_workflows', ['id' => $w->id]);
        $this->assertSame(0, DB::table('dreamlayer_editor_history')->where('user_id', $this->owner->id)->count());
        $this->getJson(route('dreamlayer.studio.thread', $this->conversation))->assertNotFound();
        $this->getJson(route('dreamlayer.studio.images'))->assertJsonPath('items.0.can_refine', false);
        // A late completion cannot restore messages deleted while it was running.
        $this->record();
        $this->assertSame(0, DB::table('dreamlayer_editor_history')->where('user_id', $this->owner->id)->count());
        $this->postJson(route('dreamlayer.editor.send'), ['conversation_id' => $this->conversation, 'request_id' => (string) Str::uuid(), 'message' => 'Generate a blue shoe', 'operation' => 'text_to_image', 'images' => [], 'history' => [], 'channel' => 'default', 'locale' => 'en_US'])->assertConflict();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_all_scalar_tasks_work_and_object_values_are_rejected_before_work(): void
    {
        $data = ['conversation_id' => $this->conversation, 'request_id' => $this->requestId, 'message' => 'Generate a blue shoe', 'operation' => 'text_to_image', 'images' => [], 'history' => [], 'channel' => 'default', 'locale' => 'en_US'];
        $this->postJson(route('dreamlayer.editor.send'), $data)->assertOk();
        $data['request_id'] = (string) Str::uuid();
        $data['operation'] = json_encode(['id' => 'text_to_image', 'label' => 'Generate image']);
        $this->postJson(route('dreamlayer.editor.send'), $data)->assertUnprocessable();
        $this->assertSame(1, Workflow::where('user_id', $this->owner->id)->count());
        Http::assertNothingSent();
    }
}
