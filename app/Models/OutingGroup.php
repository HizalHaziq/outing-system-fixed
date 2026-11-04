<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutingGroup extends Model
{
    public $timestamps = false;

    protected $table = 'outing_group';

    protected $guarded = [];

    public function student()
    {
        return $this->belongsTo(Student::class, 'lead_std_no', 'std_no');
    }
}
