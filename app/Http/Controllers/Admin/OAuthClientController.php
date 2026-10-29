<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Rules\SafeExternalUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class OAuthClientController extends Controller
{
    public function index(): View
    {
        $clientModel = Passport::clientModel();

        $clients = $clientModel::query()->orderByDesc('created_at')->get();

        return view('admin.clients.index', compact('clients'));
    }

    public function create(): View
    {
        return view('admin.clients.create');
    }

    public function store(Request $request, ClientRepository $repository): RedirectResponse
    {
        $validated = $this->validateClient($request);

        $client = $repository->createAuthorizationCodeGrantClient(
            name: $validated['name'],
            redirectUris: $validated['redirect_uris'],
            confidential: true,
        );

        $client->forceFill([
            'trusted' => (bool) $request->boolean('trusted'),
            'landing_url' => $validated['landing_url'] ?? null,
            'backchannel_logout_uri' => $validated['backchannel_logout_uri'] ?? null,
            'frontchannel_logout_uri' => $validated['frontchannel_logout_uri'] ?? null,
        ])->save();

        // Secret ditampilkan SEKALI di halaman khusus (plainSecret hanya tersedia saat pembuatan)
        return redirect()
            ->route('admin.clients.secret', $client->id)
            ->with('plain_secret', $client->plainSecret)
            ->with('status', 'Aplikasi klien berhasil dibuat.');
    }

    /**
     * Halaman tampilan secret — hanya sekali setelah create / regenerate.
     */
    public function secret(string $clientId): View
    {
        $plainSecret = session('plain_secret');

        abort_if(empty($plainSecret), 404);

        $client = Client::findOrFail($clientId);

        return view('admin.clients.secret', [
            'client' => $client,
            'plainSecret' => $plainSecret,
        ]);
    }

    public function edit(string $clientId): View
    {
        $client = Client::findOrFail($clientId);

        return view('admin.clients.edit', compact('client'));
    }

    public function update(Request $request, string $clientId): RedirectResponse
    {
        $client = Client::findOrFail($clientId);

        $validated = $this->validateClient($request, $client);

        $client->forceFill([
            'name' => $validated['name'],
            'redirect_uris' => $validated['redirect_uris'],
            'trusted' => (bool) $request->boolean('trusted'),
            'landing_url' => $validated['landing_url'] ?? null,
            'backchannel_logout_uri' => $validated['backchannel_logout_uri'] ?? null,
            'frontchannel_logout_uri' => $validated['frontchannel_logout_uri'] ?? null,
        ])->save();

        return redirect()->route('admin.clients.index')->with('status', 'Aplikasi klien berhasil diperbarui.');
    }

    public function destroy(Request $request, string $clientId): RedirectResponse
    {
        $client = Client::findOrFail($clientId);

        // Cabut semua token yang terkait client ini
        $tokenIds = \DB::table('oauth_access_tokens')->where('client_id', $client->id)->pluck('id');

        \DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        \DB::table('oauth_access_tokens')->where('client_id', $client->id)->update(['revoked' => true]);

        // Soft delete ala Passport: revoked = true
        $client->forceFill(['revoked' => true])->save();

        return redirect()->route('admin.clients.index')->with('status', 'Aplikasi klien dicabut (revoked).');
    }

    /**
     * Regenerate client secret — secret lama otomatis tidak berlaku.
     */
    public function regenerateSecret(Request $request, string $clientId, ClientRepository $repository): RedirectResponse
    {
        $client = Client::findOrFail($clientId);

        $plainSecret = Str::random(40);

        $client->forceFill(['secret' => $plainSecret])->save();

        return redirect()
            ->route('admin.clients.secret', $client->id)
            ->with('plain_secret', $plainSecret)
            ->with('status', 'Client secret berhasil di-generate ulang. Secret lama tidak berlaku lagi.');
    }

    /**
     * Validasi bersama untuk store & update.
     */
    protected function validateClient(Request $request, ?Client $client = null): array
    {
        // URI opsional yang dibiarkan kosong dibuang sebelum validasi,
        // agar textarea "URI kedua (opsional)" tidak memicu error required.
        $uris = collect((array) $request->input('redirect_uris', []))
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->values()
            ->all();

        $request->merge(['redirect_uris' => $uris]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'url', 'max:255'],
            // Server-side fetch: wajib HTTPS + host publik (anti-SSRF).
            'backchannel_logout_uri' => ['nullable', 'url', 'max:255', new SafeExternalUrl],
            'frontchannel_logout_uri' => ['nullable', 'url', 'max:255', new SafeExternalUrl],
            'landing_url' => ['nullable', 'url', 'max:255'],
            'trusted' => ['nullable', 'boolean'],
        ], [
            'redirect_uris.required' => 'Minimal satu Redirect URI wajib diisi.',
            'redirect_uris.min' => 'Minimal satu Redirect URI wajib diisi.',
            'redirect_uris.*.url' => 'Setiap redirect URI harus berupa URL yang valid.',
        ]);

        // Normalisasi: string kosong dianggap tidak diatur (null),
        // supaya whereNotNull / ?? '—' di view bekerja konsisten.
        foreach (['backchannel_logout_uri', 'frontchannel_logout_uri', 'landing_url'] as $field) {
            $validated[$field] = filled($validated[$field] ?? null) ? $validated[$field] : null;
        }

        return $validated;
    }
}
