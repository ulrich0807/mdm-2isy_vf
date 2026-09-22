<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lic extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'cle',
        'term_id',
        'statut',
        'exp_le'
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'term_id');
    }

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'term_id' => 'integer',
            'exp_le' => 'datetime',
        ];
    }
}
