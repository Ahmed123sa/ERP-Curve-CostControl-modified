'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';

export default function ClientStockMovementPage() {
  const [search, setSearch] = useState('');
  const [itemId, setItemId] = useState('');

  const { data: items } = useQuery({
    queryKey: ['client-items'],
    queryFn: () => api.get('/items', { params: { is_active: true } }).then((r) => r.data),
  });

  const { data: movements, isLoading } = useQuery({
    queryKey: ['client-stock-movement', itemId],
    queryFn: () => api.get('/stock/movement', { params: { item_id: itemId } }).then((r) => r.data),
    enabled: !!itemId,
  });

  const filtered = items?.filter((i: any) => !search || i.name.includes(search));

  return (
    <div className="flex-1 overflow-y-auto p-6">
      <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100 mb-4">حركة الأصناف</h1>

      <div className="flex gap-2 mb-4">
        <input
          value={search}
          onChange={(e) => { setSearch(e.target.value); setItemId(''); }}
          placeholder="ابحث عن صنف..."
          className="flex-1 max-w-xs px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-xl text-sm bg-white dark:bg-gray-900"
        />
        <select
          value={itemId}
          onChange={(e) => setItemId(e.target.value)}
          className="flex-1 max-w-xs px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-xl text-sm bg-white dark:bg-gray-900"
        >
          <option value="">اختر من القائمة</option>
          {filtered?.map((i: any) => <option key={i.id} value={i.id}>{i.name}</option>)}
        </select>
      </div>

      {itemId && (
        <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
              <tr>
                <th className="text-right px-4 py-3">التاريخ</th>
                <th className="text-right px-4 py-3">النوع</th>
                <th className="text-right px-4 py-3">الكمية</th>
                <th className="text-right px-4 py-3">السعر</th>
                <th className="text-right px-4 py-3">الإجمالي</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {isLoading ? (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">جاري التحميل...</td></tr>
              ) : movements?.length === 0 ? (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">لا توجد حركات</td></tr>
              ) : (
                movements?.map((m: any, i: number) => (
                  <tr key={i} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td className="px-4 py-3">{m.date}</td>
                    <td className="px-4 py-3">{m.voucher_type === 'purchase' ? 'وارد' : m.voucher_type === 'dispatch' ? 'صرف' : m.voucher_type}</td>
                    <td className={`px-4 py-3 font-mono ${m.movement_type === 'in' ? 'text-green-600' : 'text-red-600'}`}>
                      {m.movement_type === 'in' ? '+' : '-'}{m.qty}
                    </td>
                    <td className="px-4 py-3 font-mono">{m.unit_cost?.toFixed(2)}</td>
                    <td className="px-4 py-3 font-mono">{m.total_cost?.toFixed(2)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
