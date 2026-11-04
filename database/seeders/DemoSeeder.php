<?php

namespace Database\Seeders;

use App\Models\Staff;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('DemoSeeder is restricted to local/testing.');
        }
        foreach (['admin' => 'ADM001', 'warden' => 'STF001', 'guard' => 'GRD001'] as $role => $id) {
            Staff::firstOrCreate(['staff_id' => $id], ['name' => ucfirst($role).' Demo', 'role' => $role, 'email' => $role.'@example.test', 'password_hash' => Hash::make('Staff@123'), 'is_active' => 1, 'must_change_password' => 0, 'created_at' => now()]);
        }
        for ($i = 1; $i <= 5; $i++) {
            Student::firstOrCreate(['std_no' => '202600000'.$i], ['std_name' => 'Student Demo '.$i, 'ic_no' => '080101-01-000'.$i, 'email' => 'student'.$i.'@example.test', 'program' => 'Foundation Studies', 'password_hash' => Hash::make('Student@123'), 'is_active' => 1, 'must_change_password' => $i === 2 ? 1 : 0, 'created_at' => now()]);
        }
    }
}
