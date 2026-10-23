# DreamLayer 0.5.1 — acceptance test

Use a review copy of UnoPim with two disposable products, `DL-TEST-RED` and
`DL-TEST-BLUE`, each with an image. Record the ZIP SHA-256 and UnoPim version.
Real preview requests use image credits. Local fixture tests do not establish
live image quality or live billing. Keep the original 0.3.0 report as evidence;
this interface and its saved-history features require their own acceptance run.

## 1. Connection and settings

1. Open `http://127.0.0.1:8192/admin/dreamlayer/settings`. Save or discard any
   pending settings edits before continuing.
2. Confirm the page separates **DreamLayer image editor**, **Connect DreamLayer**,
   and **Editor settings** into bordered sections.
3. If rerunning onboarding, leave the chat switch in **Connect DreamLayer** off.
   Click **Get connection code**, **Copy**, then **Open DreamLayer**.
4. In DreamLayer, paste the code into **Connection code**. Click **Review
   connection**, verify the local installation URL, then **Approve connection**.
5. Return to UnoPim. Wait for **Connection status: Connected**. Confirm image
   editing is enabled. Do not replace the API key manually.
6. To test chat opt-in, turn on the chat switch in **Connect DreamLayer**. Read
   its disclosure, turn on its acknowledgement switch, then click **Reconnect
   DreamLayer**. Repeat Copy → Open DreamLayer → Review connection → Approve.
7. Confirm chat assistance is on under **Editor settings**. Reconnect must not
   change the saved-history preference or UnoPim’s native provider setting.
8. Click **What data is shared**. It must expand in place without a page jump,
   losing unsaved edits, or changing the URL. Close it again.

## 2. First image and the task-selector regression

9. Click **Open image editor** in settings. The expanded workspace should show
   DreamLayer branding, History, New conversation (+), expand/collapse and Close.
   There is no canvas.
10. Click **Add product images**. In the picker, enter `DL-TEST-RED` in **Search by
    name or SKU**, then click **Search**.
11. Click the product thumbnail. Confirm its selection checkmark and **1 selected**.
    Click **Use selected images**. Confirm the product chip above the message box.
12. Change **Image task** to **Remove background**, then **Generate image**, then
    back to **Edit image**. Enter: `Give it a plain blue background. Keep the
    product unchanged.` Click **Create preview** once.
13. Confirm a queued/processing card becomes **Complete** and shows the preview.
    There must be no “selected operation is invalid” error. Confirm the result
    identifies `DL-TEST-RED`, the image attribute, channel and locale.
14. Do not apply yet. Open the product under Catalog → Products in another tab.
    Confirm its catalog image is still unchanged.
15. Under the preview, click **Refine this image**. Change **Image task** to
    **Remove background**. Leave the message empty and click **Create preview**.
16. Wait for **Complete**. Click **Download PNG** on the cutout. Open the file in
    an image viewer with a transparency checkerboard and confirm transparent
    pixels. The catalog must still be unchanged.
17. Click **Refine this image** on your preferred completed version. Select
    **Edit image**, enter `Place the same product on a light stone surface
    outdoors. Keep its shape and color unchanged.`, then **Create preview**.
18. After completion, click **Apply to product** on the version you want to keep.
    Wait for **Applied to product**. Refresh its catalog tab and confirm that
    exact version is saved. Apply must not charge another image credit.

## 3. Persistent side chat and layout

19. Type `Draft for my next edit` without sending. Click **Settings** in the
    editor footer. The compact DreamLayer chat should stay open on the right.
20. Use UnoPim navigation to open Catalog → Products, then Dashboard. The draft,
    selected preview and conversation must remain. Other admin controls must
    remain usable while the side chat is open.
21. Click **Expand editor** in the chat header. Confirm the same draft and
    previews appear in the expanded editor. Click **Collapse editor** to return.
22. Click **Close** in the compact chat, then click the floating **DreamLayer**
    button to reopen it. Confirm nothing was submitted or lost.
23. Refresh the page. Confirm this tab’s draft and active conversation restore.
24. On a product edit page, open the product picker (+) and click **Use this
    product’s images**. Confirm it filters to that product. Navigating to another
    product must not silently replace an already attached image or Apply target.
