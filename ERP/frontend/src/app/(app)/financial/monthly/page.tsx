'use client';
import { useState, useMemo } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { financialApi } from '@/lib/financial/api';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function FinancialMonthlyPage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.toISOString().slice(0, 7));
  const [loading, setLoading] = useState(false);

  const { data: summaries, isLoading } = useQuery({
    queryKey: ['financial-summaries', month],
    queryFn: () => financialApi.monthlySummaries(month),
  });

  const { data: catList = [] } = useQuery({
    queryKey: ['financial-categories'],
    queryFn: () => financialApi.categories(),
  });

  const { data: rawEntries } = useQuery({
    queryKey: ['financial-daily', month],
    queryFn: () => financialApi.dailyEntries(month),
  });

  // Build matrix: days (1-31) x categories
  const daysInMonth = new Date(parseInt(month.slice(0, 4)), parseInt(month.slice(5, 7)), 0).getDate();
  const entries: any[] = (rawEntries as any)?.entries || [];

  const summary = useMemo(() => {
    const cats = catList;
    const dayArr = Array.from({ length: daysInMonth }, (_, i) => i + 1);

    // dayRow: { day, sales, [cat_id]: amount, totalExpenses, net }
    const rows = dayArr.map((day) => {
      const dateStr = `${month}-${String(day).padStart(2, '0')}`;
      const entry = entries.find((e: any) => e.date.slice(0, 10) === dateStr);
      const details = entry?.details || [];
      const catMap: Record<string, number> = {};
      let totalExpenses = 0;
      for (const d of details) {
        const amt = parseFloat(d.amount) || 0;
        catMap[d.expense_category_id] = (catMap[d.expense_category_id] || 0) + amt;
        totalExpenses += amt;
      }
      return {
        day,
        sales: entry ? parseFloat(entry.total_sales) || 0 : 0,
        catMap,
        totalExpenses,
        net: entry ? (parseFloat(entry.total_sales) || 0) - totalExpenses : 0,
        hasEntry: !!entry,
      };
    });

    // Totals row
    const totals: Record<string, number> = { sales: 0, expenses: 0, net: 0, purchases: 0 };
    for (const r of rows) {
      totals.sales += r.sales;
      totals.expenses += r.totalExpenses;
    }
    totals.net = totals.sales - totals.expenses;

    const catTotals: Record<string, number> = {};
    for (const r of rows) {
      for (const [cid, amt] of Object.entries(r.catMap)) {
        catTotals[cid] = (catTotals[cid] || 0) + amt;
      }
    }

    // إجمالي مشتريات = مجموع الكاتيجوريز اللي is_purchase = true
    const purchaseCatIds = cats.filter((c: any) => c.is_purchase).map((c: any) => c.id);
    totals.purchases = purchaseCatIds.reduce((sum, cid) => sum + (catTotals[cid] || 0), 0);

    return { rows, totals, catTotals };
  }, [entries, catList, daysInMonth, month]);

  function handleRecalculate() {
    setLoading(true);
    const [y, m] = month.split('-').map(Number);
    api.post(`/financial/monthly-summaries/generate`, { month: m, year: y })
      .then(() => { qc.invalidateQueries({ queryKey: ['financial-summaries', month] }); toast.success('تم تحديث الملخص'); })
      .catch((e) => toast.error(e?.response?.data?.message || 'خطأ'))
      .finally(() => setLoading(false));
  }

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="ملخص الشهر — (مجمع)"
        subtitle="جدول تفصيلي للمصروفات والمبيعات لكل أيام الشهر"
        actions={
          <button onClick={handleRecalculate} disabled={loading}
            className="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 shadow-sm font-medium">
            {loading ? '...جاري' : 'تحديث الملخص'}
          </button>
        }
      />

      <div className="flex-1 overflow-auto p-6">
        {/* Month Selector */}
        <div className="flex items-center gap-3 mb-4">
          <label className="text-sm font-medium text-gray-600">اختر الشهر:</label>
          <input type="month" value={month} onChange={(e) => setMonth(e.target.value)}
            className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" />
        </div>

        {/* Matrix Table */}
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-x-auto">
          <table className="w-full text-xs text-right">
            <thead>
              <tr>
                <th rowSpan={2} className="sticky right-0 bg-white z-10 px-3 py-2 border-b border-l border-gray-200 text-gray-600 font-bold min-w-[50px]">اليوم</th>
                <th rowSpan={2} className="px-3 py-2 border-b border-l border-gray-200 text-green-700 font-bold bg-green-50/50 min-w-[80px]">المبيعات</th>
                {catList.map((c: any) => (
                  <th key={c.id} rowSpan={2} className="px-3 py-2 border-b border-l border-gray-200 text-gray-600 font-medium bg-gray-50/50 min-w-[90px]">{c.name}</th>
                ))}
                <th rowSpan={2} className="px-3 py-2 border-b border-l border-gray-200 text-red-700 font-bold bg-red-50/50 min-w-[80px]">إجمالي المصروفات</th>
                <th rowSpan={2} className="px-3 py-2 border-b border-gray-200 font-bold min-w-[80px]">صافي</th>
              </tr>
            </thead>
            <tbody>
              {summary.rows.map((r) => (
                <tr key={r.day} className={`${r.hasEntry ? 'bg-white' : 'bg-gray-50/50'} hover:bg-blue-50/30 transition-colors`}>
                  <td className={`sticky right-0 bg-inherit z-10 px-3 py-1.5 border-b border-l border-gray-100 text-center font-bold ${r.hasEntry ? 'text-gray-800' : 'text-gray-300'}`}>
                    {r.day}
                  </td>
                  <td className="px-3 py-1.5 border-b border-l border-gray-100 text-left text-green-700 font-medium">
                    {r.hasEntry ? r.sales.toFixed(2) : '—'}
                  </td>
                  {catList.map((c: any) => (
                    <td key={c.id} className="px-3 py-1.5 border-b border-l border-gray-100 text-left text-gray-700">
                      {r.hasEntry ? (r.catMap[c.id]?.toFixed(2) || '0.00') : '—'}
                    </td>
                  ))}
                  <td className="px-3 py-1.5 border-b border-l border-gray-100 text-left text-red-600 font-medium">
                    {r.hasEntry ? r.totalExpenses.toFixed(2) : '—'}
                  </td>
                  <td className={`px-3 py-1.5 border-b border-gray-100 text-left font-bold ${r.hasEntry ? (r.net >= 0 ? 'text-green-600' : 'text-red-600') : 'text-gray-300'}`}>
                    {r.hasEntry ? r.net.toFixed(2) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="bg-gray-100 border-t-2 border-gray-300 font-bold sticky bottom-0">
                <td className="sticky right-0 bg-gray-100 z-10 px-3 py-2 border-t border-l border-gray-300 text-center">الإجمالي</td>
                <td className="px-3 py-2 border-t border-l border-gray-300 text-left text-green-700">
                  {summary.totals.sales.toFixed(2)}
                </td>
                {catList.map((c: any) => (
                  <td key={c.id} className="px-3 py-2 border-t border-l border-gray-300 text-left text-gray-800">
                    {summary.catTotals[c.id]?.toFixed(2) || '0.00'}
                  </td>
                ))}
                <td className="px-3 py-2 border-t border-l border-gray-300 text-left text-red-700">
                  {summary.totals.expenses.toFixed(2)}
                </td>
                <td className={`px-3 py-2 border-t border-gray-300 text-left ${summary.totals.net >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                  {summary.totals.net.toFixed(2)}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        {/* Summary Cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
          <div className="bg-white border-r-4 border-green-500 rounded-xl p-4 shadow-sm">
            <div className="text-xs text-gray-500 font-medium tracking-wide uppercase">إجمالي المبيعات</div>
            <div className="text-xl font-bold text-green-700 mt-1">{summary.totals.sales.toFixed(2)}</div>
          </div>
          <div className="bg-white border-r-4 border-sky-500 rounded-xl p-4 shadow-sm">
            <div className="text-xs text-gray-500 font-medium tracking-wide uppercase">إجمالي المشتريات</div>
            <div className="text-xl font-bold text-sky-700 mt-1">{summary.totals.purchases.toFixed(2)}</div>
          </div>
          <div className="bg-white border-r-4 border-red-400 rounded-xl p-4 shadow-sm">
            <div className="text-xs text-gray-500 font-medium tracking-wide uppercase">إجمالي المصروفات</div>
            <div className="text-xl font-bold text-red-600 mt-1">{summary.totals.expenses.toFixed(2)}</div>
          </div>
          <div className={`bg-white border-r-4 rounded-xl p-4 shadow-sm ${summary.totals.net >= 0 ? 'border-blue-500' : 'border-orange-500'}`}>
            <div className={`text-xs font-medium tracking-wide uppercase ${summary.totals.net >= 0 ? 'text-blue-600' : 'text-orange-600'}`}>صافي الشهر</div>
            <div className={`text-xl font-bold mt-1 ${summary.totals.net >= 0 ? 'text-blue-700' : 'text-orange-700'}`}>{summary.totals.net.toFixed(2)}</div>
          </div>
        </div>
      </div>
    </div>
  );
}
