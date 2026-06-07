'use client';

import { Fragment, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import * as XLSX from 'xlsx';
import { SearchableSelect } from '@/components/ui/SearchableSelect';

export default function ReconciliationPage() {
  const [branchId, setBranchId] = useState('');
  const [from, setFrom] = useState(() => {
    const d = new Date(); d.setDate(1);
    return d.toISOString().slice(0, 10);
  });
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10));
  const [salesMap, setSalesMap] = useState<Record<string, string>>({});
  const [inv, setInv] = useState<any>(null);

  const [loading, setLoading] = useState(false);
  const [showUpload, setShowUpload] = useState(false);
  const [branchName, setBranchName] = useState('');
  const [saleDate, setSaleDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [uploadStep, setUploadStep] = useState<'file' | 'review' | 'done'>('file');
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploadLoading, setUploadLoading] = useState(false);
  const [uploadConfirmLoading, setUploadConfirmLoading] = useState(false);
  const [uploadColumns, setUploadColumns] = useState<any[]>([]);

  const [uploadPreview, setUploadPreview] = useState<any[] | null>(null);
  const [uploadUnmatched, setUploadUnmatched] = useState<any[]>([]);
  const [uploadAllRecipes, setUploadAllRecipes] = useState<any[]>([]);
  const [uploadOverrides, setUploadOverrides] = useState<Record<string, string>>({});
  const [uploadCategories, setUploadCategories] = useState<any[]>([]);
  const [uploadHalfCats, setUploadHalfCats] = useState<Record<string, boolean>>({});
  const [uploadExportData, setUploadExportData] = useState<any[]>([]);
  const [uploadMapping, setUploadMapping] = useState<{
    name_col: number;
    qty_col: number;
    size_col: number | null;
    cat_col: number | null;
    header_rows: number;
  }>({ name_col: 0, qty_col: 1, size_col: null, cat_col: null, header_rows: 1 });
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [filterCat, setFilterCat] = useState('');
  const [filterSearch, setFilterSearch] = useState('');
  const [showSavedRecons, setShowSavedRecons] = useState(false);
  const [savedRecons, setSavedRecons] = useState<any[]>([]);
  const [saving, setSaving] = useState(false);
  const [loadingSaved, setLoadingSaved] = useState(false);

  const { currentClient } = useAuthStore();

  // ── Branches ──
  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses', currentClient?.id],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });
  const branches = warehouses
  .filter((w: any) => w.type === 'branch')
  // De-duplicate names by appending a suffix if duplicate
  .map((w: any, _idx: number, arr: any[]) => {
    const dupes = arr.filter((x: any) => x.type === 'branch' && x.name === w.name);
    if (dupes.length > 1) {
      const idx = dupes.findIndex((x: any) => x.id === w.id);
      // Map index → أ, ب, ت, ث, ج, ح, خ, د
      const letters = ['أ', 'ب', 'ت', 'ث', 'ج', 'ح', 'خ', 'د', 'ذ', 'ر', 'ز', 'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ع', 'غ', 'ف', 'ق', 'ك', 'ل', 'م', 'ن'];
      return { ...w, name: `${w.name} (${letters[idx % letters.length] || idx + 1})` };
    }
    return w;
  });

  // ── Recipes with items ──
  const { data: allRecipes = [] } = useQuery({
    queryKey: ['menu-recipes-full', branchId],
    queryFn: () =>
      api
        .get('/menu-engineering/recipes', {
          params: { branch_id: branchId || undefined, items: 'true' },
        })
        .then((r) => r.data),
    enabled: !!branchId,
  });

  // ── Filter out excluded/inactive recipes for reconciliation display ──
  const visibleRecipes = useMemo(() => {
    return allRecipes.filter((r: any) => r.status === 'active' && !r.exclude_from_reconciliation);
  }, [allRecipes]);

  // ── Build unique ingredient list from visible recipes ──
  const ingIndex = useMemo(() => {
    const seen: Record<string, string> = {};
    for (const r of visibleRecipes) {
      for (const it of r.items ?? []) {
        if (!seen[it.ingredient_id]) seen[it.ingredient_id] = it.ingredient_name;
      }
    }
    return { ids: Object.keys(seen), names: seen };
  }, [allRecipes]);

  // ── Group visible recipes by category ──
  const grouped = useMemo(() => {
    const map: Record<string, any[]> = {};
    for (const r of visibleRecipes) {
      const cat = r.category || 'أخرى';
      if (!map[cat]) map[cat] = [];
      map[cat].push(r);
    }
    return map;
  }, [allRecipes]);

  // ── Parse qty_sold ──
  const getSold = (rid: string) => {
    const v = parseFloat(salesMap[rid]);
    return isNaN(v) ? 0 : v;
  };

  // ── Theoretical calc helpers ──
  const theoForRecipe = (rid: string) => {
    if (!ingIndex.ids.length) return {};
    const recipe = visibleRecipes.find((r: any) => r.id === rid);
    if (!recipe) return {};
    const sold = getSold(rid);
    const out: Record<string, number> = {};
    for (const it of recipe.items ?? []) {
      out[it.ingredient_id] = sold * it.qty;
    }
    return out;
  };

  const totals = useMemo(() => {
    const t: Record<string, number> = {};
    for (const rid of Object.keys(salesMap)) {
      const cells = theoForRecipe(rid);
      for (const [ingId, val] of Object.entries(cells)) {
        t[ingId] = (t[ingId] ?? 0) + val;
      }
    }
    return t;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [salesMap, allRecipes, ingIndex.ids]);


  // ── Filtered grouped (for category filter + search) ──
  const filteredGrouped = useMemo(() => {
    const entries = Object.entries(grouped) as [string, any[]][];
    return entries
      .filter(([cat]) => !filterCat || cat === filterCat)
      .map(([cat, items]) => [
        cat,
        filterSearch
          ? items.filter((r: any) =>
              r.name.toLowerCase().includes(filterSearch.toLowerCase())
            )
          : items,
      ] as [string, any[]])
      .filter(([, items]) => items.length > 0);
  }, [grouped, filterCat, filterSearch]);

  // ── Upload handlers ──
  const overrideKey = (source_name: string, size: string) => source_name + (size ? '|' + size : '');

  const handleConfirm = async () => {
    if (!uploadPreview || !uploadPreview.length || !branchId) return;
    setUploadConfirmLoading(true);
    try {
      const items: any[] = [];
      for (const p of uploadPreview) {
        const key = overrideKey(p.source_name, p.size || '');
        const rid = uploadOverrides[key] || p.recipe_id;
        items.push({ recipe_id: rid, qty_sold: p.qty_sold, category: p.category || '', source_name: p.source_name || '' });
      }
      for (const u of uploadUnmatched) {
        const key = overrideKey(u.source_name, u.size || '');
        const overrideRid = uploadOverrides[key];
        if (overrideRid) {
          items.push({ recipe_id: overrideRid, qty_sold: u.qty_sold, category: u.category || '', source_name: u.source_name || '' });
        }
      }
      await api.post('/menu-engineering/confirm-sales', {
        branch_id: branchId,
        sale_date: saleDate,
        items,
        half_categories: uploadHalfCats,
      });

      // Update salesMap with confirmed data so it flows into reconciliation
      const newSalesMap: Record<string, string> = { ...salesMap };
      for (const p of uploadPreview) {
        const key = overrideKey(p.source_name, p.size || '');
        const rid = uploadOverrides[key] || p.recipe_id;
        const isHalf = !!uploadHalfCats[p.category];
        const qty = isHalf ? (p.qty_sold / 2) : p.qty_sold;
        newSalesMap[rid] = String(qty);
      }
      for (const u of uploadUnmatched) {
        const key = overrideKey(u.source_name, u.size || '');
        const overrideRid = uploadOverrides[key];
        if (overrideRid) {
          const isHalf = !!uploadHalfCats[u.category];
          const qty = isHalf ? (u.qty_sold / 2) : u.qty_sold;
          newSalesMap[overrideRid] = String(qty);
        }
      }
      setSalesMap(newSalesMap);

      const exportRows = buildUploadExportData();
      setUploadExportData(exportRows);
      setUploadStep('done');
    } catch (e: any) {
      console.error(e);
    }
    setUploadConfirmLoading(false);
  };

  const buildUploadExportData = (): any[] => {
    const rows: any[] = [];
    const preview = uploadPreview ?? [];
    const unmatched = uploadUnmatched ?? [];
    for (const p of preview) {
      const key = overrideKey(p.source_name, p.size || '');
      const rid = uploadOverrides[key] || p.recipe_id;
      const recipe = uploadAllRecipes.find((r: any) => r.id === rid);
      const isHalf = !!uploadHalfCats[p.category];
      rows.push({
        category: p.category || '',
        item: recipe?.name || p.source_name,
        size: p.size || '',
        qty: isHalf ? (p.qty_sold / 2) : p.qty_sold,
      });
    }
    for (const u of unmatched) {
      const key = overrideKey(u.source_name, u.size || '');
      const overrideRid = uploadOverrides[key];
      if (overrideRid) {
        const recipe = uploadAllRecipes.find((r: any) => r.id === overrideRid);
        const isHalf = !!uploadHalfCats[u.category];
        rows.push({
          category: u.category || '',
          item: recipe?.name || u.source_name,
          size: u.size || '',
          qty: isHalf ? (u.qty_sold / 2) : u.qty_sold,
        });
      }
    }
    return rows;
  };

  const handleExportFromReview = () => {
    const rows = buildUploadExportData();
    if (!rows.length) return;
    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'مبيعات');
    const month = saleDate ? saleDate.slice(0, 7) : new Date().toISOString().slice(0, 7);
    const fileName = branchName ? `${branchName}_${month}.xlsx` : `مبيعات_${month}.xlsx`;
    XLSX.writeFile(wb, fileName);
  };

  const handleDirectParse = async () => {
    if (!uploadPreview || !uploadPreview.length || !branchId) return;
    setUploadConfirmLoading(true);
    try {
      const items: any[] = [];
      for (const p of uploadPreview) {
        const key = overrideKey(p.source_name, p.size || '');
        const rid = uploadOverrides[key] || p.recipe_id;
        items.push({ recipe_id: rid, qty_sold: p.qty_sold, category: p.category || '', source_name: p.source_name || '' });
      }
      for (const u of uploadUnmatched) {
        const key = overrideKey(u.source_name, u.size || '');
        const overrideRid = uploadOverrides[key];
        if (overrideRid) {
          items.push({ recipe_id: overrideRid, qty_sold: u.qty_sold, category: u.category || '', source_name: u.source_name || '' });
        }
      }
      await api.post('/menu-engineering/confirm-sales', {
        branch_id: branchId,
        sale_date: saleDate,
        items,
        half_categories: uploadHalfCats,
      });
      // Update salesMap
      const newSalesMap: Record<string, string> = { ...salesMap };
      for (const p of uploadPreview) {
        const key = overrideKey(p.source_name, p.size || '');
        const rid = uploadOverrides[key] || p.recipe_id;
        const isHalf = !!uploadHalfCats[p.category];
        const qty = isHalf ? (p.qty_sold / 2) : p.qty_sold;
        newSalesMap[rid] = String(qty);
      }
      for (const u of uploadUnmatched) {
        const key = overrideKey(u.source_name, u.size || '');
        const overrideRid = uploadOverrides[key];
        if (overrideRid) {
          const isHalf = !!uploadHalfCats[u.category];
          const qty = isHalf ? (u.qty_sold / 2) : u.qty_sold;
          newSalesMap[overrideRid] = String(qty);
        }
      }
      setSalesMap(newSalesMap);
      resetUpload();
    } catch (e: any) {
      console.error(e);
    }
    setUploadConfirmLoading(false);
  };

  const handleExportExcel = () => {
    const ws = XLSX.utils.json_to_sheet(uploadExportData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'مبيعات');
    const month = saleDate ? saleDate.slice(0, 7) : new Date().toISOString().slice(0, 7);
    const fileName = branchName ? `${branchName}_${month}.xlsx` : `مبيعات_${month}.xlsx`;
    XLSX.writeFile(wb, fileName);
  };

  const resetUpload = () => {
    setShowUpload(false);
    setUploadStep('file');
    setUploadFile(null);
    setUploadPreview(null);
    setUploadUnmatched([]);
    setUploadOverrides({});
    setUploadHalfCats({});
    setUploadColumns([]);
    setUploadExportData([]);
    setUploadAllRecipes([]);
    setUploadCategories([]);
    // Auto-refresh reconciliation with newly confirmed sales
    setTimeout(() => handleRun(), 100);
  };

  // ── Auto upload (direct upload with hardcoded column mapping for .xls exports) ──
  const handleAutoUpload = async () => {
    if (!uploadFile || !branchId) return;
    setUploadLoading(true);
    setUploadColumns([]);
    setUploadStep('file');
    try {
      const fd = new FormData();
      fd.append('file', uploadFile);
      fd.append('branch_id', branchId);
      fd.append('sale_date', saleDate);
      const res = await api.post('/menu-engineering/upload-sales', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setUploadPreview(res.data.preview);
      setUploadUnmatched(res.data.unmatched ?? []);
      setUploadAllRecipes(res.data.all_recipes ?? []);
      setUploadCategories(res.data.categories ?? []);
      const halfMap: Record<string, boolean> = {};
      for (const c of (res.data.categories ?? [])) {
        if (c.half) halfMap[c.name] = true;
      }
      setUploadHalfCats(halfMap);
      setUploadStep('review');
    } catch (e: any) {
      console.error(e);
    }
    setUploadLoading(false);
  };

  // ── Save reconciliation ──
  const handleSaveRecon = async () => {
    if (!inv || !branchId || !from || !to) return;
    setSaving(true);
    try {
      const items = ingIds.map((id: string) => ({
        ingredient_id: id,
        ingredient_name: ingIndex.names[id],
        unit: '',
        opening_qty: opening(id) ?? 0,
        purchases_qty: purchases(id) ?? 0,
        closing_actual: closing(id) ?? 0,
        sales_qty: totals[id] ?? 0,
        waste_qty: 0,
      }));
      await api.post('/menu-engineering/reconciliations', {
        branch_id: branchId,
        from_date: from,
        to_date: to,
        items,
        sales_data: salesMap,
      });
      setSaving(false);
    } catch (e: any) {
      console.error(e);
      setSaving(false);
    }
  };

  const loadSavedRecons = async () => {
    setLoadingSaved(true);
    try {
      const res = await api.get('/menu-engineering/reconciliations', {
        params: { branch_id: branchId || undefined },
      });
      setSavedRecons(res.data);
    } catch (e: any) {
      console.error(e);
    }
    setLoadingSaved(false);
  };

  const handleLoadRecon = async (id: string) => {
    try {
      const res = await api.get(`/menu-engineering/reconciliations/${id}`);
      const d = res.data;
      setFrom(d.from_date.slice(0, 10));
      setTo(d.to_date.slice(0, 10));
      setBranchId(d.branch_id);
      if (d.sales_data) setSalesMap(d.sales_data);
      // Transform saved items directly into inv format (no recalculation)
      const items = d.items as any[];
      const ingIds = items.map((i: any) => i.ingredient_id);
      const ingNames: Record<string, string> = {};
      const opening: Record<string, number> = {};
      const purchases: Record<string, number> = {};
      const closing: Record<string, number> = {};
      const actual: Record<string, number> = {};
      const totals: Record<string, number> = {};
      const variance: Record<string, number> = {};
      for (const i of items) {
        ingNames[i.ingredient_id] = i.ingredient_name;
        opening[i.ingredient_id] = i.opening_qty;
        purchases[i.ingredient_id] = i.purchases_qty;
        closing[i.ingredient_id] = i.closing_actual;
        actual[i.ingredient_id] = i.actual_received;
        totals[i.ingredient_id] = i.sales_qty;
        variance[i.ingredient_id] = i.diff_qty;
      }
      setInv({
        ingredient_ids: ingIds,
        ingredient_names: ingNames,
        categories: {},
        totals,
        opening,
        purchases,
        closing,
        actual,
        variance,
      });
      setShowSavedRecons(false);
    } catch (e: any) {
      console.error(e);
    }
  };

  const handleExportRecon = async (id: string, branchName: string, fromDate: string) => {
    try {
      const res = await api.get(`/menu-engineering/reconciliations/${id}/export`, {
        responseType: 'blob',
      });
      const month = fromDate.slice(0, 7);
      const fileName = `تقرير خامات ${branchName} ${month}.xlsx`;
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = fileName;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (e: any) {
      console.error(e);
    }
  };

  const handleDeleteRecon = async (id: string) => {
    if (!window.confirm('تأكيد حذف التسوية؟')) return;
    try {
      await api.delete(`/menu-engineering/reconciliations/${id}`);
      loadSavedRecons();
    } catch (e: any) {
      console.error(e);
    }
  };

  // ── Run reconciliation ──
  const handleRun = async () => {
    if (!branchId || !from || !to) return;
    setLoading(true);
    try {
      const sales: Record<string, number> = {};
      for (const [id, val] of Object.entries(salesMap)) {
        const n = parseFloat(val);
        if (n > 0) sales[id] = n;
      }
      const res = await api.post('/menu-engineering/reconcile', {
        branch_id: branchId, from, to, sales,
      });
      setInv(res.data);
    } catch (e: any) {
      console.error(e);
    }
    setLoading(false);
  };

  const fmt = (n: number | undefined | null) => (n ?? 0).toFixed(2);
  const ingIds = ingIndex.ids;

  // ── Inventory helpers ──
  const opening = (id: string) => inv?.opening?.[id] ?? null;
  const purchases = (id: string) => inv?.purchases?.[id] ?? null;
  const closing = (id: string) => inv?.closing?.[id] ?? null;
  const actual = (id: string) => {
    const o = opening(id); const p = purchases(id); const c = closing(id);
    if (o === null || p === null || c === null) return null;
    return o + p - c;
  };
  const variance = (id: string) => {
    const a = actual(id); const t = totals[id] ?? 0;
    if (a === null) return null;
    return t - a; // theoretical - actual
  };

  return (
    <div className="flex-1 flex flex-col h-full" dir="rtl">
      {/* ─── Header ─── */}
      <div className="bg-white border-b px-6 py-3 shrink-0">
        <div className="flex items-center justify-between flex-wrap gap-3">
          <h2 className="text-lg font-bold">تسوية المنيو</h2>
          <div className="flex gap-3 items-center flex-wrap">
            <select
              value={branchId}
              onChange={(e) => {
                setBranchId(e.target.value);
                setInv(null);
                setSalesMap({});
              }}
              className="border rounded px-3 py-1.5 text-sm outline-none w-52"
            >
              <option value="">— اختر الفرع —</option>
              {branches.map((b: any) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </select>
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="border rounded px-3 py-1.5 text-sm outline-none"
            />
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="border rounded px-3 py-1.5 text-sm outline-none"
            />
            <button
              onClick={handleRun}
              disabled={loading || !branchId}
              className="px-5 py-1.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
            >
              {loading ? '...' : 'تشغيل التسوية'}
            </button>
            <button
              onClick={() => {
                setShowUpload(true);
                const b = branches.find((x: any) => x.id === branchId);
                if (b) setBranchName(b.name);
              }}
              disabled={!branchId}
              className="px-5 py-1.5 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 disabled:opacity-50"
            >
              رفع مبيعات
            </button>
          </div>
        </div>
      </div>

      {/* ─── Body ─── */}
      <div className="flex-1 overflow-auto p-6">
        {!branchId && (
          <div className="text-center text-gray-400 py-20">الرجاء اختيار الفرع</div>
        )}

        {branchId && visibleRecipes.length === 0 && (
          <div className="text-center text-gray-400 py-20 bg-white border rounded-xl">
            لا توجد وصفات نشطة لهذا الفرع
          </div>
        )}

        {branchId && visibleRecipes.length > 0 && (
          <div>
          <div className="flex items-center gap-3 mb-3">
            <select value={filterCat} onChange={(e) => setFilterCat(e.target.value)}
              className="border rounded px-2 py-1 text-sm outline-none bg-white">
              <option value="">كل الكاتيجوريز</option>
              {Object.keys(grouped).map(cat => (
                <option key={cat} value={cat}>{cat}</option>
              ))}
            </select>
            <input type="text" placeholder="بحث بالاسم..." value={filterSearch}
              onChange={(e) => setFilterSearch(e.target.value)}
              className="border rounded px-2 py-1 text-sm outline-none w-48" />
            <button onClick={() => { setShowSavedRecons(true); loadSavedRecons(); }}
              className="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 border">
              التسويات المحفوظة
            </button>
          </div>
          <div className="bg-white border rounded-xl shadow-sm overflow-x-auto">
            <table className="w-full text-sm border-collapse" style={{ minWidth: ingIds.length * 130 + 300 }}>
              {/* ─── Column headers ─── */}
              <thead className="sticky top-0 z-10">
                <tr className="bg-gray-50 border-b-2 border-gray-300">
                  <th className="sticky right-0 bg-gray-50 z-10 p-2.5 text-right font-bold text-gray-700 min-w-[220px] border-l border-gray-200">
                    الصنف
                  </th>
                  <th className="p-2.5 text-center font-bold text-gray-700 min-w-[90px] border-l border-gray-200">
                    المبيعات
                  </th>
                  {ingIds.map((id) => (
                    <th
                      key={id}
                      className="p-2 text-center font-bold text-gray-700 min-w-[120px] border-l border-gray-200 whitespace-nowrap bg-orange-50"
                    >
                      {ingIndex.names[id]}
                    </th>
                  ))}
                  <th className="p-2 text-center font-bold text-gray-700 min-w-[80px]">بيان</th>
                </tr>
              </thead>
              <tbody>
                {filteredGrouped.map(([catName, items]) => (
                  <Fragment key={catName}>
                    {/* Category header */}
                    <tr className="bg-gray-100 border-b border-gray-200">
                      <td
                        colSpan={2 + ingIds.length + 1}
                        className="p-2.5 font-bold text-gray-800 text-base"
                      >
                        {catName}
                      </td>
                    </tr>

                    {/* Recipe rows */}
                    {(items as any[]).map((r) => {
                      const cells = theoForRecipe(r.id);
                      const recipeQtys: Record<string, number> = {};
                      for (const it of r.items ?? []) {
                        recipeQtys[it.ingredient_id] = it.qty;
                      }
                      return (
                        <tr
                          key={r.id}
                          className="border-b border-gray-100 hover:bg-blue-50/20"
                        >
                          <td className="sticky right-0 bg-white z-10 p-2 text-gray-800 border-l border-gray-100">
                            {r.name}
                          </td>
                          <td className="p-2 text-center border-l border-gray-100">
                            <input
                              type="text"
                              inputMode="numeric"
                              pattern="[0-9]*"
                              value={salesMap[r.id] ?? ''}
                              onChange={(e) =>
                                setSalesMap((p) => ({ ...p, [r.id]: e.target.value }))
                              }
                              className="w-20 border border-gray-300 rounded px-2 py-1 text-center font-mono text-sm outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-200 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                              placeholder="0"
                            />
                          </td>
                          {ingIds.map((id) => {
                            const rqty = recipeQtys[id] ?? 0;
                            return (
                              <td
                                key={id}
                                className="p-1.5 text-center border-l border-gray-100"
                              >
                                <div className="font-mono text-gray-800 leading-tight">
                                  {fmt(cells[id] ?? 0)}
                                </div>
                                <div className="text-[10px] text-gray-400 leading-tight">
                                  {rqty > 0 ? `وصفة: ${fmt(rqty)}` : ''}
                                </div>
                              </td>
                            );
                          })}
                          <td className="p-2 text-center text-xs text-gray-400">نظري</td>
                        </tr>
                      );
                    })}
                    {/* Category sales subtotal */}
                    <tr className="bg-gray-50 border-b border-gray-200">
                      <td className="p-2 font-bold text-gray-700">{catName} — إجمالي البيع</td>
                      <td className="p-2 text-center font-bold text-blue-700">
                        {(items as any[]).reduce((sum: number, r: any) => sum + (parseFloat(salesMap[r.id]) || 0), 0)}
                      </td>
                      {ingIds.map((id) => (
                        <td key={id} className="p-2 text-center border-l border-gray-100" />
                      ))}
                      <td className="p-2 text-center text-xs text-gray-400" />
                    </tr>
                  </Fragment>
                ))}

                {/* ─── Summary separator ─── */}
                <tr className="bg-gray-50 border-b border-gray-200">
                  <td
                    colSpan={2 + ingIds.length + 1}
                    className="p-2 font-bold text-gray-700"
                  >
                    ملخص التسوية
                  </td>
                </tr>

                {/* Total theoretical */}
                <tr className="border-b border-gray-100">
                  <td className="sticky right-0 bg-white z-10 p-2 font-bold text-gray-800 border-l border-gray-100">
                    إجمالي النظري
                  </td>
                  <td className="p-2 border-l border-gray-100" />
                  {ingIds.map((id) => (
                    <td
                      key={id}
                      className="p-2 text-center font-mono font-bold text-blue-800 border-l border-gray-100 bg-blue-50/30"
                    >
                      {fmt(totals[id] ?? 0)}
                    </td>
                  ))}
                  <td className="p-2 text-center text-xs text-gray-400">نظري</td>
                </tr>

                {/* Spacer */}
                <tr className="h-1" />

                {/* Opening */}
                <tr className="border-b border-gray-100">
                  <td className="sticky right-0 bg-white z-10 p-2 text-gray-700 border-l border-gray-100">أول المدة</td>
                  <td className="p-2 border-l border-gray-100" />
                  {ingIds.map((id) => (
                    <td
                      key={id}
                      className="p-2 text-center font-mono text-gray-700 border-l border-gray-100"
                    >
                      {inv ? fmt(opening(id)!) : '—'}
                    </td>
                  ))}
                  <td className="p-2 text-center text-xs text-gray-400">مخزون</td>
                </tr>

                {/* Purchases */}
                <tr className="border-b border-gray-100">
                  <td className="sticky right-0 bg-white z-10 p-2 text-gray-700 border-l border-gray-100">المشتريات</td>
                  <td className="p-2 border-l border-gray-100" />
                  {ingIds.map((id) => (
                    <td
                      key={id}
                      className="p-2 text-center font-mono text-gray-700 border-l border-gray-100"
                    >
                      {inv ? fmt(purchases(id)!) : '—'}
                    </td>
                  ))}
                  <td className="p-2 text-center text-xs text-gray-400">مخزون</td>
                </tr>

                {/* Closing */}
                <tr className="border-b border-gray-100">
                  <td className="sticky right-0 bg-white z-10 p-2 text-gray-700 border-l border-gray-100">آخر المدة</td>
                  <td className="p-2 border-l border-gray-100" />
                  {ingIds.map((id) => (
                    <td
                      key={id}
                      className="p-2 text-center font-mono text-gray-700 border-l border-gray-100"
                    >
                      {inv ? fmt(closing(id)!) : '—'}
                    </td>
                  ))}
                  <td className="p-2 text-center text-xs text-gray-400">مخزون</td>
                </tr>

                {/* Spacer */}
                <tr className="h-1" />

                {/* Actual consumption */}
                <tr className="border-b border-gray-200 bg-blue-50/20">
                  <td className="sticky right-0 bg-white z-10 p-2 font-bold text-blue-800 border-l border-gray-100">
                    المنصرف الفعلي
                  </td>
                  <td className="p-2 border-l border-gray-100" />
                  {ingIds.map((id) => {
                    const v = actual(id);
                    return (
                      <td
                        key={id}
                        className="p-2 text-center font-mono font-bold text-blue-700 border-l border-gray-100"
                      >
                        {v !== null ? fmt(v) : '—'}
                      </td>
                    );
                  })}
                  <td className="p-2 text-center text-xs text-gray-400">فعلي</td>
                </tr>

                {/* Variance */}
                <tr className="bg-gray-50/50">
                  <td className="sticky right-0 bg-white z-10 p-2 font-bold text-gray-800 border-l border-gray-100">
                    الانحراف
                  </td>
                  <td className="p-2 border-l border-gray-100" />
                  {ingIds.map((id) => {
                    const v = variance(id);
                    let cls = 'text-gray-400';
                    if (v !== null) {
                      cls = v > 0 ? 'text-green-600 font-bold' : v < 0 ? 'text-red-600 font-bold' : 'text-gray-400';
                    }
                    return (
                      <td
                        key={id}
                        className={`p-2 text-center font-mono border-l border-gray-100 ${cls}`}
                      >
                        {v !== null ? fmt(v) : '—'}
                      </td>
                    );
                  })}
                  <td className="p-2 text-center text-xs text-gray-400">{inv ? 'انحراف' : ''}</td>
                </tr>
              </tbody>
            </table>
          </div>
          {inv && (
            <div className="flex justify-center mt-4">
              <button onClick={handleSaveRecon} disabled={saving}
                className="px-6 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 disabled:opacity-50">
                {saving ? '...' : 'حفظ التسوية'}
              </button>
            </div>
          )}
          </div>
        )}
      {/* ─── Upload Sales Modal (2-step: upload → review → done) ─── */}
      {showUpload && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center" onClick={(e) => { if (e.target === e.currentTarget) resetUpload(); }}>
          <div className="bg-white rounded-xl shadow-2xl max-w-7xl w-full max-h-[95vh] overflow-auto m-4" onClick={(e) => e.stopPropagation()}>
            <div className="p-8">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-bold">رفع مبيعات من إكسيل</h3>
                <button onClick={resetUpload} className="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
              </div>

              {uploadStep === 'file' && (
                <div className="space-y-4">
                  <div className="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center">
                    <input ref={fileInputRef} type="file" accept=".xlsx,.xls" onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)} className="hidden" />
                    <div className="text-gray-400 mb-3 text-2xl">+</div>
                    <p className="text-sm text-gray-500 mb-3">{uploadFile ? uploadFile.name : 'اختر ملف XLSX'}</p>
                    <button onClick={() => fileInputRef.current?.click()} className="px-4 py-1.5 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 border">تصفح</button>
                  </div>
                  <div className="flex justify-center"><button onClick={handleAutoUpload} disabled={!uploadFile || uploadLoading} className="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">{uploadLoading ? 'جاري المعالجة...' : 'رفع ومعالجة'}</button></div>
                </div>
              )}

              {uploadStep === 'review' && (
                <div className="space-y-4">
                  {uploadCategories.length > 0 && (
                    <div className="bg-gray-50 rounded-lg p-3 border">
                      <h4 className="text-sm font-bold text-gray-700 mb-2">الكاتيجوريز — إذا كان "نص" يتقسط العدد على 2</h4>
                      <div className="flex flex-wrap gap-4">
                        {uploadCategories.map((c: any) => (
                          <label key={c.name} className="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" checked={!!uploadHalfCats[c.name]} onChange={(e) => setUploadHalfCats((p) => ({ ...p, [c.name]: e.target.checked }))} className="rounded border-gray-300" />
                            <span className={uploadHalfCats[c.name] ? 'text-orange-700 font-bold' : 'text-gray-700'}>{c.name} {uploadHalfCats[c.name] ? '(نصف)' : ''}</span>
                          </label>
                        ))}
                      </div>
                    </div>
                  )}
                  <div className="flex items-center gap-4 text-sm text-gray-600 flex-wrap">
                    <span>المطابق: <strong className="text-emerald-700">{uploadPreview?.length ?? 0}</strong></span>
                    <span>غير المطابق: <strong className="text-red-600">{uploadUnmatched.length}</strong></span>
                  </div>
                  {uploadPreview && uploadPreview.length > 0 && (
                    <div>
                      <h4 className="text-sm font-bold text-gray-700 mb-2">الأصناف المطابقة</h4>
                      <div className="max-h-48 overflow-y-auto border rounded-lg">
                        <table className="w-full text-sm">
                          <thead className="bg-gray-50 sticky top-0"><tr>
                            <th className="p-2 text-right font-bold text-gray-600">#</th>
                            <th className="p-2 text-right font-bold text-gray-600">الاسم في الملف</th>
                            <th className="p-2 text-right font-bold text-gray-600">المرتبط بـ</th>
                            <th className="p-2 text-center font-bold text-gray-600">العدد</th>
                            <th className="p-2 text-center font-bold text-gray-600">الدقة</th>
                          </tr></thead>
                          <tbody>
                            {uploadPreview.map((p: any, i: number) => {
                              const isHalf = !!uploadHalfCats[p.category];
                              const displayQty = isHalf ? (p.qty_sold / 2) : p.qty_sold;
                              return (
                                <tr key={p.recipe_id + (p.size || '')} className={`border-b border-gray-100 even:bg-gray-50/50 ${isHalf ? 'bg-orange-50/30' : ''}`}>
                                  <td className="p-2 text-gray-400">{i + 1}</td>
                                  <td className="p-2 text-gray-700">{p.source_name}{p.size ? ` (${p.size})` : ''}</td>
                                  <td className="p-2">
                                    <SearchableSelect value={uploadOverrides[overrideKey(p.source_name, p.size || '')] || p.recipe_id} onChange={(val) => setUploadOverrides((prev) => ({ ...prev, [overrideKey(p.source_name, p.size || '')]: val }))} options={uploadAllRecipes} />
                                  </td>
                                  <td className="p-2 text-center font-mono">{displayQty}</td>
                                  <td className="p-2 text-center">
                                    <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${p.confidence >= 100 ? 'bg-emerald-100 text-emerald-700' : p.confidence >= 95 ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700'}`}>{p.confidence}%</span>
                                  </td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}
                  {uploadUnmatched.length > 0 && (
                    <div>
                      <h4 className="text-sm font-bold text-red-700 mb-2">الأصناف غير المطابقة — اختر الصنف المناسب</h4>
                      <div className="max-h-48 overflow-y-auto border border-red-200 rounded-lg">
                        <table className="w-full text-sm">
                          <thead className="bg-red-50 sticky top-0"><tr>
                            <th className="p-2 text-right font-bold text-red-700">الاسم في الملف</th>
                            <th className="p-2 text-center font-bold text-red-700">العدد</th>
                            <th className="p-2 text-right font-bold text-red-700">اختيار الصنف</th>
                          </tr></thead>
                          <tbody>
                            {uploadUnmatched.map((u: any) => {
                              const isHalf = !!uploadHalfCats[u.category];
                              const displayQty = isHalf ? (u.qty_sold / 2) : u.qty_sold;
                              const uKey = overrideKey(u.source_name, u.size || '');
                              return (
                                <tr key={uKey} className={`border-b border-red-100 even:bg-red-50/20 ${isHalf ? 'bg-orange-50/30' : ''}`}>
                                  <td className="p-2 text-gray-800 font-medium">{u.source_name}{u.size ? ` (${u.size})` : ''}</td>
                                  <td className="p-2 text-center font-mono">{displayQty}</td>
                                  <td className="p-2">
                                    <SearchableSelect value={uploadOverrides[uKey] || ''} onChange={(val) => setUploadOverrides((prev) => ({ ...prev, [uKey]: val }))} options={uploadAllRecipes} placeholder="— تجاهل —" />
                                  </td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}
                  <div className="flex justify-center gap-3 pt-2 flex-wrap">
                    <button onClick={handleDirectParse} disabled={uploadConfirmLoading} className="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">{uploadConfirmLoading ? '...' : 'بارسنج مباشر'}</button>
                    <button onClick={handleConfirm} disabled={uploadConfirmLoading} className="px-6 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 disabled:opacity-50">{uploadConfirmLoading ? '...' : 'تأكيد وحفظ المبيعات'}</button>
                    <button onClick={handleExportFromReview} className="px-4 py-2 text-sm bg-indigo-100 text-indigo-700 rounded-lg hover:bg-indigo-200 border border-indigo-200">تصدير Excel</button>
                    <button onClick={() => { setUploadStep('file'); setUploadPreview(null); setUploadUnmatched([]); setUploadOverrides({}); setUploadHalfCats({}); }} className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border rounded-lg">رجوع</button>
                  </div>
                </div>
              )}

              {uploadStep === 'done' && (
                <div className="text-center py-8 space-y-4">
                  <div className="text-emerald-600 text-5xl mb-4">&#10003;</div>
                  <h4 className="text-lg font-bold text-gray-800">تم حفظ المبيعات بنجاح</h4>
                  <p className="text-sm text-gray-500">{uploadExportData.length} صنف</p>
                  <div className="flex justify-center gap-3 pt-2">
                    <button onClick={handleExportExcel} disabled={!uploadExportData.length} className="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">تصدير Excel</button>
                    <button onClick={resetUpload} className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border rounded-lg">إغلاق</button>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* ─── Saved Reconciliations Modal ─── */}
      {showSavedRecons && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center" onClick={(e) => { if (e.target === e.currentTarget) setShowSavedRecons(false); }}>
          <div className="bg-white rounded-xl shadow-2xl max-w-7xl w-full max-h-[95vh] overflow-auto m-4" onClick={(e) => e.stopPropagation()}>
            <div className="p-8">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-bold">التسويات المحفوظة</h3>
                <button onClick={() => setShowSavedRecons(false)} className="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
              </div>
              {loadingSaved ? (
                <div className="text-center text-gray-400 py-8">...</div>
              ) : savedRecons.length === 0 ? (
                <div className="text-center text-gray-400 py-8">لا توجد تسويات محفوظة</div>
              ) : (
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 sticky top-0">
                    <tr className="border-b-2 border-gray-200">
                      <th className="p-2 text-right font-bold text-gray-600">الفرع</th>
                      <th className="p-2 text-center font-bold text-gray-600">من</th>
                      <th className="p-2 text-center font-bold text-gray-600">إلى</th>
                      <th className="p-2 text-center font-bold text-gray-600"># أصناف</th>
                      <th className="p-2 text-center font-bold text-gray-600">تاريخ الحفظ</th>
                      <th className="p-2 text-center font-bold text-gray-600"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {savedRecons.map((r: any) => (
                      <tr key={r.id} className="border-b border-gray-100 hover:bg-gray-50/50">
                        <td className="p-2 text-gray-800">{r.branch_name}</td>
                        <td className="p-2 text-center text-gray-600">{r.from_date}</td>
                        <td className="p-2 text-center text-gray-600">{r.to_date}</td>
                        <td className="p-2 text-center text-gray-600">{r.items_count}</td>
                        <td className="p-2 text-center text-gray-600">{new Date(r.created_at).toLocaleDateString('ar-EG')}</td>
                        <td className="p-2 text-center whitespace-nowrap">
                          <button onClick={() => handleLoadRecon(r.id)} className="px-2 py-1 text-xs text-blue-600 hover:text-blue-800 border border-blue-200 rounded">تحميل</button>
                          <button onClick={() => handleExportRecon(r.id, r.branch_name, r.from_date)} className="px-2 py-1 text-xs text-emerald-600 hover:text-emerald-800 border border-emerald-200 rounded mr-1">تصدير</button>
                          <button onClick={() => handleDeleteRecon(r.id)} className="px-2 py-1 text-xs text-red-600 hover:text-red-800 border border-red-200 rounded mr-1">حذف</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      )}

    </div>
    </div>
  );
}
