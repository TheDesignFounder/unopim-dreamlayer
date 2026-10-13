# Fresh installation and chat acceptance

Use a new UnoPIM checkout and a separate database/storage location. Keep the
currently working installation available until this test passes. This guide is
for the **0.2.13 local review candidate**; it supersedes older rehearsal instructions
for 0.2.3–0.2.12. The owner completed the installation, connection and generation flow on macOS/Docker using runtime 0.2.12. This checklist remains available for reviewers; Windows execution is not yet verified. See [Windows setup](WINDOWS_INSTALL.md).

## Record the starting point

Record the downloaded UnoPIM revision, package version and archive checksum. The
full generic patch targets the preserved 3.x baseline `614139f5b2267022e1a585d6653ac5cd50cf6faf`.
A newly downloaded revision may differ: `git apply --check` must pass before
application. If it does not, keep the error and reconcile the source revision;
do not force a partial patch or apply the full patch twice.

First establish that UnoPIM itself installs, accepts an admin login and displays
a product with an image. Use the host's supported PHP/database requirements.
This distinguishes a host installation problem from an extension problem.

Follow the package [README installation steps](../README.md#install) to apply
the core patch, install the package with Composer, migrate, clear caches and
start the supervised worker and scheduler. Laravel discovers the provider;
manual registration is unnecessary. Confirm:

- Composer reports `dreamlayer/unopim-dreamlayer` version `0.2.13`.
- Migrations finish successfully, including the nullable workflow `error_code`.
- `php artisan schedule:list` includes `dreamlayer:recover` every minute.
- The worker consumes connection/queue `dreamlayer`, with automatic restart after
  any exit. It and the web process share the same database and private storage.
- The admin menu shows **DreamLayer** and **My image results**, with intact icons.

Open DreamLayer from the catalog sidebar before refreshing the page. Its spacing,
status chip and compact buttons must already be styled. Visit My image results,
its Open chat entry and browser Back; styles must survive each navigation in
both light and dark themes. A direct URL load alone does not test UnoPIM's AJAX
navigation path.

Configure both endpoints for the same DreamLayer environment before connecting.
For the existing local DreamLayer services, the browser account console is
`http://localhost:8102`; a Docker-based UnoPIM reaches its API using
`http://host.docker.internal:8101/v1`. Local HTTP requires
`DREAMLAYER_ALLOW_LOCAL_HTTP=true` and `APP_ENV=local` or `testing`. Set
`DREAMLAYER_CONNECTIONS_ENABLED=true` when that API supports account connections.
Use the new UnoPIM's own `APP_URL` and port for return links; do not hardcode the
older test installation's address.

## Connect and reach chat

1. Open `/admin/dreamlayer/settings` on the new UnoPIM (adjust `/admin` if configured
   differently). Expect **Not Connected**, an automatic code and its countdown.
2. Press **Copy**. Expect **Copied**, then the normal label again. Confirm the
   clipboard matches the displayed code. **Generate new code** should replace it;
   refreshing the page should reuse a still-valid code.
3. Choose **Open DreamLayer**, sign in or create an account, and enter the code.
   Typed input must survive the console finishing its initial load.
4. Review the installation name, URL and requested access. Press **Approve
   connection** once. Return to UnoPIM. Approval may briefly show **Waiting for
   connection**, then **Connected**, the correct account and its actual balance.
   A pending, unapproved code must not display the waiting state.
5. Choose **Open chat**, or **Enable and open chat** if chat is disabled and your
   admin has configuration permission. The chat should open immediately with
   **DreamLayer – Chat & Images** selected and no redundant model selector.

An existing account does not receive a new trial for reinstalling/reconnecting.
Do not reset balances or credentials to simulate eligibility. The displayed
balance must agree with that account's DreamLayer API balance.

## Select, preview, and Apply

1. Press **Select Product Image**, or type `@`, and search for a product. Select
   its thumbnail using the mouse; repeat with arrows and Enter to check the
   keyboard flow. Selecting must not send the prompt prematurely.
2. Send one clear, single-step edit with one selected image. Record the request
   and result IDs. Expect queued/running state and expanded detailed steps when
   supported. Closing/reopening chat must preserve the same work.
3. Wait for a completed preview. Inspect and download it. The catalog image must
   remain unchanged up to this point.
4. Press **Apply to product** once. Verify the intended product, attribute and
   locale/channel changed, and another product did not. Repeating Apply should
   not generate or charge for another image.
5. Refresh, reopen the conversation and open **My image results**. The result,
   download and applied state should still be available.

A successful image edit consumes credits according to the actual selected
operation(s). If the DreamLayer account has no image credits, expect **You're out
of image credits. Add credits to continue.** and an **Add credits** button that
opens `/console/billing` for the configured account site in another tab. The
button must not resubmit work. Local checkout may be disabled; record that
separately from whether the link works. Do not repeatedly submit an uncertain
request; inspect its saved status and worker logs first.

## Record the result before PR submission

| Check | Result (PASS / FAIL / NOT RUN) | Evidence |
| --- | --- | --- |
| Fresh host and package installation | | Revision, version, migration output |
| Code copy/regeneration and account approval | | Screenshots without credentials |
| Connection status and Open chat | | Observed transition and account |
| Picker mouse/keyboard and selected model | | Product/SKU and screenshot |
| One preview, download and explicit Apply | | Workflow ID, destination and balance change |
| Reloaded history and saved results | | Same workflow and applied state |
| Insufficient-credit message and billing link, if exercised | | Error code and destination |

Record failures with their exact steps and logs, excluding keys, cookies and
tokens. Automated regression results are listed in the review handoff; they do
not substitute for this fresh-install run. Submit the PR only after the owner
has completed this acceptance and authorized submission.
