# DreamLayer for UnoPIM: reviewer guide

Start here to follow the implementation without the development conversation.
The package connects a DreamLayer account, adds product-image selection to the
existing chat, and returns durable previews. **Only Apply to product changes the
catalog image.** Chat & Images is the default; Images only retains a separately
configured UnoPIM chat provider.

## Review boundaries

| Area | Responsibility | Review entry point |
| --- | --- | --- |
| Generic UnoPIM changes | Provider/composer/renderer hooks, request identity, additive history | [Core compatibility](CORE_PATCH.md); `packages/Webkul/AiAgent/CHAT_EXTENSIONS.md` in the patched host |
| This Composer package | Connection settings, image selection, managed transport, durable local work, delivery, explicit Apply | [Service provider](../src/Providers/DreamLayerServiceProvider.php) |
| DreamLayer services | Account approval, scoped credentials, chat/image execution, credits, remote status | [API contract](API_CONTRACT_V1.md), [backend prerequisites](BACKEND_SETUP.md) |

The Webkul core patch contains only generic `packages/Webkul/AiAgent` changes.
Composer path repositories, local host setup, DreamLayer secrets and fixtures do
not belong in that PR. The package can be reviewed separately. The backend and
account console are separately deployed dependencies, not code installed into
UnoPIM. The owner accepted fresh installation, account connection and image generation
on 2026-10-13 with runtime 0.2.12. Version 0.2.13 adds Windows installation
instructions without changing that runtime. Windows execution remains unverified.

## Follow the user flow in the code

Shared [styles](../resources/views/styles.blade.php) use the layout's body stack,
outside Vue's mount point. UnoPIM's AJAX navigation carries over body styles but
does not import the next page's head; using only the head style stack would make
sidebar navigation unstyled until a refresh. The shared stylesheet is emitted
once even when settings and result renderers both include it.

### 1. Connect an account, then open chat

Read [SettingsController](../src/Http/Controllers/SettingsController.php),
[PendingConnection](../src/Services/PendingConnection.php),
[Settings](../src/Models/Settings.php), and the
[settings component](../resources/views/settings.blade.php).

Opening disconnected settings requests or reuses a live code. The browser shows
the public `user_code`; the exchange-capable `device_code` stays encrypted on the
server. Automatic expiry and manual regeneration issue a new attempt. An
authorization generation rejects responses from superseded attempts, so an old
approval cannot overwrite a newer key or connection.

The chip stays **Not Connected** while approval is pending. An approval-only
response changes it to **Waiting for connection**; credential exchange then
produces **Connected**. Returning to the tab honors the existing server poll
deadline instead of adding another full interval. Older APIs can still exchange
without the optional approval observation. A failed status read does not delete
a working credential.

The saved credential is encrypted with the host application key. Reconnecting
stores the new credential and attempts to revoke the previous installation;
cleanup failure is visible. Manually created API keys are not installation
credentials and are not automatically revoked. **Open chat** dispatches the
existing widget event. **Enable and open chat** requires configuration permission
and changes only the chat-interface flag.

### 2. Select the image and send one logical request

Read [picker.blade.php](../resources/views/picker.blade.php),
[CatalogImages](../src/Services/CatalogImages.php),
[ConversationImages](../src/Chat/ConversationImages.php), and
[ManagedChat](../src/Services/ManagedChat.php).

Names such as `@Sample Serum` make the prompt readable. They are not authorization:
the selected token binds an image to its owner and catalog scope. The server
resolves it and rejects stale/invalid selections. The picker, PHP selection logic
and core highlighter share mention fixtures for spaces, Unicode and boundaries.
Up to five selections require the backend's batch capability; typing a product
name alone does not attach its image.

ManagedChat supplies a request-scoped virtual provider, avoiding a duplicate key
in MagicAI's table. Its single model is still submitted when the selector is
hidden. The transport pins the endpoint, rejects redirects and leaves unrelated
HTTP traffic alone. A retried turn reuses its original prompt and rejects changed
inputs. Content-derived model-call identities remain stable when replayed tool
receipts skip a nested call; already-admitted older turns retain their legacy
identity scheme.

### 3. Admit work once and advance it in the background

Read [Workflows](../src/Services/Workflows.php),
[Batches](../src/Services/Batches.php),
[JournaledTool](../src/Chat/JournaledTool.php),
[RunWorkflow](../src/Jobs/RunWorkflow.php), and
[RecoverWorkflows](../src/Console/RecoverWorkflows.php).

The stable tuple `(admin, conversation_id, request_id)` recovers the existing
preview. A matching identity with changed inputs is a conflict. Admission locks
the settings row against credential replacement, copies the source into private
storage, pins the endpoint/credential version and dispatches after commit.
Source choice favors a new upload, an explicitly selected result or catalog
image, then the conversation's previous result before ambient page context.
A prior result carries its destination across page navigation unless the request
explicitly overrides it.

