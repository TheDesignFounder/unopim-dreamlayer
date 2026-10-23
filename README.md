# DreamLayer for UnoPim

Create and refine product images inside UnoPim. Select images, describe a change, review the previews, then choose what to save. Follow-up edits use the previous result. DreamLayer handles image model selection; UnoPim keeps the product records and permissions.

**Free extension, requires paid DreamLayer credits.** **Release 0.5.5.** See the [release notes](docs/RELEASE_NOTES_0_5_5.md) for validation and known limitations.

0.5.5 fixes **Try again** after a multi-product request. It restores the user's
message without internal image-routing text, keeps the selected original source,
and preserves per-image instructions when the retry is submitted. Older saved
conversations use their retained original message; older unsaved jobs fall back
to the cleaned processing instruction when the original wording is unavailable.

0.5.4 compacts chat result cards: completed images no longer repeat a status,
Copy, feedback, or channel/locale rows. The catalog preview details and Apply
reminder share one paragraph without the size-limit clause. Apply now follows
the editor's enabled state even when settings change while History is open.
Processing, failure, product destinations, reviewed-byte checks, original
image downloads, Refine, and Try again remain available as appropriate.

0.5.3 fixes chat-assisted refinement after a referenced batch. Rewrites preserve
only the references in the current message; if the assistant adds or drops a
reference, the editor uses the user's original instruction. Catalog previews
are fitted in the delivery worker before a new result is marked complete, with
faster PNG compression. Existing reviewed previews and original downloads remain
available, and Apply still checks the exact reviewed bytes.

0.5.2 added inline image references to the standalone editor. Type **@** and choose
a product image to insert a highlighted `@Asset-1` reference at the cursor. The
thumbnail labels identify its product. Mention one attached image to edit only
that image, or mention multiple images to create separate previews. Without
inline references, the request uses all attached images.

The saved multi-product history fix from 0.5.1 is retained: each request restores
its result cards in selection order, including older saved conversations. No data migration or image regeneration is required.

## Compatibility

The image editor works independently of UnoPim's AI Agent and native chat hooks. The underlying image workflows were tested on released UnoPim **0.1.0, 1.0.1, 2.1.6 and 3.1.3** for 0.3.0. The 0.5.0 chat workspace is browser-tested on **3.1.3**; older-version UI acceptance remains pending. See the [compatibility matrix](docs/COMPATIBILITY.md) for the exact tests and limits. This is not a claim that every intermediate or future release has been tested.

No core patch or host dependency upgrade is needed. Composer retains the host's Laravel and AI SDK versions. The optional native chat bridge appears only when the required extension registries are present. It is off by default; the standalone image editor remains available without it.

Requirements:

- PHP and Laravel versions required by your UnoPim release; PHP 8.2+ and GD for this extension.
- Writable private local storage shared between the web application and workers, with atomic rename support.
- A configured asynchronous Laravel queue connection and a supervised worker.
- A DreamLayer account or API key with image credits.

Old UnoPim releases can contain outdated dependencies. Compatibility testing does not replace the host's security updates.

## Install

**Setting up DreamLayer? Start here.** Whatever your setup process—installing it yourself, working with an internal team, agency or implementation partner, using a coding assistant, or automating deployment—follow the [recommended setup guide](docs/INSTALLER_GUIDE.md). It explains the benefits of chat assistance, saved editor conversations and native UnoPim chat integration, and helps you enable the features that match your business or client's requirements.

Using AI to help with installation? The same guide applies to:

