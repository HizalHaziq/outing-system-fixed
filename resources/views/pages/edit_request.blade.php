@extends('layouts.app')
@section('title', 'Edit outing request - Outing System')
@section('content')

    

    <main class="form-page">
        <h1>Edit outing request</h1>
        <p>{!! h(request_type_label($type)) !!}</p>
        <p>Current time: @include('partials.clock_widget', ['clock_inline'=>true])</p>

        @if ($error !== '')
            <p class="form-error">{!! h($error) !!}</p>
        @endif

        <fieldset class="form-card">
            <form method="post" action="edit_request.php?id={!! $id !!}" class="request-form">
                @csrf
<input type="hidden" name="type" value="{{ $type }}">
                <input type="hidden" name="id" value="{!! $id !!}">
                <label class="form-field">
                    {!! h(destination_field_label($type)) !!}
                    <input type="text" name="destination" maxlength="255" value="{!! h($values['destination']) !!}" required>
                </label>
                <label class="form-field">
                    Reason
                    <input type="text" name="reason" maxlength="255" value="{!! h($values['reason']) !!}" required>
                </label>
                @if ($type === 'checkout')
                    <label class="form-field">
                        What time do you need to leave?
                        <input type="datetime-local" name="requested_out_at" value="{!! h($values['requested_out_at']) !!}" required>
                    </label>
                @else

                    <label class="form-field">
                        What time do you expect to arrive back?
                        <input type="datetime-local" name="expected_return_at" value="{!! h($values['expected_return_at']) !!}" required>
                    </label>
                @endif
                <div class="form-actions">
                    <button type="submit">Save changes</button>
                    <a href="my_requests.php" class="btn-secondary">Cancel</a>
                </div>
            </form>
        </fieldset>
    </main>

@endsection
