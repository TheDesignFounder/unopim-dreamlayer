# DreamLayer for UnoPIM

Generate and edit product images from UnoPIM's Agentic PIM chat. Select a product image, describe the change, and refine the preview through follow-up messages before choosing **Apply to product**. Generating a preview does not change the catalog.

**Version 0.2.16** integrates with UnoPIM's merged chat extension hooks and optional saved history. The supported source baseline is UnoPIM `3.x` commit `1abbb5bfa393f62b7d3d8496a3afea2a93df4084` (October 19, 2026). This is a development-branch baseline, not a claim that every published UnoPIM 3.x release includes these hooks. See [compatibility and upgrades](docs/CORE_PATCH.md).

DreamLayer enables saved database chat history by default. This affects all chat providers on the host. Set `DREAMLAYER_PERSIST_CHAT_HISTORY=false` to leave UnoPIM's history setting unchanged.

Validation details and the distinction between local API fixtures and live provider testing are recorded in [release verification](docs/VERIFICATION_0_2_16.md).

## Requirements

- UnoPIM at the supported source baseline above, with its native chat hooks. Older or customized installations need the [compatibility review](docs/CORE_PATCH.md) before upgrading.
- PHP 8.4.1 or later, Laravel 13 and the GD extension.
- Private writable local storage shared by the web application and queue workers. Storage must support atomic file renames.
- UnoPIM's database queue tables, a supervised queue worker and the Laravel scheduler.
- Access to a DreamLayer account or API key issued by the configured DreamLayer environment. English (`en_US`) is the initial supported locale.

## Older UnoPIM installations

Check [the supported core revision](docs/CORE_PATCH.md) before installing. Hosts
without the required hooks cannot boot this extension. Upgrade and validate the
host first; the extension does not silently install or modify core hooks.

## Install

**Windows:** follow [the WSL 2 and Docker guide](docs/WINDOWS_INSTALL.md), including
PowerShell setup, Ubuntu paths and the dedicated worker. Windows execution is
not yet verified; the completed manual acceptance used macOS and Docker.

Apply [the two required UnoPIM dependency updates](docs/HOST_PREREQUISITE.md)
before installing the extension. The automated checks use this exact baseline.

Run these commands from a compatible UnoPIM application directory with PHP and
Composer available. For Docker, use the container commands in the Windows/WSL
guide (the same Linux-container paths apply on macOS). Back up an existing host
and database before installing.

From the UnoPIM project root, install the tagged extension into the host's shared tree:

```sh
mkdir -p packages/DreamLayer
git clone --branch 0.2.16 --depth 1 https://github.com/TheDesignFounder/unopim-dreamlayer.git packages/DreamLayer/unopim-dreamlayer
```

**Do not apply a bundled core patch to the supported merged UnoPIM baseline.** The hooks are already included. After installing the host's locked dependencies, check the required capabilities and register the package:

```sh
php packages/DreamLayer/unopim-dreamlayer/tools/check-host.php
composer config repositories.dreamlayer '{"type":"path","url":"packages/DreamLayer/unopim-dreamlayer","options":{"symlink":false}}'
composer require dreamlayer/unopim-dreamlayer:0.2.16
php artisan migrate --no-interaction
php artisan optimize:clear
```

The capability check reads host classes without modifying them. It is not a substitute for the supported-version requirement or the checks on your own installation. If it reports missing hooks, follow [compatibility and upgrades](docs/CORE_PATCH.md); do not force an old patch into a newer host.

This installation uses the public Git repository and a Composer path repository;
Packagist or a marketplace listing is not required.

Laravel discovers the extension automatically. No manual service-provider registration is needed.

Run this worker under your process supervisor:

```sh
php artisan queue:work dreamlayer --queue=dreamlayer --sleep=1 --timeout=110 --tries=0
```

The supervisor must restart the worker after **any exit**, including exit code 0
after a lost database connection. For Docker Compose, set `restart: unless-stopped`
on the queue and scheduler services. For Supervisor, use `autorestart=true`.
An `on-failure` policy is insufficient for a clean worker exit. Database startup
health checks do not restart a worker that stops later.

The result card distinguishes queued work from processing. If its saved progress
has not updated for two minutes, it reports a delay and keeps the same request.
Check worker logs and restart the worker; do not submit a replacement image request.
The recovery schedule only adds missing queue deliveries. Existing queued or
reserved jobs remain available to the worker after it restarts.

Add the recovery task to UnoPIM's `routes/console.php`:

```php
\Illuminate\Support\Facades\Schedule::command('dreamlayer:recover')
    ->everyMinute()
    ->withoutOverlapping();
```

Keep the normal Laravel scheduler running. Restart the relevant web and queue processes after installation or configuration changes. Verify that `php artisan schedule:list` includes `dreamlayer:recover`.

**DreamLayer** should now appear in the admin menu. Give the appropriate roles access to DreamLayer settings, saved image results and product editing.

## Connect an account

When the DreamLayer connection API is available in your environment, set `DREAMLAYER_CONNECTIONS_ENABLED=true` in UnoPIM's server configuration.

