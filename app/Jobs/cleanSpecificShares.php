<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Share;

class cleanSpecificShares implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $shareIds, public int $userId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        foreach ($this->shareIds as $shareId) {
            $share = Share::where('id', $shareId)
                ->where(function ($query) {
                    $query->where('user_id', $this->userId)
                        ->orWhereHas('invite', function ($inviteQuery) {
                            $inviteQuery->where('user_id', $this->userId);
                        });
                })
                ->first();

            if ($share) {
                $share->cleanFiles(true);
            }
        }
    }
}
