@extends('layouts.app')
@section('title', 'Request status - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Request status</h1>
        {!! flash_render() !!}

        <form method="get" action="request_status.php" class="no-print">
            <label>
                Filter by type
                <select name="type" onchange="this.form.submit()">
                    <option value="">All</option>
                    @foreach (OUTING_REQUEST_TYPES as $t)
                        <option value="{!! h($t) !!}" {!! $typeFilter === $t ? 'selected' : '' !!}>
                            {!! h(request_type_label($t)) !!}
                        </option>
                    @endforeach
                </select>
            </label>
            <label>
                Filter by status
                <select name="status" onchange="this.form.submit()">
                    <option value="">All</option>
                    @foreach ($validStatuses as $s)
                        <option value="{!! h($s) !!}" {!! $statusFilter === $s ? 'selected' : '' !!}>
                            {!! h(status_label($s)) !!}
                        </option>
                    @endforeach
                </select>
            </label>
        </form>

        {!! date_filter_form('request_status.php', $dateRange['from'], $dateRange['to'], ['status' => $statusFilter, 'type' => $typeFilter], true) !!}

        @if (!$requests)
            <p>No requests match this filter.</p>
        @else

            <table border="1" cellpadding="6" cellspacing="0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Destination</th>
                        <th>Coming from</th>
                        <th>Reason</th>
                        <th>Leaving</th>
                        <th>Arriving/back by</th>
                        <th>Status</th>
                        <th>Reviewed by</th>
                        <th>Response time</th>
                        <th>Notes</th>
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
                            <td>{!! h($r['requested_out_at'] ?? '') !!}</td>
                            <td>{!! h($r['expected_return_at'] ?? '') !!}</td>
                            <td>{!! h(status_label($r['status'])) !!}</td>
                            <td>{!! h($r['reviewed_by'] ?? '') !!}</td>
                            <td>
                                @php
if ($r['reviewed_at']) {
                                    $m = max(0, (int) floor((strtotime($r['reviewed_at']) - strtotime($r['created_at'])) / 60));
                                    echo h($m >= 60 ? intdiv($m, 60) . 'h ' . ($m % 60) . 'm' : $m . 'm');
                                } elseif ($r['status'] === 'expired') {
                                    echo '<strong>Not reviewed</strong>';
                                }
@endphp
                            </td>
                            <td>{!! h($r['rejection_reason'] ?? '') !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>

    

@endsection