25. Repeat key views in light/dark mode and at 390 × 844 and 1440 × 1000. Check
    composer visibility, drawer scrolling, selected-image chips and buttons.
    Press Escape to close a picker or History. Use Tab and Shift+Tab in dialogs.

## 4. Generation and two products

26. Click **New conversation** (+ in the chat header). Choose **Generate image**
    without attaching a product. Enter `A blue shoe on a white background` and
    click **Create preview**. Confirm the completed image offers **Download PNG**
    and no **Apply to product** button.
27. Start **New conversation** again. Open **Add product images**, search
    `DL-TEST`, select the RED and BLUE thumbnails, and click **Use selected images**.
28. Select **Edit image**, enter `Give each product a plain light gray background.
    Keep each product unchanged.`, then click **Create preview** once.
29. Confirm two distinct result cards identify the correct products. Click
    **Apply to product** only on BLUE. Confirm BLUE changes and RED keeps the
    version saved in step 18.

## 5. Saved and temporary conversations

30. Close the side chat if it covers settings. In DreamLayer settings → Editor
    settings, turn **Save this editor’s conversation history** on and click
    **Save changes**. Reopen DreamLayer. The composer should not repeat history or catalog-approval footnotes; the setting remains visible in Settings.
31. Start a new conversation and make one preview. Click **History** → **Chats**.
    Confirm the thread appears under its date and is marked **Saved**.
32. Click its star to bookmark it. Open **Bookmarks** and confirm the thread is
    present. Use its Rename (pencil) button, enter `Acceptance test`, and click
    **Save title**.
33. Open a separate authenticated browser tab, open DreamLayer, then History →
    Chats → `Acceptance test`. Confirm saved messages and results reopen. Refine
    a completed result and confirm it uses that result as its source.
34. Turn saved history off in settings and save. Open DreamLayer, start a new
    conversation, and create a preview. History must mark it **Current tab · not
    saved**. Existing saved chats must remain available.
35. Refresh that tab: the temporary chat should restore. In a new browser tab,
    verify that temporary messages are absent from saved Chats. Its image preview
    is still available under **Images**, since image jobs are stored separately.
36. In History, click Delete (×) on `Acceptance test`, then confirm **Delete**.
    Confirm its saved messages and bookmark disappear. **Images** must retain
    the previews, and the catalog must stay unchanged. Reviewing an image from
    a deleted conversation allows Download/Apply but disables further messages.
37. Log out. Sign in as a different authorized user in that tab. The previous
    user’s conversations, drafts, images, and bookmarks must not appear.

## 6. Permissions, recovery and billing

38. With a second full administrator, request another user’s thread, preview,
    image download, rename, delete and Apply URLs. They must return 404 and must
    not reveal the other user’s data. The second user’s lists must be independent.
39. With a role granted DreamLayer permissions but no Catalog → Products → Edit,
    request studio/history/catalog/Apply routes. They must return 403.
40. Simulate a lost response to a preview submission and retry the same request.
    Confirm one workflow/charge, not two. A changed request with the same request
    ID must return 409.
41. Change a catalog image after creating a preview, then try Apply on the stale
    preview. Confirm it is refused and the newer catalog image remains.
42. With History off, verify no new `dreamlayer_editor_history` or thread message
    rows. With History on, confirm message text and titles are encrypted. Delete
    must remove messages; a delayed completion must not restore them.
43. Record Available credits in settings, then inspect DreamLayer Billing/Usage.
    Compare successful image requests with the account’s actual price. Verify no
    additional image-credit charge for chat rewriting, Apply, or validation failures.
44. Expand **Request details** on results. Record the local request/workflow and
    DreamLayer workflow IDs. **Usage execution IDs**, when supplied by the server,
    match full execution IDs in the updated Usage page. Older deployed servers
    may omit this new field; report exact attribution as pending in that case.
45. Independently verify the service-side chat grant/limits and no unauthorized
    native provider/history changes. Record any unrun checks explicitly.

## 7. Regression checks for 0.5.0

