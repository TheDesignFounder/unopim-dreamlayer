# Local regression tests

Run only against disposable hosts and databases. The extension test bootstrap
requires an explicit test database name. Provider requests are faked; the tests
must not use production credentials or customer data.

## Released hosts

Use the official tags in `docs/COMPATIBILITY.md`, install each host from its
existing lockfile, add the extension without upgrading host packages, then run:

```sh
php /path/to/extension/tests/compatibility.php
php /path/to/extension/tests/view-compatibility.php
```

The version matrix in `.github/workflows/compatibility.yml` reproduces the
Composer and image-workflow checks. `tools/check-host-lock.py before.json
composer.lock` rejects changes to existing host packages.

On 3.1.3, run the application's PHPUnit with this extension's configuration:

```sh
UNOPIM_HOST_ROOT=/path/to/host DB_DATABASE=unopim_extension_test \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
/path/to/host/vendor/bin/phpunit -c /path/to/extension/tests/phpunit.xml \
  --fail-on-warning --fail-on-risky
```

Supply the disposable database credentials through the test environment.
Native-hook tests skip on tags without the hooks. To exercise them, use the
upstream development commit documented in the compatibility report. Tests of
native history explicitly enable the host setting inside the test; the
extension never enables it.

## Migrations and concurrency

With a separate PostgreSQL database ending in `_migration_test`:

```sh
APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=unopim_install_migration_test \
DREAMLAYER_MIGRATION_TEST=1 UNOPIM_HOST_ROOT=/path/to/host \
php /path/to/extension/tests/migration-install.php
```

The runner creates and removes randomly named schemas. It verifies fresh
install, repeat migration, full rollback/reinstall, historical repair and
upgrade from 0.2.16 while preserving credentials/results and resetting consent.

`ReconnectConcurrencyTest` needs a dedicated PostgreSQL database ending in
`_test`, with `DREAMLAYER_CONCURRENCY_TEST_DATABASE` exactly matching its name.
Run it separately with explicit PostgreSQL connection environment variables.
It observes competing writes through a second connection and cleans its rows.

## Browser acceptance

`fixtures/MarketplaceBrowserProvider.php` is for a disposable host only. It
requires `APP_ENV=testing` and `DREAMLAYER_BROWSER_FIXTURES=true`; the queue worker
also needs `DREAMLAYER_BROWSER_WORKER=true`. It blocks unhandled requests and
returns synthetic image results. Never register it in a deployed host.

After `compatibility.php` has created the local test administrator/product,
serve that host and run an asynchronous worker, then:

```sh
PLAYWRIGHT_MODULE=/path/to/playwright \
DREAMLAYER_BROWSER_URL=http://127.0.0.1:8190 \
DREAMLAYER_BROWSER_EVIDENCE=/tmp/dreamlayer-browser \
node /path/to/extension/tests/fixtures/browser-portable-editor.cjs
```

This uses only the disposable administrator defined in the fixture. It verifies
loaded previews, explicit Apply, follow-up images, native selectors, narrow
layout and dark rendering. Screenshots contain synthetic fixtures, not examples
of AI image quality. Older native-chat browser harnesses are development-only
and do not establish portable-editor acceptance.

## Package checks

```sh
php tools/check-translations.php
php tests/safe-bootstrap.php
composer validate --no-check-publish
python3 tools/build_release.py
```

The archive builder requires a clean committed tree. It uses `.gitattributes`
and rejects test/tool/CI/patch material in the ZIP. Test the extracted ZIP in a
released host as well as the development checkout.
