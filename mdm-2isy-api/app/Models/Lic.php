<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lic extends Model
{
    use HasFactory;

    protected $fillable = [
        'cle',
        'term_id',
        'statut',
        'exp_le'
    ];
}