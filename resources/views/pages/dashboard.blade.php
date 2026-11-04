@extends('layouts.app')
@section('title', 'Dashboard - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Dashboard</h1>
        <p style="padding: 8px 12px; background: {!! $curfewNow ? '#fdecea' : '#eef6ec' !!}; border-radius: 6px; display: inline-block;">
           Curfew: {!! h(app(\App\Services\Curfew::class)->label()) !!} &mdash;
           <strong>{!! $curfewNow ? 'Active now' : 'Not active now' !!}</strong></p>
        {!! flash_render() !!}

        @if ($isAdmin)
        <div class="dash-section">
            <h2>System</h2>
            <div class="stat-grid">
                <div class="stat-card">
                    <p class="stat-label">Staff accounts</p>
                    <p class="stat-value">{!! $staffSummary['total'] !!}</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Active</p>
                    <p class="stat-value">{!! $staffSummary['active'] !!}</p>
                </div>
                @if ($staffSummary['inactive'] > 0)
                <div class="stat-card stat-danger">
                    <p class="stat-label">Inactive</p>
                    <p class="stat-value">{!! $staffSummary['inactive'] !!}</p>
                </div>
                @endif
            </div>
            <p>
                @foreach ($staffSummary['by_role'] as $r => $c)
                    {!! h(ucfirst($r)) !!}: <strong>{!! $c !!}</strong>&nbsp;&nbsp;
                @endforeach
            </p>
            <p><a href="manage_staff.php">Manage staff accounts</a></p>
        </div>
        @endif

        <div class="stat-grid">
            @if ($canApprove)
                <div class="stat-card{!! $pendingCount > 0 ? ' stat-danger' : '' !!}">
                    <p class="stat-label">Pending approval</p>
                    <p class="stat-value">{!! $pendingCount !!}</p>
                </div>
            @endif
            <div class="stat-card">
                <p class="stat-label">Out (special)</p>
                <p class="stat-value">{!! $counts['special_out'] !!}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Out (standard)</p>
                <p class="stat-value">{!! $counts['standard_out'] !!}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Due back today</p>
                <p class="stat-value">{!! $counts['due_today'] !!}</p>
            </div>
        </div>

        @if ($canApprove)
        <div class="dash-section">
            <h2>Pending approvals</h2>
            @if (!$pendingPreview)
                <p class="dash-empty">No pending requests right now.</p>
            @else

                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Destination</th>
                            <th>Coming from</th>
                            <th>Expected return</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingPreview as $r)
                            <tr>
                                <td>{!! h($r['std_name']) !!} ({!! h($r['std_no']) !!})</td>
                                <td>{!! h($r['destination'] ?? '') !!}</td>
                                <td>{!! h($r['coming_from'] ?? '') !!}</td>
                                <td>{!! h($r['expected_return_at']) !!}</td>
                                <td>{!! h($r['created_at']) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p><a href="pending_requests.php">Review all{!! $pendingCount > 5 ? " ({$pendingCount})" : '' !!}</a></p>
            @endif
        </div>
        @endif

        <div class="dash-section">
            <h2>Due back today (special track)</h2>
            @if (!$dueBackToday)
                <p class="dash-empty">No one expected back today.</p>
            @else

                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Destination</th>
                            <th>Coming from</th>
                            <th>Expected return</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dueBackToday as $r)
                            <tr{!! $r['status'] === 'overdue' ? ' class="row-flag"' : '' !!}>
                                <td>{!! h($r['std_name']) !!} ({!! h($r['std_no']) !!})</td>
                                <td>{!! h(request_type_label($r['type'])) !!}</td>
                                <td>{!! h($r['destination'] ?? '') !!}</td>
                                <td>{!! h($r['coming_from'] ?? '') !!}</td>
                                <td>{!! h($r['expected_return_at']) !!}</td>
                                <td>{!! h(status_label($r['status'])) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p><a href="currently_out.php">View everyone currently out</a></p>
            @endif
        </div>

        @if ($canGate)
        <div class="dash-section">
            <h2>Recent gate activity</h2>
            @if (!$recentActivity)
                <p class="dash-empty">No check-ins or check-outs yet.</p>
            @else

                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Action</th>
                            <th>Track</th>
                            <th>By</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentActivity as $a)
                            <tr>
                                <td>{!! h($a['std_name']) !!} ({!! h($a['std_no']) !!})</td>
                                <td>{!! $a['action'] === 'checked_out' ? 'Checked out' : 'Checked in' !!}</td>
                                <td>{!! match ($a['track']) { 'special' => 'Special', 'standard_arrival' => 'Standard (arrival permission used)', default => 'Standard' } !!}</td>
                                <td>{!! h($a['staff_id'] ?? '') !!}</td>
                                <td>{!! h($a['activity_at']) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @endif

        @if ($canApprove)
        <div class="dash-section">
            <h2>Most violations</h2>
            @if (!$violations)
                <p class="dash-empty">No violations recorded yet.</p>
            @else

                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Violations</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($violations as $r)
                            <tr>
                                <td><a href="student_violations.php?std_no={!! urlencode($r['std_no']) !!}">{!! h($r['std_name']) !!}</a> ({!! h($r['std_no']) !!})</td>
                                <td><strong>{!! (int) $r['violations'] !!}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p><a href="violation_report.php">View full violation report</a></p>
            @endif
        </div>
        @endif
    </main>

    

@endsection
