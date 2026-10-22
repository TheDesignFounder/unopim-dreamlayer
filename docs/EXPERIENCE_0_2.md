# DreamLayer / UnoPim experience contract 0.2.0

This package keeps public API major version 1. These additive capabilities may be enabled independently. No optional removal balance, checkout change, removal table or $0.03 endpoint is required.

## Capability and authentication contract

`GET /v1/capabilities` advertises `image_batches: true`, `image_batch_versions: [1]`, `maximum_batch_images: 5`, `managed_chat: true`, `managed_chat_versions: [1]`, and `account_profile: true` when applicable. Batch also requires workflow support. Clients must explicitly recognize supported versions; omission or a different version does not authorize a new path. Existing execute/manual-key single-image operation remains usable. A failed discovery request is retried before admission. Once submitted, path, endpoint, request body and identity remain fixed.

`GET /v1/account` returns `{contract_version:1, account_label, key_mode}` for the authenticated key's owner. A legacy 404 leaves the manual connection usable. It is not a balance or authority to act as another user.

Installation device authorization accepts optional `included_chat: true`. Approval displays `chat:use` and explains conversation/catalog processing. The issued credential retains the approved scope snapshot. Existing installations keep their old image-only scopes until explicit reapproval. Manual API keys can use enabled chat; image-scoped installation credentials cannot. No client-provided domain establishes ownership. Google verification and once-only trial rules remain unchanged.

## Small batches

`POST /v1/image-batches` takes `input_asset_ids` (1–5 distinct owned IDs), one editing `step`, and optional `aspect_ratio`. Each input is edited separately; it is not multi-reference synthesis. Both POST and GET require a Bearer key and `DreamLayer-Version: 1`; POST also requires `Idempotency-Key`. GET `/v1/image-batches/{id}` is read-only and owner-scoped.

The response contains `contract_version:1`, `batch_id`, `status`, `count`, `children`, and a sanitized `error`. Children contain `position` and the existing public workflow status, steps and completed image references. Statuses are queued/running/completed/partial/failed. Children are created serially. Ordinary child failure permits other selected images; insufficient funds, expired access or revoked authorization stops later work. Completed children remain available and billed under existing image pricing. Every child has a separate execution identity and destination. Inputs are retained for at least 25 hours. Each workflow has the existing 24-hour bound. Polling never submits replacements.

UnoPim keeps a durable parent and separate workflow previews. Single-attribute Apply uses optimistic comparison under a product-row lock. Two previews in the same batch can safely apply to distinct slots of one gallery; an append-only application ledger proves that the intervening value change came from the sibling Apply. External changes and changes to the same slot still conflict. No automatic Apply is introduced.

## Included chat and embeddings

`POST /v1/chat/completions` accepts the documented OpenAI-compatible subset: model alias `dreamlayer-chat`, messages, function tools/tool calls/results, bounded PNG/JPEG/WebP data images, optional structured response instructions, and optional SSE transport. It supports maximum 4096 output tokens, 100 messages, 80 tools, five images and the configured input-unit bound. Remote image URLs are rejected. The server chooses the actual model and provider credentials. Unknown request fields/model aliases return 422.

`POST /v1/embeddings` accepts alias `dreamlayer-embeddings`, one string or up to 100 strings and optional dimensions; only float encoding is supported. Native catalog tools/ACL stay inside UnoPim. Managed similarity does not query another model's persisted embedding index.

Both POSTs require `DreamLayer-Version: 1`, `DreamLayer-Turn` and `Idempotency-Key`. Responses use OpenAI-compatible choices/usage or embedding data. The server stores a normalized request digest, cost reservation, price snapshot, result and available provider request identity. Identical replays return the same completed result/tool IDs. Changed input returns 409. Pending/uncertain submissions return 409 without another provider call. SSE is emitted from the persisted completed model response; it is not live provider token streaming. This boundary prevents repeating native writes after interrupted delivery.

`GET /v1/chat/limits` returns contract version 1, included=true, image_credits_charged=false and configured per-account limits (defaults 10 turns/minute, 100/day, two active turns, five provider calls/turn). The backend never reads/debits the image wallet for chat or embeddings. Operator reservations cover both against one cumulative USD cap. Limits return 429; disabled routes return 404; missing version returns 422; unsupported version returns 400. No fallback to another billable provider/path happens after submission.

