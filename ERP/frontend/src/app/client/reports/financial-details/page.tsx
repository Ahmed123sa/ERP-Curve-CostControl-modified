'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { ExportButtons } from '../export-buttons';

export default function ClientFinancialDetailsPage() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));

  const { data: summary, isLoading } = useQuery({
    queryKey: ['client-warehouse-summary', month],
    queryFn: () => api.get('/client/stock/warehouse-summary', { params: { month } }).then((r) => r.data),
  });

  return (
    <div className="flex-1 overflow-y-auto p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100">التفاصيل المالية</h1>
        <div className="flex items-center gap-2">
          <input type="month" value={month} onChange={(e) => setMonth(e.target.value)}
            className="px-3 py-1.5 border border-gray-200 dark:border-gray-700 rounded-xl text-sm bg-white dark:bg-gray-900" />
          <ExportButtons baseUrl="/api/client/reports/financial" params={{ month }} disabled={!summary?.length} />
        </div>
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
            <tr>
              <th className="text-right px-4 py-3">المخزن</th>
              <th className="text-right px-4 py-3">النوع</th>
              <th className="text-right px-4 py-3">أول المدة</th>
              <th className="text-right px-4 py-3">المشتريات</th>
              <th className="text-right px-4 py-3">الفروق</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {isLoading ? (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">جاري التحميل...</td></tr>
            ) : !summary?.length ? (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">لا توجد بيانات</td></tr>
            ) : (
              summary?.map((w: any, i: number) => (
                <tr key={i} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                  <td className="px-4 py-3 font-medium">{w.name}</td>
                  <td className="px-4 py-3">{w.type === 'main' ? 'رئيسي' : w.type === 'sub' ? 'فرعي' : w.type === 'branch' ? 'فرع' : w.type}</td>
                  <td className="px-4 py-3 font-mono">{typeof w.opening === 'number' ? w.opening.toFixed(2) : w.opening}</td>
                  <td className="px-4 py-3 font-mono">{typeof w.purchases === 'number' ? w.purchases.toFixed(2) : w.purchases}</td>
                  <td className={`px-4 py-3 font-mono ${w.diff < 0 ? 'text-red-600' : 'text-green-600'}`}>{typeof w.diff === 'number' ? w.diff.toFixed(2) : w.diff}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
