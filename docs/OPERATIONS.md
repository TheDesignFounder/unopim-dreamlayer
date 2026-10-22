# Operating DreamLayer for UnoPim

Use an existing asynchronous queue connection through `DREAMLAYER_QUEUE_CONNECTION` and a dedicated queue name through `DREAMLAYER_QUEUE`. Web and worker processes need the same configuration, APP_KEY and private storage.

Jobs retry for at most `DREAMLAYER_WORKFLOW_TIMEOUT` seconds (default 1800), with a maximum of 500 attempts and a 110-second worker timeout. Use a connection `retry_after` greater than the worker timeout. Polling logs are debug-level and omit prompts, image URLs and credentials. Recoverable interruptions are warnings.

A worker takes a short database claim before making network requests. Claim lifetime is 180 seconds, longer than the enforced worker timeout. Run workers under supervision so timeout termination is enforced. API admission always reuses the original workflow UUID as its idempotency key.

`php artisan dreamlayer:recover` adds missing delivery jobs. Database queues are checked for existing deliveries; other asynchronous queues may receive duplicate deliveries, which are serialized by database claims. `--resume-expired` grants an explicitly requested new polling period using the same upstream request IDs. `--verify-deliveries` checks terminal preview files and repairs missing bytes without admitting a new generation.

No worker writes a product image. Apply takes a product lock, checks the destination version and user permission, writes the image, and emits `catalog.product.update.before` and `catalog.product.update.after`. Repeated Apply is idempotent. The host's listeners handle indexing, webhooks and related work; its normal workers must also be running.

The catalog picker uses indexed SKU-prefix search and batches image-attribute lookups. Settings are scoped to a request/job and invalidated after saves. The application receipt index covers batch, product and destination.

Optional conversation saving stores encrypted messages in `dreamlayer_editor_history`. Turning saving off stops future inserts; existing history and backups remain under the host administrator's retention policy. Workflow instructions and product destinations are operational records even when conversation saving is off. Preserve active jobs and their source files until completion. The extension does not change UnoPim's global history setting.

Account connection stores pending codes in an encrypted cache entry. Use a
persistent cache driver (`file`, `database` or `redis`) with atomic-lock support;
`array` is for single-process tests and loses the code between web requests.
Multiple web replicas must use the same shared cache. After changing connection,
cache or endpoint configuration, clear configuration caches and restart workers.

To add included chat permission to an image-only account connection, use the
connection section in Settings, select chat assistance, acknowledge the data
notice and approve a new code. The extension validates both the returned
`chat:use` scope and the non-billable chat contract before enabling assistance.
If the server rejects chat authorization, the existing installation remains
connected. If it returns only image access, the new connection can edit images
and shows a notice that chat was not enabled. History is configured separately.
