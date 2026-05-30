'use client';

import { useRef, useState, useMemo } from 'react';
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
  const selectedRowRef = useRef<number>(0);
  const dragStartRowRef = useRef<number>(-1);

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
    queryKey: ['warehouses', currentClient?.id],
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

  const bulkUpdateMutation = useMutation({
    mutationFn: (data: any) => api.post('/menu-engineering/recipes/bulk-update-item-quantity', data),
    onSuccess: (res) => {
      toast.success(`تم تحديث ${res.data.affected_count} بند في ${res.data.affected_recipe_ids.length} ريسبي`);
      setShowBulkUpdateModal(false);
      qc.invalidateQueries({ queryKey: ['menu-recipe', selectedRecipe?.id] });
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      if (selectedRecipe?.id) loadRecipeSheet(selectedRecipe.id);
    },
    onError: () => {
      toast.error('حدث خطأ أثناء التحديث');
    },
  });

  const updateExclusionMutation = useMutation({
    mutationFn: ({ id, field, value }: { id: string; field: string; value: boolean }) =>
      api.put(`/menu-engineering/recipes/${id}`, { [field]: value }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      qc.invalidateQueries({ queryKey: ['menu-recipes-full'] });
    },
  });

  const [newItemName, setNewItemName] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [sortBy, setSortBy] = useState('name');
  const [viewMode, setViewMode] = useState<'list' | 'cards'>('list');
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [editingNameId, setEditingNameId] = useState<string | null>(null);
  const [editingName, setEditingName] = useState('');
  const [editingPriceId, setEditingPriceId] = useState<string | null>(null);
  const [editingPrice, setEditingPrice] = useState('');
  const [editingMenuId, setEditingMenuId] = useState<string | null>(null);
  const [editingMenuName, setEditingMenuName] = useState('');
  const [showMoveCategoryModal, setShowMoveCategoryModal] = useState(false);
  const [moveCategoryTarget, setMoveCategoryTarget] = useState('');
  const [showBulkUpdateModal, setShowBulkUpdateModal] = useState(false);
  const [bulkUpdateIngredient, setBulkUpdateIngredient] = useState<{ name: string; id: string; currentQty: number } | null>(null);
  const [bulkUpdateQty, setBulkUpdateQty] = useState<number>(0);
  const [bulkRecipeIds, setBulkRecipeIds] = useState<string[]>([]);
  const [showCopyMenuModal, setShowCopyMenuModal] = useState(false);
  const [copyMenuSource, setCopyMenuSource] = useState<any>(null);
  const [copyMenuName, setCopyMenuName] = useState('');
  const [copyMenuBranchId, setCopyMenuBranchId] = useState('');
  const [showCopyRecipeModal, setShowCopyRecipeModal] = useState(false);
  const [copyRecipeSource, setCopyRecipeSource] = useState<any>(null);
  const [copyRecipeName, setCopyRecipeName] = useState('');
  const [copyRecipeCategory, setCopyRecipeCategory] = useState('');
  const [showCopyCategoryModal, setShowCopyCategoryModal] = useState(false);
  const [copyCategorySource, setCopyCategorySource] = useState<any>(null);
  const [copyCategoryName, setCopyCategoryName] = useState('');
  const [editingCategoryId, setEditingCategoryId] = useState<string | null>(null);
  const [editingCategoryName, setEditingCategoryName] = useState('');
  const [editingStatusId, setEditingStatusId] = useState<string | null>(null);
  const [editingStatus, setEditingStatus] = useState('');
  const [showBulkAddModal, setShowBulkAddModal] = useState(false);
  const [bulkAddIngredientId, setBulkAddIngredientId] = useState('');
  const [bulkAddQty, setBulkAddQty] = useState(0);
  const [showBulkReplaceModal, setShowBulkReplaceModal] = useState(false);
  const [bulkReplaceOldName, setBulkReplaceOldName] = useState('');
  const [bulkReplaceOldId, setBulkReplaceOldId] = useState('');
  const [bulkReplaceNewId, setBulkReplaceNewId] = useState('');
  const [showBulkDeleteModal, setShowBulkDeleteModal] = useState(false);
  const [bulkDeleteIngredientName, setBulkDeleteIngredientName] = useState('');
  const [bulkDeleteIngredientId, setBulkDeleteIngredientId] = useState('');

  const renameRecipeMutation = useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => api.put(`/menu-engineering/recipes/${id}`, { name }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'فشل إعادة التسمية');
    },
    onSettled: () => {
      setEditingNameId(null);
    },
  });

  const updateSellingPriceMutation = useMutation({
    mutationFn: ({ id, selling_price }: { id: string; selling_price: number }) =>
      api.put(`/menu-engineering/recipes/${id}`, { selling_price }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      setEditingPriceId(null);
    },
  });

  const updateStatusMutation = useMutation({
    mutationFn: ({ id, status }: { id: string; status: string }) =>
      api.put(`/menu-engineering/recipes/${id}`, { status }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'فشل تغيير الحالة');
    },
    onSettled: () => {
      setEditingStatusId(null);
    },
  });

  const copyMenuMutation = useMutation({
    mutationFn: (data: any) => api.post(`/menu-engineering/menus/${data.menuId}/copy`, { name: data.name, target_branch_id: data.targetBranchId }),
    onSuccess: () => {
      toast.success('تم نسخ القائمة بنجاح');
      qc.invalidateQueries({ queryKey: ['menu-menus'] });
      setShowCopyMenuModal(false);
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'حدث خطأ');
    },
  });

  const copyRecipeMutation = useMutation({
    mutationFn: (data: any) => api.post(`/menu-engineering/recipes/${data.recipeId}/copy`, { name: data.name, category: data.category || undefined }),
    onSuccess: () => {
      toast.success('تم نسخ الصنف بنجاح');
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
      setShowCopyRecipeModal(false);
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'حدث خطأ');
    },
  });

  const copyCategoryMutation = useMutation({
    mutationFn: (data: any) => api.post(`/menu-engineering/categories/${data.category}/copy`, { name: data.name, menu_id: selectedMenu?.id }),
    onSuccess: (res) => {
      toast.success(res.data?.message || 'تم نسخ التصنيف بنجاح');
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
      setShowCopyCategoryModal(false);
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'حدث خطأ');
    },
  });

  const bulkCopyRecipesMutation = useMutation({
    mutationFn: (ids: string[]) => api.post('/menu-engineering/recipes/bulk-copy', { ids }),
    onSuccess: (res) => {
      toast.success(res.data?.message || 'تم نسخ الأصناف بنجاح');
      setSelectedIds(new Set());
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'حدث خطأ');
    },
  });

  const bulkMoveCategoryMutation = useMutation({
    mutationFn: ({ ids, category }: { ids: string[]; category: string }) =>
      api.post('/menu-engineering/recipes/bulk-move-category', { ids, category }),
    onSuccess: (res) => {
      toast.success(res.data?.message || 'تم نقل الأصناف بنجاح');
      setSelectedIds(new Set());
      setShowMoveCategoryModal(false);
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'حدث خطأ');
    },
  });

  const bulkAddItemMutation = useMutation({
    mutationFn: (data: any) => api.post('/menu-engineering/recipes/bulk-add-item', data),
    onSuccess: (res) => {
      toast.success(res.data?.message || 'تم إضافة الصنف بنجاح');
      setShowBulkAddModal(false);
      qc.invalidateQueries({ queryKey: ['menu-recipe', selectedRecipe?.id] });
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      if (selectedRecipe?.id) loadRecipeSheet(selectedRecipe.id);
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'حدث خطأ');
    },
  });

  const bulkReplaceItemMutation = useMutation({
    mutationFn: (data: any) => api.post('/menu-engineering/recipes/bulk-replace-item', data),
    onSuccess: (res) => {
      toast.success(res.data?.message || 'تم استبدال الصنف بنجاح');
      setShowBulkReplaceModal(false);
      qc.invalidateQueries({ queryKey: ['menu-recipe', selectedRecipe?.id] });
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      if (selectedRecipe?.id) loadRecipeSheet(selectedRecipe.id);
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'حدث خطأ');
    },
  });

  const bulkDeleteItemMutation = useMutation({
    mutationFn: (data: any) => api.post('/menu-engineering/recipes/bulk-delete-item', data),
    onSuccess: (res) => {
      toast.success(res.data?.message || 'تم حذف الصنف بنجاح');
      setShowBulkDeleteModal(false);
      qc.invalidateQueries({ queryKey: ['menu-recipe', selectedRecipe?.id] });
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      if (selectedRecipe?.id) loadRecipeSheet(selectedRecipe.id);
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'حدث خطأ');
    },
  });

  const renameCategoryMutation = useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => api.put(`/menu-engineering/categories/${id}`, { name }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['menu-categories'] });
      qc.invalidateQueries({ queryKey: ['menu-recipes'] });
      setEditingCategoryId(null);
    },
  });

  // ── Sheet data loading ──
  const loadRecipeSheet = (recipeId: string) => {
    qc.invalidateQueries({ queryKey: ['menu-ingredients'] });
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
    const cf = r.conversion_factor || getCF(r.purchase_unit || 'kg', r.recipe_unit || 'g');
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

  const getHotTotals = () => {
    const hot = hotRef.current?.hotInstance;
    if (!hot) return null;
    const data = hot.getData();
    const lineTotals = data
      .filter((r: any[]) => r[0] && ingredientIdMap[r[0]])
      .map((r: any[]) => calcRow({
        ingredient_id: ingredientIdMap[r[0]], qty: parseFloat(r[1]) || 0, purchase_unit: r[2] || 'kg',
        purchase_unit_price: parseFloat(r[3]) || 0, recipe_unit: r[4] || 'g',
        conversion_factor: parseFloat(r[5]) || 1, yield_pct: parseFloat(r[6]) || 100,
      }).line_total);
    const tc = lineTotals.reduce((s: number, v: number) => s + v, 0);
    const p = Math.max(1, recipeData?.data?.portions ?? 1);
    const sp = recipeData?.data?.selling_price ?? 0;
    const target = recipeData?.data?.target_food_cost_pct ?? 30;
    return {
      totalCost: tc,
      costPerPortion: tc / p,
      foodCostPct: sp > 0 ? (tc / sp) * 100 : 0,
      idealPrice: target > 0 ? tc / (target / 100) : 0,
    };
  };

  const downloadMenuExport = async (format: 'excel' | 'pdf') => {
    if (!selectedMenu?.id) return;
    try {
      const res = await api.get(`/menu-engineering/menus/${selectedMenu.id}/export-${format}`, { responseType: 'blob' });
      const blob = new Blob([res.data]);
      const link = document.createElement('a'); link.href = URL.createObjectURL(blob);
      link.download = `menu_${selectedMenu.name}.${format === 'excel' ? 'xlsx' : 'pdf'}`;
      link.click(); URL.revokeObjectURL(link.href);
    } catch { toast.error('حدث خطأ أثناء التصدير'); }
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
    const items = raw.filter((r: any[]) => r[0] && ingredientIdMap[r[0]]).map((r: any[], i: number) => calcRow({
      ingredient_id: ingredientIdMap[r[0]], qty: parseFloat(r[1]) || 0, purchase_unit: r[2] || 'kg',
      purchase_unit_price: parseFloat(r[3]) || 0, recipe_unit: r[4] || 'g',
      conversion_factor: parseFloat(r[5]) || 1, yield_pct: parseFloat(r[6]) || 100, sort_order: i,
    }));
    saveItemsMutation.mutate(items);
  };

  // ── Sheet columns ──
  const sheetColumns = [
    { data: 0, title: 'Ingredient', type: 'autocomplete', source: ingredientNames, width: 180, strict: false },
    { data: 1, title: 'Quantity', type: 'numeric', numericFormat: { minimumFractionDigits: 3, maximumFractionDigits: 3 }, width: 90 },
    { data: 2, title: 'Purchase Unit', type: 'dropdown', source: unitsList, width: 110 },
    { data: 3, title: 'Unit Price', type: 'numeric', numericFormat: { minimumFractionDigits: 2, maximumFractionDigits: 2 }, width: 100 },
    { data: 4, title: 'Recipe Unit', type: 'dropdown', source: unitsList, width: 100 },
    { data: 5, title: 'CF', type: 'numeric', numericFormat: { minimumFractionDigits: 3, maximumFractionDigits: 3 }, width: 80 },
    { data: 6, title: 'Yield %', type: 'numeric', numericFormat: { minimumFractionDigits: 0, maximumFractionDigits: 0 }, width: 80 },
    { data: 7, title: 'EP Cost', type: 'numeric', readOnly: true, numericFormat: { minimumFractionDigits: 4, maximumFractionDigits: 4 }, width: 95 },
    { data: 8, title: 'Line Total', type: 'numeric', readOnly: true, numericFormat: { minimumFractionDigits: 2, maximumFractionDigits: 2 }, width: 95 },
  ];

  const sheetData = useMemo(
    () => (selectedRecipe as any)?.items?.map((i: any) => [
      i.ingredient_name || ingredientNameMap[i.ingredient_id] || '', i.qty, i.purchase_unit,
      unitCostMap[i.ingredient_id] ?? i.purchase_unit_price,
      i.recipe_unit, i.conversion_factor, i.yield_pct, i.ep_cost, i.line_total,
    ]) || [],
    [selectedRecipe?.items, ingredients]
  );

  const handleAfterChange = (changes: any[] | null) => {
    if (!changes) return;
    const hot = hotRef.current?.hotInstance;
    if (!hot) return;
    for (const [row, prop] of changes) {
      const colMap: any = { 0: 'ingredient_name', 1: 'qty', 2: 'purchase_unit', 3: 'purchase_unit_price', 4: 'recipe_unit', 5: 'conversion_factor', 6: 'yield_pct' };
      const field = colMap[prop];
      if (!field) continue;
      const raw = hot.getData();
      const r = raw[row];
      if (!r) continue;

      if (field === 'ingredient_name') {
        const name = r[0];
        const id = ingredientIdMap[name];
        if (id && unitCostMap[id]) hot.setDataAtCell(row, 3, unitCostMap[id], 'auto');
      }

      if (!r[0] || !ingredientIdMap[r[0]]) continue;
      const item = { ingredient_id: ingredientIdMap[r[0]], qty: parseFloat(r[1]) || 0, purchase_unit: r[2] || 'kg', purchase_unit_price: parseFloat(r[3]) || 0, recipe_unit: r[4] || 'g', conversion_factor: parseFloat(r[5]) || 1, yield_pct: parseFloat(r[6]) || 100 };
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
              <button onClick={() => { setEditingMenuId(m.id); setEditingMenuName(m.name); }} className="text-xs text-purple-500 hover:text-purple-700" title="تعديل الاسم">✎</button>
              <button onClick={() => { setCopyMenuSource(m); setCopyMenuName(''); setCopyMenuBranchId(''); setShowCopyMenuModal(true); }} className="text-xs text-blue-500 hover:text-blue-700" title="نسخ إلى فرع آخر">📋</button>
              <button onClick={() => { if (confirm(`حذف القائمة "${m.name}"?`)) deleteMenuMutation.mutate(m.id); }} className="text-xs text-red-400 hover:text-red-700" title="حذف">✕</button>
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
                {editingCategoryId === c.id ? (
                  <input autoFocus value={editingCategoryName} onChange={(e) => setEditingCategoryName(e.target.value)}
                    onBlur={() => { if (editingCategoryName.trim()) renameCategoryMutation.mutate({ id: c.id, name: editingCategoryName.trim() }); else setEditingCategoryId(null); }}
                    onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); if (e.key === 'Escape') setEditingCategoryId(null); }}
                    className="border border-blue-300 rounded px-2 py-0.5 text-sm outline-none flex-1 ml-2" />
                ) : (
                  <span className="text-sm cursor-pointer hover:text-blue-600 flex items-center gap-1"
                    onClick={() => { setEditingCategoryId(c.id); setEditingCategoryName(c.name); }}>
                    {c.name}
                    <span className="text-gray-300 hover:text-blue-500 text-xs">✏️</span>
                  </span>
                )}
                <div className="flex gap-1">
                  <button onClick={() => { setCopyCategorySource(c); setCopyCategoryName(c.name + ' (نسخة)'); setShowCopyCategoryModal(true); }} className="text-blue-400 hover:text-blue-600 text-xs px-2 py-1" title="نسخ التصنيف">📋</button>
                  <button onClick={() => { if (confirm(`حذف "${c.name}"?`)) deleteCategoryMutation.mutate(c.id); }} className="text-red-500 hover:text-red-700 text-xs px-2 py-1">✕</button>
                </div>
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
          <div className="flex items-center gap-2">
            <button onClick={() => setShowCategoryModal(true)} className="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200">⚙ إدارة التصنيفات</button>
            <button onClick={() => downloadMenuExport('excel')} className="px-3 py-1.5 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700">⬇ Excel</button>
            <button onClick={() => downloadMenuExport('pdf')} className="px-3 py-1.5 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">⬇ PDF</button>
          </div>
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
                className="bg-white border-2 border-gray-200 rounded-2xl p-6 cursor-pointer hover:border-green-400 hover:shadow-lg transition-all text-center relative"
              >
                <div onClick={() => { setSelectedCategory(c); setLevel('items'); }}>
                  <div className="text-2xl mb-1">{c.icon || '📋'}</div>
                  <div className="font-bold text-gray-700">{c.name}</div>
                </div>
                <button onClick={(e) => { e.stopPropagation(); setCopyCategorySource(c); setCopyCategoryName(c.name + ' (نسخة)'); setShowCopyCategoryModal(true); }}
                  className="text-[11px] text-blue-500 hover:text-blue-700 mt-2 font-medium" title="نسخ التصنيف">📋 نسخ</button>
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
          <>
            <button onClick={() => {
              if (confirm(`حذف ${selectedIds.size} صنف(أصناف)؟`)) bulkDeleteRecipesMutation.mutate(Array.from(selectedIds));
            }} className="px-3 py-1.5 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">
              ✕ حذف المحدد ({selectedIds.size})
            </button>
            <button onClick={() => bulkCopyRecipesMutation.mutate(Array.from(selectedIds))}
              className="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
              📋 نسخ المحدد ({selectedIds.size})
            </button>
            <button onClick={() => { setMoveCategoryTarget(''); setShowMoveCategoryModal(true); }}
              className="px-3 py-1.5 bg-amber-600 text-white rounded-lg text-sm hover:bg-amber-700">
              📂 نقل المحدد ({selectedIds.size})
            </button>
          </>
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
              <div className="absolute top-2 right-2 z-10" onClick={(e) => e.stopPropagation()}>
                <input type="checkbox" checked={selectedIds.has(r.id)}
                  onChange={() => toggleSelect(r.id)}
                  className="accent-blue-600 cursor-pointer" />
              </div>
              <div className="text-2xl mb-1">🍽️</div>
              <div className="font-bold text-sm text-gray-800 mb-1">
                {editingNameId === r.id ? (
                  <input autoFocus value={editingName} onChange={(e) => setEditingName(e.target.value)}
                    onBlur={() => { const val = editingName.trim(); setEditingNameId(null); if (val) renameRecipeMutation.mutate({ id: r.id, name: val }); }}
                    onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); if (e.key === 'Escape') setEditingNameId(null); }}
                    onClick={(e) => e.stopPropagation()}
                    className="border border-blue-300 rounded px-1 py-0.5 text-sm w-full outline-none text-center" />
                ) : (
                  <span className="inline-flex items-center gap-1">
                    {r.name}
                    <span className="text-gray-300 hover:text-blue-500 text-xs cursor-pointer" title="إعادة تسمية"
                      onClick={(e) => { e.stopPropagation(); setEditingNameId(r.id); setEditingName(r.name); }}>✏️</span>
                  </span>
                )}
              </div>
              <div className="text-xs" onClick={(e) => e.stopPropagation()}>
                {editingStatusId === r.id ? (
                  <select value={editingStatus} onChange={(e) => setEditingStatus(e.target.value)}
                    onBlur={() => { if (editingStatus) updateStatusMutation.mutate({ id: r.id, status: editingStatus }); else setEditingStatusId(null); }}
                    onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLSelectElement).blur(); if (e.key === 'Escape') setEditingStatusId(null); }}
                    autoFocus className="border border-blue-300 rounded px-1 py-0.5 text-xs outline-none bg-white">
                    <option value="draft">draft</option>
                    <option value="active">active</option>
                    <option value="inactive">inactive</option>
                    <option value="archived">archived</option>
                  </select>
                ) : (
                  <span className={`inline-block px-2 py-0.5 rounded-full cursor-pointer hover:opacity-80 ${r.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}
                    onClick={(e) => { e.stopPropagation(); setEditingStatusId(r.id); setEditingStatus(r.status); }}>
                    {r.status}
                  </span>
                )}
              </div>
              <div className="mt-2 text-xs text-blue-700 font-mono">{r.total_cost?.toFixed(2)} ج</div>
              <div className="text-xs font-mono" onClick={(e) => e.stopPropagation()}>
                {editingPriceId === r.id ? (
                  <input autoFocus type="number" step="0.01" min="0" value={editingPrice}
                    onChange={(e) => setEditingPrice(e.target.value)}
                    onBlur={() => { const v = parseFloat(editingPrice); if (!isNaN(v) && v >= 0) updateSellingPriceMutation.mutate({ id: r.id, selling_price: v }); else setEditingPriceId(null); }}
                    onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); if (e.key === 'Escape') setEditingPriceId(null); }}
                    className="border border-blue-300 rounded px-1 py-0.5 text-sm w-20 outline-none text-center font-mono" />
                ) : (
                  <span className="cursor-pointer hover:text-blue-600 border-b border-dashed border-gray-300 text-blue-600"
                    onDoubleClick={(e) => { e.stopPropagation(); setEditingPriceId(r.id); setEditingPrice(String(r.selling_price ?? '')); }}>
                    {r.selling_price ? `${Number(r.selling_price).toFixed(2)} ج` : '—'}
                  </span>
                )}
              </div>
              <div className="text-xs text-amber-700 font-mono">
                {r.selling_price > 0 ? `${((r.total_cost / r.selling_price) * 100).toFixed(1)}%` : '—'}
              </div>
              <div className="text-xs text-gray-400 mt-1">{r.items_count} بند</div>
              <div className="flex items-center justify-center gap-2 mt-1.5" onClick={(e) => e.stopPropagation()}>
                <label className="flex items-center gap-1 text-[10px] text-gray-500 cursor-pointer" title="استبعاد من التسوية">
                  <input type="checkbox" checked={r.exclude_from_reconciliation} onChange={() => updateExclusionMutation.mutate({ id: r.id, field: 'exclude_from_reconciliation', value: !r.exclude_from_reconciliation })}
                    className="accent-amber-500 cursor-pointer" />
                  تسوية
                </label>
                <label className="flex items-center gap-1 text-[10px] text-gray-500 cursor-pointer" title="استبعاد من قائمة الطعام">
                  <input type="checkbox" checked={r.exclude_from_menu} onChange={() => updateExclusionMutation.mutate({ id: r.id, field: 'exclude_from_menu', value: !r.exclude_from_menu })}
                    className="accent-purple-500 cursor-pointer" />
                  قائمة
                </label>
              </div>
              <button onClick={(e) => { e.stopPropagation(); setCopyRecipeSource(r); setCopyRecipeName(r.name + ' (نسخة)'); setCopyRecipeCategory(r.category || ''); setShowCopyRecipeModal(true); }} className="text-[11px] text-blue-500 hover:text-blue-700 mt-2 font-medium">📋 نسخ</button>
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
                    <th className="px-4 py-3 text-left font-medium">التكلفة</th>
                    <th className="px-4 py-3 text-left font-medium">سعر البيع</th>
                    <th className="px-4 py-3 text-left font-medium">نسبة التكلفة</th>
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
                  <td className="px-4 py-3 font-medium text-gray-800">
                    {editingNameId === r.id ? (
                      <input autoFocus value={editingName} onChange={(e) => setEditingName(e.target.value)}
                        onBlur={() => { const val = editingName.trim(); setEditingNameId(null); if (val) renameRecipeMutation.mutate({ id: r.id, name: val }); }}
                        onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); if (e.key === 'Escape') setEditingNameId(null); }}
                        onClick={(e) => e.stopPropagation()}
                        className="border border-blue-300 rounded px-2 py-0.5 text-sm w-full outline-none" />
                    ) : (
                      <span className="inline-flex items-center gap-1">
                        {r.name}
                        <span className="text-gray-300 hover:text-blue-500 text-xs cursor-pointer" title="إعادة تسمية"
                          onClick={(e) => { e.stopPropagation(); setEditingNameId(r.id); setEditingName(r.name); }}>✏️</span>
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                    {editingStatusId === r.id ? (
                      <select value={editingStatus} onChange={(e) => setEditingStatus(e.target.value)}
                        onBlur={() => { if (editingStatus) updateStatusMutation.mutate({ id: r.id, status: editingStatus }); else setEditingStatusId(null); }}
                        onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLSelectElement).blur(); if (e.key === 'Escape') setEditingStatusId(null); }}
                        autoFocus className="border border-blue-300 rounded px-1 py-0.5 text-xs outline-none bg-white">
                        <option value="draft">draft</option>
                        <option value="active">active</option>
                        <option value="inactive">inactive</option>
                        <option value="archived">archived</option>
                      </select>
                    ) : (
                      <span className={`text-xs px-2 py-0.5 rounded-full cursor-pointer hover:opacity-80 ${r.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}
                        onClick={(e) => { e.stopPropagation(); setEditingStatusId(r.id); setEditingStatus(r.status); }}>
                        {r.status}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-left font-mono text-blue-700 font-medium">{r.total_cost?.toFixed(2)} ج</td>
                  <td className="px-4 py-3 text-left font-mono" onClick={(e) => e.stopPropagation()}>
                    {editingPriceId === r.id ? (
                      <input autoFocus type="number" step="0.01" min="0" value={editingPrice}
                        onChange={(e) => setEditingPrice(e.target.value)}
                        onBlur={() => { const v = parseFloat(editingPrice); if (!isNaN(v) && v >= 0) updateSellingPriceMutation.mutate({ id: r.id, selling_price: v }); else setEditingPriceId(null); }}
                        onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); if (e.key === 'Escape') setEditingPriceId(null); }}
                        className="border border-blue-300 rounded px-2 py-0.5 text-sm w-24 outline-none font-mono" />
                    ) : (
                      <span className="cursor-pointer hover:text-blue-600 border-b border-dashed border-gray-300"
                        onDoubleClick={(e) => { e.stopPropagation(); setEditingPriceId(r.id); setEditingPrice(String(r.selling_price ?? '')); }}>
                        {r.selling_price ? `${Number(r.selling_price).toFixed(2)} ج` : '—'}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-left font-mono text-amber-700">
                    {r.selling_price > 0 ? `${((r.total_cost / r.selling_price) * 100).toFixed(1)}%` : '—'}
                  </td>
                  <td className="px-4 py-3 text-center text-gray-500">{r.items_count}</td>
                  <td className="px-4 py-3 text-center whitespace-nowrap">
                    <label className="inline-flex items-center gap-1 text-[10px] text-gray-400 cursor-pointer ml-2" title="استبعاد من التسوية">
                      <input type="checkbox" checked={r.exclude_from_reconciliation} onChange={(e) => { e.stopPropagation(); updateExclusionMutation.mutate({ id: r.id, field: 'exclude_from_reconciliation', value: !r.exclude_from_reconciliation }); }}
                        className="accent-amber-500 cursor-pointer" />
                    </label>
                    <label className="inline-flex items-center gap-1 text-[10px] text-gray-400 cursor-pointer ml-2" title="استبعاد من القائمة">
                      <input type="checkbox" checked={r.exclude_from_menu} onChange={(e) => { e.stopPropagation(); updateExclusionMutation.mutate({ id: r.id, field: 'exclude_from_menu', value: !r.exclude_from_menu }); }}
                        className="accent-purple-500 cursor-pointer" />
                    </label>
                    <button onClick={(e) => { e.stopPropagation(); setCopyRecipeSource(r); setCopyRecipeName(r.name + ' (نسخة)'); setCopyRecipeCategory(r.category || ''); setShowCopyRecipeModal(true); }} className="text-xs text-blue-500 hover:text-blue-700 ml-2" title="نسخ">📋</button>
                    <button onClick={(e) => { e.stopPropagation(); loadRecipeSheet(r.id); }} className="text-xs text-blue-500 hover:text-blue-700 ml-2">فتح</button>
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
    const totals = getHotTotals() || getTotals(d.items || []);
    return (
      <div>
        <button onClick={() => setLevel('items')} className="text-xs text-blue-600 hover:underline mb-3 block">→ العودة للأصناف</button>

        {/* Header */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm mb-4">
          <div className="flex items-center justify-between mb-3">
            <div>
              <h2 className="text-lg font-bold text-gray-800">{d.name}</h2>
              <p className="text-xs text-gray-500">{d.code || ''} · v{d.version} · {d.category} · {d.branch_id ? warehouses.find((w: any) => w.id === d.branch_id)?.name : ''}{selectedMenu ? ` / ${selectedMenu.name}` : ''}</p>
              <div className="flex gap-3 mt-1.5">
                <label className="flex items-center gap-1 text-[11px] text-gray-500 cursor-pointer">
                  <input type="checkbox" checked={d.exclude_from_reconciliation} onChange={() => updateRecipeMutation.mutate({ exclude_from_reconciliation: !d.exclude_from_reconciliation })}
                    className="accent-amber-500 cursor-pointer" />
                  استبعاد من التسوية
                </label>
                <label className="flex items-center gap-1 text-[11px] text-gray-500 cursor-pointer">
                  <input type="checkbox" checked={d.exclude_from_menu} onChange={() => updateRecipeMutation.mutate({ exclude_from_menu: !d.exclude_from_menu })}
                    className="accent-purple-500 cursor-pointer" />
                  استبعاد من القائمة
                </label>
              </div>
            </div>
            <div className="flex gap-2">
              <button onClick={() => {
                const hot = hotRef.current?.hotInstance;
                if (hot) hot.alter('insert_row_below');
              }} className="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">+ إضافة بند</button>
              <button onClick={() => {
                const hot = hotRef.current?.hotInstance;
                if (!hot) return;
                let idx = selectedRowRef.current;
                if (idx < 0 || idx >= hot.countRows()) return;
                const data = hot.getSourceData();
                data.splice(idx, 1);
                hot.loadData(data);
                selectedRowRef.current = Math.min(idx, hot.countRows() - 2);
              }} className="px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-sm hover:bg-red-200">🗑 حذف البند</button>
              <button onClick={() => {
                const hot = hotRef.current?.hotInstance;
                if (!hot) return;
                const sel = hot.getSelectedLast();
                let row = sel ? sel[0] : selectedRowRef.current;
                if (row < 0) row = 0;
                const data = hot.getData();
                const r = data[row];
                if (!r || !r[0] || !ingredientIdMap[r[0]]) { toast.error('اختر صنفاً صحيحاً أولاً'); return; }
                const name = r[0];
                const id = ingredientIdMap[name];
                const qty = parseFloat(r[1]) || 0;
                setBulkUpdateIngredient({ name, id, currentQty: qty });
                setBulkUpdateQty(qty);
                setBulkRecipeIds(recipes.map((r: any) => r.id));
                setShowBulkUpdateModal(true);
              }} className="px-3 py-1.5 bg-amber-100 text-amber-700 rounded-lg text-sm hover:bg-amber-200">📊 تحديث الكمية للكل</button>
              <button onClick={() => {
                setBulkAddIngredientId('');
                setBulkAddQty(0);
                setBulkRecipeIds(recipes.map((r: any) => r.id));
                setShowBulkAddModal(true);
              }} className="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-sm hover:bg-blue-200">➕ إضافة صنف للكل</button>
              <button onClick={() => {
                const hot = hotRef.current?.hotInstance;
                if (!hot) return;
                const sel = hot.getSelectedLast();
                let row = sel ? sel[0] : selectedRowRef.current;
                if (row < 0) row = 0;
                const data = hot.getData();
                const r = data[row];
                if (!r || !r[0] || !ingredientIdMap[r[0]]) { toast.error('اختر صنفاً صحيحاً أولاً'); return; }
                setBulkReplaceOldName(r[0]);
                setBulkReplaceOldId(ingredientIdMap[r[0]]);
                setBulkReplaceNewId('');
                setBulkRecipeIds(recipes.map((r: any) => r.id));
                setShowBulkReplaceModal(true);
              }} className="px-3 py-1.5 bg-purple-100 text-purple-700 rounded-lg text-sm hover:bg-purple-200">🔄 استبدال صنف</button>
              <button onClick={() => {
                const hot = hotRef.current?.hotInstance;
                if (!hot) return;
                const sel = hot.getSelectedLast();
                let row = sel ? sel[0] : selectedRowRef.current;
                if (row < 0) row = 0;
                const data = hot.getData();
                const r = data[row];
                if (!r || !r[0] || !ingredientIdMap[r[0]]) { toast.error('اختر صنفاً صحيحاً أولاً'); return; }
                setBulkDeleteIngredientName(r[0]);
                setBulkDeleteIngredientId(ingredientIdMap[r[0]]);
                setBulkRecipeIds(recipes.map((r: any) => r.id));
                setShowBulkDeleteModal(true);
              }} className="px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-sm hover:bg-red-200">🗑️ مسح صنف من الكل</button>
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
            data={sheetData}
            columns={sheetColumns}
            colHeaders={['الصنف', 'الكمية', 'وحدة الشراء', 'سعر الوحدة', 'وحدة الريسيبي', 'CF', 'Yield %', 'EP Cost', 'الإجمالي']}
            rowHeaders={(index: number) => `<div style="cursor:grab;text-align:center;user-select:none;font-size:13px;color:#999;line-height:26px;" title="اسحب لإعادة الترتيب">⋮⋮</div>`}
            width="100%"
            height={400}
            licenseKey="non-commercial-and-evaluation"
            afterChange={handleAfterChange}
            afterSelection={(row: number) => { selectedRowRef.current = row; }}
            beforeOnCellMouseDown={(event, coords) => {
              if (coords.col === -1 && coords.row >= 0) {
                event.preventDefault();
                const hot = hotRef.current?.hotInstance;
                if (!hot) return false;
                const startRow = coords.row;
                let currentRow = startRow;
                // Find first rendered row for position calculation
                let firstRenderedRow = -1;
                let rowHeight = 23;
                for (let i = 0; i < hot.countRows(); i++) {
                  const c = hot.getCell(i, 0);
                  if (c) { firstRenderedRow = i; rowHeight = c.offsetHeight; break; }
                }
                if (firstRenderedRow < 0) return false;
                const firstCell = hot.getCell(firstRenderedRow, 0);
                if (!firstCell) return false;
                const firstRowTop = firstCell.getBoundingClientRect().top;

                const onMove = (e: MouseEvent) => {
                  const h = hotRef.current?.hotInstance;
                  if (!h) return;
                  let target = firstRenderedRow + Math.floor((e.clientY - firstRowTop) / rowHeight);
                  target = Math.max(0, Math.min(h.countRows() - 1, target));
                  if (target !== currentRow) {
                    currentRow = target;
                    h.selectCell(currentRow, 0, currentRow, h.countCols() - 1, false, false);
                  }
                };
                const onUp = () => {
                  document.removeEventListener('mousemove', onMove);
                  document.removeEventListener('mouseup', onUp);
                  const h = hotRef.current?.hotInstance;
                  if (!h) return;
                  if (startRow !== currentRow) {
                    const data = h.getSourceData();
                    const [item] = data.splice(startRow, 1);
                    data.splice(currentRow, 0, item);
                    h.loadData(data);
                    h.selectCell(currentRow, 0, currentRow, h.countCols() - 1, false, false);
                  }
                };
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
                return false;
              }
            }}
            contextMenu={{
              items: {
                'insert_row_below': { name: 'إدراج صف' },
                'remove_row': { name: 'حذف الصف' },
                'hsep1': '---------',
                'undo': { name: 'تراجع' },
                'redo': { name: 'إعادة' },
                'hsep2': '---------',
                'move_up': {
                  name: '↑ رفع لأعلى',
                  callback: () => {
                    const hot = hotRef.current?.hotInstance;
                    if (!hot) return;
                    const sel = hot.getSelectedLast();
                    if (!sel) return;
                    const row = sel[0];
                    if (row <= 0) return;
                    const data = hot.getSourceData();
                    const [item] = data.splice(row, 1);
                    data.splice(row - 1, 0, item);
                    hot.loadData(data);
                    hot.selectCell(row - 1, 0, row - 1, hot.countCols() - 1, false, false);
                  },
                },
                'move_down': {
                  name: '↓ خفض لأسفل',
                  callback: () => {
                    const hot = hotRef.current?.hotInstance;
                    if (!hot) return;
                    const sel = hot.getSelectedLast();
                    if (!sel) return;
                    const row = sel[0];
                    const rows = hot.countRows();
                    if (row >= rows - 1) return;
                    const data = hot.getSourceData();
                    const [item] = data.splice(row, 1);
                    data.splice(row + 1, 0, item);
                    hot.loadData(data);
                    hot.selectCell(row + 1, 0, row + 1, hot.countCols() - 1, false, false);
                  },
                },
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
            { label: 'Total Cost', value: totals.totalCost, suffix: ' ج', color: 'text-blue-700', bg: 'bg-blue-50' },
            { label: 'Cost/Portion', value: totals.costPerPortion, suffix: ' ج', color: 'text-green-700', bg: 'bg-green-50' },
            { label: 'Food Cost %', value: totals.foodCostPct.toFixed(1), suffix: ' %', color: totals.foodCostPct > 35 ? 'text-red-600' : 'text-green-600', bg: 'bg-amber-50' },
            { label: 'Ideal Price', value: totals.idealPrice, suffix: ' ج', color: 'text-orange-700', bg: 'bg-orange-50' },
          ].map((k) => (
            <div key={k.label} className={`${k.bg} border border-gray-100 rounded-xl p-3 shadow-sm text-center`}>
              <div className="text-xs text-gray-500">{k.label}</div>
              <div className={`text-lg font-bold mt-1 ${k.color}`}>
                {typeof k.value === 'number' ? k.value.toFixed(2) : k.value}{k.suffix}
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  };

  // ── Bulk Update Modal ──
  const renderBulkUpdateModal = () => {
    if (!showBulkUpdateModal || !bulkUpdateIngredient) return null;
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowBulkUpdateModal(false)}>
        <div className="bg-white rounded-2xl p-6 w-full max-w-lg shadow-xl" onClick={(e) => e.stopPropagation()}>
          <h3 className="text-lg font-bold mb-4">تحديث كمية الصنف</h3>

          <div className="mb-4 p-3 bg-gray-50 rounded-lg">
            <p className="text-sm"><span className="text-gray-500">الصنف:</span> <strong>{bulkUpdateIngredient.name}</strong></p>
            <p className="text-sm"><span className="text-gray-500">الكمية الحالية:</span> <strong>{bulkUpdateIngredient.currentQty}</strong></p>
          </div>

          <div className="mb-4">
            <label className="text-xs text-gray-500 block mb-1">الكمية الجديدة</label>
            <input type="number" step="0.001" value={bulkUpdateQty}
              onChange={(e) => setBulkUpdateQty(parseFloat(e.target.value) || 0)}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none" />
          </div>

          <div className="mb-4">
            <label className="text-xs text-gray-500 block mb-2">اختر الريسيبيات المطلوب تحديثها</label>
            <div className="max-h-48 overflow-y-auto border border-gray-100 rounded-lg divide-y divide-gray-50">
              {recipes.map((r: any) => (
                <label key={r.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm">
                  <input type="checkbox" checked={bulkRecipeIds.includes(r.id)}
                    onChange={() => {
                      setBulkRecipeIds(prev =>
                        prev.includes(r.id) ? prev.filter(id => id !== r.id) : [...prev, r.id]
                      );
                    }}
                    className="accent-blue-600" />
                  {r.name}
                </label>
              ))}
              {recipes.length === 0 && (
                <p className="p-3 text-gray-400 text-sm text-center">لا توجد ريسبيات في هذا التصنيف</p>
              )}
            </div>
            <button onClick={() => {
              if (bulkRecipeIds.length === recipes.length) setBulkRecipeIds([]);
              else setBulkRecipeIds(recipes.map((r: any) => r.id));
            }} className="text-xs text-blue-600 hover:underline mt-1">
              {bulkRecipeIds.length === recipes.length ? 'إلغاء تحديد الكل' : 'تحديد الكل'}
            </button>
          </div>

          <div className="flex gap-2">
            <button onClick={() => {
              if (bulkRecipeIds.length === 0) { toast.error('اختر ريسيبي واحد على الأقل'); return; }
              bulkUpdateMutation.mutate({
                ingredient_id: bulkUpdateIngredient.id,
                new_qty: bulkUpdateQty,
                recipe_ids: bulkRecipeIds,
              });
            }} disabled={bulkUpdateMutation.isPending} className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
              {bulkUpdateMutation.isPending ? '...' : 'تطبيق'}
            </button>
            <button onClick={() => setShowBulkUpdateModal(false)} className="px-4 py-2 text-gray-500 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
          </div>
        </div>
      </div>
    );
  };

  // ── Copy Menu Modal ──
  const renderCopyMenuModal = () => {
    if (!showCopyMenuModal) return null;
    const availableBranches = branches.filter((b: any) => b.id !== selectedBranch?.id);
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowCopyMenuModal(false)}>
        <div className="bg-white rounded-2xl p-6 w-full max-w-md shadow-xl" onClick={(e) => e.stopPropagation()}>
          <h3 className="text-lg font-bold mb-1">نسخ القائمة إلى فرع آخر</h3>
          <p className="text-sm text-gray-500 mb-4">القائمة المصدر: {copyMenuSource?.name}</p>

          <div className="space-y-4">
            <div>
              <label className="text-xs text-gray-500 block mb-1">اسم القائمة الجديدة</label>
              <input value={copyMenuName} onChange={(e) => setCopyMenuName(e.target.value)}
                placeholder="أدخل اسم القائمة" autoFocus
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none" />
            </div>
            <div>
              <label className="text-xs text-gray-500 block mb-1">الفرع المستهدف</label>
              <select value={copyMenuBranchId} onChange={(e) => setCopyMenuBranchId(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none">
                <option value="">-- اختر الفرع --</option>
                {availableBranches.map((b: any) => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex gap-2 mt-6">
            <button onClick={() => {
              if (!copyMenuName.trim() || !copyMenuBranchId) { toast.error('أدخل اسم القائمة واختر الفرع'); return; }
              copyMenuMutation.mutate({ menuId: copyMenuSource?.id, name: copyMenuName.trim(), targetBranchId: copyMenuBranchId });
            }} disabled={copyMenuMutation.isPending}
              className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
              {copyMenuMutation.isPending ? '...' : 'نسخ'}
            </button>
            <button onClick={() => setShowCopyMenuModal(false)} className="px-4 py-2 text-gray-500 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
          </div>
        </div>
      </div>
    );
  };

  // ── Copy Recipe Modal ──
  const renderCopyRecipeModal = () => {
    if (!showCopyRecipeModal) return null;
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowCopyRecipeModal(false)}>
        <div className="bg-white rounded-2xl p-6 w-full max-w-md shadow-xl" onClick={(e) => e.stopPropagation()}>
          <h3 className="text-lg font-bold mb-1">نسخ الصنف</h3>
          <p className="text-sm text-gray-500 mb-4">سيتم نسخ جميع المكونات من "{copyRecipeSource?.name}"</p>

          <div className="space-y-4">
            <div>
              <label className="text-xs text-gray-500 block mb-1">الاسم الجديد</label>
              <input value={copyRecipeName} onChange={(e) => setCopyRecipeName(e.target.value)}
                placeholder="أدخل اسم الصنف الجديد" autoFocus
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none" />
            </div>
            <div>
              <label className="text-xs text-gray-500 block mb-1">التصنيف</label>
              <select value={copyRecipeCategory} onChange={(e) => setCopyRecipeCategory(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none">
                <option value="">— نفس تصنيف الأصلي ({copyRecipeSource?.category || 'بدون'}) —</option>
                {categories.map((c: any) => (
                  <option key={c.id} value={c.name}>{c.name}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex gap-2 mt-6">
            <button onClick={() => {
              if (!copyRecipeName.trim()) { toast.error('أدخل اسم الصنف الجديد'); return; }
              copyRecipeMutation.mutate({ recipeId: copyRecipeSource?.id, name: copyRecipeName.trim(), category: copyRecipeCategory || undefined });
            }} disabled={copyRecipeMutation.isPending}
              className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
              {copyRecipeMutation.isPending ? '...' : 'نسخ'}
            </button>
            <button onClick={() => setShowCopyRecipeModal(false)} className="px-4 py-2 text-gray-500 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
          </div>
        </div>
      </div>
    );
  };

  const renderCopyCategoryModal = () => {
    if (!showCopyCategoryModal) return null;
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowCopyCategoryModal(false)}>
        <div className="bg-white rounded-2xl p-6 w-full max-w-md shadow-xl" onClick={(e) => e.stopPropagation()}>
          <h3 className="text-lg font-bold mb-1">نسخ التصنيف</h3>
          <p className="text-sm text-gray-500 mb-4">إنشاء نسخة من التصنيف "{copyCategorySource?.name}" بكل أصنافه</p>
          <div className="space-y-4">
            <div>
              <label className="block text-xs text-gray-500 mb-1">اسم التصنيف الجديد</label>
              <input value={copyCategoryName} onChange={(e) => setCopyCategoryName(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none" />
            </div>
            <div className="flex gap-2">
              <button onClick={() => copyCategoryMutation.mutate({ category: copyCategorySource?.id, name: copyCategoryName })}
                disabled={!copyCategoryName.trim() || copyCategoryMutation.isPending}
                className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
                {copyCategoryMutation.isPending ? '...' : 'نسخ التصنيف'}
              </button>
              <button onClick={() => setShowCopyCategoryModal(false)} className="px-4 py-2 text-gray-500 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
            </div>
          </div>
        </div>
      </div>
    );
  };

  // ── Bulk Add Item Modal ──
  const renderBulkAddModal = () => {
    if (!showBulkAddModal) return null;
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowBulkAddModal(false)}>
        <div className="bg-white rounded-2xl p-6 w-full max-w-lg shadow-xl" onClick={(e) => e.stopPropagation()}>
          <h3 className="text-lg font-bold mb-4">إضافة صنف للكل</h3>

          <div className="mb-4">
            <label className="text-xs text-gray-500 block mb-1">الصنف</label>
            <select value={bulkAddIngredientId} onChange={(e) => setBulkAddIngredientId(e.target.value)}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none">
              <option value="">-- اختر الصنف --</option>
              {ingredients.map((i: any) => (
                <option key={i.id} value={i.id}>{i.name}</option>
              ))}
            </select>
          </div>

          <div className="mb-4">
            <label className="text-xs text-gray-500 block mb-1">الكمية</label>
            <input type="number" step="0.001" value={bulkAddQty}
              onChange={(e) => setBulkAddQty(parseFloat(e.target.value) || 0)}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none" />
          </div>

          <div className="mb-4">
            <label className="text-xs text-gray-500 block mb-2">اختر الريسيبيات</label>
            <div className="max-h-48 overflow-y-auto border border-gray-100 rounded-lg divide-y divide-gray-50">
              {recipes.map((r: any) => (
                <label key={r.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm">
                  <input type="checkbox" checked={bulkRecipeIds.includes(r.id)}
                    onChange={() => setBulkRecipeIds(prev => prev.includes(r.id) ? prev.filter(id => id !== r.id) : [...prev, r.id])}
                    className="accent-blue-600" />
                  {r.name}
                </label>
              ))}
              {recipes.length === 0 && <p className="p-3 text-gray-400 text-sm text-center">لا توجد ريسبيات</p>}
            </div>
            <button onClick={() => {
              if (bulkRecipeIds.length === recipes.length) setBulkRecipeIds([]);
              else setBulkRecipeIds(recipes.map((r: any) => r.id));
            }} className="text-xs text-blue-600 hover:underline mt-1">
              {bulkRecipeIds.length === recipes.length ? 'إلغاء تحديد الكل' : 'تحديد الكل'}
            </button>
          </div>

          <div className="flex gap-2">
            <button onClick={() => {
              if (!bulkAddIngredientId) { toast.error('اختر صنفاً'); return; }
              if (bulkRecipeIds.length === 0) { toast.error('اختر ريسيبي واحد على الأقل'); return; }
              bulkAddItemMutation.mutate({ ingredient_id: bulkAddIngredientId, qty: bulkAddQty, recipe_ids: bulkRecipeIds });
            }} disabled={!bulkAddIngredientId || bulkAddItemMutation.isPending}
              className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
              {bulkAddItemMutation.isPending ? '...' : 'إضافة'}
            </button>
            <button onClick={() => setShowBulkAddModal(false)} className="px-4 py-2 text-gray-500 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
          </div>
        </div>
      </div>
    );
  };

  // ── Bulk Replace Item Modal ──
  const renderBulkReplaceModal = () => {
    if (!showBulkReplaceModal) return null;
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowBulkReplaceModal(false)}>
        <div className="bg-white rounded-2xl p-6 w-full max-w-lg shadow-xl" onClick={(e) => e.stopPropagation()}>
          <h3 className="text-lg font-bold mb-1">استبدال صنف</h3>
          <p className="text-sm text-gray-500 mb-4">استبدال "{bulkReplaceOldName}" بصنف آخر في الريسيبيات المحددة</p>

          <div className="mb-4">
            <label className="text-xs text-gray-500 block mb-1">الصنف الجديد</label>
            <select value={bulkReplaceNewId} onChange={(e) => setBulkReplaceNewId(e.target.value)}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none">
              <option value="">-- اختر الصنف --</option>
              {ingredients.filter((i: any) => i.id !== bulkReplaceOldId).map((i: any) => (
                <option key={i.id} value={i.id}>{i.name}</option>
              ))}
            </select>
          </div>

          <div className="mb-4">
            <label className="text-xs text-gray-500 block mb-2">اختر الريسيبيات</label>
            <div className="max-h-48 overflow-y-auto border border-gray-100 rounded-lg divide-y divide-gray-50">
              {recipes.map((r: any) => (
                <label key={r.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm">
                  <input type="checkbox" checked={bulkRecipeIds.includes(r.id)}
                    onChange={() => setBulkRecipeIds(prev => prev.includes(r.id) ? prev.filter(id => id !== r.id) : [...prev, r.id])}
                    className="accent-purple-600" />
                  {r.name}
                </label>
              ))}
              {recipes.length === 0 && <p className="p-3 text-gray-400 text-sm text-center">لا توجد ريسبيات</p>}
            </div>
            <button onClick={() => {
              if (bulkRecipeIds.length === recipes.length) setBulkRecipeIds([]);
              else setBulkRecipeIds(recipes.map((r: any) => r.id));
            }} className="text-xs text-blue-600 hover:underline mt-1">
              {bulkRecipeIds.length === recipes.length ? 'إلغاء تحديد الكل' : 'تحديد الكل'}
            </button>
          </div>

          <div className="flex gap-2">
            <button onClick={() => {
              if (!bulkReplaceNewId) { toast.error('اختر الصنف الجديد'); return; }
              if (bulkRecipeIds.length === 0) { toast.error('اختر ريسيبي واحد على الأقل'); return; }
              bulkReplaceItemMutation.mutate({ old_ingredient_id: bulkReplaceOldId, new_ingredient_id: bulkReplaceNewId, recipe_ids: bulkRecipeIds });
            }} disabled={!bulkReplaceNewId || bulkReplaceItemMutation.isPending}
              className="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">
              {bulkReplaceItemMutation.isPending ? '...' : 'استبدال'}
            </button>
            <button onClick={() => setShowBulkReplaceModal(false)} className="px-4 py-2 text-gray-500 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
          </div>
        </div>
      </div>
    );
  };

  // ── Bulk Delete Item Modal ──
  const renderBulkDeleteModal = () => {
    if (!showBulkDeleteModal) return null;
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowBulkDeleteModal(false)}>
        <div className="bg-white rounded-2xl p-6 w-full max-w-lg shadow-xl" onClick={(e) => e.stopPropagation()}>
          <h3 className="text-lg font-bold mb-1">مسح صنف من الريسيبيات</h3>
          <p className="text-sm text-gray-500 mb-4">مسح "{bulkDeleteIngredientName}" من الريسيبيات المحددة</p>

          <div className="mb-4">
            <label className="text-xs text-gray-500 block mb-2">اختر الريسيبيات المطلوب مسح الصنف منها</label>
            <div className="max-h-48 overflow-y-auto border border-gray-100 rounded-lg divide-y divide-gray-50">
              {recipes.map((r: any) => (
                <label key={r.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm">
                  <input type="checkbox" checked={bulkRecipeIds.includes(r.id)}
                    onChange={() => setBulkRecipeIds(prev => prev.includes(r.id) ? prev.filter(id => id !== r.id) : [...prev, r.id])}
                    className="accent-red-600" />
                  {r.name}
                </label>
              ))}
              {recipes.length === 0 && <p className="p-3 text-gray-400 text-sm text-center">لا توجد ريسبيات</p>}
            </div>
            <button onClick={() => {
              if (bulkRecipeIds.length === recipes.length) setBulkRecipeIds([]);
              else setBulkRecipeIds(recipes.map((r: any) => r.id));
            }} className="text-xs text-blue-600 hover:underline mt-1">
              {bulkRecipeIds.length === recipes.length ? 'إلغاء تحديد الكل' : 'تحديد الكل'}
            </button>
          </div>

          <div className="flex gap-2">
            <button onClick={() => {
              if (bulkRecipeIds.length === 0) { toast.error('اختر ريسيبي واحد على الأقل'); return; }
              if (!confirm(`مسح "${bulkDeleteIngredientName}" من ${bulkRecipeIds.length} ريسيبي؟`)) return;
              bulkDeleteItemMutation.mutate({ ingredient_id: bulkDeleteIngredientId, recipe_ids: bulkRecipeIds });
            }} disabled={bulkDeleteItemMutation.isPending}
              className="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 disabled:opacity-50">
              {bulkDeleteItemMutation.isPending ? '...' : 'مسح'}
            </button>
            <button onClick={() => setShowBulkDeleteModal(false)} className="px-4 py-2 text-gray-500 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
          </div>
        </div>
      </div>
    );
  };

  const renderMoveCategoryModal = () => {
    if (!showMoveCategoryModal) return null;
    const targetCategories = categories.filter((c: any) => c.name !== selectedCategory?.name);
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowMoveCategoryModal(false)}>
        <div className="bg-white rounded-2xl p-6 w-full max-w-md shadow-xl" onClick={(e) => e.stopPropagation()}>
          <h3 className="text-lg font-bold mb-1">نقل الأصناف</h3>
          <p className="text-sm text-gray-500 mb-4">نقل {selectedIds.size} صنف من "{selectedCategory?.name}" إلى تصنيف آخر</p>

          <div className="space-y-2 max-h-64 overflow-y-auto mb-4">
            {targetCategories.length === 0 ? (
              <p className="text-gray-400 text-sm text-center py-4">لا توجد تصنيفات أخرى</p>
            ) : targetCategories.map((c: any) => (
              <div key={c.id}
                onClick={() => setMoveCategoryTarget(c.name)}
                className={`flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer border transition-all text-sm ${moveCategoryTarget === c.name ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-100 hover:border-gray-300'}`}
              >
                <div className={`w-4 h-4 rounded-full border-2 flex items-center justify-center ${moveCategoryTarget === c.name ? 'border-blue-500' : 'border-gray-300'}`}>
                  {moveCategoryTarget === c.name && <div className="w-2 h-2 rounded-full bg-blue-500" />}
                </div>
                {c.name}
              </div>
            ))}
          </div>

          <div className="flex gap-2">
            <button onClick={() => { if (moveCategoryTarget) bulkMoveCategoryMutation.mutate({ ids: Array.from(selectedIds), category: moveCategoryTarget }); }}
              disabled={!moveCategoryTarget || bulkMoveCategoryMutation.isPending}
              className="flex-1 px-4 py-2 bg-amber-600 text-white rounded-lg text-sm hover:bg-amber-700 disabled:opacity-50">
              {bulkMoveCategoryMutation.isPending ? '...' : `نقل إلى "${moveCategoryTarget || '...'}"`}
            </button>
            <button onClick={() => setShowMoveCategoryModal(false)} className="px-4 py-2 text-gray-500 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
          </div>
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
        {renderBulkUpdateModal()}
        {renderBulkAddModal()}
        {renderBulkReplaceModal()}
        {renderBulkDeleteModal()}
        {renderCopyMenuModal()}
        {renderCopyRecipeModal()}
        {renderCopyCategoryModal()}
        {renderMoveCategoryModal()}
      </div>
    </div>
  );
}
