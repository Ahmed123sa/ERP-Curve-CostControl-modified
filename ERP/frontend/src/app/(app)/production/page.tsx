'use client';
import { useState, useEffect, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

export default function DailyProductionPage() {
  const qc = useQueryClient();
  const today = new Date();
  const [month, setMonth] = useState(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`);
  const [editData, setEditData] = useState<Record<string, Record<number, string>>>({});
  const [warehouseSel, setWarehouseSel] = useState<Record<string, string>>({});
  const [showAddItem, setShowAddItem] = useState(false);
  const [addItemId, setAddItemId] = useState('');
  const [manualRecipes, setManualRecipes] = useState<any[]>([]);
  const [calcExpr, setCalcExpr] = useState('');
  const [calcOpen, setCalcOpen] = useState(false);
  const [editingManualId, setEditingManualId] = useState<string | null>(null);
  const [changeToId, setChangeToId] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['daily-production', month],
    queryFn: () => api.get('/production/daily', { params: { month } }).then(r => r.data),
  });

  const { data: allItems } = useQuery({
    queryKey: ['items-list'],
    queryFn: () => api.get('/items').then(r => r.data),
    enabled: showAddItem || editingManualId !== null,
  });

  const { data: deductionsData } = useQuery({
    queryKey: ['production-deductions', month],
    queryFn: () => api.get('/production/deductions', { params: { month } }).then(r => r.data),
  });

  const itemsById = useMemo(() => {
    const map: Record<string, any> = {};
    (allItems || []).forEach((i: any) => { map[i.id] = i; });
    return map;
  }, [allItems]);

  const addManualItem = () => {
    if (!addItemId) return;
    if ((data?.recipes || []).some((r: any) => String(r.id) === String(addItemId))) {
      toast('هذا الصنف موجود بالفعل');
      setAddItemId('');
      setShowAddItem(false);
      return;
    }
    const item = itemsById[addItemId];
    if (!item) return;
    setManualRecipes(prev => [...prev, {
      id: item.id,
      _manual: true,
      name: item.name,
      unit: item.unit,
      outputItem: { id: item.id, name: item.name, unit: item.unit },
      outputWarehouse: null,
      is_size: false,
      size_index: null,
      grams: null,
      item_id: item.id,
      selectedWarehouse: null,
    }]);
    setEditData(prev => ({ ...prev, [addItemId]: {} }));
    setAddItemId('');
    setShowAddItem(false);
    toast.success(`تم إضافة ${item.name}`);
  };

  useEffect(() => {
    if (data?.data) {
      const mapped: Record<string, Record<number, string>> = {};
      for (const [recipeId, days] of Object.entries(data.data)) {
        mapped[recipeId] = {};
        for (const [day, qty] of Object.entries(days as Record<string, number>)) {
          mapped[recipeId][parseInt(day)] = String(qty);
        }
      }
      setEditData(prev => ({ ...prev, ...mapped }));
      const wh: Record<string, string> = {};
      for (const r of (data.recipes || [])) {
        if (r.selectedWarehouse) wh[r.id] = r.selectedWarehouse;
      }
      setWarehouseSel(prev => ({ ...prev, ...wh }));
    } else {
      setEditData({});
      setWarehouseSel({});
    }
    if (data?.recipes) {
      const apiIds = new Set(data.recipes.map((r: any) => r.id));
      setManualRecipes(prev => prev.filter(r => !apiIds.has(r.id)));
    }
  }, [data]);

  const computeCalc = (expr: string): string | null => {
    if (!expr.trim()) return null;
    try {
      const sanitized = expr.replace(/[^0-9+\-*/().%]/g, '');
      const r = Function('"use strict"; return (' + sanitized + ')')();
      if (isFinite(r)) return Number.isInteger(r) ? String(r) : r.toFixed(3);
    } catch {}
    return null;
  };

  const saveMutation = useMutation({
    mutationFn: (entries: { recipe_id: string; day: number; qty: number; warehouse_id?: string }[]) =>
      api.post('/production/daily', { month, entries }),
    onSuccess: () => {
      toast.success('تم حفظ الإنتاج اليومي');
      qc.invalidateQueries({ queryKey: ['daily-production'] });
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || JSON.stringify(err?.response?.data?.errors) || 'خطأ في الحفظ'),
  });

  const postMutation = useMutation({
    mutationFn: () => api.post('/production/post', { month }),
    onSuccess: (r: any) => {
      toast.success(r.data.message);
      qc.invalidateQueries({ queryKey: ['daily-production'] });
    },
    onError: () => toast.error('خطأ في الترحيل'),
  });

  const toggleDeduction = useMutation({
    mutationFn: ({ recipe_id, deduct }: { recipe_id: string; deduct: boolean }) =>
      api.post('/production/deductions/toggle', { recipe_id, month, deduct }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['production-deductions'] });
    },
    onError: () => toast.error('خطأ في حفظ الخصم'),
  });

  const updateCell = (recipeId: string, day: number, val: string) => {
    setEditData(prev => ({
      ...prev,
      [recipeId]: { ...(prev[recipeId] || {}), [day]: val },
    }));
  };

  const daysInMonth = data?.month
    ? new Date(parseInt(data.month.split('-')[0]), parseInt(data.month.split('-')[1]), 0).getDate()
    : 30;

  const handleSave = () => {
    const entries: { recipe_id: string; day: number; qty: number; warehouse_id?: string }[] = [];
    for (const [recipeId, days] of Object.entries(editData)) {
      const wh = warehouseSel[recipeId] || undefined;
      for (const [day, val] of Object.entries(days)) {
        const qty = parseFloat(val) || 0;
        entries.push({ recipe_id: recipeId, day: parseInt(day), qty, warehouse_id: wh });
      }
    }
    if (!entries.length) { toast('لا توجد كميات للحفظ'); return; }
    saveMutation.mutate(entries);
  };

  const totalRow = (recipeId: string): string => {
    const days = editData[recipeId] || {};
    let sum = 0;
    for (let d = 1; d <= daysInMonth; d++) {
      sum += parseFloat(days[d] || '0') || 0;
    }
    return sum.toLocaleString('ar-EG', { maximumFractionDigits: 2 });
  };

  const removeManualItem = (id: string) => {
    setManualRecipes(prev => prev.filter(r => r.id !== id));
    setEditData(prev => {
      const next = { ...prev };
      const oldData = prev[id];
      if (oldData && Object.keys(oldData).length) {
        const zeroed: Record<number, string> = {};
        for (const day of Object.keys(oldData)) {
          zeroed[parseInt(day)] = '0';
        }
        next[id] = zeroed;
      } else {
        delete next[id];
      }
      return next;
    });
    setWarehouseSel(prev => {
      const next = { ...prev };
      delete next[id];
      return next;
    });
    toast.success('تم حذف الصنف');
  };

  const changeManualItem = (oldRecipe: any, newItem: any) => {
    if (!newItem || !oldRecipe) return;
    const newId = newItem.id;
    setManualRecipes(prev => {
      const alreadyInApi = (data?.recipes || []).some((r: any) => String(r.id) === String(newId));
      if (alreadyInApi) {
        return prev.filter(r => r.id !== oldRecipe.id);
      }
      return [
        ...prev.filter(r => r.id !== oldRecipe.id),
        { id: newId, _manual: true, name: newItem.name, unit: newItem.unit,
          outputItem: { id: newItem.id, name: newItem.name, unit: newItem.unit },
          outputWarehouse: null, is_size: false, size_index: null, grams: null,
          item_id: newItem.id, selectedWarehouse: null },
      ];
    });
    setEditData(prev => {
      const next = { ...prev };
      const oldData = prev[oldRecipe.id];
      if (oldData) {
        next[newId] = { ...oldData };
        const zeroed: Record<number, string> = {};
        for (const day of Object.keys(oldData)) {
          zeroed[parseInt(day)] = '0';
        }
        next[oldRecipe.id] = zeroed;
      } else {
        next[newId] = {};
        delete next[oldRecipe.id];
      }
      return next;
    });
    setWarehouseSel(prev => {
      const next = { ...prev };
      next[newId] = next[oldRecipe.id] || '';
      delete next[oldRecipe.id];
      return next;
    });
    setEditingManualId(null);
    setChangeToId('');
    toast.success(`تم تغيير الصنف إلى ${newItem.name}`);
  };

  return (
    <div className="space-y-4" dir="rtl">
      <div className="flex items-center justify-between">
        <input
          type="month"
          value={month}
          onChange={(e) => setMonth(e.target.value)}
          className="px-3 py-2 border border-gray-200 rounded-xl text-sm"
        />
        <div className="flex gap-2">
          <button onClick={() => { setAddItemId(''); setShowAddItem(true); }}
            className="px-3 py-2 text-sm border border-dashed border-gray-300 text-gray-500 rounded-lg hover:border-blue-300 hover:text-blue-600">
            + إضافة صنف
          </button>
          <button
            onClick={handleSave}
            disabled={saveMutation.isPending}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40"
          >
            {saveMutation.isPending ? 'جاري الحفظ...' : '💾 حفظ'}
          </button>
          <button
            onClick={() => postMutation.mutate()}
            disabled={postMutation.isPending}
            className="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-40"
          >
            {postMutation.isPending ? 'جاري الترحيل...' : '📤 ترحيل للحسابات'}
          </button>
        </div>
      </div>

      {isLoading ? (
        <p className="text-gray-400">جاري التحميل...</p>
      ) : !data?.recipes?.length && !manualRecipes.length && !Object.keys(editData).length ? (
        <p className="text-gray-400 py-8 text-center">
          لا توجد وصفات أو أصناف — أضف وصفات من تبويب "إدارة الوصفات" أو استخدم زر "إضافة صنف"
        </p>
      ) : (
        <>
        {/* Quick Calculator */}
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <button onClick={() => setCalcOpen(!calcOpen)} className="w-full px-5 py-2 flex items-center gap-2 hover:bg-gray-50 transition-colors text-sm text-gray-500">
            <span className="text-lg">🧮</span> حاسبة
            <span className="mr-auto text-gray-300">{calcOpen ? '▲' : '▼'}</span>
          </button>
          {calcOpen && (
            <div className="px-5 py-3 border-t border-gray-100 bg-gray-50/50 flex flex-wrap items-center gap-3">
              <input type="text" value={calcExpr} onChange={(e) => setCalcExpr(e.target.value)}
                placeholder="أدخل العملية الحسابية مثل 150+200*3"
                className="flex-1 min-w-[250px] border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-400 font-mono text-left" />
              {(() => {
                const result = computeCalc(calcExpr);
                return result !== null ? (
                  <div className="flex items-center gap-2">
                    <span className="text-sm text-gray-500">=</span>
                    <span className="text-lg font-bold text-green-700 font-mono">{result}</span>
                    <button onClick={() => { navigator.clipboard.writeText(result); toast.success('تم النسخ'); }}
                      className="text-xs text-blue-500 hover:text-blue-700">نسخ</button>
                  </div>
                ) : null;
              })()}
            </div>
          )}
        </div>
        <div className="border border-gray-100 rounded-xl overflow-auto max-h-[calc(100vh-280px)]">
          <table className="w-full text-sm">
            <thead>
              <tr className="sticky top-0 z-10 bg-gray-50 text-right text-gray-500 text-xs shadow-sm">
                <th className="px-3 py-2.5 font-medium sticky right-0 z-20 bg-gray-50 min-w-[200px]">الصنف</th>
                <th className="px-3 py-2.5 font-medium min-w-[60px]">المخزن</th>
                {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((d) => (
                  <th key={d} className="px-2 py-2.5 font-medium text-center min-w-[50px]">{d}</th>
                ))}
                <th className="px-3 py-2.5 font-medium text-center bg-blue-50 min-w-[70px]">الإجمالي</th>
                <th className="px-3 py-2.5 font-medium text-center min-w-[70px]">خصم</th>
              </tr>
            </thead>
            <tbody>
              {[...(data.recipes || []), ...manualRecipes].map((recipe: any) => (
                <tr key={recipe.id}
                  className={`border-t border-gray-50 hover:bg-gray-50/50 ${recipe.is_size ? 'bg-purple-50/30 text-xs' : ''}`}>
                  <td className="px-3 py-1.5 sticky right-0 bg-white">
                    {recipe.is_size ? (
                      <>
                        <div className="font-medium text-gray-700 pr-4">
                          <span className="text-purple-500 ml-1">⊢</span>
                          {recipe.name}
                        </div>
                        <div className="text-xs text-gray-400 pr-4">
                          {recipe.grams} جم · {recipe.outputItem?.unit}
                        </div>
                      </>
                    ) : recipe._manual && editingManualId === recipe.id ? (
                      <div className="flex flex-col gap-1">
                        <select value={changeToId} onChange={(e) => setChangeToId(e.target.value)}
                          className="w-full border border-blue-200 rounded px-2 py-1 text-xs focus:outline-none">
                          <option value="">اختر الصنف...</option>
                          {(allItems || []).map((item: any) => (
                            <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
                          ))}
                        </select>
                        <div className="flex gap-1">
                          <button onClick={() => {
                            const found = (allItems || []).find((i: any) => String(i.id) === String(changeToId));
                            if (found) changeManualItem(recipe, found);
                          }} disabled={!changeToId}
                            className="text-[10px] px-2 py-0.5 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-40">حفظ</button>
                          <button onClick={() => { setEditingManualId(null); setChangeToId(''); }}
                            className="text-[10px] px-2 py-0.5 border border-gray-200 rounded hover:bg-gray-50">إلغاء</button>
                        </div>
                      </div>
                    ) : (
                      <>
                        <div className="font-medium text-gray-800 flex items-center gap-1.5">
                          {recipe.name}
                          {(manualRecipes.some(r => r.id === recipe.id) || recipe._manual) && (
                            <span className="flex gap-0.5 mr-auto">
                              <button onClick={() => { setEditingManualId(recipe.id); setChangeToId(''); }}
                                className="text-[10px] text-blue-500 hover:text-blue-700 border border-blue-200 px-1.5 py-0.5 rounded">تعديل</button>
                              <button onClick={() => removeManualItem(recipe.id)}
                                className="text-[10px] text-red-400 hover:text-red-600 border border-red-200 px-1.5 py-0.5 rounded">حذف</button>
                            </span>
                          )}
                        </div>
                        <div className="text-xs text-gray-400">{recipe.outputItem?.unit} · {recipe.outputItem?.name}</div>
                      </>
                    )}
                  </td>
                  <td className="px-3 py-1.5 text-xs">
                    <select value={warehouseSel[recipe.id] ?? ''}
                      onChange={e => setWarehouseSel(p => ({...p, [recipe.id]: e.target.value}))}
                      className="w-28 px-1 py-1 text-xs border border-gray-200 rounded bg-white focus:border-blue-300 focus:outline-none">
                      <option value="">—</option>
                      {(data?.warehouses || []).map((w: any) => (
                        <option key={w.id} value={w.id}>{w.name}</option>
                      ))}
                    </select>
                  </td>
                  {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((d) => (
                    <td key={d} className="px-1 py-1 text-center">
                      <input
                        type="number"
                        value={editData[recipe.id]?.[d] ?? ''}
                        onChange={(e) => updateCell(recipe.id, d, e.target.value)}
                        className="w-14 px-1 py-1 text-sm text-center border border-transparent rounded
                                   focus:border-blue-300 focus:outline-none bg-transparent"
                        placeholder="—"
                      />
                    </td>
                  ))}
                  <td className="px-3 py-1.5 text-center font-medium text-blue-700 bg-blue-50/50">
                    {totalRow(recipe.id)}
                  </td>
                  <td className="px-3 py-1.5 text-center">
                    <input type="checkbox"
                      checked={!!deductionsData?.[recipe.id]}
                      onChange={(e) => toggleDeduction.mutate({ recipe_id: recipe.id, deduct: e.target.checked })}
                      className="w-4 h-4 accent-blue-600 cursor-pointer" />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        </>
      )}
      {showAddItem && (
        <div className="fixed inset-0 bg-black/30 z-50 flex items-center justify-center" onClick={() => setShowAddItem(false)}>
          <div className="bg-white rounded-xl shadow-xl p-5 w-full max-w-sm mx-4" onClick={(e) => e.stopPropagation()}>
            <h4 className="font-semibold text-sm mb-3">إضافة صنف للإنتاج اليومي</h4>
            <select value={addItemId} onChange={(e) => setAddItemId(e.target.value)}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mb-4">
              <option value="">اختر الصنف...</option>
              {(allItems || []).map((item: any) => (
                <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
              ))}
            </select>
            <div className="flex justify-end gap-2">
              <button onClick={() => setShowAddItem(false)} className="px-3 py-1.5 text-xs border border-gray-200 rounded-lg hover:bg-gray-50">إلغاء</button>
              <button onClick={addManualItem} disabled={!addItemId}
                className="px-3 py-1.5 text-xs bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40">إضافة</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
