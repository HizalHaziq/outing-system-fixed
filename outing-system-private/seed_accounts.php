<?php
// Run once from the command line to create test accounts:
//   DB_USER=... DB_PASS=... php seed_accounts.php
// Passwords are hashed here -- never insert plaintext passwords into
// the database, not even for test/dummy data.

declare(strict_types=1);
require_once __DIR__ . '/db.php';

$pdo = get_db();

$staff = [
    ['staff_id' => 'STF001', 'name' => 'Warden Test One', 'role' => 'warden', 'password' => 'ChangeMe123!'],
    ['staff_id' => 'GRD001', 'name' => 'Guard Test One',  'role' => 'guard',  'password' => 'ChangeMe123!'],
    ['staff_id' => 'ADM001', 'name' => 'Admin Test One',  'role' => 'admin',  'password' => 'ChangeMe123!'],
];

$insertStaff = $pdo->prepare(
    'INSERT INTO staff (staff_id, name, role, password_hash)
     VALUES (:staff_id, :name, :role, :password_hash)'
);

foreach ($staff as $s) {
    $insertStaff->execute([
        'staff_id'      => $s['staff_id'],
        'name'          => $s['name'],
        'role'          => $s['role'],
        'password_hash' => password_hash($s['password'], PASSWORD_DEFAULT),
    ]);
    echo "Created staff {$s['staff_id']} ({$s['role']})\n";
}

$students = [
    ['std_no' => '2026000001', 'std_name' => 'Student Test One', 'ic_no' => '990101-00-0001', 'email' => 'student1@example.test', 'password' => 'StudentPass1!'],
    ['std_no' => '2026000002', 'std_name' => 'Student Test Two', 'ic_no' => '990202-00-0002', 'email' => 'student2@example.test', 'password' => 'StudentPass2!'],
];

$insertStudent = $pdo->prepare(
    'INSERT INTO student (std_no, std_name, ic_no, email, password_hash)
     VALUES (:std_no, :std_name, :ic_no, :email, :password_hash)'
);

foreach ($students as $s) {
    $insertStudent->execute([
        'std_no'        => $s['std_no'],
        'std_name'      => $s['std_name'],
        'ic_no'         => $s['ic_no'],
        'email'         => $s['email'],
        'password_hash' => password_hash($s['password'], PASSWORD_DEFAULT),
    ]);
    echo "Created student {$s['std_no']}\n";
}

echo "Done. Test logins use the plaintext passwords above -- change them before going live.\n";
