<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemMapping;
use App\Models\LocationMapping;
use App\Models\Warehouse;
use App\Models\Branch;
use Illuminate\Support\Str;

/**
 * MappingService
 * نفس فكرة mapping_engine.py بس في Laravel
 * بيربط أسماء الأصناف في الأذون بالأصناف الحقيقية في الـ DB
 * وبيتذكر كل ربط سابق
 */
class MappingService
{
    // ── Item Mapping ─────────────────────────────────────────

    /**
     * ابحث عن الصنف المطابق لاسم معين
     * الأولوية: 1) DB مباشرة  2) ذاكرة الربط  3) تطابق تلقائي
     *
     * @return array{item_id: string|null, confidence: int, needs_review: bool}
     */
    public function findItem(string $clientId, string $sourceName, ?string $context = null): array
    {
        $normalized = $this->normalize($sourceName);

        // 1. بحث في ذاكرة الربط المحفوظة
        $mapping = ItemMapping::where('client_id', $clientId)
            ->where('source_name', $sourceName)
            ->where(fn($q) => $q->where('context', $context)->orWhereNull('context'))
            ->orderByDesc('confidence')
            ->first();

        if ($mapping) {
            if ($mapping->item) {
                $mapping->increment('usage_count');
                return [
                    'item_id'      => $mapping->item_id,
                    'item_name'    => $mapping->item->name,
                    'confidence'   => $mapping->confidence,
                    'needs_review' => false,
                ];
            } else {
                // The item was deleted, so this mapping is stale.
                $mapping->delete();
            }
        }

        // 2. تطابق مباشر بالاسم
        $item = Item::where('client_id', $clientId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($sourceName)])
            ->first();

        if ($item) {
            $this->saveItemMapping($clientId, $sourceName, $item->id, $context, 100);
            return ['item_id' => $item->id, 'item_name' => $item->name, 'confidence' => 100, 'needs_review' => false];
        }

        // 3. تطابق بعد التنظيف (إزالة الفراغات، توحيد الحروف)
        $items = Item::where('client_id', $clientId)->get();
        foreach ($items as $item) {
            if ($this->normalize($item->name) === $normalized) {
                $this->saveItemMapping($clientId, $sourceName, $item->id, $context, 95);
                return ['item_id' => $item->id, 'item_name' => $item->name, 'confidence' => 95, 'needs_review' => false];
            }
        }

        // 4. تطابق جزئي — بس بيحتاج مراجعة بشرية
        $bestMatch = null;
        $bestScore = 0;
        foreach ($items as $item) {
            $score = $this->similarityScore($normalized, $this->normalize($item->name));
            if ($score > $bestScore && $score >= 0.7) {
                $bestScore = $score;
                $bestMatch = $item;
            }
        }

        if ($bestMatch) {
            return [
                'item_id'      => $bestMatch->id,
                'item_name'    => $bestMatch->name,
                'confidence'   => (int) ($bestScore * 100),
                'needs_review' => true, // لازم يوافق المستخدم
            ];
        }

