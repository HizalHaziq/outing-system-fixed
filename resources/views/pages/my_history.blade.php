@extends('layouts.app')
@section('title', 'My outing history - Outing System')
@section('content')

    

    <main>
        <h1>My outing history</h1>
        <p class="report-note">
            A violation is a late return: coming back after your approved return time (special outing),
            or checking in during curfew hours, {!! h(app(\App\Services\Curfew::class)->label()) !!} (standard outing).
        </p>

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

        @if (!$history)
            <p class="dash-empty">You haven't been out yet.</p>
        @else

            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Group</th>
                        <th>Left</th>
                        <th>Returned</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history as $r)
                        @php
[$badgeClass, $badgeText] = $badges[$r['result']];
@endphp
                        <tr{!! $r['result'] === 'late' ? ' class="row-flag"' : '' !!}>
                            <td>{!! h(history_type_label($r)) !!}</td>
                            <td>{!! $r['group_id'] ? 'Group (lead: ' . h($r['group_lead_name']) . ')' : '&mdash;' !!}</td>
                            <td>{!! $r['out_at'] !== null ? h(fmt_dt($r['out_at'])) : '&mdash;' !!}</td>
                            <td>{!! $r['back_at'] !== null ? h(fmt_dt($r['back_at'])) : '&mdash;' !!}</td>
                            <td><span class="badge {!! $badgeClass !!}">{!! h($badgeText) !!}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>

@endsection
