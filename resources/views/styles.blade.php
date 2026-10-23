{{-- UnoPIM's AJAX navigation imports body styles, not the destination's head.
     Keep this outside Vue's #app in the body stack so both a direct load and
     sidebar/history navigation receive it. Deduplicate shared result renderers. --}}
@pushOnce('scripts', 'dreamlayer-styles')
<style>
:root{--dl-border:color-mix(in srgb,currentColor 22%,transparent);--dl-accent:var(--color-primary-600,currentColor);--dl-surface:color-mix(in srgb,currentColor 4%,transparent);--dl-muted:color-mix(in srgb,currentColor 70%,transparent);--dl-danger:var(--color-red-600,currentColor)}
.dl-page{max-width:1040px;padding:32px;line-height:1.6;color:inherit}.dl-page h1{font-size:24px;font-weight:600;line-height:1.3}.dl-page h2{font-size:16px;font-weight:600}.dl-page p{margin:0}.dl-stack{display:grid;gap:24px}.dl-section{display:grid;gap:14px;padding:24px 0}.dl-section+.dl-section{border-top:1px solid var(--dl-border)}.dark .dl-section+.dl-section{border-color:var(--dl-border)}.dl-heading,.dl-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.dl-actions .primary-button,.dl-actions .secondary-button,.dl-page .primary-button,.dl-page .secondary-button{display:inline-flex;width:auto;flex:none;justify-content:center;align-items:center;min-height:40px;padding:9px 16px;font-size:14px;line-height:20px}.dl-muted{color:inherit;font-size:14px}.dark .dl-muted{color:inherit}.dl-status-chip{display:inline-flex;align-items:center;border-radius:99px;padding:3px 11px;font-size:12px;font-weight:500;background:var(--dl-surface);color:inherit}.dark .dl-status-chip{background:var(--dl-surface);color:inherit}.dl-status-chip.is-connected{background:var(--dl-surface);color:inherit}.dark .dl-status-chip.is-connected{background:var(--dl-surface);color:inherit}.dl-account{display:grid;grid-template-columns:1fr auto;gap:24px;padding:20px;border:1px solid var(--dl-border);border-radius:8px}.dark .dl-account{border-color:var(--dl-border)}.dl-account dt{font-size:12px;color:inherit;margin-bottom:4px}.dark .dl-account dt{color:inherit}.dl-account dd{font-size:14px;overflow-wrap:anywhere}.dl-account .dl-balance{font-size:22px;font-weight:600}.dl-code{font-size:24px;font-weight:600;letter-spacing:3px;font-family:ui-monospace,monospace}.dl-code-box{display:grid;gap:10px;padding:20px;background:var(--dl-surface);border:1px solid var(--dl-border);border-radius:8px}.dark .dl-code-box{background:var(--dl-surface);border-color:var(--dl-border)}.dl-link{color:inherit;text-decoration:underline;text-underline-offset:3px}.dark .dl-link{color:inherit}.dl-error{color:inherit}.dark .dl-error{color:inherit}.dl-notice{padding:14px 16px;background:var(--dl-surface);border-radius:8px;color:inherit}.dark .dl-notice{background:var(--dl-surface);color:inherit}.dl-field{display:grid;gap:8px;max-width:620px}.dl-field input,.dl-field select{width:100%;min-height:42px;padding:9px 12px;border:1px solid var(--dl-border);border-radius:6px;background:transparent;color:inherit}.dl-field select option{color:inherit;background:var(--dl-surface)}.dl-page summary{cursor:pointer;font-weight:600;padding:8px 0}.dl-page details>div{padding-top:16px}.dl-button-link{border:0;background:transparent;color:inherit;text-decoration:underline;text-underline-offset:3px;padding:8px 0}.dl-danger{color:inherit}.dark .dl-danger{color:inherit}.dl-page button:disabled{opacity:.5;cursor:not-allowed}.dl-page button:focus-visible,.dl-page a:focus-visible,.dl-page summary:focus-visible{outline:2px solid var(--dl-accent);outline-offset:3px}.dl-result-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:20px}.dl-result-card{border:1px solid var(--dl-border);border-radius:8px;overflow:hidden;padding:16px;display:grid;gap:12px}.dark .dl-result-card{border-color:var(--dl-border)}.dl-result-card img{border-radius:6px}.dl-result-card .primary-button{width:auto}.dl-card-title{font-weight:600;overflow-wrap:anywhere}@media(max-width:640px){.dl-page{padding:20px 16px}.dl-account{grid-template-columns:1fr}.dl-code{font-size:22px}.dl-actions{gap:10px}}
.dl-status-chip.is-pending{background:var(--dl-surface);color:inherit}.dark .dl-status-chip.is-pending{background:var(--dl-surface);color:inherit}
.dl-page :is(.primary-button,.secondary-button){justify-self:start;max-width:100%}
/* Contain the host's absolutely positioned, visually hidden form inputs.
   Otherwise they can extend the document beyond the scrolling admin panel. */
.dl-settings{position:relative}
.dl-editor-entry{display:grid;gap:8px;padding:16px 0;border-block:1px solid var(--dl-border)}
.dl-editor-entry .dl-link{justify-self:start;display:inline-flex;align-items:center;min-height:36px;font-size:14px;font-weight:500}
/* Settings have two distinct actions: authorize an account connection, then
   save editor preferences. Boundaries and nested consent keep those clear. */
