# Guest-Share Reliability Fixes — Report

## Changes

**Committed in `c7cdaf7` — guard null owner/invite on share access + notification jobs**

- `SharesController.php:154` — `$share->invite?->user` (the download 500)
- `sendDeletionWarningEmails.php:45` — skip ownerless shares
- `sendExpiredWarningEmails.php:42` — skip ownerless shares
- `sendExpiryWarningEmails.php:44` — third job with the same latent bug, also fixed
- `File.php:25` — `$this->share?->user` (dead code, hardened)

The email jobs now mark ownerless shares as `sent_*` and skip, so they no longer crash or retry forever.

**Committed in `03e7c28` — clean guest users by invite lifecycle instead of age sweep**

Redesigned the guest cleanup in `maintainDb.php` to fix the foreign-key failure as a side-effect.

Replaced the age sweep with two lifecycle-driven passes:

1. **Expired, unused invites** (`cleanupGuestUsers`, `maintainDb.php`): finds link invites that expired and were never completed (guest never uploaded), deletes the **invite first** — which satisfies the `guest_user_id` FK — then deletes the linked guest. If the invite still has a **live share** tied to it (via `invite_id`), both the invite and guest are kept alive.

2. **Orphaned guests** (`maintainDb.php`): guest accounts that no invite references (`whereDoesntHave('invite')`) and own no shares (`whereDoesntHave('shares')`) — leftovers from revoked invites/cleaned uploads — removed once inactive >7 days.

Why this fixes the persisting error: the FK failure was caused by deleting a guest while `reverse_share_invites.guest_user_id` still pointed at them. Now the invite is always removed before its guest.

### The `deletes_at` mechanism (why invite/guest must stay alive)

A guest-flow share is **ownerless** (`user_id = null`, `UploadsController.php:359`) and tied to the invite via `invite_id`. It is **not deleted when it expires**: its files and DB row persist until `deletes_at = expires_at + clean_files_after_days` (default 30 days, `Share.php:74-93`). Only when `cleanExpiredShares` finally cleans it does `Share::cleanFiles()` remove the invite itself (`Share.php:147`).

So deleting the invite early would break `checkShareAccess` (which resolves the requester through `$share->invite->user`) and orphan the ownerless share. The cleanup therefore skips any invite that still has a live (non-deleted) share, letting the share's own cleanup remove the invite at `deletes_at`. After that, the guest becomes orphaned and is caught by pass 2.

`chunk` and `upload session` cleanup remain age-based (those are legitimately stale artifacts).

## Verification

**Lint:** all 6 changed files pass `php -l` (PHP 8.3.14).

**Full test suite:** `vendor/bin/phpunit` → **32 tests, 166 assertions, all OK** (includes `SharesControllerSecurityTest` and `UploadsControllerSecurityTest`).

**Functional test of `maintainDb` redesign** — seeded realistic guest scenarios including the ownerless-share case (directly setting `invite_id`, matching `UploadsController`):

| Scenario | Result |
|---|---|
| Guest uploaded → invite still has a live share | guest + invite **preserved** ✅ |
| Expired unused invite → guest never uploaded | guest + invite deleted ✅ |
| Orphaned guest (no invite, no shares, inactive) | deleted ✅ |
| **FK constraint failure** | **gone** ✅ |

**Functional test of the email-job null guards** — an ownerless share is now processed without crashing and marked `sent_expired=true` (previously it crashed every night and retried forever).
