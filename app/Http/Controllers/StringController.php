<?php

namespace App\Http\Controllers;

use App\Models\StringEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StringController extends Controller
{
    // Helpers -------------------------------------------------
    protected function computeProperties(string $value): array
    {
        // length (characters)
        $length = mb_strlen($value);

        // normalized for palindrome check: keep letters/numbers, lower-case
        $normalized = mb_strtolower(preg_replace('/[^[:alnum:]\p{L}]/u', '', $value));
        $isPalindrome = ($normalized !== '' && $normalized === mb_strrev($normalized));

        // unique characters
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $uniqueCharacters = count(array_unique($chars));

        // word count (split on whitespace)
        $words = preg_split('/\s+/u', trim($value));
        $wordCount = ($words === null || $words === [''] ? 0 : count($words));

        // sha256
        $sha = hash('sha256', $value);

        // frequency map
        $freq = [];
        foreach ($chars as $c) {
            $freq[$c] = ($freq[$c] ?? 0) + 1;
        }

        return [
            'length' => $length,
            'is_palindrome' => (bool) $isPalindrome,
            'unique_characters' => $uniqueCharacters,
            'word_count' => $wordCount,
            'sha256_hash' => $sha,
            'character_frequency_map' => $freq,
        ];
    }

    // POST /strings
    public function store(Request $request)
    {
        $data = $request->validate([
            'value' => ['required', 'string'],
        ]);

        $value = $data['value'];
        $properties = $this->computeProperties($value);
        $id = $properties['sha256_hash'];

        // Conflict: if exists return 409
        if (StringEntry::find($id)) {
            return response()->json(['message' => 'String already exists'], Response::HTTP_CONFLICT);
        }

        $entry = StringEntry::create([
            'id' => $id,
            'value' => $value,
            'properties' => $properties,
        ]);

        return response()->json([
            'id' => $entry->id,
            'value' => $entry->value,
            'properties' => $entry->properties,
            'created_at' => $entry->created_at->toIso8601String(),
        ], Response::HTTP_CREATED);
    }

    // GET /strings/{string_value}
    public function show($string_value)
    {
        // To avoid URL issues: compute sha from raw path segment (Laravel decodes)
        $sha = hash('sha256', $string_value);
        $entry = StringEntry::find($sha);
        if (! $entry) {
            return response()->json(['message' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'id' => $entry->id,
            'value' => $entry->value,
            'properties' => $entry->properties,
            'created_at' => $entry->created_at->toIso8601String(),
        ]);
    }

    // GET /strings  (with filters)
    public function index(Request $request)
    {
        // validated filters
        $validated = $request->validate([
            'is_palindrome' => 'sometimes|in:true,false,0,1',
            'min_length' => 'sometimes|integer|min:0',
            'max_length' => 'sometimes|integer|min:0',
            'word_count' => 'sometimes|integer|min:0',
            'contains_character' => 'sometimes|string|size:1',
        ]);

        $query = StringEntry::query();

        if ($request->filled('is_palindrome')) {
            $val = filter_var($request->get('is_palindrome'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $query->whereJsonContains('properties->is_palindrome', (bool) $val);
        }

        if ($request->filled('min_length')) {
            $query->whereRaw("JSON_EXTRACT(properties, '$.length') >= ?", [$request->get('min_length')]);
        }
        if ($request->filled('max_length')) {
            $query->whereRaw("JSON_EXTRACT(properties, '$.length') <= ?", [$request->get('max_length')]);
        }
        if ($request->filled('word_count')) {
            $query->whereRaw("JSON_EXTRACT(properties, '$.word_count') = ?", [$request->get('word_count')]);
        }
        if ($request->filled('contains_character')) {
            $char = $request->get('contains_character');
            // search in JSON character_frequency_map keys: simplest approach: value LIKE
            $query->where('properties->character_frequency_map', 'like', "%\"{$char}\"%");
        }

        $results = $query->orderBy('created_at', 'desc')->get();

        $data = $results->map(function ($entry) {
            return [
                'id' => $entry->id,
                'value' => $entry->value,
                'properties' => $entry->properties,
                'created_at' => $entry->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'count' => $data->count(),
            'filters_applied' => $validated,
        ]);
    }

    // GET /strings/filter-by-natural-language?query=...
    public function filterByNaturalLanguage(Request $request)
    {
        $request->validate(['query' => 'required|string']);

        $q = mb_strtolower($request->get('query'));

        // Very simple rule-based parser (extend as needed)
        $parsed = [];

        // single word palindromic
        if (preg_match('/single word palindromic|single-word palindromic|single word palindrome/', $q)) {
            $parsed['word_count'] = 1;
            $parsed['is_palindrome'] = true;
        }

        if (preg_match('/strings longer than (\d+)/', $q, $m)) {
            $parsed['min_length'] = intval($m[1]) + 1;
        }

        if (preg_match('/contain(s|ing)? the letter (\w)/', $q, $m)) {
            $parsed['contains_character'] = $m[2];
        }

        if (preg_match('/palindromic/', $q) && !isset($parsed['is_palindrome'])) {
            $parsed['is_palindrome'] = true;
        }

        if (empty($parsed)) {
            return response()->json(['message' => 'Unable to parse natural language query'], Response::HTTP_BAD_REQUEST);
        }

        // reuse index logic by building a query
        $fakeRequest = new Request($parsed);
        $reqMethod = $this->index($fakeRequest);

        // parse the returned JSON to include interpreted_query
        $respData = $reqMethod->getData(true);

        return response()->json([
            'data' => $respData['data'],
            'count' => $respData['count'],
            'interpreted_query' => [
                'original' => $request->get('query'),
                'parsed_filters' => $parsed
            ]
        ]);
    }

    // DELETE /strings/{string_value}
    public function destroy($string_value)
    {
        $sha = hash('sha256', $string_value);
        $entry = StringEntry::find($sha);
        if (! $entry) {
            return response()->json(['message' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $entry->delete();
        return response(null, Response::HTTP_NO_CONTENT);
    }
}

// Helper function: multibyte string reverse
if (! function_exists('mb_strrev')) {
    function mb_strrev($str)
    {
        $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
        return implode('', array_reverse($chars));
    }
}
