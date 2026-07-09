<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmailVerificationService
{
    private const EXPIRES_MINUTES = 60;

    /**
     * Generate a fresh verification token for the user and email it via Brevo.
     */
    public static function sendVerificationLink(User $user): void
    {
        $token = Str::random(64);

        DB::table('email_verification_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $verifyUrl   = "{$frontendUrl}/verify-email?token={$token}&email=" . urlencode($user->email);

        EmailNotificationService::sendDirect(
            $user->email,
            'Verify your SureSign email address',
            "Please confirm your email address to finish setting up your SureSign account.\n\n"
                . "Verify it here: {$verifyUrl}\n\n"
                . "This link expires in " . self::EXPIRES_MINUTES . ' minutes.'
        );
    }

    /**
     * Validate a token and mark the user's email as verified. Returns false
     * on an invalid, expired, or already-consumed token.
     */
    public static function verify(string $email, string $token): bool
    {
        $record = DB::table('email_verification_tokens')->where('email', $email)->first();

        if (!$record || !Hash::check($token, $record->token)) {
            return false;
        }

        if (now()->diffInMinutes($record->created_at) > self::EXPIRES_MINUTES) {
            return false;
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return false;
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        DB::table('email_verification_tokens')->where('email', $email)->delete();

        return true;
    }
}
