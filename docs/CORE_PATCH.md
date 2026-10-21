# UnoPIM compatibility and upgrades

## Supported installation

DreamLayer 0.2.16 targets UnoPIM `3.x` commit
`1abbb5bfa393f62b7d3d8496a3afea2a93df4084` (October 19, 2026).
Apply the [two documented host dependency updates](HOST_PREREQUISITE.md) as well.
The registries, tool decorators, composer controls, result cards and optional
saved history are included natively through upstream PRs #775 and #776. PR #770's
changes were included through #775.

This is a source revision, not a minimum tagged UnoPIM release. Do not infer
compatibility from the `3.x` name alone. Pin the documented revision for a pilot;
validate later revisions before upgrading.

**No core patch is needed on this baseline. Do not apply any bundled patch.**
Install the host's locked Composer dependencies, run `php
/path/to/extension/tools/check-host.php` from its project root, then install the
extension and run `php artisan migrate --no-interaction`. See the [README](../README.md).

The capability check reads classes only. It does not verify migrations, database
permissions, provider availability, or compatibility with arbitrary local changes.
The normal Laravel migration command applies all outstanding core and extension
migrations; do not manually mark them completed.

## Upgrading a previously patched installation

Published 0.2.14 and 0.2.15 bundled older core hooks based on `c3fefa34`.
The October 16 review branch instead targeted `b406c71d`. Neither is the final
merged source baseline used by 0.2.16.

Back up the database and application files, record the exact installed core
revision, and reconcile customizations with the supported upstream source in a
staging installation. Keep the migration ledger and saved data. Run outstanding
migrations normally, install this extension, clear caches and restart workers.
Test existing conversations, product images and connection settings before
switching the live installation.

Files under `patches/` and [the archived guide](LEGACY_CORE_PATCH.md) are historical
migration references. Their successful application to an old revision does not
establish compatibility with the merged core. Do not apply the full patch twice
or apply the October 16 incremental patch to the October 19 merged source.

An automated upgrade from a customized, older patched host is outside this
release's fresh-install validation. Older tags retain their original instructions.

## Optional saved history

UnoPIM's database chat history is off by default. DreamLayer enables it during
provider boot unless `DREAMLAYER_PERSIST_CHAT_HISTORY=false`. The setting affects
all chat providers on the host, not just DreamLayer. Opting out leaves
`AI_AGENT_HISTORY_ENABLED` unchanged. Clear Laravel's configuration cache after
changing environment settings.

Disabling persistence keeps existing database conversations; it does not delete
them. Browser-local history can still be available. Migration rollbacks remove
added metadata, so a backup is required if downgrade recovery must preserve it.

## Verification

See [0.2.16 verification](VERIFICATION_0_2_16.md) for the exact tested source,
commands, outcomes, and live-provider limits. The repository workflow installs
this package on the pinned upstream source and checks installation, migrations,
extension regressions, core chat compatibility and JavaScript behavior.
