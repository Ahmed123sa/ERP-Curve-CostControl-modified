<?php

namespace App\Services\MenuEngineering;

use App\Models\ItemMapping;
use App\Models\MenuEngineering\MenuRecipe;
use Illuminate\Support\Collection;

class MenuMatchingService
{
    const HALF_KEYWORDS = ['50/50', '50%', 'نص', 'نصف', 'half'];
    const SIZE_KEYWORDS = ['كبير', 'وسط', 'صغير'];

    public function findRecipe(
        string $name,
        string $sizeVal,
        Collection $recipes,
        array $exactMap,
        array $sizeMap,
        array $allNames,
    ): ?array {
        $norm = $this->normalize($name);

        if (!empty($sizeVal) && in_array($sizeVal, self::SIZE_KEYWORDS)) {
            if (isset($sizeMap[$norm][$sizeVal])) {
                $rid = $sizeMap[$norm][$sizeVal];
                $r = $recipes->firstWhere('id', $rid);
                return ['id' => $rid, 'name' => $r?->name ?? $name, 'confidence' => 98];
            }
            $withSize = $norm . ' ' . $sizeVal;
            if (isset($exactMap[$withSize])) {
                return ['id' => $exactMap[$withSize], 'name' => $name, 'confidence' => 98];
            }
        }

        if (isset($exactMap[$norm])) {
            return ['id' => $exactMap[$norm], 'name' => $name, 'confidence' => 100];
        }

        $bestContainsId = null;
        $bestContainsLen = 0;
        $isNameContainsRecipe = false;
        foreach ($allNames as $rid => $rNorm) {
            if (!empty($sizeVal) && $this->getSizeSuffix($rNorm) !== null && $this->getSizeSuffix($rNorm) !== $sizeVal) {
                continue;
            }
            if (mb_strpos($norm, $rNorm) !== false && mb_strlen($rNorm) > $bestContainsLen) {
                $bestContainsId = $rid;
                $bestContainsLen = mb_strlen($rNorm);
                $isNameContainsRecipe = true;
            }
        }
        if (!$bestContainsId) {
            foreach ($allNames as $rid => $rNorm) {
                if (!empty($sizeVal) && $this->getSizeSuffix($rNorm) !== null && $this->getSizeSuffix($rNorm) !== $sizeVal) {
                    continue;
                }
                if (mb_strpos($rNorm, $norm) !== false && mb_strlen($rNorm) > $bestContainsLen) {
                    $bestContainsId = $rid;
                    $bestContainsLen = mb_strlen($rNorm);
                    $isNameContainsRecipe = false;
                }
            }
        }
        if ($bestContainsId) {
            $r = $recipes->firstWhere('id', $bestContainsId);
            return [
                'id' => $bestContainsId,
                'name' => $r?->name ?? $name,
                'confidence' => $isNameContainsRecipe ? 85 : 80,
            ];
        }

        $bestId = null;
        $bestScore = 0;
        foreach ($allNames as $rid => $rNorm) {
            if (!empty($sizeVal) && $this->getSizeSuffix($rNorm) !== null && $this->getSizeSuffix($rNorm) !== $sizeVal) {
                continue;
            }
            similar_text($norm, $rNorm, $pct);
            if ($pct >= 80 && $pct > $bestScore) {
                $bestScore = $pct;
                $bestId = $rid;
            }
        }

        if ($bestId) {
            $r = $recipes->firstWhere('id', $bestId);
            return [
                'id' => $bestId,
                'name' => $r?->name ?? $name,
                'confidence' => (int) round($bestScore),
            ];
        }

        return null;
    }

    private function getSizeSuffix(string $norm): ?string
    {
        $parts = explode(' ', trim($norm));
        if (count($parts) >= 2) {
            $last = end($parts);
            if (in_array($last, self::SIZE_KEYWORDS)) {
                return $last;
            }
        }
        return null;
    }

    public function findSavedMapping(string $clientId, string $sourceName, string $sizeVal = ''): ?array
    {
        $mapping = ItemMapping::where('client_id', $clientId)
            ->where('source_name', $sourceName)
            ->where('context', 'menu_engineering')
            ->orderByDesc('confidence')
            ->first();

        if ($mapping && $mapping->item_id) {
            $recipe = MenuRecipe::find($mapping->item_id);
            if ($recipe) {
                // If the mapped recipe has a size suffix that differs from requested size, skip
                if (!empty($sizeVal)) {
                    $recipeNorm = $this->normalize($recipe->name);
                    $recipeSize = $this->getSizeSuffix($recipeNorm);
                    if ($recipeSize !== null && $recipeSize !== $sizeVal) {
                        return null;
                    }
                }
                $mapping->increment('usage_count');
                return [
                    'recipe_id' => $recipe->id,
                    'recipe_name' => $recipe->name,
                    'confidence' => $mapping->confidence,
                ];
            }
            $mapping->delete();
        }

        return null;
    }

    public function saveMapping(string $clientId, string $sourceName, string $recipeId, int $confidence = 100): void
    {
        ItemMapping::updateOrCreate(
            ['client_id' => $clientId, 'source_name' => $sourceName, 'context' => 'menu_engineering'],
            ['item_id' => $recipeId, 'confidence' => $confidence]
        );
    }

    public function buildMatchingMaps(Collection $recipes): array
    {
        $exactMap = [];
        $sizeMap = [];
        $allNames = [];

        foreach ($recipes as $r) {
            $norm = $this->normalize($r->name);
            $exactMap[$norm] = $r->id;
            $allNames[$r->id] = $norm;

            $parts = explode(' ', trim($r->name));
            if (count($parts) >= 2) {
                $last = end($parts);
                if (in_array($last, self::SIZE_KEYWORDS)) {
                    $base = $this->normalize(mb_substr(trim($r->name), 0, -(mb_strlen($last) + 1)));
                    $sizeMap[$base][$last] = $r->id;
                }
            }
        }

        return [$exactMap, $sizeMap, $allNames];
    }

    public function isHalfCategory(string $category): bool
    {
        foreach (self::HALF_KEYWORDS as $kw) {
            if (mb_stripos($category, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    public function stripHalfPrefix(string $name): string
    {
        $prefix = 'نص';
        if (mb_stripos($name, $prefix) === 0) {
            return trim(mb_substr($name, mb_strlen($prefix)));
        }
        return $name;
    }

    public function normalize(string $str): string
    {
        $str = mb_strtolower(trim($str), 'UTF-8');
        $str = str_replace(['أ', 'إ', 'آ'], 'ا', $str);
        $str = str_replace('ة', 'ه', $str);
        $str = str_replace(['ى', 'ئ', 'ؤ'], 'ي', $str);
        // Strip Arabic definite article 'ال' for broader matching (صنف ↔ الصنف)
        $str = preg_replace('/^ال/u', '', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    }
}
