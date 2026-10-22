# DreamLayer for UnoPim

Create and refine product images inside UnoPim. Select images, describe a change, review the previews, then choose what to save. Follow-up edits use the previous result. DreamLayer handles image model selection; UnoPim keeps the product records and permissions.

**Free extension, requires paid DreamLayer credits.** This branch contains the unpublished **0.3.0** candidate. It has not been submitted, merged or deployed.

## Compatibility

The image editor works independently of UnoPim's AI Agent and native chat hooks. Installation and image workflow checks have passed on released UnoPim **0.1.0, 1.0.1, 2.1.6 and 3.1.3**. See the [compatibility matrix](docs/COMPATIBILITY.md) for the exact tests and limits. This is not a claim that every intermediate or future release has been tested.

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

For this unpublished candidate, use the reviewed distribution ZIP supplied alongside the release notes. Extract it to `packages/DreamLayer/unopim-dreamlayer` in your UnoPim application. Do not copy test fixtures into the host. From the application directory:

```sh
composer config repositories.dreamlayer '{"type":"path","url":"packages/DreamLayer/unopim-dreamlayer","options":{"symlink":false}}'
composer require dreamlayer/unopim-dreamlayer:0.3.0 --prefer-dist
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

Optionally schedule `php artisan dreamlayer:recover` through your existing scheduler. It restores missing queue deliveries with their original identifiers. `--resume-expired` explicitly resumes expired work; `--verify-deliveries` repairs missing preview files. Neither command applies images to products.

## Connect

Open **DreamLayer** in the admin menu. Enter an API key and enable image tools. Keys are validated and encrypted in UnoPim; retain your application's `APP_KEY`.

If account connection is available, set `DREAMLAYER_CONNECTIONS_ENABLED=true`, choose **Get connection code**, and approve the installation in DreamLayer. Account connection requests image access by default. It does not select DreamLayer as the host's default AI provider.

Chat assistance, saving editor conversations, and the optional native chat bridge are separate choices, all **off by default**. Chat assistance requires explicit acknowledgement of the data disclosure. Upgrade from 0.2.x disables the old managed-chat setting until an administrator makes that choice again.

For the complete supported experience, recommend enabling these options during customer setup:

- **Chat assistance:** make follow-up edits easier to describe by using recent editor messages to clarify the next instruction.
- **Saved editor conversations:** keep an encrypted message record in UnoPim for administrator review and troubleshooting.
- **Native chat integration:** access DreamLayer image tools from UnoPim's existing chat on compatible hosts.

The [installer guide](docs/INSTALLER_GUIDE.md) maps these benefits to customer requirements and explains how to configure the approved choices through Settings.

Give users the appropriate **My image results** permissions, including the editor, image browsing, previews and Apply. Product-edit permission is also required. Restrict connection and settings permissions to administrators.

## Use it

1. Open **My image results → DreamLayer image editor**.
2. Search by SKU and select a product image. Select up to five images when the backend supports image workflows.
3. Describe the change and choose **Create preview**. You can edit, remove a background, or generate a new image.
4. Review each result. Choose **Refine this image** to continue from that preview, or select another catalog image.
5. Choose **Apply to product** on the result you want. Each image has its own approval. Apply checks ownership, permissions and whether the destination changed, then fires UnoPim's normal product-update events.

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
- [Translation status](docs/TRANSLATIONS.md)
- [Operations](docs/OPERATIONS.md)
- [API contract](docs/API_CONTRACT_V1.md)
