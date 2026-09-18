<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profil extends Model
{
    use HasFactory;
    protected $fillable = ['nom', 'kiosk', 'app_kiosk', 'no_cam', 'no_usb', 'no_bt', 'pin_fort', 'blacklist_apps'];

    protected $casts = [
        'blacklist_apps' => 'array',
    ];

    public function terminals()
    {
        return $this->hasMany(Terminal::class);
    }
}