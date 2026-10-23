# 0.4.0 review addendum

The report below records the earlier 0.3.0 checks. This candidate adds the DreamLayer side chat, expanded workspace, saved-thread drawer, and native task selector. New workspace acceptance is documented in `ACCEPTANCE_TEST.md`; the old host-matrix evidence does not certify the new UI on older releases. The 0.4.0 regression run passed 150 PHP tests (874 assertions, 15 skips) and the fixture browser suite; real-account acceptance remains a separate run. No public release or deployment has been made.

# Marketplace review response — local 0.3.0 candidate

Prepared 2026-10-22 in response to the review of 0.2.16 at `de488f3`.
This candidate is local: no release tag, merge, deployment or submission has
been made. Marketplace approval remains UnoPim's decision.

## Required changes

| Review item | Implemented change | Validation |
| --- | --- | --- |
| Released-version compatibility | Added an extension-owned image editor independent of the AI Agent package. Native chat is an optional adapter, disabled when its hooks are absent. | 22 workflow checks pass on official 0.1.0, 1.0.1, 2.1.6 and 3.1.3 installations. All three extension pages render with each host's components. |
| Dependency constraints | Requires the actual host package `unopim/unopim`; supports the tested Laravel families without requiring a different `laravel/ai` version. | Composer adds only DreamLayer; 197/199/203/212 existing locked host packages remain unchanged. `unopim/core` is not a dependency package in these host manifests. |
| Safe startup | Missing fundamental product APIs log a warning and disable the extension. Missing chat hooks disable only the adapter. | Dedicated bootstrap test without product/AI classes; real released hosts start and run Artisan normally. |
| Privacy and defaults | Chat assistance, editor history and native-chat integration are separate opt-ins. No default-provider takeover or host-wide history change. Removed managed API/similarity replacements. | Fresh defaults, explicit consent, encrypted history, secret-redaction and reconnect-race tests pass. Upgrade resets the old chat option and preserves credentials. |
| Data disclosure | Settings, README and listing explain image/instruction transfer, optional messages, account metadata, credentials, local storage and processing providers. | Browser rendering and regression checks cover the settings controls and disclosure. |
| No core patches | Removed `patches/`, `LEGACY_CORE_PATCH.md`, patch attribution and dependency-change installation instructions. | Released hosts use their original packages and locks; ZIP exclusions are enforced by the builder. |
| Product update events | Apply fires `catalog.product.update.before` and `.after` around the normal repository write, with explicit ownership/permission/conflict checks. | Both events fire once; repeated Apply is idempotent; a preview alone leaves the catalog unchanged. |
| Translations | UI/controller messages and ACL labels use translation keys; all 33 locales are supplied. | 245 matching keys and preserved placeholders per locale. AI-assisted review corrected untranslated labels and misleading Apply/background-removal wording; native-speaker certification is not claimed. |
| Packaging | `.gitattributes` and Composer archive rules exclude tests, tools, CI, patches and `NEW_USER_TEST.md`. Installation uses a distribution ZIP plus Composer. | Deterministic ZIP builder checks excluded paths; the extracted package is tested independently. |

## Recommended changes

