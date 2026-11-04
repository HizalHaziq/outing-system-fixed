<?php
// Dummy data for manual testing. Run this AFTER schema.sql and
// seed_accounts.php -- it reuses the staff accounts seed_accounts.php
// creates (STF001, GRD001) as the actors on these records.
//
// Each scenario gets its own student number so you can look up a
// different student at the gate / in the UI to see each state in
// isolation, without one scenario's data bleeding into another's.
//
// Safe to run once. Running it twice will fail on the duplicate
// student numbers rather than silently doubling the data.

declare(strict_types=1);
require_once __DIR__ . '/db.php';

$pdo = get_db();

// Guard against double-seeding.
$exists = $pdo->query("SELECT 1 FROM student WHERE std_no = '2026000003'")->fetch();
if ($exists) {
    echo "Dummy data already seeded (student 2026000003 exists). Nothing to do.\n";
    echo "If you want a fresh set, re-run schema.sql and seed_accounts.php first.\n";
    exit;
}

$students = [
    ['2026000003', 'Ahmad Daniel bin Ismail',   '030315-11-0001', 'daniel3@example.test'],
    ['2026000004', 'Nur Aisyah binti Hassan',    '030420-11-0002', 'aisyah4@example.test'],
    ['2026000005', 'Muhammad Haziq bin Zulkifli','030512-11-0003', 'haziq5@example.test'],
    ['2026000006', 'Siti Nurhaliza binti Rahman','030618-11-0004', 'nurhaliza6@example.test'],
    ['2026000007', 'Farid Iskandar bin Yusof',   '030722-11-0005', 'farid7@example.test'],
    ['2026000008', 'Nurul Ain binti Kamal',      '030825-11-0006', 'ain8@example.test'],
    ['2026000009', 'Amirul Hakim bin Osman',     '030930-11-0007', 'hakim9@example.test'],
    ['2026000010', 'Wan Nur Batrisyia binti Wan','031002-11-0008', 'batrisyia10@example.test'],
    ['2026000011', 'Zul Hilmi bin Rosli',        '031105-11-0009', 'hilmi11@example.test'],
    ['2026000012', 'Aina Sofea binti Mahmud',    '031208-11-0010', 'sofea12@example.test'],
    ['2026000013', 'Danish Iman bin Roslan',     '031310-11-0011', 'danish13@example.test'],
    ['2026000014', 'Qistina Alya binti Zamri',   '031415-11-0012', 'qistina14@example.test'],
    ['2026000015', 'Rayyan Haqimi bin Fauzi',    '031520-11-0013', 'rayyan15@example.test'],
];

$insertStudent = $pdo->prepare(
    'INSERT INTO student (std_no, std_name, ic_no, email, password_hash)
     VALUES (:std_no, :std_name, :ic_no, :email, :password_hash)'
);
foreach ($students as [$stdNo, $name, $ic, $email]) {
    $insertStudent->execute([
        'std_no'        => $stdNo,
        'std_name'      => $name,
        'ic_no'         => $ic,
        'email'         => $email,
        'password_hash' => password_hash('DummyPass1!', PASSWORD_DEFAULT),
    ]);
}
echo "Created " . count($students) . " dummy students (password for all: DummyPass1!)\n";

$insertRequest = $pdo->prepare(
    'INSERT INTO outing_request
        (std_no, reason, destination, requested_out_at, expected_return_at, status,
         reviewed_by, reviewed_at, rejection_reason, actual_out_at, actual_return_at, created_at)
     VALUES
        (:std_no, :reason, :destination, :requested_out_at, :expected_return_at, :status,
         :reviewed_by, :reviewed_at, :rejection_reason, :actual_out_at, :actual_return_at, :created_at)'
);
$insertEvent = $pdo->prepare(
    'INSERT INTO outing_event (request_id, event_type, actor_type, actor_id, gate_location, note, event_at)
     VALUES (:request_id, :event_type, :actor_type, :actor_id, :gate_location, :note, :event_at)'
);

