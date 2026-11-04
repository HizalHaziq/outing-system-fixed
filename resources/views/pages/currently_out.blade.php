@extends('layouts.app')
@section('title', 'Currently out - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Currently out</h1>
        {!! flash_render() !!}

        {!! tab_nav([
            ''                => ['All', 'currently_out.php'],
            'standard'        => ['Standard only', 'currently_out.php?filter=standard'],
            'special'         => ['Special only', 'currently_out.php?filter=special'],
            'arrivals'        => ['Pending arrivals only', 'currently_out.php?filter=arrivals'],
        ], $filter) !!}

        <p><a href="gate.php">Go to gate</a></p>

        @if ($arrivals)
            <h2>Expected arrivals ({!! count($arrivals) !!})</h2>
            <p class="report-note">Approved to arrive during curfew &mdash; not counted as "out", just not through the gate yet.</p>
            <table border="1" cellpadding="6" cellspacing="0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Coming from</th>
                        <th>Reason</th>
                        <th>Expected</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($arrivals as $a)
                        <tr{!! $a['status'] === 'overdue' ? ' class="row-flag"' : '' !!}>
                            <td>{!! h($a['std_name']) !!} ({!! h($a['std_no']) !!})</td>
                            <td>{!! h($a['coming_from'] ?? '') !!}</td>
                            <td>{!! h($a['reason']) !!}</td>
                            <td>{!! h($a['expected_return_at']) !!}</td>
                            <td>{!! h(status_label($a['status'])) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($filter !== 'arrivals')
            <h2>Currently out ({!! count($rows) !!})</h2>
            @if (!$rows)
                <p>No one matches this view.</p>
            @else

                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Track</th>
                            <th>Destination</th>
                            <th>Reason</th>
                            <th>Out since</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr{!! $r['status'] === 'Overdue' ? ' class="row-flag"' : '' !!}>
                                <td>{!! h($r['std_name']) !!} ({!! h($r['std_no']) !!})</td>
                                <td>{!! $r['track'] === 'special' ? 'Special' : 'Standard' !!}</td>
                                <td>{!! h($r['destination']) !!}</td>
                                <td>{!! h($r['reason']) !!}</td>
                                <td>{!! h($r['out_since']) !!}</td>
                                <td>{!! h($r['status']) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @elseif (!$arrivals)
            <p>No one matches this view.</p>
        @endif
    </main>

    

@endsection
