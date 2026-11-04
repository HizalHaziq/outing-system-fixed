<?php
// Bulk student import from CSV. Existing std_no -> update the record,
// leave password_hash untouched (never reset a password someone may
// have already changed). New std_no -> insert, with a password derived
// from their IC number so students can log in immediately without
// waiting on an email.
//
// Deliberately does the "does this std_no already exist" check as a
// separate SELECT per row rather than a single INSERT ... ON DUPLICATE
// KEY UPDATE, even though that's slower for very large files -- it's
// what lets us give an accurate created/updated count and skip touching
// password_hash on updates without relying on MySQL's row-count quirks
// for ON DUPLICATE KEY UPDATE (0/1/2 depending on whether the row
// actually changed, which is easy to get wrong).

declare(strict_types=1);
require_once __DIR__ . '/db.php';

/**
 * bcrypt cost for the INITIAL (IC-derived) passwords only. PHP 8.4's
 * default cost is 12, which is slow enough (~0.5s/row on a typical PC)
 * to make big imports take minutes. 8 is ~16x faster. This is safe
 * because auth.php already re-hashes at the full default cost on the
 * student's first successful login (password_needs_rehash), and they
 * must change this password then anyway.
 */
const IMPORT_HASH_COST = 8;

/** Last 6 digits of the IC number, used as the initial password. */
function initial_password_from_ic(string $icNo): string
{
    $digits = preg_replace('/\D/', '', $icNo) ?? '';
    return substr($digits, -6) ?: 'Outing123';
}

/**
 * @param array<int, array<string, string>> $rows Each row keyed by
 *   column name: std_no, std_name, ic_no, email, program, phone,
 *   emergency_contact_name, emergency_contact_phone.
 * @return array{created:int, updated:int, errors:array<int, array{row:int, std_no:string, message:string}>}
 */
