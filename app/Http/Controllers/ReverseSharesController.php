<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\ReverseShareInvite;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Jobs\sendEmail;
use App\Mail\reverseShareInviteMail;
use App\Models\Setting;
use App\Services\LongIdGenerator;


class ReverseSharesController extends Controller
{
    public function createInvite(Request $request)
    {

        $allowReverseShares = Setting::where('key', 'allow_reverse_shares')->first()->value;
        $allowReverseShares = filter_var($allowReverseShares, FILTER_VALIDATE_BOOLEAN);

        if (!$allowReverseShares) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reverse shares are not allowed'
            ], 400);
        }

        if ($request->recipient_email) {
            $validator = Validator::make($request->all(), [
                'recipient_email' => ['email', 'max:255'],
                'recipient_name' => ['required', 'string', 'max:255'],
            ]);
        } else {
            $validator = Validator::make($request->all(), [
                'label' => ['required', 'string', 'max:255'],
            ]);
        }

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => [
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        // Check if recipient is an existing non-guest user
        $existingUser = null;
        if ($request->recipient_email) {
            $existingUser = User::where('email', $request->recipient_email)
                ->where(function ($query) {
                    $query->where('is_guest', false)
                        ->orWhereNull('is_guest');
                })
                ->first();
        }

        $guestUserId = null;

        if ($request->recipient_email) {
            // Email mode: strictly for existing registered users
            if (!$existingUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No account found for this email'
                ], 422);
            }
        } else {
            // Link mode: create a guest user for the invite
            $guestUser = User::create([
                'name' => $request->label,
                'email' => Str::random(20), //we don't need a real email for the guest user
                'password' => Hash::make(Str::random(20)), //set a random password so the user can't login
                'is_guest' => true
            ]);
            $guestUserId = $guestUser->id;
        }

        $inviteCode = (new LongIdGenerator())->generateForInvite();

        $invite = ReverseShareInvite::create([
            'user_id' => $user->id,
            'guest_user_id' => $guestUserId,
            'invite_code' => $inviteCode,
            'recipient_name' => $request->recipient_name ?: null,
            'recipient_email' => $request->recipient_email ?: null,
            'message' => $request->message ?: null,
            'label' => $request->label ?: null,
            'expires_at' => now()->addDays(14)
        ]);

        // Only send email if recipient_email is provided
        if ($request->recipient_email) {
            sendEmail::dispatch($request->recipient_email, reverseShareInviteMail::class, [
                'user' => $user,
                'invite' => $invite,
                'invite_code' => $inviteCode,
                'isExistingUser' => $existingUser !== null
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'invite' => $invite
            ]
        ]);
    }
}
