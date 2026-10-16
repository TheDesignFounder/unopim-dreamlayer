# Generic UnoPIM chat hooks: split review candidate

The full `patches/unopim-3x-chat-hooks.patch` contains only generic changes under
`packages/Webkul/AiAgent`. DreamLayer-specific behavior stays in this extension.
This branch is an unreleased compatibility candidate, not a new package release.

| Source | Revision |
| --- | --- |
| UnoPIM 3.x base | `f5d9d663b027ab86ff707aae221bcf31079be47c` |
| Registries and decorators | `188f0e26ab198adb90b7982e5cb5db5c6f45f3ea` |
| Result cards and composer controls | `0894214f3611c13d4a1461cfb72aeb7c192915ff` |
| Optional saved history, complete candidate | `b406c71d04adbac8efd9ed02a0caea24a324f489` |
| Required hook capability | `COMPOSER_REFERENCES = 2` |

The [generic hook guide and screenshots](https://github.com/TheDesignFounder/unopim/blob/docs/chat-extension-guide/docs/chat-extensions/README.md)
are supplied separately for UnoPIM's documentation site. They are no longer
installed in the AiAgent package. For the extension's account-to-Apply flow,
read [REVIEWING.md](REVIEWING.md).

## Fresh review host

Back up an existing host and database. From the UnoPIM root, before installing
this extension with Composer:

```sh
git apply --check /path/to/extension/patches/unopim-3x-chat-hooks.patch
git apply /path/to/extension/patches/unopim-3x-chat-hooks.patch
php artisan migrate --no-interaction
php artisan optimize:clear
```

The full patch is checked against the exact base above. Other revisions still
require `git apply --check`; do not force a patch into a customized host.

Run all five additive migrations: the four `2026_10_08_00000*` migrations plus
`2026_10_14_000005_add_message_deletion_identity`. They add message presentation,
conversation and request identity, native display data and deletion markers.
A unique `(conversation_id, message_id)` index supports batched writes and
prevents duplicate identities. The fifth migration assigns UUIDs only to legacy conversations/messages missing
identities, in bounded chunks, so reopening and regenerating old chats use the
same merge/deletion contract. Existing content and non-null identities are preserved.
No provider enum, credential table or product persistence implementation changes.

## Host using the published 0.2.14 or 0.2.15 core patch

The original 0.2.14 core history is preserved on the fork's
[`archive/pr-770-before-split-0.2.14` branch](https://github.com/TheDesignFounder/unopim/tree/archive/pr-770-before-split-0.2.14),
including the earlier submission heads.

Published 0.2.14 and 0.2.15 contain the same core patch. For unmodified core hooks
at `c3fefa34ea96fefded6a880b523108dfe49540b8`, use only:

```sh
git apply --check /path/to/extension/patches/unopim-3x-chat-hooks-0.2.14-to-reviewed-history.patch
git apply /path/to/extension/patches/unopim-3x-chat-hooks-0.2.14-to-reviewed-history.patch
php artisan migrate --no-interaction
php artisan optimize:clear
```

This adds the fifth migration and removes the old in-package guide/screenshots.
For the 0.2.13 hooks (`5cbfd1f7`), first apply the retained
`unopim-3x-chat-hooks-0.2.13-to-0.2.14.patch`, then the update above.
Restart workers if OPcache does not check changed files. Check failures require
reconciliation against the exact installed revision; never apply the full patch twice.

## Optional database history

Core history is off by default. `AI_AGENT_HISTORY_ENABLED=true` enables it for a
host; an extension can also set `ai-agent.history.enabled` during provider boot.
DreamLayer opts in by default for saved conversations and image results. Set
`DREAMLAYER_PERSIST_CHAT_HISTORY=false` to leave the host's core setting unchanged.
This setting applies to all chat providers on the host, not only DreamLayer.
Clear configuration caches after changing environment settings.

When history is disabled, the widget uses browser storage and makes no history
HTTP calls; history endpoints reject requests. Existing database rows remain.
Regeneration sends explicit deletion IDs, and server tombstones prevent delayed
saves from another tab from restoring removed replies. Saves batch reads/writes
and preserve immutable message identity and monotonic confirmation state.

Rolling back the migrations removes the added metadata, including deletion
markers; restore a backup if that information must survive a downgrade. Do not
serve the new application against a rolled-back schema.

## Verification

The October 16 review update was checked with PHP 8.4.26, the dependencies pinned
in UnoPIM's lockfile, and separate PostgreSQL test databases:

| Review stage | AiAgent PHP suite | JavaScript | Package Pint |
| --- | --- | --- | --- |
| Registries and decorators (#770) | 304 passed, 829 assertions | No widget changes | 235 files pass |
| Result cards and composer controls (#775) | 320 passed, 865 assertions; one Node bridge skipped | 7 passed | 240 files pass |
| Optional history (#776), complete candidate | 331 passed, 938 assertions; two Node bridges skipped | 14 passed | 249 files pass |

The Node bridges skip because the PHP container has no Node; the JavaScript
suites run separately on the host. Scoped Larastan analysis checks the AiAgent
source; unmatched repository-wide ignore rules are disabled for that local scope.

All five migrations passed rollback and re-upgrade twice on an isolated
PostgreSQL database while preserving conversation/message content. Rollback
removes the added metadata; it is not a substitute for a backup.

DreamLayer compatibility passed all 122 PHP tests (707 assertions), including
history opt-in and reconnect concurrency checks, and all 28 JavaScript checks.
Both the fresh patch and the 0.2.14 incremental patch apply cleanly and reproduce
exactly the reviewed AiAgent tree `c93b551835637641cebdac035038f68d56dd894d`, with no
changes outside that package.

Disposable-host Chrome checks passed on October 16 for history off by default,
DreamLayer opt-in and opt-out behavior, picker keyboard controls, result cards in
both themes and after reload, regeneration with stale tabs and migrated legacy
conversations, native-provider fallback and the default console link. These checks
exercise real controllers, Vue and database history with local provider/API
fixtures. They do not constitute new live account-connection acceptance, a paid
image test or a production release.
