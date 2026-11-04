<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupFormRequest;
use App\Services\GroupService;
use App\Services\QrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    public function group(GroupFormRequest $r, GroupService $groups)
    {
        $groups->act($r->user(), $r->validated());

        return redirect()->route('my_group')->with('flash', ['type' => 'success', 'message' => 'Group updated.']);
    }

    public function qr(Request $r, QrService $qr)
    {
        $r->validate(['kind' => 'nullable|in:S,G']);

        return response()->json($qr->issue($r->user(), $r->query('kind', 'S')));
    }

    public function notifications(Request $r)
    {
        $q = DB::table('notification')->where('std_no', $r->user()->std_no);
        if ($r->routeIs('get_unread_count')) {
            return response()->json(['count' => $q->where('is_read', 0)->count()]);
        }

return response()->json($q->orderByDesc('id')->limit(100)->get()->map(fn ($n) => (array) $n + ['created_at_label' => fmt_dt($n->created_at)]));
    }

    public function notificationAction(Request $r)
    {
        $d = $r->validate(['action' => 'required|in:mark_read,mark_all_read,mark_read_many,clear_all,delete', 'id' => 'nullable|integer|min:1', 'ids' => 'nullable|string|max:2000']);
        $q = DB::table('notification')->where('std_no', $r->user()->std_no);
        if (in_array($d['action'], ['delete', 'mark_read'])) {
            $r->validate(['id' => 'required|integer|min:1']);
            $q->where('id', $d['id']);
        }
        if ($d['action'] === 'mark_read_many') {
            $q->whereIn('id', array_filter(array_map('intval', explode(',', $d['ids'] ?? ''))));
        }
        in_array($d['action'], ['clear_all', 'delete']) ? $q->delete() : $q->update(['is_read' => 1]);

        return response()->json(['success' => true]);
    }
}
