# DreamLayer for UnoPIM

Generate and edit product images from UnoPIM's Agentic PIM chat. Select a product image, describe the change, and review the preview before choosing **Apply to product**. Generating a preview does not change the catalog.

This is the review package, version **0.2.13**. Account connection, managed chat and advanced image workflows require the corresponding DreamLayer services to be enabled in the environment you use.

Version 0.2.13 includes the 0.2.12 correction for missing page styles when entering DreamLayer through UnoPIM's
sidebar. Upgrading from 0.2.11 does not change the core patch or database schema;
update the package, clear compiled views and restart the web process if its PHP
opcode cache does not check changed files. Do not apply the core patch again.

The owner completed fresh installation, account connection and image generation
on macOS/Docker with 0.2.12. This submission adds Windows documentation; the
application runtime is unchanged from that accepted version.

## Requirements

- A compatible UnoPIM 3.x installation with the [generic chat extension hooks](docs/CORE_PATCH.md). The bundled patch has been checked against UnoPIM 3.x revision `d29ffdcd7b26527dd6a06d24c90d3e787cbb9b00`; check compatibility before applying it to another revision.
- PHP 8.4.1 or later, Laravel 13 and the GD extension.
- Private writable local storage shared by the web application and queue workers. Storage must support atomic file renames.
- UnoPIM's database queue tables, a supervised queue worker and the Laravel scheduler.
- Access to a DreamLayer account or API key issued by the configured DreamLayer environment. English (`en_US`) is the initial supported locale.

## Install

**Windows:** follow [the WSL 2 and Docker guide](docs/WINDOWS_INSTALL.md), including
PowerShell setup, Ubuntu paths and the dedicated worker. Windows execution is
not yet verified; the completed manual acceptance used macOS and Docker.

If Composer blocks the host's older SVG sanitizer dependency, see
[the separate UnoPIM prerequisite](docs/HOST_PREREQUISITE.md).

Run these commands from a compatible UnoPIM application directory with PHP and
Composer available. For Docker, use the container commands in the Windows/WSL
guide (the same Linux-container paths apply on macOS). Back up an existing host
and database before installing.

Clone the tagged source from the public repository into the host's shared tree:

```sh
mkdir -p packages/DreamLayer
git clone --branch 0.2.13 --depth 1 https://github.com/TheDesignFounder/unopim-dreamlayer.git packages/DreamLayer/unopim-dreamlayer
```

**If the generic core hooks are already installed, skip this next block.**
Otherwise apply the full patch once, only after its compatibility check succeeds:

```sh
git apply --check packages/DreamLayer/unopim-dreamlayer/patches/unopim-3x-chat-hooks.patch
git apply packages/DreamLayer/unopim-dreamlayer/patches/unopim-3x-chat-hooks.patch
```

If the check fails, stop and consult [core compatibility](docs/CORE_PATCH.md).
Do not force the patch or apply it twice. Install the cloned Composer package:

```sh
composer config repositories.dreamlayer path packages/DreamLayer/unopim-dreamlayer
composer require dreamlayer/unopim-dreamlayer:0.2.13 --minimal-changes
php artisan migrate --no-interaction
php artisan optimize:clear
```

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

The default endpoints are `https://api.dreamlayer.io/v1` and `https://dreamlayer.io`. For another independently deployed environment, set:

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
