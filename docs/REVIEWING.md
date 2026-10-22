# Reviewing the 0.3.0 candidate

The standalone image editor is the primary integration. It uses UnoPim product APIs, permissions, translations and admin components. Its workflow context does not depend on the AI Agent package. The optional native adapter is registered only when the host provides its extension interfaces.

No core files, dependency versions, default AI provider, embedding bindings, global HTTP middleware or global history setting are changed. Legacy managed-provider implementation and patch files have been removed. Existing extension-owned history tables are retained during upgrade to avoid deleting customer records.

Image requests are owner-scoped, validate encrypted catalog selections, pin their source and destination, and use durable upstream idempotency identifiers. Browser retries have a receipt containing a request hash, backend/credential identity and resulting workflow IDs. Optional chat assistance can refine only the instruction, not the product destination or Apply decision. Conversation messages are saved only with the history setting enabled.

Network processing uses bounded jobs and short leases rather than holding database row locks during polling. Apply separately checks permission, ownership and destination version, emits the normal product-update events and records an idempotent application receipt. Queue dispatch is configurable.

Start with [MARKETPLACE_REVIEW.md](MARKETPLACE_REVIEW.md), [COMPATIBILITY.md](COMPATIBILITY.md), the current README, and `tests/PortableEditorTest.php`. `tests/compatibility.php` exercises real released hosts with synthetic images and mocked DreamLayer responses. Existing workflow, delivery, account-race and batch tests remain regression coverage. Native-hook tests require a host exposing those hooks; they cannot run on released tags that omit them.

Distribution archives exclude tests, tools, development notes, CI files and legacy patch material. No test credentials or synthetic browser providers belong in an installed package.
