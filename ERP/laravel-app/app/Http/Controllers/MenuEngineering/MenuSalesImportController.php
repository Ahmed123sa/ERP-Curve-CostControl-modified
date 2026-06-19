<?php

namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuImportSession;
use App\Models\MenuEngineering\MenuRecipe;
use App\Models\MenuEngineering\MenuSale;
use App\Services\MenuEngineering\MenuMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MenuSalesImportController extends Controller
{
    const NAME_COL = 38;

    const QTY_COL = 10;

    const SIZE_COL = 28;

    const CAT_COL = 32;

    const HEADER_ROWS = 9;

    const HEADER_KEYWORDS = [
        'name' => ['الاسم', 'اسم', 'الصنف', 'صنف', 'البيان', 'name', 'item', 'product'],
        'qty' => ['العدد', 'عدد', 'الكمية', 'كمية', 'qty', 'quantity', 'count'],
        'size' => ['الحجم', 'حجم', 'size', 'الوزن', 'وزن'],
        'category' => ['التصنيف', 'تصنيف', 'الكاتيجوري', 'كاتيجوري', 'category', 'القسم', 'قسم', 'type'],
    ];

    public function __construct(
        protected MenuMatchingService $matchingService,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
            'branch_id' => 'required|string',
            'sale_date' => 'required|date',
        ]);

        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id;

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);

        $recipes = MenuRecipe::where('client_id', $clientId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->get(['id', 'name', 'category']);

        [$exactMap, $sizeMap, $allNames] = $this->matchingService->buildMatchingMaps($recipes);

        // Try auto-detection first; fall back to hardcoded constants if invalid
        $analysis = $this->analyzeColumns($rows);
        $detected = $analysis['suggested_mapping'];
        $columns = $analysis['columns'];
        $colByIndex = [];
        foreach ($columns as $c) {
            $colByIndex[$c['index']] = $c;
        }

        $useDetected = false;
        if ($detected['name_col'] !== null && isset($colByIndex[$detected['name_col']])) {
            $nameColInfo = $colByIndex[$detected['name_col']];
            if ($nameColInfo['type'] === 'text') {
                $hasNameLike = false;
                foreach ($nameColInfo['sample_values'] ?? [] as $sv) {
                    $svClean = str_replace(',', '', $sv);
                    if (! is_numeric($svClean) && preg_match('/\p{L}/u', $svClean)) {
                        $hasNameLike = true;
                        break;
                    }
                }
                $useDetected = $hasNameLike;
            }
        }

        if ($useDetected) {
            $nameCol = (int) $detected['name_col'];
            $qtyCol = (int) ($detected['qty_col'] ?? 0);
            $sizeCol = isset($detected['size_col']) && $detected['size_col'] !== null ? (int) $detected['size_col'] : null;
            $catCol = isset($detected['cat_col']) && $detected['cat_col'] !== null ? (int) $detected['cat_col'] : null;
            $headerRows = (int) ($detected['header_rows'] ?? 0);
        } else {
            $nameCol = self::NAME_COL;
            $qtyCol = self::QTY_COL;
            $sizeCol = self::SIZE_COL;
            $catCol = self::CAT_COL;
            $headerRows = self::HEADER_ROWS;
        }

        $matchedItems = [];
        $unmatchedRows = [];
        $currentCat = '';
        $seenCats = [];
        $halfCategories = [];

        foreach ($rows as $rIdx => $row) {
            $catVal = $catCol !== null ? trim($row[$catCol] ?? '') : '';
            if (! empty($catVal)) {
                $currentCat = $catVal;
                if (! isset($seenCats[$currentCat])) {
                    $seenCats[$currentCat] = true;
                    $halfCategories[$currentCat] = $this->matchingService->isHalfCategory($currentCat);
                }
            }

            if ($rIdx < $headerRows) {
                continue;
            }

            $name = trim($row[$nameCol] ?? '');
            $qty = (float) ($row[$qtyCol] ?? 0);
            $sizeVal = $sizeCol !== null ? trim($row[$sizeCol] ?? '') : '';

            if (empty($name) || $qty <= 0) {
                continue;
            }

            $matchName = $name;
            if (! empty($currentCat) && ! empty($halfCategories[$currentCat])) {
                $matchName = $this->matchingService->stripHalfPrefix($matchName);
            }

            // Try saved mapping first
            $savedMapping = $this->matchingService->findSavedMapping($clientId, $name, $sizeVal);
            if ($savedMapping) {
                $rid = $savedMapping['recipe_id'];
                $compositeKey = $rid.'|'.$currentCat;
                if (isset($matchedItems[$compositeKey])) {
                    $matchedItems[$compositeKey]['qty_sold'] += $qty;
                } else {
                    $matchedItems[$compositeKey] = [
                        'recipe_id' => $savedMapping['recipe_id'],
                        'recipe_name' => $savedMapping['recipe_name'],
                        'source_name' => $name,
                        'qty_sold' => $qty,
                        'category' => $currentCat,
                        'size' => $sizeVal,
                        'confidence' => $savedMapping['confidence'],
                    ];
                }

                continue;
            }

            $matched = $this->matchingService->findRecipe(
                name: $matchName,
                sizeVal: $sizeVal,
                recipes: $recipes,
                exactMap: $exactMap,
                sizeMap: $sizeMap,
                allNames: $allNames,
            );

            if ($matched) {
                $rid = $matched['id'];
                $compositeKey = $rid.'|'.$currentCat;
                if (isset($matchedItems[$compositeKey])) {
                    $matchedItems[$compositeKey]['qty_sold'] += $qty;
                } else {
                    $matchedItems[$compositeKey] = [
                        'recipe_id' => $rid,
                        'recipe_name' => $matched['name'],
                        'source_name' => $name,
                        'qty_sold' => $qty,
                        'category' => $currentCat,
                        'size' => $sizeVal,
                        'confidence' => $matched['confidence'],
                    ];
                }
            } else {
                $unmatchedKey = $name.'|'.$sizeVal.'|'.$currentCat;
                if (isset($unmatchedRows[$unmatchedKey])) {
                    $unmatchedRows[$unmatchedKey]['qty_sold'] += $qty;
                } else {
                    $unmatchedRows[$unmatchedKey] = [
                        'source_name' => $name,
                        'qty_sold' => $qty,
                        'category' => $currentCat,
                        'size' => $sizeVal,
                    ];
                }
            }
        }

        $categories = [];
        foreach (array_keys($seenCats) as $cat) {
            $categories[] = [
                'name' => $cat,
                'half' => $halfCategories[$cat] ?? false,
            ];
        }

        $preview = array_values($matchedItems);

        return response()->json([
            'preview' => $preview,
            'unmatched' => array_values($unmatchedRows),
            'matched_count' => count($preview),
            'unmatched_count' => count($unmatchedRows),
            'total_data_rows' => count($rows) - $headerRows,
            'all_recipes' => $recipes->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'categories' => $categories,
        ]);
    }

    public function previewColumns(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            return response()->json([
                'columns' => [],
                'suggested_mapping' => null,
                'total_rows' => 0,
                'sample_rows' => [],
            ]);
        }

        $totalRows = count($rows);
        $analysis = $this->analyzeColumns($rows);
        $columns = $analysis['columns'];
        $suggestedMapping = $analysis['suggested_mapping'];
        $dataStart = $analysis['header_rows'];

        $sampleRows = [];
        for ($i = $dataStart; $i < min($dataStart + 5, $totalRows); $i++) {
            $sampleRows[] = $rows[$i] ?? [];
        }

        return response()->json([
            'columns' => $columns,
            'suggested_mapping' => $suggestedMapping,
            'total_rows' => $totalRows,
            'header_row_idx' => $dataStart - 1,
            'sample_rows' => $sampleRows,
        ]);
    }

    public function process(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
            'branch_id' => 'required|string',
            'sale_date' => 'required|date',
            'mapping' => 'required|array',
            'mapping.name_col' => 'required|integer|min:0',
            'mapping.qty_col' => 'required|integer|min:0',
            'mapping.size_col' => 'nullable|integer|min:0',
            'mapping.cat_col' => 'nullable|integer|min:0',
            'mapping.header_rows' => 'required|integer|min:0',
        ]);

        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id;
        $mapping = $request->mapping;

        $nameCol = (int) $mapping['name_col'];
        $qtyCol = (int) $mapping['qty_col'];
        $sizeCol = isset($mapping['size_col']) && $mapping['size_col'] !== null ? (int) $mapping['size_col'] : null;
        $catCol = isset($mapping['cat_col']) && $mapping['cat_col'] !== null ? (int) $mapping['cat_col'] : null;
        $headerRows = (int) $mapping['header_rows'];

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);

        $recipes = MenuRecipe::where('client_id', $clientId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->get(['id', 'name', 'category']);

        [$exactMap, $sizeMap, $allNames] = $this->matchingService->buildMatchingMaps($recipes);

        $matchedItems = [];
        $unmatchedRows = [];
        $currentCat = '';
        $seenCats = [];
        $halfCategories = [];

        foreach ($rows as $rIdx => $row) {
            // Skip header rows
            if ($rIdx < $headerRows) {
                continue;
            }

            // Category detection from catCol if specified
            if ($catCol !== null) {
                $catVal = trim($row[$catCol] ?? '');
                if (! empty($catVal)) {
                    $currentCat = $catVal;
                    if (! isset($seenCats[$currentCat])) {
                        $seenCats[$currentCat] = true;
                        $halfCategories[$currentCat] = $this->matchingService->isHalfCategory($currentCat);
                    }
                }
            }

            $name = trim($row[$nameCol] ?? '');
            $qty = (float) ($row[$qtyCol] ?? 0);
            $sizeVal = $sizeCol !== null ? trim($row[$sizeCol] ?? '') : '';

            if (empty($name) || $qty <= 0) {
                continue;
            }

            $matchName = $name;
            if (! empty($currentCat) && ! empty($halfCategories[$currentCat])) {
                $matchName = $this->matchingService->stripHalfPrefix($matchName);
            }

            // Try saved mapping first
            $savedMapping = $this->matchingService->findSavedMapping($clientId, $name, $sizeVal);
            if ($savedMapping) {
                $rid = $savedMapping['recipe_id'];
                $compositeKey = $rid.'|'.$currentCat;
                if (isset($matchedItems[$compositeKey])) {
                    $matchedItems[$compositeKey]['qty_sold'] += $qty;
                } else {
                    $matchedItems[$compositeKey] = [
                        'recipe_id' => $rid,
                        'recipe_name' => $savedMapping['recipe_name'],
                        'source_name' => $name,
                        'qty_sold' => $qty,
                        'category' => $currentCat,
                        'size' => $sizeVal,
                        'confidence' => $savedMapping['confidence'],
                    ];
                }

                continue;
            }

            $matched = $this->matchingService->findRecipe(
                name: $matchName,
                sizeVal: $sizeVal,
                recipes: $recipes,
                exactMap: $exactMap,
                sizeMap: $sizeMap,
                allNames: $allNames,
            );

            if ($matched) {
                $rid = $matched['id'];
                $compositeKey = $rid.'|'.$currentCat;
                if (isset($matchedItems[$compositeKey])) {
                    $matchedItems[$compositeKey]['qty_sold'] += $qty;
                } else {
                    $matchedItems[$compositeKey] = [
                        'recipe_id' => $rid,
                        'recipe_name' => $matched['name'],
                        'source_name' => $name,
                        'qty_sold' => $qty,
                        'category' => $currentCat,
                        'size' => $sizeVal,
                        'confidence' => $matched['confidence'],
                    ];
                }
            } else {
                $unmatchedKey = $name.'|'.$sizeVal.'|'.$currentCat;
                if (isset($unmatchedRows[$unmatchedKey])) {
                    $unmatchedRows[$unmatchedKey]['qty_sold'] += $qty;
                } else {
                    $unmatchedRows[$unmatchedKey] = [
                        'source_name' => $name,
                        'qty_sold' => $qty,
                        'category' => $currentCat,
                        'size' => $sizeVal,
                    ];
                }
            }
        }

        $categories = [];
        foreach (array_keys($seenCats) as $cat) {
            $categories[] = [
                'name' => $cat,
                'half' => $halfCategories[$cat] ?? false,
            ];
        }

        $preview = array_values($matchedItems);

        // Save session for persistence across page refreshes
        $session = MenuImportSession::create([
            'client_id' => $clientId,
            'branch_id' => $branchId,
            'sale_date' => $request->sale_date,
            'file_name' => $file->getClientOriginalName(),
            'total_rows' => count($rows) - $headerRows,
            'status' => 'pending',
            'half_categories' => $halfCategories,
            'expires_at' => now()->addHours(24),
        ]);

        $sessionItems = [];
        foreach ($preview as $item) {
            $sessionItems[] = [
                'session_id' => $session->id,
                'row_index' => 0,
                'source_name' => $item['source_name'],
                'qty_sold' => $item['qty_sold'],
                'category' => $item['category'],
                'size' => $item['size'] ?? '',
                'recipe_id' => $item['recipe_id'],
                'recipe_name' => $item['recipe_name'],
                'status' => 'matched',
                'confidence' => $item['confidence'],
            ];
        }
        foreach ($unmatchedRows as $item) {
            $sessionItems[] = [
                'session_id' => $session->id,
                'row_index' => 0,
                'source_name' => $item['source_name'],
                'qty_sold' => $item['qty_sold'],
                'category' => $item['category'],
                'size' => $item['size'] ?? '',
                'recipe_id' => null,
                'recipe_name' => null,
                'status' => 'unmatched',
                'confidence' => 0,
            ];
        }
        $session->items()->createMany($sessionItems);

        return response()->json([
            'session_id' => $session->id,
            'preview' => $preview,
            'unmatched' => array_values($unmatchedRows),
            'matched_count' => count($preview),
            'unmatched_count' => count($unmatchedRows),
            'total_data_rows' => count($rows) - $headerRows,
            'all_recipes' => $recipes->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'categories' => $categories,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $request->validate([
            'branch_id' => 'required|string',
            'sale_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.recipe_id' => [
                'required', 'string',
                Rule::exists('menu_engineering_recipes', 'id')->where('client_id', $clientId),
            ],
            'items.*.qty_sold' => 'required|numeric|min:0',
            'items.*.category' => 'nullable|string',
            'items.*.source_name' => 'nullable|string',
            'half_categories' => 'nullable|array',
            'half_categories.*' => 'boolean',
        ]);
        $branchId = $request->branch_id;
        $saleDate = $request->sale_date;
        $halfCategories = $request->half_categories ?? [];

        $count = 0;
        foreach ($request->items as $item) {
            $qty = (float) $item['qty_sold'];
            $cat = $item['category'] ?? '';
            if (! empty($cat) && ! empty($halfCategories[$cat])) {
                $qty = round($qty / 2, 3);
            }

            $recipe = MenuRecipe::find($item['recipe_id']);
            MenuSale::create([
                'client_id' => $clientId,
                'branch_id' => $branchId,
                'recipe_id' => $item['recipe_id'],
                'qty_sold' => $qty,
                'selling_price' => $recipe?->selling_price ?? 0,
                'sale_date' => $saleDate,
            ]);
            $count++;

            // Save persistent mapping for future auto-matching
            if (! empty($item['source_name']) && ! empty($item['recipe_id'])) {
                $this->matchingService->saveMapping(
                    clientId: $clientId,
                    sourceName: $item['source_name'],
                    recipeId: $item['recipe_id'],
                    confidence: 100
                );
            }
        }

        return response()->json([
            'message' => "تم حفظ {$count} مبيعات بنجاح",
            'count' => $count,
        ]);
    }

    public function getSession(string $sessionId): JsonResponse
    {
        $session = MenuImportSession::with('items')->findOrFail($sessionId);

        $preview = $session->items->whereIn('status', ['matched', 'linked'])->values()->toArray();
        $unmatched = $session->items->where('status', 'unmatched')->values()->toArray();

        $recipes = MenuRecipe::where('client_id', request()->user()->current_client_id)
            ->where('status', 'active')
            ->get(['id', 'name']);

        $categories = [];
        if (! empty($session->half_categories)) {
            foreach ($session->half_categories as $cat => $isHalf) {
                $categories[] = ['name' => $cat, 'half' => $isHalf];
            }
        }

        return response()->json([
            'session_id' => $session->id,
            'preview' => $preview,
            'unmatched' => $unmatched,
            'matched_count' => count($preview),
            'unmatched_count' => count($unmatched),
            'total_data_rows' => $session->total_rows,
            'all_recipes' => $recipes->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'categories' => $categories,
        ]);
    }

    public function updateSessionItem(Request $request, string $sessionId, string $itemId): JsonResponse
    {
        $item = MenuImportSessionItem::where('session_id', $sessionId)->findOrFail($itemId);

        $clientId = $request->user()->current_client_id;

        $request->validate([
            'recipe_id' => [
                'nullable', 'string',
                Rule::exists('menu_engineering_recipes', 'id')->where('client_id', $clientId),
            ],
        ]);

        $recipeId = $request->recipe_id;
        $item->recipe_id = $recipeId;
        $item->status = $recipeId ? 'linked' : 'unmatched';

        if ($recipeId) {
            $recipe = MenuRecipe::find($recipeId);
            $item->recipe_name = $recipe?->name;
        } else {
            $item->recipe_name = null;
        }

        $item->save();

        return response()->json(['item' => $item->fresh()]);
    }

    public function confirmFromSession(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string|exists:menu_import_sessions,id',
            'half_categories' => 'nullable|array',
            'half_categories.*' => 'boolean',
        ]);

        $clientId = $request->user()->current_client_id;
        $session = MenuImportSession::with('items')->findOrFail($request->session_id);

        $halfCategories = $request->half_categories ?? $session->half_categories ?? [];
        $count = 0;

        foreach ($session->items->whereNotNull('recipe_id') as $item) {
            $qty = (float) $item->qty_sold;
            $cat = $item->category ?? '';
            if (! empty($cat) && ! empty($halfCategories[$cat])) {
                $qty = round($qty / 2, 3);
            }

            $recipe = MenuRecipe::find($item->recipe_id);
            MenuSale::create([
                'client_id' => $clientId,
                'branch_id' => $session->branch_id,
                'recipe_id' => $item->recipe_id,
                'qty_sold' => $qty,
                'selling_price' => $recipe?->selling_price ?? 0,
                'sale_date' => $session->sale_date,
            ]);
            $count++;

            if (! empty($item->source_name) && ! empty($item->recipe_id)) {
                $this->matchingService->saveMapping(
                    clientId: $clientId,
                    sourceName: $item->source_name,
                    recipeId: $item->recipe_id,
                    confidence: 100
                );
            }
        }

        $session->status = 'confirmed';
        $session->save();

        return response()->json([
            'message' => "تم حفظ {$count} مبيعات بنجاح",
            'count' => $count,
        ]);
    }

    public function deleteSession(string $sessionId): JsonResponse
    {
        $session = MenuImportSession::findOrFail($sessionId);
        $session->delete();

        return response()->json(['message' => 'تم حذف الجلسة']);
    }

    private function analyzeColumns(array $rows): array
    {
        $totalRows = count($rows);
        $maxCols = 0;
        foreach ($rows as $row) {
            $maxCols = max($maxCols, count($row));
        }

        $scanDepth = min(30, $totalRows);
        $colCandidates = array_fill(0, $maxCols, []);
        $rowScores = array_fill(0, $scanDepth, 0);

        $allKeywords = [];
        foreach (self::HEADER_KEYWORDS as $field => $kws) {
            foreach ($kws as $kw) {
                $norm = $this->matchingService->normalize($kw);
                $allKeywords[$norm] = $field;
            }
        }

        for ($col = 0; $col < $maxCols; $col++) {
            for ($i = 0; $i < $scanDepth; $i++) {
                if (isset($rows[$i][$col])) {
                    $val = trim((string) $rows[$i][$col]);
                    if (! empty($val)) {
                        $colCandidates[$col][] = $val;
                        $normVal = $this->matchingService->normalize($val);
                        if (isset($allKeywords[$normVal])) {
                            $rowScores[$i] += 10;
                        } else {
                            foreach ($allKeywords as $kwNorm => $field) {
                                if (mb_strpos($normVal, $kwNorm) !== false) {
                                    $rowScores[$i] += 5;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        $headerRowIdx = 0;
        $bestScore = 0;
        foreach ($rowScores as $i => $score) {
            if ($score > $bestScore) {
                $bestScore = $score;
                $headerRowIdx = $i;
            }
        }

        $columns = [];
        for ($col = 0; $col < $maxCols; $col++) {
            $seen = [];
            $candidates = [];
            foreach ($colCandidates[$col] as $v) {
                $norm = $this->matchingService->normalize($v);
                if (! isset($seen[$norm])) {
                    $seen[$norm] = true;
                    $candidates[] = $v;
                }
            }

            $header = isset($rows[$headerRowIdx][$col]) ? trim((string) $rows[$headerRowIdx][$col]) : '';
            if (empty($header)) {
                for ($d = 1; $d <= 3; $d++) {
                    $up = $headerRowIdx - $d;
                    $down = $headerRowIdx + $d;
                    if ($up >= 0 && ! empty(trim((string) ($rows[$up][$col] ?? '')))) {
                        $header = trim((string) $rows[$up][$col]);
                        break;
                    }
                    if ($down < $totalRows && ! empty(trim((string) ($rows[$down][$col] ?? '')))) {
                        $header = trim((string) $rows[$down][$col]);
                        break;
                    }
                }
            }
            if (empty($header) && ! empty($candidates)) {
                $header = $candidates[0];
            }

            $sampleValues = [];
            $numericCount = 0;
            $textCount = 0;

            $dataStart = $headerRowIdx + 1;
            for ($i = $dataStart; $i < min($dataStart + 10, $totalRows); $i++) {
                if (isset($rows[$i][$col])) {
                    $val = trim((string) $rows[$i][$col]);
                    if (! empty($val)) {
                        $sampleValues[] = mb_substr($val, 0, 50);
                        $cleanVal = str_replace(',', '', $val);
                        if (is_numeric($cleanVal)) {
                            $numericCount++;
                        } else {
                            $textCount++;
                        }
                    }
                }
            }

            if ($textCount > $numericCount && $textCount > 0) {
                $type = 'text';
            } elseif ($numericCount > 0) {
                $type = 'numeric';
            } else {
                $type = 'blank';
            }

            $columns[] = [
                'index' => $col,
                'header' => $header,
                'normalized' => $this->matchingService->normalize($header),
                'sample_values' => array_slice($sampleValues, 0, 5),
                'candidates' => array_slice($candidates, 0, 5),
                'type' => $type,
            ];
        }

        $suggestedMapping = $this->autoDetectMapping($columns);
        $dataStart = $headerRowIdx + 1;
        if ($dataStart < 1) {
            $dataStart = 1;
        }
        $suggestedMapping['header_rows'] = $dataStart;

        return [
            'columns' => $columns,
            'suggested_mapping' => $suggestedMapping,
            'header_rows' => $dataStart,
        ];
    }

    private function autoDetectMapping(array $columns): array
    {
        $mapping = [
            'name_col' => null,
            'qty_col' => null,
            'size_col' => null,
            'cat_col' => null,
            'header_rows' => 0,
        ];

        $keywordScores = [];

        foreach ($columns as $col) {
            $type = $col['type'];
            // Scan header AND candidates for keywords
            $allHeaders = [$col['normalized'] ?? ''];
            foreach (($col['candidates'] ?? []) as $candidate) {
                $allHeaders[] = $this->matchingService->normalize($candidate);
            }
            $allHeaders = array_unique(array_filter($allHeaders));

            foreach (self::HEADER_KEYWORDS as $field => $keywords) {
                foreach ($keywords as $kw) {
                    $kwNorm = $this->matchingService->normalize($kw);
                    foreach ($allHeaders as $h) {
                        if ($h === $kwNorm) {
                            $keywordScores[$field][$col['index']] = max(
                                $keywordScores[$field][$col['index']] ?? 0,
                                10
                            );
                        } elseif (mb_strpos($h, $kwNorm) !== false || mb_strpos($kwNorm, $h) !== false) {
                            $keywordScores[$field][$col['index']] = max(
                                $keywordScores[$field][$col['index']] ?? 0,
                                5
                            );
                        }
                    }
                }
            }

            // Type-based scoring fallback
            $nameOrCatMatch = isset($keywordScores['name'][$col['index']]) || isset($keywordScores['category'][$col['index']]);
            if ($type === 'text' && ! $nameOrCatMatch) {
                $keywordScores['name'][$col['index']] = ($keywordScores['name'][$col['index']] ?? 0) + 1;
            }
            if ($type === 'text' && ! isset($keywordScores['qty'][$col['index']]) && $col['index'] !== ($mapping['name_col'] ?? -1)) {
                $keywordScores['category'][$col['index']] = ($keywordScores['category'][$col['index']] ?? 0) + 1;
            }
            if ($type === 'numeric' && ! isset($keywordScores['qty'][$col['index']])) {
                $keywordScores['qty'][$col['index']] = ($keywordScores['qty'][$col['index']] ?? 0) + 1;
            }
        }

        $colByIndex = [];
        foreach ($columns as $c) {
            $colByIndex[$c['index']] = $c;
        }

        foreach (['name', 'qty', 'size', 'category'] as $field) {
            if (! empty($keywordScores[$field])) {
                arsort($keywordScores[$field]);
                $colIdx = (int) array_key_first($keywordScores[$field]);
                // Avoid blank columns for name/qty (merged headers shift the keyword cell)
                if (in_array($field, ['name', 'qty'], true)) {
                    $found = false;
                    foreach ($keywordScores[$field] as $idx => $score) {
                        if (isset($colByIndex[$idx]) && $colByIndex[$idx]['type'] !== 'blank') {
                            $colIdx = (int) $idx;
                            $found = true;
                            break;
                        }
                    }
                    if (! $found) {
                        continue;
                    }
                }
                // Avoid reusing same column for name and qty
                if ($field === 'name' && $colIdx === ($mapping['qty_col'] ?? -1)) {
                    next($keywordScores[$field]);
                    $colIdx = (int) array_key_first($keywordScores[$field]);
                }
                if ($field === 'qty' && $colIdx === ($mapping['name_col'] ?? -1)) {
                    next($keywordScores[$field]);
                    $colIdx = (int) array_key_first($keywordScores[$field]);
                }
                $mapping[$field.'_col'] = $colIdx;
            }
        }

        // Refinement: if detected name_col's samples don't actually look like product names
        // (e.g. formula/currency output from merged cells), scan adjacent columns for the real one
        if ($mapping['name_col'] !== null && isset($colByIndex[$mapping['name_col']])) {
            $nc = $colByIndex[$mapping['name_col']];
            $looksLegit = false;
            foreach ($nc['sample_values'] ?? [] as $sv) {
                $svClean = str_replace(',', '', $sv);
                if (! is_numeric($svClean) && preg_match('/\p{L}/u', $svClean) && ! preg_match('/\d+\.\d+.*\(\d+\)/', $svClean)) {
                    $looksLegit = true;
                    break;
                }
            }
            if (! $looksLegit && $nc['type'] !== 'blank') {
                $keywordNameCol = null;
                if (! empty($keywordScores['name'])) {
                    $keywordNameCol = (int) array_key_first($keywordScores['name']);
                }
                $anchor = $keywordNameCol ?? $mapping['name_col'];
                $nameOffset = null;
                for ($offset = -3; $offset <= 3; $offset++) {
                    if ($offset === 0) {
                        continue;
                    }
                    $scanIdx = $anchor + $offset;
                    if (! isset($colByIndex[$scanIdx]) || $colByIndex[$scanIdx]['type'] !== 'text') {
                        continue;
                    }
                    $looksLikeName = false;
                    foreach ($colByIndex[$scanIdx]['sample_values'] ?? [] as $sv) {
                        $svClean = str_replace(',', '', $sv);
                        if (! is_numeric($svClean) && preg_match('/\p{L}/u', $svClean) && ! preg_match('/\d+\.\d+.*\(\d+\)/', $svClean)) {
                            $looksLikeName = true;
                            break;
                        }
                    }
                    if ($looksLikeName) {
                        $nameOffset = $scanIdx - $anchor;
                        $mapping['name_col'] = $scanIdx;
                        break;
                    }
                }
                if ($nameOffset !== null && $mapping['qty_col'] !== null) {
                    $shiftedQty = $mapping['qty_col'] + $nameOffset;
                    if (isset($colByIndex[$shiftedQty]) && $colByIndex[$shiftedQty]['type'] === 'numeric') {
                        $mapping['qty_col'] = $shiftedQty;
                    }
                }
            }
            if ($nc['type'] === 'blank') {
                $nameOffset = null;
                for ($offset = -3; $offset <= 3; $offset++) {
                    if ($offset === 0) {
                        continue;
                    }
                    $scanIdx = $mapping['name_col'] + $offset;
                    if (! isset($colByIndex[$scanIdx]) || $colByIndex[$scanIdx]['type'] !== 'text') {
                        continue;
                    }
                    $looksLikeName = false;
                    foreach ($colByIndex[$scanIdx]['sample_values'] ?? [] as $sv) {
                        $svClean = str_replace(',', '', $sv);
                        if (! is_numeric($svClean) && preg_match('/\p{L}/u', $svClean)) {
                            $looksLikeName = true;
                            break;
                        }
                    }
                    if ($looksLikeName) {
                        $nameOffset = $scanIdx - $mapping['name_col'];
                        $mapping['name_col'] = $scanIdx;
                        break;
                    }
                }
                if ($nameOffset !== null && $mapping['qty_col'] !== null) {
                    $shiftedQty = $mapping['qty_col'] + $nameOffset;
                    if (isset($colByIndex[$shiftedQty]) && $colByIndex[$shiftedQty]['type'] === 'numeric') {
                        $mapping['qty_col'] = $shiftedQty;
                    }
                }
            }
        }

        // Fallback: if no name detected, use first text column with actual name-like values
        if ($mapping['name_col'] === null) {
            foreach ($columns as $col) {
                if ($col['type'] === 'text') {
                    $looksLikeName = false;
                    foreach ($col['sample_values'] ?? [] as $sv) {
                        $svClean = str_replace(',', '', $sv);
                        if (! is_numeric($svClean) && preg_match('/\p{L}/u', $svClean)) {
                            $looksLikeName = true;
                            break;
                        }
                    }
                    if ($looksLikeName) {
                        $mapping['name_col'] = $col['index'];
                        break;
                    }
                }
            }
        }
        if ($mapping['name_col'] === null && ! empty($columns)) {
            $mapping['name_col'] = $columns[0]['index'];
        }

        // Fallback: if no qty detected, use first numeric column (different from name)
        if ($mapping['qty_col'] === null) {
            foreach ($columns as $col) {
                if ($col['type'] === 'numeric' && $col['index'] !== $mapping['name_col']) {
                    $mapping['qty_col'] = $col['index'];
                    break;
                }
            }
        }
        // If only one column, use it for both
        if ($mapping['qty_col'] === null && $mapping['name_col'] !== null) {
            $mapping['qty_col'] = $mapping['name_col'];
        }

        // Fallback: if no size detected, look for text column with size-like values
        if ($mapping['size_col'] === null) {
            foreach ($columns as $col) {
                if ($col['type'] === 'text' && $col['index'] !== $mapping['name_col']) {
                    foreach ($col['sample_values'] ?? [] as $sv) {
                        $svNorm = $this->matchingService->normalize($sv);
                        if (in_array($svNorm, ['كبير', 'وسط', 'صغير', 'large', 'medium', 'small'])) {
                            $mapping['size_col'] = $col['index'];
                            break 2;
                        }
                    }
                }
            }
        }

        // Fallback: detect category column by repeated values pattern
        if ($mapping['cat_col'] === null) {
            foreach ($columns as $col) {
                if ($col['type'] === 'text' && $col['index'] !== $mapping['name_col']) {
                    $counts = array_count_values(array_filter($col['sample_values'] ?? []));
                    foreach ($counts as $val => $cnt) {
                        if ($cnt >= 2 && mb_strlen($val) > 2) {
                            $mapping['cat_col'] = $col['index'];
                            break 2;
                        }
                    }
                }
            }
        }

        return $mapping;
    }
}
