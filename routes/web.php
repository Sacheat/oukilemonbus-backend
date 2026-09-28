<?php

use Illuminate\Support\Facades\Route;

/*
| Le backend n'expose aucune page : l'interface est une SPA séparée qui consomme /api.
*/
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'type' => 'api',
    'api' => url('/api'),
    'health' => url('/up'),
]));
