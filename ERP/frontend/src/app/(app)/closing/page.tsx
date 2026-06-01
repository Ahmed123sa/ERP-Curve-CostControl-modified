'use client';

import { useState, Fragment } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function ClosingPage() {
  const qc = useQueryClient();
  const [viewType, setViewType] = useState<'matrix' | 'single'>('matrix');
  const [warehouseId, setWarehouseId] = useState('');
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));

  // Edit Mode
  const [editMode, setEditMode] = useState(false);
  const [pendingEdits, setPendingEdits] = useState<any[]>([]);
  const [popoverTarget, setPopoverTarget] = useState<any>(null);
  const [cellOrdersCache, setCellOrdersCache] = useState<Record<string, any[]>>({});
  const { currentClient } = useAuthStore();

  // 1. بيانات الـ Matrix
  const { data: matrixData, isLoading: matrixLoading } = useQuery({
    queryKey: ['grand-summary', month],
    queryFn: () => api.get('/reports/grand-summary', { params: { month } }).then(r => r.data),
    enabled: viewType === 'matrix',
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses', currentClient?.id],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const isBranch = (warehouses as any[]).find((w: any) => w.id === warehouseId)?.type === 'branch';

  // 2. بيانات الموقع الواحد (تقفيل + وارد يومي)
  const { data: singleData = [], isLoading: singleLoading } = useQuery({
    queryKey: ['closing', warehouseId, month],
    queryFn: () => api.get('/closing', { params: { warehouse_id: warehouseId, month } }).then((r) => r.data.data),
    enabled: viewType === 'single' && !!warehouseId,
  });

  const { data: dailyData, isLoading: dailyLoading } = useQuery({
    queryKey: ['daily', isBranch ? 'branch' : 'warehouse', warehouseId, month],
    queryFn: () => api.get(isBranch ? '/reports/branch-daily' : '/reports/warehouse-daily', {
      params: { [isBranch ? 'branch_id' : 'warehouse_id']: warehouseId, month },
    }).then((r) => r.data),
    enabled: viewType === 'single' && !!warehouseId,
  });

  const isBranchSelected = (() => {
    if (!warehouseId) return false;
    const wh = (warehouses as any[]).find((w: any) => w.id === warehouseId);
    return wh?.type === 'branch';
  })();

  const generateMutation = useMutation({
    mutationFn: () => api.post('/closing/generate', { month, warehouse_id: viewType === 'matrix' ? 'all' : warehouseId }),
    onSuccess: () => {
      toast.success('تم تحديث الحسابات بنجاح');
      qc.invalidateQueries({ queryKey: ['grand-summary'] });
      qc.invalidateQueries({ queryKey: ['closing'] });
    },
  });

  // مزامنة الجرد النهائي (تحميل الفعلي)
  // Edit Mode — حفظ التعديلات
  const fetchCellOrders = async (whId: string, itemId: string, date: string) => {
    const key = `${itemId}_${date}`;
    if (cellOrdersCache[key]) return cellOrdersCache[key];
    const res = await api.get('/closing/cell-orders', { params: { warehouse_id: whId, item_id: itemId, date } });
    const lines = res.data.lines || [];
    setCellOrdersCache(prev => ({ ...prev, [key]: lines }));
    return lines;
  };

  const editDailyCellMutation = useMutation({
    mutationFn: (payload: any) => api.patch('/closing/edit-daily-cell', payload),
  });

  const editCellValueMutation = useMutation({
    mutationFn: (payload: any) => api.patch('/closing/edit-cell-value', payload),
  });

  const saveAllEdits = async () => {
    if (pendingEdits.length === 0) return;
    const toastId = toast.loading('جاري حفظ التعديلات...');
    try {
      // Group by (warehouse_id, item_id, date, month)
      const groups: Record<string, any> = {};
      for (const edit of pendingEdits) {
        const gk = `${edit.warehouse_id}_${edit.item_id}_${edit.date}_${edit.month}`;
        if (!groups[gk]) {
          groups[gk] = { warehouse_id: edit.warehouse_id, item_id: edit.item_id, date: edit.date, month: edit.month, lines: [] };
        }
        groups[gk].lines.push({ order_id: edit.order_id, [edit.type === 'value' ? 'value' : 'qty']: edit.newVal });
      }
      const promises = Object.values(groups).map((g: any) =>
        g.lines[0]?.value !== undefined
          ? editCellValueMutation.mutateAsync(g)
          : editDailyCellMutation.mutateAsync(g)
      );
      await Promise.allSettled(promises);
      toast.dismiss(toastId);
      toast.success(`تم حفظ ${pendingEdits.length} تعديل وإعادة حساب التقفيل`);
      setPendingEdits([]);
      setEditMode(false);
      qc.invalidateQueries({ queryKey: ['grand-summary'] });
      qc.invalidateQueries({ queryKey: ['closing'] });
      qc.invalidateQueries({ queryKey: ['daily'] });
    } catch {
      toast.dismiss(toastId);
      toast.error('فشل حفظ بعض التعديلات');
    }
  };

  const syncActualMutation = useMutation({
    mutationFn: () => api.post('/closing/sync-physical', { 
      month, 
      warehouse_id: viewType === 'matrix' ? 'all' : warehouseId 
    }),
    onSuccess: () => {
      toast.success('تم تحميل الجرد الفعلي المحدث من موديول الجرد');
      qc.invalidateQueries({ queryKey: ['grand-summary'] });
      qc.invalidateQueries({ queryKey: ['closing'] });
    }
  });

  // 4. تحديث الجرد الفعلي فردياً
  const updateActualMutation = useMutation({
    mutationFn: ({ id, actual }: { id: string, actual: number }) => 
      api.patch(`/closing/${id}/actual`, { closing_qty_actual: actual }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['grand-summary'] });
    }
  });

  const { items = [], locations = [] } = matrixData || {};

  const mainWarehouses = locations.filter((l: any) => l.type === 'main');
  const subWarehouses = locations.filter((l: any) => l.type === 'sub');
  const branches = locations.filter((l: any) => l.type === 'branch');

  const downloadExport = (url: string, filename: string) => {
    const token = localStorage.getItem('erp_token');
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((res) => { if (!res.ok) throw new Error(); return res.blob(); })
      .then((blob) => {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
        URL.revokeObjectURL(link.href);
        toast.success('تم التصدير ✓');
      })
      .catch(() => toast.error('خطأ في التصدير'));
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader 
        title="تقفيل الخامات الشهري" 
        subtitle="المتابعة الشاملة للمخزون والقيم المالية"
        actions={
          <div className="flex gap-2">
            <select
              value={viewType}
              onChange={(e) => setViewType(e.target.value as any)}
              className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-bold bg-blue-50 text-blue-700 outline-none"
            >
              <option value="matrix">عرض شامل (Matrix)</option>
              <option value="single">عرض تفصيلي (موقع واحد)</option>
            </select>
            <input 
              type="month" 
              value={month} 
              onChange={(e) => setMonth(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none"
            />
            <button
              onClick={() => generateMutation.mutate()}
              disabled={generateMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50 flex items-center gap-2"
            >
              <span>{generateMutation.isPending ? 'جاري الحساب...' : 'تحديث الحسابات ⚙️'}</span>
            </button>

            {viewType === 'matrix' && (
              <>
                <button
                  onClick={() => syncActualMutation.mutate()}
                  className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 flex items-center gap-2"
                  title="تحميل الكميات الفعلية من موديول الجرد النهائي"
                >
                  <span>تحميل الجرد النهائي 📥</span>
                </button>
                <button
                  onClick={() => downloadExport(`${api.defaults.baseURL}/reports/grand-summary/export?month=${month}`, `مصفوفة_خامات_${month}.xlsx`)}
                  className="px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700"
                >إكسيل</button>
                <button
                  onClick={() => downloadExport(`${api.defaults.baseURL}/reports/grand-summary/export-pdf?month=${month}`, `مصفوفة_خامات_${month}.pdf`)}
                  className="px-3 py-1.5 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700"
                >PDF</button>
              </>
            )}
            {viewType === 'single' && warehouseId && (
              <>
                <button
                  onClick={() => {
                    if (editMode && pendingEdits.length > 0) { saveAllEdits(); return; }
                    setEditMode(!editMode);
                    if (editMode) { setPendingEdits([]); setPopoverTarget(null); }
                  }}
                  className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors flex items-center gap-1 ${
                    editMode ? 'bg-green-600 text-white hover:bg-green-700' : 'bg-amber-500 text-white hover:bg-amber-600'
                  }`}
                >
                  {editMode ? (pendingEdits.length > 0 ? `💾 حفظ التعديلات (${pendingEdits.length})` : '✏️ وضع التعديل') : '✏️ وضع التعديل'}
                </button>
                {!editMode && (
                  <>
                    <button
                      onClick={() => downloadExport(`${api.defaults.baseURL}/closing/export?warehouse_id=${warehouseId}&month=${month}`, `تقفيل_${month}.xlsx`)}
                      className="px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700"
                    >إكسيل</button>
                    <button
                      onClick={() => downloadExport(`${api.defaults.baseURL}/closing/export-pdf?warehouse_id=${warehouseId}&month=${month}`, `تقفيل_${month}.pdf`)}
                      className="px-3 py-1.5 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700"
                    >PDF</button>
                  </>
                )}
              </>
            )}
          </div>
        }
      />

      <div className="flex-1 overflow-auto p-4">
        {viewType === 'single' && (
          <div className="bg-white border border-gray-100 rounded-xl p-4 mb-4 flex gap-4 items-center">
             <label className="text-sm font-bold text-gray-700">اختر المخزن أو الفرع:</label>
             <select
               value={warehouseId}
               onChange={(e) => setWarehouseId(e.target.value)}
               className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none flex-1"
             >
               <option value="">اختر الموقع...</option>
               {warehouses.map((w: any) => <option key={w.id} value={w.id}>{w.name} ({w.type})</option>)}
             </select>
          </div>
        )}

        {viewType === 'matrix' ? (
          matrixLoading ? <div className="p-12 text-center text-gray-400">جاري تحميل الـ Matrix...</div> : (
            <div className="bg-white rounded-xl border border-gray-100 shadow-xl overflow-x-auto">
              <table className="text-right text-[10px] border-collapse w-full" dir="rtl">
                <thead className="bg-gray-800 text-white sticky top-0 z-10">
                  <tr>
                    <th rowSpan={2} className="p-2 border border-gray-700 min-w-[150px]">الصنف</th>
                    <th rowSpan={2} className="p-2 border border-gray-700">الوحدة</th>
                    {[...mainWarehouses, ...subWarehouses].map((loc: any) => (
                      <th key={loc.id} colSpan={2} className="p-1 border border-gray-700 text-center bg-blue-900/50">{loc.name} (مخزن)</th>
                    ))}
                    {branches.map((loc: any) => (
                      <th key={loc.id} colSpan={1} className="p-1 border border-gray-700 text-center bg-orange-900/50">{loc.name} (فرع)</th>
                    ))}
                    <th colSpan={4} className="p-1 border border-gray-700 text-center bg-gray-700 text-amber-400">الإجمالي العام</th>
                  </tr>
                  <tr className="bg-gray-700 text-[8px]">
                    {[...mainWarehouses, ...subWarehouses].map((loc: any) => (
                      <Fragment key={loc.id}>
                        <th className="p-1 border border-gray-600">أول</th>
                        <th className="p-1 border border-gray-600">وارد</th>
                      </Fragment>
                    ))}
                    {branches.map((loc: any) => (
                      <Fragment key={loc.id}>
                        <th className="p-1 border border-gray-600 text-green-300">مستلم</th>
                      </Fragment>
                    ))}
                    <th className="p-1 border border-gray-600 text-red-300">إجمالي منصرف فروع</th>
                    <th className="p-1 border border-gray-600">نظري</th>
                    <th className="p-1 border border-gray-600">فعلي</th>
                    <th className="p-1 border border-gray-600">فرق</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {items.map((item: any) => (
                    <tr key={item.item_id} className="hover:bg-blue-50/30 transition-colors border-b border-gray-50">
                      <td className="p-2 border-x border-gray-50 font-bold sticky right-0 bg-white z-20 shadow-sm">{item.item_name}</td>
                      <td className="p-2 border-x border-gray-50 text-gray-400">{item.unit}</td>
                      {[...mainWarehouses, ...subWarehouses].map((loc: any) => {
                        const d = item.locations[loc.id] || {};
                        return <Fragment key={loc.id}>
                          <td className="p-1 border-x border-gray-50 text-center bg-blue-50/5">{d.opening || '0'}</td>
                          <td className="p-1 border-x border-gray-50 text-center text-green-600 font-medium">{d.purchases || '0'}</td>
                        </Fragment>;
                      })}
                      {branches.map((loc: any) => {
                        const d = item.locations[loc.id] || {};
                        return <Fragment key={loc.id}>
                          <td className="p-1 border-x border-gray-50 text-center text-blue-600 font-bold">{d.internal_in || '0'}</td>
                        </Fragment>;
                      })}
                      <td className="p-1 border-x border-gray-100 text-center bg-red-50 text-red-700 font-bold">{item.totals.dispatch_qty || '0'}</td>
                      <td className="p-1 border-x border-gray-100 text-center bg-amber-50 font-bold">{item.totals.theoretical || '0'}</td>
                      <td className="p-1 border-x border-gray-100 text-center bg-white p-0">
                        <input key={`${item.item_id}-${item.totals.actual}`} type="number" defaultValue={item.totals.actual}
                          onBlur={(e) => {
                            const val = parseFloat(e.target.value);
                            if (!isNaN(val) && val !== item.totals.actual && item.totals.closing_id) {
                              updateActualMutation.mutate({ id: item.totals.closing_id, actual: val });
                            }
                          }}
                          className="w-full h-full bg-transparent text-center font-bold text-blue-700 outline-none focus:bg-blue-100" />
                      </td>
                      <td className="p-1 border-x border-gray-100 text-center font-bold text-red-600">{item.totals.diff || '0'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        ) : (
          singleLoading || dailyLoading ? <div className="p-12 text-center text-gray-400">جاري التحميل...</div> : (
            (() => {
              const daysInMonth = dailyData?.days_in_month || 30;
              // build a map of item_id -> daily days
              const dailyMap: Record<string, { days: number[], total: number }> = {};
              (dailyData?.items || []).forEach((i: any) => {
                dailyMap[i.item_id] = { days: i.days, total: i.total };
              });

              const mergedRows = singleData.map((row: any) => {
                const daily = dailyMap[row.item_id] || { days: [], total: 0 };
                const purchasesValue = Number(row.in_value ?? 0);
                return { ...row, daily, purchasesValue };
              });

              return (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-auto">
                  <table className="w-full text-xs whitespace-nowrap border-collapse" dir="rtl">
                    <thead className="sticky top-0 z-10">
                      <tr className="bg-gray-100 text-gray-600">
                        <th className="px-1.5 py-1.5 text-start font-semibold sticky right-0 bg-gray-100 z-10 border border-gray-300">الصنف</th>
                        <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">الوحدة</th>
                        <th className="px-1.5 py-1.5 text-center font-semibold text-gray-500 border border-gray-300">أول المدة</th>
                        {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((d) => (
                          <th key={d} className="px-1 py-1.5 text-center font-medium text-gray-400 min-w-[56px] border border-gray-300">{d}</th>
                        ))}
                        <th className="px-1.5 py-1.5 text-center font-semibold text-gray-700 border border-gray-300">إجمالي الوارد</th>
                        <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">متوسط السعر</th>
                        {isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">آخر المدة</th>}
                        {!isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">آخر المدة</th>}
                        {isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300 text-blue-600">المستلم الفعلي</th>}
                        {!isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300 text-orange-600">منصرف فروع</th>}
                        {!isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">نظري</th>}
                        <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">قيمة أول المدة</th>
                        <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">قيمة المشتريات</th>
                        {!isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">قيمة آخر المدة</th>}
                        {isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">قيمة آخر المدة</th>}
                        {isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300 text-blue-600">قيمة المستلم الفعلي</th>}
                        {!isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">قيمة النظري</th>}
                        {!isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">الفرق</th>}
                        {!isBranch && <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">قيمة الفرق</th>}
                        {!isBranch && <th className="px-1.5 py-1.5 text-center font-semibold text-green-700 border border-gray-300">إجمالي المشتريات</th>}
                      </tr>
                    </thead>
                    <tbody>
                      {mergedRows.map((row: any) => {
                        const avgCost = Number(row.avg_cost ?? 0);
                        const openingVal = Number(row.opening_value ?? 0);
                        const closingVal = Number(row.closing_qty_actual ?? 0) * avgCost;
                        const theoreticalVal = Number(row.closing_qty_theoretical ?? 0) * avgCost;
                        const diffVal = Number(row.diff_value ?? 0);
                        const diffQty = Number(row.diff_qty ?? 0);
                        return (
                          <tr key={row.id} className="hover:bg-gray-50/30">
                            <td className="px-1.5 py-1 text-start font-bold text-gray-800 sticky right-0 bg-white z-10 border border-gray-300">{row.item_name}</td>
                            <td className="px-1.5 py-1 text-center text-gray-400 border border-gray-300">{row.unit}</td>
                            <td className="px-1.5 py-1 text-center font-mono border border-gray-300">{row.opening_qty}</td>
                            {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((d) => {
                              const cellKey = `${row.item_id}_${String(d).padStart(2,'0')}`;
                              const cellVal = row.daily.days?.[d] > 0 ? Number(row.daily.days[d]) : 0;
                              const pending = pendingEdits.find((e: any) => e.item_id === row.item_id && e.day === d && e.type === 'qty');
                              const displayVal = pending ? pending.newVal : cellVal;

                              if (editMode) {
                                const orders = cellOrdersCache[cellKey];
                                const isMulti = orders && orders.length > 1;

                                if (isMulti) {
                                  return (
                                    <td key={d} className="px-1.5 py-1 text-center font-mono text-xs border border-gray-300">
                                      <button
                                        onClick={() => setPopoverTarget({
                                          warehouse_id: warehouseId, item_id: row.item_id, item_name: row.item_name,
                                          date: `${month}-${String(d).padStart(2,'0')}`, day: d, month, lines: orders,
                                        })}
                                        className="w-full text-center text-amber-700 font-bold bg-amber-50 hover:bg-amber-100 rounded cursor-pointer text-[10px]"
                                      >
                                        {orders.map((o: any) => o.qty).join('+')}
                                      </button>
                                    </td>
                                  );
                                }

                                return (
                                  <td key={d} className="px-1.5 py-1 text-center font-mono text-xs border border-gray-300">
                                    <input
                                      type="number"
                                      step="0.001"
                                      defaultValue={cellVal > 0 ? cellVal : ''}
                                      className="w-full h-full bg-transparent text-center text-green-700 outline-none focus:bg-yellow-100 rounded text-xs"
                                      onFocus={async () => {
                                        if (!cellOrdersCache[cellKey]) {
                                          try {
                                            const res = await api.get('/closing/cell-orders', {
                                              params: { warehouse_id: warehouseId, item_id: row.item_id, date: `${month}-${String(d).padStart(2,'0')}` }
                                            });
                                            setCellOrdersCache(prev => ({ ...prev, [cellKey]: res.data.lines || [] }));
                                          } catch {}
                                        }
                                      }}
                                      onChange={(e) => {
                                        const v = parseFloat(e.target.value) || 0;
                                        const ords = cellOrdersCache[cellKey] || [];
                                        if (ords.length !== 1) return;
                                        setPendingEdits(prev => {
                                          const others = prev.filter((ed: any) => !(ed.item_id === row.item_id && ed.day === d && ed.type === 'qty'));
                                          return [...others, {
                                            warehouse_id: warehouseId, item_id: row.item_id, day: d,
                                            date: `${month}-${String(d).padStart(2,'0')}`,
                                            month, order_id: ords[0].order_id, type: 'qty', newVal: v, oldVal: cellVal
                                          }];
                                        });
                                      }}
                                    />
                                  </td>
                                );
                              }

                              return (
                                <td key={d} className="px-2 py-1.5 text-center font-mono text-xs text-green-700 border border-gray-300">
                                  {cellVal > 0 ? cellVal.toFixed(3) : ''}
                                </td>
                              );
                            })}
                            <td className="px-1.5 py-1 text-center font-mono font-bold text-green-700 border border-gray-300">{row.daily.total.toFixed(3)}</td>
                            <td className="px-1.5 py-1 text-center font-mono font-bold border border-gray-300">{avgCost.toFixed(3)}</td>
                            {!isBranch && <td className="px-1.5 py-1 text-center font-mono border border-gray-300">{row.closing_qty_actual}</td>}
                            {isBranch && <td className="px-1.5 py-1 text-center font-mono border border-gray-300">{row.closing_qty_actual}</td>}
                            {isBranch && <td className="px-1.5 py-1 text-center font-mono text-blue-600 border border-gray-300">
                              {(() => { const v = Number(row.opening_qty || 0) + Number(row.in_qty || 0) - Number(row.closing_qty_actual || 0); return v > 0 ? v.toFixed(3) : ''; })()}
                            </td>}
                            {!isBranch && <td className="px-1.5 py-1 text-center font-mono text-orange-600 border border-gray-300">
                              {(Object.values(row.branch_dispatches || {}) as any[]).reduce((s: number, d: any) => s + Number(d.qty || 0), 0) || ''}
                            </td>}
                            {!isBranch && <td className="px-1.5 py-1 text-center font-mono text-gray-500 border border-gray-300">{row.closing_qty_theoretical}</td>}
                            <td className="px-1.5 py-1 text-center font-mono border border-gray-300">{openingVal.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                            <td className="px-1.5 py-1 text-center font-mono text-green-700 font-bold border border-gray-300">{row.purchasesValue.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                            {!isBranch && <td className="px-1.5 py-1 text-center font-mono border border-gray-300">{closingVal.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>}
                            {isBranch && <td className="px-1.5 py-1 text-center font-mono border border-gray-300">{closingVal.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>}
                            {isBranch && <td className="px-1.5 py-1 text-center font-mono text-blue-600 border border-gray-300">
                              {(() => { const v = Number(row.opening_qty || 0) + Number(row.in_qty || 0) - Number(row.closing_qty_actual || 0); return v > 0 ? (v * avgCost).toLocaleString('en-US', { minimumFractionDigits: 2 }) : ''; })()}
                            </td>}
                            {!isBranch && <td className="px-1.5 py-1 text-center font-mono border border-gray-300">{theoreticalVal.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>}
                            {!isBranch && <td className="px-1.5 py-1 text-center font-mono font-bold text-red-600 border border-gray-300">{diffQty}</td>}
                            {!isBranch && <td className="px-1.5 py-1 text-center font-mono font-bold text-red-700 border border-gray-300">{diffVal.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>}
                            {!isBranch && (
                              editMode ? (
                                <td className="px-1.5 py-1 text-center font-mono font-bold text-green-700 border border-gray-300">
                                  <button
                                    onClick={async () => {
                                      const vKey = `val_${row.item_id}`;
                                      let lines = cellOrdersCache[vKey];
                                      if (!lines) {
                                        try {
                                          const res = await api.get('/closing/monthly-orders', {
                                            params: { warehouse_id: warehouseId, item_id: row.item_id, month }
                                          });
                                          lines = res.data.lines || [];
                                          setCellOrdersCache(prev => ({ ...prev, [vKey]: lines }));
                                        } catch { lines = []; }
                                      }
                                      setPopoverTarget({
                                        warehouse_id: warehouseId, item_id: row.item_id, item_name: row.item_name,
                                        date: month, day: null, month, lines: lines || [],
                                        isValue: true, totalValue: row.purchasesValue || 0,
                                      });
                                    }}
                                    className="w-full text-center text-green-700 font-bold bg-transparent hover:bg-yellow-100 rounded cursor-pointer text-xs"
                                  >
                                    {row.purchasesValue.toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                  </button>
                                </td>
                              ) : (
                                <td className="px-1.5 py-1 text-center font-mono font-bold text-green-700 border border-gray-300">
                                  {row.purchasesValue.toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                </td>
                              )
                            )}
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                  {/* Summary section */}
                  {(() => {
                    // قيمة أول المدة
                    const totOpeningVal = mergedRows.reduce((s: number, r: any) => s + Number(r.opening_value || 0), 0);
                    // قيمة المشتريات
                    const totPurchasesVal = mergedRows.reduce((s: number, r: any) => s + Number(r.in_value || 0), 0);
                    // قيمة آخر المدة = Actual qty × avg cost
                    const totClosingVal = mergedRows.reduce((s: number, r: any) => {
                      const closingQty = Number(r.closing_qty_actual || 0);
                      const avgCost = Number(r.avg_cost || 0);
                      return s + closingQty * avgCost;
                    }, 0);
                    // قيمة المستلم الفعلي = (أول المدة + وارد - آخر المدة) × avg cost (إذا موجب)
                    const totReceivedVal = mergedRows.reduce((s: number, r: any) => {
                      const v = Number(r.opening_qty || 0) + Number(r.in_qty || 0) - (r.closing_qty_actual ?? 0);
                      return s + (v > 0 ? v * Number(r.avg_cost || 0) : 0);
                    }, 0);
                    const summaryItems = [
                      { label: 'قيمة أول المدة', value: totOpeningVal, color: 'border-r-amber-500 text-amber-700' },
                      { label: 'قيمة المشتريات', value: totPurchasesVal, color: 'border-r-sky-500 text-sky-700' },
                      { label: 'قيمة آخر المدة', value: totClosingVal, color: 'border-r-violet-500 text-violet-700' },
                      { label: 'قيمة المستلم الفعلي', value: totReceivedVal, color: 'border-r-emerald-500 text-emerald-700' },
                    ];
                    return (
                      <div className="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                        {summaryItems.map((item) => (
                          <div key={item.label} className={`bg-white border-r-4 ${item.color.split(' ')[0]} rounded-xl p-4 shadow-sm`}>
                            <div className="text-xs text-gray-600 font-medium">{item.label}</div>
                            <div className={`text-lg font-bold mt-1 ${item.color.split(' ').slice(1).join(' ')}`}>
                              {item.value.toLocaleString('en-US', { minimumFractionDigits: 2 })}
                            </div>
                          </div>
                        ))}
                      </div>
                    );
                  })()}
                </div>
              );
            })()
          )
        )}
      </div>

      {/* Popover — لتعديل الخلايا المتعددة الفواتير */}
      {popoverTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/20" onClick={() => setPopoverTarget(null)}>
          <div className="bg-white rounded-xl shadow-2xl border border-gray-200 p-4 min-w-[400px] max-w-[500px]" onClick={(e) => e.stopPropagation()} dir="rtl">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-bold text-gray-800">
                {popoverTarget.isValue ? 'تعديل قيمة المشتريات' : 'تعديل وارد اليوم'}
              </h3>
              <button onClick={() => setPopoverTarget(null)} className="text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</button>
            </div>
            <div className="text-xs text-gray-500 mb-3">
              {popoverTarget.item_name} — {popoverTarget.date}
            </div>

            <div className="space-y-2 mb-3">
              {popoverTarget.lines && popoverTarget.lines.length > 0 ? popoverTarget.lines.map((line: any, idx: number) => {
                const editKey = `${popoverTarget.item_id}_${popoverTarget.date}_${line.order_id}`;
                const pending = pendingEdits.find((e: any) => e._popoverKey === editKey);
                const currentVal = pending ? pending.newVal : (popoverTarget.isValue ? line.total_cost : line.qty);
                return (
                  <div key={line.order_id} className="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2">
                    <span className="text-xs text-gray-500 font-mono min-w-[80px]">{line.date || line.order_ref || '—'}</span>
                    <span className="text-xs text-gray-400">({line.type})</span>
                    <input
                      type="number"
                      step={popoverTarget.isValue ? "0.01" : "0.001"}
                      defaultValue={currentVal}
                      className="flex-1 border border-gray-200 rounded px-2 py-1 text-xs text-center font-mono outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-200"
                      onChange={(e) => {
                        const v = parseFloat(e.target.value) || 0;
                        setPendingEdits(prev => {
                          const others = prev.filter((ed: any) => ed._popoverKey !== editKey);
                          return [...others, {
                            _popoverKey: editKey,
                            warehouse_id: popoverTarget.warehouse_id,
                            item_id: popoverTarget.item_id,
                            day: popoverTarget.day,
                            date: popoverTarget.date,
                            month: popoverTarget.month,
                            order_id: line.order_id,
                            type: popoverTarget.isValue ? 'value' : 'qty',
                            newVal: v,
                            oldVal: popoverTarget.isValue ? line.total_cost : line.qty,
                          }];
                        });
                      }}
                    />
                  </div>
                );
              }) : (
                <div className="text-xs text-gray-400 text-center py-4">لا توجد فواتير مفصلة — سيتم توزيع التعديل مباشرة</div>
              )}
            </div>

            <div className="flex items-center justify-between border-t border-gray-100 pt-3">
              <div className="text-xs text-gray-600">
                الإجمالي: <span className="font-bold text-gray-800">
                  {popoverTarget.isValue
                    ? pendingEdits.filter((e: any) => e.item_id === popoverTarget.item_id && e.type === 'value').reduce((s: number, e: any) => s + e.newVal, 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
                    : pendingEdits.filter((e: any) => e.item_id === popoverTarget.item_id && e.day === popoverTarget.day && e.type === 'qty').reduce((s: number, e: any) => s + e.newVal, 0).toFixed(3)}
                </span>
              </div>
              <div className="flex gap-2">
                <button onClick={() => setPopoverTarget(null)} className="px-3 py-1.5 text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded-lg">إلغاء</button>
                <button onClick={() => { setPopoverTarget(null); }} className="px-3 py-1.5 text-xs bg-blue-600 text-white rounded-lg hover:bg-blue-700">حفظ</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
