<?php

use App\Services\Notifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('active_outing')) {
            Schema::create('active_outing', function (Blueprint $t) {
                $t->string('std_no', 20)->primary();
                $t->enum('kind', ['standard', 'special']);
                $t->integer('ref_id');
                $t->unique(['kind', 'ref_id']);
            });
        }
        foreach (DB::table('standard_outing')->whereNull('checked_in_at')->get() as $r) {
            DB::table('active_outing')->insertOrIgnore(['std_no' => $r->std_no, 'kind' => 'standard', 'ref_id' => $r->id]);
        }
        foreach (DB::table('outing_request')->where('type', 'checkout')->whereIn('status', ['checked_out', 'overdue'])->get() as $r) {
            DB::table('active_outing')->insertOrIgnore(['std_no' => $r->std_no, 'kind' => 'special', 'ref_id' => $r->id]);
        }
        if (! Schema::hasTable('gate_verification')) {
            Schema::create('gate_verification', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('guard_id', 20);
                $t->string('session_hash', 64);
                $t->string('subject', 40);
                $t->string('fingerprint', 64);
                $t->boolean('via_qr');
                $t->dateTime('expires_at');
                $t->dateTime('used_at')->nullable();

            });
        }
        if (! Schema::hasTable('delivery_key')) {
            Schema::create('delivery_key', function (Blueprint $t) {
                $t->string('key', 64)->primary();
                $t->timestamp('created_at')->useCurrent();
            });
        }
        if (! Schema::hasTable('mail_outbox')) {
            Schema::create('mail_outbox', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('delivery_key', 64)->unique();
                $t->string('recipient');
                $t->string('subject');
                $t->text('body');
                $t->dateTime('sent_at')->nullable();
                $t->unsignedInteger('attempts')->default(0);
                $t->text('last_error')->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }
        // Existing installations may use a different default collation. Match each referenced key.
        foreach ([['active_outing', 'std_no', 'student'], ['gate_verification', 'guard_id', 'staff']] as [$table,$column,$parent]) {
            $parentColumn = $parent === 'staff' ? 'staff_id' : 'std_no';
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                $meta = collect(Schema::getColumns($parent))->firstWhere('name', $parentColumn);
                $collation = $meta['collation'];
                if (! preg_match('/^[a-zA-Z0-9_]+$/D', $collation)) {
                    throw new RuntimeException('Unexpected identifier collation.');
                }
                $charset = explode('_', $collation)[0];
                if (collect(Schema::getColumns($table))->firstWhere('name', $column)['collation'] !== $collation) {
                    DB::statement("ALTER TABLE `$table` MODIFY `$column` VARCHAR(20) CHARACTER SET $charset COLLATE $collation NOT NULL");
                }
            }
            if (! collect(Schema::getForeignKeys($table))->contains(fn ($fk) => $fk['columns'] === [$column])) {
                Schema::table($table, function (Blueprint $t) use ($column, $parent, $parentColumn) {
                    $t->foreign($column)->references($parentColumn)->on($parent);
                });
            }
        }
        // Preserve duplicate historical notifications, reserving their delivery key without deleting rows.
        foreach (DB::table('notification')->orderBy('id')->cursor() as $n) {
            DB::table('delivery_key')->insertOrIgnore(['key' => Notifier::key($n->std_no, $n->type, $n->reference_table, $n->reference_id)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a verified backup for rollback. Operation and delivery history must be retained.');
    }
};
