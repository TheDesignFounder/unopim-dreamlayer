# Operating and verifying the integration

Start with the [installation guide](../README.md). This document explains workflow execution, storage, recovery and isolated testing for developers and operators.

## Image workflow behavior

Before each chat turn, the extension supplies owner-scoped saved image status and the latest completed result identity to the text model. This prevents an asynchronous result from being mistaken for an unsubmitted request when tool-call history is absent. Follow-up edits inherit the preview’s product destination with a fresh target-value snapshot, even after navigating to a different product page. An explicit SKU or attribute can select a different destination.

The chat tool accepts one generation instruction or at most two edit operations. Sources resolve in order: new attachment; explicitly selected result/product image; latest completed result in the same owned conversation; current saved product image. Selected inputs are copied into private extension storage before queuing.

Default to one edit describing the final result, including a background color change.
Two steps are for explicitly requested sequential operations. Chat acknowledges queued
work briefly; the live preview card supplies progress and the explicit Apply action.

The Laravel job first commits its capability-selected path, uploads the source and submits **one** execution or managed workflow with a stable idempotency key. The basic path uses `/v1/execute` and shows an honest processing state without a step list. A two-step plan requires managed workflow support and is refused before paid submission on the basic path. The path never switches after selection, including after timeouts. The API endpoint is pinned at admission too; changing the configured endpoint parks unfinished work until its original endpoint is restored. Existing records without an endpoint retain the original production endpoint. On the managed path DreamLayer executes the steps, chains actual outputs and reports ordered descriptions/statuses. The extension mirrors that report and privately stores the final PNG and completed intermediate outputs. If a later step fails, completed images remain privately downloadable; the card explains per-step billing. Partial outputs are download-only and cannot be applied through the final-result Apply action. It never orchestrates separate paid steps locally. Browser polling reads authorized extension state every two seconds while visible; both backend workers continue with the browser closed. No local Python companion or hidden image planner is required. Managed chat uses the backend’s configured model; image-only mode can use UnoPIM’s configured chat model.

The card provides compact progress expanded by default, preview, URL and download controls and UnoPIM's existing reactions. Completed previews do not save products automatically. Apply shows the product, attribute and channel/locale; it rechecks permission and ownership, locks the product, compares the preview-time image value and updates only the intended value through the product repository. Unrelated product edits are retained. Repeated Apply is idempotent. A changed target returns 409 and requires a new preview. The original saved image remains until Apply succeeds.

`background_remove` uses the existing DreamLayer API operation and pricing when the configured backend supports it.

## Recovery and history

Supervise the UnoPIM worker and scheduler with restart on **any exit**. A database
connection loss can make Laravel exit with status 0: use Docker Compose
`restart: unless-stopped` or Supervisor `autorestart=true`, not `on-failure`.
The scheduler cannot restart a stopped worker. It now checks the database queue
before rebuilding a missing delivery, including jobs whose reservations expired.
Parent batch jobs admit their children before child delivery jobs are recovered.

Cards show **Image queued** before execution and **Image work delayed** if saved
progress has not changed for two minutes. Delayed describes stale progress, not a
confirmed provider failure; polling continues and does not resubmit. Terminal
results are never labeled delayed. Check and restart the worker to resume the same
request after an outage. Existing idempotency keys and upstream references remain
authoritative.

**My image results** in the admin menu provides owner-scoped, paginated recovery independently of chat retention. Give relevant roles its menu permission and product edit permission. The settings page and result cards also link there. `/admin/dreamlayer/workflows` returns authorized JSON when requested with `Accept: application/json`; optional `conversation_id` and `request_id` filters recover an admitted request. These reads never submit work. A repeated tool call with the same owned request identity returns its original card even if the text model replans the wording; it does not execute the new plan.

The generic core patch stores immutable message IDs additively. A delayed/short snapshot cannot delete prior messages. The browser limits local storage to 50 messages but saves all messages in batches; reopening and reloading merge durable history. Native metadata, tool calls, token usage, result data, product/download links, action and confirmation state are retained. Native display data is bounded and inert; product links must be same-origin and downloads must use safe HTTP(S) or local paths. Reopening merges locally retained fields by message identity, and a handled confirmation cannot be reset by an older save. Once resaved, native fields restore in another browser without local storage. Fields already lost from both database and browser before upgrading cannot be reconstructed. Changing an existing message requires a new identity; whole-conversation deletion is explicit.

