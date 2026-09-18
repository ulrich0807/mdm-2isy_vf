<?php

namespace App\Http\Controllers;

use App\Models\Profil;
use Illuminate\Http\Request;

class ProfilController extends Controller 
{
    public function index() 
    {
        return response()->json(Profil::orderBy('id', 'desc')->get());
    }

    public function store(Request $req) 
    {
        $prof = Profil::create($req->all());
        return response()->json(['success' => true, 'message' => 'Profil créé', 'data' => $prof]);
    }

    public function destroy($id) 
    {
        Profil::destroy($id);
        return response()->json(['success' => true, 'message' => 'Profil supprimé']);
    }
}