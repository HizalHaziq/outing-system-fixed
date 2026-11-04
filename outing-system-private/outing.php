<?php
// Business logic for the outing request lifecycle (phase 2:
// student submits -> staff approves/rejects). Gate check-out/check-in
// (phase 3) is not handled here.
//
// A request now has a `type`, and the two types are NOT a round trip
// split in half -- they're two independent kinds of permission:
//
//   checkout: "I need to LEAVE during curfew" (emergency, early
//             departure). Only requested_out_at matters. Guard checks
//             the student out against this once approved. Their
//             eventual return is closed out on this SAME request
//             (see gate_ops.php::gate_check_in()), but there is no
//             deadline pressure unless the student chose to give one.
//
//   checkin:  "I will ARRIVE during curfew" (e.g. a midnight bus).
//             Only expected_return_at matters. This is not a gate --
//             nothing blocks on it -- it's a pre-clearance so the
//             arrival isn't wrongly flagged as a violation. See
//             find_active_checkin_approval() / fulfill_checkin_approval()
//             below, used by both standard_ops.php and gate_ops.php.
//
// Every write goes through a prepared statement. Multi-step writes
// (request + its audit event) are wrapped in a transaction so we never
// end up with a request row and no matching outing_event row.

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/curfew.php';   // curfew_end()
require_once __DIR__ . '/settings.php'; // special_tolerance_minutes()

const OUTING_REQUEST_TYPES = ['checkout', 'checkin'];

/**
 * Basic sanity check shared by create and edit: the one time field
 * that matters for this request's type cannot be blank or in the
 * past. Throws InvalidArgumentException with a message safe to show
 * the user.
 */
function validate_request_type_and_time(string $type, ?string $requestedOutAt, ?string $expectedReturnAt): void
{
    if (!in_array($type, OUTING_REQUEST_TYPES, true)) {
        throw new InvalidArgumentException('Invalid request type.');
    }

    if ($type === 'checkout') {
        if ($requestedOutAt === null || trim($requestedOutAt) === '') {
            throw new InvalidArgumentException('Please enter when you need to leave.');
        }
        $out = strtotime($requestedOutAt);
        if ($out === false) {
            throw new InvalidArgumentException('Please enter a valid date/time.');
        }
        if ($out < strtotime('-5 minutes')) {
            throw new InvalidArgumentException('Requested out time cannot be in the past.');
        }
        if (!is_within_curfew($requestedOutAt)) {
            throw new InvalidArgumentException(
                'Special requests are only for curfew hours (' . curfew_window_label() . '). Choose a leave time inside that window.'
            );
        }
        return;
    }

    // checkin
    if ($expectedReturnAt === null || trim($expectedReturnAt) === '') {
        throw new InvalidArgumentException('Please enter when you expect to arrive.');
    }
    $return = strtotime($expectedReturnAt);
    if ($return === false) {
        throw new InvalidArgumentException('Please enter a valid date/time.');
    }
    if ($return < strtotime('-5 minutes')) {
        throw new InvalidArgumentException('Expected arrival time cannot be in the past.');
    }
    if (!is_within_curfew($expectedReturnAt)) {
        throw new InvalidArgumentException(
            'Special requests are only for curfew hours (' . curfew_window_label() . '). Choose an arrival time inside that window.'
        );
    }
}

function log_outing_event(
    PDO $pdo,
    int $requestId,
    string $eventType,
    string $actorType,
    ?string $actorId,
    ?string $note = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO outing_event (request_id, event_type, actor_type, actor_id, note)
         VALUES (:request_id, :event_type, :actor_type, :actor_id, :note)'
    );
    $stmt->execute([
        'request_id' => $requestId,
        'event_type' => $eventType,
        'actor_type' => $actorType,
        'actor_id'   => $actorId,
        'note'       => $note,
    ]);
}

// --- Student actions ------------------------------------------------------

/**
 * True if the system has this student off campus right now: an open
 * standard outing, or a checkout request that is checked out / overdue.
 * No open record means on campus.
 */
