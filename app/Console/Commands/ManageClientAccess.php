<?php

namespace App\Console\Commands;

use App\Models\ClientAccess;
use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Passport\Passport;

class ManageClientAccess extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'client-access:manage 
                           {action : Action to perform (grant|revoke|list|reset)}
                           {--user-id : User ID}
                           {--client-id : Client ID}
                           {--user-email : User email}
                           {--client-name : Client name}
                           {--opd-id : OPD ID}
                           {--all-users : Apply to all users}
                           {--all-clients : Apply to all clients}';

    /**
     * The console command description.
     */
    protected $description = 'Manage client access for users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $userId = $this->option('user-id');
        $clientId = $this->option('client-id');
        $userEmail = $this->option('user-email');
        $clientName = $this->option('client-name');
        $opdId = $this->option('opd-id');
        $allUsers = $this->option('all-users');
        $allClients = $this->option('all-clients');

        switch ($action) {
            case 'grant':
                return $this->grantAccess($userId, $clientId, $userEmail, $clientName, $opdId, $allUsers, $allClients);
            case 'revoke':
                return $this->revokeAccess($userId, $clientId, $userEmail, $clientName, $allUsers, $allClients);
            case 'list':
                return $this->listAccess($userId, $clientId, $userEmail, $clientName);
            case 'reset':
                return $this->resetAccess();
            default:
                $this->error('Invalid action. Use: grant, revoke, list, or reset');
                return 1;
        }
    }

    /**
     * Grant access to client(s) for user(s).
     */
    private function grantAccess($userId, $clientId, $userEmail, $clientName, $opdId, $allUsers, $allClients)
    {
        $users = $this->getUsers($userId, $userEmail, $allUsers);
        $clients = $this->getClients($clientId, $clientName, $allClients);

        if ($users->isEmpty()) {
            $this->error('No users found matching the criteria.');
            return 1;
        }

        if ($clients->isEmpty()) {
            $this->error('No clients found matching the criteria.');
            return 1;
        }

        $grantedCount = 0;
        foreach ($users as $user) {
            foreach ($clients as $client) {
                $access = ClientAccess::firstOrNew([
                    'user_id' => $user->id,
                    'client_id' => $client->id,
                ]);

                if (!$access->is_allowed) {
                    $access->is_allowed = true;
                    $access->opd_id = $opdId;
                    $access->save();
                    $grantedCount++;
                    $this->info("Granted access to {$user->email} for client {$client->name}");
                } else {
                    $this->line("Access already exists for {$user->email} to {$client->name}");
                }
            }
        }

        $this->info("Successfully granted access to {$grantedCount} user-client combinations.");
        return 0;
    }

    /**
     * Revoke access to client(s) for user(s).
     */
    private function revokeAccess($userId, $clientId, $userEmail, $clientName, $allUsers, $allClients)
    {
        $users = $this->getUsers($userId, $userEmail, $allUsers);
        $clients = $this->getClients($clientId, $clientName, $allClients);

        if ($users->isEmpty()) {
            $this->error('No users found matching the criteria.');
            return 1;
        }

        if ($clients->isEmpty()) {
            $this->error('No clients found matching the criteria.');
            return 1;
        }

        $revokedCount = 0;
        foreach ($users as $user) {
            foreach ($clients as $client) {
                $access = ClientAccess::where([
                    'user_id' => $user->id,
                    'client_id' => $client->id,
                ])->first();

                if ($access && $access->is_allowed) {
                    $access->is_allowed = false;
                    $access->save();
                    $revokedCount++;
                    $this->info("Revoked access from {$user->email} for client {$client->name}");
                } elseif ($access) {
                    $this->line("Access already revoked for {$user->email} to {$client->name}");
                } else {
                    $this->line("No access record found for {$user->email} to {$client->name}");
                }
            }
        }

        $this->info("Successfully revoked access from {$revokedCount} user-client combinations.");
        return 0;
    }

    /**
     * List access for user(s) and client(s).
     */
    private function listAccess($userId, $clientId, $userEmail, $clientName)
    {
        $users = $this->getUsers($userId, $userEmail, false);
        $clients = $this->getClients($clientId, $clientName, false);

        $query = ClientAccess::with(['user', 'client']);

        if ($users->isNotEmpty()) {
            $query->whereIn('user_id', $users->pluck('id'));
        }

        if ($clients->isNotEmpty()) {
            $query->whereIn('client_id', $clients->pluck('id'));
        }

        $accessList = $query->get();

        if ($accessList->isEmpty()) {
            $this->info('No access records found.');
            return 0;
        }

        $this->table(
            ['User', 'Email', 'Client', 'Allowed', 'OPD ID'],
            $accessList->map(function ($access) {
                return [
                    $access->user->name,
                    $access->user->email,
                    $access->client->name,
                    $access->is_allowed ? 'Yes' : 'No',
                    $access->opd_id ?? 'N/A',
                ];
            })
        );

        return 0;
    }

    /**
     * Reset all access.
     */
    private function resetAccess()
    {
        if (!$this->confirm('Are you sure you want to reset all client access? This action cannot be undone.')) {
            return 0;
        }

        $count = ClientAccess::count();
        ClientAccess::truncate();
        $this->info("Reset {$count} client access records.");
        return 0;
    }

    /**
     * Get users based on criteria.
     */
    private function getUsers($userId, $userEmail, $allUsers)
    {
        if ($allUsers) {
            return User::all();
        }

        if ($userId) {
            return User::where('id', $userId)->get();
        }

        if ($userEmail) {
            return User::where('email', $userEmail)->get();
        }

        return collect();
    }

    /**
     * Get clients based on criteria.
     */
    private function getClients($clientId, $clientName, $allClients)
    {
        $clientModel = Passport::clientModel();
        $query = $clientModel::where('revoked', false)->whereNotNull('landing_url');

        if ($allClients) {
            return $query->get();
        }

        if ($clientId) {
            return $query->where('id', $clientId)->get();
        }

        if ($clientName) {
            return $query->where('name', $clientName)->get();
        }

        return collect();
    }
}