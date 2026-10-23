# DreamLayer UnoPim 0.5.0 local verification

Date: 2026-10-23. Candidate is unpublished. No merge or production deployment.

## Changes

- Oversized results are fitted to the destination attribute limit as an explicitly displayed catalog preview. Apply saves the approved bytes; original PNG downloads and refinement sources retain full resolution. Host upload limits are unchanged.
- Docked chat reserves space for the host Save/Discard bar. Earlier Refine buttons become available after submission; Apply displays its actual failure reason. Logout removes the panel, clears its private tab state and rotates the session identifier to prevent an in-flight poll from reviving it. Direct studio navigation returns to the host shell.
- The supplied DreamLayer PNG is used for the launcher, header and sidebar. Expanded layout has no surrounding outline or canvas. Composer history/catalog footnotes are removed.
- Catalog selection via @, search by SKU/name, up to five catalog images, single image upload/paste/drop, task shortcuts, instruction copy, full-image view, local feedback, original-source retry, controlled scrolling, and elapsed time are available.
- Permissions remain explicit. Optional image uploads and feedback have separate ACL grants. Settings/native AI permissions are not required for image editing. Temporary uploads are private, owner/conversation scoped and expire after 24 hours; scheduled recovery prunes them.
- Disabling image editing blocks new submissions/uploads while retaining access to saved previews. Chat assistance and saved message history remain independent choices. Old accepted jobs keep their IDs and execution paths.

## Slow edit investigation

The reported 4:54 edit is confirmed by the local workflow and correlated upstream logs. The image itself was not in the provider for the full duration.

| UTC on 2026-10-23 | Observed event |
| --- | --- |
| 11:20:32 | Local request created |
| 11:20:46 | Upstream image-workflow wrapper accepted |
| 11:22:01 | Scheduled coordinator dispatched the worker |
| 11:22:39 | Worker startup completed and execution began |
| 11:23:50 | Worker execution finished; provider call took 41.291 seconds |
| 11:25:15.8 | Coordinator recognized completion |
| 11:25:26 | Result available locally |

There were approximately 75 seconds before dispatch and 85 seconds after worker completion in the scheduled wrapper, plus about 37 seconds of worker startup. Provider latency, upload and download contributed the remainder.

New single-image requests now prefer the existing direct managed-execution API when the backend advertises `managed_execute: true`. Two-product requests dispatch independent direct executions. This avoids the scheduled multi-step wrapper for these single-step image tasks. Ready local preparation checkpoints no longer insert a five-second wait. Processing polls remain bounded, and old jobs are not resubmitted.

This behavior is tested against the real local HTTP/database/queue stack with recorded image responses and is covered by PHP request/identity tests. A fresh paid timing run was not possible: the connected account had 0.5 image credit. No credits were purchased or silently added. Do not treat the fixture completion times as a new measured production latency. Worker startup and provider runtime remain real contributors.

## Automated checks

- Full PHP regression: 160 tests, 977 assertions, 15 skips. Fourteen skips concern native-chat hooks absent on released 3.1.3; one requires the separate PostgreSQL fixture.
- The full run includes private uploads, retry source isolation, direct-execution dispatch, least-privilege operation, and explicit logout session rotation.
- Existing Playwright suite: all 13 checks passed, including saved history, SPA persistence, reload, mobile, generation/download and two-product selective Apply.
- Extended Playwright checks cover @, 284-car search, two destinations, exact saved bytes for an oversized result, original download, follow-up source hashes, copy/view/feedback/retry, actual stale-Apply reason, scrolling, upload/paste/drop, task selection, docked Save/Discard, 390px and a 200% equivalent CSS viewport (not browser toolbar zoom), all eight settings combinations, and logout.
- Translation validation: 33 locales, 245 application keys and 103 studio keys, matching placeholders.
- Browser provider calls are fixtures using recorded images. They establish UI, permissions, data flow and catalog-write behavior, not new live AI quality, live billing or current production latency.

## Local sites and catalog

The main acceptance site is `http://127.0.0.1:8192/admin/dreamlayer/settings`.
The “approximately 270” car catalog contains exactly 284 images, imported as
`ABLO-001` through `ABLO-284`. Existing `DL-TEST-RED` and `DL-TEST-BLUE` records
were preserved. Source: the existing local ABLO car test catalog, not new web
search or generated stock images.

Port 8193 is the development-host comparison installation. Preserve its disconnected/off state. The current standalone UI acceptance belongs to released 3.1.3; historical older-host tests must not be presented as current 0.5.0 browser coverage.

The independent 0.4.0 report remains useful prior evidence but does not certify this new ZIP. A new live acceptance run must name the exact checksum and distinguish completed checks from unrun or simulated checks. Earlier console fixes for approval wording, installation-list refresh and Usage IDs are separate local changes and are not deployed by this work.
