<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Student;
use App\Services\Curfew;
use App\Services\GateService;
use App\Services\GroupService;
use App\Services\Notifier;
use App\Services\QrService;
use App\Services\ReadService;
use App\Services\RequestService;
use App\Services\ScheduledWork;
use App\Services\Settings;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OutingTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private Staff $guard;

    private Staff $warden;

    private Staff $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(12, 0));
        $this->student = $this->student('S001');
        foreach (['guard', 'warden', 'admin'] as $role) {
            $this->$role = Staff::create(['staff_id' => strtoupper($role), 'name' => ucfirst($role), 'role' => $role, 'password_hash' => Hash::make('Password123!'), 'is_active' => 1, 'must_change_password' => 0, 'email' => $role.'@example.test', 'created_at' => now()]);
        }
    }

    private function student(string $std): Student
    {
        return Student::create(['std_no' => $std, 'std_name' => 'Student '.$std, 'ic_no' => '080101-01-1234', 'password_hash' => Hash::make('Password123!'), 'is_active' => 1, 'must_change_password' => 0, 'email' => $std.'@example.test', 'created_at' => now()]);
    }

    private function asUser($user): static
    {
        Auth::guard('staff')->logout();
        Auth::guard('student')->logout();

        return $this->actingAs($user, $user instanceof Staff ? 'staff' : 'student');
    }

    private function grant(string $subject, ?Staff $guard = null): string
    {
        return app(QrService::class)->grant($subject, $guard ?? $this->guard, 'test-session', false);
    }

    private function move(string $std, string $action, ?int $ref = null, ?string $grant = null): mixed
    {
        $d = ['std_no' => $std, 'action' => $action];
        if (str_starts_with($action, 'standard') && $ref) {
            $d['record_id'] = $ref;
        } elseif ($ref) {
            $d['request_id'] = $ref;
        }

        return app(QrService::class)->perform($grant ?? $this->grant('S:'.$std), $this->guard, 'test-session', $d);
    }

    private function out(?Student $s = null): int
    {
        $s ??= $this->student;
        $this->move($s->std_no, 'standard_check_out');

        return DB::table('standard_outing')->where('std_no', $s->std_no)->whereNull('checked_in_at')->value('id');
    }

    private function requestRow(array $overrides = []): int
    {
        return DB::table('outing_request')->insertGetId(array_merge(['std_no' => $this->student->std_no, 'type' => 'checkout', 'reason' => 'Emergency', 'destination' => 'Hospital', 'requested_out_at' => now()->format('Y-m-d H:i:s'), 'status' => 'approved', 'created_at' => now()], $overrides));
    }

    private function invalid(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_login_logout_and_session_rotation(): void
    {
        $this->get('/login_student.php')->assertOk();
        $old = session()->getId();
        $this->post('/login_student.php', ['std_no' => 'S001', 'password' => 'Password123!'])->assertRedirect('/student_dashboard.php');
        $this->assertAuthenticatedAs($this->student, 'student');
        $this->assertNotEquals($old, session()->getId());
        $this->get('/logout.php')->assertOk();
        $this->assertAuthenticated('student');
        $this->post('/logout.php')->assertRedirect('/login_student.php');
        $this->assertGuest('student');
    }

    public function test_login_is_throttled_and_disabled_accounts_fail(): void
    {
        $this->student->update(['is_active' => 0]);
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login_student.php', ['std_no' => 'S001', 'password' => 'Password123!']);
        }$response->assertSessionHasErrors();
        $this->assertGuest('student');
        $this->assertStringContainsString('Too many', session('errors')->first());
    }

    public function test_forced_password_change_and_password_validation(): void
    {
        $this->student->update(['must_change_password' => 1]);
        $this->asUser($this->student)->get('/my_qr.php')->assertRedirect('/change_password.php');
        $this->post('/change_password.php', ['current_password' => 'bad', 'new_password' => 'ChangedPass123!', 'confirm_password' => 'ChangedPass123!'])->assertSessionHasErrors('current_password');
        $this->post('/change_password.php', ['current_password' => 'Password123!', 'new_password' => 'ChangedPass123!', 'confirm_password' => 'ChangedPass123!'])->assertRedirect('/student_dashboard.php');
        $this->assertFalse($this->student->fresh()->must_change_password);
        $this->get('/my_qr.php')->assertOk();
    }

    public function test_account_deactivation_and_session_expiry_apply_to_photos_and_pages(): void
    {
        $this->asUser($this->student)->withSession(['login_at' => now()->timestamp - 28801])->get('/my_history.php')->assertRedirect();
        $this->assertGuest('student');
        $this->asUser($this->guard)->withSession(['last_activity' => now()->timestamp - 3601])->get('/gate.php')->assertRedirect();
        $this->asUser($this->student);
        $this->student->update(['is_active' => 0]);
        $this->get('/student_photo.php?std_no=S001')->assertRedirect();
        $this->assertGuest('student');
    }

    public function test_only_wardens_review_and_only_guards_move(): void
    {
        $id = $this->requestRow(['status' => 'pending']);
        foreach ([$this->admin, $this->guard, $this->student] as $u) {
            $this->asUser($u)->post('/review_request.php', ['request_id' => $id, 'decision' => 'approved'])->assertForbidden();
        }
        foreach ([$this->admin, $this->warden, $this->student] as $u) {
            $this->asUser($u)->post('/gate_action.php', [])->assertForbidden();
        }
        $this->asUser($this->warden)->post('/review_request.php', ['request_id' => $id, 'decision' => 'approved'])->assertRedirect('/pending_requests.php');
        $this->assertDatabaseHas('outing_request', ['id' => $id, 'reviewed_by' => 'WARDEN', 'status' => 'approved']);
    }

    public function test_ownership_prevents_edit_cancel_and_notification_idor(): void
    {
        $other = $this->student('S002');
        $id = $this->requestRow(['std_no' => $other->std_no, 'status' => 'pending']);
        $this->asUser($this->student)->get('/edit_request.php?id='.$id)->assertNotFound();
        $this->post('/cancel_request.php', ['id' => $id])->assertForbidden();
        app(Notifier::class)->student($other->std_no, 'test', 'Private');
        $nid = DB::table('notification')->where('std_no', $other->std_no)->value('id');
        $this->postJson('/notification_action.php', ['action' => 'delete', 'id' => $nid])->assertOk();
        $this->assertDatabaseHas('notification', ['id' => $nid]);
    }

    public function test_request_creation_edit_rejection_and_cancellation(): void
    {
        $d = ['type' => 'checkout', 'reason' => 'Medical', 'destination' => 'Clinic', 'requested_out_at' => '2026-11-04T23:10'];
        $this->asUser($this->student)->post('/new_request.php', $d)->assertSessionHasNoErrors()->assertRedirect('/my_requests.php');
        $id = DB::table('outing_request')->value('id');
        $this->get('/edit_request.php?id='.$id)->assertOk();
        $this->post('/edit_request.php', $d + ['id' => $id])->assertRedirect('/my_requests.php');
        $this->asUser($this->warden)->post('/review_request.php', ['request_id' => $id, 'decision' => 'rejected'])->assertSessionHasErrors('rejection_reason');
        $this->post('/review_request.php', ['request_id' => $id, 'decision' => 'rejected', 'rejection_reason' => 'No supporting details'])->assertRedirect('/pending_requests.php');
        $this->asUser($this->student)->post('/new_request.php', $d)->assertRedirect('/my_requests.php');
        $second = DB::table('outing_request')->max('id');
        $this->post('/cancel_request.php', ['id' => $second])->assertRedirect('/my_requests.php');
        $this->assertDatabaseHas('outing_request', ['id' => $second, 'status' => 'cancelled']);
    }

    public function test_standard_departure_return_and_duplicate_submissions(): void
    {
        $first = $this->grant('S:S001');
        $second = $this->grant('S:S001');
        $this->move('S001', 'standard_check_out', grant: $first);
        $this->invalid(fn () => $this->move('S001', 'standard_check_out', grant: $first));
        $this->invalid(fn () => $this->move('S001', 'standard_check_out', grant: $second));
        $this->assertDatabaseCount('standard_outing', 1);
        $this->assertDatabaseCount('active_outing', 1);
        $id = DB::table('standard_outing')->value('id');
        $this->move('S001', 'standard_check_in', $id);
        $this->assertDatabaseCount('active_outing', 0);
        $this->assertDatabaseHas('standard_outing', ['id' => $id, 'is_late_return' => 0]);
    }

    public function test_http_gate_requires_identity_check_and_executes_current_form(): void
    {
        $page = $this->asUser($this->guard)->get('/gate.php?std_no=S001')->assertOk();
        $id = $page->viewData('gateGrant');
        $this->withCookie(config('session.cookie'), session()->getId());
        $data = ['action' => 'standard_check_out', 'std_no' => 'S001', 'verification_id' => $id];
        $this->post('/gate_action.php', $data)->assertSessionHasErrors('identity_confirmed');
        $this->post('/gate_action.php', $data + ['identity_confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect('/gate.php');
        $this->assertDatabaseCount('standard_outing', 1);
    }

    public function test_curfew_boundaries_and_nonwrapping_window(): void
    {
        $c = app(Curfew::class);
        foreach (['2026-11-04 22:59:59' => false, '2026-11-04 23:00:00' => true, '2026-11-05 00:00:00' => true, '2026-11-05 05:59:59' => true, '2026-11-05 06:00:00' => false] as $time => $expected) {
            $this->assertSame($expected, $c->active($time));
        }
        DB::table('app_setting')->where('setting_key', 'curfew_start')->update(['setting_value' => '01:00:00']);
        DB::table('app_setting')->where('setting_key', 'curfew_end')->update(['setting_value' => '05:00:00']);
        $this->assertFalse($c->active('2026-11-04 00:59'));
        $this->assertTrue($c->active('2026-11-04 01:00'));
        $this->assertFalse($c->active('2026-11-04 05:00'));
    }

    public function test_stale_departure_at_curfew_and_current_curfew_rejection(): void
    {
        $this->travelTo(now()->setTime(22, 59));
        $grant = $this->grant('S:S001');
        $this->travel(1)->minutes();
        $this->invalid(fn () => $this->move('S001', 'standard_check_out', grant: $grant));
        $this->invalid(fn () => $this->move('S001', 'standard_check_out'));
        $this->assertDatabaseCount('standard_outing', 0);
    }

    public function test_special_departure_overdue_and_return_close_same_request(): void
    {
        $this->travelTo(now()->setTime(23, 10));
        $id = $this->requestRow(['expected_return_at' => now()->addMinutes(10)]);
        $this->move('S001', 'special_check_out', $id);
        $this->travel(26)->minutes();
        $this->assertSame(1, app(ScheduledWork::class)->overdue());
        $this->assertSame('special_out', app(GateService::class)->route('S001')['state']);
        $this->move('S001', 'special_check_in', $id);
        $this->assertDatabaseHas('outing_request', ['id' => $id, 'status' => 'checked_in']);
        $this->assertDatabaseCount('active_outing', 0);
        $this->assertCount(1, app(ReadService::class)->get_violations('S001'));
    }

    public function test_special_departure_cannot_overlap_standard_or_start_early(): void
    {
        $id = $this->requestRow(['requested_out_at' => now()->addHour()]);
        $this->invalid(fn () => $this->move('S001', 'special_check_out', $id));
        DB::table('outing_request')->where('id', $id)->update(['status' => 'cancelled']);
        $trip = $this->out();
        DB::table('outing_request')->where('id', $id)->update(['status' => 'approved', 'requested_out_at' => now()]);
        $this->invalid(fn () => $this->move('S001', 'special_check_out', $id));
        $this->assertDatabaseCount('active_outing', 1);
    }

    public function test_inactive_students_cannot_depart_but_can_return_on_both_tracks(): void
    {
        $this->student->update(['is_active' => 0]);
        $this->invalid(fn () => $this->move('S001', 'standard_check_out'));
        $this->student->update(['is_active' => 1]);
        $trip = $this->out();
        $this->student->update(['is_active' => 0]);
        $this->move('S001', 'standard_check_in', $trip);
        $this->assertDatabaseCount('active_outing', 0);
        $this->student->update(['is_active' => 1]);
        $id = $this->requestRow();
        $this->move('S001', 'special_check_out', $id);
        $this->student->update(['is_active' => 0]);
        $this->move('S001', 'special_check_in', $id);
        $this->assertDatabaseCount('active_outing', 0);
    }

    public function test_unreviewed_arrival_expiry_and_fair_return_policy(): void
    {
        $trip = $this->out();
        $this->travelTo(now()->setTime(22, 0));
        $id = app(RequestService::class)->save($this->student, ['type' => 'checkin', 'reason' => 'Bus', 'destination' => 'Home', 'expected_return_at' => '2026-11-05T00:30']);
        $this->travelTo(now()->setTime(23, 59)->addDay()); // Move to the intended arrival morning below.
        $this->travelTo(Carbon::parse('2026-11-05 00:01:00'));
        $this->assertSame(1, app(RequestService::class)->expire());
        $this->assertDatabaseHas('outing_request', ['id' => $id, 'status' => 'expired', 'unreviewed_by_staff' => 1]);
        $this->invalid(fn () => app(RequestService::class)->review($this->warden, $id, 'approved', null));
        $this->move('S001', 'standard_check_in', $trip);
        $this->assertDatabaseHas('standard_outing', ['id' => $trip, 'is_late_return' => 0, 'arrival_request_id' => $id]);
        $this->assertCount(1, app(ReadService::class)->get_student_outing_history('S001'));
        $this->assertCount(0, app(ReadService::class)->get_violations('S001'));
    }

    public function test_unreviewed_arrival_does_not_cover_other_curfew_window(): void
    {
        $this->out();
        $id = $this->requestRow(['type' => 'checkin', 'destination' => null, 'coming_from' => 'Home', 'requested_out_at' => null, 'expected_return_at' => '2026-11-06 01:00:00', 'status' => 'pending']);
        $this->travelTo(Carbon::parse('2026-11-05 00:01:00'));
        $this->assertNull(app(RequestService::class)->arrival('S001'));
    }

    public function test_approved_arrival_can_be_overdue_and_joins_standard_history_once(): void
    {
        $trip = $this->out();
        $this->travelTo(now()->setTime(23, 0));
        $id = $this->requestRow(['type' => 'checkin', 'expected_return_at' => now(), 'status' => 'approved', 'destination' => null]);
        $this->travel(16)->minutes();
        app(ScheduledWork::class)->overdue();
        $this->move('S001', 'standard_check_in', $trip);
        $this->assertDatabaseHas('outing_request', ['id' => $id, 'status' => 'checked_in']);
        $this->assertCount(1, app(ReadService::class)->history('S001'));
        $this->assertCount(1, app(ReadService::class)->get_violations('S001'));
    }

    public function test_on_campus_arrival_request_is_rejected_and_late_standard_return_is_flagged(): void
    {
        $this->invalid(fn () => app(RequestService::class)->save($this->student, ['type' => 'checkin', 'reason' => 'Bus', 'destination' => 'Home', 'expected_return_at' => '2026-11-05T00:30']));
        $trip = $this->out();
        $this->travelTo(now()->setTime(23, 0));
        $this->move('S001', 'standard_check_in', $trip);
        $this->assertDatabaseHas('standard_outing', ['id' => $trip, 'is_late_return' => 1]);
    }

    public function test_qr_expiry_atomic_replay_and_verification_session_binding(): void
    {
        $qr = app(QrService::class);
        $token = $qr->issue($this->student, 'S')['token'];
        $scan = $qr->scan($token, $this->guard, 'test-session');
        $this->invalid(fn () => $qr->scan($token, $this->guard, 'test-session'));
        $this->invalid(fn () => $qr->perform($scan['grant'], $this->guard, 'different-session', ['action' => 'standard_check_out', 'std_no' => 'S001']));
        $other = $this->student('S002');
        $this->invalid(fn () => $this->move('S002', 'standard_check_out', grant: $scan['grant']));
        $this->travel(31)->seconds();
        $this->invalid(fn () => $qr->scan($token, $this->guard, 'test-session'));
        $this->travel(150)->seconds();
        $this->invalid(fn () => $this->move('S001', 'standard_check_out', grant: $scan['grant']));
    }

    public function test_manual_disabled_requires_qr_and_group_token_ownership(): void
    {
        DB::table('app_setting')->where('setting_key', 'allow_manual_gate_entry')->update(['setting_value' => '0']);
        $this->invalid(fn () => $this->move('S001', 'standard_check_out'));
        $qr = app(QrService::class);
        $scan = $qr->scan($qr->issue($this->student, 'S')['token'], $this->guard, 'test-session');
        $this->move('S001', 'standard_check_out', grant: $scan['grant']);
        $this->asUser($this->student)->getJson('/qr_token.php?kind=G')->assertNotFound();
    }

    private function group(): array
    {
        $other = $this->student('S002');
        $groups = app(GroupService::class);
        $groups->act($this->student, ['action' => 'create']);
        $id = DB::table('outing_group')->value('id');
        $groups->act($this->student, ['action' => 'invite', 'group_id' => $id, 'member_std_no' => $other->std_no]);
        $mid = DB::table('outing_group_member')->value('id');
        $groups->act($other, ['action' => 'accept', 'member_row_id' => $mid]);

        return [$id, $other];
    }

    private function batch(int $id, array $selected): array
    {
        $qr = app(QrService::class);
        $v = $qr->grant('G:'.$id, $this->guard, 'test-session', true);

        return $qr->perform($v, $this->guard, 'test-session', ['action' => 'group_process', 'group_id' => $id, 'members' => $selected]);
    }

    public function test_group_invites_batch_individual_return_and_automatic_closure(): void
    {
        [$id,$other] = $this->group();
        $this->asUser($other)->get('/my_group.php')->assertOk();
        $this->getJson('/qr_token.php?kind=G')->assertOk();
        $result = $this->batch($id, ['S001', 'S002']);
        $this->assertCount(2, $result['done']);
        $this->assertDatabaseCount('active_outing', 2);
        $trip = DB::table('standard_outing')->where('std_no', 'S002')->value('id');
        $this->move('S002', 'standard_check_in', $trip);
        $this->assertDatabaseHas('outing_group', ['id' => $id, 'status' => 'active']);
        $this->student->update(['is_active' => 0]);
        $result = $this->batch($id, ['S001']);
        $this->assertTrue($result['closed']);
        $this->assertDatabaseCount('active_outing', 0);
        $this->assertDatabaseCount('outing_group_link', 2);
    }

    public function test_group_rejects_nonmembers_and_membership_changes_after_scan(): void
    {
        [$id,$other] = $this->group();
        $this->invalid(fn () => $this->batch($id, ['S003']));
        $v = app(QrService::class)->grant('G:'.$id, $this->guard, 'test-session', true);
        app(GroupService::class)->act($other, ['action' => 'leave', 'group_id' => $id]);
        $this->invalid(fn () => app(QrService::class)->perform($v, $this->guard, 'test-session', ['action' => 'group_process', 'group_id' => $id, 'members' => ['S001']]));
        $this->assertDatabaseCount('standard_outing', 0);
    }

    public function test_group_batch_excludes_inactive_and_curfew_departures(): void
    {
        [$id,$other] = $this->group();
        $other->update(['is_active' => 0]);
        $result = $this->batch($id, ['S001', 'S002']);
        $this->assertCount(1, $result['done']);
        $this->assertCount(1, $result['skipped']);
        $this->assertDatabaseCount('standard_outing', 1);
        $this->invalid(fn () => app(GroupService::class)->act($this->student, ['action' => 'close', 'group_id' => $id]));
    }

    public function test_group_batch_rolls_back_all_members_on_storage_failure(): void
    {
        [$id,$other] = $this->group();
        DB::table('active_outing')->insert(['std_no' => 'S002', 'kind' => 'standard', 'ref_id' => 999]);
        $this->invalid(fn () => $this->batch($id, ['S001', 'S002']));
        $this->assertDatabaseCount('standard_outing', 0);
        $this->assertDatabaseCount('outing_group_scan', 0);
        $this->assertDatabaseCount('outing_group_log', 0);
    }

    public function test_expired_group_still_allows_recording_departed_members_return(): void
    {
        [$id,$other] = $this->group();
        $this->batch($id, ['S001', 'S002']);
        $this->travel(25)->hours();
        $this->assertNotNull(app(GroupService::class)->find($id));
        $result = $this->batch($id, ['S001', 'S002']);
        $this->assertCount(2, $result['done']);
        $this->assertTrue($result['closed']);
    }

    public function test_import_validates_rows_preserves_hashes_and_forces_new_passwords(): void
    {
        $hash = $this->student->password_hash;
        $csv = "std_no,std_name,ic_no,email\nS001,Updated Student,080101011234,updated@example.test\nS002,New Student,080101011235,new@example.test\nBAD,Bad,123,x\n";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);
        $this->asUser($this->admin)->post('/import_students.php', ['csv_file' => $file])->assertOk()->assertSee('Updated');
        $this->assertSame($hash, $this->student->fresh()->password_hash);
        $this->assertDatabaseHas('student', ['std_no' => 'S002', 'must_change_password' => 1]);
        $this->assertDatabaseMissing('student', ['std_no' => 'BAD']);
        $this->asUser($this->guard)->post('/import_students.php', ['csv_file' => $file])->assertForbidden();
    }

    public function test_private_photo_upload_and_authorized_access(): void
    {
        $path = storage_path('app/private/photos/S001.jpg');
        try {
            $this->asUser($this->admin)->post('/manage_photos.php', ['photos' => [UploadedFile::fake()->image('S001.png', 20, 20)]])->assertOk();
            $this->assertFileExists($path);
            $this->asUser($this->student)->get('/student_photo.php?std_no=S001')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
            $other = $this->student('S002');
            $this->asUser($other)->get('/student_photo.php?std_no=S001')->assertForbidden();
            $this->get('/student_photo.php?std_no=../.env')->assertSessionHasErrors('std_no');
            $this->asUser($this->guard)->get('/student_photo.php?std_no=S001')->assertOk();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_notification_deduplication_survives_clear_and_jobs_are_retryable(): void
    {
        $trip = $this->out();
        $this->travelTo(now()->setTime(22, 0));
        $this->assertSame(1, app(ScheduledWork::class)->reminders());
        $this->assertSame(0, app(ScheduledWork::class)->reminders());
        DB::table('notification')->delete();
        $this->assertSame(0, app(ScheduledWork::class)->reminders());
        $this->move('S001', 'standard_check_in', $trip);
        $id = $this->requestRow(['expected_return_at' => now()->subHour(), 'status' => 'checked_out', 'actual_out_at' => now()->subHours(2)]);
        $this->assertSame(1, app(ScheduledWork::class)->overdue());
        $this->assertSame(0, app(ScheduledWork::class)->overdue());
        $this->assertDatabaseCount('mail_outbox', 3);
        $this->artisan('outing:mail')->assertExitCode(0);
        $this->artisan('outing:mail')->assertExitCode(0);
        $this->assertSame(3, DB::table('mail_outbox')->whereNotNull('sent_at')->count());
    }

    public function test_settings_are_validated_before_any_write_and_admin_resets_force_change(): void
    {
        $this->asUser($this->admin)->post('/manage_settings.php', ['curfew_start' => '25:99'])->assertSessionHasErrors();
        $this->assertSame('23:00:00', app(Settings::class)->get('curfew_start'));
        $this->post('/manage_staff.php', ['form_action' => 'reset_password', 'staff_id' => 'GUARD'])->assertOk()->assertSee('Temporary password');
        $this->assertTrue($this->guard->fresh()->must_change_password);
    }

    public function test_representative_pages_render_for_each_permitted_role(): void
    {
        $pages = ['student' => ['student_dashboard', 'my_qr', 'my_history', 'my_requests', 'my_group', 'new_request', 'profile', 'change_password'], 'guard' => ['dashboard', 'gate', 'currently_out', 'outing_history', 'profile'], 'warden' => ['dashboard', 'pending_requests', 'request_status', 'currently_out', 'outing_history', 'violation_report'], 'admin' => ['dashboard', 'manage_settings', 'manage_staff', 'manage_photos', 'import_students', 'violation_report', 'student_violations?std_no=S001']];
        foreach ($pages as $role => $list) {
            $this->asUser($this->$role);
            foreach ($list as $page) {
                $parts = explode('?', $page, 2);
                $this->get('/'.$parts[0].'.php'.(isset($parts[1]) ? '?'.$parts[1] : ''))->assertOk();
            }
        }
    }

    public function test_dashboard_count_excludes_overdue_arrival_permissions_and_registry_audits(): void
    {
        $this->requestRow(['type' => 'checkin', 'status' => 'overdue', 'expected_return_at' => now()->subHour()]);
        $this->assertSame(0, app(ReadService::class)->get_dashboard_counts()['out_now']);
        $this->out();
        $this->artisan('outing:audit')->assertExitCode(0);
    }

    public function test_database_constraint_rejects_second_active_registry(): void
    {
        $this->out();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('active_outing')->insert(['std_no' => 'S001', 'kind' => 'special', 'ref_id' => 99]);
    }

    public function test_inactive_return_recovery_works_when_manual_entry_is_disabled(): void
    {
        $trip = $this->out();
        $this->student->update(['is_active' => 0]);
        DB::table('app_setting')->where('setting_key', 'allow_manual_gate_entry')->update(['setting_value' => '0']);
        $response = $this->asUser($this->guard)->get('/gate.php?std_no=S001')->assertOk()->assertSee('Inactive account return:');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/gate_action.php', ['action' => 'standard_check_in', 'std_no' => 'S001', 'record_id' => $trip, 'verification_id' => $response->viewData('gateGrant'), 'identity_confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect('/gate.php');
        $this->assertDatabaseCount('active_outing', 0);
        $this->get('/gate.php?std_no=S001')->assertSee('Manual entry is turned off');
        $this->invalid(fn () => $this->move('S001', 'standard_check_out'));
    }

    public function test_student_names_remain_data_in_confirmation_handlers(): void
    {
        $this->student->update(['std_name' => "O'Connor <script>alert(1)</script>"]);
        $response = $this->asUser($this->guard)->get('/gate.php?std_no=S001')->assertOk();
        $response->assertSee('data-confirm="Check out O&#039;Connor &lt;script&gt;alert(1)&lt;/script&gt;?"', false);
        $response->assertSee('onsubmit="return confirm(this.dataset.confirm);"', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_background_qr_refresh_does_not_extend_idle_session(): void
    {
        $last = now()->timestamp - 1790;
        $this->asUser($this->student)->withSession(['login_at' => now()->timestamp - 2000, 'last_activity' => $last])->getJson('/qr_token.php?kind=S')->assertOk();
        $this->assertSame($last, session('last_activity'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->travel(11)->seconds();
        $this->get('/my_qr.php')->assertRedirect();
        $this->assertGuest('student');
    }

    public function test_invalid_csv_headers_are_reported_as_validation_errors(): void
    {
        $file = UploadedFile::fake()->createWithContent('students.csv', "wrong,columns\n1,2\n");
        $this->asUser($this->admin)->post('/import_students.php', ['csv_file' => $file])->assertSessionHasErrors('operation');
    }

    public function test_database_constraint_blocks_raw_duplicate_standard_departure(): void
    {
        $this->out();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('standard_outing')->insert(['std_no' => 'S001', 'checked_out_at' => now(), 'checked_out_by' => 'GUARD']);
    }

    public function test_database_constraint_blocks_raw_duplicate_special_departure(): void
    {
        $this->requestRow(['status' => 'checked_out', 'actual_out_at' => now()]);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->requestRow(['status' => 'overdue', 'actual_out_at' => now()]);
    }

    public function test_first_administrator_can_be_provisioned_without_demo_data(): void
    {
        $this->artisan('outing:admin')->expectsQuestion('New administrator staff ID', 'NEWADMIN')->expectsQuestion('Name', 'New Admin')->expectsQuestion('Email (optional)', 'newadmin@example.test')->expectsQuestion('Password (8–72 bytes)', 'StrongPassword123!')->assertExitCode(0);
        $staff = Staff::where('staff_id', 'NEWADMIN')->firstOrFail();
        $this->assertSame('admin', $staff->role);
        $this->assertTrue($staff->must_change_password);
        $this->assertTrue(Hash::check('StrongPassword123!', $staff->password_hash));
    }
}
