<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Create a default tenant and user for development and testing.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // Create the default development tenant
        $tenant = Tenant::create([
            'name' => 'Development Tenant',
            'status' => 'active',
        ]);

        // Create a default admin user associated with the tenant
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);
    }
}
