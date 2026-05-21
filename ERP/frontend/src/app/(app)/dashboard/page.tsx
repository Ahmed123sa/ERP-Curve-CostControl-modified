'use client';

import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useAuthStore } from '@/lib/store';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts';

export default function DashboardPage() {
  const { currentClient } = useAuthStore();
  const month = new Date().toISOString().slice(0, 7);

  const { data: kpis, isLoading } = useQuery({
    queryKey: ['kpis', currentClient?.id, month],
    queryFn: () => api.get('/dashboard/kpis', { params: { month } }).then((r) => r.data),
    enabled: !!currentClient,
  });

  const { data: trend } = useQuery({
    queryKey: ['trend', currentClient?.id],
    queryFn: () => api.get('/dashboard/monthly-trend').then((r) => r.data),
    enabled: !!currentClient,
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['wh-summary', currentClient?.id, month],
    queryFn: () => api.get('/dashboard/warehouse-summary', { params: { month } }).then((r) => r.data),
    enabled: !!currentClient,
  });

  const kpiCards = [
    { label: 'إجمالي المشتريات', value: kpis?.total_purchases, unit: 'ج', color: 'text-blue-600' },
    { label: 'قيمة المنصرف',     value: kpis?.total_dispatched, unit: 'ج', color: 'text-gray-600' },
    { label: 'إجمالي الفروق',    value: kpis?.total_diffs,      unit: 'ج', color: (kpis?.total_diffs ?? 0) < 0 ? 'text-red-600' : 'text-green-600' },
    { label: 'Food Cost %',       value: kpis?.food_cost_pct,   unit: '%', color: (kpis?.food_cost_pct ?? 0) > 35 ? 'text-amber-600' : 'text-green-600' },
  ];

  const branches = warehouses.filter((w: any) => w.type === 'branch');
  const mainSub = warehouses.filter((w: any) => w.type !== 'branch');

  const totals = mainSub.reduce(
    (acc: any, w: any) => {
      acc.opening += w.opening;
      acc.purchases += w.purchases;
      acc.diff += w.diff;
      return acc;
    },
    { opening: 0, purchases: 0, diff: 0 }
  );
  const branchIn = branches.reduce((s: number, w: any) => s + w.in, 0);

  const downloadExport = (url: string, filename: string) => {
    const token = localStorage.getItem('erp_token');
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((res) => { if (!res.ok) throw new Error(); return res.blob(); })
      .then((blob) => {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob); link.download = filename; link.click();
        URL.revokeObjectURL(link.href);
      })
      .catch(() => {});
  };

  return (
    <>
      <PageHeader
        title="لوحة التحكم"
        subtitle={`${currentClient?.name ?? ''} — ${month}`}
        actions={
          <button
            onClick={() => downloadExport(`${api.defaults.baseURL}/dashboard/export?month=${month}`, `مؤشرات_${month}.xlsx`)}
            className="text-xs text-blue-600 hover:underline bg-transparent border-none cursor-pointer"
          >
            تصدير إكسيل
          </button>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6" dir="rtl">
        {/* KPI Cards */}
        <div className="grid grid-cols-4 gap-4">
          {kpiCards.map((k) => (
            <div key={k.label} className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
              <div className="text-xs text-gray-500">{k.label}</div>
              <div className={`text-2xl font-semibold mt-1 ${k.color}`}>
                {isLoading ? '...' : (k.value?.toLocaleString('en-US') ?? '—')}
                <span className="text-sm font-normal text-gray-400 mr-1">{k.unit}</span>
              </div>
            </div>
          ))}
        </div>

        {/* Monthly Trend Chart */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
          <h3 className="font-medium text-gray-800 text-sm mb-4">اتجاه شهري (آخر 6 أشهر)</h3>
          <ResponsiveContainer width="100%" height={260}>
            <BarChart data={trend ?? []}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="month" tick={{ fontSize: 11 }} />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip />
              <Bar dataKey="purchases" name="مشتريات" fill="#3b82f6" radius={[4, 4, 0, 0]} />
              <Bar dataKey="dispatched" name="منصرف" fill="#6b7280" radius={[4, 4, 0, 0]} />
              <Bar dataKey="diffs" name="فروق" fill="#ef4444" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>

        {/* Warehouse Summary */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {/* المخازن الرئيسية والفرعية */}
          <div className="bg-white border border-gray-100 rounded-xl shadow-sm">
            <div className="px-4 py-3 border-b border-gray-100">
              <h3 className="font-medium text-gray-800 text-sm">ملخص المخازن (رئيسي + فرعي)</h3>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-xs text-gray-400 border-b border-gray-50">
                    <th className="px-3 py-2 text-right font-normal">الموقع</th>
                    <th className="px-3 py-2 text-left font-normal">أول المدة</th>
                    <th className="px-3 py-2 text-left font-normal">مشتريات</th>
                    <th className="px-3 py-2 text-left font-normal">منصرف</th>
                    <th className="px-3 py-2 text-left font-normal">الفروق</th>
                  </tr>
                </thead>
                <tbody>
                  {mainSub.map((w: any) => (
                    <tr key={w.id} className="border-b border-gray-50 last:border-0">
                      <td className="px-3 py-2.5 font-medium text-gray-800">
                        {w.name}
                        <span className={`mr-1 text-[10px] px-1.5 py-0.5 rounded-full ${
                          w.type === 'main' ? 'bg-blue-100 text-blue-600' : 'bg-amber-100 text-amber-600'
                        }`}>{w.type === 'main' ? 'رئيسي' : 'فرعي'}</span>
                      </td>
                      <td className="px-3 py-2.5 text-left font-mono text-blue-700">{w.opening?.toLocaleString()}</td>
                      <td className="px-3 py-2.5 text-left font-mono text-green-700">{w.purchases?.toLocaleString()}</td>
                      <td className="px-3 py-2.5 text-left font-mono text-gray-600">{w.out_qty}</td>
                      <td className={`px-3 py-2.5 text-left font-mono font-medium ${w.diff < 0 ? 'text-red-600' : w.diff > 0 ? 'text-green-600' : 'text-gray-400'}`}>
                        {w.diff?.toLocaleString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot className="bg-gray-50 text-xs font-bold">
                  <tr>
                    <td className="px-3 py-2">الإجمالي</td>
                    <td className="px-3 py-2 text-left">{totals.opening.toLocaleString()}</td>
                    <td className="px-3 py-2 text-left">{totals.purchases.toLocaleString()}</td>
                    <td className="px-3 py-2 text-left">—</td>
                    <td className="px-3 py-2 text-left">{totals.diff.toLocaleString()}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          {/* مشتريات الفروع (الوارد للفروع) */}
          <div className="bg-white border border-gray-100 rounded-xl shadow-sm">
            <div className="px-4 py-3 border-b border-gray-100">
              <h3 className="font-medium text-gray-800 text-sm">مشتريات الفروع (وارد)</h3>
            </div>
            {branches.length === 0 ? (
              <div className="p-8 text-center text-gray-400 text-sm">لا توجد فروع</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-xs text-gray-400 border-b border-gray-50">
                      <th className="px-3 py-2 text-right font-normal">الفرع</th>
                      <th className="px-3 py-2 text-left font-normal">إجمالي الوارد</th>
                      <th className="px-3 py-2 text-left font-normal">المنصرف</th>
                      <th className="px-3 py-2 text-left font-normal">الفروق</th>
                    </tr>
                  </thead>
                  <tbody>
                    {branches.map((w: any) => (
                      <tr key={w.id} className="border-b border-gray-50 last:border-0">
                        <td className="px-3 py-2.5 font-medium text-orange-800">{w.name}</td>
                        <td className="px-3 py-2.5 text-left font-mono text-blue-700">{w.in?.toLocaleString()}</td>
                        <td className="px-3 py-2.5 text-left font-mono text-gray-600">{w.out_qty}</td>
                        <td className={`px-3 py-2.5 text-left font-mono font-medium ${w.diff < 0 ? 'text-red-600' : w.diff > 0 ? 'text-green-600' : 'text-gray-400'}`}>
                          {w.diff?.toLocaleString()}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="bg-gray-50 text-xs font-bold">
                    <tr>
                      <td className="px-3 py-2">الإجمالي</td>
                      <td className="px-3 py-2 text-left">{branchIn.toLocaleString()}</td>
                      <td className="px-3 py-2 text-left">—</td>
                      <td className="px-3 py-2 text-left">—</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
