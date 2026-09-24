<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContactRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company',
        'email',
        'contact',
        'issue',
        'status',
        'read_at',
        'resolved_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ContactRequest $request): void {
            $request->public_id ??= (string) Str::uuid();
        });
    }
}
