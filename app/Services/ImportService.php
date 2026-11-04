<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ImportService
{
    public function import(string $path): array
    {
        $rows = $this->parse_student_csv($path);
        if (count($rows) > 5000) {
            RequestService::fail('Import at most 5,000 students per file.');
        }
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        $seen = [];
        DB::transaction(function () use ($rows, &$result, &$seen) {
            foreach ($rows as $i => $data) {
                $data['ic_no'] = $this->normalize_ic($data['ic_no']);
                foreach ($data as $k => $v) {
                    if ($v === '') {
                        $data[$k] = null;
                    }
                }
                $v = Validator::make($data, ['std_no' => ['required', 'regex:/^[A-Za-z0-9_-]{1,20}$/D'], 'std_name' => 'required|string|max:100', 'ic_no' => ['required', 'regex:/^\d{6}-\d{2}-\d{4}$/D'], 'email' => 'nullable|email|max:100', 'program' => 'nullable|string|max:100', 'phone' => 'nullable|string|max:20', 'emergency_contact_name' => 'nullable|string|max:100', 'emergency_contact_phone' => 'nullable|string|max:20']);
                if ($v->fails() || isset($seen[$data['std_no'] ?? ''])) {
                    $result['errors'][] = ['row' => $i + 2, 'std_no' => $data['std_no'] ?? '', 'message' => $v->fails() ? $v->errors()->first() : 'Duplicate student number in this file.'];

                    continue;
                }
                $seen[$data['std_no']] = true;
                $s = Student::where('std_no', $data['std_no'])->lockForUpdate()->first();
                if ($s) {
                    $s->update($v->validated());
                    $result['updated']++;
                } else {
                    Student::create($v->validated() + ['password_hash' => Hash::make(substr(preg_replace('/\D/', '', $data['ic_no']), -6)), 'is_active' => 1, 'must_change_password' => 1, 'created_at' => now()]);
                    $result['created']++;
                }
            }
        }, 3);

        return $result;
    }

    const STUDENT_CSV_ALIASES = [
        'std_no' => ['stdno', 'studentno', 'studentid', 'matricno', 'matric'],
        'std_name' => ['stdname', 'studentname', 'name', 'fullname'],
        'ic_no' => ['noic', 'icno', 'ic', 'nric', 'icnumber'],
        'program' => ['progcode', 'program', 'programme', 'progname'],
        'email' => ['emailuitm', 'email', 'emailaddress'],
        'phone' => ['nophone', 'phone', 'phoneno', 'telno', 'handphone'],
        'emergency_contact_name' => ['emergencycontactname'],
        'emergency_contact_phone' => ['emergencycontactphone'],
    ];

    public function normalize_csv_header(string $h): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h; // UTF-8 BOM on first header

        return preg_replace('/[^a-z0-9]/', '', strtolower($h)) ?? '';
    }

    /** 12 digits -> YYMMDD-PB-####, matching how IC numbers are stored; anything else is kept trimmed. */
    public function normalize_ic(string $ic): string
    {
        $digits = preg_replace('/\D/', '', $ic) ?? '';
        if (strlen($digits) === 12) {
            return substr($digits, 0, 6).'-'.substr($digits, 6, 2).'-'.substr($digits, 8, 4);
        }

        return trim($ic);
    }

    /**
     * Parses an uploaded CSV file into row arrays keyed by field name
     * (std_no, std_name, ic_no, program, email, phone, ...), using
     * STUDENT_CSV_ALIASES to map whatever headers the source export uses.
     * Throws if the required fields (std_no, std_name, ic_no) can't be found.
     */
    public function parse_student_csv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('The file appears to be empty.');
        }

        // normalized header -> column index (first occurrence wins on duplicates)
        $index = [];
        foreach ($header as $i => $h) {
            $n = $this->normalize_csv_header((string) $h);
            if ($n !== '' && ! isset($index[$n])) {
                $index[$n] = $i;
            }
        }

        // field -> ordered list of column indexes that can supply it
        $map = [];
        foreach (self::STUDENT_CSV_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($index[$alias])) {
                    $map[$field][] = $index[$alias];
                }
            }
        }

        $missing = array_values(array_filter(
            ['std_no', 'std_name', 'ic_no'],
            static fn ($f) => empty($map[$f])
        ));
        if ($missing) {
            fclose($handle);
            throw new \RuntimeException(
                'Could not find column(s) for: '.implode(', ', $missing)
                .'. Headers found: '.implode(', ', array_map('strval', $header))
            );
        }

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($line) === 1 && trim((string) $line[0]) === '') {
                continue; // skip blank lines
            }
            $row = [];
            foreach (self::STUDENT_CSV_ALIASES as $field => $_) {
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
}
