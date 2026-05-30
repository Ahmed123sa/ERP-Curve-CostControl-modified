'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import { PageHeader } from '@/components/ui/AppShell';

export default function StockMovementPage() {
  const [warehouseId, setWarehouseId] = useState('');
  const [itemId, setItemId] = useState('');
  const { currentClient } = useAuthStore();

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const { data: items = [] } = useQuery({
    queryKey: ['items', currentClient?.id],
    queryFn: () => api.get('/items').then((r) => r.data),
  });

  const { data: movements = [], isLoading } = useQuery({
    queryKey: ['stock-movement', warehouseId, itemId],
    queryFn: () => api.get('/stock/movement', { params: { warehouse_id: warehouseId, item_id: itemId } }).then((r) => r.data),
    enabled: !!warehouseId && !!itemId,
  });

  const typeMap: Record<string, string> = {
    'in': 'وارد',
    'out': 'منصرف',
    'transfer_in': 'تحويل وارد',
    'transfer_out': 'تحويل صادر',
  };

  return (
    <>
      <PageHeader title="حركة الأصناف" subtitle="تتبع حركة صنف معين في مخزن محدد" />
      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        <div className="bg-white border border-gray-100 rounded-xl p-4 flex gap-4 items-center shadow-sm">
          <div className="flex flex-col gap-1 flex-1">
            <label className="text-xs font-medium text-gray-400">المخزن</label>
            <select
              value={warehouseId}
              onChange={(e) => setWarehouseId(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
            >
              <option value="">اختر المخزن...</option>
              {warehouses.map((w: any) => <option key={w.id} value={w.id}>{w.name}</option>)}
            </select>
          </div>
          <div className="flex flex-col gap-1 flex-1">
            <label className="text-xs font-medium text-gray-400">الصنف</label>
            <select
              value={itemId}
              onChange={(e) => setItemId(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
            >
              <option value="">اختر الصنف...</option>
              {items.map((i: any) => <option key={i.id} value={i.id}>{i.name}</option>)}
            </select>
          </div>
        </div>

        <div className="bg-white border border-gray-100 rounded-xl overflow-hidden shadow-sm">
          <table className="w-full text-sm text-right" dir="rtl">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr className="text-gray-500 text-[11px] uppercase">
                <th className="px-6 py-3 font-semibold">التاريخ</th>
                <th className="px-6 py-3 font-semibold">نوع الحركة</th>
                <th className="px-6 py-3 font-semibold text-green-600">وارد (+)</th>
                <th className="px-6 py-3 font-semibold text-red-600">صادر (-)</th>
                <th className="px-6 py-3 font-semibold">الرصيد</th>
                <th className="px-6 py-3 font-semibold">المرجع</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {!warehouseId || !itemId ? (
                <tr><td colSpan={6} className="px-6 py-12 text-center text-gray-400">الرجاء اختيار المخزن والصنف لعرض التقرير</td></tr>
              ) : isLoading ? (
                <tr><td colSpan={6} className="px-6 py-12 text-center text-gray-400">جاري التحميل...</td></tr>
              ) : movements.length === 0 ? (
                <tr><td colSpan={6} className="px-6 py-12 text-center text-gray-400">لا توجد حركات مسجلة لهذا الصنف</td></tr>
              ) : movements.map((m: any, idx: number) => (
                <tr key={idx} className="hover:bg-gray-50 transition-colors">
                  <td className="px-6 py-4 text-gray-600">{m.date}</td>
                  <td className="px-6 py-4 font-medium">{typeMap[m.movement_type] || m.movement_type}</td>
                  <td className="px-6 py-4 text-green-600 font-bold">{m.qty > 0 ? m.qty : '-'}</td>
                  <td className="px-6 py-4 text-red-600 font-bold">{m.qty < 0 ? Math.abs(m.qty) : '-'}</td>
                  <td className="px-6 py-4 font-bold text-gray-900">{m.running_balance}</td>
                  <td className="px-6 py-4 text-xs text-gray-400">{m.ref_type} #{m.ref_id?.slice(0, 8)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
