<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'first_name' => 'Alice',
                'last_name' => 'Admin',
                'email' => 'admin.test@bus-communal.local',
                'phone' => '082100001',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ],
            [
                'first_name' => 'Bruno',
                'last_name' => 'Agent',
                'email' => 'agent.test@bus-communal.local',
                'phone' => '082100002',
                'password' => Hash::make('password'),
                'role' => 'agent_communal',
            ],
            [
                'first_name' => 'Chloe',
                'last_name' => 'Chauffeur',
                'email' => 'chauffeur.test@bus-communal.local',
                'phone' => '082100003',
                'password' => Hash::make('password'),
                'role' => 'chauffeur',
            ],
            [
                'first_name' => 'David',
                'last_name' => 'Demandeur',
                'email' => 'user.test@bus-communal.local',
                'phone' => '082100004',
                'password' => Hash::make('password'),
                'role' => 'user',
            ],
            [
                'first_name' => 'Emma',
                'last_name' => 'Responsable',
                'email' => 'responsable.test@bus-communal.local',
                'phone' => '082100005',
                'password' => Hash::make('password'),
                'role' => 'responsable_organisation',
            ],
            [
                'first_name' => 'Nora',
                'last_name' => 'Reviewer',
                'email' => 'reviewer.test@bus-communal.local',
                'phone' => '082100006',
                'password' => Hash::make('password'),
                'role' => 'agent_communal',
            ],
        ];

        foreach ($users as $attributes) {
            $role = $attributes['role'];
            unset($attributes['role']);

            $user = User::query()->updateOrCreate(
                ['email' => $attributes['email']],
                $attributes
            );

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }
    }
}