The worker first saves its execution path (`workflow_v1` or `execute_v1`). It
then uploads, admits with the same workflow UUID as idempotency key, polls the
saved upstream ID and delivers the result. It does not restart a completed paid
edit to repair local delivery. Completed intermediate outputs remain available
when a later step fails. Batch children wait for their parent admission.

Journaled native tool calls recheck current permissions even on replay. A claim
is saved before execution; a completed receipt can be reused. An unfinished claim
is intentionally uncertain and needs review, since an external effect cannot be
rolled back by a database transaction. This is not a blanket exactly-once promise.

The supervisor must restart workers after **any** exit, including a clean exit.
The scheduled recovery command restores missing queue deliveries and leaves
existing queued/reserved jobs alone. A stopped worker is an operational failure;
the card reports a delay while retaining the request. See [operations](OPERATIONS.md).

### 4. Display progress, recover errors, and Apply explicitly

Read [WorkflowController](../src/Http/Controllers/WorkflowController.php),
[ImageStore](../src/Services/ImageStore.php),
[workflow.blade.php](../resources/views/workflow.blade.php), and
[ProductImages](../src/Services/ProductImages.php).

Cards fetch owner-scoped local state; rendering and polling cannot submit an edit.
Detailed progress is expanded by default. Local image delivery validates PNG
bytes and publishes by atomic rename before marking the preview complete.

A structured insufficient-credit failure becomes `error_code: 402`, with the
message **You're out of image credits. Add credits to continue.** The **Add
credits** link comes from configured billing settings and opens the account
console in another tab. It does not retry a failed edit or initiate a purchase.
Do not classify failures from arbitrary upstream error text.

Apply locks both the owned workflow and the product, checks current edit
permission, destination scope, image version, image integrity and upload limit,
then uses UnoPIM's file storage/product repository. Repeated clicks return the
saved application. Unrelated catalog values are preserved. For galleries, only
recorded sibling-slot applications within the same batch can advance the initial
version; a same-slot or external change remains a conflict.

## Identities a reviewer will encounter

| Identity | Purpose |
| --- | --- |
| Conversation/client ID | Stable chat scope, separate from the host conversation database row ID |
| Request UUID | One logical send, retained across transport retry |
| Message ID | One displayed history entry; a retried send may have a new entry |
| Workflow/batch UUID | Durable local work and upstream idempotency identity |
| Upstream workflow/execution ID | Poll/recover already-admitted work |
| Authorization generation | Reject an old pending account connection |
| Credential version | Prevent in-flight work from silently switching accounts |
| Image target version | Detect a changed catalog destination before Apply |

## Tests and what they establish

Test source is in the review source archive/bundle; it is deliberately excluded
from the customer installation ZIP. Use an isolated host/database, with the core
patch, extension migrations and dependencies installed. [Test setup](../tests/README.md)
includes the separate real-migrator test; the transaction-based application suite
alone cannot prove fresh installation or upgrade ordering.

| Area | Existing regression files |
| --- | --- |
| Code reuse, expiry, approval/status, chat enablement | `OnboardingTest.php`, `ReconnectConcurrencyTest.php`, `ReviewRegressionTest.php` |
| Managed agent loop, tool receipts, permission replay, redirect protection | `ManagedChatTest.php` |
| Named selections, batches, gallery apply, content-based retry identity | `ExperienceTest.php`, `fixtures/mention-cases.json` |
| Admission, recovery, source selection, Apply conflicts, insufficient-credit errors | `WorkflowTest.php` |
| Atomic image delivery, native history fields and safe links | `DeliveryHistoryTest.php` |
| Endpoint configuration and credential destinations | `EndpointTest.php` |
| Actual picker/settings/card methods and core mention/model parity | `fixtures/ui-contract.test.cjs`, core `composer-contract.test.cjs` |

From the patched host, after configuring its dedicated test database:

```sh
APP_ENV=testing DB_DATABASE=unopim_review_test UNOPIM_HOST_ROOT="$PWD" \
  DREAMLAYER_FIXTURE_HOST=0 DREAMLAYER_ALLOW_LOCAL_HTTP=false \
  DREAMLAYER_CONNECTIONS_ENABLED=false \
  DREAMLAYER_API_URL=https://api.dreamlayer.io/v1 \
  DREAMLAYER_PLATFORM_URL=https://dreamlayer.io \
  vendor/bin/phpunit --bootstrap /path/to/extension/tests/bootstrap.php \
  /path/to/extension/tests
UNOPIM_HOST_ROOT="$PWD" node --test /path/to/extension/tests/fixtures/ui-contract.test.cjs
node --test packages/Webkul/AiAgent/tests/JavaScript/composer-contract.test.cjs
```

HTTP provider calls in application tests are faked. These tests do not establish
live provider quality, checkout fulfillment or production deployment readiness.
Use [FRESH_INSTALL_TEST.md](FRESH_INSTALL_TEST.md) for the owner's next installation
and browser acceptance. Record exact versions and failures rather than treating
historical test counts as evidence for a different candidate.
