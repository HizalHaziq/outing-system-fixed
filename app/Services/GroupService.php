<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

class GroupService
{
    const MAX_MEMBERS = 4;

    public function __construct(private Notifier $notify) {}

    public function query()
    {
        return DB::table('outing_group as g')->join('student as s', 's.std_no', '=', 'g.lead_std_no')->select('g.*', 's.std_name as lead_name', 's.is_active as lead_active')->where('g.status', 'active')->where(function ($q) {
            $q->where('g.expires_at', '>', now())->orWhereExists(function ($q) {
                $q->selectRaw('1')->from('outing_group_log as l')->whereColumn('l.group_id', 'g.id')->whereNotNull('l.out_at')->whereNull('l.in_at');
            });
        });
    }

    public function find(int $id): ?array
    {
        $r = $this->query()->where('g.id', $id)->first();

        return $r ? (array) $r : null;
    }

    public function forStudent(string $std): ?array
    {
        $r = $this->query()->where(function ($q) use ($std) {
            $q->where('g.lead_std_no', $std)->orWhereExists(function ($q) use ($std) {
                $q->selectRaw('1')->from('outing_group_member as m')->whereColumn('m.group_id', 'g.id')->where('m.std_no', $std)->where('m.status', 'accepted');
            });
        })->orderByDesc('g.id')->first();

        return $r ? (array) $r : null;
    }

