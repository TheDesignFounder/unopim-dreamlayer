<?php

use DreamLayer\UnoPIM\Services\Endpoints;
use Tests\TestCase;

final class EndpointTest extends TestCase
{
    public function test_explicit_local_configuration_and_staging_origins(): void
    {
        config(['dreamlayer.api_url'=>'https://staging.example/v1/', 'dreamlayer.platform_url'=>'https://staging.example']);
        $this->assertSame('https://staging.example/v1', Endpoints::api());
        $this->assertSame('https://staging.example', Endpoints::platform());
        config(['dreamlayer.api_url'=>'http://host.docker.internal:8101/v1', 'dreamlayer.allow_local_http'=>true]);
        $this->assertSame('http://host.docker.internal:8101/v1', Endpoints::api());
    }

    public function test_http_is_rejected_without_explicit_development_opt_in(): void
    {
        config(['dreamlayer.api_url'=>'http://localhost:8101/v1', 'dreamlayer.allow_local_http'=>false]);
        $this->expectException(RuntimeException::class);
        Endpoints::api();
    }

    public function test_configured_url_cannot_embed_credentials(): void
    {
        config(['dreamlayer.api_url'=>'https://secret@staging.example/v1']);
        $this->expectException(RuntimeException::class);
        Endpoints::api();
    }
}
