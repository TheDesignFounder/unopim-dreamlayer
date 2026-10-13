# Local regression tests

Fresh installation and upgrades must also use the real Laravel migrator. The
application suite below starts from an existing schema and cannot establish
migration ordering. With a dedicated PostgreSQL database whose name ends in
`_migration_test` and the patched host's dependencies installed, run:

```sh
APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=dreamlayer_install_migration_test \
DREAMLAYER_MIGRATION_TEST=1 UNOPIM_HOST_ROOT=/path/to/patched-host \
php /path/to/package/tests/migration-install.php
```

Configure the test database host/user/password through the host environment.
The runner creates and removes only its randomly named schemas, refuses other
database names, blocks stray HTTP requests, and exits nonzero on failure. It
checks fresh install, repeated migrate, complete rollback/reinstall, preservation
of existing credentials/results/endpoints and migration history, rollback of
the corrective migration, and repair of an already-recorded missing column.
It does not install or alter the owner's acceptance candidate.

Run only against an isolated test host/database. These commands do not install
the extension or change a running acceptance candidate.

For the existing Laravel host, set DB_DATABASE to its disposable test database
and UNOPIM_HOST_ROOT to that host; run its PHPUnit with this package's
tests/bootstrap.php and test directory. HTTP provider calls are faked.

For the picker, highlighting parity, Unicode limits and live settings behavior:

    UNOPIM_HOST_ROOT=/path/to/patched-host node --test tests/fixtures/ui-contract.test.cjs

Node 18 or later is required. This uses the committed generic core test harness
from the matching review patch; no server or external provider is contacted.
The same mention-cases.json is consumed by ExperienceTest on PHP and by the
actual picker/highlighter JavaScript, so both sides use the same expected cases.

For the rendered settings label, use existing Playwright and Chrome:

    UNOPIM_HOST_ROOT=/path/to/patched-host \
      PLAYWRIGHT_MODULE=/path/to/existing/@playwright/test \
      CHROME_PATH=/path/to/chrome \
      node tests/fixtures/browser-live-settings.cjs

The browser uses the host's existing compiled admin assets with the current
Blade status component. Every request is fulfilled locally in a fresh browser
context, including status refreshes. It never navigates to the real UnoPIM,
DreamLayer account site or provider. This does not replace authenticated
new-user acceptance or the reviewer/owner reconnect recheck.

For navigation styling, use a running, disconnected review installation and a
private credentials file containing `email:` and `password:` lines:

```sh
UNOPIM_TEST_URL=http://localhost:8150 \
  UNOPIM_TEST_CREDENTIALS_FILE=/private/path/admin-credentials.txt \
  PLAYWRIGHT_MODULE=/path/to/existing/@playwright/test \
  CHROME_PATH=/path/to/chrome \
  node tests/fixtures/browser-navigation-styles.cjs
```

Set `UNOPIM_ADMIN_PATH` if the admin prefix differs from `/admin`, and optionally
`UNOPIM_TEST_OUTPUT` for screenshots. The check logs in and follows real sidebar
links from the catalog to settings, results and chat entry, then tests browser
Back and direct loads in light/dark themes. It verifies navigation did not fall
back to a reload. Connection-code requests are fulfilled inside the browser;
other non-login writes and requests to external origins are blocked. It does
not connect an account, send chat, apply images or run paid work.

For picker layout and actual Vue composer updates, without any running service:

```sh
UNOPIM_HOST_ROOT=/path/to/patched-host \
  PLAYWRIGHT_MODULE=/path/to/existing/@playwright/test \
  CHROME_PATH=/path/to/chrome \
  node tests/fixtures/browser-picker-layout.cjs
```

This uses synthetic tall/wide catalog images and the existing admin bundle. It
checks that changing the hovered preview does not move the selection row, mouse
selection inserts the name and chip, and Enter selects a reference. All requests
are fulfilled in the browser; no account, chat submission or provider is used.
For before/after diagnosis, `PICKER_TEST_VIEW` and `UNOPIM_TEST_WIDGET_FILE` can
point at the corresponding saved Blade sources. Normal runs use this checkout
and the patched host.
