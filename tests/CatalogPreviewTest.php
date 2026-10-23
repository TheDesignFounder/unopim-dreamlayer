<?php

use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\CatalogPreview;
use DreamLayer\UnoPIM\Services\ImageStore;
use DreamLayer\UnoPIM\Services\ProductImages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class CatalogPreviewTest extends TestCase
{
    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->owner = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($this->owner, 'admin');
    }

    private function preview(): array
    {
        $attribute = Attribute::factory()->create(['type' => 'image', 'max_file_size' => null, 'value_per_locale' => false, 'value_per_channel' => false]);
        $family = AttributeFamily::factory()->withRequiredAttributes($attribute)->create();
        $product = Product::factory()->create(['attribute_family_id' => $family->id, 'values' => ['common' => [$attribute->code => 'original.png']]]);
        $id = (string) Str::uuid();
        $workflow = Workflow::create(['id' => $id, 'user_id' => $this->owner->id, 'conversation_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'fingerprint' => str_repeat('b', 64), 'status' => 'completed', 'steps' => [['operation' => 'image_to_image']], 'result_path' => 'dreamlayer/'.$id.'/result.png',
            'target' => app(ProductImages::class)->target($product, $attribute->code, 0, 'default', 'en_US')]);
        static $png;
        if (! $png) {
            $image = imagecreatetruecolor(2048, 2048);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            for ($y = 100; $y < 1948; $y++) {
                $random = random_bytes(1848 * 3);
                for ($x = 100; $x < 1948; $x++) {
                    $i = ($x - 100) * 3;
                    imagesetpixel($image, $x, $y, (ord($random[$i]) << 16) | (ord($random[$i + 1]) << 8) | ord($random[$i + 2]));
                }
            }
            ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        }
        app(ImageStore::class)->put($workflow->result_path, $png);
        $this->assertGreaterThan(2 * 1024 * 1024, strlen($png));

        return [$product, $attribute, $workflow, $png];
    }

    public function test_large_preview_is_reviewable_fits_host_and_applies_exact_reviewed_bytes(): void
    {
        [$product, $attribute, $workflow, $original] = $this->preview();
        $response = $this->getJson(route('dreamlayer.workflow', $workflow->id))->assertOk();
        $data = $response->json();
        $this->assertStringContainsString('Catalog preview:', $data['catalog_notice']);
        $catalog = $this->get($data['catalog_image_url'])->assertOk()->getContent();
        $this->assertLessThanOrEqual(2 * 1024 * 1024, strlen($catalog));
        $decoded = imagecreatefromstring($catalog);
        $this->assertSame(127, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
        $this->assertLessThan(2048, imagesx($decoded));
        imagedestroy($decoded);
        $this->assertSame($original, $this->get($data['image_url'])->getContent());
        $this->assertSame('original.png', $product->fresh()->values['common'][$attribute->code]);
        $this->postJson(route('dreamlayer.apply', $workflow->id))->assertConflict()->assertJsonPath('message', trans('dreamlayer::studio.catalog-preview-changed'));
        $this->postJson(route('dreamlayer.apply', $workflow->id), ['catalog_token' => $data['catalog_token']])->assertOk();
        $saved = $product->fresh()->values['common'][$attribute->code];
        $this->assertSame($catalog, Storage::disk('public')->get($saved));
        $this->postJson(route('dreamlayer.apply', $workflow->id), ['catalog_token' => $data['catalog_token']])->assertOk();
        $this->assertSame($saved, $product->fresh()->values['common'][$attribute->code]);
        $this->assertSame($original, Storage::disk('local')->get($workflow->result_path));
        Http::assertNothingSent();
    }

    public function test_changed_attribute_limit_requires_review_and_stale_catalog_is_refused_with_specific_message(): void
    {
        [$product, $attribute, $workflow] = $this->preview();
        $data = $this->getJson(route('dreamlayer.workflow', $workflow->id))->assertOk()->json();
        $attribute->update(['max_file_size' => 1]);
        $this->postJson(route('dreamlayer.apply', $workflow->id), ['catalog_token' => $data['catalog_token']])->assertConflict()->assertJsonPath('message', trans('dreamlayer::studio.catalog-preview-changed'));
        $product->forceFill(['values' => ['common' => [$attribute->code => 'newer.png']]])->save();
        config(['app.debug' => false]);
        $this->postJson(route('dreamlayer.apply', $workflow->id), ['catalog_token' => $data['catalog_token']])->assertConflict()->assertJsonPath('message', trans('dreamlayer::app.message-5b8888faeb'));
        $this->assertSame('newer.png', $product->fresh()->values['common'][$attribute->code]);
        $this->assertNull($workflow->fresh()->applied_at);
        Http::assertNothingSent();
    }

    public function test_gallery_uses_its_own_host_limits_and_explicit_attribute_size_is_respected(): void
    {
        $service = app(CatalogPreview::class);
        $attribute = new Attribute(['type' => 'gallery', 'max_file_size' => null]);
        config(['media.gallery.max_file_size_kilobytes' => 15360]);
        $this->assertSame(15360, $service->limit($attribute));
        $attribute->max_file_size = 4;
        $this->assertSame(4096, $service->limit($attribute));
        $attribute->max_file_size = 0;
        $this->assertNull($service->limit($attribute));
    }

    public function test_direct_studio_opens_host_page_and_expired_iframe_never_sets_intended_url(): void
    {
        $this->get(route('dreamlayer.studio'))->assertRedirect(route('dreamlayer.choose'));
        auth()->guard('admin')->logout();
        $this->get(route('dreamlayer.studio'), ['Sec-Fetch-Dest' => 'iframe'])->assertUnauthorized();
        $this->assertNull(session('url.intended'));
    }
}
