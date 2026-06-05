'use client';
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

interface DiffRow {
  item_id: string;
  item_name: string;
  unit: string;
  opening_qty: number;
  in_qty: number;
  internal_out_qty: number;
  consumption_qty: number;
  closing_theoretical: number;
  closing_actual: number | null;
  diff_qty: number;
  avg_cost: number;
  diff_value: number;
}

export default function DiffsPage() {
  const qc = useQueryClient();
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [warehouseId, setWarehouseId] = useState('');
  const { currentClient } = useAuthStore();

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses', currentClient?.id],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const { data, isLoading } = useQuery({
    queryKey: ['diffs', month, warehouseId],
    queryFn: () => api.get('/reports/diffs', {
      params: { month, warehouse_id: warehouseId }
    }).then((r) => r.data),
    enabled: !!warehouseId,
  });

  const generateMutation = useMutation({
    mutationFn: () => api.post('/closing/generate', { month, warehouse_id: warehouseId }),
    onSuccess: () => {
      toast.success('تم توليد التقفيل بنجاح ✓');
      qc.invalidateQueries({ queryKey: ['diffs', month, warehouseId] });
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في توليد التقفيل'),
  });

  const rows = (data?.data ?? []) as DiffRow[];

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="الفروق والهدر"
        subtitle={data ? `المخزن: ${data.warehouse_name} — ${month}` : 'اختر المخزن والشهر'}
        actions={
          <div className="flex gap-2">
            <button
              onClick={() => generateMutation.mutate()}
              disabled={generateMutation.isPending || !warehouseId}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50 flex items-center gap-2"
            >
              <span>{generateMutation.isPending ? 'جاري التوليد...' : 'توليد التقفيل ⚙️'}</span>
            </button>
            <button
              onClick={() => {
                const params = new URLSearchParams({ month, warehouse_id: warehouseId });
                const url = `${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/reports/diffs/export?${params}`;
                const token = localStorage.getItem('erp_token');
                fetch(url, {
                  headers: { Authorization: `Bearer ${token}` },
                })
                  .then((res) => {
                    if (!res.ok) throw new Error('فشل التحميل');
                    return res.blob();
                  })
                  .then((blob) => {
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = `تقرير_خامات_مخزن_${month}.xlsx`;
                    link.click();
                    URL.revokeObjectURL(link.href);
                    toast.success('تم تصدير التقرير ✓');
                  })
                  .catch(() => toast.error('خطأ في تصدير التقرير'));
              }}
              disabled={!rows.length}
              className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-40 flex items-center gap-2"
            >
              📥 إكسيل
            </button>
          </div>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        {/* الفلاتر */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
          <div className="grid grid-cols-2 gap-4 items-end">
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">الشهر</label>
              <input
                type="month"
                value={month}
                onChange={(e) => setMonth(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">المخزن</label>
              <select
                value={warehouseId}
                onChange={(e) => setWarehouseId(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              >
                <option value="">-- اختر المخزن --</option>
                {warehouses.map((w: any) => (
                  <option key={w.id} value={w.id}>{w.name}</option>
                ))}
              </select>
            </div>
          </div>
        </div>

        {!warehouseId ? (
          <div className="text-center py-16 bg-white border border-dashed border-gray-200 rounded-xl">
            <div className="text-5xl mb-4 text-gray-300">📊</div>
            <h3 className="text-lg font-bold text-gray-700">اختر المخزن لعرض الفروق والهدر</h3>
          </div>
        ) : isLoading ? (
          <div className="text-center py-12 text-gray-400">جاري التحميل...</div>
        ) : !rows.length ? (
          <div className="text-center py-16 bg-white border border-dashed border-gray-200 rounded-xl">
            <div className="text-5xl mb-4 text-gray-300">📊</div>
            <h3 className="text-lg font-bold text-gray-700 mb-2">لا توجد بيانات للشهر {month}</h3>
            <p className="text-sm text-gray-500 mb-6">التقفيل الشهري لم يتم توليده بعد لهذا الشهر</p>
            <button
              onClick={() => generateMutation.mutate()}
              disabled={generateMutation.isPending}
              className="px-8 py-3 bg-blue-600 text-white rounded-xl text-sm font-bold hover:bg-blue-700 disabled:opacity-50 shadow-lg shadow-blue-200"
            >
              {generateMutation.isPending ? '⚙️ جاري توليد التقفيل...' : '⚙️ توليد التقفيل الشهري'}
            </button>
          </div>
        ) : (
          <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-200">
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-right">#</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-right">الصنف</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-center">الوحدة</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-center">أول المدة</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-center">وارد مخزن</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-center">منصرف فروع</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-center">آخر مدة</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-center">آخر فعلي</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-center">هوالك</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-center">الفرق</th>
                  <th className="px-3 py-2.5 font-medium text-gray-600 text-center">قيمة الفرق</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={r.item_id} className="border-b border-gray-100 hover:bg-gray-50/50">
                    <td className="px-3 py-2 text-gray-400 text-xs">{i + 1}</td>
                    <td className="px-3 py-2 font-medium text-gray-800">{r.item_name}</td>
                    <td className="px-3 py-2 text-center text-gray-500">{r.unit}</td>
                    <td className="px-3 py-2 text-center font-mono">{r.opening_qty || '—'}</td>
                    <td className="px-3 py-2 text-center font-mono">{r.in_qty || '—'}</td>
                    <td className="px-3 py-2 text-center font-mono">{r.internal_out_qty || '—'}</td>
                    <td className="px-3 py-2 text-center font-mono">{r.closing_theoretical}</td>
                    <td className="px-3 py-2 text-center font-mono">{r.closing_actual !== null ? r.closing_actual : '—'}</td>
                    <td className="px-3 py-2 text-center font-mono">{r.consumption_qty || '—'}</td>
                    <td className={`px-3 py-2 text-center font-mono font-bold ${r.diff_qty > 0 ? 'text-green-600' : r.diff_qty < 0 ? 'text-red-600' : ''}`}>
                      {r.diff_qty !== 0 ? r.diff_qty : '—'}
                    </td>
                    <td className={`px-3 py-2 text-center font-mono font-bold ${r.diff_value > 0 ? 'text-green-600' : r.diff_value < 0 ? 'text-red-600' : ''}`}>
                      {r.diff_value !== 0 ? r.diff_value.toLocaleString() : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}