| Review item | Implemented change | Validation |
| --- | --- | --- |
| Finite polling | Jobs have retry limits and deadlines; expired batches stop waiting children. Explicit recovery preserves request identities and admission limits. | Deadline, recovery, concurrency-limit and stale-delivery regression tests pass. |
| HTTP outside database locks | Workers use short leases; HTTP calls run after the database transaction commits. Batch capability checks run before admission transactions. | Real worker tests assert no database transaction is open during HTTP calls. Worker timeout must remain below the lease duration. |
| Poll logging | Routine operation/poll details use debug logging; failures keep diagnostic warnings. | Log tests exclude credentials, instructions and signed image URLs. |
| Application-wide overrides | Removed global AI client, similarity service and HTTP middleware overrides. Native integration uses the extension registries. Queue connection/name are configurable. | Native integration tests pass on the upstream hook commit; host defaults remain intact. |
| Admin conventions | Settings/editor/filters use UnoPim form control components; styling uses host classes and inherited/semantic colors. Validation is in FormRequests. | Real Blade rendering on all four tags; browser checks on 3.1.3. |
| Complete ACL | Every extension route has an ACL entry. Controllers also enforce product-edit or settings permissions and owner scoping. | Route coverage and permission regression tests pass. |
| Catalog performance | Image attributes are loaded for product families in one query; SKU-prefix search replaces unindexed product-values JSON LIKE queries. | Query-count regression and catalog-selection tests pass. Search now deliberately uses SKU. |
| Settings cache | Settings are scoped to a request/job and invalidated on writes. | Repeated-read query-count tests plus concurrent reconnect tests pass. |
| Apply index | Added `(batch_id, product_id, destination)` index. | Fresh-install, upgrade and rollback migration tests pass on PostgreSQL; MySQL host migrations pass. |

## Listing changes

`MARKETPLACE_LISTING.md` supplies the tested UnoPim versions, UnoPim branding,
plain-language description, data disclosure, pricing, excerpt, SEO title, meta
description and last-updated date. Pricing is **Free extension, requires paid
DreamLayer credits**. Extension versions are no longer used as host compatibility
versions. The public description does not use “prerelease for pilots” or
“development baseline.”

The attached current-editor screenshots and MP4 replace the old native-chat
demo for this candidate. They use clearly disclosed replays of previously
generated images and are not live AI or billing evidence. The final real-account
acceptance test should supply its own results and optional recording. Public
links and the release tag should be updated only during authorized publication.

## Test results

- Four released hosts: 22 workflow checks each, plus editor/results/settings rendering.
- UnoPim 3.1.3 regression run: 144 tests, 821 assertions, no failures/errors; 15 expected skips (14 require native hooks, one requires PostgreSQL).
- Upstream development commit `1abbb5bfa393f62b7d3d8496a3afea2a93df4084`: 144 tests, 883 assertions, no failures/errors; only the PostgreSQL-specific test skipped. Its 209 locked dependencies also remain unchanged.
- PostgreSQL concurrency: one test, eight assertions, passed.
- PostgreSQL migration runner: fresh install/repeat/rollback/reinstall, historical migration preservation, missing-column repair, and 0.2.16 upgrade passed.
- Browser: loaded preview, explicit Apply, follow-up refinement, mobile width and light/dark pages passed; no JavaScript page errors.
- Account connection tests: 19 tests and 165 assertions each on 0.1.0, 1.0.1 and 2.1.6; connection-to-editor integration is also covered on 3.1.3.
- All 33 locale files pass key/placeholder and untranslated-label checks; safe-disable bootstrap and Composer validation pass. Composer emits only the advisory about an explicit version field used for this local ZIP candidate.

Provider calls use simulated API responses and synthetic PNG files. These checks
validate integration behavior, not paid-provider availability or generated image
quality. Native-speaker translation certification and UnoPim's marketplace re-review
are still external review steps. Do not advertise “all UnoPim versions”; use the
four tested release tags and the tested PHP versions in `COMPATIBILITY.md`.

## Account connection follow-up

The candidate now asks for chat during account connection and supports adding
it through reconnect. Chat remains off until the administrator accepts the
notice, the returned credential contains `chat:use`, and the server confirms
included chat with no image-credit charge. A server rejection does not silently
fall back to a different request. An image-only grant is reported explicitly.
History remains a separate choice. Consent validation covers JSON booleans as
well as form checkbox values.

Use `ACCEPTANCE_TEST.md` for the final real-account run against the exact ZIP.
The review bundle includes `SUBMISSION_REPLY.txt` as an unsent draft. No merge,
deployment, release-tag publication or marketplace submission has been performed.
