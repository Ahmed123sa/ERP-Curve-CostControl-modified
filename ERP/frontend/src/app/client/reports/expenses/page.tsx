'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { ExportButtons } from '../export-buttons';

export default function ClientExpensesPage() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));

  const { data, isLoading } = useQuery({
    queryKey: ['client-report-expenses', month],
    queryFn: () => api.get('/client/reports/expenses', { params: { month } }).then((r) => r.data),
  });

  const summary = data?.summary;

  return (
    <div className="flex-1 overflow-y-auto p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100">تقرير المصروفات</h1>
        <div className="flex items-center gap-2">
          <input type="month" value={month} onChange={(e) => setMonth(e.target.value)}
            className="px-3 py-1.5 border border-gray-200 dark:border-gray-700 rounded-xl text-sm bg-white dark:bg-gray-900" />
          <ExportButtons baseUrl="/api/client/reports/expenses" params={{ month }} disabled={!data?.daily?.length} />
        </div>
      </div>

      {summary && (
        <div className="grid grid-cols-3 gap-4 mb-4">
          <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-4 text-center">
            <div className="text-xs text-gray-500 mb-1">إجمالي المبيعات</div>
            <div className="text-lg font-bold text-emerald-600">{summary.total_sales.toFixed(2)}</div>
          </div>
          <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-4 text-center">
            <div className="text-xs text-gray-500 mb-1">إجمالي المصروفات</div>
            <div className="text-lg font-bold text-red-600">{summary.total_expenses.toFixed(2)}</div>
          </div>
          <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-4 text-center">
            <div className="text-xs text-gray-500 mb-1">صافي الربح</div>
            <div className={`text-lg font-bold ${summary.net_total >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{summary.net_total.toFixed(2)}</div>
          </div>
        </div>
      )}

      <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
            <tr>
              <th className="text-right px-4 py-3">التاريخ</th>
              <th className="text-right px-4 py-3">المبيعات</th>
              <th className="text-right px-4 py-3">المصروفات</th>
              <th className="text-right px-4 py-3">الصافي</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {isLoading ? (
              <tr><td colSpan={4} className="px-4 py-8 text-center text-gray-400">جاري التحميل...</td></tr>
            ) : !data?.daily?.length ? (
              <tr><td colSpan={4} className="px-4 py-8 text-center text-gray-400">لا توجد بيانات لهذا الشهر</td></tr>
            ) : (
              data.daily.map((r: any, i: number) => (
                <tr key={i} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                  <td className="px-4 py-3">{r.date}</td>
                  <td className="px-4 py-3 font-mono text-emerald-600">{r.total_sales.toFixed(2)}</td>
                  <td className="px-4 py-3 font-mono text-red-600">{r.total_expenses.toFixed(2)}</td>
                  <td className={`px-4 py-3 font-mono ${r.net_daily >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{r.net_daily.toFixed(2)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
