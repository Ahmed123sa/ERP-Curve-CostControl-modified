'use client';
import { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import { PageHeader } from '@/components/ui/AppShell';

export default function StockPage() {
  const [warehouseId, setWarehouseId] = useState('');
  const [search, setSearch] = useState('');
  const { currentClient } = useAuthStore();

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses', currentClient?.id],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const { data: stock = [], isLoading } = useQuery({
    queryKey: ['stock', warehouseId],
    queryFn: () => api.get('/stock/current', { params: { warehouse_id: warehouseId } }).then((r) => r.data),
    enabled: !!warehouseId,
  });

  const filtered = useMemo(() => {
    if (!search) return stock;
    const q = search.toLowerCase();
    return stock.filter((item: any) => item.name.toLowerCase().includes(q));
  }, [stock, search]);

  const totalValue = useMemo(() => {
    return filtered.reduce((sum: number, item: any) => sum + item.qty * (item.avg_cost || 0), 0);
  }, [filtered]);

  const selectedWarehouse = warehouses.find((w: any) => w.id === warehouseId);

  return (
    <>
      <PageHeader title="الرصيد الحالي" subtitle="كميات الأصناف المتوفرة في المخازن" />
      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        {/* Warehouse selector */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
          <div className="flex gap-4 items-end flex-wrap">
            <div className="min-w-48">
              <label className="text-[10px] text-gray-400 block mb-1 font-medium">اختر المخزن</label>
              <select
                value={warehouseId}
                onChange={(e) => { setWarehouseId(e.target.value); setSearch(''); }}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              >
                <option value="">كل المخازن</option>
                {warehouses.map((w: any) => <option key={w.id} value={w.id}>{w.name}</option>)}
              </select>
            </div>
            {warehouseId && (
              <div className="min-w-48 flex-1">
                <label className="text-[10px] text-gray-400 block mb-1 font-medium">بحث بالاسم</label>
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="ابحث عن صنف..."
                  className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                />
              </div>
            )}
          </div>
        </div>

        {warehouseId && !isLoading && filtered.length > 0 && (
          <div className="bg-gradient-to-l from-blue-50 to-indigo-50/50 border border-blue-100 rounded-xl p-4 shadow-sm">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-xs text-gray-500 font-medium">إجمالي القيمة التقديرية</div>
                <div className="text-xl font-bold text-blue-700 mt-1">
                  {totalValue.toLocaleString()} ج.م
                </div>
              </div>
              <div className="text-left">
                <div className="text-xs text-gray-400">عدد الأصناف</div>
                <div className="text-lg font-semibold text-gray-700 mt-1">{filtered.length}</div>
              </div>
              {selectedWarehouse && (
                <div className="text-left">
                  <div className="text-xs text-gray-400">المخزن</div>
                  <div className="text-lg font-semibold text-gray-700 mt-1">{selectedWarehouse.name}</div>
                </div>
              )}
            </div>
          </div>
        )}

        <div className="bg-white border border-gray-100 rounded-xl overflow-hidden shadow-sm">
          <table className="w-full text-sm text-right" dir="rtl">
            <thead>
              <tr className="text-gray-500 text-xs border-b border-gray-100 bg-gray-50/80">
                <th className="px-6 py-3.5 font-semibold">الصنف</th>
                <th className="px-6 py-3.5 font-semibold">الوحدة</th>
                <th className="px-6 py-3.5 font-semibold">الرصيد الحالي</th>
                <th className="px-6 py-3.5 font-semibold">القيمة التقديرية</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {!warehouseId ? (
                <tr><td colSpan={4} className="px-6 py-16 text-center text-gray-400">الرجاء اختيار مخزن لعرض الرصيد</td></tr>
              ) : isLoading ? (
                <tr><td colSpan={4} className="px-6 py-12 text-center text-gray-400">
                  <div className="flex items-center justify-center gap-2">
                    <svg className="animate-spin h-4 w-4 text-blue-500" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    جاري التحميل...
                  </div>
                </td></tr>
              ) : filtered.length === 0 ? (
                <tr><td colSpan={4} className="px-6 py-16 text-center text-gray-400">
                  {search ? 'لا توجد أصناف تطابق البحث' : 'لا يوجد رصيد حالياً في هذا المخزن'}
                </td></tr>
              ) : filtered.map((item: any) => (
                <tr key={item.id} className="hover:bg-blue-50/40 transition-colors">
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