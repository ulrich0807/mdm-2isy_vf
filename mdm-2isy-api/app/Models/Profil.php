<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profil extends Model
{
    use HasFactory;
    protected $fillable = ['nom', 'kiosk', 'app_kiosk', 'kiosk_apps', 'no_cam', 'no_usb', 'no_bt', 'no_wifi', 'no_data', 'no_airplane', 'pin_fort', 'blacklist_apps', 'whitelist_apps'];

    protected $casts = [
        'blacklist_apps' => 'array',
        'whitelist_apps' => 'array',
        'kiosk_apps' => 'array',
        'no_wifi' => 'boolean',
        'no_data' => 'boolean',
        'no_airplane' => 'boolean',
        'no_cam' => 'boolean',
        'no_usb' => 'boolean',
        'no_bt' => 'boolean',
        'pin_fort' => 'boolean',
        'kiosk' => 'boolean',
    ];

    public function terminals()
    {
        return $this->hasMany(Terminal::class);
    }
}