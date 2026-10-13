<x-admin::layouts>
<x-slot:title>DreamLayer chat</x-slot>
@include('dreamlayer::styles')
<div class="dl-page text-gray-800 dark:text-gray-200"><section class="dl-section"><h1>DreamLayer chat</h1>
<p>Choose your product images in the chat and describe what you’d like to change. Review the preview before applying it to your catalog.</p>
<div class="dl-actions">
@if(core()->getConfigData('general.magic_ai.agentic_pim.enabled'))
<button type="button" class="primary-button" onclick="window.dispatchEvent(new CustomEvent('ai-agent:compose'));">Open chat</button>
@else
<a class="primary-button" href="{{ route('dreamlayer.settings') }}">Set up chat</a>
@endif
<a class="secondary-button" href="{{ route('dreamlayer.results') }}">My image results</a>
</div></section></div>
</x-admin::layouts>
