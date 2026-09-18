<?php

namespace App\Http\Controllers;

use App\Models\Lic;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LicController extends Controller
{
    // Lister toutes les licences
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Lic::all()
        ]);
    }

    // Le Super Admin génère une nouvelle licence vierge
    public function store(Request $req)
    {
        if ($req->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        // Création d'une clé unique (ex: MDM-2026-A1B2C3)
        $cle = 'MDM-' . date('Y') . '-' . strtoupper(Str::random(6));

        $lic = Lic::create([
            'cle' => $cle,
            'statut' => 'Vierge'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Licence générée avec succès',
            'data' => $lic
        ]);
    }

    // Le client active la licence sur un terminal
    public function actv(Request $req, $id)
    {
        $lic = Lic::find($id);

        if (!$lic || $lic->statut === 'Expirée') {
            return response()->json(['success' => false, 'message' => 'Licence invalide ou expirée'], 400);
        }

        // C'est ici que la magie du compte à rebours opère :
        // Si c'est la toute première activation, on lance le chrono de 1 an.
        if (is_null($lic->exp_le)) {
            $lic->exp_le = now()->addYear();
        }

        $lic->statut = 'Active';
        // $lic->term_id = $req->term_id; // À décommenter quand tu lieras un terminal précis depuis l'interface
        $lic->save();

        return response()->json([
            'success' => true,
            'message' => 'Licence activée avec succès ! Fin le : ' . $lic->exp_le->format('d/m/Y')
        ]);
    }

    // Assigner ou détacher un terminal d'une licence
    public function assign(\Illuminate\Http\Request $req, $id)
    {
        $lic = Lic::find($id);

        if (!$lic || $lic->statut !== 'Active') {
            return response()->json(['success' => false, 'message' => 'Licence invalide ou non active'], 400);
        }

        // term_id sera null pour détacher, ou un ID pour assigner
        $lic->term_id = $req->term_id;
        $lic->save();

        $msg = $req->term_id ? 'Licence assignée avec succès.' : 'Licence détachée du terminal.';
        
        return response()->json([
            'success' => true,
            'message' => $msg
        ]);
    }
}