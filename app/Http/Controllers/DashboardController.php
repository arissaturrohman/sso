<?php

namespace App\Http\Controllers;

use App\Models\ClientAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Passport\Passport;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with client applications based on user role.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $clientModel = Passport::clientModel();
        
        // Get all active clients
        $allClients = $clientModel::query()
            ->where('revoked', false)
            ->whereNotNull('landing_url')
            ->orderBy('name')
            ->get();
        
        // Filter clients based on user access
        if ($user->is_pbj) {
            // PBJ users can see all client applications
            $apps = $allClients;
        } else {
            // Regular users can only see applications they have access to
            $allowedClientIds = ClientAccess::where('user_id', $user->id)
                ->where('is_allowed', true)
                ->pluck('client_id');
            
            $apps = $allClients->whereIn('id', $allowedClientIds);
        }
        
        return view('dashboard', [
            'apps' => $apps,
            'isPbj' => $user->is_pbj,
            'userOpd' => $user->opd_id ? $user->opd->name : null,
            'totalClients' => $allClients->count(),
            'accessibleClients' => $apps->count(),
        ]);
    }
}