1. Open **DreamLayer** in UnoPIM. A connection code appears automatically. It stays valid for the displayed countdown; a new code appears when it expires. **Generate new code** replaces it immediately.
2. Choose **Open DreamLayer**, sign in or create an account, enter the matching code, and approve the installation. **Chat & Images** is the default.
3. Choose **Return to UnoPIM**. The chip stays **Not Connected** until approval is detected, then shows **Waiting for connection** while the connection finishes. Returning to an existing code checks approval as soon as its current polling deadline allows, without restarting the countdown. The page updates to show your connected account and available credits.
4. Choose **Open chat**. If UnoPIM chat is disabled, administrators with configuration access can choose **Enable and open chat**. This enables the chat interface without sending a message or enabling automatic enrichment.

**Account settings** lets you switch to **Images only** to keep another chat provider, pause DreamLayer tools, manage billing, connect another account, or disconnect. Manual keys and local connection recovery are in their own advanced sections.

Manual API-key setup is also available in account settings. Keys are validated and encrypted in UnoPIM; preserve the application's `APP_KEY` so saved credentials remain readable. Reconnecting replaces the installation credential and attempts to revoke the previous installation. A cleanup failure appears as a notice. Manually created API keys are managed separately on DreamLayer's API-key page.

## Use it

1. Open Agentic PIM chat and click **Select Product Image**, or type `@`.
2. Search by product name or SKU. Choose an image using its thumbnail and preview; arrow keys and Enter also work.
3. Describe the edit using the inserted product-name reference, such as `Change the background of @Sample Serum to pale peach. Keep the bottle and label unchanged.`
4. Wait for the preview. Open it to inspect or download the result.
5. Choose **Apply to product** to save the image to the displayed product and destination. If the product image changed while the preview was running, create a new preview before applying.

References display product names. Duplicate selected names receive a suffix such as `(2)` to distinguish them. Choose images through the picker; typing or pasting a product name alone does not attach its image.

When the backend supports it, select up to **five images** and apply one shared instruction separately to each. Each image has its own preview, progress and Apply action. A single image can receive up to two sequential edit steps when workflow support is available. Completed outputs remain downloadable if a later step fails.

Choose the chat setup that suits your installation:

- **DreamLayer – Chat & Images:** DreamLayer provides chat and image processing. Its single internal chat model is selected automatically.
- **DreamLayer – Images only:** use your separately configured UnoPIM chat provider for conversations while DreamLayer handles image tools. Other providers retain their model selector.

Included DreamLayer chat does not deduct image credits. Image generation and editing use image credits; each sequential edit step is charged separately. Availability and limits depend on the configured backend.

Open **My image results** to find saved previews, download outputs and apply completed results. Closing the browser does not stop queued image work. Applying a result does not generate another image.

## Configure the environment

DreamLayer enables UnoPIM's optional database chat history so conversations and result cards can be reopened. This setting applies to the host's AI chat, including other providers. Set `DREAMLAYER_PERSIST_CHAT_HISTORY=false` to leave the core history setting unchanged; core database history is off by default. Browser storage remains available when database history is disabled. After changing environment settings, clear Laravel's configuration cache. Existing saved conversations are retained when persistence is disabled.

The default endpoints are `https://api.dreamlayer.io/v1` and `https://platform.dreamlayer.io`. If an existing installation explicitly sets `DREAMLAYER_PLATFORM_URL=https://dreamlayer.io`, update that override to `https://platform.dreamlayer.io` and clear Laravel's configuration cache. The console origin must match the connection URL returned by the selected API. For another independently deployed environment, set:

```dotenv
DREAMLAYER_API_URL=https://your-api.example/v1
DREAMLAYER_PLATFORM_URL=https://your-account.example
```

Use credentials from that environment. Finish active work before switching endpoints, then replace the credential and restart workers. For local development only, `DREAMLAYER_ALLOW_LOCAL_HTTP=true` permits loopback or `host.docker.internal` endpoints when `APP_ENV` is `local` or `testing`.

For multiple web replicas, use a shared cache with atomic-lock support and shared private storage. Local file cache is suitable only when the processes share its storage. Set an appropriate product image upload limit: generated PNGs can exceed UnoPIM's default 2 MB. Apply respects the configured limit.

## Developer documentation

- [Reviewer guide](docs/REVIEWING.md): request flow, design decisions, trust boundaries and test map.
- [New-user and reconnect/revoke test](NEW_USER_TEST.md)
- [Fresh installation test](docs/FRESH_INSTALL_TEST.md): installation, connection and chat acceptance for this candidate.

- [Core extension hooks](docs/CORE_PATCH.md): integration points and compatibility.
- [API contract](docs/API_CONTRACT_V1.md): supported operations and capability negotiation.
- [Operating and verifying the integration](docs/OPERATIONS.md): execution, history, storage, recovery and isolated tests.
- [Managed chat and multiple-image editing](docs/EXPERIENCE_0_2.md): detailed behavior and request contracts.
- [Backend setup](docs/BACKEND_SETUP.md): service-side prerequisites.

## License

[MIT](LICENSE), matching UnoPIM. The compatibility patch retains Webkul’s copyright notice.
