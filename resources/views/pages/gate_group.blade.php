@extends('layouts.app')
@section('title', 'Group gate - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Group: {!! h($group['lead_name']) !!} ({!! h($group['lead_std_no']) !!})</h1>
        <p style="color:green;"><strong>&#10003; Live Master QR verified.</strong></p>
        <p>Curfew: {!! h(app(\App\Services\Curfew::class)->label()) !!} &mdash; <strong>{!! app(\App\Services\Curfew::class)->active() ? 'Active now' : 'Not active now' !!}</strong></p>
        <p><strong>{!! $view['phase'] === 'return' ? 'Returning: some of this group are still out.' : 'Leaving: no one from this group is out right now.' !!}</strong></p>
        {!! flash_render() !!}

        <form method="post" action="gate_action.php">
<input type="hidden" name="verification_id" value="{{ $gateGrant }}">
<label class="identity-check"><input type="checkbox" name="identity_confirmed" value="1" required> I checked the student identity against the photo or student ID.</label>
            @csrf
            <input type="hidden" name="action" value="group_process">
            <input type="hidden" name="group_id" value="{!! (int) $groupId !!}">

            <table border="1" cellpadding="6" cellspacing="0" class="group-table">
                <thead><tr><th></th><th>Photo</th><th>Student</th><th>Role</th><th>What will happen</th></tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td><input type="checkbox" name="members[]" value="{!! h($r['std_no']) !!}" {!! $r['actionable'] ? 'checked' : 'disabled' !!}></td>
                        <td>{!! student_photo_html($r['std_no'], $r['std_name'], 72) !!}</td>
                        <td>
                            <strong>{!! h($r['std_name']) !!}</strong><br>
                            {!! h($r['std_no']) !!}<br>
                            <span style="color:#666;">{!! h($r['route']['student']['program'] ?? '') !!}</span>
                        </td>
                        <td>{!! h($r['role']) !!}</td>
                        <td><span style="color:{!! $toneColor[$r['tone']] !!};">{!! h($r['note']) !!}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <p>
                <button type="submit" onclick="return confirm('Process the selected students?');">Process selected</button>
                <a href="gate.php">Cancel</a>
            </p>
        </form>
    </main>

    

@endsection
