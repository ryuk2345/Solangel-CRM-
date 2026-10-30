<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Crea el usuario administrador inicial del sistema.
     * ⚠️ Cambia la contraseña inmediatamente después del primer ingreso.
     */
    public function run(): void
    {
        // 1. Crear rol super-admin si no existe
        $adminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        // 2. Obtener correo y contraseña desde variables de entorno o generar una aleatoria
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $plainPassword = env('ADMIN_PASSWORD');
        $wasGenerated = false;

        if (empty($plainPassword)) {
            $plainPassword = Str::random(16);
            $wasGenerated = true;
        }

        // 3. Crear usuario admin
        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name'     => 'Administrador',
                'password' => Hash::make($plainPassword),
            ]
        );

        // 4. Asignar rol
        $admin->assignRole($adminRole);

        $this->command->info("✅ Usuario admin configurado: {$email}");
        if ($wasGenerated) {
            $this->command->warn("🔑 Contraseña generada (se muestra una sola vez): {$plainPassword}");
        }
        $this->command->warn('⚠️  Cambia la contraseña desde el panel de administración.');
    }
}
