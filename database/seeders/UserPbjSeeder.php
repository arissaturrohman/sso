<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserPbjSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Update existing users dengan email pattern PBJ
        $pbjUsers = User::where('email', 'like', '%pbj.go.id%')->get();
        
        foreach ($pbjUsers as $user) {
            $user->update(['is_pbj' => true]);
            $this->command->info("Updated {$user->email} as PBJ user");
        }
        
        // Update admin user sebagai PBJ
        $adminUser = User::where('email', 'admin@example.com')->first();
        if ($adminUser) {
            $adminUser->update(['is_pbj' => true]);
            $this->command->info("Updated admin user as PBJ");
        }
        
        // Tambahkan sample PBJ users jika tidak ada
        $samplePbjUsers = [
            [
                'name' => 'Ahmad PBJ',
                'email' => 'ahmad@pbj.go.id',
                'nip' => '198501012023041001',
                'password' => Hash::make('password123'),
                'status' => 'active',
                'role' => 'user',
                'is_pbj' => true,
            ],
            [
                'name' => 'Siti PBJ',
                'email' => 'siti@pbj.go.id',
                'nip' => '199002022023041002',
                'password' => Hash::make('password123'),
                'status' => 'active',
                'role' => 'user',
                'is_pbj' => true,
            ],
            [
                'name' => 'Budi PBJ',
                'email' => 'budi@pbj.go.id',
                'nip' => '199103032023041003',
                'password' => Hash::make('password123'),
                'status' => 'active',
                'role' => 'user',
                'is_pbj' => true,
            ],
        ];
        
        foreach ($samplePbjUsers as $userData) {
            $user = User::where('email', $userData['email'])->first();
            
            if (!$user) {
                User::create($userData);
                $this->command->info("Created PBJ user: {$userData['email']}");
            } else {
                $user->update(['is_pbj' => true]);
                $this->command->info("Updated existing user as PBJ: {$userData['email']}");
            }
        }
    }
}