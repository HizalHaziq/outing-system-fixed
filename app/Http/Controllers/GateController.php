<?php

namespace App\Http\Controllers;

use App\Http\Requests\GateFormRequest;
use App\Services\QrService;
use Illuminate\Http\Request;

class GateController extends Controller
{
    public function scan(Request $r, QrService $qr)
    {
        $r->validate(['qr_token' => 'required|string|max:120']);
        $scan = $qr->scan($r->qr_token, $r->user(), $r->session()->getId());
        $r->session()->put('gate_grants.'.$scan['subject'], $scan['grant']);
        $group = str_starts_with($scan['subject'], 'G:');

        return redirect()->route($group ? 'gate_group' : 'gate', [$group ? 'group_id' : 'std_no' => substr($scan['subject'], 2)]);
    }

    public function action(GateFormRequest $r, QrService $qr)
    {
        $result = $qr->perform($r->verification_id, $r->user(), $r->session()->getId(), $r->validated());
        $message = is_array($result) ? count($result['done']).' processed, '.count($result['skipped']).' skipped. '.implode('; ', $result['skipped']).($result['closed'] ? ' Everyone is back -- group closed.' : '') : $result;

        return redirect()->route('gate')->with('flash', ['type' => 'success', 'message' => $message]);
    }
}
