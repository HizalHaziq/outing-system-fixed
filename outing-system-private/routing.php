<?php
// Time-based routing. ONE place decides which track a student is on
// right now, using SERVER time only (curfew.php / Asia/Kuala_Lumpur),
// never anything the student or the guard's device supplies:
//
//   curfew inactive -> standard track (guard checks out/in directly)
//   curfew active   -> special track  (needs an approved request; no
//                                      approval = hard block, no override)
//
// Individual gate lookups and group batches both go through here, so a
// group can never do something a member couldn't do alone.

declare(strict_types=1);
require_once __DIR__ . '/gate_ops.php';
require_once __DIR__ . '/standard_ops.php';
require_once __DIR__ . '/curfew.php';

/** 'standard' or 'special' for the current server time. */
function current_track(): string
{
    return is_within_curfew() ? 'special' : 'standard';
}

/**
 * Everything the gate needs to know about one student right now.
 * state: not_found | standard_out | special_out | special_ready |
 *        arrival_only | blocked_curfew | standard_ready
 */
function resolve_gate_route(string $stdNo): array
{
    $route = [
        'std_no' => $stdNo, 'student' => null, 'curfew' => is_within_curfew(),
        'state' => 'not_found', 'track' => null, 'direction' => null,
        'standard_open' => null, 'special_active' => null,
        'special_ready' => null, 'arrival_approval' => null,
    ];

    $student = find_student_for_gate($stdNo);
    if (!$student) {
        return $route;
    }
    $route['student']          = $student;
    $route['arrival_approval'] = find_active_checkin_approval($stdNo);
    $route['standard_open']    = get_open_standard_outing($stdNo);

    if ($route['standard_open']) {
        return ['state' => 'standard_out', 'track' => 'standard', 'direction' => 'in'] + $route;
    }
    $route['special_active'] = get_active_checkout($stdNo);
    if ($route['special_active']) {
        return ['state' => 'special_out', 'track' => 'special', 'direction' => 'in'] + $route;
    }
    $route['special_ready'] = get_checkoutable_request($stdNo);
    if ($route['special_ready']) {
        return ['state' => 'special_ready', 'track' => 'special', 'direction' => 'out'] + $route;
    }
    // No open record = the system has them ON campus, so there is nothing to
    // check in -- an arrival request never turns this into a check-in.
    if ($route['curfew']) {
        return ['state' => 'blocked_curfew', 'track' => 'special', 'direction' => null] + $route;
    }
    return ['state' => 'standard_ready', 'track' => 'standard', 'direction' => 'out'] + $route;
}

/**
 * Group outings are STANDARD TRACK ONLY. A group member who has any
 * special-track request (approved/checked out/overdue checkout, or an
 * approved arrival) is left out of the batch and must be processed on
 * their own at the gate -- the group never touches outing_request.
 * During curfew a group can't leave at all (no approval path for it);
 * a member who is already out on a standard outing can still check in.
 * state: not_found | standard_out | standard_ready | special_excluded | blocked_curfew
 */
function resolve_group_route(string $stdNo): array
{
    $route = [
        'std_no' => $stdNo, 'student' => null, 'curfew' => is_within_curfew(),
        'state' => 'not_found', 'track' => null, 'direction' => null,
        'standard_open' => null, 'special_active' => null,
        'special_ready' => null, 'arrival_approval' => null,
    ];
    $student = find_student_for_gate($stdNo);
    if (!$student) {
        return $route;
    }
    $route['student']       = $student;
    $route['standard_open']    = get_open_standard_outing($stdNo);
    $route['arrival_approval'] = find_active_checkin_approval($stdNo);
    if ($route['standard_open']) {
        return ['state' => 'standard_out', 'track' => 'standard', 'direction' => 'in'] + $route;
    }
    $route['special_active'] = get_active_checkout($stdNo);
    $route['special_ready']  = get_checkoutable_request($stdNo);
    if ($route['special_active'] || $route['special_ready']) {
        return ['state' => 'special_excluded', 'track' => 'special', 'direction' => null] + $route;
    }
    if ($route['curfew']) {
        return ['state' => 'blocked_curfew', 'track' => 'standard', 'direction' => null] + $route;
    }
    return ['state' => 'standard_ready', 'track' => 'standard', 'direction' => 'out'] + $route;
}

function route_is_actionable(array $route): bool
{
    return in_array($route['state'], ['standard_out', 'special_out', 'special_ready', 'arrival_only', 'standard_ready'], true);
}

/** Short guard-facing description of what will happen for this route. */
function route_action_label(array $route): string
{
    return match ($route['state']) {
        'standard_ready' => 'Check out (standard)',
        'standard_out'   => !empty($route['arrival_approval'])
            ? 'Check in (standard outing, using arrival permission)'
            : 'Check in (standard)',
        'special_ready'  => 'Check out (approved request)',
        'special_out'    => 'Check in (special)',
        'arrival_only'   => 'Check in (pre-cleared arrival)',
        'blocked_curfew' => 'Blocked: curfew, no approved request',
        'special_excluded' => 'Has a special request -- process individually (not part of group)',
        default          => 'Not found',
    };
}

/** Performs whatever the route calls for. @return array{ok:bool,message:string} */
function execute_gate_route(array $route, string $guardId, string $gateLocation): array
{
    $stdNo = $route['std_no'];
    try {
        $ok = match ($route['state']) {
            'standard_out'   => standard_check_in((int) $route['standard_open']['id'], $stdNo, $guardId, $gateLocation),
            'special_out'    => gate_check_in((int) $route['special_active']['id'], $stdNo, $guardId, $gateLocation),
            'special_ready'  => gate_check_out((int) $route['special_ready']['id'], $stdNo, $guardId, $gateLocation),
            'arrival_only'   => fulfill_checkin_approval_standalone((int) $route['arrival_approval']['id'], $stdNo, $guardId, $gateLocation),
            'standard_ready' => create_standard_checkout($stdNo, $guardId, $gateLocation) > 0,
            'blocked_curfew' => false,
            default          => false,
        };
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    if ($route['state'] === 'blocked_curfew') {
        return ['ok' => false, 'message' => 'Curfew is active and no approved request.'];
    }
    return $ok
        ? ['ok' => true,  'message' => route_action_label($route)]
        : ['ok' => false, 'message' => 'State changed while processing (already handled?).'];
}
