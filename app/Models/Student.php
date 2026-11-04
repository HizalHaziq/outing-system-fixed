<?php

namespace App\Models;

class Student extends Account
{
    protected $table = 'student';

    public function requests()
    {
        return $this->hasMany(OutingRequest::class, 'std_no', 'std_no');
    }

    public function outings()
    {
        return $this->hasMany(StandardOuting::class, 'std_no', 'std_no');
    }
}
