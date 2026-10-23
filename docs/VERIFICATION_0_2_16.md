# 0.2.16 verification

Validated October 20, 2026 (America/Toronto) against UnoPim source
`1abbb5bfa393f62b7d3d8496a3afea2a93df4084`, with `enshrined/svg-sanitize` 1.1.1
and `symfony/http-foundation` 8.1.9. See [host prerequisites](HOST_PREREQUISITE.md).
The supplied source download's Composer lock blob matches this upstream revision.
The GitHub workflow checks out that exact upstream commit independently.

## Automated checks

| Check | Result |
| --- | --- |
| Fresh Composer installation, no core patch | Pass |
| Extension PHP suite on PHP 8.4.26 / PostgreSQL 16 | 124 tests, 715 assertions |
| Extension JavaScript | 28 passed |
| Upstream chat/composer JavaScript | 17 passed |
| Real extension migration install/rollback/repair scenarios | 3 passed |
| Browser picker layout, mouse and keyboard | Pass |
| Browser connection labels and unauthorized state | Pass |
| Host Composer audit after the two targeted updates | No advisories or abandoned packages reported |
| Reproducible release archive against tracked commit | Pass |
| ZIP extracted and installed with Composer mirroring into a fresh database | Pass; provider boots, migrations and DreamLayer routes registered |

The [compatibility workflow](../.github/workflows/compatibility.yml) repeats these
checks on PRs and on `main`. Branch protection requires its successful result and
an up-to-date PR, including for the repository administrator. Initial successful
run: https://github.com/TheDesignFounder/unopim-dreamlayer/actions/runs/39520419985.

## Actual local application walkthrough

A fresh local host and separate PostgreSQL database were created from the
supplied UnoPim source. No existing installation's database or connection was
used. Browser acceptance passed in both **Images only** (another chat provider)
and **DreamLayer – Chat & Images** modes:

1. Sign in through the real UnoPim administrator login form.
2. Select a catalog image with the native chat's DreamLayer picker.
3. Submit an edit, process it through the real database queue and display its preview.
4. Confirm that previewing has not modified the product.
5. Submit a follow-up edit. Verify from saved source/result hashes that it uses
   the previous result rather than the original product image.
6. Apply the second result explicitly and verify that the product changes.
7. Reload and verify both previews and the applied state are restored.
8. Capture and inspect light and dark layouts; also capture a narrower viewport.

Both walkthroughs completed without browser JavaScript errors or external
browser requests. They use the real Laravel controllers, queue worker, database,
Vue chat and product-saving path. The chat model and image API responses are
**local fixtures**. The fixture image is deliberately synthetic, and its bytes
differ from the original image so source chaining is independently checkable.

## Compatibility correction found during validation

UnoPim's merged embedding implementation selects a configured native MagicAI
platform. The old DreamLayer override could therefore route managed-chat ranking
through another configured provider and return no matches. A regression test
failed with that provider present. The corrected implementation explicitly uses
the scoped DreamLayer endpoint/model during managed chat, restores configuration
on success or failure, and preserves native-provider behavior outside managed chat.
All three cases are covered by PHP tests.

## Limits and release positioning

This is a compatibility release for the pinned development-source baseline.
The validation does not establish support for every published UnoPim 3.x tag,
Windows execution, MySQL, or automatic upgrades from customized older patched hosts.
The PostgreSQL migration tests verify the extension's own install/upgrade paths;
they do not prove a complete older UnoPim installation upgrade.

No paid image calls or new live account authorization/revoke cycle were made in
this run. Before a production rollout, complete [new-user acceptance](../NEW_USER_TEST.md)
with the intended DreamLayer account and deployed API, including real image
quality, billing, connection/reconnection and the customer's permissions/schema.
The October 14 live evidence for 0.2.15 remains historical evidence, not a new
0.2.16 acceptance run. The GitHub release is marked as a prerelease for pilots.
