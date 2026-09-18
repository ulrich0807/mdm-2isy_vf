<?php

namespace App\Http\Controllers;

use App\Models\Terminal;
use Illuminate\Http\Request;

class GpsCtrl extends Controller
{
    public function sync(Request $request, int $id)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'batt' => ['required', 'integer', 'between:0,100'],
        ]);

        $term = Terminal::findOrFail($id);

        $term->update([
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'batterie' => $data['batt'],
            'statut' => 'En ligne',
        ]);

        return response()->json([
            'sync' => true,
            'id' => $term->imei,
        ]);
    }
}
