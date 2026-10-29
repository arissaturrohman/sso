<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SsoSession;
use App\Models\User;
use Illuminate\View\View;
use Laravel\Passport\Passport;

class DashboardController extends Controller
{
    public function index(): View
    {
        $clientModel = Passport::clientModel();

        $stats = [
            'users' => User::count(),
            'users_active' => User::where('status', 'active')->count(),
            'users_suspended' => User::where('status', '!=', 'active')->count(),
            'clients' => $clientModel::where('revoked', false)->count(),
            'sessions_active' => SsoSession::active()->count(),
            'admins' => User::where('role', 'admin')->count(),
        ];

        // 5 user terbaru & 5 client terbaru untuk ringkasan
        $recentUsers = User::latest()->take(5)->get();
        $recentClients = $clientModel::latest()->take(5)->get();

        return view('admin.dashboard', compact('stats', 'recentUsers', 'recentClients'));
    }
}
