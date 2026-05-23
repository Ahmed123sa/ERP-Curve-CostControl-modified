'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';

export default function BranchDailyPage() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [selectedBranch, setSelectedBranch] = useState<string>('');

  const { data: branches = [] } = useQuery({
    queryKey: ['branches'],
    queryFn: () => api.get('/warehouses').then((r) =>
      r.data.filter((w: any) => w.type === 'branch')
    ),
  });

  const { data, isLoading } = useQuery({
    queryKey: ['branch-daily', month, selectedBranch],
    queryFn: () => api.get('/reports/branch-daily', {
      params: { month, branch_id: selectedBranch || undefined },
    }).then((r) => r.data),
    enabled: !!selectedBranch,
  });

  const report = data as {
    month: string; days_in_month: number; branch_name: string;
    items: { item_id: string; item_name: string; unit: string; days: number[]; total: number }[];
  } | undefined;

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader title="اليوميات — وارد الفروع" />

      <div className="flex gap-3 p-4 items-end">
        <div>
          <label className="block text-xs text-gray-500 mb-1">الشهر</label>
          <input type="month" value={month} onChange={(e) => setMonth(e.target.value)}
            className="border rounded-lg px-3 py-2 text-sm w-40" />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">الفرع</label>
          <select value={selectedBranch} onChange={(e) => setSelectedBranch(e.target.value)}
            className="border rounded-lg px-3 py-2 text-sm w-52">
            <option value="">اختر الفرع</option>
            {branches.map((b: any) => (
              <option key={b.id} value={b.id}>{b.name}</option>
            ))}
          </select>
        </div>
      </div>

      {!selectedBranch && (
        <div className="p-8 text-center text-gray-400">اختر الفرع والشهر لعرض التقرير</div>
      )}

      {isLoading && <div className="p-8 text-center text-gray-400">جاري التحميل...</div>}

      {report && (
        <div className="flex-1 overflow-auto px-4 pb-4">
          <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-auto">
            <table className="w-full text-sm whitespace-nowrap">
              <thead>
                <tr className="bg-gray-50 text-xs text-gray-500 border-b">
                  <th className="px-2 py-1.5 text-start font-medium sticky right-0 bg-gray-50 z-10">الصنف</th>
                  {Array.from({ length: report.days_in_month }, (_, i) => i + 1).map((d) => (
                    <th key={d} className="px-2 py-1.5 text-center font-medium text-gray-400 min-w-[40px]">{d}</th>
                  ))}
                  <th className="px-2 py-1.5 text-center font-medium text-gray-700 min-w-[60px]">الإجمالي</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {report.items.filter((i) => i.total > 0).map((item) => (
                  <tr key={item.item_id} className="hover:bg-gray-50/30">
                    <td className="px-2 py-1 text-start font-medium text-gray-800 sticky right-0 bg-white z-10">
                      {item.item_name} <span className="text-xs text-gray-400">({item.unit})</span>
                    </td>
                    {Array.from({ length: report.days_in_month }, (_, i) => i + 1).map((d) => (
                      <td key={d} className="px-2 py-1 text-center font-mono text-xs">
                        {item.days[d] > 0 ? item.days[d].toFixed(3) : ''}
                      </td>
                    ))}
                    <td className="px-2 py-1 text-center font-mono font-bold text-sm">
                      {item.total.toFixed(3)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {report.items.filter((i) => i.total > 0).length === 0 && (
              <div className="p-8 text-center text-gray-400">لا توجد واردات في هذا الشهر</div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}