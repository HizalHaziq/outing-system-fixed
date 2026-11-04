@extends('layouts.app')
@section('title', 'Gate - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Gate check-out / check-in</h1>
        <p>Curfew: {!! h(app(\App\Services\Curfew::class)->label()) !!} &mdash;
           <strong>{!! $curfewNow ? 'Active now' : 'Not active now' !!}</strong></p>
        {!! flash_render() !!}

        @if ($manualBlocked)
            <p style="color:red;">Manual entry is turned off. Scan the student's live QR code.</p>
        @endif

        <form method="get" action="gate.php">
            @if ($manualOk)
            <label>
                Student number
                <input type="text" id="std_no" name="std_no" value="{!! h($stdNo) !!}" autofocus required>
            </label>
            <button type="submit">Look up</button>
            @endif
            <button type="button" id="scan-toggle">Scan QR</button>
        </form>

        @if(!$manualOk)
        <details style="margin:12px 0;"><summary>Record an inactive student's return</summary>
            <p>Only an inactive student with an open outing can use this return recovery. Check physical student ID.</p>
            <form method="get" action="gate.php"><label>Student number <input type="text" name="std_no" required maxlength="20"></label><button type="submit">Look up return</button></form>
        </details>
        @endif
        @if($inactiveReturnRecovery)
        <p class="report-note"><strong>Inactive account return:</strong> verify physical student ID. This lookup permits only closing the displayed open outing.</p>
        @endif

        <form method="post" action="gate.php" id="scan-form" style="display:none;">
            @csrf
            <input type="hidden" name="qr_token" id="qr_token" value="">
        </form>

        <div id="qr-reader" style="width: 320px; display: none; margin-top: 8px;"></div>
        <p id="qr-error" style="color: red; display: none;"></p>

        @if ($notFound)
            <p style="color:red;">No student found with number "{!! h($stdNo) !!}".</p>

        @elseif ($student)
            <div style="display:flex;gap:16px;align-items:flex-start;margin:12px 0;">
                {!! student_photo_html($student['std_no'], $student['std_name'], 140) !!}
                <div>
                    <h2 style="margin:0 0 6px;">{!! h($student['std_name']) !!}</h2>
                    <table cellpadding="3" cellspacing="0">
                        <tr><td>Student no.</td><td><strong>{!! h($student['std_no']) !!}</strong></td></tr>
                        <tr><td>Program</td><td>{!! h($student['program'] ?? '') !== '' ? h($student['program']) : '&mdash;' !!}</td></tr>
                        <tr><td>Phone</td><td>{!! h($student['phone'] ?? '') !== '' ? h($student['phone']) : '&mdash;' !!}</td></tr>
                        <tr><td>Status now</td><td><strong>{!! h(\App\Services\GateService::label($route)) !!}</strong></td></tr>
                    </table>
                </div>
            </div>
            @if ($qrVerified)
                <p style="color:green;"><strong>&#10003; Live QR verified.</strong></p>
            @else

                <p style="color:#b26a00;"><strong>Manual entry &mdash; not QR-verified.</strong> Check the student's ID.</p>
            @endif
            @if (!$student['is_active'])
                <p style="color:red;"><strong>This student account is inactive.</strong></p>
            @endif

            @if ($arrivalApproval && $standardOpen && is_unreviewed_arrival($arrivalApproval))
                <p style="color:#b45309;">
                    <strong>Arrival request not reviewed by staff</strong>
                    (coming from {!! h($arrivalApproval['coming_from'] ?? '') !!}, expected {!! h($arrivalApproval['expected_return_at']) !!}, reason: {!! h($arrivalApproval['reason']) !!}).
                    The student asked in time, so this check-in will NOT be flagged as late.
                </p>
            @elseif ($arrivalApproval && $standardOpen)
                <p style="color:green;">
                    <strong>Has an approved arrival permission</strong>
                    (coming from {!! h($arrivalApproval['coming_from'] ?? '') !!}, expected {!! h($arrivalApproval['expected_return_at']) !!}, reason: {!! h($arrivalApproval['reason']) !!}).
                    This check-in will not be flagged as late.
                </p>
            @elseif ($arrivalApproval && !$specialActive)
                <p style="color:#6b7280;">
                    Has an arrival request on file, but is <strong>not checked out</strong>, so it does not apply.
                    It is cancelled automatically once they check out and back in.
                </p>
            @endif

            @if (!$student['is_active'] && !$standardOpen && !$specialActive)
<p>Departures are blocked for this inactive account.</p>
@elseif ($standardOpen)
                <p>Currently <strong>out (standard)</strong> since {!! h($standardOpen['checked_out_at']) !!}.</p>
                <form method="post" action="gate_action.php"
                      data-confirm="Check in {!! h($student['std_name']) !!}?" onsubmit="return confirm(this.dataset.confirm);">
<input type="hidden" name="verification_id" value="{{ $gateGrant }}">
<label class="identity-check"><input type="checkbox" name="identity_confirmed" value="1" required> I checked the student identity against the photo or student ID.</label>
                    @csrf
                    <input type="hidden" name="action" value="standard_check_in">
                    <input type="hidden" name="record_id" value="{!! (int) $standardOpen['id'] !!}">
                    <input type="hidden" name="std_no" value="{!! h($student['std_no']) !!}">
                    <button type="submit">Check in</button>
                </form>
                @if ($curfewNow && !$arrivalApproval)
                    <p><em>Checking in now will be flagged as a late return (curfew is active, no arrival permission on file).</em></p>
                @endif

            @elseif ($specialActive)
                <p>Currently <strong>out (special &mdash; leave permission)</strong> since {!! h($specialActive['actual_out_at']) !!},
                   destination: {!! h($specialActive['destination']) !!}
                   @if ($specialActive['expected_return_at'])
                       , agreed back by: {!! h($specialActive['expected_return_at']) !!}
                   @endif
                   @if ($specialActive['status'] === 'overdue')
                       <strong style="color:red;">(OVERDUE)</strong>
                   @endif
                </p>
                <form method="post" action="gate_action.php"
                      data-confirm="Check in {!! h($student['std_name']) !!}?" onsubmit="return confirm(this.dataset.confirm);">
<input type="hidden" name="verification_id" value="{{ $gateGrant }}">
<label class="identity-check"><input type="checkbox" name="identity_confirmed" value="1" required> I checked the student identity against the photo or student ID.</label>
                    @csrf
                    <input type="hidden" name="action" value="special_check_in">
                    <input type="hidden" name="request_id" value="{!! (int) $specialActive['id'] !!}">
                    <input type="hidden" name="std_no" value="{!! h($student['std_no']) !!}">
                    <button type="submit">Check in</button>
                </form>

            @elseif ($specialReady)
                <p>Has an <strong>approved leave permission</strong>:
                   destination: {!! h($specialReady['destination']) !!},
                   reason: {!! h($specialReady['reason']) !!}
                   @if ($specialReady['expected_return_at'])
                       , agreed back by: {!! h($specialReady['expected_return_at']) !!}
                   @endif
                </p>
                <form method="post" action="gate_action.php"
                      data-confirm="Check out {!! h($student['std_name']) !!}?" onsubmit="return confirm(this.dataset.confirm);">
<input type="hidden" name="verification_id" value="{{ $gateGrant }}">
<label class="identity-check"><input type="checkbox" name="identity_confirmed" value="1" required> I checked the student identity against the photo or student ID.</label>
                    @csrf
                    <input type="hidden" name="action" value="special_check_out">
                    <input type="hidden" name="request_id" value="{!! (int) $specialReady['id'] !!}">
                    <input type="hidden" name="std_no" value="{!! h($student['std_no']) !!}">
                    <button type="submit">Check out</button>
                </form>

            @elseif ($curfewNow)
                <p style="color:red;">
                    <strong>Curfew is active ({!! h(app(\App\Services\Curfew::class)->label()) !!}).</strong>
                    This student has no approved leave permission, so they cannot be checked out right now.
                    They need to submit a request and have it approved first.
                </p>

            @else

                <p>No approved request, not currently out. Curfew is not active, so this is a standard check-out.</p>
                <form method="post" action="gate_action.php"
                      data-confirm="Check out {!! h($student['std_name']) !!}?" onsubmit="return confirm(this.dataset.confirm);">
<input type="hidden" name="verification_id" value="{{ $gateGrant }}">
<label class="identity-check"><input type="checkbox" name="identity_confirmed" value="1" required> I checked the student identity against the photo or student ID.</label>
                    @csrf
                    <input type="hidden" name="action" value="standard_check_out">
                    <input type="hidden" name="std_no" value="{!! h($student['std_no']) !!}">
                    <button type="submit">Check out</button>
                </form>
            @endif
        @endif

        <p><a href="currently_out.php">View everyone currently out</a></p>
    </main>

    

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <script>
        (function () {
            var toggleBtn = document.getElementById('scan-toggle');
            var readerDiv = document.getElementById('qr-reader');
            var errorEl = document.getElementById('qr-error');
            var scanner = null;
            var scanning = false;

            function showError(msg) {
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
            }

            function stopScanner() {
                if (scanner && scanning) {
                    scanner.stop().catch(function () {});
                }
                scanning = false;
                readerDiv.style.display = 'none';
            }

            function onScanSuccess(decodedText) {
                stopScanner();
                document.getElementById('qr_token').value = decodedText.trim();
                document.getElementById('scan-form').submit();
            }

            toggleBtn.addEventListener('click', function () {
                if (scanning) {
                    stopScanner();
                    return;
                }

                if (typeof Html5Qrcode === 'undefined') {
                    showError('QR scanner library failed to load (check your internet connection).');
                    return;
                }
                if (!window.isSecureContext) {
                    showError('Camera access needs HTTPS (or localhost). This page is loaded over plain HTTP, so the browser is blocking the camera before we even get to pick one.');
                    return;
                }

                errorEl.style.display = 'none';
                readerDiv.style.display = 'block';
                scanner = new Html5Qrcode('qr-reader');

                Html5Qrcode.getCameras().then(function (cameras) {
                    if (!cameras || !cameras.length) {
                        readerDiv.style.display = 'none';
                        showError('No camera found on this device.');
                        return;
                    }

                    var chosen = cameras.find(function (c) {
                        return /back|rear|environment/i.test(c.label);
                    }) || cameras[0];

                    scanning = true;
                    scanner.start(
                        chosen.id,
                        { fps: 10, qrbox: 250 },
                        onScanSuccess,
                        function () { /* per-frame decode failures -- ignore, this fires constantly while scanning */ }
                    ).catch(function (err) {
                        scanning = false;
                        readerDiv.style.display = 'none';
                        showError('Could not start the camera: ' + err);
                    });
                }).catch(function (err) {
                    readerDiv.style.display = 'none';
                    showError('Could not access the camera list: ' + err + '. This usually means camera permission was denied -- check your browser\'s site settings.');
                });
            });
        })();
    </script>

@endsection