46. Use **@** to open catalog selection. Search by car name and by `ABLO-00`.
    The local acceptance catalog contains 284 car images. Select two different
    cars, submit once, confirm two destination labels, and apply only one.
47. Test a completed PNG larger than 2 MB against the host's default image
    attribute. Inspect the catalog-preview notice. Hash the displayed preview,
    apply it, and verify the saved catalog file matches. Download original PNG
    and verify the original resolution and bytes were retained. Repeat with a
    transparent result. Do not raise the host limit to make the test pass.
48. Start another edit, wait for completion, and click **Refine this image** on
    an earlier result without reloading. Confirm it is enabled and the selected
    result is actually used. Apply a stale result and verify the actual reason
    appears beside that result. Simulate 422 and network failure separately.
49. Dock chat beside a settings or product edit page. Change a field. Check that
    Save changes and Discard remain visible and clickable. Confirm UnoPim's
    Discard dialog appears above the chat and completing/cancelling it behaves
    correctly. Repeat with narrow layouts and 200% zoom.
50. Test file selection, clipboard paste and drag/drop with PNG/JPEG/WebP;
    invalid files and multi-file input must show a useful message. An uploaded
    image produces a downloadable preview with no automatic product destination.
    Verify another user cannot view or reuse the upload token. Do not expose
    private upload links in a public report.
51. Use Copy, full-image viewing, Helpful/Not helpful and Try again. Try again
    should populate the composer with the original source/instruction and make
    no paid request until Create preview. Refinement uses the previous result.
52. Scroll to older messages during processing. Polling must not pull you down.
    Latest messages must return to the newest result. Elapsed/completion time
    must remain stable after Apply or feedback.
53. Test all eight combinations of image editing, chat assistance and saved
    history through the actual settings form. Provide consent when enabling
    assistance. Confirm edits are blocked when editing is off, assistant
    rewriting is absent when assistance is off, and new message rows are absent
    when history is off. Existing saved history must remain readable with all
    three switches off. Preserve the initial settings after testing.
54. Test a custom editor role without settings or native AI permissions. Grant
    only the image workflow permissions needed for the scenario; verify actions
    outside those grants are rejected. Test optional upload and feedback grants
    independently. Do not broaden existing user roles merely for convenience.
55. Log out with chat open. Verify the login screen has no lingering chat panel,
    private tab state is cleared, and login returns to the host interface rather
    than a bare iframe page.
56. For one fresh paid edit, record UTC timestamps at submit, direct admission,
    worker start, provider completion, download and visible completion. New
    single-image jobs should use `execute_v1`; old jobs must keep their original
    identifiers. Confirm there is no scheduled wrapper delay. Record real
    duration rather than extrapolating from fixture tests. At least two image
    credits are needed for a two-product request; never top up without approval.

## 8. Saved multi-product history regression (0.5.1)

57. In a fresh authenticated browser context with no previous tab storage, open
    History → Chats → **Acceptance test 0.5.0** on the existing review site.
    Both ABLO-001 and ABLO-002 result cards must load beneath the saved request,
    in their original selection order. The false “History off” note must be gone.
    Repeat for the older two-product thread (conversation prefix `05326233`).
    These checks need no new image request or catalog write.
58. Repeat with all three editor switches off. Messages, images and downloads
    must remain accessible; new edits remain disabled. Check a single-image
    saved thread too. Preserve the starting settings.
59. With editing and saved history enabled, submit a new two-product request.
    Close the tab and reopen the saved thread in a fresh authenticated context.
    Verify both destinations, preview images and download links. Refine one
    reopened result; verify its workflow ID is the selected source and both the
    original batch and follow-up reopen together. Distinguish fixture runs from
    paid provider calls in the report.
60. For a conversation containing both saved and unsaved requests, keep the
    actual unsaved-history warning and show only saved messages on a fresh
    context. Verify another user's or another conversation's results are not
    attached. Deleting a conversation must still prevent reopening it.

## Evidence and result

Attach exact ZIP checksum, host versions, screenshots, request/status receipts,
permission results, billing evidence and any failures. Do not report fixture
image responses as live AI verification. Do not reuse a pass for a different ZIP.