    public function members(int $id): array
    {
        return DB::table('outing_group_member as m')->join('student as s', 's.std_no', '=', 'm.std_no')->where('m.group_id', $id)->whereIn('m.status', ['invited', 'accepted'])->select('m.*', 's.std_name', 's.is_active')->orderBy('m.id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function participants(int $id): array
    {
        $g = $this->find($id);
        if (! $g) {
            return [];
        }
        $lead = DB::table('student')->select('std_no', 'std_name', 'is_active', 'program', 'phone')->where('std_no', $g['lead_std_no'])->first();
        $rows = [(array) $lead + ['role' => 'Lead']];
        foreach ($this->members($id) as $m) {
            if ($m['status'] === 'accepted') {
                $rows[] = $m + ['role' => 'Member'];
            }
        }

return $rows;
    }

    public function invitations(string $std): array
    {
        return DB::table('outing_group_member as m')->join('outing_group as g', 'g.id', '=', 'm.group_id')->join('student as s', 's.std_no', '=', 'g.lead_std_no')->where('m.std_no', $std)->where('m.status', 'invited')->where('g.status', 'active')->where('g.expires_at', '>', now())->select('m.id as member_row_id', 'g.id as group_id', 's.std_name as lead_name', 'g.lead_std_no')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function hasSpecial(string $std): bool
    {
        return DB::table('outing_request')->where('std_no', $std)->whereIn('status', ['approved', 'checked_out', 'overdue'])->exists();
    }

    public function hasOutstanding(int $id, ?string $std = null): bool
    {
        $q = DB::table('outing_group_log')->where('group_id', $id)->whereNotNull('out_at')->whereNull('in_at');
        if ($std) {
            $q->where('std_no', $std);
        }

return $q->exists();
    }

    public function act(Student $user, array $data): void
    {
        $action = $data['action'];
        $id = (int) ($data['group_id'] ?? 0);
        if (in_array($action, ['accept', 'decline'])) {
            $id = (int) DB::table('outing_group_member')->where('id', $data['member_row_id'])->where('std_no', $user->std_no)->value('group_id');
        }
        DB::transaction(function () use ($user, $data, $action, $id) {
            $g = $id ? DB::table('outing_group')->where('id', $id)->lockForUpdate()->first() : null;
            $stds = [$user->std_no];
            if (! empty($data['member_std_no'])) {
                $stds[] = $data['member_std_no'];
            }sort($stds);
            $students = Student::whereIn('std_no', $stds)->orderBy('std_no')->lockForUpdate()->get()->keyBy('std_no');
            $s = $students[$user->std_no];
            if (! $s->is_active) {
                RequestService::fail('Account inactive.');
            }
            if ($action === 'create') {
                if ($this->forStudent($s->std_no)) {
                    RequestService::fail('You are already in an active group.');
                }
                if ($this->hasSpecial($s->std_no) || app(RequestService::class)->isOut($s->std_no)) {
                    RequestService::fail('Start a standard group while on campus without a live special request.');
                }
                DB::table('outing_group')->insert(['lead_std_no' => $s->std_no, 'expires_at' => now()->addHours(24), 'created_at' => now()]);

                return;
            }
            if (! $g || $g->status !== 'active') {
                RequestService::fail('Group is no longer active.');
            }
            if (in_array($action, ['invite', 'remove', 'close']) && $g->lead_std_no !== $s->std_no) {
                abort(403);
            }
            $hasOut = $this->hasOutstanding($id);
            if (in_array($action, ['invite', 'accept']) && (now()->gte($g->expires_at) || DB::table('outing_group_log')->where('group_id', $id)->exists())) {
                RequestService::fail('Membership is fixed once a group departs or expires.');
            }
            if ($action === 'close') {
                if ($hasOut) {
                    RequestService::fail('The group cannot close while any departed student is still out.');
                }
                DB::table('outing_group')->where('id', $id)->update(['status' => 'closed']);

                return;
            }
            if ($action === 'invite') {
                $target = $data['member_std_no'];
                $member = $students[$target] ?? null;
                if (! $member || ! $member->is_active || $target === $s->std_no) {
                    RequestService::fail('Choose another active student.');
                }
                if (count($this->members($id)) >= self::MAX_MEMBERS) {
                    RequestService::fail('A group can have at most four other students.');
                }
                if ($this->forStudent($target) || $this->hasSpecial($target) || app(RequestService::class)->isOut($target)) {
                    RequestService::fail('Student is already in a group, out, or has a live special request.');
                }
                if (DB::table('outing_group_member')->where('group_id', $id)->where('std_no', $target)->whereIn('status', ['invited', 'accepted'])->exists()) {
                    RequestService::fail('Already invited.');
                }
                $mid = DB::table('outing_group_member')->insertGetId(['group_id' => $id, 'std_no' => $target, 'status' => 'invited', 'created_at' => now()]);
                $this->notify->student($target, 'group_invite', $s->std_name.' invited you to a group outing. Open "My group" to respond.', 'outing_group_member', $mid);

                return;
            }
            if (in_array($action, ['accept', 'decline'])) {
                $m = DB::table('outing_group_member')->where('id', $data['member_row_id'])->where('group_id', $id)->where('std_no', $s->std_no)->where('status', 'invited')->first();
                if (! $m) {
                    RequestService::fail('Invitation no longer available.');
                }
                if ($action === 'accept' && ($this->forStudent($s->std_no) || $this->hasSpecial($s->std_no) || app(RequestService::class)->isOut($s->std_no))) {
                    RequestService::fail('Already in a group, out, or has a live special request.');
                }
                DB::table('outing_group_member')->where('id', $m->id)->update(['status' => $action === 'accept' ? 'accepted' : 'declined', 'responded_at' => now()]);
                $this->notify->student($g->lead_std_no, 'group_response', $s->std_name.($action === 'accept' ? ' accepted' : ' declined').' your group invitation.', 'outing_group_member', $m->id);

                return;
            }
            $target = $action === 'remove' ? $data['member_std_no'] : $s->std_no;
            if ($this->hasOutstanding($id, $target)) {
                RequestService::fail('A departed member must return before leaving or being removed.');
            }
            $changed = DB::table('outing_group_member')->where('group_id', $id)->where('std_no', $target)->whereIn('status', $action === 'leave' ? ['accepted'] : ['invited', 'accepted'])->update(['status' => $action === 'leave' ? 'left' : 'removed', 'responded_at' => now()]);
            if (! $changed) {
                RequestService::fail('Membership no longer available.');
            }$this->maybeClose($id);
        }, 3);
    }

    public function movement(string $std, string $direction, int $trip, ?int $groupId = null): void
    {
        $id = $groupId;
        if (! $id && $direction === 'in') {
            $id = DB::table('outing_group_link')->where('kind', 'standard')->where('ref_id', $trip)->value('group_id');
        }
        if (! $id) {
            $id = $this->forStudent($std)['id'] ?? null;
        }if (! $id) {
            return;
        }
        if ($direction === 'out') {
            DB::table('outing_group_log')->updateOrInsert(['group_id' => $id, 'std_no' => $std], ['out_at' => now(), 'in_at' => null]);
            DB::table('outing_group_link')->insertOrIgnore(['kind' => 'standard', 'ref_id' => $trip, 'group_id' => $id]);
        } else {
            DB::table('outing_group_log')->where('group_id', $id)->where('std_no', $std)->whereNull('in_at')->update(['in_at' => now()]);
            $this->maybeClose($id);
        }
    }

    public function maybeClose(int $id): bool
    {
        if (! DB::table('outing_group_log')->where('group_id',$id)->whereNotNull('out_at')->exists() || $this->hasOutstanding($id)) {
            return false;
        }

        return (bool) DB::table('outing_group')->where('id',$id)->where('status','active')->update(['status' => 'closed']);
    }
}
