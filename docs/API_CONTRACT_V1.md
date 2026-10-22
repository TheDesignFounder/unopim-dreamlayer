# DreamLayer image integration contract v1

Contract date: 2026-10-08. Local review implementation. The existing public API remains compatible; deployment is not part of this session.

## Negotiation and independent releases

All execution/workflow requests send `DreamLayer-Version: 1`. The existing execute contract remains unchanged. A new additive `/v1/capabilities` response advertises `image_workflows: true` and `image_workflow_versions: [1]` only when enabled. Older servers omit these fields. Clients must ignore additive unknown response fields.

The extension selects a path **before admission**, and commits that choice locally before submitting upstream work:

| Available backend | Authentication | Selected path and UI |
| --- | --- | --- |
| Existing API, workflow field absent/false | Manual API key | Existing `/v1/execute`; one generation or edit; basic processing, preview and Apply |
| Workflow v1 advertised | Manual API key | `/v1/image-workflows`; detailed ordered API step progress |
| Workflow v1 advertised | Installation key | Same workflow execution/state; independent onboarding |
| Only unknown workflow versions advertised | Either usable key | Existing execute v1, selected before admission; no detailed steps |
| Connection service disabled/unavailable | Manual API key | Configuration, execution, balance and existing billing link remain usable |
| Optional removal schema entirely absent | Either | All above supported; standard background_remove uses existing pricing |

Extension 0.2.0 requires an explicit `image_workflow_versions: [1]` advertisement for new admissions; a missing list selects the existing execute path. Already admitted workflows retain their pinned path. Earlier extension clients may continue accepting the initial boolean advertisement. Two sequential edits require managed workflow v1; the basic path rejects such a plan before any image submission. It does not pretend to execute two steps in one operation or build a second local orchestration service.

A missing/false capability is an explicit legacy selection. A capability request timeout is **not** evidence of lack of support: retry discovery, without submitting work. Once the path is recorded, capability changes, submission/status timeouts, 404s and lost acknowledgements never switch it. Submission retries preserve the same source, body, path and idempotency key. Once acknowledged, poll the existing upstream ID. The authoritative state is the selected backend execution or workflow; the extension caches its report and final private image.

## Workflow wire contract

`POST /v1/image-workflows` accepts `steps`, optional `input_asset_id`, and `aspect_ratio`; see the backend integration guide for examples. Unknown request fields are rejected. One generation or up to two ordered edits are supported. API keys own their inputs; results are owner-scoped. Idempotency-Key is required and identical replays share one workflow; changed input returns 409.

Responses contain `contract_version: 1`, `workflow_id`, `status`, ordered `steps` with `position`, `description`, `status`, nullable `image`, and root `image`/`error`. Only completed steps expose image references. Overall status is queued/running/completed/failed; step status is pending/running/completed/failed/skipped. Descriptions are bounded executable instructions, not model reasoning. `GET /v1/image-workflows/{id}` reads this state without starting work. Images use existing authenticated delivery URLs and retain their current lifetime/authorization rules.

OpenAPI includes typed WorkflowRequest/WorkflowStatus schemas. `DreamLayer-Version` values other than `1` return HTTP 400 with unsupported-version semantics; a missing required version header returns HTTP 422. This applies to existing execute and new workflow routes. Disabled workflow routes return 404; they are not advertised. Clients cannot silently change paths after that rejection. A workflow response declaring an unsupported `contract_version` is refused without resubmission. New incompatible major versions require an explicit version and capability; v1 meanings must remain stable.

There are no new checkout, removal-balance or optional-pricing dependencies in this contract. No simultaneous backend/extension release is required for basic image use. Account connection can be enabled separately; the generic UnoPim hook remains an explicit installation dependency for inline rendering.

## Acceptance evidence

The local extension suite covers both basic generation and background editing through execute v1, managed generation/two-step progress through workflow v1, manual setup with onboarding disabled and returning 503, and lost acknowledgements on both immutable paths. Backend integration tests create a workflow using the actual issued installation key, validate version errors and ownership, and explicitly assert the optional removal ledger is absent. The dedicated removal balance/checkout surface is absent from the main branch.


## Partial completion and local recovery (extension 0.1.1)

The workflow contract remains version 1. Each completed `steps[].image` is useful even when a later step fails and root `image` is null. Clients must retain access to these references. UnoPim caches completed PNGs privately and provides download-only partial previews, with per-step billing explanation; final Apply remains limited to a completed workflow. Successful work is not implicitly refunded or repeated after another step fails. Test mode consumes no credits.

UnoPim's owner-scoped local `GET /admin/dreamlayer/workflows` lists paginated results independently of conversation history; optional conversation/request filters recover lost descriptors. This endpoint does not execute work. Chat tool retries recover the original admitted identity rather than executing model-replanned input. DreamLayer's public input-conflict/idempotency behavior and contract version are unchanged.


## Connection presentation additions (0.1.4)

`POST /v1/external-api/connections/decision` keeps its existing `status` and adds `trial_granted: boolean`. The value records whether this approval transaction inserted the once-only three-credit promotional grant; denial returns false. It is not a balance and does not grant again on token exchange. Older clients ignore the added field. Updated UI accepts its absence from older servers and directs users to billing without guessing whether credits were granted. No migration, removal balance or pricing change is required.

The device token request optionally accepts `approval_only: true`. It uses the same private device code, grant type, expiration and polling checks, but an approved request returns `{ "status": "approved" }` without issuing a key or consuming the grant. UnoPim can then display **Waiting for connection** and immediately exchange the token with the flag omitted. Before approval the chip stays **Not Connected**. Older servers that reject the optional field with 422 are retried without it; these finish in one exchange. Device secrets and credentials never reach the browser.

Installation-list polling is read-only. An approved installation appears only after UnoPim exchanges the device code. The platform refreshes the list while visible and discards late responses from a previous account. UnoPim checks its saved credential via the existing balance API; 401/403 mean access is no longer authorized, while temporary errors retain the credential with an unavailable status. Onboarding may remain disabled and manual keys still work.

Image workflow status adds nullable `error_code` (an HTTP status code). A managed execution rejected for `insufficient_credits` becomes workflow error `402`, preserving the reason through the asynchronous worker. UnoPim stores that code and offers **Add credits**, opening its configured `/console/billing` page in a new tab. Direct execution errors with `reason: insufficient_credits` and HTTP 402 admission errors show the same message. Other failures keep their existing behavior; status reads and the billing link never resubmit an image or change credits.

## Reconciled candidate (0.1.5)

The v1 wire contract is unchanged. Installation credentials remain limited to image operations even when the backend also supports video on shared execution routes. The backend joins the preserved video and UnoPim migration histories with additive revision `0076_unopim_video_merge`; this is a deployment concern, not a new extension dependency. An existing image API with manual keys continues to work without workflow/onboarding or any optional removal schema. Follow-up Apply destinations remain attached to the preceding result across product-page navigation unless the user explicitly selects a SKU or attribute.

## Additions in 0.2.0

The API remains version 1. The batch, included-chat/embedding, account profile and explicit chat-consent contracts are documented in [EXPERIENCE_0_2.md](EXPERIENCE_0_2.md). Missing new capabilities preserve the old image/manual-key path. Existing installation keys retain their image-only scope.
