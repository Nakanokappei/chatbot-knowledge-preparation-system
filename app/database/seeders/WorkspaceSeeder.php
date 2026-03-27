<?php

namespace Database\Seeders;

use App\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Create a default workspace and user for development and testing.
 */
class WorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        // Create the default development workspace
        $workspace = Workspace::create([
            'name' => 'Development Workspace',
            'status' => 'active',
        ]);

        // Create a default admin user associated with the workspace
        User::create([
            'workspace_id' => $workspace->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);
    }
}
