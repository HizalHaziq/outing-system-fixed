<?php

use App\Models\Staff;
use App\Models\Student;
use App\Services\GroupService;
use App\Services\Notifier;
use App\Services\QrService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
$isolation = DB::select("SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation','tx_isolation')");
if (! $isolation || strtoupper($isolation[0]->Value) !== 'READ-COMMITTED') {
    throw new RuntimeException('Gate concurrency requires the configured READ COMMITTED isolation level.');
}
Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00'));
function check($c, $m)
{
    if (! $c) {
        throw new RuntimeException($m);
    }
}
if (($argv[1] ?? null) === 'worker') {
    [$script,$mode,$task,$barrier,$guardId,$grant] = $argv;
    $until = microtime(true) + 10;
    file_put_contents($barrier.'.ready.'.$guardId, 'ready');
    while (! is_file($barrier) && microtime(true) < $until) {
        usleep(1000);
    }
    if (! is_file($barrier)) {
        throw new RuntimeException('Barrier timed out');
    }
    try {
        $g = Staff::where('staff_id', $guardId)->firstOrFail();
        $qr = app(QrService::class);
        if ($task === 'qr') {
            $qr->scan($grant, $g, 'test-session');
        } elseif ($task === 'notify') {
            app(Notifier::class)->student('RACE1', 'race', 'Concurrent delivery', 'standard_outing', 99);
        } elseif ($task === 'group') {
            $qr->perform($grant, $g, 'test-session', ['action' => 'group_process', 'group_id' => 1, 'members' => ['RACE1', 'RACE2']]);
        } else {
            $qr->perform($grant, $g, 'test-session', ['action' => 'standard_check_out', 'std_no' => 'RACE1']);
        }
        echo 'success';
        exit(0);
    } catch (ValidationException $e) {
        echo 'rejected';
        exit(0);
    }
}
function race(string $task, array $grants): array
{
    $barrier = tempnam(sys_get_temp_dir(), 'outing-race-');
    unlink($barrier);
    $processes = [];
    foreach (['RACEGUARD1', 'RACEGUARD2'] as $i => $guard) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'worker', $task, $barrier, $guard, $grants[$i]], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $processes[] = [$p, $pipes, $guard];
    }
    $until = microtime(true) + 10;
    while ((! is_file($barrier.'.ready.RACEGUARD1') || ! is_file($barrier.'.ready.RACEGUARD2')) && microtime(true) < $until) {
        usleep(1000);
    }touch($barrier);
    $results = [];
    foreach ($processes as [$p,$pipes,$guard]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        check($code === 0, 'Worker failed: '.$err.$out);
        $results[] = $out;
        @unlink($barrier.'.ready.'.$guard);
    }@unlink($barrier);
    sort($results);

    return $results;
}
Artisan::call('migrate:fresh', ['--force' => true]);
$students = [];
$guards = [];
foreach (['RACE1', 'RACE2'] as $std) {
    $students[] = Student::create(['std_no' => $std, 'std_name' => $std, 'ic_no' => '080101-01-1234', 'password_hash' => Hash::make('test-password'), 'is_active' => 1, 'must_change_password' => 0, 'created_at' => now()]);
}
foreach (['RACEGUARD1', 'RACEGUARD2'] as $id) {
    $guards[] = Staff::create(['staff_id' => $id, 'name' => $id, 'role' => 'guard', 'password_hash' => Hash::make('test-password'), 'is_active' => 1, 'created_at' => now()]);
}
$qr = app(QrService::class);
$grants = array_map(fn ($g) => $qr->grant('S:RACE1', $g, 'test-session', false), $guards);
check(race('individual', $grants) === ['rejected', 'success'], 'Concurrent individual checkout did not select one winner');
check(DB::table('standard_outing')->count() === 1 && DB::table('active_outing')->count() === 1, 'Duplicate individual departure');
echo 'PASS: two independent guards concurrently checking out one student create exactly one trip.'.PHP_EOL;
$trip = DB::table('standard_outing')->value('id');
$qr->perform($qr->grant('S:RACE1', $guards[0], 'test-session', false), $guards[0], 'test-session', ['action' => 'standard_check_in', 'std_no' => 'RACE1', 'record_id' => $trip]);
$token = $qr->issue($students[0], 'S')['token'];
check(race('qr', [$token, $token]) === ['rejected', 'success'], 'QR replay race did not select one winner');
echo 'PASS: concurrent verification consumes a QR window once.'.PHP_EOL;
$groups = app(GroupService::class);
$groups->act($students[0], ['action' => 'create']);
$groups->act($students[0], ['action' => 'invite', 'group_id' => 1, 'member_std_no' => 'RACE2']);
$mid = DB::table('outing_group_member')->value('id');
$groups->act($students[1], ['action' => 'accept', 'member_row_id' => $mid]);
$grants = array_map(fn ($g) => $qr->grant('G:1', $g, 'test-session', true), $guards);
check(race('group', $grants) === ['rejected', 'success'], 'Group batch race did not select one winner');
check(DB::table('active_outing')->count() === 2, 'Duplicate or incomplete group departure');
check(DB::table('outing_group_scan')->count() === 1, 'Duplicate batch scan');
echo 'PASS: concurrent group batches create one atomic departure per accepted participant.'.PHP_EOL;
race('notify', ['unused', 'unused']);
check(DB::table('notification')->where('type', 'race')->count() === 1, 'Concurrent duplicate notification');
echo 'PASS: concurrent notification delivery creates one bell notification.'.PHP_EOL;
check(Artisan::call('outing:audit') === 0, 'Registry inconsistent after races');
echo 'PASS: registry audit after all races.'.PHP_EOL;
