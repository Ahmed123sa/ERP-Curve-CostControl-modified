'use client';
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function FinancialDetailsPage() {
  const qc = useQueryClient();
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [selectedWh, setSelectedWh] = useState<string>('');

  const { data, isLoading } = useQuery({
    queryKey: ['financial-details', month, selectedWh],
    queryFn: () => api.get('/reports/financial-details', {
      params: { month, warehouse_id: selectedWh || undefined }
    }).then((r) => r.data),
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const generateMutation = useMutation({
    mutationFn: () => api.post('/closing/generate', { month, warehouse_id: 'all' }),
    onSuccess: () => {
      toast.success('تم توليد التقفيل بنجاح ✓');
      qc.invalidateQueries({ queryKey: ['financial-details', month, selectedWh] });
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في توليد التقفيل'),
  });

  const report = data as {
    month: string;
    warehouses: any[];
    summary: {
      total_opening: number;
      total_purchases: number;
      total_internal_in: number;
      total_in: number;
      total_closing: number;
      total_closing_actual: number;
      total_received: number;
      total_diff: number;
    };
  } | undefined;

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="التفاصيل المالية"
        subtitle="تحليل مالي لكل موقع — أول المدة / المشتريات / آخر المدة / المستلم الفعلي"
        actions={
          <div className="flex gap-2">
            <button
              onClick={() => generateMutation.mutate()}
              disabled={generateMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50 flex items-center gap-2"
            >
              <span>{generateMutation.isPending ? 'جاري التوليد...' : 'توليد التقفيل ⚙️'}</span>
            </button>
            <button
              onClick={() => {
                const params = new URLSearchParams({ month });
                if (selectedWh) params.set('warehouse_id', selectedWh);
                const url = `${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/reports/financial-details/export?${params}`;
                const token = localStorage.getItem('erp_token');

                fetch(url, {
                  headers: { Authorization: `Bearer ${token}` },
                })
                  .then((res) => {
                    if (!res.ok) throw new Error('فشل التحميل');
                    return res.blob();
                  })
                  .then((blob) => {
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = `تفاصيل_مالية_${month}.xlsx`;
                    link.click();
                    URL.revokeObjectURL(link.href);
                    toast.success('تم تصدير التقرير ✓');
                  })
                  .catch(() => toast.error('خطأ في تصدير التقرير'));
              }}
              disabled={!report?.warehouses?.length}
              className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-40 flex items-center gap-2"
            >
              📥 إكسيل
            </button>
            <a
              href={`${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/reports/financial-details/export-pdf?${new URLSearchParams({ month, ...(selectedWh ? { warehouse_id: selectedWh } : {}) })}`}
              className="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 disabled:opacity-40"
              onClick={(e) => { if (!report?.warehouses?.length) e.preventDefault(); }}
            >
              PDF
            </a>
          </div>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        {/* الفلاتر */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
          <div className="grid grid-cols-2 gap-4 items-end">
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">الشهر</label>
              <input
                type="month"
                value={month}
                onChange={(e) => setMonth(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">الموقع (اختياري)</label>
              <select
                value={selectedWh}
                onChange={(e) => setSelectedWh(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              >
                <option value="">كل المواقع</option>
                {warehouses.map((w: any) => (
                  <option key={w.id} value={w.id}>{w.name}</option>
                ))}
              </select>
            </div>
          </div>
        </div>

        {isLoading ? (
          <div className="text-center py-12 text-gray-400">جاري التحميل...</div>
        ) : !report?.warehouses?.length && !isLoading ? (
          <div className="text-center py-16 bg-white border border-dashed border-gray-200 rounded-xl">
            <div className="text-5xl mb-4 text-gray-300">📊</div>
            <h3 className="text-lg font-bold text-gray-700 mb-2">لا توجد بيانات تقفيل للشهر {month}</h3>
            <p className="text-sm text-gray-500 mb-6">التقفيل الشهري لم يتم توليده بعد لهذا الشهر.<br/>اضغط على الزر أدناه لحساب بيانات أول المدة والمشتريات والمستلم الفعلي</p>
            <button
              onClick={() => generateMutation.mutate()}
              disabled={generateMutation.isPending}
              className="px-8 py-3 bg-blue-600 text-white rounded-xl text-sm font-bold hover:bg-blue-700 disabled:opacity-50 shadow-lg shadow-blue-200"
            >
              {generateMutation.isPending ? '⚙️ جاري توليد التقفيل...' : '⚙️ توليد التقفيل الشهري'}
            </button>
          </div>
        ) : (
          <>
            {/* شريط الملخص العام */}
            {report?.summary && (
              <div className="grid grid-cols-5 gap-4">
                {[
                  { label: 'إجمالي أول المدة', value: report.summary.total_opening, color: 'blue' },
                  { label: 'إجمالي المشتريات', value: report.summary.total_purchases, color: 'green' },
                  { label: 'إجمالي الوارد الداخلي', value: report.summary.total_internal_in, color: 'teal' },
                  { label: 'آخر المدة (فعلي)',  value: report.summary.total_closing_actual, color: 'orange' },
                  { label: 'المستلم الفعلي',    value: report.summary.total_received, color: 'purple' },
                ].map((k) => (
                  <div key={k.label} className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
                    <div className="text-xs text-gray-500 mb-1">{k.label}</div>
                    <div className={`text-xl font-bold text-${k.color}-600`}>
                      {Math.abs(k.value).toLocaleString()} ج
                    </div>
                  </div>
                ))}
              </div>
            )}

            {/* المعادلة التوضيحية */}
            <div className="bg-blue-50/50 border border-blue-100 rounded-xl p-4 text-sm text-blue-800">
              <span className="font-bold">معادلة المستلم الفعلي: </span>
              أول المدة + مشتريات + وارد داخلي - آخر المدة (فعلي إن وجد وإلا صفر) = قيمة ما تم استهلاكه أو صرفه فعلياً من الموقع
            </div>

            {/* جدول المواقع */}
            <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-x-auto">
              <table className="w-full text-sm text-right min-w-[1100px]">
                <thead className="bg-gray-50 border-b border-gray-100 text-gray-600">
                  <tr>
                    <th className="p-3 font-medium">الموقع</th>
                    <th className="p-3 font-medium text-center w-16">النوع</th>
                    <th className="p-3 font-medium text-left">أول المدة</th>
                    <th className="p-3 font-medium text-left text-green-700">مشتريات</th>
                    <th className="p-3 font-medium text-left text-teal-700">وارد داخلي</th>
                    <th className="p-3 font-medium text-left text-amber-700">آخر المدة (نظري)</th>
                    <th className="p-3 font-medium text-left text-orange-700">آخر المدة (فعلي)</th>
                    <th className="p-3 font-medium text-left text-purple-700">المستلم الفعلي</th>
                    <th className="p-3 font-medium text-left">الفروق</th>
                    <th className="p-3 font-medium text-center w-20">الأصناف</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {report?.warehouses?.map((wh: any) => {
                    const isBranch = wh.type === 'branch';
                    return (
                    <tr key={wh.id} className="hover:bg-blue-50/20">
                      <td className="p-3 font-medium text-gray-900">
                        <span className={wh.locked ? 'text-gray-400' : ''}>{wh.name}</span>
                        {wh.locked && <span className="text-xs text-gray-400 mr-1">🔒</span>}
                      </td>
                      <td className="p-3 text-center">
                        <span className={`text-xs px-2 py-0.5 rounded-full ${
                          wh.type === 'main' ? 'bg-blue-100 text-blue-700' :
                          wh.type === 'sub' ? 'bg-amber-100 text-amber-700' :
                          wh.type === 'branch' ? 'bg-gray-100 text-gray-600' :
                          'bg-gray-100 text-gray-600'
                        }`}>
                          {wh.type === 'main' ? 'رئيسي' : wh.type === 'sub' ? 'فرعي' : wh.type === 'branch' ? 'فرع' : wh.type}
                        </span>
                      </td>
                      <td className="p-3 text-left font-mono text-blue-700 font-medium">
                        {wh.opening_value.toLocaleString()}
                      </td>
                      <td className="p-3 text-left font-mono text-green-700 font-medium">
                        {isBranch ? '—' : wh.purchases_value.toLocaleString()}
                      </td>
                      <td className="p-3 text-left font-mono text-teal-700 font-medium">
                        {wh.internal_in_value ? Number(wh.internal_in_value).toLocaleString() : (isBranch ? '—' : '0')}
                      </td>
                      <td className="p-3 text-left font-mono text-amber-700 font-medium">
                        {isBranch ? '—' : wh.closing_value.toLocaleString()}
                      </td>
                      <td className="p-3 text-left font-mono text-orange-700 font-medium">
                        {wh.closing_value_actual ? wh.closing_value_actual.toLocaleString() : '—'}
                      </td>
                      <td className="p-3 text-left font-mono text-purple-700 font-bold text-base">
                        {wh.actual_received.toLocaleString()}
                      </td>
                      <td className={`p-3 text-left font-mono font-medium ${wh.diff_value < 0 ? 'text-red-600' : wh.diff_value > 0 ? 'text-green-600' : 'text-gray-400'}`}>
                        {wh.diff_value.toLocaleString()}
                      </td>
                      <td className="p-3 text-center text-gray-500 text-xs">
                        {wh.active_items}/{wh.item_count}
                      </td>
                    </tr>
                    );
                  })}
                  {(!report?.warehouses || report.warehouses.length === 0) && !isLoading && (
                    <tr>
                      <td colSpan={10} className="p-12 text-center">
                        <div className="text-gray-400 mb-4 text-lg">📊 لا توجد بيانات تقفيل للشهر المحدد</div>
                        <p className="text-sm text-gray-500 mb-4">اضغط على "توليد التقفيل" لحساب بيانات أول المدة والمشتريات وآخر المدة</p>
                        <button
                          onClick={() => generateMutation.mutate()}
                          disabled={generateMutation.isPending}
                          className="px-6 py-3 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
                        >
                          {generateMutation.isPending ? 'جاري التوليد...' : '⚙️ توليد التقفيل الآن'}
                        </button>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            {/* إجمالي القيم */}
            {report?.warehouses && report.warehouses.length > 0 && (
              <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
                <div className="grid grid-cols-5 gap-6">
                  {[
                    { label: 'عدد المواقع', value: report.warehouses.length, suffix: '' },
                    { label: 'إجمالي أول المدة', value: report.summary.total_opening, suffix: 'ج' },
                    { label: 'إجمالي المشتريات', value: report.summary.total_purchases, suffix: 'ج' },
                    { label: 'إجمالي وارد داخلي', value: report.summary.total_internal_in, suffix: 'ج' },
                    { label: 'المستلم الفعلي', value: report.summary.total_received, suffix: 'ج' },
                  ].map((k) => (
                    <div key={k.label} className="text-center">
                      <div className="text-xs text-gray-400">{k.label}</div>
                      <div className="text-lg font-bold text-gray-800 mt-1">
                        {k.value.toLocaleString()} {k.suffix}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}