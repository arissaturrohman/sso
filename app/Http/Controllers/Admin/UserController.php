<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\WhatsAppNumber;
use App\Services\FonnteService;
use App\Services\PasswordGenerator;
use App\Services\SloService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(
        private readonly FonnteService $whatsapp,
        private readonly SloService $slo
    ) {}

    public function index(Request $request): View
    {
        $query = User::query()->orderByDesc('id');

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%")
                    ->orWhere('whatsapp', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(10)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'whatsappConfigured' => $this->whatsapp->isConfigured(),
        ]);
    }

    /**
     * Buat user baru. Password dibuat otomatis lalu dikirim ke WhatsApp user
     * via Fonnte. Jika pengiriman gagal, password ditampilkan sekali di panel
     * admin agar bisa diteruskan manual.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->normalizeIdentityInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'nip' => ['required', 'string', 'max:50', 'unique:users,nip'],
            'whatsapp' => ['required', 'string', 'max:20', new WhatsAppNumber()],
            'role' => ['required', 'in:admin,user'],
            'status' => ['required', 'in:active,suspended,inactive'],
        ], [
            'nip.required' => 'NIP wajib diisi.',
            'nip.unique' => 'NIP sudah digunakan oleh user lain.',
            'whatsapp.required' => 'Nomor WhatsApp wajib diisi (password dikirim ke nomor ini).',
        ]);

        $plainPassword = PasswordGenerator::generate(12);

        $validated['password'] = $plainPassword;
        $validated['whatsapp'] = $this->whatsapp->normalizeNumber($validated['whatsapp']);
        // Password dibuat sistem -> user wajib menggantinya saat login pertama
        $validated['must_change_password'] = true;

        $user = User::create($validated);

        $result = $this->whatsapp->sendCredentials($user, $plainPassword);

        if ($result['ok']) {
            $user->forceFill(['password_sent_at' => now()])->save();

            return redirect()->route('admin.users.index')->with(
                'status',
                "User {$user->name} berhasil dibuat. Password dikirim ke WhatsApp {$user->whatsappDisplay()}."
            );
        }

        return redirect()->route('admin.users.index')
            ->with('status', "User {$user->name} berhasil dibuat, tetapi pengiriman WhatsApp GAGAL: {$result['error']}")
            ->with('generated_password', [
                'name' => $user->name,
                'email' => $user->email,
                'nip' => $user->nip,
                'whatsapp' => $user->whatsappDisplay(),
                'password' => $plainPassword,
            ]);
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->normalizeIdentityInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'nip' => ['required', 'string', 'max:50', 'unique:users,nip,'.$user->id],
            'whatsapp' => ['nullable', 'string', 'max:20', new WhatsAppNumber()],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'in:admin,user'],
            'status' => ['required', 'in:active,suspended,inactive'],
            'must_change_password' => ['sometimes', 'boolean'],
        ], [
            'nip.required' => 'NIP wajib diisi.',
            'nip.unique' => 'NIP sudah digunakan oleh user lain.',
        ]);

        // Admin tidak boleh menurunkan role / menonaktifkan akunnya sendiri.
        if ($user->is($request->user())) {
            if ($validated['role'] !== 'admin') {
                return back()->withErrors(['role' => 'Anda tidak dapat mengubah role akun Anda sendiri.']);
            }

            if ($validated['status'] !== 'active') {
                return back()->withErrors(['status' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.']);
            }
        }

        $originalEmail = $user->email;
        $originalStatus = $user->status;
        $originalRole = $user->role;
        $passwordChanged = filled($validated['password'] ?? null);

        if (empty($validated['password'])) {
            unset($validated['password']); // password tetap
            $validated['must_change_password'] = $request->boolean('must_change_password');
        } else {
            // Admin menetapkan password manual -> paksa user menggantinya
            $validated['must_change_password'] = true;
        }

        $validated['whatsapp'] = filled($validated['whatsapp'] ?? null)
            ? $this->whatsapp->normalizeNumber($validated['whatsapp'])
            : null;

        $user->update($validated);

        $emailChanged = $user->email !== $originalEmail;

        if ($emailChanged) {
            // Email baru belum diverifikasi. Jangan pertahankan centang
            // email_verified_at milik alamat lama (cegah takeover via
            // perubahan email tanpa verifikasi ulang).
            $user->forceFill(['email_verified_at' => null])->save();

            Log::info('[SSO] Email user diubah oleh admin', [
                'user_id' => $user->id,
                'old_email' => $originalEmail,
                'new_email' => $user->email,
                'admin_id' => $request->user()->id,
            ]);
        }

        // Perubahan sensitif harus langsung mematikan sesi/token yang ada:
        // ganti password, ubah email, ubah role, atau suspend/aktifkan akun.
        $securityRelevant = $emailChanged
            || $user->status !== $originalStatus
            || $user->role !== $originalRole
            || $passwordChanged;

        if ($securityRelevant) {
            $this->slo->logout($user->fresh(), null, 'admin');
        }

        return redirect()->route('admin.users.index')->with('status', 'User berhasil diperbarui.');
    }

    /**
     * Buat password baru untuk user lalu kirim ulang ke WhatsApp-nya.
     */
    public function sendPassword(User $user): RedirectResponse
    {
        if (! $user->whatsapp) {
            return back()->withErrors([
                'error' => 'User ini belum memiliki nomor WhatsApp. Tambahkan nomor lewat menu Edit terlebih dahulu.',
            ]);
        }

        $plainPassword = PasswordGenerator::generate(12);

        $user->forceFill([
            'password' => $plainPassword,
            // Password hasil reset wajib diganti user saat login berikutnya
            'must_change_password' => true,
        ])->save();

        // Reset password = tindakan keamanan: matikan semua sesi & token lama.
        $this->slo->logout($user->fresh(), null, 'admin');

        $result = $this->whatsapp->sendCredentials($user, $plainPassword);

        if ($result['ok']) {
            $user->forceFill(['password_sent_at' => now()])->save();

            return back()->with(
                'status',
                "Password baru untuk {$user->name} dikirim ke WhatsApp {$user->whatsappDisplay()}. User wajib menggantinya saat login."
            );
        }

        return back()
            ->with('status', "Gagal mengirim WhatsApp ke {$user->name}: {$result['error']}")
            ->with('generated_password', [
                'name' => $user->name,
                'email' => $user->email,
                'nip' => $user->nip,
                'whatsapp' => $user->whatsappDisplay(),
                'password' => $plainPassword,
            ]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['error' => 'Anda tidak dapat menghapus akun Anda sendiri.']);
        }

        // Cabut token, tandai sesi berakhir, dan beri tahu SP SEBELUM user dihapus.
        $this->slo->logout($user, null, 'admin');

        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'User berhasil dihapus.');
    }

    /**
     * Normalisasi sebelum validasi unique, agar "1995 000002" tidak lolos
     * unique:NIP lalu bertabrakan setelah dinormalisasi menjadi "1995000002".
     */
    protected function normalizeIdentityInput(Request $request): void
    {
        if ($request->has('email')) {
            $request->merge([
                'email' => strtolower(trim((string) $request->input('email'))),
            ]);
        }

        if ($request->has('nip')) {
            $nip = preg_replace('/[\s.\-]+/u', '', trim((string) $request->input('nip')));
            $request->merge(['nip' => $nip === '' ? null : $nip]);
        }
    }
}
