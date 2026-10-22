# Final customer acceptance test — DreamLayer 0.3.0

Use the distribution ZIP and SHA256 in the review bundle. Test this exact package;
do not install from the moving development branch. Record the UnoPim tag, PHP
version, ZIP checksum, date and tester below. The automated checks use simulated
DreamLayer responses. This final test confirms the real account, services and billing.

UnoPim version: ______  PHP: ______  ZIP SHA256: ______
Date / tester: ______  Result: PASS / FAIL

## 1. Install and connect

- Start with a clean, unmodified UnoPim 3.1.3 installation and a test product image.
- Install the ZIP using the included README. Composer should add DreamLayer
  without changing existing host packages. Run migrations and start the queue worker.
- Set `DREAMLAYER_CONNECTIONS_ENABLED=true`, clear the configuration cache, and
  open DreamLayer settings. Image editing, chat assistance and saved history start off.
- Leave chat off, get a connection code, and approve it with your DreamLayer account.
  After returning, the page should show Connected and enable image editing only.
- Create a preview from the test product. The original product image must stay unchanged.

Record the starting credit balance and the image request's charge: ______

## 2. Add chat assistance

- In the account connection section, turn on **Enable DreamLayer chat assistance**.
  The reconnect button should require the data-sharing acknowledgement.
- Read the disclosure, accept it, choose **Reconnect DreamLayer**, and approve
  the new code in DreamLayer. Return to UnoPim.
- Chat assistance should now be on. Saved history must still be off. On 3.1.3,
  the native-chat switch should be absent because that release lacks its hooks.
- Confirm the new installation has chat permission in DreamLayer connection
  management and the previous connection has been revoked.
- If chat is unavailable, record the displayed notice. Do not mark this test
  passed by changing the database or substituting a broader credential.

## 3. Edit, refine and save

- Select a product and ask for a blue background. Wait for its preview.
- Use **Refine this image** and ask for an outdoor parking scene. Confirm it starts
  from the previous result and retains the selected product destination.
- Remove the background from a result and check the downloaded PNG's transparency.
- Select two products, request the same change, and verify separate previews.
  Apply one result; only that product should change.
- Apply the chosen single-product result. Confirm the catalog image updates and
  normal product-update listeners (such as an installed webhook) receive the update.
- Try Apply again. It must not save or charge twice.
- Change a destination product image after generating another preview, then try
  to apply the stale preview. It must ask for a new preview instead of overwriting it.

Record request IDs, screenshot names and any issue: ______

## 4. Check pricing and stored history

- Record the image-credit balance before and after each completed image operation.
  Compare the charges with the account's applicable image pricing. Chat assistance
  must add no image-credit charge. Use billing records/request IDs to separate
  chat usage from the paid image work created by the same editor request.
- The service's chat limits response must confirm included chat and
  `image_credits_charged: false`. The extension checks this before enabling chat.
- With saved history off, new full-conversation records must not appear in
  `dreamlayer_editor_history`. Image job records still exist for recovery and Apply.
- Turn on **Save this editor's conversation history**, save settings, and make
  another edit. An administrator should find an encrypted message record in the
  UnoPim database. Turning it off stops new message records; existing ones remain.
- Confirm UnoPim's selected chat provider and its history setting stay unchanged.

Credit/billing evidence: ______  History check: ______

## 5. Finish the handoff

- Verify another user cannot open your private preview URL and a user without
  product-edit permission cannot create or apply previews.
- Open the editor and settings at a narrow mobile width and in the host's dark mode.
- Capture one real-service screenshot or short recording for the submission.
  The supplied review video demonstrates the actual editor with replayed images;
  it is not evidence of a paid generation or billing test.
- Keep the tested ZIP unchanged. Attach this completed checklist, the ZIP, current
  listing, review response and current screenshots when handing it to the UnoPim team.

If a defect requires a code change, rebuild and checksum a new candidate, then
rerun the affected checks. Do not reuse a pass recorded for a different ZIP.
Publishing a release tag and sending the submission remain separate actions;
this bundle has not been submitted or deployed.

## Older versions

The automated released-host matrix covers UnoPim 0.1.0, 1.0.1, 2.1.6 and 3.1.3.
For a live-service claim on an older version, repeat this checklist on a clean
installation of that exact tag. Use its original Composer lockfile and compatible
PHP runtime. Do not enable the native-chat adapter or patch core to force it to work.
