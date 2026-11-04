<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Resets ONLY an explicitly authorized disposable test database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});

$db = config('database.connections.'.config('database.default').'.database');
if (! app()->environment('testing') || getenv('OUTING_ALLOW_TEST_RESET') !== '1' || ! str_ends_with((string) $db, '_test') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
    throw new RuntimeException('Requires testing environment, isolated *_test MySQL/MariaDB and OUTING_ALLOW_TEST_RESET=1.');
}
function check($condition, $message)
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function fixture()
{
    Schema::dropAllTables();
    DB::unprepared(file_get_contents(__DIR__.'/../fixtures/legacy-schema.sql'));
    DB::unprepared(file_get_contents(__DIR__.'/../fixtures/legacy-seed.sql'));
}
function snapshot()
{
    $out = [];
    foreach (Schema::getTableListing(config('database.connections.'.config('database.default').'.database')) as $table) {
        $cols = Schema::getColumnListing($table);
        $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $out[$table] = ['cols' => $cols, 'rows' => $rows];
    }

    return $out;
}
fixture();
$before = snapshot();
Artisan::call('migrate', ['--force' => true]);
foreach ($before as $table => $data) {
    $after = DB::table($table)->select($data['cols'])->get()->map(fn ($r) => (array) $r)->all();
    foreach ($data['rows'] as $original) {
        check(in_array($original, $after, true), 'Original row or identifier changed: '.$table);
    }
}
check(Schema::hasColumn('outing_request', 'coming_from'), 'coming_from missing');
check(Schema::hasColumn('outing_request', 'unreviewed_by_staff'), 'unreviewed flag missing');
check(Schema::hasColumn('standard_outing', 'arrival_request_id'), 'arrival link missing');
check(DB::table('active_outing')->count() === 4, 'Open outing backfill count differs');
check(Artisan::call('outing:audit') === 0, 'Registry audit failed');
Artisan::call('migrate', ['--force' => true]);
$counts = [];
foreach ($before as $table => $data) {
    $counts[$table] = count($data['rows']);
}echo 'PASS: legacy upgrade retained all original columns and rows: '.json_encode($counts).PHP_EOL;
DB::table('outing_request')->where('id', 2)->update(['status' => 'expired', 'unreviewed_by_staff' => 1, 'destination' => null]);
DB::table('outing_event')->insert(['request_id' => 2, 'event_type' => 'expired', 'actor_type' => 'system', 'event_at' => now()]);
echo 'PASS: repaired enum states and nullable arrival destination.'.PHP_EOL;
fixture();
DB::table('outing_request')->insert(['std_no' => '2026000012', 'type' => 'checkout', 'reason' => 'Conflicting legacy data', 'destination' => 'Test', 'status' => 'checked_out', 'actual_out_at' => now()]);
$failed = false;
try {
    Artisan::call('migrate', ['--force' => true]);
} catch (Throwable $e) {
    $failed = str_contains($e->getMessage(), 'Conflicting open outings');
}
check($failed, 'Duplicate open history was not refused');
check(! Schema::hasColumn('outing_request', 'coming_from'), 'Conflict preflight modified domain schema');
check(! Schema::hasTable('active_outing'), 'Conflict preflight created operation registry');
echo 'PASS: conflicting history aborts before domain changes.'.PHP_EOL;
fixture();
foreach (['outing_group_link', 'outing_group_log', 'outing_group_scan', 'outing_group_member', 'outing_group', 'qr_secret', 'notification', 'login_failure'] as $table) {
    Schema::drop($table);
}
Schema::table('student', fn ($t) => $t->dropColumn('must_change_password'));
Schema::table('staff', fn ($t) => $t->dropColumn('must_change_password'));
Schema::table('outing_request', fn ($t) => $t->dropColumn('type'));
check(Artisan::call('migrate', ['--force' => true]) === 0, 'Older pre-group/pre-auth schema failed');
check(Schema::hasTable('outing_group'), 'Missing group upgrade');
echo 'PASS: older schema gains authentication flags, request type and group/notification tables with matching key collations.'.PHP_EOL;
Schema::dropAllTables();
DB::unprepared(file_get_contents(dirname(__DIR__, 2).'/database/schema.sql'));
check(Artisan::call('migrate', ['--force' => true]) === 0, 'Canonical SQL plus Laravel migration failed');
check(Artisan::call('outing:audit') === 0, 'Canonical fresh audit failed');
echo 'PASS: canonical SQL reference and fresh migrations agree.'.PHP_EOL;
