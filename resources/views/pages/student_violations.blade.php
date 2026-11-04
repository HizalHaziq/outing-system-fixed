@extends('layouts.app')
@section('title', 'Student violations - Outing System')
@section('content')

    
    

    <main>
        <p class="no-print"><a href="violation_report.php{!! $backQuery !== '' ? '?' . h($backQuery) : '' !!}">&larr; Back to violation report</a></p>
        <h1>{!! h($student['std_name']) !!} ({!! h($student['std_no']) !!})</h1>
        {!! export_pdf_button() !!}

        <div class="stat-grid">
            <div class="stat-card{!! $violations ? ' stat-danger' : '' !!}">
                <p class="stat-label">Violations{!! ($dateRange['from'] || $dateRange['to']) ? ' (in range)' : '' !!}</p>
                <p class="stat-value">{!! count($violations) !!}</p>
            </div>
        </div>

        @if (!$violations)
            <p class="dash-empty">No violations recorded.</p>
        @else

            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Left</th>
                        <th>Should be back</th>
                        <th>Returned</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($violations as $v)
                        <tr>
                            <td>{!! h(track_label($v['track'])) !!}</td>
                            <td>{!! $v['out_at'] !== null ? h(fmt_dt($v['out_at'])) : '<em>&mdash; (arrival permission)</em>' !!}</td>
                            <td>
                                @if (str_starts_with($v['track'], 'special'))
                                    {!! h(fmt_dt($v['due_at'])) !!}
                                @else

                                    Before curfew ({!! h(date('g:i A', strtotime(app(\App\Services\Settings::class)->get('curfew_start')))) !!})
                                @endif
                            </td>
                            <td>{!! $v['back_at'] !== null ? h(fmt_dt($v['back_at'])) : '<em>Not back yet</em>' !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>

    

@endsection