.dl-settings .dl-stack{gap:16px}
.dl-settings .dl-settings-panel{display:grid;gap:16px;padding:24px;border:1px solid var(--dl-border);border-radius:10px;min-width:0}
.dl-settings .dl-settings-panel h2{font-size:18px;line-height:1.4;margin:0}
.dl-settings .dl-panel-intro{display:grid;gap:8px}
.dl-settings [data-control-group]{margin-bottom:0;position:relative}
.dl-settings [data-control-group]>p{margin-top:8px;max-width:76ch}
.dl-settings .dl-setting-row{display:grid;gap:16px;border-top:1px solid var(--dl-border);padding-top:20px;min-width:0}
.dl-settings .dl-consent-box{display:grid;gap:12px;padding:16px;border-inline-start:3px solid var(--dl-border);background:var(--dl-surface);min-width:0}
.dl-settings .dl-consent-box .dl-data-disclosure{background:transparent}
.dl-settings .dl-consent input[type=checkbox]~label{display:inline-flex;align-items:center;justify-content:center;min-width:32px;min-height:32px}
.dark .dl-settings .dl-consent input[type=checkbox]:checked~label{color:rgb(var(--c-primary-300,196 181 253))}
.dl-settings .dl-data-disclosure{border:1px solid var(--dl-border);border-radius:6px;background:var(--dl-surface);min-width:0}
.dl-settings .dl-data-disclosure>summary{padding:12px 16px;font-size:14px;line-height:1.5;min-height:44px}
.dl-settings .dl-data-disclosure[open]>summary{border-bottom:1px solid var(--dl-border)}
.dl-settings .dl-data-topics{display:grid;gap:16px;padding:16px;max-width:80ch}
.dl-settings .dl-data-topics>div{display:grid;gap:6px}
.dl-settings .dl-data-topics h3{font-size:14px;font-weight:600;line-height:1.5;margin:0}
.dl-settings .dl-data-topics p{font-size:14px;line-height:1.6}
.dl-settings details.dl-advanced-panel{display:block}
.dl-settings .dl-advanced-panel>summary{padding:0;font-size:16px;line-height:1.6}
@supports selector(:has(*)){.dl-settings .dl-managed-setting:not(:has(input[name=managed_chat]:checked)) .dl-settings-consent{display:none}}
@media(max-width:640px){.dl-settings .dl-settings-panel{padding:18px 16px}.dl-settings .dl-consent-box{padding:14px 12px}.dl-settings .dl-data-topics{padding:12px}.dl-settings .dl-data-disclosure>summary{padding:12px}.dl-settings .dl-settings-panel h2{font-size:17px}}
/* The host teleports its save bar outside the scrolling panel. Leave room for
   it here so the final settings remain reachable when the form is dirty. */
.unsaved-bar-open .dl-settings{padding-bottom:108px}
/* Older host sidebar offsets must not indent the save bar on small screens. */
@media(max-width:1024px){body.unsaved-bar-open:has(.dl-settings) .unsaved-bar{left:0;right:0}}
.dl-switch{--dl-switch-on:rgb(var(--c-primary-700,109 40 217));--dl-switch-focus:rgb(var(--c-primary-700,109 40 217))}
.dark .dl-switch{--dl-switch-on:rgb(var(--c-primary-500,139 92 246));--dl-switch-focus:rgb(var(--c-primary-300,196 181 253))}
.dl-settings .dl-switch input[type=checkbox]~label{background:color-mix(in srgb,currentColor 55%,transparent);box-shadow:inset 0 0 0 1px color-mix(in srgb,currentColor 60%,transparent);transition:background-color .15s ease}
.dl-settings .dl-switch input[type=checkbox]:checked~label{background:var(--dl-switch-on);box-shadow:none}
.dl-settings .dl-switch input[type=checkbox]:focus-visible~label{outline:2px solid var(--dl-switch-focus);outline-offset:3px}
.dl-settings .dl-switch input[type=checkbox]:disabled~label{opacity:.6;cursor:not-allowed}
@media(prefers-reduced-motion:reduce){.dl-settings .dl-switch input[type=checkbox]~label,.dl-settings .dl-switch input[type=checkbox]~label::after{transition:none}}
.dl-workflow{padding:16px!important;line-height:1.6}.dl-workflow>p,.dl-workflow>details{margin-bottom:12px}.dl-workflow .secondary-button,.dl-workflow .primary-button{display:inline-flex;width:auto;padding:8px 14px;font-size:13px;line-height:20px}.dl-workflow .dl-result-action{margin-top:16px}.dl-workflow img{margin-top:16px;margin-bottom:16px}
</style>
@endPushOnce
@pushOnce('scripts', 'dreamlayer-editor-styles')
<style>
.dl-editor-grid{display:grid;grid-template-columns:minmax(240px,1fr) minmax(320px,1.5fr);gap:32px;padding-top:32px;align-items:start}.dl-picker-images{display:flex;gap:12px;flex-wrap:wrap}.dl-image-choice{display:grid;gap:8px;max-width:140px;padding:8px;border:1px solid var(--dl-border);border-radius:8px}.dl-image-choice[aria-pressed=true]{outline:2px solid var(--dl-accent);outline-offset:2px}.dl-image-choice img{width:120px;height:100px;object-fit:contain}.dl-message{padding:12px 16px;border:1px solid var(--dl-border);border-radius:8px;white-space:pre-wrap}.dl-field textarea{padding:12px;border:1px solid var(--dl-border);border-radius:8px;color:inherit;background:transparent}.dl-editor-grid button:disabled{opacity:.5;cursor:not-allowed}@media(max-width:800px){.dl-editor-grid{grid-template-columns:1fr}}
</style>
@endPushOnce

@pushOnce('scripts', 'dreamlayer-semantic-colors')<style>.dl-error,.dl-danger{color:var(--dl-danger)}.dl-muted{color:var(--dl-muted)}.dl-link{color:var(--dl-accent)}</style>@endPushOnce
