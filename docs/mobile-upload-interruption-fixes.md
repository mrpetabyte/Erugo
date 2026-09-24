# Mobile Upload Interruption Fixes — Report

## Problem

Users reported that uploads failed after they left their phone on the table
while uploading. The first few files succeeded, then the upload stopped with
an error.

Files in a batch are uploaded **one after another** (`uploadFilesInChunks`,
`resources/js/api.js`), and the batch stops at the first file that fails. So a
single file failing partway through explains "the first files worked, then it
broke".

## Root causes

1. **Screen auto-lock kills requests, and retries gave up too early.** When a
   phone auto-locks, mobile browsers suspend the page and kill its in-flight
   network requests. tus-js-client's default retry schedule (`[0, 1s, 3s, 5s]`)
   gives up after about 9 seconds. Its default retry check also refuses to
   retry at all while `navigator.onLine === false`, which is common while a
   phone's radio is asleep.

2. **A stale JWT was sent after a token refresh.** The `Authorization` header
   was a static tus option, fixed when the `tus.Upload` was created.
   `onBeforeRequest` refreshed an expiring token but set the new token only on
   the request that triggered the refresh. Later requests of the same upload
   still sent the old token. Examples:
   - the final `Upload-Concat: final` POST of a parallel upload
   - retries after the device woke up

   tusd's `pre-create` hook (`TusdHooksController::preCreate`) then rejected the
   expired token (access-token TTL is 60 min) with a 401. tus does not retry a
   401, so the batch failed.

3. **Several token refreshes at once.** Each file uses 3 parallel partial
   uploads. All of them could start a token refresh at the same moment.

4. **Share creation could not safely be retried.** A network error on the
   final `create-share-from-uploads` request failed the upload even though
   every file had been transferred (see
   [Retry-safe share creation](#retry-safe-share-creation)).

## Changes

**Committed in `9244a95` — always send the current JWT on tus requests**

- `resources/js/api.js` (`uploadFileWithTus`): removed the static
  `Authorization` header. `onBeforeRequest` now refreshes the token when it
  expires within 5 minutes and always sets `Authorization` from `store.jwt`.
- New `refreshTokenForTus()` helper: a single refresh at a time, shared by all
  partial uploads, which also updates the store (`store.authSuccess`).

**Committed in `822bb36` — keep retrying tus requests through device sleep**

- `TUS_RETRY_DELAYS`: a schedule covering about 10 minutes of lost connectivity
  (`0, 1s, 3s, 5s, 10s, 15s, 30s, 30s, 60s × 7`). tus resets the attempt
  counter whenever a retry makes progress, so the schedule only has to bridge
  a single outage.
- `shouldRetryTusRequest` (`onShouldRetry`):
  - **Network errors / no response:** always retried, even while
    `navigator.onLine` is false.
  - **5xx, 409 (offset conflict), 423 (upload locked):** retried.
  - **401:** retried up to `TUS_MAX_UNAUTHORIZED_RETRIES` (2) times. An
    `onAfterResponse` hook sets `tusForceTokenRefresh` so the retry uses a
    freshly refreshed token.
  - **Other 4xx (e.g. 413 size limit):** not retried. These are permanent.

**Committed in `16e35cc` — hold a screen wake lock while uploading**

- `resources/js/components/uploader.vue`: requests a Screen Wake Lock
  (`navigator.wakeLock.request('screen')`) while `currentlyUploading` is true
  and releases it when the upload ends or the component unmounts.
- The browser drops the lock whenever the page is hidden. A `visibilitychange`
  listener requests it again when the page becomes visible during an upload.
- Where the API is unsupported (or the request is denied), the upload continues
  without the lock and a warning is logged to the console.

### Retry-safe share creation

After the last file finishes, the client calls
`POST /api/uploads/create-share-from-uploads` to create the share. Before this
change, that request retried only the "not found or not completed" race (tusd
hooks still running). A network error at this moment (e.g. the phone went to
sleep) failed the whole upload even though every file had been transferred.
Retrying it was not safe: if the first request reached the server, the upload
sessions had already been used, so a retry would fail with "not found or not
completed".

**Committed as `fix(uploads): make create-share-from-uploads idempotent per batch`**

- New nullable, unique `shares.upload_batch_id` column
  (`2026_09_24_000000_add_upload_batch_id_to_shares_table.php`). It stores
  `"{user_id}:{upload_id}"`, where `upload_id` is the batch UUID the client
  already sends with every upload. Prefixing the user id scopes the key to
  the uploader without extra ownership checks, and it also works for guest
  shares, where `user_id` is later set to `null`.
- `UploadsController::createShareFromUploads` looks the key up **before** doing
  any work. If a share already exists for it, the request returns that share
  in the normal success response. It does not move files, dispatch the ZIP
  job, or send emails again.
- If two attempts run at the same time, the unique index makes the second
  `Share::create` fail before it moves any files. The client then retries and
  gets the existing share.
- `upload_id` is now limited to 200 characters so the key fits the column.
  The column is added to the `Share` model's `$hidden` list so it doesn't show
  up in API responses.

**Committed as `fix(upload): retry share creation on network errors`**

- New `createShareFromUploads()` helper in `resources/js/api.js`, used by both
  the normal and the bundle upload flows. The bundle flow previously had no
  retry at all.
- **Network errors and 5xx responses** (e.g. a 502 from the tunnel): retried
  on `CREATE_SHARE_RETRY_DELAYS` (1s up to 60s, about 5 minutes in total).
- **"Not found or not completed":** still retried, up to 4 times.
- **Anything else** (validation errors, expired session, …): fails immediately.

## Limitations

- The wake lock prevents **auto-lock** only. If the user locks the phone or
  switches apps, the page is still suspended. The longer retry schedule
  recovers only if they come back within about 10 minutes.
- A 401 is retried only while fewer than 2 retries have been used. After a long
  outage has used up the retry budget, a late 401 is not retried. This is
  unlikely in practice: `onBeforeRequest` refreshes the token based on its
  expiry before sending, and a refresh that fails because the network is down
  shows up as a (retried) network error.
- A share created before the migration has no `upload_batch_id`. This only
  matters for a request retried across the deployment, which is negligible.

## Deployment

The changes touch the frontend (`resources/js`), the backend
(`UploadsController`, `Share` model) and add a migration. Rebuild the
production image (`docker/alpine/Dockerfile`). The container's start script
runs `php artisan migrate --force`, so the new column is added automatically
when the container starts.

## Verification

- **Build:** `npm run build` (Vite) succeeds. No separate JS lint is configured.
- **PHP lint:** not run. PHP isn't installed locally and the dev container
  wasn't running. Run `php -l` on the changed PHP files in the container.
- **Tests:** not run (the test environment isn't set up at the moment). Run
  `UploadsControllerSecurityTest` once it is available.
- **Not yet checked on a real device.** Suggested manual check on a phone:
  1. Start a multi-file upload with files large enough that it takes longer
     than the auto-lock timeout.
     - **Expected:** the screen stays on.
  2. Briefly turn on airplane mode during an upload, then turn it off.
     - **Expected:** the upload resumes instead of failing.
  3. Send the same `create-share-from-uploads` request twice with the same
     `upload_id`.
     - **Expected:** both requests return the same share.
