@extends('layouts.app')
@section('title', 'Special requests - Outing System')
@section('content')

    

    <main>
        <h1>My special outing requests</h1>
        <p>
            This is only for leaving <strong>during curfew hours</strong>, or for any outing that needs
            staff approval for another reason. For a normal outing outside curfew hours, you don't need
            to request anything &mdash; just go to the gate and the guard will check you out directly.
        </p>

        {!! flash_render() !!}

        <p><a href="new_request.php" class="btn-primary">+ New special request</a></p>

        @if (!$requests)
            <p>You haven't submitted any outing requests yet.</p>
        @else

            <table border="1" cellpadding="6" cellspacing="0">
                <thead>
                    <tr>
                        <th>Destination</th>
                        <th>Coming from</th>
                        <th>Reason</th>
                        <th>Out at</th>
                        <th>Expected return</th>
                        <th>Status</th>
                        <th>Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $r)
                        <tr>
                            <td>{!! h($r['destination'] ?? '') !!}</td>
                            <td>{!! h($r['coming_from'] ?? '') !!}</td>
                            <td>{!! h($r['reason']) !!}</td>
                            <td>{!! h($r['requested_out_at']) !!}</td>
                            <td>{!! h($r['expected_return_at']) !!}</td>
                            <td>{!! h(status_label($r['status'])) !!}</td>
                            <td>
                                @if ($r['status'] === 'rejected' && $r['rejection_reason'])
                                    Rejected: {!! h($r['rejection_reason']) !!}
                                @endif
                                @if ($r['status'] === 'expired')
                                    Not reviewed by staff in time. You will not be marked late for this arrival.
                                @endif
                            </td>
                            <td>
                                @if ($r['status'] === 'pending')
                                  <div class="row-actions">
                                    <a href="edit_request.php?id={!! (int) $r['id'] !!}" class="btn-secondary btn-sm">Edit</a>
                                    <form method="post" action="cancel_request.php" style="display:inline"
                                          data-confirm="Cancel this outing request?" onsubmit="return confirm(this.dataset.confirm);">
                                        @csrf
                                        <input type="hidden" name="id" value="{!! (int) $r['id'] !!}">
                                        <button type="submit" class="btn-danger btn-sm">Cancel</button>
                                    </form>
                                  </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>

@endsection
