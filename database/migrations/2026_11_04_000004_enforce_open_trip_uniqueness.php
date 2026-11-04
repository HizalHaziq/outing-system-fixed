<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'standard_outing' => 'checked_in_at IS NULL',
            'outing_request' => "type = 'checkout' AND status IN ('checked_out','overdue')",
        ] as $table => $condition) {
            $index = $table.'_one_open_per_student';
            if (DB::getDriverName() === 'sqlite') {
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS $index ON $table (std_no) WHERE $condition");
            } else {
                if (! Schema::hasColumn($table, 'open_student')) {
                    $collation = collect(Schema::getColumns('student'))->firstWhere('name', 'std_no')['collation'];
                    Schema::table($table, function (Blueprint $t) use ($condition, $collation) {
                        $t->string('open_student', 20)->collation($collation)->nullable()->storedAs("CASE WHEN $condition THEN std_no ELSE NULL END");
                    });
                }
                if (! Schema::hasIndex($table, $index)) {
                    Schema::table($table, fn (Blueprint $t) => $t->unique('open_student', $index));
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Keep outing integrity constraints. Use the documented backup recovery process for rollback.');
    }
};
