<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommandEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'device_command_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'metadata',
        'created_at',
    ];

    protected $hidden = [
        'id',
        'device_command_id',
        'actor_id',
    ];

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'device_command_id');
    }

    protected function casts(): array
    {
        return [
            'device_command_id' => 'integer',
            'actor_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