Image delivery uses a same-directory private staging file, checked writes, flush/fsync and atomic rename. A full PNG structure/CRC check and decoder validation run before publication, cache reuse, download and Apply; transparent bytes are preserved. Interrupted deliveries retry the same upstream identity, never another paid execution. Storage must support local atomic rename and be shared between web and workers.

To check existing cached results, run `php artisan dreamlayer:recover --verify-deliveries` with a worker running to inspect terminal records for invalid cached files and redownload their existing upstream outputs. The usual scheduled command still handles unfinished jobs. Invalid files are not offered as downloads and return 503 if directly requested. Recovery requires the original upstream reference, retained upstream asset and matching authorized credential; it cannot recreate expired/deleted outputs. Polling only reads state. A hard-killed writer may leave an unreferenced `.image-*` staging file; remove such leftovers only during maintenance with image workers stopped. They are private and are never served as results.

Product-fidelity checking is excluded: no scores, badges, approval gates or dedicated comparison interface. Prompts request preservation of the product without claiming verification or guarantees.

## Verification

Start with the [reviewer guide](REVIEWING.md) for the code path and test map, and
use [fresh-install acceptance](FRESH_INSTALL_TEST.md) for the next manual run.

See the separate review bundle for measured verification and external limitations, and [API contract](API_CONTRACT_V1.md) for capability/version negotiation and the tested compatibility matrix. Tests and fixtures are retained in the source checkout and review evidence; the customer ZIP excludes them. From the source checkout, package tests run in an isolated UnoPIM host using that host's test dependencies:

```sh
# From the isolated host, with a dedicated test database configured:
UNOPIM_HOST_ROOT="$PWD" DREAMLAYER_FIXTURE_HOST=0 \
DREAMLAYER_API_URL=https://api.dreamlayer.io/v1 \
DREAMLAYER_PLATFORM_URL=https://dreamlayer.io \
DREAMLAYER_ALLOW_LOCAL_HTTP=false DREAMLAYER_CONNECTIONS_ENABLED=false \
php vendor/bin/phpunit -c /path/to/package/tests/phpunit.xml
```

Use all four DREAMLAYER overrides above even when the host normally points at a local backend. The suite mocks these canonical endpoints; it must not inherit live credentials or submit requests to a real image service.

The source checkout's XML defaults to the session's isolated PostgreSQL fixture container. Override DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD with your own **test** database. Never point these tests at a demo or production database. `tests/fixtures/TestHostProvider.php` and `setup-host.php` are test-only; they are never registered by package autodiscovery. They use deterministic local responses and must not be enabled on a real host.

Build the fixture-free ZIP from the reviewed Git commit with `python tools/build_release.py` in the source checkout. It uses a tracked-file allowlist and verifies every archived byte against HEAD.

The extension source is MIT licensed. Publication of the source does not deploy
DreamLayer services, publish to a marketplace, or submit the generic core change
to Webkul. The owner accepted the fresh install, connection and real image flow
on runtime 0.2.12. Version 0.2.13 preserves that runtime and adds installation and
review documentation. Use [NEW_USER_TEST.md](../NEW_USER_TEST.md) for release
verification, including reconnect/revoke. Windows execution is unverified.


Connection attempts use encrypted, ten-minute entries in Laravel’s configured cache with an atomic lock, scoped to the administrator and authenticated session. Use a lock-capable shared cache (for example Redis) for multiple web replicas; local file cache is suitable only when those processes share the same storage. Clearing the cache requires starting connection again, but never removes the saved encrypted credential. After clearing pending connection state, start Connect again.

## Queue connection and delayed status (0.2.14)

The extension registers its own `dreamlayer` database queue. UnoPIM's default
queue can still use Redis or another driver. Keep `queue.connections.dreamlayer.driver`
set to `database`: recovery deduplicates against that connection's durable job
rows. If overridden, `dreamlayer:recover` exits with an actionable error before
querying job tables or dispatching recovery jobs. Restore that configuration and
restart the extension worker before running recovery again.

A valid, successful status poll refreshes the workflow heartbeat even when the
reported step has not changed. Edits that take longer than two minutes therefore
remain running. If polls stop, the existing two-minute delayed indication still
appears. Status reads from the browser do not refresh the heartbeat.
