<?php

namespace Tests\Feature;

use App\Jobs\cleanSpecificShares;
use App\Models\ReverseShareInvite;
use App\Models\Share;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanSpecificSharesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reverse_share_is_cleaned_for_invite_owner(): void
    {
        $requester = User::factory()->create();
        $invite = ReverseShareInvite::create([
            'user_id' => $requester->id,
            'recipient_name' => 'Guest',
            'recipient_email' => 'guest@example.com',
            'invite_code' => 'test-invite',
        ]);
        $share = Share::create([
            'user_id' => null,
            'name' => 'Expired reverse share',
            'path' => 'expired-reverse-share',
            'long_id' => 'test-share',
            'size' => 0,
            'file_count' => 0,
            'expires_at' => Carbon::yesterday(),
        ]);
        $share->invite_id = $invite->id;
        $share->save();

        (new cleanSpecificShares([$share->id], $requester->id))->handle();

        $this->assertSame('deleted', $share->fresh()->status);
        $this->assertDatabaseMissing('reverse_share_invites', ['id' => $invite->id]);
    }

    public function test_share_not_owned_by_requester_is_not_cleaned(): void
    {
        $requester = User::factory()->create();
        $owner = User::factory()->create();
        $share = Share::create([
            'user_id' => $owner->id,
            'name' => 'Someone else share',
            'path' => 'someone-else-share',
            'long_id' => 'other-share',
            'size' => 0,
            'file_count' => 0,
            'expires_at' => Carbon::yesterday(),
        ]);

        (new cleanSpecificShares([$share->id], $requester->id))->handle();

        $this->assertNotSame('deleted', $share->fresh()->status);
    }
}