function student_is_out(string $stdNo): bool
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT (EXISTS (SELECT 1 FROM standard_outing WHERE std_no = :a AND checked_in_at IS NULL)
              OR EXISTS (SELECT 1 FROM outing_request
                         WHERE std_no = :b AND type = "checkout" AND status IN ("checked_out", "overdue")))'
    );
    $stmt->execute(['a' => $stdNo, 'b' => $stdNo]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Called inside a check-in's transaction, after the student is back on
 * campus: any arrival request still pending/approved is moot, so cancel
 * it. Otherwise it could be approved later (or sit approved) while the
 * student is on campus and hijack their next gate visit.
 */
function cancel_leftover_arrival_requests(PDO $pdo, string $stdNo): int
{
    $sel = $pdo->prepare(
        'SELECT id FROM outing_request
         WHERE std_no = :std_no AND type = "checkin" AND status IN ("pending", "approved")'
    );
    $sel->execute(['std_no' => $stdNo]);
    $count = 0;
    foreach ($sel->fetchAll() as $row) {
        $upd = $pdo->prepare(
            'UPDATE outing_request SET status = "cancelled"
             WHERE id = :id AND type = "checkin" AND status IN ("pending", "approved")'
        );
        $upd->execute(['id' => $row['id']]);
        if ($upd->rowCount() > 0) {
            log_outing_event($pdo, (int) $row['id'], 'cancelled', 'system', null, 'Student already back on campus');
            $count++;
        }
    }
    return $count;
}

function create_outing_request(
    string $stdNo,
    string $type,
    string $reason,
    string $destination,
    ?string $requestedOutAt,
    ?string $expectedReturnAt
): int {
    validate_request_type_and_time($type, $requestedOutAt, $expectedReturnAt);

    // Arrival permission only makes sense for someone who is out. A student
    // on campus has nothing to check in, and the request would make the gate
    // offer "check in" when they actually want to check out.
    if ($type === 'checkin' && !student_is_out($stdNo)) {
        throw new InvalidArgumentException(
            'You can only request arrival permission while you are checked out. You are currently on campus.'
        );
    }

    // Only the field this type actually uses is stored -- the other
    // stays NULL rather than holding a stale/irrelevant value.
    $requestedOutAt   = $type === 'checkout' ? $requestedOutAt : null;
    $expectedReturnAt = $type === 'checkin' ? $expectedReturnAt : null;

    // $destination is "the place" the student typed: where they are
    // going (checkout) or where they are arriving from (checkin).
    $comingFrom  = $type === 'checkin' ? $destination : null;
    $destination = $type === 'checkout' ? $destination : null;

    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO outing_request
                (std_no, type, reason, destination, coming_from, requested_out_at, expected_return_at, status)
             VALUES (:std_no, :type, :reason, :destination, :coming_from, :requested_out_at, :expected_return_at, "pending")'
        );
        $stmt->execute([
            'std_no'             => $stdNo,
            'type'               => $type,
            'reason'             => $reason,
            'destination'        => $destination,
            'coming_from'        => $comingFrom,
            'requested_out_at'   => $requestedOutAt,
            'expected_return_at' => $expectedReturnAt,
        ]);
        $requestId = (int) $pdo->lastInsertId();

        log_outing_event($pdo, $requestId, 'submitted', 'student', $stdNo);

        create_notification(
            $stdNo,
            'request_submitted',
            'Your outing application has been submitted. CRS will review your application soon.',
            'outing_request',
            $requestId
        );

        $pdo->commit();
        return $requestId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function get_student_requests(string $stdNo): array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM outing_request WHERE std_no = :std_no ORDER BY created_at DESC'
    );
    $stmt->execute(['std_no' => $stdNo]);
    return $stmt->fetchAll();
}

