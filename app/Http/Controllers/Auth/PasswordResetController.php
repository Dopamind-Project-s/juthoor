<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => 'طلبت رابطًا قبل قليل؛ انتظر دقيقة ثم أعد المحاولة.']);
        }

        return back()->with('status', 'إذا كان البريد مسجلًا لدينا فسيصلك رابط استعادة كلمة المرور.');
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:12'],
        ]);
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'رابط الاستعادة غير صالح أو منتهي.']);
        }

        return redirect()->route('login')->with('status', 'تم تحديث كلمة المرور.');
    }
}
