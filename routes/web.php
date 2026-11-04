<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GateController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'home'])->name('home');
Route::get('/index.php', [AuthController::class, 'home']);
foreach (['student', 'staff'] as $kind) {
    Route::get('/login_'.$kind.'.php', [AuthController::class, 'form'])->defaults('page', 'login_'.$kind)->name('login_'.$kind);
    Route::post('/login_'.$kind.'.php', [AuthController::class, 'login'])->name($kind.'.login')->middleware('throttle:30,1');
}
Route::middleware('account')->group(function () {
    Route::get('/change_password.php', [AuthController::class, 'passwordForm'])->name('change_password');
    Route::post('/change_password.php', [AuthController::class, 'password'])->name('password.update');
    Route::get('/logout.php', fn () => view('pages.logout'))->name('logout.confirm');
    Route::post('/logout.php', [AuthController::class, 'logout'])->name('logout');
    Route::get('/profile.php', [PageController::class, 'show'])->defaults('page', 'profile')->name('profile');
    Route::get('/student_photo.php', [AdminController::class, 'photo'])->name('student_photo');
});
Route::middleware('account:student')->group(function () {
    foreach (['student_dashboard', 'my_qr', 'my_history', 'my_requests', 'new_request', 'edit_request', 'my_group'] as $page) {
        Route::get('/'.$page.'.php', [PageController::class, 'show'])->defaults('page', $page)->name($page);
    }
    Route::post('/new_request.php', [RequestController::class, 'save'])->name('requests.store');
    Route::post('/edit_request.php', [RequestController::class, 'save'])->name('requests.update');
    Route::post('/cancel_request.php', [RequestController::class, 'cancel'])->name('requests.cancel');
    Route::post('/group_action.php', [StudentController::class, 'group'])->name('groups.action');
    Route::get('/qr_token.php', [StudentController::class, 'qr'])->name('qr_token');
    Route::get('/get_notifications.php', [StudentController::class, 'notifications'])->name('get_notifications');
    Route::get('/get_unread_count.php', [StudentController::class, 'notifications'])->name('get_unread_count');
    Route::post('/notification_action.php', [StudentController::class, 'notificationAction'])->name('notifications.action');
});
Route::middleware('account:guard,warden,admin')->group(function () {
    foreach (['dashboard', 'currently_out', 'outing_history'] as $page) {
        Route::get('/'.$page.'.php', [PageController::class, 'show'])->defaults('page', $page)->name($page);
    }
    Route::get('/standard_history.php', fn () => redirect()->route('outing_history', request()->only('date_from', 'date_to', 'filter') + ['view' => 'standard'], 301));
});
Route::middleware('account:warden,admin')->group(function () {
    foreach (['pending_requests', 'request_status', 'violation_report', 'student_violations'] as $page) {
        Route::get('/'.$page.'.php', [PageController::class, 'show'])->defaults('page', $page)->name($page);
    }
    Route::get('/request_history.php', fn () => redirect()->route('outing_history', request()->only('date_from', 'date_to', 'status', 'type') + ['view' => 'special'], 301));
    Route::get('/special_reports.php', fn () => redirect()->route('violation_report', [], 301));
});
Route::post('/review_request.php', [RequestController::class, 'review'])->middleware('account:warden')->name('requests.review');
Route::middleware('account:guard')->group(function () {
    foreach (['gate', 'gate_group'] as $page) {
        Route::get('/'.$page.'.php', [PageController::class, 'show'])->defaults('page', $page)->name($page);
    }
    Route::post('/gate.php', [GateController::class, 'scan'])->middleware('throttle:60,1')->name('gate.scan');
    Route::post('/gate_action.php', [GateController::class, 'action'])->name('gate.action');
});
Route::middleware('account:admin')->group(function () {
    foreach (['manage_settings', 'manage_staff', 'manage_photos', 'import_students'] as $page) {
        Route::get('/'.$page.'.php', [PageController::class, 'show'])->defaults('page', $page)->name($page);
    }
    foreach (['manage_settings' => 'settings', 'manage_staff' => 'staff', 'manage_photos' => 'photos', 'import_students' => 'import'] as $page => $method) {
        Route::post('/'.$page.'.php',[AdminController::class, $method])->defaults('page',$page)->name('admin.'.$method);
    }
});
