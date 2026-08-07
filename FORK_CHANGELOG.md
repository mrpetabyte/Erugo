# FORK Changelog

> **Translation policy of this fork:** Only **English and German** are actively maintained and offered in the UI. The language selector has been reduced to these two languages, and the other locale files (`fr`, `it`, `nl`, `pt`, `pt-BR`) still exist but are no longer updated when UI strings change.

## Reverse Shares
- Reverse Shares: Add option for link-only invite
- Reverse Shares: Remove option for guest email invites (only existing users can be invited via email)
- Reverse Shares: Link invites create a guest account (multi-use); email invites target existing registered users only
- Reverse Shares: Email invites to an unrecognized address are rejected with an error
- Reverse Shares: Allow multiple uses of the same invite
- Reverse Shares: Add a link/email mode switch to the reverse invite dialog
- Reverse Shares: Link-only invites generate a copyable invite URL after creation
- Reverse Shares: Require a label when creating a link-only invite
- Reverse Shares: Preserve the active invite label across authentication and page reloads
- Reverse Shares: Show the invite label as a title above the upload buttons so guests know they clicked the right link
- Reverse Shares: Guest-created shares are named "<invite label> – <guest name>"
- Reverse Shares: Removed the redundant "invite accepted" toast when accepting via link

## Guest Upload Experience
- Reverse Shares: Uploaders stay logged in to upload multiple files; the uploader name is remembered and editable per batch
- Reverse Shares: After uploading, guests can choose "Upload More Files" or "Done"
- Guests can upload additional batches without exposing account settings or password controls
- Redesigned the guest name field with an inline label; theme-aware dark styling
- Added an upload progress header with the reverse-share label and upload status
- Enforce a minimum expiry value of one
- Expiry time display uses proper singular/plural forms for all languages (ICU)
- File dropzone no longer opens the file picker when clicked; the hand cursor was removed

## Uploads & Downloads
- Direct download supported via ?directdl=1
- Faster and resumable multi-file uploads
- Improved estimated upload time display
- Downloaded ZIP filenames use safe ASCII fallbacks to avoid malformed names in some clients

## Interface & Theming
- Added a full-screen loading screen while the initial authentication state is determined
- Prevented the main UI and login form from flashing before initial authentication completes
- Fixed layout issue on mobile
- Fixed dark seam artifacts between upload sections on mobile
- Server-side theme category now determines the initial dark/light mode without operating-system detection

## Security & Reliability
- Various security improvements and removal of security vulnerabilities
- RCE fix in uploads controllers
- Forbidden API responses no longer log users out; logout is reserved for expired sessions

## Translations
- Language selector now offers only English and German; other locales are no longer maintained in this fork
- Major and continued German translation improvements
- Added upload progress title translations for all supported locales
