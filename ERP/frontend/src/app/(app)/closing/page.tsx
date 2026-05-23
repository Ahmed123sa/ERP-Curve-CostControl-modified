'use client';

import { useState, Fragment } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function ClosingPage() {
  const qc = useQueryClient();
  const [viewType, setViewType] = useState<'matrix' | 'single'>('matrix');
  const [singleTab, setSingleTab] = useState<'closing' | 'daily'>('closing');
  const [warehouseId, setWarehouseId] = useState('');
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));

  // 1. بيانات الـ Matrix
  const { data: matrixData, isLoading: matrixLoading } = useQuery({
    queryKey: ['grand-summary', month],
    queryFn: () => api.get('/reports/grand-summary', { params: { month } }).then(r => r.data),
    enabled: viewType === 'matrix',
  });

  // 2. بيانات الموقع الواحد
  const { data: singleData = [], isLoading: singleLoading } = useQuery({
    queryKey: ['closing', warehouseId, month],
    queryFn: () => api.get('/closing', { params: { warehouse_id: warehouseId, month } }).then((r) => r.data.data),
    enabled: viewType === 'single' && !!warehouseId && singleTab === 'closing',
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  // 3. بيانات اليوميات للموقع الواحد (بعد warehouses عشان isBranch)
  const isBranch = (warehouses as any[]).find((w: any) => w.id === warehouseId)?.type === 'branch';
  const { data: dailyData, isLoading: dailyLoading } = useQuery({
    queryKey: ['daily', isBranch ? 'branch' : 'warehouse', warehouseId, month],
    queryFn: () => api.get(isBranch ? '/reports/branch-daily' : '/reports/warehouse-daily', {
      params: { [isBranch ? 'branch_id' : 'warehouse_id']: warehouseId, month },
    }).then((r) => r.data),
    enabled: viewType === 'single' && !!warehouseId && singleTab === 'daily',
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
             {warehouseId && (
               <div className="flex gap-1 bg-gray-100 rounded-lg p-0.5">
                 <button onClick={() => setSingleTab('closing')}
                   className={`px-3 py-1.5 text-xs rounded-md font-medium transition ${singleTab === 'closing' ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'}`}>
                   التقفيل
                 </button>
                 <button onClick={() => setSingleTab('daily')}
                   className={`px-3 py-1.5 text-xs rounded-md font-medium transition ${singleTab === 'daily' ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'}`}>
                   اليوميات
                 </button>
               </div>
             )}
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
        ) : singleTab === 'closing' ? (
          singleLoading ? <div className="p-12 text-center text-gray-400">جاري التحميل...</div> : (
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
              <table className="w-full text-sm text-right" dir="rtl">
                <thead className="bg-gray-50 border-b border-gray-100">
                  <tr className="text-gray-500 text-[10px]">
                    <th className="px-3 py-3 font-semibold">الصنف</th>
                    <th className="px-3 py-3 font-semibold">أول المدة (كمية)</th>
                    <th className="px-3 py-3 font-semibold">قيمة أول المدة</th>
                    <th className="px-3 py-3 font-semibold">وارد (كمية)</th>
                    <th className="px-3 py-3 font-semibold">قيمة الوارد</th>
                    <th className="px-3 py-3 font-semibold">المنصرف</th>
                    <th className="px-3 py-3 font-semibold">متوسط السعر</th>
                    {!isBranchSelected && <th className="px-3 py-3 font-semibold">نظري آخر</th>}
                    <th className="px-3 py-3 font-semibold">آخر المدة (فعلي)</th>
                    {!isBranchSelected && <th className="px-3 py-3 font-semibold">الفرق</th>}
                    {!isBranchSelected && <th className="px-3 py-3 font-semibold">قيمة الفرق</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {singleData.map((row: any) => (
                    <tr key={row.id}>
                      <td className="px-3 py-3 font-bold">{row.item_name}</td>
                      <td className="px-3 py-3">{row.opening_qty}</td>
                      <td className="px-3 py-3">{Number(row.opening_value ?? 0).toLocaleString('en-US')}</td>
                      <td className="px-3 py-3 text-green-600">{row.in_qty}</td>
                      <td className="px-3 py-3 text-green-700">{Number(row.in_value ?? 0).toLocaleString('en-US')}</td>
                      <td className="px-3 py-3 text-red-600">{row.out_qty}</td>
                      <td className="px-3 py-3 font-medium">{Number(row.avg_cost ?? 0).toFixed(2)}</td>
                      {!isBranchSelected && <td className="px-3 py-3 font-bold">{row.closing_qty_theoretical}</td>}
                      <td className="px-3 py-3">{row.closing_qty_actual ? Number(row.closing_qty_actual * row.avg_cost).toLocaleString('en-US') : '—'}</td>
                      {!isBranchSelected && <td className="px-3 py-3 font-bold text-red-600">{row.diff_qty}</td>}
                      {!isBranchSelected && <td className="px-3 py-3 font-bold text-red-700">{Number(row.diff_value ?? 0).toLocaleString('en-US')}</td>}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        ) : (
          /* اليوميات */
          dailyLoading ? <div className="p-12 text-center text-gray-400">جاري التحميل...</div> : (
            !dailyData || !dailyData.items || dailyData.items.filter((i: any) => i.total > 0).length === 0 ? (
              <div className="p-12 text-center text-gray-400">لا توجد واردات في هذا الشهر</div>
            ) : (
              <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-auto">
                <table className="w-full text-sm whitespace-nowrap">
                  <thead>
                    <tr className="bg-gray-50 text-xs text-gray-500 border-b">
                      <th className="px-2 py-1.5 text-start font-medium sticky right-0 bg-gray-50 z-10">الصنف</th>
                      {Array.from({ length: dailyData.days_in_month }, (_, i) => i + 1).map((d: number) => (
                        <th key={d} className="px-2 py-1.5 text-center font-medium text-gray-400 min-w-[40px]">{d}</th>
                      ))}
                      <th className="px-2 py-1.5 text-center font-medium text-gray-700 min-w-[60px]">الإجمالي</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {dailyData.items.filter((i: any) => i.total > 0).map((item: any) => (
                      <tr key={item.item_id} className="hover:bg-gray-50/30">
                        <td className="px-2 py-1 text-start font-medium text-gray-800 sticky right-0 bg-white z-10">
                          {item.item_name} <span className="text-xs text-gray-400">({item.unit})</span>
                        </td>
                        {Array.from({ length: dailyData.days_in_month }, (_, i) => i + 1).map((d: number) => (
                          <td key={d} className="px-2 py-1 text-center font-mono text-xs">
                            {item.days[d] > 0 ? item.days[d].toFixed(3) : ''}
                          </td>
                        ))}
                        <td className="px-2 py-1 text-center font-mono font-bold text-sm">{item.total.toFixed(3)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )
          )
        )}
      </div>
    </div>
  );
}
