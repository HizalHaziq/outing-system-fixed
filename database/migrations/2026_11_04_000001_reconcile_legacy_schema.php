<?php

use App\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abort on conflicting real history; never choose or discard a trip automatically.
        $open = [];
        if (Schema::hasTable('standard_outing')) {
            foreach (DB::table('standard_outing')->whereNull('checked_in_at')->get() as $row) {
                $open[$row->std_no][] = 'standard:'.$row->id;
            }
        }
        if (Schema::hasTable('outing_request')) {
            foreach (DB::table('outing_request')->when(Schema::hasColumn('outing_request', 'type'), fn ($q) => $q->where('type', 'checkout'))->whereIn('status', ['checked_out', 'overdue'])->get() as $row) {
                $open[$row->std_no][] = 'special:'.$row->id;
            }
        }
        foreach ($open as $std => $rows) {
            if (count($rows) > 1) {
                throw new RuntimeException('Conflicting open outings for '.$std.': '.implode(', ', $rows).'. Reconcile against gate records before upgrading.');
            }
        }
        if (! Schema::hasTable('student')) {
            Schema::create('student', function (Blueprint $t) {
                $t->integer('id', true);
                $t->string('std_no', 20)->unique();
                $t->string('std_name', 100);
                $t->string('ic_no', 20);
                $t->string('program', 100)->nullable();
                $t->string('email', 100)->nullable();
                $t->string('phone', 20)->nullable();
                $t->string('emergency_contact_name', 100)->nullable();
                $t->string('emergency_contact_phone', 20)->nullable();
                $t->string('password_hash', 255);
                $t->boolean('is_active')->default(1);
                $t->timestamp('created_at')->useCurrent();
                $t->boolean('must_change_password')->default(1);
            });
        }
        if (! Schema::hasTable('staff')) {
            Schema::create('staff', function (Blueprint $t) {
                $t->integer('id', true);
                $t->string('staff_id', 20)->unique();
                $t->string('name', 100);
                $t->string('email', 100)->nullable();
                $t->enum('role', ['admin', 'warden', 'guard']);
                $t->string('password_hash', 255);
                $t->boolean('is_active')->default(1);
                $t->timestamp('created_at')->useCurrent();
                $t->boolean('must_change_password')->default(0);
            });
        }
        if (! Schema::hasTable('app_setting')) {
            Schema::create('app_setting', function (Blueprint $t) {
                $t->string('setting_key', 64)->primary();
                $t->string('setting_value', 255);
                $t->string('updated_by', 20)->nullable();
                $t->timestamp('updated_at')->useCurrent();
            });
        }
        if (! Schema::hasTable('outing_request')) {
            Schema::create('outing_request', function (Blueprint $t) {
                $t->integer('id', true);
                $t->string('std_no', 20)->collation($this->keyCollation('student', 'std_no'));
                $t->enum('type', ['checkout', 'checkin'])->default('checkout');
                $t->string('reason', 255);
                $t->string('destination', 255)->nullable();
                $t->string('coming_from', 255)->nullable();
                $t->boolean('unreviewed_by_staff')->default(0);
                $t->dateTime('requested_out_at')->nullable();
                $t->dateTime('expected_return_at')->nullable();
                $t->enum('status', ['pending', 'approved', 'rejected', 'checked_out', 'checked_in', 'overdue', 'cancelled', 'expired'])->default('pending');
                $t->string('reviewed_by', 20)->collation($this->keyCollation('staff', 'staff_id'))->nullable();
                $t->dateTime('reviewed_at')->nullable();
                $t->string('rejection_reason', 255)->nullable();
                $t->dateTime('actual_out_at')->nullable();
                $t->dateTime('actual_return_at')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->foreign('std_no')->references('std_no')->on('student');
                $t->foreign('reviewed_by')->references('staff_id')->on('staff');
                $t->index(['std_no'], 'idx_request_stdno');
                $t->index(['status'], 'idx_request_status');
                $t->index(['type'], 'idx_request_type');
            });
        }
        if (! Schema::hasTable('standard_outing')) {
            Schema::create('standard_outing', function (Blueprint $t) {
                $t->integer('id', true);
                $t->string('std_no', 20)->collation($this->keyCollation('student', 'std_no'));
                $t->dateTime('checked_out_at');
                $t->string('checked_out_by', 20)->collation($this->keyCollation('staff', 'staff_id'));
                $t->string('gate_location_out', 100)->nullable();
                $t->dateTime('checked_in_at')->nullable();
                $t->string('checked_in_by', 20)->collation($this->keyCollation('staff', 'staff_id'))->nullable();
                $t->string('gate_location_in', 100)->nullable();
                $t->boolean('is_late_return')->default(0);
                $t->integer('arrival_request_id')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->foreign('std_no')->references('std_no')->on('student');
                $t->foreign('checked_out_by')->references('staff_id')->on('staff');
                $t->foreign('checked_in_by')->references('staff_id')->on('staff');
                $t->index(['std_no'], 'idx_standard_stdno');
                $t->index(['std_no', 'checked_in_at'], 'idx_standard_open');
            });
        }
        if (! Schema::hasTable('outing_event')) {
            Schema::create('outing_event', function (Blueprint $t) {
                $t->integer('id', true);
                $t->integer('request_id');
                $t->enum('event_type', ['submitted', 'approved', 'rejected', 'checked_out', 'checked_in', 'overdue_flagged', 'cancelled', 'expired']);
                $t->enum('actor_type', ['student', 'staff', 'system']);
                $t->string('actor_id', 20)->nullable();
                $t->string('gate_location', 100)->nullable();
                $t->string('note', 255)->nullable();
                $t->dateTime('event_at')->useCurrent();
                $t->foreign('request_id')->references('id')->on('outing_request');
                $t->index(['request_id'], 'idx_event_request');
            });
        }
        if (! Schema::hasTable('login_failure')) {
            Schema::create('login_failure', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->enum('account_type', ['staff', 'student']);
                $t->string('identifier', 64);
                $t->string('ip', 45);
                $t->dateTime('attempted_at')->useCurrent();
                $t->index(['account_type', 'identifier', 'attempted_at'], 'idx_account');
                $t->index(['ip', 'attempted_at'], 'idx_ip');
            });
        }
        if (! Schema::hasTable('notification')) {
            Schema::create('notification', function (Blueprint $t) {
                $t->integer('id', true);
                $t->string('std_no', 20)->collation($this->keyCollation('student', 'std_no'));
                $t->string('type', 30);
                $t->string('message', 255);
                $t->string('reference_table', 30)->nullable();
                $t->integer('reference_id')->nullable();
                $t->boolean('is_read')->default(0);
                $t->timestamp('created_at')->useCurrent();
                $t->foreign('std_no')->references('std_no')->on('student')->cascadeOnDelete();
                $t->index(['std_no', 'is_read'], 'idx_notification_unread');
                $t->index(['std_no', 'type', 'reference_table', 'reference_id'], 'idx_notification_dedupe');
            });
        }
        if (! Schema::hasTable('qr_secret')) {
            Schema::create('qr_secret', function (Blueprint $t) {
                $t->string('subject', 40)->primary();
                $t->string('secret', 64);
                $t->bigInteger('last_ts')->default(0);
                $t->timestamp('created_at')->useCurrent();
            });
        }
        if (! Schema::hasTable('outing_group')) {
            Schema::create('outing_group', function (Blueprint $t) {
                $t->integer('id', true);
                $t->string('lead_std_no', 20)->collation($this->keyCollation('student', 'std_no'));
                $t->enum('status', ['active', 'closed'])->default('active');
                $t->timestamp('created_at')->useCurrent();
                $t->dateTime('expires_at');
                $t->foreign('lead_std_no')->references('std_no')->on('student');
                $t->index(['lead_std_no', 'status'], 'idx_group_lead');
            });
        }
        if (! Schema::hasTable('outing_group_member')) {
            Schema::create('outing_group_member', function (Blueprint $t) {
                $t->integer('id', true);
                $t->integer('group_id');
                $t->string('std_no', 20)->collation($this->keyCollation('student', 'std_no'));
                $t->enum('status', ['invited', 'accepted', 'declined', 'removed', 'left'])->default('invited');
                $t->timestamp('created_at')->useCurrent();
                $t->dateTime('responded_at')->nullable();
                $t->foreign('group_id')->references('id')->on('outing_group');
                $t->foreign('std_no')->references('std_no')->on('student');
                $t->index(['group_id', 'status'], 'idx_gm_group');
                $t->index(['std_no', 'status'], 'idx_gm_student');
            });
        }
        if (! Schema::hasTable('outing_group_scan')) {
            Schema::create('outing_group_scan', function (Blueprint $t) {
                $t->integer('id', true);
                $t->integer('group_id');
                $t->string('guard_id', 20)->collation($this->keyCollation('staff', 'staff_id'));
                $t->string('gate_location', 100)->nullable();
                $t->integer('processed_count')->default(0);
                $t->integer('skipped_count')->default(0);
                $t->timestamp('created_at')->useCurrent();
                $t->foreign('group_id')->references('id')->on('outing_group');
                $t->foreign('guard_id')->references('staff_id')->on('staff');
            });
        }
        if (! Schema::hasTable('outing_group_log')) {
            Schema::create('outing_group_log', function (Blueprint $t) {
                $t->integer('group_id');
                $t->string('std_no', 20)->collation($this->keyCollation('student', 'std_no'));
                $t->dateTime('out_at')->nullable();
                $t->dateTime('in_at')->nullable();
                $t->foreign('group_id')->references('id')->on('outing_group');
                $t->foreign('std_no')->references('std_no')->on('student');
                $t->primary(['group_id', 'std_no']);
            });
        }
        if (! Schema::hasTable('outing_group_link')) {
            Schema::create('outing_group_link', function (Blueprint $t) {
                $t->enum('kind', ['standard', 'special']);
                $t->integer('ref_id');
                $t->integer('group_id');
                $t->foreign('group_id')->references('id')->on('outing_group');
                $t->primary(['kind', 'ref_id']);
                $t->index(['group_id'], 'idx_gl_group');
            });
        }
        if (! Schema::hasColumn('student', 'must_change_password')) {
            Schema::table('student', function (Blueprint $t) {
                $t->boolean('must_change_password')->default(1);
            });
        }
        if (! Schema::hasColumn('staff', 'must_change_password')) {
            Schema::table('staff', function (Blueprint $t) {
                $t->boolean('must_change_password')->default(0);
            });
        }
        if (! Schema::hasColumn('outing_request', 'type')) {
            Schema::table('outing_request', function (Blueprint $t) {
                $t->enum('type', ['checkout', 'checkin'])->default('checkout');
            });
        }
        if (! Schema::hasColumn('outing_request', 'coming_from')) {
            Schema::table('outing_request', function (Blueprint $t) {
                $t->string('coming_from', 255)->nullable();
            });
        }
        if (! Schema::hasColumn('outing_request', 'unreviewed_by_staff')) {
            Schema::table('outing_request', function (Blueprint $t) {
                $t->boolean('unreviewed_by_staff')->default(0);
            });
        }
        if (! Schema::hasColumn('standard_outing', 'arrival_request_id')) {
            Schema::table('standard_outing', function (Blueprint $t) {
                $t->integer('arrival_request_id')->nullable();
            });
        }
        if (! Schema::hasColumn('notification', 'type')) {
            Schema::table('notification', function (Blueprint $t) {
                $t->string('type', 30);
            });
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE outing_request MODIFY destination VARCHAR(255) NULL, MODIFY status ENUM('pending','approved','rejected','checked_out','checked_in','overdue','cancelled','expired') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE outing_event MODIFY event_type ENUM('submitted','approved','rejected','checked_out','checked_in','overdue_flagged','cancelled','expired') NOT NULL");
        }
        DB::table('outing_request')->where('type', 'checkin')->whereNull('coming_from')->update(['coming_from' => DB::raw('destination')]);
        // Deterministic link backfill only. Ambiguous matches remain untouched for operator review.
        foreach (DB::table('standard_outing')->whereNotNull('checked_in_at')->whereNull('arrival_request_id')->get() as $trip) {
            $matches = DB::table('outing_request')->where('std_no', $trip->std_no)->where('type', 'checkin')->where('status', 'checked_in')->whereBetween('actual_return_at', [date('Y-m-d H:i:s', strtotime($trip->checked_in_at) - 5), date('Y-m-d H:i:s', strtotime($trip->checked_in_at) + 5)])->pluck('id');
            if ($matches->count() === 1 && ! DB::table('standard_outing')->where('arrival_request_id', $matches[0])->exists()) {
                DB::table('standard_outing')->where('id', $trip->id)->update(['arrival_request_id' => $matches[0]]);
            }
        }
        foreach (Settings::DEFAULTS as $key => $value) {
            DB::table('app_setting')->insertOrIgnore(['setting_key' => $key, 'setting_value' => $value]);
        }
    }

    private function keyCollation(string $table,string $column): ?string
    {
        if (! in_array(DB::getDriverName(),['mysql', 'mariadb'])) {
            return null;
        }

        return collect(Schema::getColumns($table))->firstWhere('name',$column)['collation'];
    }

    public function down(): void
    {
        throw new RuntimeException('Data-preserving migration: automatic rollback is intentionally disabled. Restore the verified backup; see docs/UPGRADE.md.');
    }
};
