<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(string $token): View
    {
        $invitation = $this->invitation($token);
        abort_if(User::query()->where('email', $invitation->email)->exists(), 404);

        return view('auth.register-invitation', compact('invitation', 'token'));
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitation($token);
        abort_if(User::query()->where('email', $invitation->email)->exists(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'min:12'],
        ]);
        $user = DB::transaction(function () use ($invitation, $token, $data): User {
            Family::query()->whereKey($invitation->family_id)->lockForUpdate()->firstOrFail();
            $current = FamilyInvitation::query()->with('family')->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($current->isAcceptable() && hash_equals($current->token_hash, hash('sha256', $token)), 410);
            abort_if(User::query()->where('email', $current->email)->exists(), 404);

            return User::query()->create(['name' => $data['name'], 'email' => $current->email, 'password' => Hash::make($data['password'])]);
        });
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('invitations.show', $token);
    }

    private function invitation(string $token): FamilyInvitation
    {
        $invitation = FamilyInvitation::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($invitation->isAcceptable(), 410);

        return $invitation;
    }
}
