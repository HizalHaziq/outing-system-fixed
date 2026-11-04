<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

abstract class Account extends Authenticatable
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password_hash', 'ic_no'];

    protected $casts = ['is_active' => 'boolean', 'must_change_password' => 'boolean'];

    public function getAuthPasswordName()
    {
        return 'password_hash';
    }

    public function getRememberTokenName()
    {
        return '';
    }

    public function roleName(): string
    {
        return $this instanceof Student ? 'student' : $this->role;
    }

    public function identifier(): string
    {
        return $this instanceof Student ? $this->std_no : $this->staff_id;
    }
}
