@extends('layouts.app')
@section('title', 'My group - Outing System')
@section('content')

    

    <main>
        <p><a href="student_dashboard.php">&larr; Back</a></p>
        <h1>Group outing</h1>
        <p class="report-note">
            One group lead can bring up to {!! GROUP_MAX_MEMBERS !!} other students. Each student must accept the invitation,
            then any member shows the group QR and the guard processes everyone together. Curfew rules still apply to each
            person individually. A group stays open for {!! GROUP_LIFETIME_HOURS !!} hours.
        </p>
        {!! flash_render() !!}

        @if ($invites)
            <h2>Invitations</h2>
            @foreach ($invites as $inv)
                <form method="post" action="group_action.php" style="margin-bottom:8px;">
                    @csrf
                    <input type="hidden" name="member_row_id" value="{!! (int) $inv['member_row_id'] !!}">
                    {!! h($inv['lead_name']) !!} ({!! h($inv['lead_std_no']) !!}) invited you.
                    <button type="submit" name="action" value="accept">Accept</button>
                    <button type="submit" name="action" value="decline" class="btn-danger">Decline</button>
                </form>
            @endforeach
        @endif

        @if ($leadGroup)
            <h2>Your group</h2>
            <table border="1" cellpadding="6" cellspacing="0" class="group-table">
                <thead><tr><th>Student</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr><td>{!! h($leadGroup['lead_name']) !!} ({!! h($stdNo) !!})</td><td>Lead</td><td></td></tr>
                    @foreach ($members as $m)
                        <tr>
                            <td>{!! h($m['std_name']) !!} ({!! h($m['std_no']) !!})</td>
                            <td>{!! $m['status'] === 'accepted' ? 'Accepted' : 'Invited (waiting)' !!}</td>
                            <td>
                                <form method="post" action="group_action.php" style="display:inline">
                                    @csrf
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="group_id" value="{!! (int) $leadGroup['id'] !!}">
                                    <input type="hidden" name="member_std_no" value="{!! h($m['std_no']) !!}">
                                    <button type="submit" class="btn-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if (count($members) < GROUP_MAX_MEMBERS)
                <form method="post" action="group_action.php" style="margin-top:12px;">
                    @csrf
                    <input type="hidden" name="action" value="invite">
                    <input type="hidden" name="group_id" value="{!! (int) $leadGroup['id'] !!}">
                    <label>Add student number <input type="text" name="member_std_no" required></label>
                    <button type="submit">Invite</button>
                </form>
            @endif

            @if (!$showQr)
                <h2>Group QR</h2>
                <p class="report-note">The group QR appears once at least one student has accepted.</p>
            @endif

            @if ($showQr)
                <h2>Group QR</h2>
                <p class="report-note">Any member of the group can show this at the gate &mdash; the guard only needs to scan it once.
                   It refreshes automatically; screenshots will not work.</p>
                <div class="qr-card">
                    <div id="qrcode" style="user-select:none;-webkit-user-select:none;"></div>
                    <p class="qr-card__name">Group of {!! (int) $qrPeople !!}</p>
                    <div class="qr-timer"><div id="qr-bar" class="qr-timer__bar"></div></div>
                    <p id="qr-countdown" class="report-note" style="text-align:center;margin:6px 0 0;">Loading&hellip;</p>
                </div>
                <p id="qr-error" class="form-error" style="display:none;"></p>
            @endif

            <form method="post" action="group_action.php" style="margin-top:16px;"
                  data-confirm="Close this group?" onsubmit="return confirm(this.dataset.confirm);">
                @csrf
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="group_id" value="{!! (int) $leadGroup['id'] !!}">
                <button type="submit" class="btn-danger">Close group</button>
            </form>

        @elseif ($memberGroup)
            <h2>Your group</h2>
            <p>You are in {!! h($memberGroup['lead_name']) !!}'s group ({!! h($memberGroup['lead_std_no']) !!}).
               Any member can show the group QR below at the gate &mdash; you do not need your own QR when going out together.</p>
            <table border="1" cellpadding="6" cellspacing="0" class="group-table">
                <thead><tr><th>Student</th><th>Role</th></tr></thead>
                <tbody>
                    @foreach ($roster as $r)
                        <tr>
                            <td>{!! h($r['std_name']) !!} ({!! h($r['std_no']) !!}){!! $r['std_no'] === $stdNo ? ' &mdash; you' : '' !!}</td>
                            <td>{!! h($r['role']) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="report-note">{!! count($roster) !!} in this group.</p>

            @if ($showQr)
                <h2>Group QR</h2>
                <p class="report-note">Any member of the group can show this at the gate &mdash; the guard only needs to scan it once.
                   It refreshes automatically; screenshots will not work.</p>
                <div class="qr-card">
                    <div id="qrcode" style="user-select:none;-webkit-user-select:none;"></div>
                    <p class="qr-card__name">Group of {!! (int) $qrPeople !!}</p>
                    <div class="qr-timer"><div id="qr-bar" class="qr-timer__bar"></div></div>
                    <p id="qr-countdown" class="report-note" style="text-align:center;margin:6px 0 0;">Loading&hellip;</p>
                </div>
                <p id="qr-error" class="form-error" style="display:none;"></p>
            @endif
            <form method="post" action="group_action.php" data-confirm="Leave this group?" onsubmit="return confirm(this.dataset.confirm);">
                @csrf
                <input type="hidden" name="action" value="leave">
                <input type="hidden" name="group_id" value="{!! (int) $memberGroup['id'] !!}">
                <button type="submit" class="btn-danger">Leave group</button>
            </form>

        @else

            <form method="post" action="group_action.php">
                @csrf
                <input type="hidden" name="action" value="create">
                <button type="submit">Start a group (I am the lead)</button>
            </form>
        @endif
    </main>

    @if ($showQr)
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        (function () {
            var errorEl = document.getElementById('qr-error');
            var bar = document.getElementById('qr-bar');
            var countdown = document.getElementById('qr-countdown');
            var qr = null, timer = null, tick = null;
            function showError(m) { errorEl.textContent = m; errorEl.style.display = 'block'; }
            if (typeof QRCode === 'undefined') { showError('QR library failed to load.'); return; }

            function load() {
                clearTimeout(timer); clearInterval(tick);
                fetch('qr_token.php?kind=G', { cache: 'no-store', credentials: 'same-origin' })
                    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then(function (d) {
                        errorEl.style.display = 'none';
                        if (!qr) {
                            qr = new QRCode(document.getElementById('qrcode'), { text: d.token, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
                        } else { qr.makeCode(d.token); }
                        var end = Date.now() + d.expires_in * 1000;
                        tick = setInterval(function () {
                            var left = Math.max(0, (end - Date.now()) / 1000);
                            bar.style.width = (left / d.period * 100) + '%';
                            countdown.textContent = 'New code in ' + Math.ceil(left) + 's';
                        }, 250);
                        timer = setTimeout(load, Math.max(1000, d.expires_in * 1000 - 800));
                    })
                    .catch(function () { showError('Could not refresh the group QR.'); timer = setTimeout(load, 5000); });
            }
            load();
            document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
        })();
    </script>
    @endif

@endsection
