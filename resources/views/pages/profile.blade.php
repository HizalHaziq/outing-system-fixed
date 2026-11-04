@extends('layouts.app')
@section('title', 'My profile - Outing System')
@section('content')

    

    <main class="form-page">
        <h1>My profile</h1>
        {!! flash_render() !!}

        @php
if ($userType === 'student') {
            $cardName = (string) $profile['std_name'];
            $cardId   = (string) $profile['std_no'];
            $hasPhoto = student_photo_file($cardId) !== null;
            $emergency = trim((string) ($profile['emergency_contact_name'] ?? ''));
            if (!empty($profile['emergency_contact_phone'])) {
                $emergency .= ' (' . $profile['emergency_contact_phone'] . ')';
            }
            $rows = [
                'Program'           => $profile['program'] ?? '',
                'IC number'         => $profile['ic_no'] ?? '',
                'Email'             => $profile['email'] ?? '',
                'Phone'             => $profile['phone'] ?? '',
                'Emergency contact' => $emergency,
            ];
        } else {
            $cardName = (string) $profile['name'];
            $cardId   = (string) $profile['staff_id'];
            $hasPhoto = false;
            $rows = [
                'Role'  => ucfirst((string) $profile['role']),
                'Email' => $profile['email'] ?? '',
            ];
        }
@endphp
        <div class="profile-card">
            <div class="profile-card__head">
                @if ($hasPhoto)
                    <img class="profile-card__avatar" src="student_photo.php?std_no={!! urlencode($cardId) !!}" alt="{!! h($cardName) !!}">
                @else

                    <div class="profile-card__avatar profile-card__avatar--initials">{!! h(initials($cardName)) !!}</div>
                @endif
                <div class="profile-card__name">{!! h($cardName) !!}</div>
                <div class="profile-card__id">({!! h($cardId) !!})</div>
            </div>
            <div class="profile-card__body">
                @foreach ($rows as $label => $value)
                    <div class="profile-card__row">
                        <span class="profile-card__label">{!! h($label) !!} :</span>
                        <span class="profile-card__value">{!! trim((string) $value) !== '' ? h((string) $value) : '&mdash;' !!}</span>
                    </div>
                @endforeach
            </div>
            <p class="profile-card__note">This information comes from the university database and can't be edited here. Contact your administrator if anything needs correcting.</p>
        </div>

        <div class="form-card">
            <h2>Change password</h2>
            <form method="post" action="change_password.php">
                @csrf
                <label class="form-field">Current password
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <label class="form-field">New password (min. 8 characters)
                    <input type="password" name="new_password" autocomplete="new-password" required>
                </label>
                <label class="form-field">Confirm new password
                    <input type="password" name="confirm_password" autocomplete="new-password" required>
                </label>
                <div class="form-actions">
                    <button type="submit">Change password</button>
                </div>
            </form>
        </div>
    </main>

    

@endsection
