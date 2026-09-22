<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class App extends Model
{
    use HasFactory;
    protected $fillable = ['organization_id', 'nom', 'pkg', 'type', 'ver', 'chemin_apk'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
