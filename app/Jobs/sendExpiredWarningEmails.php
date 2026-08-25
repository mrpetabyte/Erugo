<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Share;
use App\Jobs\sendEmail;
use App\Mail\shareExpiredWarningMail;
use App\Services\SettingsService; 

class sendExpiredWarningEmails implements ShouldQueue
{
  use Queueable;

  /**
   * Create a new job instance.
   */
  public function __construct()
  {
    //
  }

  /**
   * Execute the job.
   */
  public function handle(): void
  {
    $settingsService = new SettingsService();

    $shouldSend = $settingsService->get('emails_share_expired_warning_enabled') ?? true;

    if (!$shouldSend) {
      return;
    }

    $shares = Share::where('expires_at', '<', now())
      ->where('sent_expired', false)
      ->get();

    foreach ($shares as $share) {
      if (!$share->user) {
        // Guest uploads / deleted-owner shares have no recipient - mark sent and skip
        $share->sent_expired = true;
        $share->save();
        continue;
      }
      sendEmail::dispatch($share->user->email, shareExpiredWarningMail::class, ['share' => $share]);
      $share->sent_expired = true;
      $share->save();
    }
  }
}
