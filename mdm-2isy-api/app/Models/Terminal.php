<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Terminal extends Model
{
    use HasFactory, HasUuids;

    public const CONNECTIVITY_THRESHOLD_MINUTES = 5;

    protected $appends = [
        'connectivity_status',
    ];

    protected $fillable = [
        'organization_id',
        'device_group_id',
        'device_uid',
        'imei',
        'serial_number',
        'manufacturer',
        'livreur',
        'modele',
        'android_version',
        'android_build',
        'batterie',
        'storage_total_mb',
        'storage_free_mb',
        'agent_version',
        'statut',
        'enrollment_status',
        'management_state',
        'enrolled_at',
        'last_seen_at',
        'lat',
        'lng',
        'camera_active',
        'wifi_active',
        'bluetooth_active',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function deviceGroup(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(DeviceCredential::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    protected function connectivityStatus(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->last_seen_at !== null
                && $this->last_seen_at->greaterThanOrEqualTo(
                    now()->subMinutes(self::CONNECTIVITY_THRESHOLD_MINUTES),
                )
                    ? 'online'
                    : 'offline',
        );
    }

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'device_group_id' => 'integer',
            'batterie' => 'integer',
            'storage_total_mb' => 'integer',
            'storage_free_mb' => 'integer',
            'lat' => 'decimal:8',
            'lng' => 'decimal:8',
            'camera_active' => 'boolean',
            'wifi_active' => 'boolean',
            'bluetooth_active' => 'boolean',
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