function import_students_csv(array $rows): array
{
    // Each NEW student needs a password hash (deliberately slow), so big
    // files can run far past PHP's default 30s limit. Lift it for this call.
    @set_time_limit(0);
    ignore_user_abort(true);

    $pdo = get_db();
    $result = ['created' => 0, 'updated' => 0, 'errors' => []];

    $findStmt = $pdo->prepare('SELECT id FROM student WHERE std_no = :std_no LIMIT 1');

    $insertStmt = $pdo->prepare(
        'INSERT INTO student
            (std_no, std_name, ic_no, email, program, phone,
             emergency_contact_name, emergency_contact_phone, password_hash, is_active)
         VALUES
            (:std_no, :std_name, :ic_no, :email, :program, :phone,
             :emergency_contact_name, :emergency_contact_phone, :password_hash, 1)'
    );

    $updateStmt = $pdo->prepare(
        'UPDATE student SET
            std_name = :std_name,
            ic_no = :ic_no,
            email = :email,
            program = :program,
            phone = :phone,
            emergency_contact_name = :emergency_contact_name,
            emergency_contact_phone = :emergency_contact_phone
         WHERE std_no = :std_no'
        // password_hash intentionally not touched here.
    );

    $pdo->beginTransaction();
    try {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // +1 for zero-index, +1 for the header row

            $stdNo   = trim($row['std_no'] ?? '');
            $stdName = trim($row['std_name'] ?? '');
            $icNo    = normalize_ic((string) ($row['ic_no'] ?? ''));
            $email   = trim($row['email'] ?? '');

            if ($stdNo === '' || $stdName === '' || $icNo === '') {
                $result['errors'][] = ['row' => $rowNum, 'std_no' => $stdNo, 'message' => 'Missing required field (std_no, std_name, or ic_no).'];
                continue;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result['errors'][] = ['row' => $rowNum, 'std_no' => $stdNo, 'message' => "Invalid email: {$email}"];
                continue;
            }

            $fields = [
                'std_no'                  => $stdNo,
                'std_name'                => mb_substr($stdName, 0, 100),
                'ic_no'                   => $icNo,
                'email'                   => $email !== '' ? $email : null,
                'program'                 => mb_substr(trim($row['program'] ?? ''), 0, 100) ?: null,
                'phone'                   => mb_substr(preg_replace('/[^0-9+]/', '', $row['phone'] ?? '') ?? '', 0, 20) ?: null,
                'emergency_contact_name'  => trim($row['emergency_contact_name'] ?? '') ?: null,
                'emergency_contact_phone' => trim($row['emergency_contact_phone'] ?? '') ?: null,
            ];

            $findStmt->execute(['std_no' => $stdNo]);
            $exists = (bool) $findStmt->fetch();

            if ($exists) {
                $updateStmt->execute($fields);
                $result['updated']++;
            } else {
                $fields['password_hash'] = password_hash(initial_password_from_ic($icNo), PASSWORD_BCRYPT, ['cost' => IMPORT_HASH_COST]);
                $insertStmt->execute($fields);
                $result['created']++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
}

/**
 * CSV header -> field mapping. Headers are normalized (lowercase, letters
 * and digits only), so "Std_No", "std no" and "STDNO" all match "stdno".
 * Where several columns can feed one field, the first NON-EMPTY one in
 * this list wins (e.g. email_uitm, then email). Columns not listed here
 * are ignored on purpose (salary, address, parent occupation, tokens...).
 * To use the full programme name instead of the code, put 'progname'
 * before 'progcode' in the 'program' list.
 */
const STUDENT_CSV_ALIASES = [
    'std_no'                  => ['stdno', 'studentno', 'studentid', 'matricno', 'matric'],
    'std_name'                => ['stdname', 'studentname', 'name', 'fullname'],
    'ic_no'                   => ['noic', 'icno', 'ic', 'nric', 'icnumber'],
    'program'                 => ['progcode', 'program', 'programme', 'progname'],
    'email'                   => ['emailuitm', 'email', 'emailaddress'],
    'phone'                   => ['nophone', 'phone', 'phoneno', 'telno', 'handphone'],
    'emergency_contact_name'  => ['emergencycontactname'],
    'emergency_contact_phone' => ['emergencycontactphone'],
];

function normalize_csv_header(string $h): string
{
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h; // UTF-8 BOM on first header
    return preg_replace('/[^a-z0-9]/', '', strtolower($h)) ?? '';
}

/** 12 digits -> YYMMDD-PB-####, matching how IC numbers are stored; anything else is kept trimmed. */
function normalize_ic(string $ic): string
{
    $digits = preg_replace('/\D/', '', $ic) ?? '';
    if (strlen($digits) === 12) {
        return substr($digits, 0, 6) . '-' . substr($digits, 6, 2) . '-' . substr($digits, 8, 4);
    }
    return trim($ic);
}

/**
 * Parses an uploaded CSV file into row arrays keyed by field name
 * (std_no, std_name, ic_no, program, email, phone, ...), using
 * STUDENT_CSV_ALIASES to map whatever headers the source export uses.
 * Throws if the required fields (std_no, std_name, ic_no) can't be found.
 */
function parse_student_csv(string $filePath): array
{
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Could not read the uploaded file.');
    }

    $header = fgetcsv($handle, 0, ',', '"', '');
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException('The file appears to be empty.');
    }

    // normalized header -> column index (first occurrence wins on duplicates)
    $index = [];
    foreach ($header as $i => $h) {
        $n = normalize_csv_header((string) $h);
        if ($n !== '' && !isset($index[$n])) {
            $index[$n] = $i;
        }
    }

    // field -> ordered list of column indexes that can supply it
    $map = [];
    foreach (STUDENT_CSV_ALIASES as $field => $aliases) {
        foreach ($aliases as $alias) {
            if (isset($index[$alias])) {
                $map[$field][] = $index[$alias];
            }
        }
    }

    $missing = array_values(array_filter(
        ['std_no', 'std_name', 'ic_no'],
        static fn($f) => empty($map[$f])
    ));
    if ($missing) {
        fclose($handle);
        throw new RuntimeException(
            'Could not find column(s) for: ' . implode(', ', $missing)
            . '. Headers found: ' . implode(', ', array_map('strval', $header))
        );
    }

    $rows = [];
    while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if (count($line) === 1 && trim((string) $line[0]) === '') {
            continue; // skip blank lines
        }
        $row = [];
        foreach (STUDENT_CSV_ALIASES as $field => $_) {
            $value = '';
            foreach ($map[$field] ?? [] as $col) {
                $candidate = trim((string) ($line[$col] ?? ''));
                if ($candidate !== '' && strcasecmp($candidate, 'null') !== 0) {
                    $value = $candidate;
                    break;
                }
            }
            $row[$field] = $value;
        }
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}
