@extends('layouts.app')
@section('title', 'New outing request - Outing System')
@section('content')

    

    <main class="form-page">
        <h1>New special outing request</h1>
        <p>Use this if you need staff approval for something during curfew hours.</p>
        <p>Current time: @include('partials.clock_widget', ['clock_inline'=>true])</p>

        @if ($error !== '')
            <p class="form-error">{!! h($error) !!}</p>
        @endif

        <fieldset class="form-card">
            <form method="post" action="new_request.php" id="request-form" class="request-form">
                @csrf

                <div class="segmented" role="radiogroup" aria-label="Request type">
                    <label class="seg">
                        <input type="radio" name="type" value="checkout" {!! $values['type'] === 'checkout' ? 'checked' : '' !!}>
                        <span>Check out</span>
                    </label>
                    <label class="seg">
                        <input type="radio" name="type" value="checkin" {!! $values['type'] === 'checkin' ? 'checked' : '' !!}>
                        <span>Check in</span>
                    </label>
                </div>
                <div id="type-notice" role="alert" style="display:none;margin:8px 0;padding:10px 12px;border-radius:8px;background:#fef3c7;border:1px solid #f59e0b;color:#92400e;font-size:14px;">
                    You can only request arrival permission while you are checked out. You are currently on campus.
                </div>

                <label class="form-field">
                    <span id="destination-label">{!! h(destination_field_label($values['type'])) !!}</span>
                    <input type="text" name="destination" maxlength="255" value="{!! h($values['destination']) !!}" required>
                </label>
                <label class="form-field">
                    Reason
                    <input type="text" name="reason" maxlength="255" value="{!! h($values['reason']) !!}" required>
                </label>

                <div id="field-checkout" style="display:none;">
                    <label class="form-field">
                        What time do you need to leave?
                        <input type="datetime-local" name="requested_out_at" value="{!! h($values['requested_out_at']) !!}">
                    </label>
                </div>
                <div id="field-checkin" style="display:none;">
                    <label class="form-field">
                        What time do you expect to arrive back?
                        <input type="datetime-local" name="expected_return_at" value="{!! h($values['expected_return_at']) !!}">
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit">Submit request</button>
                    <a href="my_requests.php" class="btn-secondary">Cancel</a>
                </div>
            </form>
        </fieldset>
    </main>

    <script>
        // Only the field that matches the chosen type is shown -- and
        // only that field is required, so the other one never blocks
        // submission even though both live in the same form.
        (function () {
            var radios = document.querySelectorAll('input[name="type"]');
            var checkoutField = document.getElementById('field-checkout');
            var checkinField = document.getElementById('field-checkin');
            var checkoutInput = checkoutField.querySelector('input');
            var checkinInput = checkinField.querySelector('input');
            var destinationLabel = document.getElementById('destination-label');

            function sync() {
                var isCheckin = document.querySelector('input[name="type"]:checked').value === 'checkin';
                checkoutField.style.display = isCheckin ? 'none' : 'block';
                checkinField.style.display = isCheckin ? 'block' : 'none';
                checkoutInput.required = !isCheckin;
                checkinInput.required = isCheckin;
                destinationLabel.textContent = isCheckin ? 'Coming from' : 'Destination';
            }

            var isOut = {!! $isOut ? 'true' : 'false' !!};
            var notice = document.getElementById('type-notice');
            var noticeTimer = null;

            function showNotice() {
                notice.style.display = 'block';
                clearTimeout(noticeTimer);
                noticeTimer = setTimeout(function () { notice.style.display = 'none'; }, 6000);
            }

            radios.forEach(function (r) {
                r.addEventListener('change', function () {
                    if (r.value === 'checkin' && !isOut) {
                        document.querySelector('input[name="type"][value="checkout"]').checked = true;
                        showNotice();
                    } else {
                        notice.style.display = 'none';
                    }
                    sync();
                });
            });
            sync();
        })();
    </script>

@endsection