## Operator configuration and recovery

Apply backend migrations through `0078_included_chat_audit`; preserve 0074 video/connections and 0075 workflow history via merge 0076. Do not edit an applied migration or stamp away a missing branch. New extension migrations 000006–000010 add batch/Apply journals, tool receipts and chat turn pinning. The generic core has no additional migration beyond its existing four.

Enable `ABLOH_EXTERNAL_CHAT_ENABLED=1` only with `ABLOH_CHAT_MODEL`, `ABLOH_CHAT_PRICE_VERIFIED`, positive `ABLOH_CHAT_INPUT_USD_PER_MILLION`, `ABLOH_CHAT_OUTPUT_USD_PER_MILLION`, positive `ABLOH_CHAT_TOTAL_CAP_USD`, and the Anthropic credential/base origin. Set corresponding `ABLOH_EMBEDDING_MODEL`, `ABLOH_EMBEDDING_PRICE_VERIFIED`, `ABLOH_EMBEDDING_INPUT_USD_PER_MILLION` and the OpenAI provider credential for similarity. Rates are operator-owned and must be reverified when the actual model changes. The cap is cumulative over the retained accounting table, not a daily auto-reset. Defaults fail closed. Model configuration is not accepted from customers. Do not remove journals to reset a spending cap.

Enable `ABLOH_EXTERNAL_BATCHES_ENABLED=1` with image/workflow flags. Supervise the existing gateway, engine API, render worker and `tools/ops/run_image_workflows.py`. The worker uses a bounded DB pool of 2 + 1 for nested transitions. Supervise UnoPim's database queue and scheduled `dreamlayer:recover`. Keep web and workers on the same private/public storage and APP_KEY; use a shared locking cache for multiple replicas. Verify buckets, read/write permissions and authorized asset delivery before admitting real work. Preserve output retention and cached images while results remain in use.

`python tools/ops/report_included_chat.py` is a read-only cost/recovery report using the target DATABASE_URL. If a worker/network interruption leaves a reserved/uncertain chat call, do not rerun it under a new identity or release its reservation merely because time passed. Compare provider usage/evidence; retain the conservative reservation until an operator can establish the outcome. A response lost before durable storage may not be retrievable from the provider. Tell the user that the request needs review rather than claiming it was free. Native tool receipts left pending likewise require inspection of the local action; they never replay automatically. Keep identity tombstones and price/cost totals for as long as retries are supported. No automated chat journal deletion is enabled in this candidate; define a privacy/retention policy before a hosted pilot.

Separate product/request data by existing account/role ownership. Included chat sends the conversation, allowed tool schemas and selected catalog information to the configured text provider. Never place secrets, provider URLs or private reasoning in result cards. Preserve the current explicit preview/download/Apply flow; no fidelity scoring or comparison interface is included.

## Verification boundaries

Read the release evidence for exact source versions and measured results. Fixture tests cover error/retry/ACL paths without provider charges. The new isolated live acceptance uses two disposable catalog images and a separate local API account; its seeded balance is a test fixture, not proof of Google trial eligibility. Previous Google/real-provider evidence is preserved. The browser-assisted session must verify new chat-scope consent, real account switching and revoke/reconnect against the upgraded local origin. No production deployment, publication, Stripe purchase or production billing/OAuth change is part of this work.

## Chat identity upgrade

New turns pin identity scheme 2: each provider-call identity is derived from the normalized request body and turn ID. Replaying a completed native tool may skip its nested model calls without shifting later identities. Migration 000010 preserves scheme 1 for existing prototype turns; it never silently switches an already submitted turn to a new key. A legacy nested-call replay may require operator inspection after a conflict. Keep its saved results and never create a replacement paid request merely to bypass that conflict.


0.2.1 adds inline @ image references with paged server search, thumbnail navigation, larger preview and explicit text insertion. Multiple references mean separate edits with distinct Apply destinations. Unknown or duplicated aliases are rejected before provider work; aliases never replace owner-scoped encrypted selections. The empty-composer pricing paragraph was removed; the selected-image quote remains.


0.2.2 UI: product-name references replace visible Asset-N aliases; Select Image inserts @ and opens the inline dropdown. Selected chips show names only. The composer preview/credit text is removed. Internal identities and signed destination tokens remain separate from display names.
