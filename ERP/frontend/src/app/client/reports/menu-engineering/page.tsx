'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { ExportButtons } from '../export-buttons';

export default function ClientMenuEngineeringPage() {
  const [branchId, setBranchId] = useState('');
  const [menuId, setMenuId] = useState('');

  const { data: warehouses } = useQuery({
    queryKey: ['client-warehouses'],
    queryFn: () => api.get('/client/warehouses').then((r) => r.data),
  });

  const branches = (warehouses || []).filter((w: any) => w.type === 'branch');

  const { data: menus } = useQuery({
    queryKey: ['client-menus', branchId],
    queryFn: () => api.get('/client/menu-engineering/menus', { params: { branch_id: branchId || undefined } }).then((r) => r.data),
    enabled: !!branchId,
  });

  const { data, isLoading } = useQuery({
    queryKey: ['client-report-menu-engineering', menuId, branchId],
    queryFn: () => api.get('/client/reports/menu-engineering', { params: { menu_id: menuId || undefined, branch_id: !menuId && branchId ? branchId : undefined } }).then((r) => r.data),
    enabled: !!branchId,
  });

  const recipes = data?.recipes ?? [];
  const summary = data?.summary ?? null;

  const handleBranchChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    setBranchId(e.target.value);
    setMenuId('');
  };

  return (
    <div className="flex-1 overflow-y-auto p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100">Menu Engineering</h1>
        <ExportButtons baseUrl="/api/client/reports/menu-engineering" params={{ menu_id: menuId || undefined, branch_id: branchId || undefined }} disabled={!recipes.length} />
      </div>

      <div className="flex flex-wrap gap-4 mb-6">
        <div className="flex-1 min-w-[200px]">
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">الفرع</label>
          <select
            value={branchId}
            onChange={handleBranchChange}
            className="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm"
          >
            <option value="">-- اختر الفرع --</option>
            {branches.map((b: any) => (
              <option key={b.id} value={b.id}>{b.name}</option>
            ))}
          </select>
        </div>
        <div className="flex-1 min-w-[200px]">
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">القائمة</label>
          <select
            value={menuId}
            onChange={(e) => setMenuId(e.target.value)}
            className="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm"
            disabled={!branchId}
          >
            <option value="">-- جميع القوائم --</option>
            {(menus || []).map((m: any) => (
              <option key={m.id} value={m.id}>{m.name}</option>
            ))}
          </select>
        </div>
      </div>

      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
          <div className="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4">
            <div className="text-xs text-gray-500 dark:text-gray-400">عدد الوصفات</div>
            <div className="text-xl font-bold mt-1">{summary.total_recipes}</div>
          </div>
          <div className="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4">
            <div className="text-xs text-gray-500 dark:text-gray-400">متوسط نسبة التكلفة</div>
            <div className={`text-xl font-bold mt-1 ${summary.avg_fc_pct <= 30 ? 'text-green-600' : summary.avg_fc_pct <= 45 ? 'text-amber-600' : 'text-red-600'}`}>{summary.avg_fc_pct}%</div>
          </div>
          <div className="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4">
            <div className="text-xs text-gray-500 dark:text-gray-400">إجمالي التكلفة</div>
            <div className="text-xl font-bold mt-1">{summary.total_cost.toFixed(2)}</div>
          </div>
          <div className="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4">
            <div className="text-xs text-gray-500 dark:text-gray-400">إجمالي سعر البيع</div>
            <div className="text-xl font-bold mt-1">{summary.total_selling_price.toFixed(2)}</div>
          </div>
          <div className="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4">
            <div className="text-xs text-gray-500 dark:text-gray-400">نطاق fc%</div>
            <div className="text-xl font-bold mt-1">{summary.min_fc_pct}% – {summary.max_fc_pct}%</div>
          </div>
        </div>
      )}

      <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
            <tr>
              <th className="text-right px-4 py-3">الوصفة</th>
              <th className="text-right px-4 py-3">التكلفة</th>
              <th className="text-right px-4 py-3">سعر البيع</th>
              <th className="text-right px-4 py-3">نسبة التكلفة</th>
              <th className="text-right px-4 py-3">الربحية</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {!branchId ? (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">اختر الفرع والقائمة لعرض التقرير</td></tr>
            ) : isLoading ? (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">جاري التحميل...</td></tr>
            ) : !recipes?.length ? (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">لا توجد وصفات نشطة</td></tr>
            ) : (
              recipes.map((r: any, i: number) => (
                <tr key={i} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                  <td className="px-4 py-3 font-medium">{r.recipe_name}</td>
                  <td className="px-4 py-3 font-mono">{r.total_cost.toFixed(2)}</td>
                  <td className="px-4 py-3 font-mono">{r.selling_price.toFixed(2)}</td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                      r.fc_pct <= 30 ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' :
                      r.fc_pct <= 45 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' :
                      'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'
                    }`}>{r.fc_pct}%</span>
                  </td>
                  <td className="px-4 py-3 font-mono">{r.profit_margin}%</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
