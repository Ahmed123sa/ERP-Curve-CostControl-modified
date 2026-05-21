'use client';

import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useState } from 'react';

export default function GrandSummaryPage() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));

  const { data, isLoading } = useQuery({
    queryKey: ['grand-summary', month],
    queryFn: () => api.get('/reports/grand-summary', { params: { month } }).then(r => r.data),
  });

  if (isLoading) return <div className="p-12 text-center text-gray-400">جاري تحميل التقرير الشامل...</div>;

  const { items = [], locations = [] } = data || {};

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50/50">
      <div className="p-6 bg-white border-b border-gray-100 flex items-center justify-between shadow-sm">
        <div>
          <h1 className="text-xl font-bold text-gray-800">التقرير الشامل للجرد (Matrix)</h1>
          <p className="text-xs text-gray-500 mt-1">توزيع الأرصدة والمنصرف عبر كل المخازن والفروع</p>
        </div>
        <div className="flex gap-4">
          <input 
            type="month" 
            value={month} 
            onChange={(e) => setMonth(e.target.value)}
            className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
          />
          <button className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">تصدير Excel 📥</button>
        </div>
      </div>

      <div className="flex-1 overflow-auto p-4">
        <div className="bg-white rounded-xl border border-gray-100 shadow-xl min-w-max">
          <table className="text-right text-xs border-collapse w-full" dir="rtl">
            <thead className="bg-gray-800 text-white sticky top-0 z-10">
              <tr>
                <th rowSpan={2} className="p-3 border border-gray-700 min-w-[200px]">الصنف</th>
                <th rowSpan={2} className="p-3 border border-gray-700">الوحدة</th>
                {locations.map((loc: any) => (
                  <th key={loc.id} colSpan={4} className="p-2 border border-gray-700 text-center text-[10px]">
                    {loc.name} {loc.type === 'main' ? '(رئيسي)' : loc.type === 'sub' ? '(فرعي)' : '(فرع)'}
                  </th>
                ))}
                <th colSpan={4} className="p-2 border border-gray-700 text-center bg-gray-700 text-amber-400">الإجمالي العام</th>
              </tr>
              <tr className="bg-gray-700 text-[9px]">
                {locations.map((loc: any) => (
                  <>
                    <th key={`${loc.id}-o`} className="p-2 border border-gray-600">أول</th>
                    <th key={`${loc.id}-i`} className="p-2 border border-gray-600">وارد</th>
                    <th key={`${loc.id}-p`} className="p-2 border border-gray-600">صرف</th>
                    <th key={`${loc.id}-c`} className="p-2 border border-gray-600">آخر</th>
                  </>
                ))}
                <th className="p-2 border border-gray-600 bg-gray-600">أول</th>
                <th className="p-2 border border-gray-600 bg-gray-600">وارد</th>
                <th className="p-2 border border-gray-600 bg-gray-600">صرف</th>
                <th className="p-2 border border-gray-600 bg-gray-600">آخر</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {items.map((item: any) => (
                <tr key={item.item_id} className="hover:bg-blue-50/30 transition-colors">
                  <td className="p-3 border-x border-gray-50 font-bold sticky right-0 bg-white z-20 shadow-[2px_0_5px_rgba(0,0,0,0.05)]">
                    {item.item_name}
                  </td>
                  <td className="p-3 border-x border-gray-50 text-gray-400">{item.unit}</td>
                  {locations.map((loc: any) => {
                    const d = item.locations[loc.id] || {};
                    return (
                      <>
                        <td className="p-2 border-x border-gray-50 text-center text-blue-600 bg-blue-50/5 font-medium">{d.opening || '—'}</td>
                        <td className="p-2 border-x border-gray-50 text-center text-green-600">{d.in || '—'}</td>
                        <td className="p-2 border-x border-gray-50 text-center text-red-600">{d.out || '—'}</td>
                        <td className="p-2 border-x border-gray-50 text-center font-bold bg-gray-50/30">{d.theoretical || '—'}</td>
                      </>
                    );
                  })}
                  <td className="p-2 border-x border-gray-100 text-center bg-amber-50 text-amber-800 font-bold">{item.totals.opening_qty}</td>
                  <td className="p-2 border-x border-gray-100 text-center bg-amber-50 text-amber-800 font-bold">{item.totals.in_qty}</td>
                  <td className="p-2 border-x border-gray-100 text-center bg-amber-50 text-amber-800 font-bold">{item.totals.out_qty}</td>
                  <td className="p-2 border-x border-gray-100 text-center bg-amber-100 text-amber-900 font-black">{item.totals.theoretical}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
