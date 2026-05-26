'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

export default function MenuReportPage() {
  const [branchId, setBranchId] = useState<string>('');
  const [menuId, setMenuId] = useState<string>('');

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });
  const branches = warehouses.filter((w: any) => w.type === 'branch');
  const branchName = branches.find((b: any) => b.id === branchId)?.name;

  const { data: menus = [] } = useQuery({
    queryKey: ['menu-menus', branchId],
    queryFn: () => api.get('/menu-engineering/menus', { params: { branch_id: branchId } }).then((r) => r.data),
    enabled: !!branchId,
  });
  const menuName = menus.find((m: any) => m.id === menuId)?.name;

  const downloadExport = async (format: 'excel' | 'pdf') => {
    try {
      const res = await api.get(`/menu-engineering/report/summary/export-${format}`, {
        params: { branch_id: branchId, menu_id: menuId || undefined },
        responseType: 'blob',
      });
      const blob = new Blob([res.data]);
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      const ext = format === 'excel' ? 'xlsx' : 'pdf';
      link.download = `تقرير_تكاليف_المنيو.${ext}`;
      link.click();
      URL.revokeObjectURL(link.href);
    } catch { toast.error('حدث خطأ أثناء التصدير'); }
  };

  const { data: report, isLoading } = useQuery({
    queryKey: ['menu-report', branchId, menuId],
    queryFn: () => api.get('/menu-engineering/report/summary', { params: { branch_id: branchId, menu_id: menuId } }).then((r) => r.data),
    enabled: !!branchId && !!menuId,
  });

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50/50" dir="rtl">
      <div className="bg-white border-b border-gray-200 px-6 py-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-lg font-bold text-gray-800">تقرير Menu Engineering</h2>
            <p className="text-xs text-gray-500 mt-0.5">تحليل التكاليف ونسب التكلفة حسب التصنيفات</p>
            {(branchName || menuName) && (
              <p className="text-xs text-gray-400 mt-1">
                {branchName && <span>{branchName}</span>}
                {branchName && menuName && <span> — </span>}
                {menuName && <span>{menuName}</span>}
              </p>
            )}
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
            {branchId && (
              <div className="flex gap-2 mr-2 pr-2 border-r border-gray-200">
                <button onClick={() => downloadExport('excel')}
                  className="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs hover:bg-green-700 disabled:opacity-50"
                  disabled={!menuId}>
                  ⬇ Excel
                </button>
                <button onClick={() => downloadExport('pdf')}
                  className="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs hover:bg-red-700 disabled:opacity-50"
                  disabled={!menuId}>
                  ⬇ PDF
                </button>
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        {!branchId && (
          <div className="text-center text-gray-400 py-20 bg-white rounded-xl border border-gray-100">
            الرجاء اختيار فرع لعرض التقرير
          </div>
        )}

        {branchId && !menuId && (
          <div className="text-center text-gray-400 py-20 bg-white rounded-xl border border-gray-100">
            الرجاء اختيار المنيو لعرض التقرير
          </div>
        )}

        {isLoading && <div className="text-center text-gray-400 py-12">جاري التحميل...</div>}

        {branchId && menuId && report && (
          <>
            <div className="bg-gradient-to-r from-blue-600 to-blue-500 rounded-2xl p-6 shadow-lg text-white">
              <div className="text-sm opacity-80 mb-1">ملخص المنيو</div>
              <div className="grid grid-cols-2 gap-6 mt-3">
                <div>
                  <div className="text-2xl font-bold">{report.overall.total_cost.toFixed(2)} ج</div>
                  <div className="text-xs opacity-70 mt-0.5">الدايركت كوست</div>
                </div>
                <div>
                  <div className={`text-2xl font-bold ${report.overall.overall_cost_pct > 35 ? 'text-red-300' : 'text-green-300'}`}>
                    {report.overall.overall_cost_pct}%
                  </div>
                  <div className="text-xs opacity-70 mt-0.5">نسبة التكلفة الإجمالية</div>
                </div>
              </div>
            </div>

            {report.categories.map((cat: any) => (
              <div key={cat.name} className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                <div className="bg-gray-50 px-4 py-3 flex items-center justify-between border-b border-gray-100">
                  <div className="font-bold text-gray-700">{cat.name}</div>
                  <div className="text-sm text-gray-500">
                    الدايركت كوست: <span className="text-blue-700 font-medium">{cat.total_cost.toFixed(2)} ج</span>
                    {' · '}
                    نسبة التكلفة: <span className={`font-medium ${cat.cost_pct > 35 ? 'text-red-600' : 'text-green-600'}`}>
                      {cat.cost_pct}%
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
            ))}

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
