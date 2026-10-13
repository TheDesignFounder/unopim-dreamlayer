# New-user acceptance and reconnect/revoke check

Use a fresh UnoPIM checkout with a separate database and storage. Follow the
[README](README.md#install), or [Windows/WSL guide](docs/WINDOWS_INSTALL.md), using
the public repository and tag `0.2.13`. Record both Git commits and the environment.

Complete [the installation, connection and picker checklist](docs/FRESH_INSTALL_TEST.md).
The optional image edit/Apply section spends image credits; record separately
whether it was exercised in this run or covered by earlier accepted runtime
evidence. Do not report earlier evidence as a new provider call. Check sidebar navigation in both themes
before refreshing: spacing, status chip, buttons and the double-diamond icons
must be correct. Confirm Copy becomes Copied, Chat & Images is the default,
Select Product Image opens the picker, and progress details start expanded.

## Reconnect and revoke

Use an isolated review installation. Never revoke another installation's key.

1. Connect a DreamLayer review account. Record the installation/connection ID
   shown in its account console and its current credit balance. Do not record
   the raw credential in screenshots, logs or the repository.
2. In UnoPIM's Account settings choose Connect another account, obtain a new
   code, and approve it using the same review account. Wait for Connected.
3. Verify the new connection has a different ID and can read the account and
   balance. Verify the previous installation credential is revoked in DreamLayer.
   With a private test harness, a request using the old credential must receive
   HTTP 401. Reconnecting must not issue another promotional trial.
4. From DreamLayer's installation table, revoke the new connection. Refresh or
   return focus to UnoPIM. It must show the unauthorized/disconnected state and
   offer connection recovery, without exposing a credential or submitting work.
5. Reconnect once more and confirm Open chat works. Disconnect from UnoPIM and
   verify this installation is revoked remotely. The explicit local-only forget
   action is a different recovery operation and must not be used for this check.

If old-key cleanup fails, the new working connection must remain saved and a
cleanup notice must be visible. Record failure; do not mark reconnect passed.

## Record evidence

| Check | Result | Evidence |
| --- | --- | --- |
| Clean clone and install from public tag | | Commit, Composer/migration output |
| Sidebar style and both themes | | Browser assertions or screenshots |
| Code, approval, default chat and picker | | Observed UI states |
| Preview, explicit Apply and saved history | | Workflow and destination, no secrets |
| Reconnect replaces and revokes old credential | | Different IDs, old key HTTP 401 |
| Remote revoke detected; local disconnect revokes | | Status responses and UI |
| Credit balance unchanged by reconnect | | Before/after balance |

Keep credentials and database snapshots outside the Git repository. Mark real
service checks separately from mocked automated tests. The owner accepted the
0.2.12 installation/connection/generation flow on macOS on 2026-10-13; the public
release must additionally record the reconnect/revoke and public-clone checks.
Windows instructions are included, but Windows execution is not yet verified.
