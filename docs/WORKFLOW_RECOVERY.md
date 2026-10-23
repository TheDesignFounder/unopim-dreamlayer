# Independent image requests and delivery recovery

Multiple selected images are grouped in UnoPim, with two independent DreamLayer
`/image-workflows` requests active per group by default. Each result keeps its own
source, destination, idempotency key and Apply action. New groups do not use the
remote `/image-batches` scheduler. Existing groups retain that path and their
remote identities.

`config('dreamlayer.batch_concurrency')` controls admission, bounded between 1 and
5 and saved when the group is created. Run at least two workers on the
`dreamlayer` queue to allow independent requests to overlap. This limit applies
per group; backend and provider capacity still determine actual concurrency.

Admission and queue dispatch commit together under the parent and child locks.
Recovery can redispatch an admitted child even before it has received its remote
ID, using the same idempotency key. A normal image failure frees a slot for the
next image. Authentication, funding, expiry or connection changes stop unstarted
images while preserving the identities of admitted work.

Completed remote results have a durable delivery checkpoint. Each worker attempt
downloads at most one missing asset, reuses identical step/final images and
resumes interrupted delivery without submitting another generation. Chat cards
distinguish waiting, downloading and retrying delivery. A successful preview
never changes the catalog until the user chooses Apply.

## Upgrade

Install the updated package, run `php artisan migrate --force`, clear application
caches and restart the DreamLayer queue workers. The two additive migrations add
the delivery checkpoint and the independent-dispatch fields. Existing batches
default to `upstream`. Back up the database before an upgrade.

No UnoPim core patch or composer styling change is required.

## Validation

The extension suite covers independent submission, slot limits, lost-response
identity, partial failures, recovery before remote admission, legacy batches,
interrupted downloads and saved preview reuse. Migration scenarios cover fresh
installation, repeated execution, rollback/reinstallation and existing data.

Local validation passed 138 PHP tests with 800 assertions. A real two-image chat
request produced two overlapping upstream workflows and displayed both previews
without a reload or catalog changes. Restart recovery was separately tested by
interrupting a worker during delivery.

## Provider failures

The extension can recover delivery of an existing result. It cannot poll a
provider job after the DreamLayer API has already marked that job terminal.
Temporary BFL polling/download recovery is a separate backend change:
https://github.com/TheDesignFounder/Abloh/pull/547. That change retries reads of
the same accepted job; it does not repeat the generation POST. Backend deployment
must be verified separately from installing this package.
