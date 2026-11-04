<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Notifier
{
    public static function key($student, $type, $table = null, $id = null): string
    {
        return hash('sha256', json_encode([$student, $type, $table, $id === null ? null : (int) $id]));
    }

    public function student(string $std, string $type, string $message, ?string $table = null, ?int $id = null): bool
    {
        return DB::transaction(function () use ($std, $type, $message, $table, $id) {
            if (! DB::table('delivery_key')->insertOrIgnore(['key' => self::key($std, $type, $table, $id)])) {
                return false;
            }
            DB::table('notification')->insert(['std_no' => $std, 'type' => $type, 'message' => mb_substr($message, 0, 255), 'reference_table' => $table, 'reference_id' => $id, 'created_at' => now()]);

            return true;
        });
    }

    public function mail(string $key, string $recipient, string $subject, string $body): void
    {
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        DB::table('mail_outbox')->insertOrIgnore(['delivery_key' => hash('sha256', $key.'|'.strtolower($recipient)), 'recipient' => $recipient, 'subject' => $subject, 'body' => $body, 'created_at' => now()]);
    }
}
