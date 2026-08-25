<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\User;
use App\Models\Share;
use App\Models\UploadSession;
use App\Models\ChunkUpload;
use App\Models\ReverseShareInvite;
use Illuminate\Support\Facades\Log;

class maintainDb implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting maintainDb job');

        //delete any chunks over 7 days old, these have probably been left by failed or aborted uploads
        Log::info('Deleting chunks over 7 days old: ' . ChunkUpload::where('created_at', '<', now()->subDays(7))->count() . ' chunks');
        ChunkUpload::where('created_at', '<', now()->subDays(7))->delete();

        //delete any upload sessions over 7 days old, these have probably been left by failed or aborted uploads
        Log::info('Deleting upload sessions over 7 days old: ' . UploadSession::where('created_at', '<', now()->subDays(7))->count() . ' sessions');
        UploadSession::where('created_at', '<', now()->subDays(7))->delete();

        $this->cleanupGuestUsers();
    }

    /**
     * Clean up guest accounts by their lifecycle rather than by age.
     *
     * Guests are only ever created by a link invite (ReverseSharesController),
     * so their natural end-of-life is the invite, not an arbitrary age. Two cases:
     *
     * 1. Expired, unused link invites whose guest never uploaded: no share is
     *    tied to the invite, so both the invite and its guest can go. Delete the
     *    invite first (satisfying the guest_user_id FK), then the guest.
     * 2. Orphaned guests: guest accounts that no invite references and that own
     *    no shares. These are leftovers (e.g. from invites that were revoked or
     *    uploads that were cleaned up) and are safe to remove once inactive.
     */
    private function cleanupGuestUsers(): void
    {
        // 1. Expired, unused link invites -> delete invite, then its guest
        $expiredInvites = ReverseShareInvite::whereNotNull('guest_user_id')
            ->whereNull('completed_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        Log::info('Cleaning up expired unused invites: ' . $expiredInvites->count() . ' invites');
        foreach ($expiredInvites as $invite) {
            // A guest-flow share is ownerless (user_id null) and tied to the invite
            // via invite_id. It is NOT deleted at expiry - its files and row persist
            // until deletes_at (expires_at + clean_files_after_days), and only then
            // does cleanFiles() remove the invite (Share::cleanFiles). So if this
            // invite still has a live share, keep the invite (and guest) alive so
            // checkShareAccess can still resolve the requester through it.
            $hasLiveShare = Share::where('invite_id', $invite->id)
                ->where('status', '!=', 'deleted')
                ->exists();

            if ($hasLiveShare) {
                continue;
            }

            $guestId = $invite->guest_user_id;
            $invite->delete();

            $guest = User::find($guestId);
            if ($guest && $guest->is_guest && !$guest->shares()->exists()) {
                $guest->delete();
            }
        }

        // 2. Orphaned guests: no invite references them and they own no shares
        $orphanedGuests = User::where('is_guest', true)
            ->where('created_at', '<', now()->subDays(7))
            ->whereDoesntHave('invite')
            ->whereDoesntHave('shares')
            ->get();

        Log::info('Deleting orphaned guest users: ' . $orphanedGuests->count() . ' users');
        foreach ($orphanedGuests as $guest) {
            $guest->delete();
        }
    }
}
