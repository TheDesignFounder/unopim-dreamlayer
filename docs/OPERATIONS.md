# Operating DreamLayer for UnoPim

Use an existing asynchronous queue connection through `DREAMLAYER_QUEUE_CONNECTION` and a dedicated queue name through `DREAMLAYER_QUEUE`. Web and worker processes need the same configuration, APP_KEY and private storage.

Jobs retry for at most `DREAMLAYER_WORKFLOW_TIMEOUT` seconds (default 1800), with a maximum of 500 attempts and a 110-second worker timeout. Use a connection `retry_after` greater than the worker timeout. Polling logs are debug-level and omit prompts, image URLs and credentials. Recoverable interruptions are warnings.

A worker takes a short database claim before making network requests. Claim lifetime is 180 seconds, longer than the enforced worker timeout. Run workers under supervision so timeout termination is enforced. API admission always reuses the original workflow UUID as its idempotency key.

`php artisan dreamlayer:recover` adds missing delivery jobs. Database queues are checked for existing deliveries; other asynchronous queues may receive duplicate deliveries, which are serialized by database claims. `--resume-expired` grants an explicitly requested new polling period using the same upstream request IDs. `--verify-deliveries` checks terminal preview files and repairs missing bytes without admitting a new generation.

No worker writes a product image. Apply takes a product lock, checks the destination version and user permission, writes the image, and emits `catalog.product.update.before` and `catalog.product.update.after`. Repeated Apply is idempotent. The host's listeners handle indexing, webhooks and related work; its normal workers must also be running.

The catalog picker uses indexed SKU-prefix search and batches image-attribute lookups. Settings are scoped to a request/job and invalidated after saves. The application receipt index covers batch, product and destination.

Optional conversation saving stores encrypted messages in `dreamlayer_editor_history`. Turning saving off stops future inserts; existing history and backups remain under the host administrator's retention policy. Workflow instructions and product destinations are operational records even when conversation saving is off. Preserve active jobs and their source files until completion. The extension does not change UnoPim's global history setting.
