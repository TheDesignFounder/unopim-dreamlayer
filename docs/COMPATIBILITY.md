# UnoPim compatibility

**0.4.0 update (2026-10-23):** the new persistent chat workspace is browser-tested on 3.1.3. Its PHP regression run passed 150 tests, 874 assertions and 15 host/configuration-specific skips. The older-release matrix below belongs to 0.3.0 and must not be read as completed acceptance of the new workspace on those releases.

Validation date: 2026-10-22. Candidate: DreamLayer 0.3.0, unpublished.

| Released host | Host Laravel | Tested PHP | Portable editor and Apply | Native chat adapter |
| --- | --- | --- | --- | --- |
| 0.1.0 | 10.x | 8.3.30 | Passed | Required hooks absent; stays disabled |
| 1.0.1 | 10.x | 8.3.30 | Passed | Required hooks absent; stays disabled |
| 2.1.6 | 12.x | 8.4.26 | Passed | Required hooks absent; stays disabled |
| 3.1.3 | 13.x | 8.4.26 | Passed | Required hooks absent; stays disabled |

Each host was extracted from its official release archive, installed from its
own Composer lockfile and tested with MySQL 8.4.9. Adding the extension left all
existing locked package versions and source references unchanged: 197, 199, 203
and 212 packages respectively. No core patches or host dependency overrides
were applied. Test fixtures create product data through the host's normal
models; the 0.1.0 fixture adds an image attribute to a product family because
that release's default seed does not supply one.

`tests/compatibility.php` passes 22 checks on each release: optional features off,
request-scoped settings, catalog selection, idempotent admission and conflict
rejection, HTTP outside transactions, worker execution, valid private PNG
preview, no catalog change before Apply, both product update events, repeated
Apply, follow-up source selection, finite polling and configurable queues.
`tests/view-compatibility.php` renders the editor, result list and settings with
the host's own components. Browser acceptance on 3.1.3 covers preview loading,
Apply, follow-up editing, mobile width and light/dark rendering.

Account connection tests also run on the older releases. The 0.1.0, 1.0.1 and
2.1.6 runs each pass 19 tests and 165 assertions, including chat consent, image-only
connection, reconnect and missing chat grants. The older hosts use a disposable
test bootstrap; their application code and dependencies remain unchanged.

The full 3.1.3 regression run passes 144 tests and 821 assertions. Native-hook-specific tests are skipped
there because those APIs are absent; the PostgreSQL concurrency scenario runs
separately. PostgreSQL 16 tests cover fresh migration, rollback/reinstall,
historical repair and upgrade from the 0.2.16 schema.

## Optional native chat

The adapter uses the host's extension registries when they expose the required
contract. It is off by default and uses the administrator's existing chat
provider. It does not require replacing the host AI SDK or enabling host-wide
history. The standalone editor remains available without these hooks.

A separate development-host regression run uses official upstream commit
`1abbb5bfa393f62b7d3d8496a3afea2a93df4084` with its own locked dependencies.
Its regression run passed 144 tests with 883 assertions (one PostgreSQL-only
test skipped and run separately). This is a development compatibility check,
not an additional released version
in the marketplace compatibility field.

## Limits of the claim

The historical 0.3.0 listing named the four tested tags above. The 0.4.0 workspace needs browser acceptance on an older tag before renewing its UI claim for that tag. The Composer range
`>=0.1.0 <4.0.0` permits installation and further testing; it is not evidence that
every intermediate, customized or future release works. Additional tags should
pass the same clean-install, permission, image, migration and browser checks.

Image and chat API responses were simulated with real HTTP client/worker code
and synthetic PNGs. No paid generation, live credential exchange or provider
image-quality assessment was performed. Browser fixtures were installed only
in the disposable 3.1.3 test host and are excluded from the distribution.

All 33 locales have translation files and validated keys/placeholders. English
was edited for clarity; other languages received an AI-assisted review of key workflows and terminology, with native-speaker certification not claimed.
Compatibility with old dependencies is not a security certification of an old
UnoPim installation.