        // 5. مش لاقي — محتاج يضيفه يدوي
        return ['item_id' => null, 'item_name' => null, 'confidence' => 0, 'needs_review' => true];
    }

    /**
     * احفظ ربط صنف — بعد تأكيد المستخدم
     */
    public function saveItemMapping(
        string $clientId,
        string $sourceName,
        string $itemId,
        ?string $context = null,
        int $confidence = 100
    ): void {
        ItemMapping::updateOrCreate(
            ['client_id' => $clientId, 'source_name' => $sourceName, 'context' => $context],
            ['item_id' => $itemId, 'confidence' => $confidence]
        );
    }

    // ── Location Mapping (مخازن وفروع) ──────────────────────

    /**
     * ابحث عن المخزن أو الفرع المطابق لاسم في الإذن
     * مثال: "وارد مخزن" → warehouse "مخزن مركزي"
     *        "ماي بروست" → branch "ماي بروست"
     */
    public function findLocation(string $clientId, string $sourceName): array
    {
        // 1. ذاكرة الربط
        $mapping = LocationMapping::where('client_id', $clientId)
            ->where('source_name', $sourceName)
            ->first();

        if ($mapping) {
            return [
                'type'         => $mapping->target_type,
                'id'           => $mapping->target_id,
                'confidence'   => $mapping->confidence,
                'needs_review' => false,
            ];
        }

        $normalized = $this->normalize($sourceName);

        // 2. بحث في المخازن
        $warehouses = Warehouse::where('client_id', $clientId)->get();
        foreach ($warehouses as $wh) {
            $whNorm = $this->normalize($wh->name);
            if ($whNorm === $normalized) {
                $this->saveLocationMapping($clientId, $sourceName, 'warehouse', $wh->id, 100);
                return ['type' => 'warehouse', 'id' => $wh->id, 'confidence' => 100, 'needs_review' => false];
            }
            if (str_contains($normalized, $whNorm) && mb_strlen($whNorm) > 4) {
                $this->saveLocationMapping($clientId, $sourceName, 'warehouse', $wh->id, 90);
                return ['type' => 'warehouse', 'id' => $wh->id, 'confidence' => 90, 'needs_review' => false];
            }
        }

        // 3. بحث في الفروع
        $branches = Branch::where('client_id', $clientId)->get();
        foreach ($branches as $br) {
            $brNorm = $this->normalize($br->name);
            if ($brNorm === $normalized) {
                $this->saveLocationMapping($clientId, $sourceName, 'branch', $br->id, 100);
                return ['type' => 'branch', 'id' => $br->id, 'confidence' => 100, 'needs_review' => false];
            }
            if (str_contains($normalized, $brNorm) && mb_strlen($brNorm) > 4) {
                $this->saveLocationMapping($clientId, $sourceName, 'branch', $br->id, 90);
                return ['type' => 'branch', 'id' => $br->id, 'confidence' => 90, 'needs_review' => false];
            }
        }

        // 4. تطابق جزئي (مخازن)
        $bestMatch = null;
        $bestScore = 0;
        $bestType  = null;
        foreach ($warehouses as $wh) {
            $score = $this->similarityScore($normalized, $this->normalize($wh->name));
            if ($score > $bestScore && $score >= 0.65) {
                $bestScore = $score;
                $bestMatch = $wh;
                $bestType  = 'warehouse';
            }
        }
        foreach ($branches as $br) {
            $score = $this->similarityScore($normalized, $this->normalize($br->name));
            if ($score > $bestScore && $score >= 0.65) {
                $bestScore = $score;
                $bestMatch = $br;
                $bestType  = 'branch';
            }
        }

        if ($bestMatch && $bestType) {
            return [
                'type'         => $bestType,
                'id'           => $bestMatch->id,
                'name'         => $bestMatch->name,
                'confidence'   => (int) ($bestScore * 100),
                'needs_review' => true,
            ];
        }

        return ['type' => null, 'id' => null, 'confidence' => 0, 'needs_review' => true];
    }

    public function saveLocationMapping(
        string $clientId,
        string $sourceName,
        string $targetType,
        string $targetId,
        int $confidence = 100
    ): void {
        LocationMapping::updateOrCreate(
            ['client_id' => $clientId, 'source_name' => $sourceName],
            ['target_type' => $targetType, 'target_id' => $targetId, 'confidence' => $confidence]
        );
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * تنظيف النص — إزالة الفراغات الزيادة، توحيد الأرقام
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        // Arabic digits → English
        $text = strtr($text, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        // توحيد الهمزات
        $text = strtr($text, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ة'=>'ه','ى'=>'ي','ؤ'=>'و','ئ'=>'ي']);
        // إزالة الفراغات الزيادة
        return preg_replace('/\s+/', ' ', $text);
    }

    /**
     * حساب درجة التشابه بين نصين (0 → 1)
     * مبني على Levenshtein distance مع تعديل للعربي
     */
    private function similarityScore(string $a, string $b): float
    {
        if ($a === $b) return 1.0;
        if (empty($a) || empty($b)) return 0.0;

        // تطابق جزئي — لو أحدهم جزء من التاني
        if (str_contains($b, $a) || str_contains($a, $b)) {
            return 0.85;
        }

        similar_text($a, $b, $percent);
        return $percent / 100;
    }

    /**
     * جيب كل الربط المحفوظ للعميل (للعرض في إعدادات الـ mapping)
     */
    public function getMappingsForClient(string $clientId): array
    {
        return [
            'items'     => ItemMapping::where('client_id', $clientId)->with('item')->get(),
            'locations' => LocationMapping::where('client_id', $clientId)->get(),
        ];
    }
}
