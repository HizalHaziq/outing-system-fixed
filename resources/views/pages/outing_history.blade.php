@extends('layouts.app')
@section('title', 'Outing history - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Outing History</h1>
        {!! flash_render() !!}

        @if ($canViewSpecial)
        {!! tab_nav([
            'standard' => ['Standard outing history', 'outing_history.php?view=standard'],
            'special'  => ['Special outing history', 'outing_history.php?view=special'],
        ], $view) !!}
        @endif

        @if ($view === 'standard')

            <p><strong>{!! $lateCount7d !!}</strong> late return{!! $lateCount7d === 1 ? '' : 's' !!} in the last 7 days.</p>

            {!! tab_nav([
                ''     => ['All', 'outing_history.php?view=standard'],
                'late' => ['Late returns only', 'outing_history.php?view=standard&filter=late'],
            ], $filter) !!}

            {!! date_filter_form('outing_history.php', $dateRange['from'], $dateRange['to'], ['view' => 'standard', 'filter' => $filter], true) !!}

            @if (!$records)
                <p>No records match this filter.</p>
            @else

                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Group</th>
                            <th>Checked out</th>
                            <th>Checked out by</th>
                            <th>Checked in</th>
                            <th>Checked in by</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $r)
                            @php
$stillOut = $r['checked_in_at'] === null;
                                $statusLabel = $stillOut ? 'Out' : ($r['is_late_return'] ? 'Late return' : 'Returned on time');
                                if (!$stillOut && !empty($r['arrival_request_id'])) {
                                    $statusLabel .= ' (arrival permission used)';
                                }
@endphp
                            <tr{!! $r['is_late_return'] ? ' class="row-flag"' : '' !!}>
                                <td>{!! h($r['std_name']) !!} ({!! h($r['std_no']) !!})</td>
                                <td>{!! $r['group_id'] ? 'Group (lead: ' . h($r['group_lead_name']) . ')' : '' !!}</td>
                                <td>{!! h($r['checked_out_at']) !!}</td>
                                <td>{!! h($r['checked_out_by']) !!}</td>
                                <td>{!! h($r['checked_in_at'] ?? '') !!}</td>
                                <td>{!! h($r['checked_in_by'] ?? '') !!}</td>
                                <td>{!! h($statusLabel) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

        @else


            <form method="get" action="outing_history.php" class="no-print">
                <input type="hidden" name="view" value="special">
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

            {!! date_filter_form('outing_history.php', $dateRange['from'], $dateRange['to'], ['view' => 'special', 'status' => $statusFilter, 'type' => $typeFilter], true) !!}

            @if (!$requests)
                <p>No requests match this filter.</p>
            @else

                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Group</th>
                            <th>Type</th>
                            <th>Destination</th>
                            <th>Coming from</th>
                            <th>Reason</th>
                            <th>Leaving</th>
                            <th>Arriving/back by</th>
                            <th>Status</th>
                            <th>Reviewed by</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $r)
                            <tr>
                                <td>{!! h($r['std_name']) !!} ({!! h($r['std_no']) !!})</td>
                                <td>{!! $r['group_id'] ? 'Group (lead: ' . h($r['group_lead_name']) . ')' : '' !!}</td>
                                <td>{!! h(request_type_label($r['type'])) !!}</td>
                                <td>{!! h($r['destination'] ?? '') !!}</td>
                                <td>{!! h($r['coming_from'] ?? '') !!}</td>
                                <td>{!! h($r['reason']) !!}</td>
                                <td>{!! h($r['requested_out_at'] ?? '') !!}</td>
                                <td>{!! h($r['expected_return_at'] ?? '') !!}</td>
                                <td>{!! h(status_label($r['status'])) !!}</td>
                                <td>{!! h($r['reviewed_by'] ?? '') !!}</td>
                                <td>{!! h($r['rejection_reason'] ?? '') !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

        @endif
    </main>

    

@endsection
