'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';

export default function StockPage() {
  const [warehouseId, setWarehouseId] = useState('');

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const { data: stock = [], isLoading } = useQuery({
    queryKey: ['stock', warehouseId],
    queryFn: () => api.get('/stock/current', { params: { warehouse_id: warehouseId } }).then((r) => r.data),
    enabled: !!warehouseId,
  });

  return (
    <>
      <PageHeader title="الرصيد الحالي" subtitle="كميات الأصناف المتوفرة في المخازن" />
      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        <div className="bg-white border border-gray-100 rounded-xl p-4 flex gap-4 items-center">
          <label className="text-sm font-medium text-gray-600">اختر المخزن:</label>
          <select
            value={warehouseId}
            onChange={(e) => setWarehouseId(e.target.value)}
            className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
          >
            <option value="">اختر المخزن...</option>
            {warehouses.map((w: any) => <option key={w.id} value={w.id}>{w.name}</option>)}
          </select>
        </div>

        <div className="bg-white border border-gray-100 rounded-xl overflow-hidden">
          <table className="w-full text-sm text-right" dir="rtl">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr className="text-gray-500 text-[11px] uppercase">
                <th className="px-6 py-3 font-semibold">الصنف</th>
                <th className="px-6 py-3 font-semibold">الوحدة</th>
                <th className="px-6 py-3 font-semibold">الرصيد الحالي</th>
                <th className="px-6 py-3 font-semibold">القيمة التقديرية</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {!warehouseId ? (
                <tr><td colSpan={4} className="px-6 py-12 text-center text-gray-400">الرجاء اختيار مخزن لعرض الرصيد</td></tr>
              ) : isLoading ? (
                <tr><td colSpan={4} className="px-6 py-12 text-center text-gray-400">جاري التحميل...</td></tr>
              ) : stock.length === 0 ? (
                <tr><td colSpan={4} className="px-6 py-12 text-center text-gray-400">لا يوجد رصيد حالياً في هذا المخزن</td></tr>
              ) : stock.map((item: any) => (
                <tr key={item.id} className="hover:bg-gray-50 transition-colors">
                  <td className="px-6 py-4 font-medium text-gray-900">{item.name}</td>
                  <td className="px-6 py-4 text-gray-500">{item.unit}</td>
                  <td className={`px-6 py-4 font-bold ${item.qty < 0 ? 'text-red-600' : 'text-blue-600'}`}>
                    {item.qty}
                  </td>
                  <td className="px-6 py-4 text-gray-600">
                    {(item.qty * (item.avg_cost || 0)).toLocaleString()} ج.م
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