function seed_request(PDO $pdo, PDOStatement $insertRequest, PDOStatement $insertEvent, array $req, array $events): void
{
    $insertRequest->execute($req);
    $id = (int) $pdo->lastInsertId();
    foreach ($events as $e) {
        $insertEvent->execute($e + ['request_id' => $id]);
    }
}

$now = new DateTimeImmutable();
$fmt = fn(DateTimeImmutable $d) => $d->format('Y-m-d H:i:s');

// --- Special track: 2 pending, awaiting staff review ---
seed_request($pdo, $insertRequest, $insertEvent, [
    'std_no' => '2026000003', 'reason' => 'Family gathering', 'destination' => 'Kuala Terengganu',
    'requested_out_at' => $fmt($now->modify('+1 day 10:00')), 'expected_return_at' => $fmt($now->modify('+1 day 18:00')),
    'status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null,
    'actual_out_at' => null, 'actual_return_at' => null, 'created_at' => $fmt($now->modify('-2 hours')),
], [
    ['event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000003', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-2 hours'))],
]);

seed_request($pdo, $insertRequest, $insertEvent, [
    'std_no' => '2026000004', 'reason' => 'Doctor appointment', 'destination' => 'Hospital Sultanah Nur Zahirah',
    'requested_out_at' => $fmt($now->modify('+3 hours')), 'expected_return_at' => $fmt($now->modify('+6 hours')),
    'status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null,
    'actual_out_at' => null, 'actual_return_at' => null, 'created_at' => $fmt($now->modify('-30 minutes')),
], [
    ['event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000004', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-30 minutes'))],
]);

// --- Special track: 2 approved, ready to check out at the gate ---
seed_request($pdo, $insertRequest, $insertEvent, [
    'std_no' => '2026000005', 'reason' => 'Wedding (cousin)', 'destination' => 'Dungun',
    'requested_out_at' => $fmt($now->modify('+2 hours')), 'expected_return_at' => $fmt($now->modify('+10 hours')),
    'status' => 'approved', 'reviewed_by' => 'STF001', 'reviewed_at' => $fmt($now->modify('-1 hour')), 'rejection_reason' => null,
    'actual_out_at' => null, 'actual_return_at' => null, 'created_at' => $fmt($now->modify('-1 day')),
], [
    ['event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000005', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-1 day'))],
    ['event_type' => 'approved', 'actor_type' => 'staff', 'actor_id' => 'STF001', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-1 hour'))],
]);

seed_request($pdo, $insertRequest, $insertEvent, [
    'std_no' => '2026000006', 'reason' => 'Overnight study camp', 'destination' => 'UMT Campus B',
    'requested_out_at' => $fmt($now->modify('+1 hour')), 'expected_return_at' => $fmt($now->modify('+1 day')),
    'status' => 'approved', 'reviewed_by' => 'STF001', 'reviewed_at' => $fmt($now->modify('-3 hours')), 'rejection_reason' => null,
    'actual_out_at' => null, 'actual_return_at' => null, 'created_at' => $fmt($now->modify('-1 day -2 hours')),
], [
    ['event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000006', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-1 day -2 hours'))],
    ['event_type' => 'approved', 'actor_type' => 'staff', 'actor_id' => 'STF001', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-3 hours'))],
]);

// --- Special track: rejected ---
seed_request($pdo, $insertRequest, $insertEvent, [
    'std_no' => '2026000007', 'reason' => 'Concert', 'destination' => 'Kuala Lumpur',
    'requested_out_at' => $fmt($now->modify('+2 days')), 'expected_return_at' => $fmt($now->modify('+3 days')),
    'status' => 'rejected', 'reviewed_by' => 'STF001', 'reviewed_at' => $fmt($now->modify('-4 hours')),
    'rejection_reason' => 'Overnight off-campus trips need parental consent on file first.',
    'actual_out_at' => null, 'actual_return_at' => null, 'created_at' => $fmt($now->modify('-1 day')),
], [
    ['event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000007', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-1 day'))],
    ['event_type' => 'rejected', 'actor_type' => 'staff', 'actor_id' => 'STF001', 'gate_location' => null, 'note' => 'Overnight off-campus trips need parental consent on file first.', 'event_at' => $fmt($now->modify('-4 hours'))],
]);

// --- Special track: currently checked out (not yet overdue) ---
seed_request($pdo, $insertRequest, $insertEvent, [
    'std_no' => '2026000008', 'reason' => 'Grocery run', 'destination' => 'Pasar Payang',
    'requested_out_at' => $fmt($now->modify('-2 hours')), 'expected_return_at' => $fmt($now->modify('+2 hours')),
    'status' => 'checked_out', 'reviewed_by' => 'STF001', 'reviewed_at' => $fmt($now->modify('-3 hours')), 'rejection_reason' => null,
    'actual_out_at' => $fmt($now->modify('-2 hours')), 'actual_return_at' => null, 'created_at' => $fmt($now->modify('-4 hours')),
], [
    ['event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000008', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-4 hours'))],
    ['event_type' => 'approved', 'actor_type' => 'staff', 'actor_id' => 'STF001', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-3 hours'))],
    ['event_type' => 'checked_out', 'actor_type' => 'staff', 'actor_id' => 'GRD001', 'gate_location' => 'Gate: Main Gate', 'note' => null, 'event_at' => $fmt($now->modify('-2 hours'))],
]);

// --- Special track: overdue (expected_return_at already passed) ---
seed_request($pdo, $insertRequest, $insertEvent, [
    'std_no' => '2026000009', 'reason' => 'Sports tournament', 'destination' => 'Kuala Nerus',
    'requested_out_at' => $fmt($now->modify('-1 day')), 'expected_return_at' => $fmt($now->modify('-3 hours')),
    'status' => 'overdue', 'reviewed_by' => 'STF001', 'reviewed_at' => $fmt($now->modify('-1 day -1 hour')), 'rejection_reason' => null,
    'actual_out_at' => $fmt($now->modify('-1 day')), 'actual_return_at' => null, 'created_at' => $fmt($now->modify('-1 day -2 hours')),
], [
    ['event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000009', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-1 day -2 hours'))],
    ['event_type' => 'approved', 'actor_type' => 'staff', 'actor_id' => 'STF001', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-1 day -1 hour'))],
    ['event_type' => 'checked_out', 'actor_type' => 'staff', 'actor_id' => 'GRD001', 'gate_location' => 'Gate: Main Gate', 'note' => null, 'event_at' => $fmt($now->modify('-1 day'))],
    ['event_type' => 'overdue_flagged', 'actor_type' => 'system', 'actor_id' => null, 'gate_location' => null, 'note' => 'Past expected return time', 'event_at' => $fmt($now->modify('-3 hours'))],
]);

// --- Special track: completed (checked back in already) ---
seed_request($pdo, $insertRequest, $insertEvent, [
    'std_no' => '2026000010', 'reason' => 'Internship interview', 'destination' => 'Kuala Terengganu',
    'requested_out_at' => $fmt($now->modify('-2 days')), 'expected_return_at' => $fmt($now->modify('-2 days +6 hours')),
    'status' => 'checked_in', 'reviewed_by' => 'STF001', 'reviewed_at' => $fmt($now->modify('-2 days -1 hour')), 'rejection_reason' => null,
    'actual_out_at' => $fmt($now->modify('-2 days')), 'actual_return_at' => $fmt($now->modify('-2 days +5 hours')),
    'created_at' => $fmt($now->modify('-2 days -2 hours')),
], [
    ['event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000010', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-2 days -2 hours'))],
    ['event_type' => 'approved', 'actor_type' => 'staff', 'actor_id' => 'STF001', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-2 days -1 hour'))],
    ['event_type' => 'checked_out', 'actor_type' => 'staff', 'actor_id' => 'GRD001', 'gate_location' => 'Gate: Main Gate', 'note' => null, 'event_at' => $fmt($now->modify('-2 days'))],
    ['event_type' => 'checked_in', 'actor_type' => 'staff', 'actor_id' => 'GRD001', 'gate_location' => 'Gate: Main Gate', 'note' => null, 'event_at' => $fmt($now->modify('-2 days +5 hours'))],
]);

// --- Special track: cancelled by the student ---
seed_request($pdo, $insertRequest, $insertEvent, [
    'std_no' => '2026000011', 'reason' => 'Movie outing', 'destination' => 'AEON Kuala Terengganu',
    'requested_out_at' => $fmt($now->modify('+5 hours')), 'expected_return_at' => $fmt($now->modify('+9 hours')),
    'status' => 'cancelled', 'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null,
    'actual_out_at' => null, 'actual_return_at' => null, 'created_at' => $fmt($now->modify('-5 hours')),
], [
    ['event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000011', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-5 hours'))],
    ['event_type' => 'cancelled', 'actor_type' => 'student', 'actor_id' => '2026000011', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-4 hours'))],
]);

echo "Created 8 special-track requests: 2 pending, 2 approved, 1 rejected, 1 active checkout, 1 overdue, 1 completed, 1 cancelled\n";

// --- Checkin-type ("arriving during curfew") demo scenarios ---
// Separate prepared statement since these are the only rows that set
// `type` to anything other than the default.
$insertCheckinRequest = $pdo->prepare(
    'INSERT INTO outing_request
        (std_no, type, reason, destination, expected_return_at, status,
         reviewed_by, reviewed_at, created_at)
     VALUES
        (:std_no, "checkin", :reason, :destination, :expected_return_at, :status,
         :reviewed_by, :reviewed_at, :created_at)'
);

// Approved, upcoming, and the student (2026000012) is already out via
// the standard track (seeded below) -- this is the exact scenario the
// split was built for: check them in at the gate and it will NOT be
// flagged late, unlike before.
$insertCheckinRequest->execute([
    'std_no' => '2026000012', 'reason' => 'Late bus from KL', 'destination' => 'N/A',
    'expected_return_at' => $fmt($now->modify('+30 minutes')),
    'status' => 'approved', 'reviewed_by' => 'STF001', 'reviewed_at' => $fmt($now->modify('-1 hour')),
    'created_at' => $fmt($now->modify('-2 hours')),
]);
$checkinId1 = (int) $pdo->lastInsertId();
$insertEvent->execute(['request_id' => $checkinId1, 'event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000012', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-2 hours'))]);
$insertEvent->execute(['request_id' => $checkinId1, 'event_type' => 'approved', 'actor_type' => 'staff', 'actor_id' => 'STF001', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-1 hour'))]);

// Overdue -- approved to arrive, past that time, never showed up.
// No open standard/special record to attach to, so gate.php's
// stand-alone "Check in (approved arrival)" branch is what handles this one.
$insertCheckinRequest->execute([
    'std_no' => '2026000001', 'reason' => 'Flight delay', 'destination' => 'N/A',
    'expected_return_at' => $fmt($now->modify('-2 hours')),
    'status' => 'overdue', 'reviewed_by' => 'STF001', 'reviewed_at' => $fmt($now->modify('-5 hours')),
    'created_at' => $fmt($now->modify('-6 hours')),
]);
$checkinId2 = (int) $pdo->lastInsertId();
$insertEvent->execute(['request_id' => $checkinId2, 'event_type' => 'submitted', 'actor_type' => 'student', 'actor_id' => '2026000001', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-6 hours'))]);
$insertEvent->execute(['request_id' => $checkinId2, 'event_type' => 'approved', 'actor_type' => 'staff', 'actor_id' => 'STF001', 'gate_location' => null, 'note' => null, 'event_at' => $fmt($now->modify('-5 hours'))]);
$insertEvent->execute(['request_id' => $checkinId2, 'event_type' => 'overdue_flagged', 'actor_type' => 'system', 'actor_id' => null, 'gate_location' => null, 'note' => 'Past expected arrival time', 'event_at' => $fmt($now->modify('-2 hours'))]);

echo "Created 2 checkin-type (arrival permission) requests: 1 upcoming/approved, 1 overdue\n";

// --- Standard track ---
$insertStandard = $pdo->prepare(
    'INSERT INTO standard_outing
        (std_no, checked_out_at, checked_out_by, gate_location_out, checked_in_at, checked_in_by, gate_location_in, is_late_return, created_at)
     VALUES
        (:std_no, :checked_out_at, :checked_out_by, :gate_location_out, :checked_in_at, :checked_in_by, :gate_location_in, :is_late_return, :created_at)'
);

// Currently out, standard track (2 students -- for testing standard check-in at the gate)
$insertStandard->execute([
    'std_no' => '2026000012', 'checked_out_at' => $fmt($now->modify('-1 hour')), 'checked_out_by' => 'GRD001',
    'gate_location_out' => 'Main Gate', 'checked_in_at' => null, 'checked_in_by' => null, 'gate_location_in' => null,
    'is_late_return' => 0, 'created_at' => $fmt($now->modify('-1 hour')),
]);
$insertStandard->execute([
    'std_no' => '2026000013', 'checked_out_at' => $fmt($now->modify('-30 minutes')), 'checked_out_by' => 'GRD001',
    'gate_location_out' => 'Main Gate', 'checked_in_at' => null, 'checked_in_by' => null, 'gate_location_in' => null,
    'is_late_return' => 0, 'created_at' => $fmt($now->modify('-30 minutes')),
]);

// Completed, normal return (not late)
$insertStandard->execute([
    'std_no' => '2026000014', 'checked_out_at' => $fmt($now->modify('-1 day')), 'checked_out_by' => 'GRD001',
    'gate_location_out' => 'Main Gate', 'checked_in_at' => $fmt($now->modify('-1 day +3 hours')), 'checked_in_by' => 'GRD001',
    'gate_location_in' => 'Main Gate', 'is_late_return' => 0, 'created_at' => $fmt($now->modify('-1 day')),
]);

// Completed, LATE return (flagged) -- out in the evening, back after curfew started
$insertStandard->execute([
    'std_no' => '2026000015', 'checked_out_at' => $fmt($now->modify('-1 day -6 hours')), 'checked_out_by' => 'GRD001',
    'gate_location_out' => 'Main Gate', 'checked_in_at' => $fmt($now->modify('-1 day -1 hour')), 'checked_in_by' => 'GRD001',
    'gate_location_in' => 'Main Gate', 'is_late_return' => 1, 'created_at' => $fmt($now->modify('-1 day -6 hours')),
]);

echo "Created 4 standard-track records: 2 currently out, 1 normal completed, 1 late-return completed\n";

echo "\nDone. All dummy students use password: DummyPass1!\n";
echo "\nQuick reference -- who to look up for each scenario:\n";
echo "  2026000003 / 04  - pending special request (student login, or pending_requests.php as staff)\n";
echo "  2026000005 / 06  - approved, ready to check out at the gate (special)\n";
echo "  2026000007       - rejected special request (see request_history.php)\n";
echo "  2026000008       - currently checked out, special track (gate.php offers check-in)\n";
echo "  2026000009       - OVERDUE, special track (visit currently_out.php to trigger the flag+email)\n";
echo "  2026000010       - completed special request (history)\n";
echo "  2026000011       - cancelled special request (history)\n";
echo "  2026000012 / 13  - currently out, standard track (gate.php offers check-in)\n";
echo "                     2026000012 ALSO has an approved arrival permission expiring soon --\n";
echo "                     check them in and confirm it is NOT flagged late\n";
echo "  2026000001       - OVERDUE arrival permission, no open record (currently_out.php?filter=arrivals,\n";
echo "                     or look them up at gate.php for the stand-alone check-in button)\n";
echo "  2026000014       - completed standard outing, normal return\n";
echo "  2026000015       - completed standard outing, LATE return (is_late_return=1, not currently visible in UI yet)\n";
echo "  2026000002       - clean slate (from seed_accounts.php), no history yet\n";
