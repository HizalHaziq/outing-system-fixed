<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsFormRequest;
use App\Models\Staff;
use App\Services\ImportService;
use App\Services\Notifier;
use App\Services\PhotoService;
use App\Services\RequestService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function settings(SettingsFormRequest $r, Settings $settings)
    {
        $settings->save($r->validated(), $r->user()->staff_id);

        return redirect()->route('manage_settings')->with('flash', ['type' => 'success', 'message' => 'Settings updated.']);
    }

    public function import(Request $r, ImportService $service, PageController $pages)
    {
        $r->validate(['csv_file' => 'required|file|max:10240|extensions:csv']);
        try {
            $result = $service->import($r->file('csv_file')->getRealPath());
        } catch (\RuntimeException $e) {
            RequestService::fail($e->getMessage());
        }

return $pages->show($r, ['result' => $result]);
    }

    public function photos(Request $r, PhotoService $photos, PageController $pages)
    {
        $r->validate(['photos' => 'required|array|max:50', 'photos.*' => 'required|file|image|mimes:jpeg,png,webp|max:5120']);
        $results = [];
        foreach ($r->file('photos') as $file) {
            $name = $file->getClientOriginalName();
            $std = pathinfo($name, PATHINFO_FILENAME);
            if (! DB::table('student')->where('std_no', $std)->exists()) {
                $results[] = [$name, false, 'No student with this number.'];

                continue;
            }$photos->save($std, $file);
            $results[] = [$name, true, 'Saved'];
        }

        return $pages->show($r, compact('results'));
    }

    public function staff(Request $r, Notifier $notify, PageController $pages)
    {
        $d = $r->validate(['form_action' => 'required|in:create,reset_password,toggle_active', 'staff_id' => ['required', 'regex:/^[A-Za-z0-9_-]{1,20}$/D'], 'name' => 'required_if:form_action,create|nullable|string|max:100', 'role' => 'required_if:form_action,create|nullable|in:guard,warden,admin', 'email' => 'nullable|email|max:100']);
        $password = null;
        DB::transaction(function () use ($r, $d, $notify, &$password) {
            if ($d['form_action'] === 'create') {
                $r->validate(['staff_id' => 'unique:staff,staff_id']);
                $password = Str::password(16);
                Staff::create(['staff_id' => $d['staff_id'], 'name' => $d['name'], 'role' => $d['role'], 'email' => $d['email'] ?? null, 'password_hash' => Hash::make($password), 'must_change_password' => 1, 'is_active' => 1, 'created_at' => now()]);
            } else {
                $s = Staff::where('staff_id', $d['staff_id'])->lockForUpdate()->firstOrFail();
                if ($d['form_action'] === 'toggle_active') {
                    if ($s->id === $r->user()->id) {
                        RequestService::fail('You cannot deactivate your own account.');
                    }$s->update(['is_active' => ! $s->is_active]);
                } else {
                    $password = Str::password(16);
                    $s->update(['password_hash' => Hash::make($password), 'must_change_password' => 1]);
                    if ($s->email) {
                        $notify->mail('staff-reset:'.$s->id.':'.hash('sha256', $s->password_hash), $s->email, 'Your Outing System password has been reset', 'An administrator reset your password. Collect the temporary password from your administrator and change it at your next login.');
                    }
                }
            }
        }, 3);

        return $pages->show($r, ['generatedPassword' => $password, 'passwordForStaffId' => $d['staff_id']]);
    }

    public function photo(Request $r, PhotoService $photos)
    {
        $d = $r->validate(['std_no' => ['required', 'regex:/^[A-Za-z0-9_-]{1,20}$/D']]);
        abort_unless($r->user()->roleName() !== 'student' || $r->user()->std_no === $d['std_no'], 403);
        $path = $photos->path($d['std_no']);
        abort_unless($path, 404);

        return response()->file($path,['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
