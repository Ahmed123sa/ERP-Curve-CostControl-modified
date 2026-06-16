'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageContainer } from '@/components/ui/PageContainer';
import { TableSkeleton } from '@/components/ui/Skeleton';

export default function ClientStockPage() {
  const [warehouseId, setWarehouseId] = useState('');

  const { data: warehouses } = useQuery({
    queryKey: ['client-warehouses'],
    queryFn: () => api.get('/client/warehouses').then((r) => r.data),
  });

  const { data: stock, isLoading } = useQuery({
    queryKey: ['client-stock', warehouseId],
    queryFn: () => api.get('/client/stock/current', { params: { warehouse_id: warehouseId } }).then((r) => r.data),
    enabled: !!warehouseId,
  });

  return (
    <PageContainer className="flex-1 overflow-y-auto p-6">
      <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100 mb-4">الرصيد الحالي</h1>

      <select
        value={warehouseId}
        onChange={(e) => setWarehouseId(e.target.value)}
        className="w-full max-w-xs px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-xl text-sm bg-white dark:bg-gray-900 mb-4"
      >
        <option value="">اختر المخزن</option>
        {warehouses?.map((w: any) => <option key={w.id} value={w.id}>{w.name}</option>)}
      </select>

      {warehouseId && (
        <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
              <tr>
                <th className="text-right px-4 py-3">الصنف</th>
                <th className="text-right px-4 py-3">الوحدة</th>
                <th className="text-right px-4 py-3">الكمية</th>
                <th className="text-right px-4 py-3">متوسط السعر</th>
                <th className="text-right px-4 py-3">القيمة</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {isLoading ? (
                <tr><td colSpan={5} className="px-4 py-3"><TableSkeleton cols={5} rows={6} /></td></tr>
              ) : stock?.length === 0 ? (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">لا يوجد رصيد</td></tr>
              ) : (
                stock?.map((item: any) => (
                  <tr key={item.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td className="px-4 py-3 font-medium">{item.name}</td>
                    <td className="px-4 py-3 text-gray-500">{item.unit}</td>
                    <td className="px-4 py-3 font-mono">{item.qty}</td>
                    <td className="px-4 py-3 font-mono">{item.avg_cost?.toFixed(2)}</td>
                    <td className="px-4 py-3 font-mono">{(item.qty * item.avg_cost).toFixed(2)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}
    </PageContainer>
  );
}
