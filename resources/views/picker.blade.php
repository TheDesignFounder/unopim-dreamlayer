@pushOnce('scripts')
<style>
.dl-select-image{font-size:12px;font-weight:500;line-height:1.4;padding:4px 8px;border:1px solid #a78bfa;border-radius:6px;color:inherit;background:transparent}.dl-select-image:hover{background:#8b5cf615}.dl-select-image:disabled{opacity:.5}.dl-picker{position:relative;padding:8px 12px;color:#374151}.dark .dl-picker{color:#e5e7eb}.dl-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.dl-chip{display:flex;align-items:center;gap:4px;border:1px solid #a78bfa;border-radius:8px;padding:4px;font-size:12px}.dl-chip img{width:32px;height:32px;object-fit:contain}
.dl-mentions{position:absolute;bottom:100%;left:10px;right:10px;z-index:30;display:flex;background:#fff;color:#20212b;border:1px solid #b8bac5;border-radius:16px;box-shadow:0 12px 36px #0003;overflow:hidden}.dark .dl-mentions{background:#211b31;color:#eee;border-color:#655875}.dl-mention-list{flex:1;min-width:0;max-height:320px;overflow:auto}.dl-mention-row{display:flex;align-items:center;gap:10px;width:100%;padding:10px;text-align:left}.dl-mention-row[aria-selected=true]{background:#eef2ff}.dark .dl-mention-row[aria-selected=true]{background:#39304d}.dl-mention-row img{width:44px;height:44px;border-radius:7px;object-fit:contain}.dl-mention-label{min-width:0;overflow-wrap:anywhere}.dl-mention-label strong,.dl-mention-label small{display:block}.dl-mention-label small{font-size:11px;opacity:.8}.dl-mention-preview{width:38%;padding:12px;border-left:1px solid #8884;display:flex;flex-direction:column;justify-content:center;text-align:center;font-size:12px}.dl-mention-preview img{width:100%;max-height:210px;object-fit:contain;margin-bottom:8px}.dl-mention-hint{padding:8px 10px;font-size:12px;opacity:.85}
</style>
@endPushOnce
@pushOnce('scripts')
<script type="text/x-template" id="dl-picker-template">
<div class="dl-picker">
<div v-if="mentionOpen && !disabled" class="dl-mentions" aria-label="Choose an image reference">
<div class="dl-mention-list" role="listbox" aria-label="Catalog image suggestions">
<p class="dl-mention-hint">Choose an image · ↑ ↓ to browse · Enter to insert</p>
<p v-if="loading" class="dl-mention-hint" role="status">Searching images…</p><p v-if="error" class="dl-mention-hint" role="alert" v-text="error"></p>
<button v-for="(img,i) in suggestions" :key="img.image_url" type="button" class="dl-mention-row" role="option" :aria-selected="active===i" :disabled="!options || loading" @mouseenter="active=i" @mousedown.prevent @click="chooseMention(img)"><img :src="img.image_url" alt=""><span class="dl-mention-label"><strong>@{{img.name}}</strong><small>@{{img.sku}} · @{{img.attribute}} @{{img.index+1}}</small></span></button>
<p v-if="!loading && !suggestions.length" class="dl-mention-hint">No images found. Search by product name or SKU after @.</p>
<button v-if="next" type="button" class="dl-mention-row" :disabled="loading" @mousedown.prevent @click="load(next)">Load more images</button>
</div>
<div v-if="suggestions[active]" class="dl-mention-preview"><img :src="suggestions[active].image_url" :alt="suggestions[active].name"><strong>@{{suggestions[active].name}}</strong><span>@{{suggestions[active].channel}} / @{{suggestions[active].locale}} · @{{suggestions[active].attribute}} @{{suggestions[active].index+1}}</span></div>
</div>
<button type="button" class="dl-select-image" @mousedown.prevent @click="open()" :disabled="disabled">Select Product Image</button>
<div class="dl-chips"><div v-for="(image,i) in selected" :key="image.token" class="dl-chip"><img :src="image.image_url" alt=""><span :title="`${image.sku} · ${image.attribute} ${image.index+1} · ${image.channel} / ${image.locale}`">@{{image.name}}</span><button type="button" :disabled="disabled" :aria-label="'Remove '+image.name" @click="remove(i)">×</button></div></div>

</div>
</script>
<script type="module">
app.component('v-dreamlayer-picker', {
    template: '#dl-picker-template',
    props: ['value', 'conversationId', 'disabled', 'draft', 'cursor'],
    emits: ['change', 'replace-draft'],
    data() {
        return {
            mentionOpen: false,
            mentionRange: null,
            active: 0,
            query: '',
            products: [],
            selected: this.value?.images || [],
            next: null,
            loading: false,
            error: '',
            timer: null,
            serial: 0,
            product: null,
            options: null
        };
    },
    computed: {
        suggestions() {
            return this.products.flatMap((p) => p.images);
        }
    },
    watch: {
        value: {
            deep: true,
            handler(v) {
                this.selected = v?.images || [];
            }
        },
        conversationId() {
            this.selected = [];
            this.mentionOpen = false;
            this.publish();
        },
        draft(text, old) {
            if (this.disabled) return;
            const before = this.references(old);
            const now = this.references(text);
            this.selected = this.selected.filter(
                (i) => !before.includes(i.reference) || now.includes(i.reference)
            );
            this.publish();
            this.findMention();
        },
        cursor() {
            this.findMention();
        },
        disabled(v) {
            if (v) this.mentionOpen = false;
        }
    },
    mounted() {
        this.loadOptions();
        this.listener = () => this.open();
        window.addEventListener('ai-agent:picker', this.listener);
        const url = new URL(location.href);
        if (location.pathname.includes('/dreamlayer/choose') && !history.state?.dreamlayerPickerOpened) {
            history.replaceState({ ...history.state, dreamlayerPickerOpened: true }, '');
            this.product = url.searchParams.get('product_id');
            this.$nextTick(() => {
                window.dispatchEvent(new CustomEvent('ai-agent:compose'));
                if (this.product) this.open();
            });
        }
    },
    beforeUnmount() {
        window.removeEventListener('ai-agent:picker', this.listener);
        clearTimeout(this.timer);
    },
    methods: {
        identity(i) {
            return i.image_url;
        },
        label(i) {
            return i.mention || i.reference;
        },
        pattern(images = this.selected) {
            // Keep these label boundaries in sync with the core highlighter and
            // CatalogImages::selected(). Shared mention fixtures cover all three.
            const labels = [...new Set(images.map((i) => '@' + this.label(i)))].sort(
                (a, b) => b.length - a.length
            );
            return labels.length
                ? new RegExp(
                      '(?<![\\p{L}\\p{N}_])(?:' +
                          labels.map((x) => x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') +
                          ')(?![\\p{L}\\p{N}_-])',
                      'gu'
                  )
                : null;
        },
        references(text) {
            const pattern = this.pattern();
            if (!pattern) return [];
            return [...(text || '').matchAll(pattern)].map(
                (m) => this.selected.find((i) => '@' + this.label(i) === m[0])?.reference
            );
        },
        publish() {
            this.$emit('change', {
                images: this.selected,
                references: this.selected.map((i) => '@' + this.label(i))
            });
        },
        allocate() {
            const key = 'dreamlayer.asset-counter.' + this.conversationId;
            let count = 0;
            try {
                count = Number(sessionStorage.getItem(key)) || 0;
            } catch {}
            count = Math.max(count, ...this.selected.map((i) => Number(i.reference?.slice(6)) || 0)) + 1;
            try {
                sessionStorage.setItem(key, String(count));
            } catch {}
            return 'Asset-' + count;
        },
        add(i) {
            // Names make readable mentions; image identity and the server-issued
            // token bind the selection to a particular catalog image. Duplicate
            // names get display suffixes, not permission to resolve another image.
            const existing = this.selected.find((x) => this.identity(x) === this.identity(i));
            if (existing) return existing;
            if (this.selected.length >= (this.options?.batch_supported ? 5 : 1)) {
                this.error = this.options?.batch_supported
                    ? 'Choose at most five images.'
                    : 'This API supports one selected image. Small batches require the batch capability.';
                return null;
            }
            const base = Array.from(
                (i.name || i.sku)
                    .replace(/[@\r\n\t]+/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim()
            )
                .slice(0, 180)
                .join('');
            let mention = base;
            let suffix = 2;
            while (this.selected.some((x) => this.label(x) === mention))
                mention = base + ' (' + suffix++ + ')';
            const image = { ...i, reference: this.allocate(), mention };
            this.selected.push(image);
            this.publish();
            return image;
        },
        findMention() {
            if (this.disabled) return;
            const text = (this.draft || '').slice(0, this.cursor ?? 0);
            const start = text.lastIndexOf('@');
            if (start < 0 || /[\p{L}\p{N}_]$/u.test(text.slice(0, start))) {
                this.mentionOpen = false;
                return;
            }
            const query = text.slice(start + 1);
            if (
                query.includes('\n') ||
                this.selected.some(
                    (i) =>
                        query === this.label(i) ||
                        query.startsWith(this.label(i) + ' ') ||
                        query.startsWith(this.label(i) + ',')
                )
            ) {
                this.mentionOpen = false;
                return;
            }
            const changed = !this.mentionOpen || this.query !== query;
            this.mentionRange = { start, end: text.length };
            this.mentionOpen = true;
            if (changed) {
                this.query = query;
                if (query) this.product = null;
                this.active = 0;
                this.searchLater();
            }
        },
        handleKey(e) {
            // Returning true tells the core composer that this key chose/navigated
            // an image. In particular, Enter must not also send the unfinished draft.
            if (!this.mentionOpen || this.disabled) return false;
            if (e.key === 'Escape') {
                e.preventDefault();
                this.mentionOpen = false;
                return true;
            }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                this.active = Math.max(
                    0,
                    Math.min(this.suggestions.length - 1, this.active + (e.key === 'ArrowDown' ? 1 : -1))
                );
                this.$nextTick(() =>
                    this.$el
                        .querySelector('[role=option][aria-selected=true]')
                        ?.scrollIntoView({ block: 'nearest' })
                );
                return true;
            }
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (!this.loading && this.options && this.suggestions[this.active])
                    this.chooseMention(this.suggestions[this.active]);
                return true;
            }
            return false;
        },
        chooseMention(i) {
            const image = this.add(i);
            if (!image || !this.mentionRange) return;
            this.$emit('replace-draft', { ...this.mentionRange, text: '@' + this.label(image) + ' ' });
            this.mentionOpen = false;
        },
        open() {
            if (this.disabled) return;
            window.dispatchEvent(new CustomEvent('ai-agent:compose'));
            const draft = this.draft || '';
            const cursor = Math.min(this.cursor ?? draft.length, draft.length);
            if (this.mentionOpen) {
                this.$emit('replace-draft', { start: cursor, end: cursor, text: '' });
                return;
            }
            this.$emit('replace-draft', {
                start: cursor,
                end: cursor,
                text: (cursor > 0 && !/\s/.test(draft[cursor - 1]) ? ' ' : '') + '@'
            });
            this.loadOptions();
        },
        remove(i) {
            const image = this.selected[i];
            const pattern = this.pattern();
            const text = pattern
                ? (this.draft || '').replace(pattern, (match) =>
                      match === '@' + this.label(image) ? '' : match
                  )
                : this.draft || '';
            this.selected.splice(i, 1);
            this.publish();
            this.$emit('replace-draft', { start: 0, end: (this.draft || '').length, text });
        },
        searchLater() {
            clearTimeout(this.timer);
            this.loading = true;
            this.serial++;
            this.timer = setTimeout(() => this.load(1), 200);
        },
        async loadOptions() {
            try {
                const r = await fetch(@json(route('dreamlayer.catalog.options')), { headers: { Accept: 'application/json' } });
                if (!r.ok) throw Error();
                this.options = await r.json();
            } catch {
                this.error = 'Could not check image capabilities. Try again before submitting.';
            }
        },
        async load(page) {
            // Search requests can finish out of order. Ignore older responses so
            // they cannot replace results for the text the visitor is now typing.
            const serial = ++this.serial;
            this.loading = true;
            this.error = '';
            try {
                const url = new URL(@json(route('dreamlayer.catalog')));
                url.searchParams.set('q', this.query);
                url.searchParams.set('page', page);
                if (this.product) url.searchParams.set('product_id', this.product);
                const r = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!r.ok) throw Error('Catalog images are unavailable. Check your permissions.');
                const d = await r.json();
                if (serial !== this.serial) return;
                this.products = page === 1 ? d.products : [...this.products, ...d.products];
                this.next = d.next_page;
            } catch (e) {
                if (serial === this.serial) this.error = e.message;
            } finally {
                if (serial === this.serial) this.loading = false;
            }
        }
    }
});
</script>
@endPushOnce
