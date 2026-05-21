<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuRecipe;
use App\Models\MenuEngineering\MenuRecipeItem;
use App\Models\MenuEngineering\MenuRecipeVersion;
use App\Services\MenuEngineering\RecipeCostCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuRecipeController extends Controller
{
    public function __construct(
        private RecipeCostCalculationService $calc,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id;
        $menuId = $request->menu_id;
        $category = $request->category;
        $withItems = $request->boolean('items');

        $query = MenuRecipe::where('client_id', $clientId)->withCount('items');
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($menuId) {
            $query->where('menu_id', $menuId);
        }
        if ($category) {
            $query->where('category', $category);
        }

        if ($withItems) {
            $query->with('items.ingredient:id,name');
        }

        $recipes = $query->orderBy('created_at', 'desc')->get()->map(fn($r) => [
            'id'                => $r->id,
            'name'              => $r->name,
            'code'              => $r->code,
            'category'          => $r->category,
            'branch_id'         => $r->branch_id,
            'menu_id'           => $r->menu_id,
            'status'            => $r->status,
            'version'           => $r->version,
            'portions'          => (float) $r->portions,
            'selling_price'     => (float) ($r->selling_price ?? 0),
            'items_count'       => (int) $r->items_count,
            'total_cost'        => $r->total_cost,
            'cost_per_portion'  => $r->cost_per_portion,
            'items'             => $withItems ? $r->items->map(fn($i) => [
                'ingredient_id'   => $i->ingredient_id,
                'ingredient_name' => $i->ingredient?->name ?? '—',
                'qty'             => (float) $i->qty,
                'purchase_unit'   => $i->purchase_unit,
            ]) : [],
        ]);

        return response()->json($recipes);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:50',
            'branch_id' => 'nullable|string',
            'menu_id' => 'nullable|string',
            'portions' => 'nullable|numeric|min:1',
            'selling_price' => 'nullable|numeric|min:0',
            'target_food_cost_pct' => 'nullable|numeric|min:0|max:100',
            'prep_instructions' => 'nullable|string',
            'status' => 'nullable|string|in:draft,active,inactive',
        ]);

        $data['client_id'] = $request->user()->current_client_id;
        $data['created_by'] = $request->user()->id;
        $data['portions'] ??= 1;

        $recipe = MenuRecipe::create($data);

        return response()->json(['data' => $recipe->fresh()], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $recipe = MenuRecipe::where('client_id', $request->user()->current_client_id)
            ->with('items.ingredient:id,name,unit,default_cost')
            ->findOrFail($id);

        $items = $recipe->items->map(fn($i) => [
            'id'                 => $i->id,
            'ingredient_id'      => $i->ingredient_id,
            'ingredient_name'    => $i->ingredient?->name ?? '—',
            'ingredient_unit'    => $i->ingredient?->unit ?? 'each',
            'qty'                => (float) $i->qty,
            'weight_g'           => $i->weight_g ? (float) $i->weight_g : null,
            'volume_ml'          => $i->volume_ml ? (float) $i->volume_ml : null,
            'purchase_unit'      => $i->purchase_unit,
            'purchase_unit_price'=> (float) $i->purchase_unit_price,
            'recipe_unit'        => $i->recipe_unit,
            'conversion_factor'  => (float) $i->conversion_factor,
            'yield_pct'          => (float) $i->yield_pct,
            'ep_cost'            => (float) $i->ep_cost,
            'line_total'         => (float) $i->line_total,
            'sort_order'         => (int) $i->sort_order,
        ]);

        $totals = $this->calc->calculateRecipeTotals($recipe);

        return response()->json([
            'data' => [
                'id'                => $recipe->id,
                'name'              => $recipe->name,
                'code'              => $recipe->code,
                'category'          => $recipe->category,
                'branch_id'         => $recipe->branch_id,
                'menu_id'           => $recipe->menu_id,
                'recipe_type'       => $recipe->recipe_type,
                'portions'          => (float) $recipe->portions,
                'selling_price'     => (float) ($recipe->selling_price ?? 0),
                'target_food_cost_pct' => (float) ($recipe->target_food_cost_pct ?? 30),
                'prep_instructions' => $recipe->prep_instructions,
                'status'            => $recipe->status,
                'version'           => $recipe->version,
                'items'             => $items,
            ],
            'totals' => $totals,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $recipe = MenuRecipe::where('client_id', $request->user()->current_client_id)
            ->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:50',
            'branch_id' => 'nullable|string',
            'menu_id' => 'nullable|string',
            'portions' => 'nullable|numeric|min:1',
            'selling_price' => 'nullable|numeric|min:0',
            'target_food_cost_pct' => 'nullable|numeric|min:0|max:100',
            'prep_instructions' => 'nullable|string',
            'status' => 'nullable|string|in:draft,active,archived',
        ]);

        if (isset($data['status']) && $data['status'] === 'active' && $recipe->status !== 'active') {
            $data['version'] = $recipe->version + 1;
            $this->createVersionSnapshot($recipe);
        }

        $recipe->update($data);

        return response()->json(['data' => $recipe->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $recipe = MenuRecipe::where('client_id', $request->user()->current_client_id)
            ->findOrFail($id);
        $recipe->delete();
        return response()->json(['message' => 'deleted']);
    }

    // ── Items ──

    public function syncItems(Request $request, string $id): JsonResponse
    {
        $recipe = MenuRecipe::where('client_id', $request->user()->current_client_id)
            ->findOrFail($id);

        $items = $request->validate([
            'items' => 'required|array',
            'items.*.ingredient_id' => 'required|string',
            'items.*.qty' => 'required|numeric|min:0',
            'items.*.purchase_unit' => 'required|string|max:20',
            'items.*.purchase_unit_price' => 'required|numeric|min:0',
            'items.*.recipe_unit' => 'required|string|max:20',
            'items.*.conversion_factor' => 'required|numeric|min:0',
            'items.*.yield_pct' => 'nullable|numeric|min:0|max:100',
            'items.*.sort_order' => 'nullable|integer',
        ])['items'];

        DB::transaction(function () use ($recipe, $items) {
            $recipe->items()->delete();
            foreach ($items as $i => $item) {
                $item['recipe_id'] = $recipe->id;
                $item['yield_pct'] ??= 100;
                $item['sort_order'] ??= $i;
                $calculated = $this->calc->calculateItemFromArray($item);
                MenuRecipeItem::create($calculated);
            }
        });

        $recipe->touch();

        return response()->json(['message' => 'items synced']);
    }

    // ── Versions ──

    public function versions(Request $request, string $id): JsonResponse
    {
        $recipe = MenuRecipe::where('client_id', $request->user()->current_client_id)
            ->findOrFail($id);
        $versions = $recipe->versions()->orderBy('version_number', 'desc')->get()
            ->map(fn($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'notes' => $v->notes,
                'created_at' => $v->created_at,
            ]);
        return response()->json($versions);
    }

    public function createVersion(Request $request, string $id): JsonResponse
    {
        $recipe = MenuRecipe::where('client_id', $request->user()->current_client_id)
            ->findOrFail($id);

        $notes = $request->validate(['notes' => 'nullable|string'])['notes'] ?? '';

        $snapshot = $this->buildSnapshot($recipe);
        $version = MenuRecipeVersion::create([
            'recipe_id' => $recipe->id,
            'version_number' => $recipe->version + 1,
            'snapshot' => $snapshot,
            'notes' => $notes,
            'created_by' => $request->user()->id,
        ]);

        $recipe->increment('version');

        return response()->json(['data' => $version], 201);
    }

    // ── Private ──

    private function createVersionSnapshot(MenuRecipe $recipe): void
    {
        MenuRecipeVersion::create([
            'recipe_id' => $recipe->id,
            'version_number' => $recipe->version,
            'snapshot' => $this->buildSnapshot($recipe),
            'created_by' => request()->user()?->id,
        ]);
    }

    private function buildSnapshot(MenuRecipe $recipe): array
    {
        return [
            'recipe' => $recipe->toArray(),
            'items' => $recipe->items()->with('ingredient:id,name,unit')->get()->toArray(),
        ];
    }
}
