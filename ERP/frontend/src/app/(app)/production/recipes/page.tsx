'use client';
import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

interface IngredientRow {
  tempId: string;
  item_id: string;
  name: string;
  unit: string;
  price: number;
  qty: string;
}

interface CustomWeight {
  id: string;
  grams: string;
  item_id: string;
}

interface RecipeForm {
  item_id: string;
  name: string;
  unit: string;
  output_warehouse_id: string;
  notes: string;
  ingredients: IngredientRow[];
  production_qty: string;
  selling_price: string;
  customWeights: CustomWeight[];
}

const emptyIngredient = (): IngredientRow => ({
  tempId: Math.random().toString(36).slice(2),
  item_id: '', name: '', unit: '', price: 0, qty: '',
});

const emptyForm = (): RecipeForm => ({
  item_id: '', name: '', unit: '', output_warehouse_id: '', notes: '',
  ingredients: [emptyIngredient()],
  production_qty: '1',
  selling_price: '',
  customWeights: [],
});

export default function RecipesPage() {
  const qc = useQueryClient();
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState<RecipeForm>(emptyForm());
  const [showForm, setShowForm] = useState(false);
  const [itemSearch, setItemSearch] = useState('');
  const [renamingId, setRenamingId] = useState<string | null>(null);
  const [renameValue, setRenameValue] = useState('');
  const [newSizeItem, setNewSizeItem] = useState('');
  const [newSizeGrams, setNewSizeGrams] = useState('');

  const { data: recipes, isLoading } = useQuery({
    queryKey: ['production-recipes'],
    queryFn: () => api.get('/production/recipes').then(r => r.data),
  });

  const { data: items = [] } = useQuery({
    queryKey: ['items'],
    queryFn: () => api.get('/items').then(r => r.data),
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then(r => r.data),
  });

  const invalidateAll = () => {
    qc.invalidateQueries({ queryKey: ['production-recipes'] });
    qc.invalidateQueries({ queryKey: ['daily-production'] });
    qc.invalidateQueries({ queryKey: ['items'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: any) => {
      if (editingId) return api.put(`/production/recipes/${editingId}`, payload);
      return api.post('/production/recipes', payload);
    },
    onSuccess: () => {
      toast.success(editingId ? 'تم تحديث الوصفة' : 'تم إضافة الوصفة');
      invalidateAll();
      setShowForm(false);
      setEditingId(null);
      setForm(emptyForm());
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || JSON.stringify(err?.response?.data?.errors) || 'خطأ في الحفظ'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/production/recipes/${id}`),
    onSuccess: () => {
      toast.success('تم حذف الوصفة');
      invalidateAll();
    },
  });

  const syncCostsMutation = useMutation({
    mutationFn: () => api.post('/production/recipes/sync-costs'),
    onSuccess: (r: any) => {
      toast.success(r.data.message);
      invalidateAll();
    },
    onError: () => toast.error('خطأ في تحديث التكاليف'),
  });

  const renameMutation = useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => api.put(`/production/recipes/${id}`, { name }),
    onSuccess: () => {
      toast.success('تم تعديل الاسم');
      setRenamingId(null);
      invalidateAll();
    },
    onError: () => toast.error('خطأ في تعديل الاسم'),
  });

  const itemsById = useMemo(() => {
    const map: Record<string, any> = {};
    items.forEach((i: any) => { map[i.id] = i; });
    return map;
  }, [items]);

  const selectItem = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const id = e.target.value;
    const item = itemsById[id];
    setForm(prev => ({ ...prev, item_id: id, name: item?.name || '', unit: item?.unit || '' }));
  };

  const addIngredient = () => {
    setForm(prev => ({ ...prev, ingredients: [...prev.ingredients, emptyIngredient()] }));
  };

  const updateIngredient = (idx: number, field: string, value: string) => {
    setForm(prev => {
      const next = [...prev.ingredients];
      next[idx] = { ...next[idx], [field]: value };
      if (field === 'item_id') {
        const item = itemsById[value];
        if (item) {
          next[idx].name = item.name;
          next[idx].unit = item.unit || '';
          next[idx].price = parseFloat(item.default_cost) || 0;
        }
      }
      return { ...prev, ingredients: next };
    });
  };

  const removeIngredient = (idx: number) => {
    setForm(prev => ({
      ...prev,
      ingredients: prev.ingredients.filter((_, i) => i !== idx),
    }));
  };

  // ── حسابات التكلفة ────────────────────────────────────
  const totalIngredientQty = useMemo(() =>
    form.ingredients.reduce((s, ing) => s + (parseFloat(ing.qty) || 0), 0), [form.ingredients]);

  const totalCost = useMemo(() =>
    form.ingredients.reduce((s, ing) => s + ((parseFloat(ing.qty) || 0) * ing.price), 0), [form.ingredients]);

  const productionQty = parseFloat(form.production_qty) || 1;
  const sellingPrice = parseFloat(form.selling_price) || 0;

  const pricePerKgInput = totalIngredientQty > 0 ? totalCost / totalIngredientQty : 0;
  const pricePerKgOutput = totalCost / productionQty;
  const directCostPct = sellingPrice > 0 ? (totalCost / sellingPrice) * 100 : 0;

  const pctOfSelling = (price: number) => sellingPrice > 0 ? (price / sellingPrice) * 100 : 0;

  const editRecipe = (recipe: any) => {
    setEditingId(recipe.id);
    setForm({
      item_id: recipe.item_id || '',
      name: recipe.name || '',
      unit: recipe.unit || '',
      output_warehouse_id: recipe.output_warehouse_id || '',
      notes: recipe.notes || '',
      production_qty: String(recipe.production_qty || 1),
      selling_price: String(recipe.selling_price || ''),
      customWeights: (recipe.sizes || []).map((s: any) => ({
        id: Math.random().toString(36).slice(2),
        grams: String(s.grams),
        item_id: s.item_id || '',
      })),
      ingredients: recipe.ingredients?.length
        ? recipe.ingredients.map((ing: any) => ({
            tempId: ing.id || Math.random().toString(36).slice(2),
            item_id: ing.item_id,
            name: ing.item?.name || '',
            unit: ing.item?.unit || '',
            price: parseFloat(ing.item?.default_cost || 0),
            qty: String(ing.qty),
          }))
        : [emptyIngredient()],
    });
    setShowForm(true);
  };

  const handleSubmit = () => {
    const payload = {
      item_id: form.item_id,
      name: form.name,
      unit: form.unit,
      production_qty: parseFloat(form.production_qty) || 1,
      selling_price: parseFloat(form.selling_price) || null,
      output_warehouse_id: form.output_warehouse_id || null,
      notes: form.notes || null,
      ingredients: form.ingredients
        .filter(ing => ing.item_id && ing.qty)
        .map(ing => ({
          item_id: ing.item_id,
          qty: parseFloat(ing.qty) || 0,
          unit_cost: null,
        })),
      sizes: form.customWeights
        .filter(w => w.grams && w.item_id)
        .map(w => ({
          grams: parseFloat(w.grams) || 0,
          selling_price: null,
          item_id: w.item_id,
        })),
    };
    if (!payload.ingredients.length) {
      toast.error('أضف مكون واحد على الأقل');
      return;
    }
    saveMutation.mutate(payload);
  };

  const filteredItems = items.filter((i: any) =>
    i.name.includes(itemSearch) || itemSearch === ''
  );

  const { data: marketLatest = [] } = useQuery({
    queryKey: ['market-latest'],
    queryFn: () => api.get('/production/market-prices/latest').then(r => r.data),
    refetchInterval: 60_000,
  });

  return (
    <div className="space-y-4" dir="rtl">
      {/* Market price strip */}
      {marketLatest.length > 0 && (
        <div className="bg-amber-50 border border-amber-200 rounded-xl px-4 py-2 flex items-center gap-4 text-sm overflow-x-auto">
          <span className="text-amber-700 font-medium whitespace-nowrap">📊 أسعار البورصة:</span>
          {marketLatest.map((m: any) => (
            <span key={m.item_name} className="whitespace-nowrap text-gray-700">
              {m.item_name}: <strong className="text-amber-800">{m.price ?? '—'} ج</strong>
              {m.date && <span className="text-gray-400 text-xs mr-1">({m.date})</span>}
            </span>
          ))}
        </div>
      )}
      <div className="flex justify-between items-center">
        <h2 className="text-lg font-semibold text-gray-800">الوصفات التصنيعية</h2>
        <div className="flex gap-2">
          <button onClick={() => syncCostsMutation.mutate()} disabled={syncCostsMutation.isPending}
            className="px-4 py-2 text-sm border border-amber-300 text-amber-700 rounded-lg hover:bg-amber-50 disabled:opacity-40">
            {syncCostsMutation.isPending ? 'جاري...' : '🔄 تحديث التكاليف'}
          </button>
          <button onClick={() => { setShowForm(true); setEditingId(null); setForm(emptyForm()); }}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            + وصفة جديدة
          </button>
        </div>
      </div>

      {showForm && (
        <div className="border border-gray-100 rounded-xl p-4 space-y-4 bg-white">
          <h3 className="font-semibold text-gray-700">{editingId ? 'تعديل وصفة' : 'وصفة جديدة'}</h3>

          {/* اختيار المنتج والمخزن */}
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="block text-xs text-gray-500 mb-1">المنتج النهائي</label>
              <select value={form.item_id} onChange={selectItem}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                <option value="">اختر المنتج...</option>
                {filteredItems.map((item: any) => (
                  <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">المخزن المستلم</label>
              <select value={form.output_warehouse_id}
                onChange={(e) => setForm(prev => ({ ...prev, output_warehouse_id: e.target.value }))}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                <option value="">اختر المخزن...</option>
                {warehouses.map((w: any) => (
                  <option key={w.id} value={w.id}>{w.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">سعر البيع</label>
              <input type="number" value={form.selling_price}
                onChange={(e) => setForm(prev => ({ ...prev, selling_price: e.target.value }))}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
            </div>
          </div>

          {/* المكونات */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs text-gray-500 font-medium">المكونات</label>
              <button onClick={addIngredient} className="text-xs text-blue-600 hover:text-blue-700">+ أضف مكون</button>
            </div>
            <table className="w-full text-sm border border-gray-100 rounded-lg overflow-hidden">
              <thead>
                <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                  <th className="px-3 py-2 font-normal">الصنف</th>
                  <th className="px-3 py-2 font-normal w-16">الوحدة</th>
                  <th className="px-3 py-2 font-normal w-24">السعر</th>
                  <th className="px-3 py-2 font-normal w-24">الكمية</th>
                  <th className="px-3 py-2 font-normal w-28">إجمالي السعر</th>
                  <th className="px-3 py-2 w-8"></th>
                </tr>
              </thead>
              <tbody>
                {form.ingredients.map((ing, idx) => {
                  const ingTotal = (parseFloat(ing.qty) || 0) * ing.price;
                  return (
                    <tr key={ing.tempId} className="border-t border-gray-50">
                      <td className="px-1 py-1">
                        <select value={ing.item_id}
                          onChange={(e) => updateIngredient(idx, 'item_id', e.target.value)}
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm">
                          <option value="">اختر...</option>
                          {filteredItems.map((item: any) => (
                            <option key={item.id} value={item.id}>{item.name}</option>
                          ))}
                        </select>
                      </td>
                      <td className="px-2 py-1.5 text-gray-500 text-xs">{ing.unit || '—'}</td>
                      <td className="px-1 py-1">
                        <input type="number" value={ing.price || ''}
                          onChange={(e) => updateIngredient(idx, 'price', e.target.value)}
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-left" />
                      </td>
                      <td className="px-1 py-1">
                        <input type="number" value={ing.qty}
                          onChange={(e) => updateIngredient(idx, 'qty', e.target.value)}
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-left" placeholder="0" />
                      </td>
                      <td className="px-3 py-1.5 text-left text-gray-700 font-medium tabular-nums">
                        {ingTotal > 0 ? ingTotal.toFixed(2) : '—'}
                      </td>
                      <td className="px-1 py-1">
                        <button onClick={() => removeIngredient(idx)}
                          className="text-gray-300 hover:text-red-400 text-xs">✕</button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
              <tfoot>
                <tr className="bg-gray-50 border-t border-gray-100 font-medium text-sm">
                  <td colSpan={4} className="px-3 py-2 text-gray-600">الإجمالي</td>
                  <td className="px-3 py-2 text-left text-blue-700 tabular-nums">{totalCost.toFixed(2)}</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>

          {/* كمية الإنتاج */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs text-gray-500 mb-1">كمية الإنتاج</label>
              <input type="number" value={form.production_qty}
                onChange={(e) => setForm(prev => ({ ...prev, production_qty: e.target.value }))}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
            </div>
            <div className="bg-green-50 rounded-xl p-3 flex items-center justify-between">
              <span className="text-sm text-gray-600">Direct Cost %</span>
              <span className="text-lg font-bold text-green-700 tabular-nums">{directCostPct.toFixed(1)}%</span>
            </div>
          </div>

          {/* جدول حسابات التكلفة والنسب */}
          <div>
            <table className="w-full text-sm border border-gray-100 rounded-lg overflow-hidden">
              <thead>
                <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                  <th className="px-3 py-2 font-normal">البيان</th>
                  <th className="px-3 py-2 font-normal w-28 text-left">السعر</th>
                  <th className="px-3 py-2 font-normal w-24 text-left">% من البيع</th>
                </tr>
              </thead>
              <tbody>
                <tr className="border-t border-gray-50">
                  <td className="px-3 py-2 text-gray-500">
                    <span className="text-xs text-gray-400">متوسط سعر الخامات</span>
                    <div className="font-medium text-gray-700">كيلو الخامات</div>
                  </td>
                  <td className="px-3 py-2 text-left font-bold tabular-nums">{pricePerKgInput.toFixed(2)} ج</td>
                  <td className="px-3 py-2 text-left text-gray-500 tabular-nums">{pctOfSelling(pricePerKgInput).toFixed(1)}%</td>
                </tr>
                <tr className="border-t border-gray-50 bg-blue-50/30">
                  <td className="px-3 py-2 text-blue-800">
                    <span className="text-xs text-blue-500">تكلفة المنتج النهائي</span>
                    <div className="font-bold">كيلو المنتج</div>
                  </td>
                  <td className="px-3 py-2 text-left font-bold text-blue-700 tabular-nums">{pricePerKgOutput.toFixed(2)} ج</td>
                  <td className="px-3 py-2 text-left text-blue-500 tabular-nums">{pctOfSelling(pricePerKgOutput).toFixed(1)}%</td>
                </tr>
                {form.customWeights.map((w) => {
                  const grams = parseFloat(w.grams) || 0;
                  const price = grams > 0 ? (grams / 1000) * pricePerKgOutput : 0;
                  const item = itemsById[w.item_id];
                  return (
                    <tr key={w.id} className="border-t border-gray-50">
                      <td className="px-3 py-2 text-gray-700">
                        {item?.name || w.item_id.slice(0, 8) || '?'} — {grams} جم
                        <button onClick={() => setForm(prev => ({ ...prev, customWeights: prev.customWeights.filter(cw => cw.id !== w.id) }))}
                          className="mr-2 px-1.5 py-0.5 text-xs text-red-500 bg-red-50 rounded hover:bg-red-100">✕ حذف</button>
                      </td>
                      <td className="px-3 py-2 text-left font-bold tabular-nums">{price.toFixed(2)} ج</td>
                      <td className="px-3 py-2 text-left text-gray-500 tabular-nums">{pctOfSelling(price).toFixed(1)}%</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {/* إضافة وزن مخصص مع اختيار الصنف */}
          <div className="border border-gray-100 rounded-lg p-3 space-y-2">
            <label className="text-xs text-gray-500 font-medium">أضف مقاس</label>
            <div className="flex gap-2 items-end">
              <div className="flex-1">
                <label className="block text-xs text-gray-400 mb-1">الصنف</label>
                <select value={newSizeItem} onChange={(e) => setNewSizeItem(e.target.value)}
                  className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                  <option value="">اختر الصنف...</option>
                  {filteredItems.map((item: any) => (
                    <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
                  ))}
                </select>
              </div>
              <div className="w-32">
                <label className="block text-xs text-gray-400 mb-1">الوزن (جرام)</label>
                <input type="text" inputMode="numeric" value={newSizeGrams}
                  onChange={(e) => setNewSizeGrams(e.target.value.replace(/[^0-9]/g, ''))}
                  placeholder="مثال: 100"
                  className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
              </div>
              <button onClick={() => {
                const g = newSizeGrams.trim();
                if (newSizeItem && g) {
                  setForm(prev => ({
                    ...prev,
                    customWeights: [...prev.customWeights, {
                      id: Math.random().toString(36).slice(2),
                      grams: g,
                      item_id: newSizeItem,
                    }],
                  }));
                  setNewSizeItem('');
                  setNewSizeGrams('');
                } else {
                  toast.error('اختر الصنف وأدخل الوزن');
                }
              }}
                className="px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">
                + أضف
              </button>
            </div>
          </div>

          {/* الأزرار */}
          <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
            <button onClick={() => { setShowForm(false); setEditingId(null); setForm(emptyForm()); }}
              className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">إلغاء</button>
            <button onClick={handleSubmit} disabled={!form.item_id || saveMutation.isPending}
              className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40">
              {saveMutation.isPending ? 'جاري الحفظ...' : 'حفظ الوصفة'}
            </button>
          </div>
        </div>
      )}

      {/* عرض الوصفات */}
      {isLoading ? (
        <p className="text-gray-400">جاري التحميل...</p>
      ) : !recipes?.length ? (
        <p className="text-gray-400">لا توجد وصفات — أضف وصفة جديدة</p>
      ) : (
        <div className="space-y-2">
          {recipes.map((recipe: any) => {
            const costFromIngredients = recipe.ingredients?.reduce((sum: number, ing: any) => {
              return sum + (parseFloat(ing.qty) || 0) * (parseFloat(ing.item?.default_cost) || 0);
            }, 0) || 0;
            const prodQty = parseFloat(recipe.production_qty) || 1;
            const recipeCost = recipe.outputItem?.default_cost ?? (costFromIngredients > 0 ? costFromIngredients / prodQty : null);
            return (
            <div key={recipe.id} className="border border-gray-100 rounded-xl p-4 hover:border-gray-200">
              <div className="flex justify-between items-start">
                <div>
                  <div className="font-medium text-gray-800">
                    {renamingId === recipe.id ? (
                      <input type="text" value={renameValue} autoFocus
                        onChange={(e) => setRenameValue(e.target.value)}
                        onBlur={() => { if (renameValue.trim() && renameValue !== recipe.name) renameMutation.mutate({ id: recipe.id, name: renameValue.trim() }); else setRenamingId(null); }}
                        onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); if (e.key === 'Escape') setRenamingId(null); }}
                        className="border border-blue-300 rounded px-2 py-0.5 text-sm w-full" />
                    ) : (
                      <span onDoubleClick={() => { setRenamingId(recipe.id); setRenameValue(recipe.name); }} className="cursor-default hover:bg-gray-100 rounded px-1 -mx-1">
                        {recipe.name}
                      </span>
                    )}
                    <span className="text-xs text-gray-400 mr-2">{recipe.unit}</span>
                    {recipeCost != null && recipeCost > 0 && (
                      <span className="px-2 py-0.5 text-xs bg-green-50 text-green-700 rounded-full font-mono font-medium mr-2">
                        {recipeCost.toFixed(2)} ج /{recipe.unit || 'كجم'}
                      </span>
                    )}
                  </div>
                  <div className="text-xs text-gray-400">
                    {recipe.outputItem?.name}
                  </div>
                  <div className="text-xs text-gray-400 mt-1">
                    المخزن: {recipe.outputWarehouse?.name || '—'} · مكونات: {recipe.ingredients?.length || 0}
                  </div>
                  {recipe.sizes?.length > 0 && (
                    <div className="flex flex-wrap gap-1 mt-2">
                      {recipe.sizes.map((s: any, idx: number) => (
                        <span key={idx} className="px-2 py-0.5 text-xs bg-purple-50 text-purple-600 rounded-full">
                          {s.grams} جم
                        </span>
                      ))}
                    </div>
                  )}
                </div>
                <div className="flex gap-2">
                  <button onClick={() => editRecipe(recipe)}
                    className="px-3 py-1 text-xs border border-gray-200 rounded-lg hover:bg-gray-50">تعديل</button>
                  <button onClick={() => { if (confirm('حذف الوصفة؟')) deleteMutation.mutate(recipe.id); }}
                    className="px-3 py-1 text-xs text-red-500 border border-red-200 rounded-lg hover:bg-red-50">حذف</button>
                </div>
              </div>
              {recipe.ingredients?.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                  {recipe.ingredients.map((ing: any) => (
                    <span key={ing.id} className="px-2 py-0.5 text-xs bg-gray-50 text-gray-500 rounded-full">
                      {ing.item?.name || '—'} ({ing.qty})
                    </span>
                  ))}
                </div>
              )}
            </div>
          );
        })}
        </div>
      )}
    </div>
  );
}
