<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'branch_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function cashRegisters()
    {
        return $this->hasMany(CashRegister::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /** Rol de negocio con acceso total (decisión 1 del cliente: opera todas las tiendas). */
    public function isAdministrator(): bool
    {
        return $this->hasAnyRole(['super_admin', 'administrador']);
    }

    /** Administrador y super_admin ven y operan todas las tiendas; el resto, solo la suya. */
    public function seesAllBranches(): bool
    {
        return $this->isAdministrator();
    }
    
    public function canAccessPanel(Panel $panel): bool
    {
        return true; // Acceso total al panel
    }

    protected static function booted()
    {
        static::deleting(function ($user) {
            if ($user->sales()->count() > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => 'No se puede eliminar un usuario con ventas registradas en el sistema.',
                ]);
            }

            if ($user->cashRegisters()->count() > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => 'No se puede eliminar un usuario con registros de caja en el sistema.',
                ]);
            }

            if ($user->hasRole('super_admin') && static::role('super_admin')->count() <= 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => 'No se puede eliminar al único super administrador del sistema.',
                ]);
            }
        });
    }
}
