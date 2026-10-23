# DreamLayer for UnoPim 0.5.5

This release brings the persistent DreamLayer editor, inline image references,
saved conversations and compact result cards to the public extension package.
The extension works through UnoPim's existing APIs; no core patch is required.

## Changes since the previous public release

- A docked conversation workspace and expanded editor, with saved conversations
  that can be reopened, renamed, bookmarked and deleted by their owner.
- Inline `@Asset-N` references, multi-product requests, private image upload,
  follow-up refinement and separate review/Apply for each product.
- Saved batch conversations restore all result cards in selection order.
- Chat assistance cannot inject references from earlier requests into a new edit.
- Oversized catalog previews are fitted before completion. Apply uses the exact
  reviewed bytes and refuses a stale review; original downloads remain intact.
- Compact result cards and editing-state controls, including live settings refresh.
- Try again restores user wording instead of internal batch instructions. The
  retry keeps the authenticated original source and product, and the server adds
  source-routing context only to the image instruction. Repeated retries remain
  clean. For older saved jobs the original history message is used; older unsaved
  jobs use the cleaned processing instruction if the original wording was not kept.

## Validation

Independent real-account desktop acceptance passed 0.5.1, 0.5.3 and the 0.5.4
compact-card changes on UnoPim 3.1.3. The 0.5.2 inline-reference run passed that
feature and identified the rewrite defect fixed in 0.5.3. The 0.5.4 run identified
the older batch retry defect addressed here. These reports are prior-version
acceptance, not a claim that an independent tester reran 0.5.5.

The 0.5.5 local server regression run passed 174 tests and 1,100 assertions.
Fifteen tests are skipped on this host: fourteen require native chat hooks absent
from released 3.1.3, and one requires the separate PostgreSQL concurrency fixture.
Tests use disposable databases and simulated provider responses, with no paid
requests. The retry regressions cover new and old batch data, saved history,
unsaved fallback, repeated retries, pinned source/product and prompt-length limits.

The release workflow checks installation and workflow compatibility on official
UnoPim 0.1.0, 1.0.1, 2.1.6 and 3.1.3. This does not imply real-account browser
acceptance on every host. See [compatibility details](COMPATIBILITY.md).

## Known follow-ups

- Worker-side fitting took 11–52 seconds for some 2048-pixel square images on the
  independent test host. First-open blocking is fixed; fitting can still extend
  the Processing phase.
- The editing-off Apply restriction is currently in the interface. The endpoint
  retains product-edit permission, ownership, destination and reviewed-byte checks,
  but does not independently enforce the editing switch.
- The floating Latest messages control can overlap result actions, and slow
  settings requests can overlap. These are tracked UI follow-ups.
- Typing a literal image-reference tag may require Escape to dismiss the picker.
- Real phone-width and browser-toolbar zoom coverage remains limited; simulated
  widths are labelled as such. The current acceptance focus is desktop.
- Service-side chat grants/limits and a new onboarding/reconnect cycle were not
  repeated in the latest independent runs. Do not infer coverage from prior runs.

## Installation and upgrade

Download `unopim-dreamlayer-0.5.5.zip` from the GitHub release and follow the
[installation guide](../README.md#install). Keep the host dependencies unchanged.
Back up the host database and private image storage before upgrading, run the
extension migrations, clear application caches and restart the supervised
DreamLayer workers. Existing settings and connection remain installation-owned.
The 0.5.5 retry fix introduces no new database migration.
