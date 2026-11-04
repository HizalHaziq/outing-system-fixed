<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/profile.php';
require_once PRIVATE_LIB . '/ui_helpers.php';
require_once PRIVATE_LIB . '/photos.php';

start_secure_session();
$userType = $_SESSION['user_type'] ?? null;
if (!in_array($userType, ['student', 'staff'], true)) {
    header('Location: login_student.php');
    exit;
}

if ($userType === 'student') {
    $profile = get_student_profile($_SESSION['std_no']);
} else {
    $profile = get_staff_profile($_SESSION['staff_id']);
}

if (!$profile) {
    header('Location: logout.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My profile - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php if ($userType === 'staff'): ?>
        <?php require 'menuheader.php'; ?>
        <?php require 'mainmenu.php'; ?>
    <?php else: ?>
        <?php require 'student_header.php'; ?>
    <?php endif; ?>

    <main class="form-page">
        <h1>My profile</h1>
        <?= flash_render() ?>

        <?php
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
        ?>
        <div class="profile-card">
            <div class="profile-card__head">
                <?php if ($hasPhoto): ?>
                    <img class="profile-card__avatar" src="student_photo.php?std_no=<?= urlencode($cardId) ?>" alt="<?= h($cardName) ?>">
                <?php else: ?>
                    <div class="profile-card__avatar profile-card__avatar--initials"><?= h(initials($cardName)) ?></div>
                <?php endif; ?>
                <div class="profile-card__name"><?= h($cardName) ?></div>
                <div class="profile-card__id">(<?= h($cardId) ?>)</div>
            </div>
            <div class="profile-card__body">
                <?php foreach ($rows as $label => $value): ?>
                    <div class="profile-card__row">
                        <span class="profile-card__label"><?= h($label) ?> :</span>
                        <span class="profile-card__value"><?= trim((string) $value) !== '' ? h((string) $value) : '&mdash;' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="profile-card__note">This information comes from the university database and can't be edited here. Contact your administrator if anything needs correcting.</p>
        </div>

        <div class="form-card">
            <h2>Change password</h2>
            <form method="post" action="change_password.php">
                <?= csrf_field() ?>
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

    <?php require 'footer.php'; ?>
</body>
</html>
