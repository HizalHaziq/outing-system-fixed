<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutingRequest extends Model
{
    public $timestamps = false;

    protected $table = 'outing_request';

    protected $guarded = [];

    public function student()
    {
        return $this->belongsTo(Student::class, 'std_no', 'std_no');
    }
}
