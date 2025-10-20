<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pest\Plugins\Profile;

use App\Http\Controllers\StringController;

Route::post('/strings', [StringController::class, 'store']);
Route::get('/strings/{string_value}', [StringController::class, 'show']);
Route::get('/strings', [StringController::class, 'index']);
Route::get('/strings/filter-by-natural-language', [StringController::class, 'filterByNaturalLanguage']);
Route::delete('/strings/{string_value}', [StringController::class, 'destroy']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/profile/me', [ProfileController::class, 'me']);
