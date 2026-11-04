<?php

use App\Models\Staff;
use App\Services\ScheduledWork;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('outing:overdue', function () {
    $this->info(app(ScheduledWork::class)->overdue().' request(s) flagged overdue.');
})->purpose('Expire unreviewed arrival requests and flag overdue outings');
Artisan::command('outing:reminders', function () {
    $this->info(app(ScheduledWork::class)->reminders().' reminder(s) created.');
})->purpose('Create deduplicated curfew and return reminders');
Artisan::command('outing:mail', function () {
    $failed = 0;
    foreach (DB::table('mail_outbox')->whereNull('sent_at')->orderBy('id')->limit(100)->pluck('id') as $id) {
        DB::transaction(function () use ($id, &$failed) {
            $m = DB::table('mail_outbox')->where('id', $id)->lockForUpdate()->first();
            if ($m->sent_at) {
                return;
            }
            try {
                Mail::raw($m->body, function ($message) use ($m) {
                    $message->to($m->recipient)->subject($m->subject);
                    $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $m->delivery_key.'@outing.local');
                });
                DB::table('mail_outbox')->where('id', $id)->update(['sent_at' => now(), 'attempts' => $m->attempts + 1, 'last_error' => null]);
            } catch (Throwable $e) {
                DB::table('mail_outbox')->where('id', $id)->update(['attempts' => $m->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
                $failed++;
            }
        });
    }
    if ($failed) {
        $this->error($failed.' email(s) failed and remain queued.');

        return 1;
    }$this->info('Mail outbox processed.');

    return 0;
})->purpose('Deliver pending mail through configured Laravel mail transport');
Artisan::command('outing:audit', function () {
    $errors = [];
    $actual = [];
    foreach (DB::table('standard_outing')->whereNull('checked_in_at')->get() as $o) {
        $actual[$o->std_no][] = ['kind' => 'standard', 'ref_id' => $o->id];
    }
    foreach (DB::table('outing_request')->where('type', 'checkout')->whereIn('status', ['checked_out', 'overdue'])->get() as $o) {
        $actual[$o->std_no][] = ['kind' => 'special', 'ref_id' => $o->id];
    }
    foreach ($actual as $std => $trips) {
        if (count($trips) > 1) {
            $errors[] = 'Multiple open trips: '.$std;
        }foreach ($trips as $trip) {
            if (! DB::table('active_outing')->where('std_no', $std)->where($trip)->exists()) {
                $errors[] = 'Registry mismatch: '.$std;
            }
        }
    }
    foreach (DB::table('active_outing')->get() as $o) {
        if (! isset($actual[$o->std_no])) {
            $errors[] = 'Orphan registry: '.$o->std_no;
        }
    }
    if ($errors) {
        foreach ($errors as $e) {
            $this->error($e);
        }

        return 1;
    }$this->info('Open outing registry matches gate history.');

    return 0;
})->purpose('Read-only verification of open outings and their unique registry');
Schedule::command('outing:overdue')->everyMinute()->withoutOverlapping();
Schedule::command('outing:reminders')->everyMinute()->withoutOverlapping();
Schedule::command('outing:mail')->everyMinute()->withoutOverlapping();

Artisan::command('outing:admin', function () {
    $id = $this->ask('New administrator staff ID');
    $name = $this->ask('Name');
    $email = $this->ask('Email (optional)');
    $password = $this->secret('Password (8–72 bytes)');
    $data = ['staff_id' => $id, 'name' => $name, 'email' => $email, 'password' => $password];
    $validator = Validator::make($data, [
        'staff_id' => ['required', 'regex:/^[A-Za-z0-9_-]{1,20}$/D', 'unique:staff,staff_id'],
        'name' => 'required|string|max:100', 'email' => 'nullable|email|max:100',
        'password' => ['required', 'string', 'min:8', 'max:72', function ($attribute, $value, $fail) {
            if (strlen($value) > 72) {
                $fail('Password must be at most 72 bytes.');
            }
        }],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    Staff::create([
        'staff_id' => $id, 'name' => $name, 'email' => $email, 'role' => 'admin',
        'password_hash' => Hash::make($password),
        'is_active' => 1, 'must_change_password' => 1, 'created_at' => now(),
    ]);
    $this->info('Administrator created. Change the password at first login.');

    return 0;
})->purpose('Provision a first administrator without demo accounts or a plaintext password argument');
