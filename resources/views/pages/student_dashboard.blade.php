@extends('layouts.app')
@section('title', 'Dashboard - Outing System')
@section('content')

    

    <main>
        <h1>Welcome, {!! h($name) !!}</h1>

        {!! flash_render() !!}

        @if ($inCurfew)
            <div class="status-banner status-banner--warn">
                <strong>Curfew is active ({!! h($curfewLabel) !!}).</strong>
                You can only leave with an approved special request.
                @if (!$standardOpen && !$specialOut && !$specialReady)
                    <a href="new_request.php">Request a special outing</a>
                @endif
            </div>
        @else

            <div class="status-banner">
                <strong>No curfew right now.</strong>
                For a normal outing just go to the gate and show your QR code &mdash; no request needed.
                Curfew starts at {!! h($curfewStart) !!}.
            </div>
        @endif

        <div class="dash-section">
            <h2>Your status</h2>
            <div class="status-card">
                @if ($standardOpen)
                    <p class="status-card__title"><span class="badge badge-out">Out</span> Standard outing</p>
                    <p>Left at {!! h(fmt_dt($standardOpen['checked_out_at'])) !!}.
                       Check back in at the gate before curfew starts at {!! h($curfewStart) !!}.</p>
                @elseif ($specialOut)
                    <p class="status-card__title"><span class="badge badge-out">Out</span> Special outing</p>
                    <p>Left at {!! h(fmt_dt($specialOut['actual_out_at'])) !!}{!! !empty($specialOut['destination']) ? ' &middot; ' . h($specialOut['destination']) : '' !!}.
                       @if (!empty($specialOut['expected_return_at']))
                           Expected back by {!! h(fmt_dt($specialOut['expected_return_at'])) !!}.
                       @endif</p>
                @elseif ($specialReady)
                    <p class="status-card__title"><span class="badge badge-ok">Approved</span> Ready to leave</p>
                    <p>Your special request{!! !empty($specialReady['destination']) ? ' to ' . h($specialReady['destination']) : '' !!}
                       is approved. Show your QR code at the gate{!! !empty($specialReady['requested_out_at']) ? ' (requested for ' . h(fmt_dt($specialReady['requested_out_at'])) . ')' : '' !!}.</p>
                @elseif ($arrival && is_unreviewed_arrival($arrival))
                    <p class="status-card__title"><span class="badge badge-ok">Protected</span> Arrival request not reviewed</p>
                    <p>Staff did not review your arrival request{!! !empty($arrival['expected_return_at']) ? ' (expected ' . h(fmt_dt($arrival['expected_return_at'])) . ')' : '' !!}.
                       You will not be marked late if you arrive during this curfew. Show your QR code at the gate.</p>
                @elseif ($arrival)
                    <p class="status-card__title"><span class="badge badge-ok">Approved</span> Arrival approved</p>
                    <p>You're approved to arrive back after curfew{!! !empty($arrival['expected_return_at']) ? ' (expected ' . h(fmt_dt($arrival['expected_return_at'])) . ')' : '' !!}.
                       Show your QR code at the gate.</p>
                @else

                    <p class="status-card__title"><span class="badge badge-ok">In</span> No active outing</p>
                    <p>You have no open outing right now.</p>
                @endif
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <p class="stat-label">Total outings</p>
                <p class="stat-value">{!! $total !!}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Returned on time</p>
                <p class="stat-value">{!! $onTime !!}</p>
            </div>
            <div class="stat-card{!! $violations > 0 ? ' stat-danger' : '' !!}">
                <p class="stat-label">Violations</p>
                <p class="stat-value">{!! $violations !!}</p>
            </div>
        </div>

        <div class="quick-actions">
            <a class="quick-action" href="my_qr.php"><strong>My QR code</strong><span>Show this at the gate</span></a>
            <a class="quick-action" href="my_group.php"><strong>Group outing</strong>
                <span>
@if ($invitations){!! count($invitations) !!} invitation{!! count($invitations) > 1 ? 's' : '' !!} waiting
                      @elseif ($group)You're in an active group
                      @else
Go out with friends
@endif</span></a>
            <a class="quick-action" href="my_requests.php"><strong>Special requests</strong>
                <span>
@if ($pendingReqs || $approvedReqs){!! $pendingReqs !!} pending, {!! $approvedReqs !!} approved
                      @else
For leaving during curfew
@endif</span></a>
        </div>

        <div class="dash-section">
            <h2>Recent outings</h2>
            @if (!$recent)
                <p class="dash-empty">You haven't been out yet.</p>
            @else

                <table>
                    <thead>
                        <tr><th>Type</th><th>Left</th><th>Returned</th><th>Result</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $r)
                            @php
[$badgeClass, $badgeText] = $badges[$r['result']];
@endphp
                            <tr{!! $r['result'] === 'late' ? ' class="row-flag"' : '' !!}>
                                <td>{!! h(history_type_label($r)) !!}</td>
                                <td>{!! $r['out_at'] !== null ? h(fmt_dt($r['out_at'])) : '&mdash;' !!}</td>
                                <td>{!! $r['back_at'] !== null ? h(fmt_dt($r['back_at'])) : '&mdash;' !!}</td>
                                <td><span class="badge {!! $badgeClass !!}">{!! h($badgeText) !!}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p><a href="my_history.php">View full history</a></p>
            @endif
        </div>
    </main>

@endsection
