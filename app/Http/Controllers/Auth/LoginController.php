<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SsoSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function showLoginForm(Request $request): View
    {
        // Passport throws AuthenticationException => redirect intended. Tapi
        // kita juga dukung param ?redirect= eksplisit.
        $redirect = $request->input('redirect');

        if ($redirect) {
            $request->session()->put('url.intended', $redirect);
        }

        return view('auth.login');
    }

    /**
     * Login terpusat IdP. Identifier bisa email ATAU NIP.
     */
    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
        ], [
            'identifier.required' => 'Email atau NIP wajib diisi.',
        ]);

        $identifier = trim((string) $request->input('identifier'));

        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'nip';

        // Samakan normalisasi dengan model (email lowercase, NIP tanpa spasi).
        $identifier = $field === 'email'
            ? strtolower($identifier)
            : preg_replace('/[\s.\-]+/u', '', $identifier);

        $user = User::where($field, $identifier)
            ->where('status', 'active')
            ->first();

        $recognized = $user instanceof \Illuminate\Contracts\Auth\Authenticatable;

        if (! $recognized || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'Kredensial tidak valid atau akun tidak aktif.',
            ]);
        }

        // Session regeneration mencegah session fixation.
        $request->session()->regenerate();

        auth()->login($user, $request->boolean('remember'));

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        SsoSessionService::trackIdpLogin($user);

        // User baru / password hasil reset admin: wajib ganti password dulu.
        // 'url.intended' (mis. URL /oauth/authorize) dibiarkan agar setelah
        // ganti password user dikembalikan ke tujuan semula.
        if ($user->must_change_password) {
            return redirect()->route('password.change')->with(
                'warning',
                'Demi keamanan, Anda wajib mengganti password terlebih dahulu.'
            );
        }

        // redirect()->intended() mengambil & menghapus 'url.intended'
        // (disimpan middleware TrackAuthorizeRequest saat redirect ke login),
        // fallback ke dashboard.
        return redirect()->intended(route('dashboard'));
    }

    /**
     * Logout IdP ditangani SsoLogoutController@webLogout (Single Logout global).
     */
}
