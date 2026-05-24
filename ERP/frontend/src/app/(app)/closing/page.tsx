'use client';

import { useState, Fragment } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function ClosingPage() {
  const qc = useQueryClient();
  const [viewType, setViewType] = useState<'matrix' | 'single'>('matrix');
  const [warehouseId, setWarehouseId] = useState('');
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));

  // 1. بيانات الـ Matrix
  const { data: matrixData, isLoading: matrixLoading } = useQuery({
    queryKey: ['grand-summary', month],
    queryFn: () => api.get('/reports/grand-summary', { params: { month } }).then(r => r.data),
    enabled: viewType === 'matrix',
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
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
                  onClick={() => downloadExport(`${api.defaults.baseURL}/closing/export?warehouse_id=${warehouseId}&month=${month}`, `تقفيل_${month}.xlsx`)}
                  className="px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700"
                >إكسيل</button>
                <button
                  onClick={() => downloadExport(`${api.defaults.baseURL}/closing/export-pdf?warehouse_id=${warehouseId}&month=${month}`, `تقفيل_${month}.pdf`)}
                  className="px-3 py-1.5 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700"
                >PDF</button>
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
                      <th key={loc.id} colSpan={2} className="p-1 border border-gray-700 text-center bg-orange-900/50">{loc.name} (فرع)</th>
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
                        <th className="p-1 border border-gray-600">استهلاك</th>
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
                          <td className="p-1 border-x border-gray-50 text-center text-gray-600">{d.consumption || '0'}</td>
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
                    <thead>
                      <tr className="bg-gray-100 text-gray-600">
                        <th className="px-1.5 py-1.5 text-start font-semibold sticky right-0 bg-gray-100 z-10 border border-gray-300">الصنف</th>
                        <th className="px-1.5 py-1.5 text-center font-semibold border border-gray-300">الوحدة</th>
                        <th className="px-1.5 py-1.5 text-center font-semibold text-gray-500 border border-gray-300">أول المدة</th>
                        {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((d) => (
                          <th key={d} className="px-1 py-1.5 text-center font-medium text-gray-400 min-w-[34px] border border-gray-300">{d}</th>
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
                            {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((d) => (
                              <td key={d} className="px-1 py-1 text-center font-mono text-xs text-green-700 border border-gray-300">
                                {row.daily.days?.[d] > 0 ? Number(row.daily.days[d]).toFixed(3) : ''}
                              </td>
                            ))}
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
                            {!isBranch && <td className="px-1.5 py-1 text-center font-mono font-bold text-green-700 border border-gray-300">{row.purchasesValue.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>}
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                  {/* Summary section */}
                  {(() => {
                    const totOpening = mergedRows.reduce((s: number, r: any) => s + Number(r.opening_qty || 0), 0);
                    const totInQty = mergedRows.reduce((s: number, r: any) => s + Number(r.in_qty || 0), 0);
                    const totClosingActual = mergedRows.reduce((s: number, r: any) => s + Number(r.closing_qty_actual || 0), 0);
                    const totBranchDispatch = mergedRows.reduce((s: number, r: any) => s + (Object.values(r.branch_dispatches || {}) as any[]).reduce((ss: number, d: any) => ss + Number(d.qty || 0), 0), 0);
                    const totReceivedQty = mergedRows.reduce((s: number, r: any) => {
                      const v = Number(r.opening_qty || 0) + Number(r.in_qty || 0) - (r.closing_qty_actual ?? 0);
                      return s + (v > 0 ? v : 0);
                    }, 0);
                    const totReceivedVal = mergedRows.reduce((s: number, r: any) => {
                      const v = Number(r.opening_qty || 0) + Number(r.in_qty || 0) - (r.closing_qty_actual ?? 0);
                      return s + (v > 0 ? v * Number(r.avg_cost || 0) : 0);
                    }, 0);
                    const summaryItems = [
                      ['إجمالي أول المدة', totOpening],
                      ['إجمالي المشتريات', totInQty],
                      ['إجمالي آخر المدة', totClosingActual],
                      ...(!isBranch ? [['إجمالي منصرف فروع', totBranchDispatch]] : []),
                      ['المستلم الفعلي (كمية)', totReceivedQty.toFixed(3)],
                      ['المستلم الفعلي (قيمة)', totReceivedVal.toLocaleString('en-US', { minimumFractionDigits: 2 })],
                    ];
                    return (
                      <div className="mt-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                        {summaryItems.map(([label, val]: [string, any]) => (
                          <div key={label} className="bg-blue-50 border border-blue-200 rounded-lg px-3 py-2">
                            <div className="text-xs text-blue-600 font-medium">{label}</div>
                            <div className="text-lg font-bold text-blue-900 mt-0.5">{val}</div>
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
    </div>
  );
}
