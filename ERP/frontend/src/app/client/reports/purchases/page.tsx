'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageContainer } from '@/components/ui/PageContainer';
import { TableSkeleton } from '@/components/ui/Skeleton';
import { ExportButtons } from '../export-buttons';

export default function ClientPurchasesPage() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));

  const { data: items, isLoading } = useQuery({
    queryKey: ['client-report-purchases', month],
    queryFn: () => api.get('/client/reports/purchases', { params: { month } }).then((r) => r.data),
  });

  return (
    <PageContainer className="flex-1 overflow-y-auto p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100">تقرير المشتريات</h1>
        <div className="flex items-center gap-2">
          <input type="month" value={month} onChange={(e) => setMonth(e.target.value)}
            className="px-3 py-1.5 border border-gray-200 dark:border-gray-700 rounded-xl text-sm bg-white dark:bg-gray-900" />
          <ExportButtons baseUrl="/api/client/reports/purchases" params={{ month }} disabled={!items?.length} />
        </div>
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
            <tr>
              <th className="text-right px-4 py-3">#</th>
              <th className="text-right px-4 py-3">الصنف</th>
              <th className="text-right px-4 py-3">الوحدة</th>
              <th className="text-right px-4 py-3">الكمية</th>
              <th className="text-right px-4 py-3">الإجمالي</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {isLoading ? (
              <tr><td colSpan={5} className="px-4 py-3"><TableSkeleton cols={5} rows={6} /></td></tr>
            ) : !items?.length ? (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">لا توجد مشتريات هذا الشهر</td></tr>
            ) : (
              items.map((r: any, i: number) => (
                <tr key={i} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                  <td className="px-4 py-3 text-gray-400">{i + 1}</td>
                  <td className="px-4 py-3 font-medium">{r.item_name}</td>
                  <td className="px-4 py-3 text-gray-500">{r.unit}</td>
                  <td className="px-4 py-3 font-mono">{r.total_qty}</td>
                  <td className="px-4 py-3 font-mono">{r.total_value.toFixed(2)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </PageContainer>
  );
}
