<?php

namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuOcrController extends Controller
{
    public function items(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'category', 'default_cost']);

        return response()->json($items);
    }

    public function match(Request $request): JsonResponse
    {
        $request->validate(['word' => 'required|string|min:1']);

        $word = $request->word;
        $clientId = $request->user()->current_client_id;

        if (mb_strlen($word) < 2) {
            return response()->json([]);
        }

        // First pass: DB LIKE filter to reduce candidates
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $word) . '%';

        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like);
            })
            ->limit(50)
            ->get(['id', 'name', 'unit', 'category', 'default_cost']);

        if ($items->isEmpty()) {
            return response()->json([]);
        }

        $results = [];
        $normalized = mb_strtolower(trim($word));

        foreach ($items as $item) {
            $similarity = $this->similarity($normalized, $item->name);

            if ($similarity < 0.4) {
                continue;
            }

            $results[] = [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'category' => $item->category,
                'default_cost' => $item->default_cost,
                'similarity' => round($similarity * 100, 1),
            ];
        }

        usort($results, fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);

        return response()->json(array_slice($results, 0, 20));
    }

    private function similarity(string $normalized, string $name): float
    {
        $name = mb_strtolower(trim($name));

        if ($normalized === $name) {
            return 1.0;
        }

        $lenA = mb_strlen($normalized);
        $lenB = mb_strlen($name);

        if ($lenA < 2 || $lenB < 2) {
            return 0.0;
        }

        $lev = $this->levenshteinMb($normalized, $name, $lenA, $lenB);
        $maxLen = max($lenA, $lenB);

        return ($maxLen - min($lev, $maxLen)) / $maxLen;
    }

    private function levenshteinMb(string $a, string $b, int $lenA, int $lenB): int
    {
        if ($lenA === 0) {
            return $lenB;
        }

        if ($lenB === 0) {
            return $lenA;
        }

        $matrix = range(0, $lenB);

        for ($i = 0; $i < $lenA; $i++) {
            $prev = $matrix[0];
            $matrix[0] = $i + 1;

            for ($j = 0; $j < $lenB; $j++) {
                $temp = $matrix[$j + 1];
                $cost = mb_substr($a, $i, 1) === mb_substr($b, $j, 1) ? 0 : 1;
                $matrix[$j + 1] = min(
                    $matrix[$j + 1] + 1,
                    $matrix[$j] + 1,
                    $prev + $cost
                );
                $prev = $temp;
            }
        }

        return $matrix[$lenB];
    }
}
