<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Log extends Model
{
    use HasFactory;
    protected $fillable = ['organization_id', 'usr', 'act', 'cible', 'typ'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
