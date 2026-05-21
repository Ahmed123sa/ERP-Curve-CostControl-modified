'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export default function MenuReportPage() {
  const [branchId, setBranchId] = useState<string>('');
  const [menuId, setMenuId] = useState<string>('');

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });
  const branches = warehouses.filter((w: any) => w.type === 'branch');

  const { data: menus = [] } = useQuery({
    queryKey: ['menu-menus', branchId],
    queryFn: () => api.get('/menu-engineering/menus', { params: { branch_id: branchId } }).then((r) => r.data),
    enabled: !!branchId,
  });

  const { data: report, isLoading } = useQuery({
    queryKey: ['menu-report', branchId, menuId],
    queryFn: () => api.get('/menu-engineering/report/summary', { params: { branch_id: branchId, menu_id: menuId || undefined } }).then((r) => r.data),
    enabled: !!branchId,
  });

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50/50" dir="rtl">
      <div className="bg-white border-b border-gray-200 px-6 py-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-lg font-bold text-gray-800">تقرير Menu Engineering</h2>
            <p className="text-xs text-gray-500 mt-0.5">تحليل التكاليف ونسب التكلفة حسب التصنيفات</p>
          </div>
          <div className="flex gap-3">
            <select value={branchId} onChange={(e) => { setBranchId(e.target.value); setMenuId(''); }}
              className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none w-56">
              <option value="">-- اختر الفرع --</option>
              {branches.map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
            <select value={menuId} onChange={(e) => setMenuId(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none w-56">
              <option value="">-- كل المنيوهات --</option>
              {menus.map((m: any) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
          </div>
        </div>
      </div>

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        {!branchId && (
          <div className="text-center text-gray-400 py-20 bg-white rounded-xl border border-gray-100">
            الرجاء اختيار فرع لعرض التقرير
          </div>
        )}

        {isLoading && <div className="text-center text-gray-400 py-12">جاري التحميل...</div>}

        {branchId && report && (
          <>
            <div className="bg-gradient-to-r from-blue-600 to-blue-500 rounded-2xl p-6 shadow-lg text-white">
              <div className="text-sm opacity-80 mb-1">إجمالي المنيو</div>
              <div className="grid grid-cols-3 gap-6 mt-3">
                <div>
                  <div className="text-2xl font-bold">{report.overall.total_cost.toFixed(2)} ج</div>
                  <div className="text-xs opacity-70 mt-0.5">إجمالي التكلفة المباشرة</div>
                </div>
                <div>
                  <div className="text-2xl font-bold">{report.overall.total_selling_price.toFixed(2)} ج</div>
                  <div className="text-xs opacity-70 mt-0.5">إجمالي سعر البيع</div>
                </div>
                <div>
                  <div className={`text-2xl font-bold ${report.overall.overall_cost_pct > 35 ? 'text-red-300' : 'text-green-300'}`}>
                    {report.overall.overall_cost_pct}%
                  </div>
                  <div className="text-xs opacity-70 mt-0.5">نسبة التكلفة الإجمالية</div>
                </div>
              </div>
            </div>

            {report.categories.map((cat: any) => {
              const catCost = cat.items.reduce((s: number, i: any) => s + i.total_cost, 0);
              const catPrice = cat.items.reduce((s: number, i: any) => s + i.selling_price, 0);
              return (
                <div key={cat.name} className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                  <div className="bg-gray-50 px-4 py-3 flex items-center justify-between border-b border-gray-100">
                    <div className="font-bold text-gray-700">{cat.name}</div>
                    <div className="text-sm text-gray-500">
                      متوسط التكلفة: <span className="text-blue-700 font-medium">{cat.avg_cost.toFixed(2)} ج</span>
                      {' · '}
                      إجمالي: <span className="text-blue-700 font-medium">{catCost.toFixed(2)} ج</span>
                      {' · '}
                      نسبة التكلفة: <span className={`font-medium ${catPrice > 0 && (catCost / catPrice) * 100 > 35 ? 'text-red-600' : 'text-green-600'}`}>
                        {catPrice > 0 ? ((catCost / catPrice) * 100).toFixed(1) : 0}%
                      </span>
                    </div>
                  </div>

                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-gray-500 text-xs border-b border-gray-50">
                        <th className="px-4 py-2.5 text-start font-medium">الصنف</th>
                        <th className="px-4 py-2.5 text-end font-medium">التكلفة</th>
                        <th className="px-4 py-2.5 text-end font-medium">سعر البيع</th>
                        <th className="px-4 py-2.5 text-end font-medium">نسبة التكلفة</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                      {cat.items.map((item: any) => (
                        <tr key={item.id} className="hover:bg-gray-50/30">
                          <td className="px-4 py-2.5 text-start font-medium text-gray-800">{item.name}</td>
                          <td className="px-4 py-2.5 text-end font-mono text-blue-700 dir-ltr">{item.total_cost.toFixed(2)} ج</td>
                          <td className="px-4 py-2.5 text-end font-mono dir-ltr">{item.selling_price.toFixed(2)} ج</td>
                          <td className="px-4 py-2.5 text-end font-mono">
                            <span className={`font-medium ${item.cost_pct > 35 ? 'text-red-600' : 'text-green-600'}`}>
                              {item.cost_pct}%
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              );
            })}

            {report.categories.length === 0 && (
              <div className="text-center text-gray-400 py-12 bg-white rounded-xl border border-gray-100">
                لا توجد أصناف في المنيو لهذا الفرع
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
