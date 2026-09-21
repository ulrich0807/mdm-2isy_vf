<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationHistory extends Model
{
    protected $fillable = [
        'terminal_id',
        'lat',
        'lng',
        'recorded_at',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'recorded_at' => 'datetime',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
