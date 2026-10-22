@if(bouncer()->hasPermission('catalog.products.edit'))
<a class="secondary-button" href="{{ route('dreamlayer.choose', ['product_id' => $product->id]) }}">@lang('dreamlayer::app.editor')</a>
@endif
