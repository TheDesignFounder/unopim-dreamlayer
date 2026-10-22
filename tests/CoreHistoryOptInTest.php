<?php

use DreamLayer\UnoPIM\Providers\DreamLayerServiceProvider;
use Tests\TestCase;

final class CoreHistoryOptInTest extends TestCase
{
    public function test_extension_leaves_core_history_off_by_default(): void
    {
        $this->assertNull(config('dreamlayer.persist_chat_history'));
        $this->assertFalse((bool) config('ai-agent.history.enabled'));
    }

    public function test_operator_can_leave_the_core_history_setting_unchanged(): void
    {
        config(['dreamlayer.persist_chat_history' => false, 'ai-agent.history.enabled' => false]);
        (new DreamLayerServiceProvider(app()))->boot();
        $this->assertFalse((bool) config('ai-agent.history.enabled'));

        config(['ai-agent.history.enabled' => true]);
        (new DreamLayerServiceProvider(app()))->boot();
        $this->assertTrue((bool) config('ai-agent.history.enabled'));
    }
}
