<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'active',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function deviceGroups(): HasMany
    {
        return $this->hasMany(DeviceGroup::class);
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    public function enrollmentTokens(): HasMany
    {
        return $this->hasMany(DeviceEnrollmentToken::class);
    }

    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    public function profils(): HasMany
    {
        return $this->hasMany(Profil::class);
    }

    public function licences(): HasMany
    {
        return $this->hasMany(Lic::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(Log::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
