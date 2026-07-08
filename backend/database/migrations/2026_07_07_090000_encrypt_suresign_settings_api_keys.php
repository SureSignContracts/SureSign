<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['brevo_api_key', 'anthropic_api_key'];

    public function up(): void
    {
        // brevo_api_key was VARCHAR(255) — an encrypted payload (iv+value+mac, base64) runs
        // 300-500+ chars for a typical API key and would silently truncate. Widen to match
        // anthropic_api_key, which is already TEXT.
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->text('brevo_api_key')->nullable()->change();
        });

        DB::table('suresign_settings')->get(['id', ...self::COLUMNS])->each(function ($row) {
            $updates = [];
            foreach (self::COLUMNS as $column) {
                $value = $row->$column;
                if (!empty($value) && !$this->looksEncrypted($value)) {
                    $updates[$column] = Crypt::encryptString($value);
                }
            }
            if ($updates) {
                DB::table('suresign_settings')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        DB::table('suresign_settings')->get(['id', ...self::COLUMNS])->each(function ($row) {
            $updates = [];
            foreach (self::COLUMNS as $column) {
                $value = $row->$column;
                if (!empty($value) && $this->looksEncrypted($value)) {
                    try {
                        $updates[$column] = Crypt::decryptString($value);
                    } catch (\Throwable) {
                        // Leave as-is if it doesn't decrypt cleanly.
                    }
                }
            }
            if ($updates) {
                DB::table('suresign_settings')->where('id', $row->id)->update($updates);
            }
        });

        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->string('brevo_api_key')->nullable()->change();
        });
    }

    /**
     * Laravel's encrypted payload is a base64-encoded JSON object with iv/value/mac keys.
     * Used to make the up/down migration idempotent and safe to re-run.
     */
    private function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        $json = json_decode($decoded, true);
        return is_array($json) && isset($json['iv'], $json['value'], $json['mac']);
    }
};
