# Final customer acceptance test — DreamLayer 0.3.0

Test the exact ZIP and SHA256 supplied in the review bundle. Keep that ZIP
unchanged after testing. The automated service tests use simulated responses;
this walkthrough checks the real connection, image service and billing.

UnoPim version: ______  PHP: ______  ZIP SHA256: ______
Date / tester: ______  Result: PASS / FAIL

## Before starting

For the prepared local test, open
[DreamLayer settings](http://127.0.0.1:8192/admin/dreamlayer/settings).
Port 8192 is the released UnoPim 3.1.3 installation. Port 8193 is a separate
3.x development installation; start with 8192.

The local installation, migrations, queue workers and two sample products are
already prepared. You do not need to reinstall it. The sample SKUs are
`DL-TEST-RED` and `DL-TEST-BLUE`. Sign in with the local UnoPim login if prompted;
that login is separate from your DreamLayer account.

If you are testing elsewhere, first follow the README installation instructions
on a clean, unmodified UnoPim 3.1.3 release, enable account connections, run
migrations and start the queue worker. Create two disposable test products with
images. Use their SKUs instead of the sample SKUs below.

Refresh the settings page to load the latest UI. If an unsaved-changes warning
appears, finish saving your intended changes or choose **Discard** and confirm
**Discard** to abandon that unsaved draft before starting.

The page separates account connection from saved editor settings:

- **Connect DreamLayer → Include chat assistance in this connection** requests
  chat permission when you approve the connection in DreamLayer. Its checkbox
  acknowledges data sharing for that connection request.
- **Editor settings → Enable DreamLayer chat assistance** controls whether the
  editor uses chat assistance. Its acknowledgement records consent when you
  click **Save changes**; it does not connect the account.
- **What data is shared** expands in place within either section. Read it without
  leaving the page or losing your current choices.

The **DreamLayer image editor** explanation describes the workspace. Its smaller
**Open image editor** link appears when an account is connected and image editing
is enabled. It is where you select product images, create and refine previews,
and choose what gets applied. **My image results** is the saved-preview library.

New image previews use real DreamLayer credits. Record the starting balance and
the time of each preview so its usage can be checked later.

To test history being off and then on, turn **Save this editor’s conversation
history** off before section 1 if you have already enabled it. Click **Save
changes** in the bottom bar. Existing stored messages are retained. Section 6
turns this setting back on.

## 1. Connect with image editing only

If you already see **Connection status: Connected**, do not disconnect just to
follow this section. Record whether chat is already enabled, then continue with
the remaining checks. Record a skipped first-connection test as skipped.

1. In **Connect DreamLayer**, leave **Include chat assistance in this connection** off.
2. Click **Get connection code**. A **Your connection code** box should appear.
3. Click **Copy** beside the code.
4. Click **Open DreamLayer**. A new browser tab opens. Sign in to your DreamLayer account if asked.
5. In DreamLayer, paste the code into **Connection code** and click **Review connection**.
6. Confirm the installation URL matches your test site, then click **Approve connection**.
7. Wait for **Connection approved**, then switch back to the original UnoPim tab. The **Return to UnoPIM** button is also available if you need it.
8. Wait for **Connection status: Connected**. The page updates automatically. Image editing should now be on; chat assistance should still be off.
9. Record **Available credits**. If you need to inspect the balance in DreamLayer, use **Manage billing** near the bottom of the settings page.

Leave **Advanced: use an API key** collapsed throughout this test. The connection
flow supplies the credential; you do not need to paste an API key.

Connection result / starting credits: ______

## 2. Create a preview without changing the catalog

1. Near the top of settings, click **Open image editor**.
2. In **Search by SKU**, type `DL-TEST-RED`, then click **Search**.
3. Click the product image itself. A check mark should appear.
4. In **Image task**, choose **Edit image** if it is not already selected.
5. In **Describe the change**, type: `Give it a plain blue background. Keep the product unchanged.`
6. Click **Create preview** once. Wait for the image and **Edits complete**. A queued or processing message is normal while the job runs.
7. Leave **Apply to product** alone for now. The preview must not replace the catalog image automatically.
8. Open **Catalog → Products** in a separate browser tab, search for `DL-TEST-RED`, and open that product. Confirm its original image remains. Keep the editor tab open.
9. Capture a screenshot of the preview and record the time. Keep the product tab for later comparison.

If **Add credits** appears, open it to review the account balance and pricing
before deciding whether to fund the test. If a request fails, record its visible
error and time instead of repeatedly submitting the same edit.

Preview result / time / screenshot: ______

## 3. Reconnect with chat assistance

1. In the editor, click **DreamLayer** beside the page title to return to settings.
2. Under **Connect DreamLayer**, turn on **Include chat assistance in this connection**.
3. In the shaded area that appears, click **What data is shared** and read the explanation. Tick **I have read the data-sharing information and agree to enable optional chat assistance.** in that same area.
4. Click **Reconnect DreamLayer**.
5. Click **Copy**, then **Open DreamLayer**.
6. Paste this new code into **Connection code**, click **Review connection**, review the requested chat access, then click **Approve connection**.
7. Switch back to the original UnoPim settings tab and wait for **Connected**.
8. Check **Editor settings**: **Enable image editing** and **Enable DreamLayer chat assistance** should both be on. **Save this editor’s conversation history** should remain off if you turned it off before starting; reconnect should preserve that choice.

The **Include chat assistance in this connection** switch is a choice for the
next connection request; it resets after connection. Use **Editor settings** to check whether
chat assistance is currently enabled. On released UnoPim 3.1.3, a native UnoPim
chat switch should not appear; the standalone editor remains available.

If chat is reported unavailable, capture the notice. Do not substitute a broader
API key or modify the database to make this test pass.

Chat reconnect result: ______

## 4. Refine a previous result and save your chosen version

Returning to settings starts a fresh editor page, so create a new first preview
here to test a complete conversation with chat assistance on.

1. Click **Open image editor**. Search `DL-TEST-RED` and click its image.
2. Leave **Image task** on **Edit image**. Enter `Give it a plain blue background. Keep the product unchanged.` and click **Create preview**.
3. Wait for **Edits complete**. Click **Refine this image** beneath that preview.
4. In **Describe the change**, enter `Now place the same product on a light stone surface outdoors. Keep its shape and color unchanged.`
5. Click **Create preview** and wait. Confirm a second preview appears in the same conversation and still targets `DL-TEST-RED`.
6. Click **Refine this image** beneath the second preview.
7. In **Image task**, select **Remove background**. In **Describe the change**, type `Remove the background from this result.`
8. Click **Create preview** and wait for the cutout preview.
9. Click **Download PNG** on that result. Open the download and inspect its transparent background.
10. On the version you want to keep, confirm the SKU is `DL-TEST-RED`, then click **Apply to product** once.
11. Wait for the button to change to **Applied to product**. It should be disabled so you cannot apply it a second time.
12. Refresh the product’s **Catalog → Products** tab. Confirm its image now matches the chosen preview.
13. In the editor, click **My image results**. Confirm the generated previews are listed and the chosen result shows its applied status.

Follow-up / download / Apply result: ______

## 5. Edit two products and approve only one

1. From **My image results**, click **Open image editor**. If you are already in an existing editor conversation, click **New conversation**.
2. Enter `DL-TEST` in **Search by SKU**, then click **Search**.
3. Click the image for `DL-TEST-RED` and the image for `DL-TEST-BLUE`. Confirm **2 images selected**.
4. Set **Image task** to **Edit image**.
5. In **Describe the change**, enter `Give each product a plain light gray background. Keep each product unchanged.`
6. Click **Create preview** once. Wait for two separate result cards; they may finish one after the other.
7. Check the SKU on each card. Click **Apply to product** only on the `DL-TEST-BLUE` card. Leave the red product’s result unapplied.
8. In **Catalog → Products**, inspect both products. Blue should have the newly approved image. Red should retain the image you saved in section 4.

Two-product result: ______

## 6. Enable saved history and check the balance

1. Click **DreamLayer** to return to settings.
2. Under **Editor settings**, leave **Enable image editing** and **Enable DreamLayer chat assistance** on.
3. Turn on **Save this editor’s conversation history**.
4. Click **Save changes** in the bottom bar. On hosts that show an inline button instead, click **Save settings**.
5. Refresh settings and confirm the history switch remains on.
6. Click **Open image editor**, search for `DL-TEST-RED`, select its image, and create one more preview with a short instruction such as `Make the background a lighter shade.` Do not apply it unless you want that catalog change.
7. Record the time and screenshot. History saving currently keeps an encrypted record for administrator review; it does not add a conversation-reopen button to this page.
8. Return to settings and record **Available credits**. Click **Manage billing** to open DreamLayer’s **Billing** page and compare the **Available balance**.
9. In DreamLayer, click **Usage** and capture the relevant entries. Each image preview can incur an image-processing charge. Chat assistance should add no image-credit charge; the same editor action may include both chat assistance and paid image processing.

Exact request-level charges and encrypted history storage are technical checks
listed below. A changed account balance alone does not prove which operation
was charged.

History setting / balance / usage screenshots: ______

## Technical checks to accompany the click-through

These require administrator or service-side evidence; there is no button in this
editor that proves them. Record them separately from the visible UI test.

- Verify the new connection has `chat:use` and the previous connection is revoked.
- Verify the chat limits response confirms included chat and `image_credits_charged: false`.
- Match image request IDs with billing records and applicable account pricing. Confirm chat adds no image-credit charge and Apply does not charge a second time.
- With saved history off, new full-conversation records must not appear in `dreamlayer_editor_history`. With it on, verify encrypted message records. Turning history off stops new message records and retains existing records.
- Confirm UnoPim’s selected chat provider and global history setting remain unchanged.
- Verify Apply fires the normal product-update events and installed listeners receive them.
- Test a stale preview: change its destination product image after generation, then try **Apply to product**. Expect a conflict asking for a new preview.
- Verify another user cannot open a private preview and a user without product-edit permission cannot create or apply previews.
- Verify settings and editor navigation at mobile width and in dark mode.

Technical evidence / result: ______

## Complete the handoff

Keep the tested ZIP unchanged and record its checksum. Include this completed
checklist, the review response, listing, current screenshots and a short recording
from the real-service test. The supplied review video uses replayed results and
is not evidence of live generation or billing.

If a defect requires a code change, rebuild and checksum a new candidate, then
rerun the affected checks. Do not reuse a pass recorded for a different ZIP.
Publishing a release and sending the submission are separate actions; this
bundle has not been submitted or deployed.

## Older versions

The earlier automated released-host matrix covers UnoPim 0.1.0, 1.0.1, 2.1.6 and
3.1.3. For a live-service claim on an older version, repeat this checklist on a
clean installation of that exact tag with its original Composer lockfile and
compatible PHP runtime. Do not patch core or force the native-chat adapter on.
