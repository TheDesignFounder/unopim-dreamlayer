{{-- Expand in place. A hash link triggers some hosts' popstate navigation and
     remounts the settings page, discarding the pending connection choices. --}}
<details class="dl-data-disclosure">
    <summary>@lang('dreamlayer::app.privacy')</summary>
    <div class="dl-data-topics">
        @foreach(['images','chat','connection','storage','providers'] as $topic)
            <div>
                <h3>{{ trans('dreamlayer::app.privacy-'.$topic.'-title') }}</h3>
                <p>{{ trans('dreamlayer::app.privacy-'.$topic) }}</p>
            </div>
        @endforeach
    </div>
</details>
