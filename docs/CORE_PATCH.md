# Generic UnoPIM chat hooks: split review candidate

The full `patches/unopim-3x-chat-hooks.patch` contains only generic changes under
`packages/Webkul/AiAgent`. DreamLayer-specific behavior stays in this extension.
This branch is an unreleased compatibility candidate, not a new package release.

| Source | Revision |
| --- | --- |
| UnoPIM 3.x base | `f5d9d663b027ab86ff707aae221bcf31079be47c` |
| Registries and decorators | `d936e80d163da8b8934d9972b580ec9dafa395ac` |
| Result cards and composer controls | `73f519944f36f04a4b7fd2d7824e13afbecf55d5` |
| Optional saved history, complete candidate | `dec819a5a7ba7872aba2f3b202d0248a8fc32dad` |
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

## Host using the published 0.2.14 core patch

The original 0.2.14 core history is preserved on the fork's
[`archive/pr-770-before-split-0.2.14` branch](https://github.com/TheDesignFounder/unopim/tree/archive/pr-770-before-split-0.2.14),
including the earlier submission heads.

For unmodified core hooks at `c3fefa34ea96fefded6a880b523108dfe49540b8`, use only:

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

On the complete core candidate, the AiAgent suite passed 297 PHP tests (835
assertions); two Node bridges skipped because the PHP container has no Node.
All 14 JavaScript tests passed separately. The registries-only stage passed 284
PHP tests. Before the comment-only cleanup, result cards passed 287 PHP tests;
the cleanup reran four focused PHP tests (17 assertions) and all seven composer
JavaScript tests, with unchanged non-comment widget source.
All five migrations passed upgrade, rollback and re-upgrade checks on an isolated
PostgreSQL database while preserving legacy conversation/message content.

DreamLayer compatibility passed all 122 PHP tests (707 assertions), including
its history opt-in and reconnect concurrency checks; all 28 extension JavaScript
checks passed. Clean-install patches on both the current 3.x and original
submission bases, plus the 0.2.14 incremental upgrade, produce the exact reviewed
AiAgent tree.

These checks use the dependencies pinned in UnoPIM's lockfile. Browser acceptance
and paid edits are separate release evidence and were not repeated for this change.