/** Fetches a request only if it belongs to this student -- prevents IDOR. */
function get_request_for_student(int $requestId, string $stdNo): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM outing_request WHERE id = :id AND std_no = :std_no LIMIT 1'
    );
    $stmt->execute(['id' => $requestId, 'std_no' => $stdNo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Returns false (not an exception) if the request is missing, not the
 *  student's own, or no longer pending -- callers turn that into a
 *  friendly flash message rather than a crash. The request's type is
 *  fixed at creation and cannot be changed by an edit. */
function update_pending_request(
    int $requestId,
    string $stdNo,
    string $type,
    string $reason,
    string $destination,
    ?string $requestedOutAt,
    ?string $expectedReturnAt
): bool {
    validate_request_type_and_time($type, $requestedOutAt, $expectedReturnAt);

    $requestedOutAt   = $type === 'checkout' ? $requestedOutAt : null;
    $expectedReturnAt = $type === 'checkin' ? $expectedReturnAt : null;

    $comingFrom  = $type === 'checkin' ? $destination : null;
    $destination = $type === 'checkout' ? $destination : null;

    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE outing_request
             SET reason = :reason,
                 destination = :destination,
                 coming_from = :coming_from,
                 requested_out_at = :requested_out_at,
                 expected_return_at = :expected_return_at
             WHERE id = :id AND std_no = :std_no AND type = :type AND status = "pending"'
        );
        $stmt->execute([
            'reason'             => $reason,
            'destination'        => $destination,
            'coming_from'        => $comingFrom,
            'requested_out_at'   => $requestedOutAt,
            'expected_return_at' => $expectedReturnAt,
            'id'                 => $requestId,
            'std_no'             => $stdNo,
            'type'               => $type,
        ]);
        $updated = $stmt->rowCount() > 0;

        if ($updated) {
            log_outing_event($pdo, $requestId, 'submitted', 'student', $stdNo, 'Edited by student');
        }
        $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function cancel_request_by_student(int $requestId, string $stdNo): bool
{
    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE outing_request SET status = "cancelled"
             WHERE id = :id AND std_no = :std_no AND status = "pending"'
        );
        $stmt->execute(['id' => $requestId, 'std_no' => $stdNo]);
        $cancelled = $stmt->rowCount() > 0;

        if ($cancelled) {
            log_outing_event($pdo, $requestId, 'cancelled', 'student', $stdNo);
        }
        $pdo->commit();
        return $cancelled;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// --- Staff actions ----------------------------------------------------

function get_pending_requests(): array
{
    $pdo  = get_db();
    $stmt = $pdo->query(
        'SELECT r.*, s.std_name, s.email AS student_email
         FROM outing_request r
         JOIN student s ON s.std_no = r.std_no
         WHERE r.status = "pending"
         ORDER BY r.created_at ASC'
    );
    return $stmt->fetchAll();
}

/**
 * $statusIn optionally restricts the result to a fixed set of statuses
 * regardless of $statusFilter -- used to split the single request table
 * into two pages (outing_history.php's "special" view for the
 * executed/gate statuses, request_status.php for the approval-workflow
 * statuses) without either page being able to see the other's rows.
 */
function get_all_requests(?string $statusFilter = null, ?string $typeFilter = null, ?string $from = null, ?string $to = null, ?array $statusIn = null): array
{
    $pdo    = get_db();
    $params = [];
    $where  = ' WHERE 1=1';

    if ($statusIn !== null && $statusIn !== []) {
        $placeholders = [];
        foreach (array_values($statusIn) as $i => $s) {
            $key = "status_in_{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $s;
        }
        $where .= ' AND r.status IN (' . implode(',', $placeholders) . ')';
    }
    if ($statusFilter !== null && $statusFilter !== '') {
        $where .= ' AND r.status = :status';
        $params['status'] = $statusFilter;
    }
    if ($typeFilter !== null && $typeFilter !== '') {
        $where .= ' AND r.type = :type';
        $params['type'] = $typeFilter;
    }
    $where .= date_range_sql('r.created_at', $from, $to, $params);

    $stmt = $pdo->prepare(
        'SELECT r.*, s.std_name, s.email AS student_email, gl.group_id, gs.std_name AS group_lead_name
         FROM outing_request r
         JOIN student s ON s.std_no = r.std_no
         LEFT JOIN outing_group_link gl ON gl.kind = "special" AND gl.ref_id = r.id
         LEFT JOIN outing_group og ON og.id = gl.group_id
         LEFT JOIN student gs ON gs.std_no = og.lead_std_no' . $where . '
         ORDER BY r.created_at DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_request_with_student(int $requestId): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT r.*, s.std_name, s.email AS student_email
         FROM outing_request r
         JOIN student s ON s.std_no = r.std_no
         WHERE r.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $requestId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Approves or rejects a pending request. Returns the updated request
 * (joined with student info, for the email notification) on success,
 * or null if there was nothing to review (already decided, or the id
 * doesn't exist) -- this covers two staff double-clicking Approve on
 * the same request at once, since the WHERE status = "pending" guard
 * makes the second UPDATE affect zero rows.
 */
function review_request(int $requestId, string $staffId, string $decision, ?string $rejectionReason): ?array
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid decision.');
    }
    if ($decision === 'rejected' && trim((string) $rejectionReason) === '') {
        throw new InvalidArgumentException('A rejection reason is required.');
    }

    // Fair rule: an arrival request whose deadline already passed cannot
    // be decided any more. Expire it now (in case the scheduled job
    // hasn't run yet) so a late click can never approve it.
    expire_unreviewed_requests($requestId);

    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE outing_request
             SET status = :status,
                 reviewed_by = :reviewed_by,
                 reviewed_at = NOW(),
                 rejection_reason = :rejection_reason
             WHERE id = :id AND status = "pending"
               AND NOT (type = "checkin" AND ' . arrival_review_deadline_sql() . ' < NOW())'
        );
        $stmt->execute([
            'status'            => $decision,
            'reviewed_by'       => $staffId,
            'rejection_reason'  => $decision === 'rejected' ? $rejectionReason : null,
            'id'                => $requestId,
            'lead_a'            => arrival_review_lead_minutes(),
            'lead_b'            => arrival_review_lead_minutes(),
        ]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            $cur = get_request_with_student($requestId);
            if ($cur && $cur['status'] === 'expired') {
                throw new InvalidArgumentException(
                    'This arrival request expired because its review deadline passed before it was decided. It can no longer be approved or rejected.'
                );
            }
            return null;
        }

        log_outing_event($pdo, $requestId, $decision, 'staff', $staffId, $rejectionReason);

        $updated = get_request_with_student($requestId);
        create_notification(
            $updated['std_no'],
            $decision === 'approved' ? 'request_approved' : 'request_rejected',
            $decision === 'approved'
                ? 'Your outing request has been approved.'
                : 'Your outing request has been rejected.',
            'outing_request',
            $requestId
        );

        $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// --- Check-in ("arriving during curfew") approvals -------------------
//
// FAIR RULE for arrival requests staff never reviewed in time:
//   - staff should decide BEFORE the student arrives: the deadline is
//     expected_return_at minus the "arrival review lead" setting (default
//     30 min), see arrival_review_deadline_sql(). If it passes while the
//     request is still pending, it becomes "expired" with
//     unreviewed_by_staff = 1 and can no longer be approved/rejected
//     (expire_unreviewed_requests());
//   - the student is not penalised for staff inaction: a pending or
//     expired request still covers their arrival until the curfew window
//     it was made for ends (unreviewed_cover_until());
//   - such an arrival is never a violation (see reports.php) and the gate
//     shows "request not reviewed" instead of "late".
// A request staff DID approve in time is unchanged: miss the time and
// it goes overdue, which is the student's own fault.

/**
 * Review deadline for an arrival (checkin) request: expected arrival minus
 * the lead setting, but never earlier than lead minutes after it was
 * submitted (so a request made right before arrival still gets a window).
 * SQL form -- binds :lead_a and :lead_b (both = arrival_review_lead_minutes()).
 */
function arrival_review_deadline_sql(): string
{
    return 'GREATEST(DATE_SUB(expected_return_at, INTERVAL :lead_a MINUTE),
                     DATE_ADD(created_at, INTERVAL :lead_b MINUTE))';
}

/** Same deadline as a unix timestamp, for a request row (needs expected_return_at, created_at). */
function arrival_review_deadline_ts(array $request): int
{
    $lead = arrival_review_lead_minutes() * 60;
    return max(strtotime($request['expected_return_at']) - $lead, strtotime($request['created_at']) + $lead);
}

/** Unix time until which an UNREVIEWED arrival request still covers the student:
 *  the end of the curfew window that contains the expected arrival time. */
function unreviewed_cover_until(string $expectedReturnAt): int
{
    $t   = strtotime($expectedReturnAt);
    $end = strtotime(date('Y-m-d', $t) . ' ' . curfew_end());
    if ($end <= $t) {
        $end = strtotime('+1 day', $end);
    }
    return $end;
}

/** True for a checkin request staff never reviewed (still pending, or expired). */
function is_unreviewed_arrival(array $request): bool
{
    return ($request['type'] ?? '') === 'checkin'
        && in_array($request['status'] ?? '', ['pending', 'expired'], true);
}

/**
 * Pending checkin requests past their review deadline (see
 * arrival_review_deadline_sql()) become "expired" (unreviewed_by_staff = 1). Pass an id to check one request.
 * Safe to call often; each row flips once. Returns how many expired.
 */
function expire_unreviewed_requests(?int $onlyId = null): int
{
    $pdo    = get_db();
    $params = ['lead_a' => arrival_review_lead_minutes(), 'lead_b' => arrival_review_lead_minutes()];
    $sql    = 'SELECT id, std_no FROM outing_request
               WHERE type = "checkin" AND status = "pending"
                 AND ' . arrival_review_deadline_sql() . ' < NOW()';
    if ($onlyId !== null) {
        $sql .= ' AND id = :id';
        $params['id'] = $onlyId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $count = 0;
    foreach ($rows as $row) {
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare(
                'UPDATE outing_request SET status = "expired", unreviewed_by_staff = 1
                 WHERE id = :id AND type = "checkin" AND status = "pending"'
            );
            $upd->execute(['id' => $row['id']]);
            if ($upd->rowCount() > 0) {
                log_outing_event($pdo, (int) $row['id'], 'expired', 'system', null, 'Not reviewed before the review deadline');
                create_notification(
                    $row['std_no'],
                    'request_expired',
                    'Your arrival request was not reviewed in time. If you arrive during curfew, it will not be counted as a late return.',
                    'outing_request',
                    (int) $row['id']
                );
                $count++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    return $count;
}

/**
 * The checkin-type request this student could be arriving under right now:
 * a staff-approved one first (approved/overdue), otherwise an unreviewed
 * one (pending/expired) that still covers this curfew window (only
 * considered while curfew is active). Soonest
 * expected arrival first within each group. Check is_unreviewed_arrival()
 * on the result to tell them apart.
 */
function find_active_checkin_approval(string $stdNo): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM outing_request
         WHERE std_no = :std_no AND type = "checkin"
           AND status IN ("approved", "overdue", "pending", "expired")
         ORDER BY expected_return_at ASC'
    );
    $stmt->execute(['std_no' => $stdNo]);

    $unreviewed = null;
    foreach ($stmt->fetchAll() as $row) {
        if (!is_unreviewed_arrival($row)) {
            return $row; // staff-approved wins
        }
        if ($unreviewed === null && is_within_curfew() && time() <= unreviewed_cover_until($row['expected_return_at'])) {
            $unreviewed = $row;
        }
    }
    return $unreviewed;
}

/**
 * Marks a checkin-type request as fulfilled -- the student showed up.
 * Works for approved/overdue, and for unreviewed (pending/expired) ones,
 * only while the curfew window the request was for is still open; those are flagged
 * unreviewed_by_staff = 1 so they never count as a violation. Returns
 * false (not an exception) if it's no longer fulfillable, e.g. two
 * guards processing the same student at once.
 *
 * No transaction of its own -- call from inside another write's
 * transaction, or use fulfill_checkin_approval_standalone().
 */
function fulfill_checkin_approval(PDO $pdo, int $requestId, string $stdNo, string $guardId, string $gateLocation): bool
{
    $sel = $pdo->prepare('SELECT * FROM outing_request WHERE id = :id AND std_no = :std_no AND type = "checkin"');
    $sel->execute(['id' => $requestId, 'std_no' => $stdNo]);
    $row = $sel->fetch();
    if (!$row) {
        return false;
    }
    $unreviewed = is_unreviewed_arrival($row);
    if ($unreviewed && time() > unreviewed_cover_until($row['expected_return_at'])) {
        return false; // unreviewed request only covers the curfew window it was made for
    }

    $stmt = $pdo->prepare(
        'UPDATE outing_request
         SET status = "checked_in", actual_return_at = NOW(),
             unreviewed_by_staff = :unreviewed
         WHERE id = :id AND std_no = :std_no AND type = "checkin"
           AND status IN ("approved", "overdue", "pending", "expired")'
    );
    $stmt->execute(['unreviewed' => $unreviewed ? 1 : 0, 'id' => $requestId, 'std_no' => $stdNo]);
    $ok = $stmt->rowCount() > 0;

    if ($ok) {
        log_outing_event(
            $pdo, $requestId, 'checked_in', 'staff', $guardId,
            "Gate: {$gateLocation}" . ($unreviewed ? ' (arrival request was not reviewed by staff)' : '')
        );
        if ($unreviewed) {
            create_notification(
                $stdNo,
                'arrival_unreviewed',
                'Your arrival was recorded. Your request was not reviewed in time, so no late return was recorded.',
                'outing_request',
                $requestId
            );
        }
    }
    return $ok;
}

/** Standalone version for a bare call with no surrounding transaction. */
function fulfill_checkin_approval_standalone(int $requestId, string $stdNo, string $guardId, string $gateLocation): bool
{
    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $ok = fulfill_checkin_approval($pdo, $requestId, $stdNo, $guardId, $gateLocation);
        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
