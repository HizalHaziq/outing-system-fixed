<?php

use App\Models\Staff;
use App\Models\Student;

return [
    'defaults' => ['guard' => 'student', 'passwords' => 'students'],
    'guards' => ['student' => ['driver' => 'session', 'provider' => 'students'], 'staff' => ['driver' => 'session', 'provider' => 'staff']],
    'providers' => ['students' => ['driver' => 'eloquent', 'model' => Student::class], 'staff' => ['driver' => 'eloquent', 'model' => Staff::class]],
];
