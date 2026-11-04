<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StandardOuting extends Model
{
    public $timestamps = false;

    protected $table = 'standard_outing';

    protected $guarded = [];

    public function student()
    {
        return $this->belongsTo(Student::class, 'std_no', 'std_no');
    }
}
