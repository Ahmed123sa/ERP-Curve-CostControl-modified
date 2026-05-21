'use client';

import { useRef, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useAuthStore } from '@/lib/store';
import toast from 'react-hot-toast';
import { HotTable } from '@handsontable/react-wrapper';
import { registerAllModules } from 'handsontable/registry';
import 'handsontable/styles/handsontable.min.css';
registerAllModules();

type Level = 'branches' | 'menus' | 'categories' | 'items' | 'sheet';

export default function MenuEngineeringPage() {
  const qc = useQueryClient();
  const { currentClient } = useAuthStore();
  const hotRef = useRef<any>(null);

  const [level, setLevel] = useState<Level>('branches');
  const [selectedBranch, setSelectedBranch] = useState<any>(null);
  const [selectedMenu, setSelectedMenu] = useState<any>(null);
  const [selectedCategory, setSelectedCategory] = useState<any>(null);
  const [selectedRecipe, setSelectedRecipe] = useState<any>(null);
  const [recipeData, setRecipeData] = useState<any>(null);
  const [showCategoryModal, setShowCategoryModal] = useState(false);
  const [newCategoryName, setNewCategoryName] = useState('');

  // ── Data ──
  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });
  const branches = (warehouses as any[]).filter((w: any) => w.type === 'branch');

  const { data: menus = [] } = useQuery({
    queryKey: ['menu-menus', selectedBranch?.id],
    queryFn: () => api.get('/menu-engineering/menus', {
      params: { branch_id: selectedBranch?.id || undefined },
    }).then((r) => r.data),
    enabled: level === 'menus' || level === 'categories' || level === 'items',
  });

  const { data: categories = [] } = useQuery({
    queryKey: ['menu-categories', selectedMenu?.id],
    queryFn: () => api.get('/menu-engineering/categories', {
      params: { menu_id: selectedMenu?.id || undefined },
    }).then((r) => r.data),
    enabled: level === 'categories' || level === 'items',
  });

  const { data: modalCategories = [] } = useQuery({
    queryKey: ['menu-categories-modal', selectedMenu?.id],
    queryFn: () => api.get('/menu-engineering/categories', {
      params: { menu_id: selectedMenu?.id || undefined },
    }).then((r) => r.data),
    enabled: showCategoryModal,
  });

  const { data: ingredients = [] } = useQuery({
    queryKey: ['menu-ingredients'],
    queryFn: () => api.get('/menu-engineering/ingredients').then((r) => r.data),
  });

  const { data: unitData } = useQuery({
    queryKey: ['menu-unit-conversions'],
    queryFn: () => api.get('/menu-engineering/unit-conversions').then((r) => r.data),
  });

  const { data: recipes = [] } = useQuery({
    queryKey: ['menu-recipes', selectedMenu?.id, selectedCategory?.name],
    queryFn: () => api.get('/menu-engineering/recipes', {
      params: { menu_id: selectedMenu?.id || undefined, category: selectedCategory?.name || undefined },
    }).then((r) => r.data),
    enabled: level === 'items' || level === 'sheet',
  });

  // ── Mutations ──
  const createMenuMutation = useMutation({
    mutationFn: (data: any) => api.post('/menu-engineering/menus', data),
    onSuccess: () => {
      toast.success('تم إنشاء القائمة');
      qc.invalidateQueries({ queryKey: ['menu-menus'] });
    },
  });

  const deleteMenuMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/menu-engineering/menus/${id}`),
    onSuccess: () => {
      toast.success('تم حذف القائمة');
      if (selectedMenu) setSelectedMenu(null);
      qc.invalidateQueries({ queryKey: ['menu-menus'] });
    },
  });

  const renameMenuMutation = useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => api.put(`/menu-engineering/menus/${id}`, { name }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['menu-menus'] });
    },
  });

  const createRecipeMutation = useMutation({
    mutationFn: (data: any) => api.post('/menu-engineering/recipes', data),
    onSuccess: () => {
      toast.success('تم إنشاء الصنف');
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
    },
  });

  const saveItemsMutation = useMutation({
    mutationFn: (items: any[]) =>
      api.post(`/menu-engineering/recipes/${selectedRecipe?.id}/sync-items`, { items }),
    onSuccess: () => {
      toast.success('تم الحفظ');
      qc.invalidateQueries({ queryKey: ['menu-recipe', selectedRecipe?.id] });
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      if (selectedRecipe?.id) loadRecipeSheet(selectedRecipe.id);
    },
  });

  const deleteRecipeMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/menu-engineering/recipes/${id}`),
    onSuccess: () => {
      toast.success('تم حذف الصنف');
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
    },
  });

  const bulkDeleteRecipesMutation = useMutation({
    mutationFn: (ids: string[]) => Promise.all(ids.map(id => api.delete(`/menu-engineering/recipes/${id}`))),
    onSuccess: (_data, ids) => {
      toast.success(`تم حذف ${ids.length} صنف`);
      setSelectedIds(new Set());
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
    },
  });

  const updateRecipeMutation = useMutation({
    mutationFn: (data: any) => api.put(`/menu-engineering/recipes/${selectedRecipe?.id}`, data),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      setRecipeData((prev: any) => prev ? { ...prev, data: { ...prev.data, ...res.data?.data } } : prev);
      setSelectedRecipe((prev: any) => prev ? { ...prev, ...res.data?.data } : prev);
    },
  });

  const [newItemName, setNewItemName] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [sortBy, setSortBy] = useState('name');
  const [viewMode, setViewMode] = useState<'list' | 'cards'>('list');
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [editingNameId, setEditingNameId] = useState<string | null>(null);
  const [editingName, setEditingName] = useState('');
  const [editingMenuId, setEditingMenuId] = useState<string | null>(null);
  const [editingMenuName, setEditingMenuName] = useState('');

  const renameRecipeMutation = useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => api.put(`/menu-engineering/recipes/${id}`, { name }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      setEditingNameId(null);
    },
  });

  // ── Sheet data loading ──
  const loadRecipeSheet = (recipeId: string) => {
    api.get(`/menu-engineering/recipes/${recipeId}`).then((r) => {
      setRecipeData(r.data);
      setSelectedRecipe(r.data.data);
      setLevel('sheet');
    });
  };

  // ── Helpers ──
  const unitsList = ['kg', 'Piece', 'Litre'];
  const getCF = (from: string, to: string) => {
    if (from === to) return 1;
    const found = (unitData?.conversions ?? []).find((c: any) => c.from_unit === from && c.to_unit === to);
    return found ? parseFloat(found.factor) : 1;
  };
  const calcRow = (r: any) => {
    const cf = getCF(r.purchase_unit || 'kg', r.recipe_unit || 'g');
    const uc = (r.purchase_unit_price || 0) / (cf || 1);
    const y = (r.yield_pct || 100) / 100;
    const ep = uc > 0 && y > 0 ? parseFloat((uc / y).toFixed(4)) : 0;
    const lt = parseFloat((ep * (r.qty || 0)).toFixed(4));
    const ru = r.recipe_unit || 'g'; const q = r.qty || 0;
    return { ...r, conversion_factor: cf, ep_cost: ep, line_total: lt, weight_g: ru === 'g' || ru === 'kg' ? (ru === 'kg' ? q * 1000 : q) : null, volume_ml: ru === 'ml' || ru === 'liter' ? (ru === 'liter' ? q * 1000 : q) : null };
  };

  const getTotals = (items: any[]) => {
    const tc = items.reduce((s: number, i: any) => s + (i.line_total || 0), 0);
    const p = Math.max(1, recipeData?.data?.portions || 1);
    const sp = recipeData?.data?.selling_price || 0;
    return { totalCost: tc, costPerPortion: tc / p, foodCostPct: sp > 0 ? (tc / sp) * 100 : 0, idealPrice: tc / ((recipeData?.data?.target_food_cost_pct || 30) / 100) };
  };

  const ingredientNames = ingredients.map((i: any) => i.name);
  const unitCostMap = Object.fromEntries(ingredients.map((i: any) => [i.id, i.default_cost]));
  const ingredientNameMap = Object.fromEntries(ingredients.map((i: any) => [i.id, i.name]));
  const ingredientIdMap = Object.fromEntries(ingredients.map((i: any) => [i.name, i.id]));

  // ── Save sheet items ──
  const handleSaveSheet = () => {
    const hot = hotRef.current?.hotInstance;
    if (!hot) return;
    const raw = hot.getData();
    const items = raw.filter((r: any[]) => r[1] && r[0]).map((r: any[], i: number) => calcRow({
      ingredient_id: r[0] || ingredientIdMap[r[1]] || '', qty: parseFloat(r[2]) || 0, purchase_unit: r[3] || 'kg',
      purchase_unit_price: parseFloat(r[4]) || 0, recipe_unit: r[5] || 'g',
      conversion_factor: 1, yield_pct: parseFloat(r[6]) || 100, sort_order: i,
    }));
    saveItemsMutation.mutate(items);
  };

  // ── Sheet columns ──
  const sheetColumns = [
    { data: 0, title: 'ID', type: 'text', visible: false },
    { data: 1, title: 'Ingredient', type: 'autocomplete', source: ingredientNames, width: 180, strict: false },
    { data: 2, title: 'Quantity', type: 'numeric', numericFormat: { minimumFractionDigits: 3, maximumFractionDigits: 3 }, width: 90 },
    { data: 3, title: 'Purchase Unit', type: 'dropdown', source: unitsList, width: 110 },
    { data: 4, title: 'Unit Price', type: 'numeric', numericFormat: { minimumFractionDigits: 2, maximumFractionDigits: 2 }, width: 100 },
    { data: 5, title: 'Recipe Unit', type: 'dropdown', source: unitsList, width: 100 },
    { data: 6, title: 'Yield %', type: 'numeric', numericFormat: { minimumFractionDigits: 0, maximumFractionDigits: 0 }, width: 80 },
    { data: 7, title: 'EP Cost', type: 'numeric', readOnly: true, numericFormat: { minimumFractionDigits: 4, maximumFractionDigits: 4 }, width: 95 },
    { data: 8, title: 'Line Total', type: 'numeric', readOnly: true, numericFormat: { minimumFractionDigits: 2, maximumFractionDigits: 2 }, width: 95 },
  ];

  const getSheetData = () => (selectedRecipe as any)?.items?.map((i: any) => [
    i.ingredient_id, i.ingredient_name || ingredientNameMap[i.ingredient_id] || '', i.qty, i.purchase_unit,
    i.purchase_unit_price, i.recipe_unit, i.yield_pct, i.ep_cost, i.line_total,
  ]) || [];

  const handleAfterChange = (changes: any[] | null) => {
    if (!changes) return;
    const hot = hotRef.current?.hotInstance;
    if (!hot) return;
    for (const [row, prop] of changes) {
      const colMap: any = { 0: 'ingredient_id', 1: 'ingredient_name', 2: 'qty', 3: 'purchase_unit', 4: 'purchase_unit_price', 5: 'recipe_unit', 6: 'yield_pct' };
      const field = colMap[prop];
      if (!field) continue;
      const raw = hot.getData();
      const r = raw[row];
      if (!r) continue;

      if (field === 'ingredient_name') {
        const name = r[1];
        const id = ingredientIdMap[name];
        if (id) {
          hot.setDataAtCell(row, 0, id, 'auto');
          if (unitCostMap[id]) hot.setDataAtCell(row, 4, unitCostMap[id], 'auto');
        }
      }

      if (!r[0]) continue;
      const item = { ingredient_id: r[0], qty: parseFloat(r[2]) || 0, purchase_unit: r[3] || 'kg', purchase_unit_price: parseFloat(r[4]) || 0, recipe_unit: r[5] || 'g', conversion_factor: 1, yield_pct: parseFloat(r[6]) || 100 };
      const calc = calcRow(item);
      hot.setDataAtCell(row, 7, calc.ep_cost, 'auto');
      hot.setDataAtCell(row, 8, calc.line_total, 'auto');
    }
  };

  // ── Render: Branch Cards ──
  const renderBranches = () => (
    <div>
      <h3 className="text-sm font-medium text-gray-500 mb-4">اختر الفرع</h3>
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {branches.map((w: any) => (
          <div
            key={w.id}
            onClick={() => { setSelectedBranch(w); setSelectedMenu(null); setLevel('menus'); }}
            className="bg-white border-2 border-gray-200 rounded-2xl p-6 cursor-pointer hover:border-blue-400 hover:shadow-lg transition-all text-center"
          >
            <div className="text-3xl mb-2">🏪</div>
            <div className="font-bold text-gray-700">{w.name}</div>
          </div>
        ))}
      </div>
    </div>
  );

  // ── Render: Menu Cards ──
  const [newMenuName, setNewMenuName] = useState('');
  const renderMenus = () => (
    <div>
      <button onClick={() => setLevel('branches')} className="text-xs text-blue-600 hover:underline mb-3 block">← العودة للفروع</button>
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-medium text-gray-500">{selectedBranch?.name} — اختر القائمة</h3>
        <div className="flex gap-2">
          <input value={newMenuName} onChange={(e) => setNewMenuName(e.target.value)} placeholder="اسم قائمة جديدة" className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none w-44" />
          <button onClick={() => {
            if (!newMenuName) return;
            createMenuMutation.mutate({ name: newMenuName, branch_id: selectedBranch?.id });
            setNewMenuName('');
          }} className="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">+ إضافة</button>
        </div>
      </div>
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {menus.map((m: any) => (
          <div
            key={m.id}
            onClick={() => { setSelectedMenu(m); setLevel('categories'); }}
            className="bg-white border-2 border-gray-200 rounded-2xl p-6 cursor-pointer hover:border-purple-400 hover:shadow-lg transition-all text-center relative"
          >
            <div className="text-2xl mb-1">📑</div>
            <div className="font-bold text-gray-700 mb-2" onClick={(e) => e.stopPropagation()}>
              {editingMenuId === m.id ? (
                <input autoFocus value={editingMenuName} onChange={(e) => setEditingMenuName(e.target.value)}
                  onBlur={() => { if (editingMenuName.trim()) renameMenuMutation.mutate({ id: m.id, name: editingMenuName.trim() }); else setEditingMenuId(null); }}
                  onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); if (e.key === 'Escape') setEditingMenuId(null); }}
                  className="border border-purple-300 rounded px-1 py-0.5 text-sm w-full outline-none text-center" />
              ) : (
                <span className="cursor-pointer hover:text-purple-600 border-b border-dashed border-gray-300"
                  onDoubleClick={() => { setEditingMenuId(m.id); setEditingMenuName(m.name); }}>
                  {m.name}
                </span>
              )}
            </div>
            <div className="flex gap-2 justify-center" onClick={(e) => e.stopPropagation()}>
              <button onClick={() => { setEditingMenuId(m.id); setEditingMenuName(m.name); }} className="text-xs text-purple-500 hover:text-purple-700">✎</button>
              <button onClick={() => { if (confirm(`حذف القائمة "${m.name}"?`)) deleteMenuMutation.mutate(m.id); }} className="text-xs text-red-400 hover:text-red-700">✕</button>
            </div>
          </div>
        ))}
        {menus.length === 0 && (
          <div className="col-span-full text-center text-gray-400 py-12">لا توجد قوائم لهذا الفرع — أضف أول قائمة</div>
        )}
      </div>
    </div>
  );

  // ── Category mutations ──
  const createCategoryMutation = useMutation({
    mutationFn: (data: any) => api.post('/menu-engineering/categories', data),
    onSuccess: () => {
      toast.success('تم إنشاء التصنيف');
      setNewCategoryName('');
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
      qc.invalidateQueries({ queryKey: ['menu-categories-modal'] });
    },
  });
  const deleteCategoryMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/menu-engineering/categories/${id}`),
    onSuccess: () => {
      toast.success('تم حذف التصنيف');
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
      qc.invalidateQueries({ queryKey: ['menu-categories-modal'] });
    },
  });

  // ── Category Modal ──
  const renderCategoryModal = () => {
    if (!showCategoryModal) return null;
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowCategoryModal(false)}>
        <div className="bg-white rounded-2xl p-6 w-full max-w-md shadow-xl" onClick={(e) => e.stopPropagation()}>
          <h3 className="text-lg font-bold mb-4">إدارة التصنيفات</h3>

          <div className="flex gap-2 mb-4">
            <input value={newCategoryName} onChange={(e) => setNewCategoryName(e.target.value)} placeholder="اسم التصنيف الجديد" className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none" />
            <button onClick={() => { if (newCategoryName) createCategoryMutation.mutate({ name: newCategoryName, menu_id: selectedMenu?.id }); }} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">إضافة</button>
          </div>

          <div className="space-y-2 max-h-64 overflow-y-auto">
            {modalCategories.map((c: any) => (
              <div key={c.id} className="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                <span className="text-sm">{c.name}</span>
                <button onClick={() => { if (confirm(`حذف "${c.name}"?`)) deleteCategoryMutation.mutate(c.id); }} className="text-red-500 hover:text-red-700 text-xs px-2 py-1">✕</button>
              </div>
            ))}
            {modalCategories.length === 0 && (
              <p className="text-gray-400 text-sm text-center py-4">لا توجد تصنيفات لهذه القائمة — أضف أول تصنيف</p>
            )}
          </div>

          <button onClick={() => setShowCategoryModal(false)} className="mt-4 w-full py-2 text-gray-500 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إغلاق</button>
        </div>
      </div>
    );
  };

  // ── Render: Category Cards ──
  const renderCategories = () => { return (
      <div>
        <button onClick={() => setLevel('menus')} className="text-xs text-blue-600 hover:underline mb-3 block">← العودة للقوائم</button>
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-sm font-medium text-gray-500">
            {selectedBranch?.name} / {selectedMenu?.name} — اختر التصنيف
          </h3>
          <button onClick={() => setShowCategoryModal(true)} className="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200">⚙ إدارة التصنيفات</button>
        </div>

        {categories.length === 0 ? (
          <div className="bg-white border border-gray-100 rounded-xl p-6 text-center">
            <p className="text-gray-400">لا توجد تصنيفات لهذا الفرع — استخدم "إدارة التصنيفات" للإضافة</p>
          </div>
        ) : (
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            {categories.map((c: any) => (
              <div
                key={c.id}
                onClick={() => { setSelectedCategory(c); setLevel('items'); }}
                className="bg-white border-2 border-gray-200 rounded-2xl p-6 cursor-pointer hover:border-green-400 hover:shadow-lg transition-all text-center"
              >
                <div className="text-2xl mb-1">{c.icon || '📋'}</div>
                <div className="font-bold text-gray-700">{c.name}</div>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  };

  // ── Filtered + sorted recipes ──
  const filteredRecipes = recipes
    .filter((r: any) => !searchTerm || r.name.toLowerCase().includes(searchTerm.toLowerCase()))
    .sort((a: any, b: any) => {
      if (sortBy === 'name') return a.name.localeCompare(b.name);
      if (sortBy === 'cost') return (a.total_cost || 0) - (b.total_cost || 0);
      if (sortBy === 'cost_desc') return (b.total_cost || 0) - (a.total_cost || 0);
      if (sortBy === 'price') return (a.selling_price || 0) - (b.selling_price || 0);
      if (sortBy === 'status') return (a.status || '').localeCompare(b.status || '');
      return 0;
    });

  const toggleSelect = (id: string) => {
    setSelectedIds(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  // ── Render: Items List ──
  const renderItems = () => (
    <div>
      <button onClick={() => setLevel('categories')} className="text-xs text-blue-600 hover:underline mb-3 block">→ العودة للتصنيفات</button>

      {/* Header */}
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-medium text-gray-500">
          {selectedBranch?.name} / {selectedMenu?.name} / {selectedCategory?.name}
        </h3>
        <div className="flex gap-2">
          <input value={newItemName} onChange={(e) => setNewItemName(e.target.value)} placeholder="اسم صنف جديد" className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none w-48" />
          <button
            onClick={() => {
              if (!newItemName) return;
              createRecipeMutation.mutate({ name: newItemName, category: selectedCategory?.name, branch_id: selectedBranch?.id || undefined, menu_id: selectedMenu?.id || undefined, status: 'active', portions: 1 });
              setNewItemName('');
            }}
            className="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700"
          >
            + إضافة
          </button>
        </div>
      </div>

      {/* Toolbar: filter, sort, view toggle, bulk delete */}
      <div className="flex items-center gap-3 mb-3 flex-wrap">
        <div className="relative flex-1 max-w-xs">
          <input value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} placeholder="بحث بالاسم..."
            className="w-full border border-gray-200 rounded-lg px-3 py-1.5 pr-8 text-sm outline-none" />
          {searchTerm && <button onClick={() => setSearchTerm('')} className="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-xs">✕</button>}
        </div>

        <select value={sortBy} onChange={(e) => setSortBy(e.target.value)}
          className="border border-gray-200 rounded-lg px-2 py-1.5 text-sm outline-none">
          <option value="name">الاسم</option>
          <option value="cost">التكلفة ↑</option>
          <option value="cost_desc">التكلفة ↓</option>
          <option value="price">سعر البيع</option>
          <option value="status">الحالة</option>
        </select>

        <div className="flex border border-gray-200 rounded-lg overflow-hidden">
          <button onClick={() => setViewMode('list')}
            className={`px-2.5 py-1.5 text-sm ${viewMode === 'list' ? 'bg-gray-100 text-gray-700' : 'bg-white text-gray-400 hover:text-gray-600'}`}>☰</button>
          <button onClick={() => setViewMode('cards')}
            className={`px-2.5 py-1.5 text-sm ${viewMode === 'cards' ? 'bg-gray-100 text-gray-700' : 'bg-white text-gray-400 hover:text-gray-600'}`}>⊞</button>
        </div>

        {selectedIds.size > 0 && (
          <button onClick={() => {
            if (confirm(`حذف ${selectedIds.size} صنف(أصناف)؟`)) bulkDeleteRecipesMutation.mutate(Array.from(selectedIds));
          }} className="px-3 py-1.5 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">
            ✕ حذف المحدد ({selectedIds.size})
          </button>
        )}
      </div>

      {/* Cards View */}
      {viewMode === 'cards' && (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
          {filteredRecipes.map((r: any) => (
            <div key={r.id}
              className={`bg-white border-2 rounded-xl p-4 cursor-pointer transition-all hover:shadow-md text-center relative ${selectedIds.has(r.id) ? 'border-blue-500 shadow-md' : 'border-gray-100'}`}
              onClick={() => loadRecipeSheet(r.id)}
            >
              <div className="absolute top-2 right-2 z-10">
                <input type="checkbox" checked={selectedIds.has(r.id)}
                  onChange={(e) => { e.stopPropagation(); toggleSelect(r.id); }}
                  className="accent-blue-600 cursor-pointer" />
              </div>
              <div className="text-2xl mb-1">🍽️</div>
              <div className="font-bold text-sm text-gray-800 mb-1" onClick={(e) => e.stopPropagation()}>
                {editingNameId === r.id ? (
                  <input autoFocus value={editingName} onChange={(e) => setEditingName(e.target.value)}
                    onBlur={() => { if (editingName.trim()) renameRecipeMutation.mutate({ id: r.id, name: editingName.trim() }); else setEditingNameId(null); }}
                    onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); if (e.key === 'Escape') setEditingNameId(null); }}
                    className="border border-blue-300 rounded px-1 py-0.5 text-sm w-full outline-none text-center" />
                ) : (
                  <span className="cursor-pointer hover:text-blue-600 border-b border-dashed border-gray-300"
                    onDoubleClick={() => { setEditingNameId(r.id); setEditingName(r.name); }}>
                    {r.name}
                  </span>
                )}
              </div>
              <div className={`text-xs px-2 py-0.5 rounded-full inline-block ${r.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>{r.status}</div>
              <div className="mt-2 text-xs text-blue-700 font-mono">{r.total_cost?.toFixed(2)} ج</div>
              <div className="text-xs text-green-600 font-mono">{r.cost_per_portion?.toFixed(2)} ج/حصة</div>
              <div className="text-xs text-gray-400 mt-1">{r.items_count} بند</div>
            </div>
          ))}
          {filteredRecipes.length === 0 && (
            <div className="col-span-full text-center text-gray-400 py-12">لا توجد أصناف</div>
          )}
        </div>
      )}

      {/* List View */}
      {viewMode === 'list' && (
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr className="text-gray-500 text-xs">
                <th className="px-2 py-3 text-center w-8">
                  <input type="checkbox" onChange={(e) => {
                    if (e.target.checked) setSelectedIds(new Set(filteredRecipes.map((r: any) => r.id)));
                    else setSelectedIds(new Set());
                  }} checked={filteredRecipes.length > 0 && selectedIds.size === filteredRecipes.length}
                    className="accent-blue-600 cursor-pointer" />
                </th>
                <th className="px-4 py-3 text-right font-medium">الاسم</th>
                <th className="px-4 py-3 text-right font-medium">الحالة</th>
                <th className="px-4 py-3 text-left font-medium">الحصص</th>
                <th className="px-4 py-3 text-left font-medium">التكلفة</th>
                <th className="px-4 py-3 text-left font-medium">تكلفة/حصة</th>
                <th className="px-4 py-3 text-center font-medium">البنود</th>
                <th className="px-4 py-3 text-center"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {filteredRecipes.map((r: any) => (
                <tr key={r.id} className="hover:bg-blue-50/20 cursor-pointer" onClick={() => loadRecipeSheet(r.id)}>
                  <td className="px-2 py-3 text-center" onClick={(e) => e.stopPropagation()}>
                    <input type="checkbox" checked={selectedIds.has(r.id)} onChange={() => toggleSelect(r.id)}
                      className="accent-blue-600 cursor-pointer" />
                  </td>
                  <td className="px-4 py-3 font-medium text-gray-800" onClick={(e) => e.stopPropagation()}>
                    {editingNameId === r.id ? (
                      <input autoFocus value={editingName} onChange={(e) => setEditingName(e.target.value)}
                        onBlur={() => { if (editingName.trim()) renameRecipeMutation.mutate({ id: r.id, name: editingName.trim() }); else setEditingNameId(null); }}
                        onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); if (e.key === 'Escape') setEditingNameId(null); }}
                        className="border border-blue-300 rounded px-2 py-0.5 text-sm w-full outline-none" />
                    ) : (
                      <span className="cursor-pointer hover:text-blue-600 border-b border-dashed border-gray-300"
                        onDoubleClick={() => { setEditingNameId(r.id); setEditingName(r.name); }}>
                        {r.name}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <span className={`text-xs px-2 py-0.5 rounded-full ${r.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>{r.status}</span>
                  </td>
                  <td className="px-4 py-3 text-left font-mono">{r.portions}</td>
                  <td className="px-4 py-3 text-left font-mono text-blue-700 font-medium">{r.total_cost?.toFixed(2)} ج</td>
                  <td className="px-4 py-3 text-left font-mono text-green-700">{r.cost_per_portion?.toFixed(2)} ج</td>
                  <td className="px-4 py-3 text-center text-gray-500">{r.items_count}</td>
                  <td className="px-4 py-3 text-center whitespace-nowrap">
                    <button onClick={(e) => { e.stopPropagation(); loadRecipeSheet(r.id); }} className="text-xs text-blue-500 hover:text-blue-700 ml-3">فتح</button>
                    <button onClick={(e) => { e.stopPropagation(); if (confirm(`حذف "${r.name}"?`)) deleteRecipeMutation.mutate(r.id); }} className="text-xs text-red-400 hover:text-red-700">✕</button>
                  </td>
                </tr>
              ))}
              {filteredRecipes.length === 0 && (
                <tr><td colSpan={8} className="px-4 py-12 text-center text-gray-400">لا توجد أصناف في هذا التصنيف — أضف أول صنف</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );

  // ── Render: Recipe Sheet ──
  const renderSheet = () => {
    const d = selectedRecipe;
    if (!d || !recipeData) return null;
    const totals = getTotals(d.items || []);
    return (
      <div>
        <button onClick={() => setLevel('items')} className="text-xs text-blue-600 hover:underline mb-3 block">→ العودة للأصناف</button>

        {/* Header */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm mb-4">
          <div className="flex items-center justify-between mb-3">
            <div>
              <h2 className="text-lg font-bold text-gray-800">{d.name}</h2>
              <p className="text-xs text-gray-500">{d.code || ''} · v{d.version} · {d.category} · {d.branch_id ? warehouses.find((w: any) => w.id === d.branch_id)?.name : ''}{selectedMenu ? ` / ${selectedMenu.name}` : ''}</p>
            </div>
            <div className="flex gap-2">
              <button onClick={() => {
                const hot = hotRef.current?.hotInstance;
                if (hot) hot.alter('insert_row_below');
              }} className="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">+ إضافة بند</button>
              <button onClick={() => {
                const hot = hotRef.current?.hotInstance;
                if (!hot) return;
                const sel = hot.getSelectedLast();
                let idx = sel ? sel[0] : hot.countRows() - 1;
                if (idx < 0) return;
                const data = hot.getSourceData();
                data.splice(idx, 1);
                hot.loadData(data);
              }} className="px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-sm hover:bg-red-200">🗑 حذف البند</button>
              <button onClick={handleSaveSheet} disabled={saveItemsMutation.isPending} className="px-4 py-1.5 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                {saveItemsMutation.isPending ? '...' : '💾 حفظ الشيت'}
              </button>
            </div>
          </div>

          {/* Meta */}
          <div className="grid grid-cols-4 gap-3 text-sm">
            {[
              { label: 'سعر البيع', val: d.selling_price, key: 'selling_price' },
              { label: 'الحصص', val: d.portions, key: 'portions' },
              { label: 'Target FC%', val: d.target_food_cost_pct, key: 'target_food_cost_pct' },
            ].map((f) => (
              <div key={f.key}>
                <label className="text-xs text-gray-500">{f.label}</label>
                <input type="number" defaultValue={f.val ?? ''}
                  onBlur={(e) => {
                    const val = parseFloat(e.target.value);
                    if (!isNaN(val)) updateRecipeMutation.mutate({ [f.key]: val });
                  }}
                  className="w-full border border-gray-200 rounded px-2 py-1 text-sm outline-none" />
              </div>
            ))}
            <div>
              <label className="text-xs text-gray-500">الحالة</label>
              <select value={d.status || 'draft'} onChange={(e) => updateRecipeMutation.mutate({ status: e.target.value })}
                className="w-full border border-gray-200 rounded px-2 py-1 text-sm outline-none">
                <option value="draft">مسودة</option>
                <option value="active">نشط</option>
                <option value="inactive">غير نشط</option>
              </select>
            </div>
          </div>
        </div>

        {/* Handsontable */}
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden mb-4">
          <HotTable
            ref={hotRef}
            data={getSheetData()}
            columns={sheetColumns}
            colHeaders={['', 'الصنف', 'الكمية', 'وحدة الشراء', 'سعر الوحدة', 'وحدة الريسيبي', 'Yield %', 'EP Cost', 'الإجمالي']}
            rowHeaders={true}
            width="100%"
            height={400}
            licenseKey="non-commercial-and-evaluation"
            afterChange={handleAfterChange}
            contextMenu={{
              items: {
                'insert_row_below': { name: 'إدراج صف' },
                'remove_row': { name: 'حذف الصف' },
                'hsep1': '---------',
                'undo': { name: 'تراجع' },
                'redo': { name: 'إعادة' },
              }
            }}
            fillHandle={true}
            stretchH="all"
            manualColumnResize={true}
          />
        </div>

        {/* Totals */}
        <div className="grid grid-cols-4 gap-4">
          {[
            { label: 'Total Cost', value: totals.totalCost, color: 'text-blue-700', bg: 'bg-blue-50' },
            { label: 'Cost/Portion', value: totals.costPerPortion, color: 'text-green-700', bg: 'bg-green-50' },
            { label: 'Food Cost %', value: totals.foodCostPct.toFixed(1), color: totals.foodCostPct > 35 ? 'text-red-600' : 'text-green-600', bg: 'bg-amber-50' },
            { label: 'Ideal Price', value: totals.idealPrice, color: 'text-orange-700', bg: 'bg-orange-50' },
          ].map((k) => (
            <div key={k.label} className={`${k.bg} border border-gray-100 rounded-xl p-3 shadow-sm text-center`}>
              <div className="text-xs text-gray-500">{k.label}</div>
              <div className={`text-lg font-bold mt-1 ${k.color}`}>
                {typeof k.value === 'number' ? k.value.toFixed(2) : k.value} ج
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="هندسة القائمة (Menu Engineering)"
        subtitle={level === 'branches' ? 'اختر الفرع للبدء' : level === 'menus' ? 'اختر القائمة' : level === 'categories' ? 'اختر التصنيف' : level === 'items' ? `${selectedCategory?.name}` : selectedRecipe?.name || ''}
      />
      <div className="flex-1 overflow-y-auto p-6">
        {level === 'branches' && renderBranches()}
        {level === 'menus' && renderMenus()}
        {level === 'categories' && renderCategories()}
        {level === 'items' && renderItems()}
        {level === 'sheet' && renderSheet()}
        {renderCategoryModal()}
      </div>
    </div>
  );
}
