# Generic UnoPIM chat hooks: current review candidate

The full `patches/unopim-3x-chat-hooks.patch` contains only changes under
`packages/Webkul/AiAgent`. It adds provider/composer/renderer integration hooks,
request identity and additive history; DreamLayer-specific code stays in this
Composer package.

| Source | Revision |
| --- | --- |
| Preserved unpatched UnoPIM 3.x baseline | `614139f5b2267022e1a585d6653ac5cd50cf6faf` |
| Annotated review candidate | `23adf9d6e9c6bcde77570c75c72c8b235fa99a14` |
| Same functional core before this annotation pass | `3dd2e0be50a6ac0e8bd9cefd4756bbb8353d00fa` |

Start review at **`packages/Webkul/AiAgent/CHAT_EXTENSIONS.md`** in the patched
host. It maps each hook to its implementation, explains conversation/request/
message identities and compatibility, and gives the test commands. For the
package's complete account-to-Apply flow, read [REVIEWING.md](REVIEWING.md).

## Fresh host

Apply the full patch before Composer installs this extension:

```sh
git apply --check /path/to/extension/patches/unopim-3x-chat-hooks.patch
git apply /path/to/extension/patches/unopim-3x-chat-hooks.patch
```

Other downloaded revisions require normal compatibility review. The baseline
above is a local commit of preserved source. The patch also applies cleanly to
[unopim/unopim 3.x](https://github.com/unopim/unopim/tree/3.x) at upstream commit
`d29ffdcd7b26527dd6a06d24c90d3e787cbb9b00`, the submission base. Later revisions
still require `git apply --check`; compatibility with every 3.x revision is not assumed.

Run the four additive `2026_10_08_00000*` core migrations before serving requests.
They add message identity/presentation, per-admin client conversation identity,
request identity and bounded native display data. Existing rows remain readable.
No provider enum, native credential schema, vendor file or product persistence
implementation is patched.

## Host already using the hooks

Do not apply the full patch again. Hosts at `3dd2e0b` already have the current
functional hooks; this review pass adds comments, readable formatting and the
reviewer guide. Older incremental patches kept in `patches/` are historical,
base-specific upgrades. Apply only an increment matching that host, or reconcile
its changes normally. Never combine a full patch with an incremental one.

The package checks required classes and `COMPOSER_REFERENCES >= 2` at boot and
reports missing hooks. This is a capability check, not proof of compatibility
with an arbitrary modified host.

## Validation and limits

The annotation pass compares PHP executable tokens and parsed JavaScript against
the working pre-annotation source. Its native regression and composer suites are
listed in the review handoff, together with the clean-baseline patch check.
Older independent review at `3dd2e0b` remains historical evidence; it is not a new
independent review of this annotated candidate. The owner confirmed successful fresh installation, connection and image
generation on 2026-10-13. The 0.2.12 navigation-style regression passes 18 browser
scenarios; 0.2.13 adds Windows instructions without changing application behavior.
