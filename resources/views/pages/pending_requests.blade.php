@extends('layouts.app')
@section('title', 'Pending approval - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Pending special requests</h1>
        {!! flash_render() !!}

        @if (!$requests)
            <p>No pending requests right now.</p>
        @else

            <table border="1" cellpadding="6" cellspacing="0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Destination</th>
                        <th>Coming from</th>
                        <th>Reason</th>
                        <th>Time</th>
                        <th>Submitted</th>
                        <th>Waiting</th>
                        @if ($canDecide)<th>Decision</th>
@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $r)
                        <tr>
                            <td>{!! h($r['std_name']) !!} ({!! h($r['std_no']) !!})</td>
                            <td>{!! h(request_type_label($r['type'])) !!}</td>
                            <td>{!! h($r['destination'] ?? '') !!}</td>
                            <td>{!! h($r['coming_from'] ?? '') !!}</td>
                            <td>{!! h($r['reason']) !!}</td>
                            <td>
                                @if ($r['type'] === 'checkin')
                                    Arriving: {!! h($r['expected_return_at']) !!}
                                @else

                                    Leaving: {!! h($r['requested_out_at']) !!}
                                    @if ($r['expected_return_at'])
                                        <br>Back by: {!! h($r['expected_return_at']) !!}
                                    @endif
                                @endif
                            </td>
                            <td>{!! h($r['created_at']) !!}</td>
                            @php
$waitMin  = max(0, (int) floor((time() - strtotime($r['created_at'])) / 60));
                                $waitText = $waitMin >= 60 ? intdiv($waitMin, 60) . 'h ' . ($waitMin % 60) . 'm' : $waitMin . 'm';
                                $dueTs    = $r['type'] === 'checkin' ? app(\App\Services\Curfew::class)->reviewDeadline($r)->timestamp : null;
                                $dueSoon  = $dueTs !== null && $dueTs - time() <= 1800;
@endphp
                            <td{!! $dueSoon ? ' class="row-flag"' : '' !!}>
                                {!! h($waitText) !!}
                                @if ($dueTs !== null)
                                    <br><strong>Decide by {!! h(date('Y-m-d H:i', $dueTs)) !!}</strong>
                                    @if ($dueSoon)<br>Expires soon &mdash; cannot be decided after this.
@endif
                                @endif
                            </td>
                            @if ($canDecide)
                            <td>
                                <form method="post" action="review_request.php">
                                    @csrf
                                    <input type="hidden" name="request_id" value="{!! (int) $r['id'] !!}">
                                    <input type="text" name="rejection_reason" placeholder="Reason (required to reject)">
                                    <br>
                                    <button type="submit" name="decision" value="approved"
                                            onclick="return confirm('Approve this outing request?');">Approve</button>
                                    <button type="submit" name="decision" value="rejected" class="btn-danger"
                                            onclick="return confirm('Reject this outing request?');">Reject</button>
                                </form>
                            </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>

    

@endsection
