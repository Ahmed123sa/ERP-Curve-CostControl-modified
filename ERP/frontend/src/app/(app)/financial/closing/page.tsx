'use client';
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { financialApi } from '@/lib/financial/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function FinancialClosingPage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());

  const { data, isLoading } = useQuery({
    queryKey: ['financial-closing', month, year],
    queryFn: () => financialApi.closingReports(month, year),
  });

  const reports = (data as any)?.reports || [];
  const report = reports[0];

  const generateMutation = useMutation({
    mutationFn: () => financialApi.generateClosingReport(month, year),
    onSuccess: () => {
      toast.success('تم توليد تقرير التقفيل');
      qc.invalidateQueries({ queryKey: ['financial-closing', month, year] });
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في التوليد'),
  });

  function getLineClass(line: any) {
    if (line.line_type === 'revenue') return 'bg-green-50 font-bold';
    if (line.line_type === 'profit') return 'font-bold text-lg';
    if (line.name.includes('إجمالي')) return 'bg-gray-50 font-medium';
    return '';
  }

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="التقفيل الشهري"
        subtitle="تقرير الأرباح والخسائر الشهري (P&L)"
        actions={
          <button onClick={() => generateMutation.mutate()} disabled={generateMutation.isPending}
            className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
            {generateMutation.isPending ? 'جاري التوليد...' : 'توليد التقفيل ⚙️'}
          </button>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        {/* Filters */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
          <div className="grid grid-cols-2 gap-4 items-end">
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">الشهر</label>
              <select value={month} onChange={(e) => setMonth(Number(e.target.value))}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                {Array.from({ length: 12 }, (_, i) => (
                  <option key={i + 1} value={i + 1}>{i + 1}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">السنة</label>
              <input type="number" value={year} onChange={(e) => setYear(Number(e.target.value))}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" />
            </div>
          </div>
        </div>

        {isLoading ? (
          <div className="text-center py-12 text-gray-400">جاري التحميل...</div>
        ) : !report ? (
          <div className="text-center py-16 bg-white border border-dashed border-gray-200 rounded-xl">
            <div className="text-5xl mb-4 text-gray-300">📑</div>
            <h3 className="text-lg font-bold text-gray-700 mb-2">لا يوجد تقرير تقفيل لهذا الشهر</h3>
            <p className="text-sm text-gray-500 mb-6">قم بتوليد التقرير من اليوميات المسجلة</p>
            <button onClick={() => generateMutation.mutate()}
              className="px-8 py-3 bg-blue-600 text-white rounded-xl text-sm hover:bg-blue-700">⚙️ توليد التقفيل</button>
          </div>
        ) : (
          <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
            {/* Report Header */}
            <div className="bg-gray-50 border-b border-gray-100 p-4 text-center">
              <h3 className="font-bold text-gray-900">تقرير التقفيل الشهري</h3>
              <p className="text-sm text-gray-500">الشهر {report.month} / {report.year}</p>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-2 md:grid-cols-5 gap-4 p-4 border-b border-gray-100 bg-gradient-to-br from-blue-50 to-white">
              <div className="text-center">
                <div className="text-xs text-gray-500">إجمالي المبيعات</div>
                <div className="text-lg font-bold text-green-700">{report.total_sales}</div>
              </div>
              <div className="text-center">
                <div className="text-xs text-gray-500">إجمالي المشتريات</div>
                <div className="text-lg font-bold text-orange-700">{report.total_purchases}</div>
              </div>
              <div className="text-center">
                <div className="text-xs text-gray-500">إجمالي المصروفات</div>
                <div className="text-lg font-bold text-red-700">{report.total_expenses}</div>
              </div>
              <div className="text-center">
                <div className="text-xs text-gray-500">صافي نقدية</div>
                <div className={`text-lg font-bold ${report.net_cash_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                  {report.net_cash_profit}
                </div>
              </div>
              <div className="text-center">
                <div className="text-xs text-gray-500">ربح صافي</div>
                <div className={`text-lg font-bold ${report.net_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                  {report.net_profit}
                </div>
              </div>
            </div>

            {/* Details Table */}
            <table className="w-full text-sm text-right">
              <thead>
                <tr className="bg-gray-50 text-gray-600 border-b border-gray-100">
                  <th className="px-6 py-3">البيان</th>
                  <th className="px-6 py-3">القيمة</th>
                  <th className="px-6 py-3">النسبة %</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {(report.details || []).map((line: any) => (
                  <tr key={line.id} className={`hover:bg-gray-50 ${getLineClass(line)}`}>
                    <td className="px-6 py-2.5">{line.name}</td>
                    <td className="px-6 py-2.5">{line.amount}</td>
                    <td className="px-6 py-2.5">{line.percentage ? line.percentage.toFixed(2) + '%' : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            {/* Key Metrics */}
            <div className="bg-gray-50 border-t border-gray-100 p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
              <div>
                <div className="text-xs text-gray-500">نسبة صافي نقدي</div>
                <div className={`text-lg font-bold ${report.percentages_json?.net_cash_percentage >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                  {report.percentages_json?.net_cash_percentage ? (report.percentages_json.net_cash_percentage).toFixed(2) + '%' : '—'}
                </div>
              </div>
              <div>
                <div className="text-xs text-gray-500">نسبة الربح الصافي</div>
                <div className={`text-lg font-bold ${report.percentages_json?.net_profit_percentage >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                  {report.percentages_json?.net_profit_percentage ? (report.percentages_json.net_profit_percentage).toFixed(2) + '%' : '—'}
                </div>
              </div>
              <div>
                <div className="text-xs text-gray-500">معدل المصروفات</div>
                <div className="font-bold text-orange-700">
                  {report.total_sales > 0 ? ((report.total_expenses / report.total_sales) * 100).toFixed(2) + '%' : '—'}
                </div>
              </div>
              <div>
                <div className="text-xs text-gray-500">معدل المشتريات</div>
                <div className="font-bold text-blue-700">
                  {report.total_sales > 0 ? ((report.total_purchases / report.total_sales) * 100).toFixed(2) + '%' : '—'}
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
