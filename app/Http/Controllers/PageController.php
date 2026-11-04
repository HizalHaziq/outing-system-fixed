<?php

namespace App\Http\Controllers;

use App\Services\Curfew;
use App\Services\GateService;
use App\Services\GroupService;
use App\Services\QrService;
use App\Services\ReadService;
use App\Services\RequestService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PageController extends Controller
{
    public function show(Request $http, string|array|null $page = null)
    {
        $extra = is_array($page) ? $page : [];
        $http->validate(['date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d', 'std_no' => 'nullable|string|max:20', 'id' => 'nullable|integer|min:1', 'group_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100', 'view' => 'nullable|in:standard,special', 'filter' => 'nullable|string|max:30', 'status' => 'nullable|string|max:30', 'type' => 'nullable|in:checkout,checkin']);
        $page = $http->route('page');
        $account = profile_account();
        $read = app(ReadService::class);
        $groups = app(GroupService::class);
        $requestsService = app(RequestService::class);
        $error = '';
        $requestsService->expire($account['user_type'] === 'student' ? $account['std_no'] : null);
        switch ($page) {
            case 'currently_out':

                // Standard late returns are fixed at check-in; scheduled jobs flag special deadlines.
                $filter = request()->query('filter', '');

                // Students expected to arrive during curfew under an approved
                // checkin-type request -- not "out" (they may already be back on
                // campus, just not through the gate yet), shown separately so it's
                // never confused with the currently-out list.
                $arrivals = [];
                if ($filter === '' || $filter === 'arrivals') {
                    foreach (app(ReadService::class)->get_pending_arrivals($filter === 'arrivals' && request()->has('overdue_only')) as $r) {
                        $arrivals[] = $r;
                    }
                }

                $rows = [];
                if ($filter !== 'standard' && $filter !== 'arrivals') {
                    foreach (app(ReadService::class)->get_special_currently_out($filter === 'overdue') as $r) {
                        $rows[] = [
                            'track' => 'special',
                            'std_name' => $r['std_name'],
                            'std_no' => $r['std_no'],
                            'destination' => $r['destination'],
                            'reason' => $r['reason'],
                            'out_since' => $r['actual_out_at'],
                            'expected' => $r['expected_return_at'],
                            'status' => status_label($r['status']),
                        ];
                    }
                }
                if ($filter !== 'special' && $filter !== 'overdue' && $filter !== 'arrivals') {
                    foreach (app(ReadService::class)->get_standard_currently_out() as $r) {
                        $rows[] = [
                            'track' => 'standard',
                            'std_name' => $r['std_name'],
                            'std_no' => $r['std_no'],
                            'destination' => '',
                            'reason' => '',
                            'out_since' => $r['checked_out_at'],
                            'expected' => '',
                            'status' => 'Out',
                        ];
                    }
                }
                usort($rows, fn ($a, $b) => strcmp($b['out_since'], $a['out_since']));

                break;
            case 'dashboard':

                $role = $account['role'];
                $canApprove = in_array($role, ['admin', 'warden'], true);
                $canGate = $role === 'guard';
                $isAdmin = $role === 'admin';

                // Counts compute deadlines directly, independently of the scheduled status update.
                $counts = app(ReadService::class)->get_dashboard_counts();

                $allPending = $canApprove ? app(ReadService::class)->get_pending_requests() : [];
                $pendingCount = count($allPending);
                $pendingPreview = array_slice($allPending, 0, 5);

                $dueBackToday = app(ReadService::class)->get_due_back_today(8);

                $violations = $canApprove ? array_slice(app(ReadService::class)->get_violation_summary(), 0, 5) : [];
                $recentActivity = $canGate ? app(ReadService::class)->get_recent_gate_activity(8) : [];
                $staffSummary = $isAdmin ? app(ReadService::class)->get_staff_summary() : null;

                $curfewNow = app(Curfew::class)->active();

                break;
            case 'my_history':

                // Always the logged-in student's own number from the session -- never from the URL.
                $history = app(ReadService::class)->get_student_outing_history($account['std_no']);

                $resultCounts = array_count_values(array_column($history, 'result'));
                $total = count($history);
                $onTime = $resultCounts['on_time'] ?? 0;
                $violations = ($resultCounts['late'] ?? 0);

                $badges = [
                    'on_time' => ['badge-ok',   'On time'],
                    'late' => ['badge-late', 'Late'],
                    'out' => ['badge-out',  'Still out'],
                    'awaiting' => ['badge-out',  'Awaiting arrival'],
                ];

                break;
            case 'my_requests':

                $requests = app(ReadService::class)->get_student_requests($account['std_no']);

                break;
            case 'outing_history':

                $role = $account['role'];
                // Special-track history carries the same reviewer/rejection detail as the
                // approval workflow itself, so it's gated the same way pending_requests.php
                // and request_history.php already are -- a guard never sees the toggle,
                // let alone the tab, not just gets blocked if they guess the URL.
                $canViewSpecial = in_array($role, ['admin', 'warden'], true);

                $view = request()->query('view', 'standard');
                if ($view !== 'special' || ! $canViewSpecial) {
                    $view = 'standard';
                }

                $dateRange = parse_date_range();

                if ($view === 'standard') {
                    $filter = request()->query('filter', '');
                    $records = app(ReadService::class)->get_standard_history($filter === 'late', $dateRange['from'], $dateRange['to']);
                    $lateCount7d = app(ReadService::class)->count_late_returns(7);
                } else {
                    // Executed/gate statuses only -- pending/approved/rejected/cancelled
                    // (the approval-workflow statuses) live on request_status.php instead.
                    $validStatuses = ['checked_out', 'checked_in', 'overdue'];
                    $statusFilter = request()->query('status', '');
                    if (! in_array($statusFilter, $validStatuses, true)) {
                        $statusFilter = '';
                    }
                    $typeFilter = request()->query('type', '');
                    if (! in_array($typeFilter, OUTING_REQUEST_TYPES, true)) {
                        $typeFilter = '';
                    }
                    $requests = app(ReadService::class)->get_all_requests(
                        $statusFilter !== '' ? $statusFilter : null,
                        $typeFilter !== '' ? $typeFilter : null,
                        $dateRange['from'],
                        $dateRange['to'],
                        $validStatuses
                    );
                }

                break;
            case 'request_status':

                // Same audience as pending_requests.php / the special view on
                // outing_history.php -- a guard never sees this workflow detail.

                $validStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'expired'];
                $statusFilter = request()->query('status', '');
                if (! in_array($statusFilter, $validStatuses, true)) {
                    $statusFilter = '';
                }
                $typeFilter = request()->query('type', '');
                if (! in_array($typeFilter, OUTING_REQUEST_TYPES, true)) {
                    $typeFilter = '';
                }

                $dateRange = parse_date_range();

                $requests = app(ReadService::class)->get_all_requests(
                    $statusFilter !== '' ? $statusFilter : null,
                    $typeFilter !== '' ? $typeFilter : null,
                    $dateRange['from'],
                    $dateRange['to'],
                    $validStatuses
                );

                break;
            case 'student_dashboard':

                // gate_ops, standard_ops, curfew

                $stdNo = $account['std_no'];
                $name = (string) ($account['name'] ?? '');

                // --- Where am I right now? (same lookups the gate uses) ---
                $standardOpen = app(ReadService::class)->get_open_standard_outing($stdNo);
                $specialOut = $standardOpen ? null : app(ReadService::class)->get_active_checkout($stdNo);
                $specialReady = ($standardOpen || $specialOut) ? null : app(ReadService::class)->get_checkoutable_request($stdNo);
                $arrival = app(RequestService::class)->arrival($stdNo);

                $inCurfew = app(Curfew::class)->active();
                $curfewLabel = app(Curfew::class)->label();
                $curfewStart = date('g:i A', strtotime(app(Settings::class)->get('curfew_start')));

                // --- Counts + recent activity ---
                $history = app(ReadService::class)->get_student_outing_history($stdNo);
                $counts = array_count_values(array_column($history, 'result'));
                $total = count($history);
                $onTime = $counts['on_time'] ?? 0;
                $violations = ($counts['late'] ?? 0);
                $recent = array_slice($history, 0, 5);

                $requests = app(ReadService::class)->get_student_requests($stdNo);
                $reqCounts = array_count_values(array_column($requests, 'status'));
                $pendingReqs = $reqCounts['pending'] ?? 0;
                $approvedReqs = $reqCounts['approved'] ?? 0;

                $group = app(GroupService::class)->forStudent($stdNo);
                $invitations = app(GroupService::class)->invitations($stdNo);

                $badges = [
                    'on_time' => ['badge-ok',   'On time'],
                    'late' => ['badge-late', 'Late'],
                    'out' => ['badge-out',  'Still out'],
                    'awaiting' => ['badge-out',  'Awaiting arrival'],
                ];

                break;
            case 'student_violations':

                $stdNo = trim((string) (request()->query('std_no', '')));
                $student = $stdNo !== '' ? app(ReadService::class)->find_student_for_gate($stdNo) : null;
                abort_unless($student, 404);
                $dateRange = parse_date_range();
                $violations = app(ReadService::class)->get_violations($student['std_no'], $dateRange['from'], $dateRange['to']);

                $backQuery = http_build_query(array_filter([
                    'date_from' => $dateRange['from'],
                    'date_to' => $dateRange['to'],
                ]));

                break;
            case 'violation_report':

                $dateRange = parse_date_range();
                $search = trim((string) (request()->query('q', '')));

                $allViolations = app(ReadService::class)->get_violations(null, $dateRange['from'], $dateRange['to']);
                $rows = app(ReadService::class)->search_violations($allViolations, $search);

                $totalViolations = count($allViolations);
                $studentCount = count(array_unique(array_column($allViolations, 'std_no')));
                $overdueNow = app(ReadService::class)->count_overdue_now();

                $hasFilter = $search !== '' || $dateRange['from'] !== null || $dateRange['to'] !== null;

                break;
            case 'my_qr':

                $stdNo = $account['std_no'];
                $stdName = $account['name'] ?? '';
                $track = (app(Curfew::class)->active() ? 'special' : 'standard'); // server time decides

                break;
            case 'pending_requests':$canDecide = $account['role'] === 'warden';
                $requests = $read->get_pending_requests();
                break;
            case 'new_request':$isOut = $requestsService->isOut($account['std_no']);
                $values = array_merge(['type' => 'checkout', 'reason' => '', 'destination' => '', 'requested_out_at' => '', 'expected_return_at' => ''], $http->old());
                break;
            case 'edit_request':
                $id = (int) $http->input('id');
                $request = $read->get_request_for_student($id, $account['std_no']);
                abort_unless($request && $request['status'] === 'pending', 404);
                $type = $request['type'];
                $values = ['reason' => $request['reason'], 'destination' => $type === 'checkin' ? ($request['coming_from'] ?? '') : ($request['destination'] ?? ''), 'requested_out_at' => $request['requested_out_at'] ? to_datetime_local($request['requested_out_at']) : '', 'expected_return_at' => $request['expected_return_at'] ? to_datetime_local($request['expected_return_at']) : ''];
                $values = array_merge($values, $http->old());
                break;
            case 'my_group':
                $stdNo = $account['std_no'];
                $g = $groups->forStudent($stdNo);
                $leadGroup = $g && $g['lead_std_no'] === $stdNo ? $g : null;
                $memberGroup = $leadGroup ? null : $g;
                $invites = $groups->invitations($stdNo);
                $members = $leadGroup ? $groups->members($leadGroup['id']) : [];
                $accepted = count(array_filter($members, fn ($m) => $m['status'] === 'accepted'));
                $roster = $memberGroup ? $groups->participants($memberGroup['id']) : [];
                $qrGroup = $g;
                $qrPeople = $g ? count($groups->participants($g['id'])) : 0;
                $showQr = $g && $qrPeople >= 2;
                break;
            case 'profile':$userType = $account['user_type'];
                $profile = $userType === 'student' ? $read->get_student_profile($account['std_no']) : $read->get_staff_profile($account['staff_id']);
                break;
            case 'manage_settings':$settings = app(Settings::class)->all();
                break;
            case 'manage_staff':$staffList = $read->get_all_staff();
                $generatedPassword = null;
                $passwordForStaffId = null;
                $createError = null;
                break;
            case 'manage_photos':$totalStudents = DB::table('student')->where('is_active', 1)->count();
                $results = [];
                break;
            case 'import_students':$result = null;
                $fatalError = null;
                break;
            case 'gate':
                $stdNo = trim((string) $http->query('std_no', ''));
                $manualOk = app(Settings::class)->get('allow_manual_gate_entry') === '1';
                $student = null;
                $notFound = false;
                $manualBlocked = false;
                $qrVerified = false;
                $inactiveReturnRecovery = false;
                $gateGrant = '';
                $curfewNow = app(Curfew::class)->active();
                $standardOpen = $specialActive = $specialReady = $arrivalApproval = null;
                if ($stdNo !== '') {
                    $qr = app(QrService::class);
                    $subject = 'S:'.$stdNo;
                    $gateGrant = $http->session()->get('gate_grants.'.$subject);
                    $valid = $qr->valid($gateGrant, $subject, $http->user(), $http->session()->getId());
                    $qrVerified = $valid && DB::table('gate_verification')->where('id', $gateGrant)->value('via_qr');
                    $lookupStudent = $read->find_student_for_gate($stdNo);
                    $inactiveReturnRecovery = $lookupStudent && ! $lookupStudent['is_active'] && $requestsService->isOut($stdNo);
                    if (! $valid && ! $manualOk && ! $inactiveReturnRecovery) {
                        $manualBlocked = true;
                        $stdNo = '';
                        $gateGrant = '';
                    } else {
                        $route = app(GateService::class)->route($stdNo);
                        $student = $read->find_student_for_gate($stdNo);
                        $notFound = ! $student;
                        $standardOpen = $route['standard_open'];
                        $specialActive = $route['special_active'];
                        $specialReady = $route['special_ready'];
                        $arrivalApproval = $route['arrival_approval'];
                        if ($student && ! $valid) {
                            $gateGrant = $qr->grant($subject, $http->user(), $http->session()->getId(), false);
                        }
                    }
                }break;
            case 'gate_group':
                $groupId = (int) $http->query('group_id');
                $group = $groups->find($groupId);
                $gateGrant = $http->session()->get('gate_grants.G:'.$groupId);
                if (! $group || ! app(QrService::class)->valid($gateGrant, 'G:'.$groupId, $http->user(), $http->session()->getId())) {
                    return redirect()->route('gate')->with('flash', ['type' => 'error', 'message' => 'Scan the group Master QR to open it.']);
                }
                $view = app(GateService::class)->groupView($groupId);
                $rows = $view['rows'];
                $toneColor = ['ok' => 'inherit', 'info' => '#2b6cb0', 'bad' => 'red'];
                break;
            default:abort(404);
        }
        $data = get_defined_vars();
        unset($data['this'],$data['http'],$data['extra']);

        return view('pages.'.$page, array_merge($data, $extra));
    }
}