- **Coding assistants:** OpenAI Codex, Claude Code, Cursor, GitHub Copilot, Gemini CLI, Cline, OpenHands and other coding tools.
- **Agents powered by open-weight models:** for example, [Qwen3-Coder](https://github.com/QwenLM/Qwen3-Coder), [DeepSeek-V3.2](https://huggingface.co/deepseek-ai/DeepSeek-V3.2) or [Mistral Devstral 2](https://mistral.ai/news/devstral-2-vibe-cli/), running through a coding tool with access to your installation.

Download the distribution ZIP from the [0.5.5 release](https://github.com/TheDesignFounder/unopim-dreamlayer/releases/tag/0.5.5). Extract it to `packages/DreamLayer/unopim-dreamlayer` in your UnoPim application. Do not copy test fixtures into the host. From the application directory:

```sh
composer config repositories.dreamlayer '{"type":"path","url":"packages/DreamLayer/unopim-dreamlayer","options":{"symlink":false}}'
composer require dreamlayer/unopim-dreamlayer:0.5.5 --prefer-dist
php artisan migrate --no-interaction
php artisan optimize:clear
```

Review Composer's proposed operations: installing DreamLayer should not update or replace existing host dependencies. Do not use `--ignore-platform-reqs` or change UnoPim's dependency constraints to force installation. The package explicitly requires the `unopim/unopim` root project; see [host prerequisites](docs/HOST_PREREQUISITE.md) for why it does not require a nonexistent `unopim/core` package.

Laravel discovers the provider automatically. If required product APIs are unavailable, the provider logs a warning and leaves its routes and features disabled so UnoPim can continue starting.

Choose an existing asynchronous queue connection in `.env`, for example:

```dotenv
DREAMLAYER_QUEUE_CONNECTION=database
DREAMLAYER_QUEUE=dreamlayer
DREAMLAYER_WORKFLOW_TIMEOUT=1800
```

Run this under your process supervisor, using the same connection name:

```sh
php artisan queue:work database --queue=dreamlayer --sleep=1 --timeout=110 --tries=500
```

Set the queue connection's `retry_after` above the worker timeout, and supervise it with automatic restarts. The extension does not replace the host's queue configuration. Jobs have a finite retry deadline and use short database leases; network requests run outside row-lock transactions.

Schedule `php artisan dreamlayer:recover` through your existing scheduler to prune expired temporary image uploads and recover deliveries. It restores missing queue deliveries with their original identifiers. `--resume-expired` explicitly resumes expired work; `--verify-deliveries` repairs missing preview files. Neither command applies images to products.

## Connect

Open **DreamLayer** in the admin menu. Enter an API key and enable image tools. Keys are validated and encrypted in UnoPim; retain your application's `APP_KEY`.

For account connection, set `DREAMLAYER_CONNECTIONS_ENABLED=true` and clear the configuration cache. Use a persistent cache driver such as `file`, `database` or `redis`; the `array` driver cannot retain connection codes between requests. In **Connect DreamLayer**, choose whether to enable chat assistance. Leave it off for image access only, or turn it on and acknowledge the data-sharing information. Then choose **Get connection code** and approve the installation in DreamLayer.

To add chat to an existing account connection, turn on chat assistance in the connection section, acknowledge the disclosure, and choose **Reconnect DreamLayer**. Approve the new code. The old connection remains usable until the new one is saved, then the extension revokes it. Chat turns on only when the approved credential has chat permission and the server confirms that chat uses no image credits. A denied or unavailable chat grant is shown explicitly. History saving stays a separate setting. Account connection does not change UnoPim's default AI provider.

Chat assistance, saving editor conversations, and the optional native chat bridge are separate choices, all **off by default**. Chat assistance requires explicit acknowledgement of the data disclosure. Upgrade from 0.2.x disables the old managed-chat setting until an administrator makes that choice again.

For the complete supported experience, recommend enabling these options during customer setup:

- **Chat assistance, included at no extra charge:** make follow-up edits easier to describe by using recent editor messages to clarify the next instruction. It uses no image credits and is subject to your account's usage limits.
- **Saved editor conversations:** reopen, rename and bookmark your encrypted conversations in UnoPim.
- **Native chat integration:** access DreamLayer image tools from UnoPim's existing chat on compatible hosts.

The [installer guide](docs/INSTALLER_GUIDE.md) maps these benefits to customer requirements and explains how to configure the approved choices through Settings.

Generating and editing images use paid DreamLayer credits; see [current pricing](https://dreamlayer.io/pricing). If you use UnoPim's native chat, its existing conversation provider retains its own billing.

Give users the appropriate **My image results** permissions, including the editor, image browsing, previews and Apply. Product-edit permission is also required. Restrict connection and settings permissions to administrators.

## Use it

1. Open **My image results → Open image editor**.
2. Type **@**, use **Add product images**, or use the + button. Search by name or SKU and select up to five product images when supported by the connected backend.
3. Describe the change and choose **Create preview**. You can edit, remove a background, or generate a new image.
4. Review each result. Choose **Refine this image** to continue from that preview, or select another catalog image.
5. Choose **Apply to product** on the result you want. Each image has its own approval. Apply checks ownership, permissions and whether the destination changed, then fires UnoPim's normal product-update events.

If a result exceeds the destination attribute’s upload limit, the result card displays a catalog preview fitted to that limit and identifies its dimensions. **Apply to product** saves those exact reviewed bytes. **Download original PNG** retains the full-resolution image; further refinement also uses that original. The extension does not increase the host upload limit.

A generated image without a selected product can be downloaded but has no catalog destination. Closing the editor does not stop queued image work. Saved previews are available in **My image results**; opening a new conversation does not restore unsaved conversation messages.

## Privacy and data handling

| Feature | Data sent to DreamLayer |
| --- | --- |
| Image editing | Selected source image or previous preview, editing instruction, requested dimensions/aspect ratio, and request identifiers. |
| Optional chat assistance | The current message and recent conversation supplied in this editor, used to clarify an editing instruction. |
| Account connection | Installation identifier, application/store name and this installation's settings URL. |
| API calls | Access credential and the request identifiers required to authenticate and deduplicate work. |

DreamLayer and its processing providers receive the data needed to run those requests. The extension does not upload the entire catalog or send product text for similarity ranking. Native UnoPim chat continues using the provider chosen by the host administrator. Its provider may receive the product context and tool results that UnoPim normally shares with that provider.

UnoPim stores source copies, private previews, editing instructions, product destinations and workflow records for recovery and explicit Apply. Full editor messages are stored encrypted only if **Save this editor's conversation history** is enabled. Turning it off stops new message storage; it does not erase existing records. Database backups contain those records. Conversation-history records can be removed by the host administrator according to the store's retention policy; workflow records and image files should be retained while jobs are active.

The extension never enables history for other providers. Image previews are served through authenticated, owner-scoped routes. API credentials are not included in public image requests or logs. See [DreamLayer's website](https://dreamlayer.io) for current service information and obtain the applicable data-processing terms before enabling the service.

## Configuration and upgrades

Default endpoints are `https://api.dreamlayer.io/v1` and `https://platform.dreamlayer.io`. Operators can configure `DREAMLAYER_API_URL` and `DREAMLAYER_PLATFORM_URL` for another environment. Use its credentials, clear configuration caches and restart workers. Existing jobs stay pinned to their original endpoint and credential version.

Use a shared cache with atomic-lock support for multiple replicas. Private image storage must be shared with workers. Apply respects the host's image upload limits. Back up an existing installation before running migrations.

## Review material

- [Compatibility and validation](docs/COMPATIBILITY.md)
- [Marketplace review response](docs/MARKETPLACE_REVIEW.md)
- [Listing draft](docs/MARKETPLACE_LISTING.md)
- [Translation review](docs/TRANSLATIONS.md)
- [Final acceptance checklist](docs/ACCEPTANCE_TEST.md)
- [Current editor review media](docs/REVIEW_MEDIA.md)
- [Operations](docs/OPERATIONS.md)
- [API contract](docs/API_CONTRACT_V1.md)

## DreamLayer conversations

Open **DreamLayer** from the floating button on any authorized admin screen.
The compact chat stays available during UnoPim navigation. **Expand editor**
opens the same conversation in a larger workspace; there is no canvas. Use
**Add product images** (or the + button), choose up to five product images, and
then describe an edit. Every preview keeps its own product destination and
requires **Apply to product** before changing the catalog.

**History** contains **Chats**, **Bookmarks**, and **Images**. With saved history
enabled, conversations and their titles are encrypted in the UnoPim database
and can be reopened, renamed, bookmarked, or deleted by their owner. Turning
history off stops saving new messages and leaves existing saved chats available.
The active tab keeps temporary conversations and drafts in session storage so
full page navigation can restore them; closing the tab ends that temporary
session (browser session restoration may restore the tab). Logout clears it.
Image job records and previews remain separate from saved conversation messages.
Deleting a chat retains its image results and catalog images.

The standalone DreamLayer chat works independently of UnoPim’s selected chat
provider. The optional native chat bridge remains available only when the host
exposes its integration hooks. See [acceptance checks](docs/ACCEPTANCE_TEST.md).


### Image chat controls

- Type **@** to select catalog images by name or SKU; add the current product from the picker when working on its product page.
- Upload, paste or drop one PNG, JPEG or WebP image (up to 20 MB and 40 megapixels). Uploaded images have no product destination. Use catalog selection to edit up to five products together.
- Use **Image tasks** or the task selector for editing, background removal and generation.
- Open a result at full size, copy your instruction, or record helpful/not-helpful feedback locally.
- **Try again** restores the original source and instruction for review in the composer. It creates a new paid preview only after you choose **Create preview**. **Refine this image** instead starts from the selected result.
- Scroll through earlier messages without being pulled to the bottom. **Latest messages** returns to the newest result.

Files attached through the chat remain private in UnoPim until a preview request
sends the selected file to DreamLayer. Unused uploads expire after 24 hours;
`dreamlayer:recover` removes expired files. Accepted jobs keep a separate private
source copy for recovery. Configure PHP and reverse-proxy upload limits to allow
the advertised 20 MB maximum. Feedback is stored with the owner’s workflow in
UnoPim and is not a promise of model training or external feedback delivery.

Image editing, chat assistance and saving history remain independent settings.
With editing off, new previews and uploads are blocked; existing saved results
can still be reviewed and downloaded. With assistance off, the image instruction
is used without a conversation rewrite. With history off, no new full message
records are saved; temporary tab state and necessary image job records still
exist. Existing history remains available. Settings changes are refreshed by
an open chat within 15 seconds and validated immediately by the server.

The standalone assistant supports image work, not general product/category CRUD,
imports, exports, or changing UnoPim’s selected text provider. It does not require
permission to configure UnoPim’s AI Agent. Administrators can grant the editor,
catalog browsing, preview and product-edit permissions without settings access.
Upload, feedback, history management and Apply each have their own permissions.
Existing custom roles need the new upload/feedback grants if those actions are
wanted; upgrades never silently expand a role.

### Execution timing

New single-image requests use the direct managed-execution API when the connected
backend advertises it. Multi-product requests submit independent executions, so
one slow product does not block dispatch of the others. Backends without direct
execution keep the workflow API. Already accepted requests retain their original
API path and identifiers; upgrades do not resubmit them or charge for duplicates.
Ready local preparation checkpoints proceed immediately. Processing polls retain
a bounded interval. Result cards show elapsed time; worker logs record stage
latency and identifiers without recording credentials, prompts or image URLs.
Provider runtime and worker startup still affect total duration.
