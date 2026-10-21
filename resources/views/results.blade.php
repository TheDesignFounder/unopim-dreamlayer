<x-admin::layouts>
<x-slot:title>DreamLayer image results</x-slot>
@include('dreamlayer::styles')
<div class="dl-page dl-stack text-gray-800 dark:text-gray-200">
<div class="dl-heading" style="justify-content:space-between"><h1 class="text-xl font-semibold">Your image results</h1><a class="primary-button" href="{{ route('dreamlayer.choose') }}">Open chat</a></div>
<p class="mb-4">Review, download, and apply your saved previews. Choose a result to see it in detail.</p>
<form method="get" class="dl-actions"><input name="q" value="{{ request('q') }}" aria-label="Search image results" placeholder="Product name or SKU" class="border rounded p-2 dark:bg-cherry-800"><select name="status" aria-label="Result status" class="border rounded p-2 dark:bg-cherry-800"><option value="">All statuses</option>@foreach(['processing'=>'Processing','ready'=>'Ready to review','applied'=>'Applied','partial'=>'Partially completed','failed'=>'Failed'] as $value=>$label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select><button class="secondary-button">Search</button></form>
<div class="dl-result-grid">
@forelse($workflows as $workflow)
@php
$product = $workflow->target ? \Webkul\Product\Models\Product::find($workflow->target['product_id']) : null;
$name = $product ? app(\DreamLayer\UnoPIM\Services\CatalogImages::class)->name($product,$workflow->target['channel'],$workflow->target['locale']) : 'Image preview';
$partial = $workflow->status === 'failed' && count(array_filter($workflow->progress ?? [], fn ($step) => ($step['status'] ?? '') === 'completed')) > 0;
$status = $workflow->applied_at ? 'Applied' : ($workflow->status === 'completed' ? 'Ready to review' : ($partial ? 'Partially completed' : ($workflow->delayed() ? 'Delayed' : match ($workflow->status) {'queued' => 'Queued', 'running' => 'Processing', default => 'Failed'})));
$status = match ($workflow->phase()) {'waiting' => 'Waiting for another image', 'downloading' => 'Downloading preview', 'retrying_delivery' => 'Retrying preview delivery', default => $status};
@endphp
<article class="dl-result-card"><h2 class="font-semibold">{{ $name }}</h2><p class="text-xs mb-2">{{ $workflow->target['sku'] ?? '' }} · {{ $status }}</p>

<v-dreamlayer-library-preview :descriptor="@js(['type'=>'dreamlayer.workflow','id'=>$workflow->id])" :title="@js($name)" :image-url="@js($workflow->status === 'completed' && app(\DreamLayer\UnoPIM\Services\ImageStore::class)->valid($workflow->result_path) ? route('dreamlayer.image',$workflow->id) : null)"></v-dreamlayer-library-preview><p class="text-xs mt-3" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">{{ $workflow->steps[0]['instruction'] ?: 'Remove background' }}</p></article>
@empty<p class="dl-muted">No image results yet. Open the chat to create a preview, or try a different search.</p>@endforelse
</div><div class="mt-4">{{ $workflows->links() }}</div></div>
@include('dreamlayer::workflow')
@include('dreamlayer::library-preview')
</x-admin::layouts>
