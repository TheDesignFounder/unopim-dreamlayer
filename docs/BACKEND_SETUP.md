# UnoPIM extension backend review

**0.2.0:** apply through `0078_included_chat_audit` for the new small-batch and included-chat services. See [the current experience contract](EXPERIENCE_0_2.md) for flags, approved credential scopes, model pricing/caps and recovery. The image-workflow details below remain valid; historical acceptance statements identify earlier evidence.

Local implementation; not deployed. The 0.1.3 acceptance pass exercised real image providers through the browser. See its separate acceptance report for measured results and the Google-origin blocker. No Stripe purchase, provider top-up, deployment or production billing configuration change was performed.

## Required capabilities

`ABLOH_EXTERNAL_IMAGE_API_ENABLED=1` retains the existing API access gate. After applying migrations through `0076_unopim_video_merge` on the reconciled branch, enable `ABLOH_EXTERNAL_WORKFLOWS_ENABLED=1` to advertise `image_workflows: true` in `/v1/capabilities`. Manual API keys work without enabling installation connections. Standard image execution pricing is unchanged, including `background_remove`.

Run the deployable worker with the repository environment:

```sh
python tools/ops/run_image_workflows.py
```

Use a supervised worker with CPU allocated while idle. Do not depend on request-background tasks or an open browser stream. PostgreSQL is the durable queue; a worker commits one transition at a time under a row lock. Multiple workers use `SKIP LOCKED`. The existing `tools/ops/sweep_managed_executions.py` also processes due image workflows for recovery. Worker/database restarts need no browser retry. Status polling does not dispatch work.

## Public image workflow contract

`POST /v1/image-workflows` requires Bearer API key, `DreamLayer-Version: 1` and `Idempotency-Key`. It accepts a closed JSON object:

```json
{
  "input_asset_id": "owned-upload-uuid",
  "aspect_ratio": "1:1",
  "steps": [
    {"operation": "image_to_image", "instruction": "Change the background to pink"},
    {"operation": "background_remove"}
  ]
}
```

One `text_to_image` step requires no input asset. Editing accepts one or two sequential `image_to_image`/`background_remove` steps. Removal has no instruction field; it uses the current existing operation/pricing, not the tentative dedicated endpoint. Each existing managed execution bills independently at its existing rate. If step two fails, the successfully delivered first step remains charged and available; this is not atomic bundle billing.

The 202 acknowledgement and `GET /v1/image-workflows/{workflow_id}` return a workflow ID, overall status, ordered `position`, `description`, `status`, and nullable completed `image` references for each step. `image` on the root is the final image only when completed. Image references use existing authenticated asset delivery endpoints. States are actual execution states, never private reasoning or provider routing. Descriptions contain bounded executable instructions. The route differs from Studio's pre-existing `/v1/workflows/{id}`.

GET is owner-scoped, read-only and `Cache-Control: no-store`. Poll about every two seconds while the UI is visible. Retry POST with the original key and identical body after uncertain network failures. Changed bodies with the same key return 409. Execution keys have a server-reserved namespace. A committed source reference and upstream execution ID survive retries. Completed output is copied, normalized and uploaded for the next edit. Failed steps mark later steps skipped. Workflow lifetime is bounded to 24 hours; the owned initial input is retained at least 25 hours. Transient service failures retry using the same identities. Revoked keys stop future workflow transitions; revocation does not cancel an image job already admitted upstream.

No local Python companion or hidden planning-model dependency is installed. The chat model supplies executable structured steps; DreamLayer validates, stores and executes them.

## Installation connections

Enable `ABLOH_EXTERNAL_CONNECTIONS_ENABLED=1` independently after migration `0074_external_connections`. The existing platform/login/signup/access restrictions still apply. Device authorization is ten minutes, with a five-second minimum poll interval and five-second slowdown increments. Only hashed device/code secrets are persisted. The approval screen is `/console/connect`; it requires explicit review and approval of matching code and installation details marked unverified. It uses the existing login/signup flow. A new verified Google sign-in records trial eligibility; old accounts must sign in again after enabling this capability. Development login is not a verified identity.

One identifiable installation key is issued server-to-server. It is restricted to image execution/workflows, owned assets, balance and self-revocation. The platform screen lists and revokes owned connections. Lost token responses require reconnecting; a consumed secret cannot replay the credential. This may leave a key to revoke in the installation list. No browser redirect URL or analytics contains a credential.

Three promotional **external API** credits are granted once on first eligible approval, under the existing wallet lock plus durable unique grant record. Existing beta recipients receive no second allowance. The Studio wallet and purchased API credit pricing remain unchanged. Billing links point to the existing console billing page. No $0.03 pricing/removal-unit UI is part of this branch.

## Review and release dependencies

The extension needs the separate generic UnoPIM patch for inline rendering. Its basic manual-key path works with the existing API before these backend changes are deployed. Workflow capability enables detailed steps and sequential edits. Connection support can remain disabled or unavailable. See the versioned contract and compatibility matrix in `unopim-api-contract-v1.md`. No code in A/B references the optional removal tables.

Real Google signup/login was demonstrated in the isolated local stack with the user-approved development client. Release verification in the selected pilot environment remains required. Real-provider browser evidence now supplements the isolated PostgreSQL/fixture tests. Local purchased-bucket funding is synthetic, and recorded adapter cost estimates are not provider invoice verification. These tests do not establish profitability. Webkul must review the core patch. No production deployment is authorized by this session.

Worker setup: orchestration holds a workflow row lock while the managed boundary opens another database session. Budget the bounded service pool (2 + 1) for `run_image_workflows.py` and workflow-enabled sweeps. The one-connection job pool cannot run those nested calls. Configure `ABLOH_EXTERNAL_IMAGE_API_BASE_URL` and `ABLOH_PLATFORM_URL` to match the extension operator URLs. Google must separately authorize the platform origin. Local HTTP origins are accepted only in local mode.

Connection attempts use encrypted, ten-minute entries in Laravel’s configured cache with an atomic lock, scoped to the administrator and authenticated session. Use a lock-capable shared cache (for example Redis) for multiple web replicas; local file cache is suitable only when those processes share the same storage. Clearing the cache requires starting connection again, but never removes the saved encrypted credential. Upgrade discards old session-backed pending attempts; start Connect again.
