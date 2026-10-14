# Install and review on Windows

Use **WSL 2 (Ubuntu) and Docker Desktop's Linux containers** for this guide. This
keeps PHP, Composer, the queue worker and shared storage in the same Linux
environment. Native Windows PHP/IIS and PowerShell equivalents for every Linux
command have not been validated.

**Test status:** the owner completed fresh installation, account connection and
image generation on macOS with Docker using 0.2.12. The same runtime is included
in this submission. These Windows instructions have been checked against the
upstream configuration and official setup documentation, but have not been run
on a Windows machine. Report Windows results separately.

## 1. Prepare Windows and Docker

In **PowerShell as Administrator**, install Ubuntu if WSL is not already set up:

```powershell
wsl --install -d Ubuntu
```

Restart Windows when requested, open Ubuntu and create its Linux username and
password. In PowerShell, check that Ubuntu uses version 2:

```powershell
wsl --update
wsl --list --verbose
```

If Ubuntu is listed as version 1, run `wsl --set-version Ubuntu 2`. Follow
[Microsoft's WSL installation instructions](https://learn.microsoft.com/en-us/windows/wsl/install)
if installation or virtualization prerequisites need attention.

Install and start [Docker Desktop for Windows](https://docs.docker.com/desktop/setup/install/windows-install/).
Enable the WSL 2 engine and Ubuntu integration in Docker Desktop's settings;
see [Docker's WSL guide](https://docs.docker.com/desktop/features/wsl/).

**All remaining shell blocks run in the Ubuntu terminal**, not PowerShell or
Command Prompt. Paste each complete block and stop if a command fails.

```sh
docker version
docker compose version
sudo apt-get update
sudo apt-get install -y git unzip
mkdir -p ~/dreamlayer-review
```

Keep the working checkout under the Ubuntu home directory. A Windows download
at `C:\Users\YOUR_NAME\Downloads` is available at
`/mnt/c/Users/YOUR_NAME/Downloads` inside Ubuntu. Replace `YOUR_NAME` with your
Windows account folder; quote paths containing spaces. No `/Users/user/...`
Mac path or private `/setup/...` helper is needed.

## 2. Prepare a fresh UnoPIM development checkout

If you already have a working UnoPIM source installation inside WSL, use that
directory and skip initial setup. Otherwise, use UnoPIM's source development
stack, which mounts your checkout into the PHP container:

```sh
cd ~/dreamlayer-review
git clone --branch 3.x https://github.com/unopim/unopim.git unopim
cd unopim
cp .env.docker .env
docker compose -f compose.dev.yaml up -d
docker compose -f compose.dev.yaml logs --tail=80 unopim-fpm
```

Wait for installation to complete, then open `http://localhost:8000/admin` in
your Windows browser. Use the admin credentials you configured before first
boot, or read UnoPIM's generated credentials privately:

```sh
docker compose -f compose.dev.yaml exec unopim-fpm cat storage/app/admin-credentials.txt
```

Confirm UnoPIM login works and add a product with an image. If a port is already
used, adjust `APP_PORT`, `APP_URL` and the conflicting `FORWARD_*` setting in
`.env` before restarting. Consult the checked-out UnoPIM README for its current
requirements. This guide uses `compose.dev.yaml`; plain `docker compose up`
selects the different prebuilt-image stack and will not use your patched source.

## 3. Clone and install the extension

Clone the tagged extension source from its public repository into UnoPIM's
mounted checkout. Web, worker and scheduler must all see this directory:

```sh
cd ~/dreamlayer-review/unopim
mkdir -p packages/DreamLayer
git clone --branch 0.2.15 --depth 1 https://github.com/TheDesignFounder/unopim-dreamlayer.git packages/DreamLayer/unopim-dreamlayer
```

For reproducibility, record `git -C packages/DreamLayer/unopim-dreamlayer rev-parse HEAD`
and compare it with the published tag's commit. No package registry is needed.

If you checked out the generic chat-hooks PR, its hooks are already present:
skip the next block. Otherwise apply the package's full patch once, only if its
compatibility check succeeds:

```sh
git apply --check packages/DreamLayer/unopim-dreamlayer/patches/unopim-3x-chat-hooks.patch
git apply packages/DreamLayer/unopim-dreamlayer/patches/unopim-3x-chat-hooks.patch
```

If the check fails, stop and compare your UnoPIM revision with
[CORE_PATCH.md](CORE_PATCH.md). Do not force a partial patch or apply it twice.

Run Composer **inside `unopim-fpm`**. `/var/www/html` is the container's path to
the mounted UnoPIM checkout, so the package is visible to web and worker services:

```sh
docker compose -f compose.dev.yaml exec -T unopim-fpm composer config repositories.dreamlayer path /var/www/html/packages/DreamLayer/unopim-dreamlayer
docker compose -f compose.dev.yaml exec -T unopim-fpm composer require dreamlayer/unopim-dreamlayer:0.2.15 --minimal-changes
docker compose -f compose.dev.yaml exec -T unopim-fpm php artisan migrate --no-interaction
docker compose -f compose.dev.yaml exec -T unopim-fpm php artisan optimize:clear
```

If Composer reports the existing host's `enshrined/svg-sanitize` 0.22 dependency
as blocked, follow [the host prerequisite](HOST_PREREQUISITE.md). Do not disable
Composer's advisory checks. That prerequisite is separate from the chat hooks.

## 4. Start the image worker and recovery schedule

Add this once to UnoPIM's `routes/console.php`, after its opening `<?php`:

```php
\Illuminate\Support\Facades\Schedule::command('dreamlayer:recover')
    ->everyMinute()
    ->withoutOverlapping();
```

Create `compose.dreamlayer.yaml` next to `compose.dev.yaml`:

```yaml
services:
  unopim-dreamlayer-worker:
    extends:
      file: compose.dev.yaml
      service: unopim-q
    user: "www-data"
    entrypoint: ["php", "artisan", "queue:work", "dreamlayer", "--queue=dreamlayer", "--sleep=1", "--timeout=110", "--tries=0"]
    restart: unless-stopped
```

This adds a dedicated database-backed DreamLayer worker and preserves UnoPIM's
existing queue worker. The inherited development service shares the checkout,
environment, database and private image storage. It restarts after any exit.

```sh
docker compose -f compose.dev.yaml -f compose.dreamlayer.yaml up -d unopim-dreamlayer-worker unopim-scheduler
docker compose -f compose.dev.yaml -f compose.dreamlayer.yaml restart unopim-fpm unopim-dreamlayer-worker unopim-scheduler
docker compose -f compose.dev.yaml exec -T unopim-fpm php artisan schedule:list
```

Expect `dreamlayer:recover` every minute. Keep Docker Desktop and these services
running while testing; a suspended or shut-down laptop cannot process edits.

## 5. Connect and test in the Windows browser

Use a DreamLayer environment that advertises the required connection/chat/image
capabilities. Set its values in UnoPIM's `.env`:

```dotenv
DREAMLAYER_API_URL=https://YOUR_API_HOST/v1
DREAMLAYER_PLATFORM_URL=https://YOUR_ACCOUNT_HOST
DREAMLAYER_CONNECTIONS_ENABLED=true
```

Replace both example hosts with the endpoints supplied for the review. Your
Windows computer cannot reach another developer's `localhost`. The local ports
shown in historical screenshots are not public review services. For an API
running on your own Windows host, the PHP container normally uses
`host.docker.internal`; the account-console URL must work in your Windows browser.
Local HTTP also requires `DREAMLAYER_ALLOW_LOCAL_HTTP=true` and `APP_ENV=local`.

After `.env` changes, run `up -d` with both Compose files to recreate services
whose environment changed, clear configuration with `php artisan optimize:clear`
inside `unopim-fpm`, and restart web, DreamLayer worker and scheduler as above.

Open **DreamLayer** from the UnoPIM sidebar. The spacing and compact buttons
should be correct immediately, without a refresh. Copy the code, approve the
matching installation in DreamLayer, return and choose **Enable and open chat**
or **Open chat**. Continue the [acceptance checklist](FRESH_INSTALL_TEST.md).

If an edit remains queued, inspect the worker before sending another prompt:

```sh
docker compose -f compose.dev.yaml -f compose.dreamlayer.yaml ps
docker compose -f compose.dev.yaml -f compose.dreamlayer.yaml logs --tail=100 unopim-dreamlayer-worker unopim-scheduler
```

Restarting the worker resumes its existing queued work. Share the failing step
and relevant error output without credentials. A Windows test result should
include Windows/WSL versions, UnoPIM commit, package version and observed result.
