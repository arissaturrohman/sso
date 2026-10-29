<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.change-password', [
            'forced' => (bool) $request->user()->must_change_password,
        ]);
    }

    /**
     * Ganti password user yang sedang login. Setelah berhasil,
     * flag must_change_password dimatikan dan user diarahkan ke
     * halaman yang tadi ingin dibuka (mis. /oauth/authorize).
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'current_password.required' => 'Password saat ini wajib diisi.',
            'current_password.current_password' => 'Password saat ini tidak sesuai.',
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.different' => 'Password baru harus berbeda dari password saat ini.',
        ]);

        $user = $request->user();

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // Cegah session fixation setelah kredensial berubah
        $request->session()->regenerate();

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', 'Password berhasil diubah.');
    }
}
