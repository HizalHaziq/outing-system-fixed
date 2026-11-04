<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\OutingRequest;
use App\Models\Staff;
use App\Models\Student;

class OutingRequestPolicy
{
    public function update(Account $user, OutingRequest $request): bool
    {
        return $user instanceof Student && $user->is_active && $user->std_no === $request->std_no && $request->status === 'pending';
    }

    public function review(Account $user): bool
    {
        return $user instanceof Staff && $user->is_active && $user->role === 'warden';
    }
}
