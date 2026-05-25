'use client';

import { Fragment, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

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

  // ── Branches ──
  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
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
          <div className="bg-white border rounded-xl shadow-sm overflow-x-auto">
            <table className="w-full text-sm border-collapse" style={{ minWidth: ingIds.length * 130 + 300 }}>
              {/* ─── Column headers ─── */}
              <thead>
                <tr className="bg-gray-50 border-b-2 border-gray-300">
                  <th className="p-2.5 text-right font-bold text-gray-700 min-w-[220px] border-l border-gray-200">
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
                {Object.entries(grouped).map(([catName, items]) => (
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
                          <td className="p-2 text-gray-800 border-l border-gray-100">
                            {r.name}
                          </td>
                          <td className="p-2 text-center border-l border-gray-100">
                            <input
                              type="number"
                              min="0"
                              step="1"
                              value={salesMap[r.id] ?? ''}
                              onChange={(e) =>
                                setSalesMap((p) => ({ ...p, [r.id]: e.target.value }))
                              }
                              className="w-20 border border-gray-300 rounded px-2 py-1 text-center font-mono text-sm outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-200"
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
                  <td className="p-2 font-bold text-gray-800 border-l border-gray-100">
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
                  <td className="p-2 text-gray-700 border-l border-gray-100">أول المدة</td>
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
                  <td className="p-2 text-gray-700 border-l border-gray-100">المشتريات</td>
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
                  <td className="p-2 text-gray-700 border-l border-gray-100">آخر المدة</td>
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
                  <td className="p-2 font-bold text-blue-800 border-l border-gray-100">
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
                  <td className="p-2 font-bold text-gray-800 border-l border-gray-100">
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
        )}
      </div>
    </div>
  );
}
