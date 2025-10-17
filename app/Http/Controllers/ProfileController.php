<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ProfileController extends Controller
{
    //
    public function me()
    {
        // Try fetching a cat fact from external API
        try {
            $response = Http::withoutVerifying()->timeout(5)->get('https://catfact.ninja/fact');

            if ($response->successful()) {
                $catFact = $response->json()['fact'];
            } else {
                $catFact = "Cat fact currently unavailable";
            }
        } catch (\Exception $e) {
            $catFact = "Cat fact currently unavailable ";
        }

        // Build response
        return response()->json([
            'status' => 'success',
            'user' => [
                'email' => env('USER_EMAIL'),
                'name' => env('USER_NAME'),
                'stack' => env('USER_STACK'),
            ],
            'timestamp' => now()->toISOString(),
            'fact' => $catFact
        ], 200);
    }
}
