<x-admin::layouts>
<x-slot:title>{{ trans('dreamlayer::app.results') }}</x-slot>
@include('dreamlayer::styles')
<div class="dl-page dl-stack text-gray-800 dark:text-gray-200">
<div class="dl-heading" style="justify-content:space-between"><h1 class="text-xl font-semibold">@lang('dreamlayer::app.results')</h1><a class="primary-button" href="{{ route('dreamlayer.choose') }}">@lang('dreamlayer::app.editor')</a></div>
<p class="mb-4">@lang('dreamlayer::app.results-help')</p>
<form method="get" class="dl-actions"><x-admin::form.control-group><x-admin::form.control-group.control type="text" name="q" :value="request('q')" :aria-label="trans('dreamlayer::app.search-sku')" :placeholder="trans('dreamlayer::app.search-sku')" /></x-admin::form.control-group><x-admin::form.control-group><x-admin::form.control-group.control type="select" name="status" :value="request('status', '')" :aria-label="trans('dreamlayer::app.result-status')" :options="json_encode(collect([''=>'all-statuses','processing'=>'status-processing','ready'=>'status-ready','applied'=>'status-applied','partial'=>'status-partial','failed'=>'status-failed'])->map(fn($key,$id)=>['id'=>$id,'label'=>trans('dreamlayer::app.'.$key)])->values())" track-by="id" label-by="label" /></x-admin::form.control-group><button class="secondary-button">@lang('dreamlayer::app.search')</button></form>
<div class="dl-result-grid">
@forelse($workflows as $workflow)
@php
$product = $workflow->target ? $products->get($workflow->target['product_id']) : null;
$name = $product ? app(\DreamLayer\UnoPIM\Services\CatalogImages::class)->name($product,$workflow->target['channel'],$workflow->target['locale']) : trans('dreamlayer::app.image-preview');
$partial = $workflow->status === 'failed' && count(array_filter($workflow->progress ?? [], fn ($step) => ($step['status'] ?? '') === 'completed')) > 0;
$status = $workflow->applied_at ? trans('dreamlayer::app.status-applied') : ($workflow->status === 'completed' ? trans('dreamlayer::app.status-ready') : ($partial ? trans('dreamlayer::app.status-partial') : ($workflow->delayed() ? trans('dreamlayer::app.delayed') : match ($workflow->status) {'queued' => trans('dreamlayer::app.status-queued'), 'running' => trans('dreamlayer::app.status-processing'), default => trans('dreamlayer::app.status-failed')})));
$status = match ($workflow->phase()) {'waiting' => trans('dreamlayer::app.waiting'), 'downloading' => trans('dreamlayer::app.downloading'), 'retrying_delivery' => trans('dreamlayer::app.retrying_delivery'), default => $status};
@endphp
<article class="dl-result-card"><h2 class="font-semibold">{{ $name }}</h2><p class="text-xs mb-2">{{ $workflow->target['sku'] ?? '' }} · {{ $status }}</p>

<v-dreamlayer-library-preview :descriptor="@js(['type'=>'dreamlayer.workflow','id'=>$workflow->id])" :title="@js($name)" :image-url="@js($workflow->status === 'completed' && app(\DreamLayer\UnoPIM\Services\ImageStore::class)->valid($workflow->result_path) ? route('dreamlayer.image',$workflow->id) : null)"></v-dreamlayer-library-preview><p class="text-xs mt-3" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">{{ $workflow->steps[0]['instruction'] ?: trans('dreamlayer::app.remove-background') }}</p></article>
@empty<p class="dl-muted">@lang('dreamlayer::app.no-results')</p>@endforelse
</div><div class="mt-4">{{ $workflows->links() }}</div></div>
@include('dreamlayer::workflow')
@include('dreamlayer::library-preview')
</x-admin::layouts>
