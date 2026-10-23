<!doctype html>
<html dir="{{ str_starts_with(app()->getLocale(), 'ar') ? 'rtl' : 'ltr' }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DreamLayer</title>
    <style>@include('dreamlayer::studio.css')</style>
</head>
<body>
    <div id="studio">
        <header class="topbar">
            <div class="brand"><img class="brand-mark" src="{{ \DreamLayer\UnoPIM\Support\Brand::logo() }}" width="34" height="26" alt="">DreamLayer <span class="brand-ai">AI</span></div>
            <nav aria-label="DreamLayer"><button data-action="new" class="icon-button" title="{{ trans('dreamlayer::studio.new-chat') }}" aria-label="{{ trans('dreamlayer::studio.new-chat') }}">＋</button><button data-action="history" class="quiet">{{ trans('dreamlayer::studio.history') }}</button><button data-action="expand" class="icon-button" aria-label="{{ trans('dreamlayer::studio.expand') }}" title="{{ trans('dreamlayer::studio.expand') }}">⤢</button><button data-action="close" class="icon-button" aria-label="{{ trans('dreamlayer::studio.close') }}">×</button></nav>
        </header>
        <div id="connection" hidden></div>
        <div id="conversation" tabindex="0" aria-label="{{ trans('dreamlayer::app.conversation') }}"><div id="messages" class="chat-width"></div><button id="jump-latest" class="outline jump-latest" hidden>{{ trans('dreamlayer::studio.latest') }} ↓</button></div>
        <footer id="composer" class="composer">
            <div class="chat-width">
                <div id="sources"></div>
                <form id="send-form">
                    <label class="sr-only" for="message">{{ trans('dreamlayer::app.instruction') }}</label>
                    <textarea id="message" name="message" rows="2" maxlength="4000" placeholder="{{ trans('dreamlayer::studio.composer-mentions') }}"></textarea>
                    <input id="upload-file" type="file" accept="image/png,image/jpeg,image/webp" hidden><div class="compose-tools"><button type="button" data-action="pick" class="icon-button attach" aria-label="{{ trans('dreamlayer::studio.add-images') }}" title="{{ trans('dreamlayer::studio.add-images') }}">＋</button><button type="button" data-action="upload" class="icon-button attach" aria-label="{{ trans('dreamlayer::studio.upload-image') }}" title="{{ trans('dreamlayer::studio.upload-image') }}">↥</button><label class="sr-only" for="operation">{{ trans('dreamlayer::app.operation') }}</label><select id="operation" name="operation"><option value="image_to_image">{{ trans('dreamlayer::app.edit') }}</option><option value="background_remove">{{ trans('dreamlayer::app.remove-background') }}</option><option value="text_to_image">{{ trans('dreamlayer::app.generate') }}</option></select><button id="send" class="primary" type="submit" aria-label="{{ trans('dreamlayer::app.create-preview') }}">{{ trans('dreamlayer::app.create-preview') }} <span aria-hidden="true">↑</span></button></div>
                </form>
                <p id="task-help" class="fine"></p><p id="error" class="error" role="alert" hidden></p>
                <div class="composer-note"><button data-action="tasks" class="text-button">{{ trans('dreamlayer::studio.tasks') }}</button><button data-action="settings" class="text-button">{{ trans('dreamlayer::studio.settings-link') }}</button></div>
            </div>
        </footer>
    </div>
    <dialog id="history-dialog" class="history-drawer" aria-labelledby="history-title">
        <header class="drawer-header"><h2 id="history-title">{{ trans('dreamlayer::studio.history') }}</h2><button data-action="close-dialog" class="icon-button" aria-label="{{ trans('dreamlayer::studio.close') }}">×</button></header>
        <div role="tablist" class="tabs" aria-label="{{ trans('dreamlayer::studio.history') }}"><button role="tab" data-tab="chats" aria-selected="true">{{ trans('dreamlayer::studio.chats') }}</button><button role="tab" data-tab="bookmarks" aria-selected="false">{{ trans('dreamlayer::studio.bookmarks') }}</button><button role="tab" data-tab="images" aria-selected="false">{{ trans('dreamlayer::studio.images') }}</button></div>
        <p class="fine drawer-copy">{{ trans('dreamlayer::studio.history-help') }}</p><div id="history-list" role="tabpanel" class="drawer-body"></div><p id="history-error" class="error" role="alert" hidden></p><button id="history-more" class="outline" hidden>{{ trans('dreamlayer::app.load-more') }}</button>
    </dialog>
    <dialog id="picker-dialog" class="picker" aria-labelledby="picker-title"><header class="drawer-header"><h2 id="picker-title">{{ trans('dreamlayer::studio.picker-title') }}</h2><button data-action="close-dialog" class="icon-button" aria-label="{{ trans('dreamlayer::studio.close') }}">×</button></header><p class="fine">{{ trans('dreamlayer::studio.picker-help') }}</p><button id="current-product" class="outline" hidden>{{ trans('dreamlayer::studio.add-current') }}</button><form id="search-form" class="search-form"><label for="sku">{{ trans('dreamlayer::studio.search-products') }}</label><div><input id="sku" name="query" maxlength="100" autocomplete="off"><button class="outline">{{ trans('dreamlayer::app.search') }}</button></div></form><p id="picker-error" class="error" role="alert" hidden></p><div id="products" class="products"></div><button id="catalog-more" class="outline" hidden>{{ trans('dreamlayer::app.load-more') }}</button><footer class="picker-footer"><span id="selected-count"></span><button data-action="use-selection" class="primary">{{ trans('dreamlayer::studio.use-selection') }}</button></footer></dialog>
    <dialog id="tasks-dialog" class="small-dialog" aria-labelledby="tasks-title"><header class="drawer-header"><h2 id="tasks-title">{{ trans('dreamlayer::studio.tasks') }}</h2><button data-action="close-dialog" class="icon-button" aria-label="{{ trans('dreamlayer::studio.close') }}">×</button></header><div id="task-list" class="task-list"></div></dialog>
    <dialog id="image-dialog" class="image-dialog" aria-label="{{ trans('dreamlayer::studio.view-image') }}"><button data-action="close-dialog" class="icon-button" aria-label="{{ trans('dreamlayer::studio.close') }}">×</button><img id="full-image" alt="{{ trans('dreamlayer::studio.image-result') }}"></dialog>
    <dialog id="confirm-dialog" class="small-dialog" aria-labelledby="confirm-title"><h2 id="confirm-title"></h2><p id="confirm-help"></p><label id="rename-label" for="rename-title">{{ trans('dreamlayer::studio.title-label') }}</label><input id="rename-title" maxlength="120"><p id="confirm-error" class="error" role="alert" hidden></p><div class="dialog-actions"><button data-action="close-dialog" class="outline">{{ trans('dreamlayer::studio.cancel') }}</button><button id="confirm-button" class="primary"></button></div></dialog>
    <script>window.DreamLayerConfig = @json($config);</script>
    <script>@include('dreamlayer::studio.js')</script>
</body>
</html>
