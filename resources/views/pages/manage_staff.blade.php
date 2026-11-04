@extends('layouts.app')
@section('title', 'Manage staff - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Manage staff accounts</h1>
        {!! flash_render() !!}

        @if ($generatedPassword !== null)
            <div style="border: 2px solid #333; padding: 12px; margin-bottom: 16px;">
                <p><strong>Temporary password for {!! h($passwordForStaffId) !!}:</strong>
                   <code style="font-size: 1.2em;">{!! h($generatedPassword) !!}</code></p>
                <p>This is shown once and not stored anywhere in plain text. Give it to the staff member securely. They must change it at the next login.</p>
            </div>
        @endif

        <h2>Add a new staff account</h2>
        @if ($createError)<p style="color: red;">{!! h($createError) !!}</p>
@endif
        <form method="post" action="manage_staff.php" style="margin-bottom: 24px;">
            @csrf
            <input type="hidden" name="form_action" value="create">
            <label>Staff ID<br><input type="text" name="staff_id" required></label><br><br>
            <label>Name<br><input type="text" name="name" required></label><br><br>
            <label>Role<br>
                <select name="role" required>
                    <option value="guard">Guard</option>
                    <option value="warden">Warden</option>
                    <option value="admin">Admin</option>
                </select>
            </label><br><br>
            <label>Email (optional)<br><input type="email" name="email"></label><br><br>
            <button type="submit">Create account</button>
        </form>

        <h2>Existing staff</h2>
        <table border="1" cellpadding="6" cellspacing="0">
            <tr><th>Staff ID</th><th>Name</th><th>Role</th><th>Email</th><th>Status</th><th colspan="2">Actions</th></tr>
            @foreach ($staffList as $s)
                <tr>
                    <td>{!! h($s['staff_id']) !!}</td>
                    <td>{!! h($s['name']) !!}</td>
                    <td>{!! h(ucfirst($s['role'])) !!}</td>
                    <td>{!! h($s['email'] ?? '-') !!}</td>
                    <td>{!! $s['is_active'] ? 'Active' : 'Inactive' !!}</td>
                    <td>
                        <form method="post" action="manage_staff.php" data-confirm="Reset password for {!! h($s['staff_id']) !!}? Their current password will stop working immediately." onsubmit="return confirm(this.dataset.confirm);">
                            @csrf
                            <input type="hidden" name="form_action" value="reset_password">
                            <input type="hidden" name="staff_id" value="{!! h($s['staff_id']) !!}">
                            <button type="submit" class="btn-danger">Reset password</button>
                        </form>
                    </td>
                    <td>
                        @if ($s['staff_id'] !== $account['staff_id'])
                            <form method="post" action="manage_staff.php" data-confirm="{!! $s['is_active'] ? 'Deactivate' : 'Reactivate' !!} {!! h($s['staff_id']) !!}?" onsubmit="return confirm(this.dataset.confirm);">
                                @csrf
                                <input type="hidden" name="form_action" value="toggle_active">
                                <input type="hidden" name="staff_id" value="{!! h($s['staff_id']) !!}">
                                <button type="submit" class="{!! $s['is_active'] ? 'btn-danger' : '' !!}">
                                    {!! $s['is_active'] ? 'Deactivate' : 'Reactivate' !!}
                                </button>
                            </form>
                        @else

                            <span style="color: #999;">(you)</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </main>

    

@endsection
