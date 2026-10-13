# Generic UnoPIM chat hooks: current review candidate

The full `patches/unopim-3x-chat-hooks.patch` contains only changes under
`packages/Webkul/AiAgent`. It adds provider/composer/renderer integration hooks,
request identity and additive history; DreamLayer-specific code stays in this
Composer package.

| Source | Revision |
| --- | --- |
| Upstream UnoPIM 3.x base | `d29ffdcd7b26527dd6a06d24c90d3e787cbb9b00` |
| Generic core candidate | `5cbfd1f7a95f257b34f2b152e9dbd7f3dc7b34af` (rebased onto the 3.x base above) |
| Included hook capability | `COMPOSER_REFERENCES = 2` |


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

Other downloaded revisions require normal compatibility review. The patch applies cleanly to
[unopim/unopim 3.x](https://github.com/unopim/unopim/tree/3.x) at upstream commit
`d29ffdcd7b26527dd6a06d24c90d3e787cbb9b00`, the submission base. Later revisions
still require `git apply --check`; compatibility with every 3.x revision is not assumed.

Run the four additive `2026_10_08_00000*` core migrations before serving requests.
They add message identity/presentation, per-admin client conversation identity,
request identity and bounded native display data. Existing rows remain readable.
No provider enum, native credential schema, vendor file or product persistence
implementation is patched.

## Host already using the hooks

Do not apply the full patch again. Compare the installed hooks with the bundled
patch and reconcile changes against the exact installed version. Historical
incremental patches are not included in this public release. The generic core
change is a separate upstream proposal. This release still requires the bundled
patch until compatible hooks are included in your installed UnoPIM version.

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
