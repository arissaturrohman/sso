<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClientAccessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing access data
        DB::table('client_access')->truncate();
        
        // Get all existing clients
        $clients = DB::table('oauth_clients')
            ->where('revoked', false)
            ->whereNotNull('landing_url')
            ->get();
        
        if ($clients->isEmpty()) {
            $this->command->info('No active clients found. Skipping client access seeder.');
            return;
        }
        
        // Get all users
        $users = User::all();
        
        if ($users->isEmpty()) {
            $this->command->info('No users found. Skipping client access seeder.');
            return;
        }
        
        $this->command->info("Setting up access for {$users->count()} users and {$clients->count()} clients...");
        
        // For each user, set up client access
        foreach ($users as $user) {
            foreach ($clients as $client) {
                $isAllowed = false;
                $opdId = null;
                
                // PBJ users can access all clients
                if ($user->is_pbj) {
                    $isAllowed = true;
                } 
                // Regular users - for now, allow access to all clients
                // This can be enhanced later with actual OPD matching
                else {
                    $isAllowed = true;
                    $opdId = $user->opd_id;
                }
                
                // Create access record
                DB::table('client_access')->insert([
                    'id' => \Str::uuid(),
                    'user_id' => $user->id,
                    'client_id' => $client->id,
                    'opd_id' => $opdId,
                    'is_allowed' => $isAllowed,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                if ($isAllowed) {
                    $this->command->line("✓ Granted access to {$user->email} for client {$client->name}");
                } else {
                    $this->command->line("✗ Denied access to {$user->email} for client {$client->name}");
                }
            }
        }
        
        // Add some specific access rules for demonstration
        $this->addSpecificAccessRules();
        
        $this->command->info('Client access setup completed!');
    }
    
    /**
     * Add specific access rules for demonstration purposes.
     */
    private function addSpecificAccessRules()
    {
        $this->command->info('Adding specific access rules...');
        
        // Example: Create restricted clients for demonstration
        $restrictedClients = [
            'SIM-UKPBJ' => [1, 2], // Only allow users with ID 1 and 2
            'Monitoring Proyek PBJ' => [2, 3], // Only allow users with ID 2 and 3
        ];
        
        foreach ($restrictedClients as $clientName => $allowedUserIds) {
            $client = DB::table('oauth_clients')
                ->where('name', $clientName)
                ->first();
            
            if ($client) {
                // Remove existing access for this client
                DB::table('client_access')
                    ->where('client_id', $client->id)
                    ->delete();
                
                $this->command->info("Restricting access to {$clientName}...");
                
                // Add restricted access
                foreach ($allowedUserIds as $userId) {
                    $user = User::find($userId);
                    if ($user) {
                        DB::table('client_access')->insert([
                            'id' => \Str::uuid(),
                            'user_id' => $user->id,
                            'client_id' => $client->id,
                            'opd_id' => $user->opd_id,
                            'is_allowed' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        
                        $this->command->line("  → Restricted access to {$user->email}");
                    }
                }
            }
        }
    }
}