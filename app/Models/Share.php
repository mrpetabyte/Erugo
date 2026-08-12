<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Setting;
use App\Mail\shareDeletedWarningMail;
use App\Jobs\sendEmail;
use App\Services\SettingsService;
class Share extends Model
{

  protected $fillable = [
    'user_id',
    'name',
    'description',
    'path',
    'long_id',
    'size',
    'file_count',
    'download_limit',
    'download_count',
    'require_email',
    'expires_at',
    'status',
    'password'
  ];

  protected $casts = [
    'expires_at' => 'datetime',
    'deletes_at' => 'datetime',
  ];

  protected $appends = [
    'expired',
    'deletes_at',
    'deleted'
  ];

  protected $hidden = [
    'path',
    'user_id',
  ];

  public function files()
  {
    return $this->hasMany(File::class);
  }

  public function getFileCountAttribute()
  {
    return $this->files()->count();
  }

  public function user()
  {
    return $this->belongsTo(User::class);
  }

  public function invite()
  {
    return $this->belongsTo(ReverseShareInvite::class, 'invite_id');
  }

  function getExpiredAttribute()
  {
    if (!$this->expires_at) {
      return false;
    }
    return $this->expires_at < now()->addMinutes(1);
  }

  function getDeletesAtAttribute()
  {
    $cleanFilesAfterDays = Setting::where('key', 'clean_files_after_days')->first();

    if (!$cleanFilesAfterDays) {
      $cleanFilesAfterDays = 30;
    } else {
      $cleanFilesAfterDays = (int) $cleanFilesAfterDays->value;
    }

    if ($cleanFilesAfterDays == 0) {
      $cleanFilesAfterDays = 30;
    }

    if (!$this->expires_at) {
      return null;
    }

    return $this->expires_at->copy()->addDays($cleanFilesAfterDays);
  }

  function getDeletedAttribute()
  {
    return $this->status == 'deleted';
  }

  public function scopeReadyForCleaning($query)
  {
    $cleanFilesAfterDays = Setting::where('key', 'clean_files_after_days')->first();

    if (!$cleanFilesAfterDays) {
      $cleanFilesAfterDays = 30;
    } else {
      $cleanFilesAfterDays = (int) $cleanFilesAfterDays->value;
    }

    if ($cleanFilesAfterDays == 0) {
      $cleanFilesAfterDays = 30;
    }

    $deletesAt = now()->subDays($cleanFilesAfterDays);

    return $query->where('expires_at', '<', $deletesAt)->where('status', '!=', 'deleted');
  }


  public function cleanFiles($disableEmail = false)
  {
    $filePath = $this->path;
    $completePath = storage_path('app/shares/' . $filePath);

    // Best-effort disk cleanup. A locked, permission-denied, or already-missing
    // file must never prevent the share from being marked deleted.
    if (is_dir($completePath)) {
      $files = glob($completePath . '/*');
      if ($files !== false) {
        foreach ($files as $file) {
          @unlink($file);
        }
      }
      @rmdir($completePath);
    }

    //or is it a zip file?
    if (is_file($completePath . '.zip')) {
      @unlink($completePath . '.zip');
    }

    // Mark as deleted unconditionally: a missing owner, failing email, or
    // partially-removable files must not leave the share stuck.
    $this->status = 'deleted';
    $this->save();

    if ($this->invite) {
      $this->invite->delete();
    }

    if (!$disableEmail) {
      try {
        $settingsService = new SettingsService();
        $shouldSend = $settingsService->get('emails_share_deleted_enabled') ?? true;

        // owner may be null (guest uploads / deleted account) - never crash here
        if ($shouldSend && $this->user) {
          sendEmail::dispatch($this->user->email, shareDeletedWarningMail::class, ['share' => $this]);
        }
      } catch (\Exception $e) {
        // A notification failure must not prevent the deletion flag.
      }
    }

    return true;
  }
}
