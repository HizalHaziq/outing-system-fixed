<?php

namespace App\Http\Controllers;

use App\Http\Requests\OutingFormRequest;
use App\Http\Requests\ReviewFormRequest;
use App\Models\OutingRequest;
use App\Services\RequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RequestController extends Controller
{
    public function save(OutingFormRequest $r, RequestService $service)
    {
        $id = $r->filled('id') ? (int) $r->id : null;
        if ($id) {
            Gate::authorize('update', OutingRequest::findOrFail($id));
        }
        $service->save($r->user(), $r->validated(), $id);

        return redirect()->route('my_requests')->with('flash', ['type' => 'success', 'message' => $id ? 'Outing request updated.' : 'Outing request submitted.']);
    }

    public function cancel(Request $r, RequestService $service)
    {
        $data = $r->validate(['id' => 'required|integer|min:1']);
        Gate::authorize('update', OutingRequest::findOrFail($data['id']));
        $service->cancel($r->user(), $data['id']);

        return redirect()->route('my_requests')->with('flash', ['type' => 'success', 'message' => 'Outing request cancelled.']);
    }

    public function review(ReviewFormRequest $r, RequestService $service)
    {
        Gate::authorize('review', OutingRequest::class);
        $service->review($r->user(), (int) $r->request_id, $r->decision, $r->rejection_reason);

        return redirect()->route('pending_requests')->with('flash', ['type' => 'success', 'message' => 'Request '.$r->decision.'.']);
    }
}
