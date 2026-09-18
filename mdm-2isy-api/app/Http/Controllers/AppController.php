<?php

namespace App\Http\Controllers;

use App\Models\App;
use Illuminate\Http\Request;

class AppController extends Controller 
{
    public function index() 
    {
        return response()->json(App::orderBy('id', 'desc')->get());
    }

    public function store(Request $req) 
    {
        $app = App::create($req->all());
        return response()->json(['success' => true, 'message' => 'Application ajoutée', 'data' => $app]);
    }

    public function destroy($id) 
    {
        App::destroy($id);
        return response()->json(['success' => true, 'message' => 'Application supprimée']);
    }
}