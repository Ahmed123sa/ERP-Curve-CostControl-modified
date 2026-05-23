'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

type Tab = 'inventory' | 'purchases' | 'prices' | 'cost-impact' | 'pareto' | 'stock-value';

export default function AnalyticsPage() {
  const [activeTab, setActiveTab] = useState<Tab>('inventory');
  const [threshold, setThreshold] = useState('10');
  const [limit, setLimit] = useState('10');

  const tabs: { key: Tab; label: string }[] = [
    { key: 'inventory', label: '📦 إنذار المخزون' },
    { key: 'purchases', label: '📈 أهم المشتريات' },
    { key: 'prices', label: '💸 تغيرات الأسعار' },
    { key: 'cost-impact', label: '📊 أثر التكلفة' },
    { key: 'pareto', label: '🎯 باريتو' },
    { key: 'stock-value', label: '💰 قيمة المخزون' },
  ];

  // ── Inventory Alerts ──
  const { data: alerts } = useQuery({
    queryKey: ['analytics-inventory'],
    queryFn: () => api.get('/menu-engineering/analytics/inventory-alerts').then((r) => r.data),
  });

  // ── Top Purchases ──
  const { data: purchases } = useQuery({
    queryKey: ['analytics-purchases', limit],
    queryFn: () => api.get('/menu-engineering/analytics/top-purchases', { params: { limit } }).then((r) => r.data),
  });

  // ── Price Changes ──
  const { data: prices } = useQuery({
    queryKey: ['analytics-prices', threshold],
    queryFn: () => api.get('/menu-engineering/analytics/price-changes', { params: { threshold } }).then((r) => r.data),
  });

  // ── Cost Impact ──
  const { data: costImpact } = useQuery({
    queryKey: ['analytics-cost-impact', threshold],
    queryFn: () => api.get('/menu-engineering/analytics/cost-impact', { params: { threshold } }).then((r) => r.data),
  });

  // ── Stock Value ──
  const { data: stockValue } = useQuery({
    queryKey: ['analytics-stock-value'],
    queryFn: () => api.get('/menu-engineering/analytics/stock-value').then((r) => r.data),
  });

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50/50" dir="rtl">
      {/* Header */}
      <div className="bg-white border-b border-gray-200 px-6 py-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-lg font-bold text-gray-800">🔬 التحليلات الذكية</h2>
            <p className="text-xs text-gray-500 mt-0.5">تحليلات متقدمة للمخزون والمشتريات والتكاليف</p>
          </div>
          {(activeTab === 'prices' || activeTab === 'cost-impact') && (
            <div className="flex items-center gap-2">
              <span className="text-xs text-gray-500">Threshold:</span>
              <input type="number" value={threshold} onChange={(e) => setThreshold(e.target.value)}
                className="w-16 border border-gray-200 rounded px-2 py-1 text-xs text-center outline-none" />
              <span className="text-xs text-gray-400">%</span>
            </div>
          )}
          {activeTab === 'purchases' && (
            <div className="flex items-center gap-2">
              <span className="text-xs text-gray-500">العدد:</span>
              <select value={limit} onChange={(e) => setLimit(e.target.value)}
                className="border border-gray-200 rounded px-2 py-1 text-xs outline-none">
                <option value="5">5</option>
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="50">50</option>
              </select>
            </div>
          )}
        </div>
      </div>

      {/* Sub-tabs */}
      <div className="bg-white border-b border-gray-200 px-6 py-0 flex gap-0 overflow-x-auto">
        {tabs.map((t) => (
          <button key={t.key} onClick={() => setActiveTab(t.key)}
            className={`whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 transition-colors ${
              activeTab === t.key
                ? 'border-blue-600 text-blue-700'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}>
            {t.label}
          </button>
        ))}
      </div>

      {/* Content */}
      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        {activeTab === 'inventory' && (
          <InventoryAlertsTab data={alerts} />
        )}
        {activeTab === 'purchases' && (
          <TopPurchasesTab data={purchases} />
        )}
        {activeTab === 'prices' && (
          <PriceChangesTab data={prices} threshold={parseFloat(threshold)} />
        )}
        {activeTab === 'cost-impact' && (
          <CostImpactTab data={costImpact} />
        )}
        {activeTab === 'pareto' && (
          <ParetoTab />
        )}
        {activeTab === 'stock-value' && (
          <StockValueTab data={stockValue} />
        )}
      </div>
    </div>
  );
}

// ── Sub-tab Components ──

function InventoryAlertsTab({ data }: { data: any }) {
  if (!data) return <Loading />;
  return (
    <>
      {/* Summary */}
      <div className="grid grid-cols-3 gap-4">
        <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-center">
          <div className="text-2xl font-bold text-red-600">{data.summary.critical_count}</div>
          <div className="text-xs text-red-500 mt-1">🔴 حرج — أقل من الحد الأدنى</div>
        </div>
        <div className="bg-amber-50 border border-amber-200 rounded-xl p-4 text-center">
          <div className="text-2xl font-bold text-amber-600">{data.summary.warning_count}</div>
          <div className="text-xs text-amber-500 mt-1">🟡 إنذار — وشك يخلص</div>
        </div>
        <div className="bg-green-50 border border-green-200 rounded-xl p-4 text-center">
          <div className="text-2xl font-bold text-green-600">{data.summary.ok_count}</div>
          <div className="text-xs text-green-500 mt-1">🟢 تمام — ضمن الحدود الآمنة</div>
        </div>
      </div>

      {/* Critical */}
      {data.critical.length > 0 && (
        <div className="bg-white border border-red-200 rounded-xl shadow-sm overflow-hidden">
          <div className="bg-red-50 px-4 py-2.5 border-b border-red-100 font-bold text-red-700 text-sm">
            🔴 حرج — أصناف أقل من الحد الأدنى
          </div>
          <table className="w-full text-sm">
            <thead><tr className="text-xs text-gray-500 border-b border-gray-50">
              <th className="px-3 py-2 text-start font-medium">الصنف</th>
              <th className="px-3 py-2 text-start font-medium">المخزن</th>
              <th className="px-3 py-2 text-end font-medium">الرصيد الحالي</th>
              <th className="px-3 py-2 text-end font-medium">الحد الأدنى</th>
              <th className="px-3 py-2 text-end font-medium">العجز</th>
              <th className="px-3 py-2 text-end font-medium">اقتراح طلب</th>
            </tr></thead>
            <tbody className="divide-y divide-gray-50">
              {data.critical.map((a: any, i: number) => (
                <tr key={i} className="hover:bg-red-50/30">
                  <td className="px-3 py-2 font-medium text-gray-800">{a.item_name}</td>
                  <td className="px-3 py-2 text-gray-600">{a.warehouse_name}</td>
                  <td className="px-3 py-2 text-end font-mono text-red-600 font-medium">{a.current_qty} {a.unit}</td>
                  <td className="px-3 py-2 text-end font-mono">{a.min_stock_level} {a.unit}</td>
                  <td className="px-3 py-2 text-end font-mono text-red-600">{a.deficit} {a.unit}</td>
                  <td className="px-3 py-2 text-end font-mono text-blue-600">
                    {a.reorder_qty > 0 ? `+${a.reorder_qty} ${a.unit}` : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Warning */}
      {data.warning.length > 0 && (
        <div className="bg-white border border-amber-200 rounded-xl shadow-sm overflow-hidden">
          <div className="bg-amber-50 px-4 py-2.5 border-b border-amber-100 font-bold text-amber-700 text-sm">
            🟡 إنذار — أصناف أوشكت على النفاد
          </div>
          <table className="w-full text-sm">
            <thead><tr className="text-xs text-gray-500 border-b border-gray-50">
              <th className="px-3 py-2 text-start font-medium">الصنف</th>
              <th className="px-3 py-2 text-start font-medium">المخزن</th>
              <th className="px-3 py-2 text-end font-medium">الرصيد الحالي</th>
              <th className="px-3 py-2 text-end font-medium">الحد الأدنى</th>
            </tr></thead>
            <tbody className="divide-y divide-gray-50">
              {data.warning.map((a: any, i: number) => (
                <tr key={i} className="hover:bg-amber-50/30">
                  <td className="px-3 py-2 font-medium text-gray-800">{a.item_name}</td>
                  <td className="px-3 py-2 text-gray-600">{a.warehouse_name}</td>
                  <td className="px-3 py-2 text-end font-mono text-amber-600 font-medium">{a.current_qty} {a.unit}</td>
                  <td className="px-3 py-2 text-end font-mono">{a.min_stock_level} {a.unit}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data.critical.length === 0 && data.warning.length === 0 && (
        <div className="bg-white border border-green-200 rounded-xl p-8 text-center text-green-600 font-medium">
          ✅ جميع الأصناف ضمن الحدود الآمنة — لا توجد إنذارات
        </div>
      )}
    </>
  );
}

function TopPurchasesTab({ data }: { data: any }) {
  if (!data) return <Loading />;
  return (
    <>
      <div className="bg-gradient-to-r from-blue-600 to-blue-500 rounded-xl p-5 shadow text-white">
        <div className="text-sm opacity-80">إجمالي قيمة المشتريات (الفترة)</div>
        <div className="text-3xl font-bold mt-1">{data.total_value_all.toLocaleString()} ج</div>
      </div>

      <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-gray-100 font-medium text-gray-700 text-sm">📈 ترتيب الأصناف حسب قيمة الشراء</div>
        <table className="w-full text-sm">
          <thead><tr className="text-xs text-gray-500 border-b border-gray-50">
            <th className="px-3 py-2 text-center font-medium">#</th>
            <th className="px-3 py-2 text-start font-medium">الصنف</th>
            <th className="px-3 py-2 text-end font-medium">عدد مرات الشراء</th>
            <th className="px-3 py-2 text-end font-medium">إجمالي الكمية</th>
            <th className="px-3 py-2 text-end font-medium">إجمالي القيمة</th>
            <th className="px-3 py-2 text-end font-medium">متوسط سعر الوحدة</th>
          </tr></thead>
          <tbody className="divide-y divide-gray-50">
            {data.items.map((p: any) => (
              <tr key={p.rank} className="hover:bg-gray-50/30">
                <td className="px-3 py-2 text-center text-gray-400 text-xs">{p.rank}</td>
                <td className="px-3 py-2 font-medium text-gray-800">{p.item_name}</td>
                <td className="px-3 py-2 text-end font-mono">{p.purchase_count}</td>
                <td className="px-3 py-2 text-end font-mono">{p.total_qty} {p.unit}</td>
                <td className="px-3 py-2 text-end font-mono text-green-700 font-medium">{p.total_value.toLocaleString()} ج</td>
                <td className="px-3 py-2 text-end font-mono text-gray-500">
                  {p.purchase_count > 0 ? (p.total_value / p.total_qty).toFixed(2) : 0} ج/{p.unit}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {data.items.length === 0 && (
          <div className="p-8 text-center text-gray-400">لا توجد مشتريات في هذه الفترة</div>
        )}
      </div>
    </>
  );
}

function PriceChangesTab({ data, threshold }: { data: any; threshold: number }) {
  const [hideStale, setHideStale] = useState(false);
  if (!data) return <Loading />;
  const visibleChanges = hideStale ? data.changes.filter((c: any) => !c.is_stale) : data.changes;
  const staleCount = data.changes.filter((c: any) => c.is_stale).length;
  return (
    <>
      <div className="flex items-center gap-3">
        <div className="bg-orange-50 border border-orange-200 rounded-xl p-3 text-center flex-1">
          <div className="text-xl font-bold text-orange-600">{data.changes.length}</div>
          <div className="text-xs text-orange-500 mt-0.5">إجمالي تغيرات الأسعار</div>
        </div>
        <div className="bg-red-50 border border-red-200 rounded-xl p-3 text-center flex-1">
          <div className="text-xl font-bold text-red-600">{data.unusual_count}</div>
          <div className="text-xs text-red-500 mt-0.5">⚠️ غير طبيعي (&gt;{threshold}%)</div>
        </div>
        {staleCount > 0 && (
          <div className="bg-gray-100 border border-gray-200 rounded-xl p-3 text-center flex-1">
            <div className="text-xl font-bold text-gray-500">{staleCount}</div>
            <div className="text-xs text-gray-400 mt-0.5">تم التصحيح</div>
          </div>
        )}
      </div>

      <div className="flex items-center gap-2 justify-end mb-2">
        {staleCount > 0 && (
          <label className="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer select-none">
            <input type="checkbox" checked={hideStale} onChange={(e) => setHideStale(e.target.checked)}
              className="rounded border-gray-300" />
            إخفاء التغييرات الملغية ({staleCount})
          </label>
        )}
      </div>

      <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead><tr className="text-xs text-gray-500 border-b border-gray-50">
            <th className="px-3 py-2 text-start font-medium">الصنف</th>
            <th className="px-3 py-2 text-end font-medium">متوسط السعر</th>
            <th className="px-3 py-2 text-end font-medium">السعر القديم</th>
            <th className="px-3 py-2 text-end font-medium">السعر الجديد</th>
            <th className="px-3 py-2 text-end font-medium">الفرق</th>
            <th className="px-3 py-2 text-end font-medium">%</th>
            <th className="px-3 py-2 text-end font-medium">المخزن</th>
            <th className="px-3 py-2 text-end font-medium">التاريخ</th>
          </tr></thead>
          <tbody className="divide-y divide-gray-50">
            {visibleChanges.map((c: any, i: number) => (
              <tr key={c.id || i} className={`hover:bg-gray-50/30 ${c.is_unusual ? 'bg-red-50/50' : ''} ${c.is_stale ? 'opacity-50 line-through' : ''}`}>
                <td className="px-3 py-2 font-medium text-gray-800">
                  {c.is_unusual && <span className="ml-1">⚠️</span>}
                  {c.is_stale && <span className="ml-1 text-gray-400">🗑️</span>}
                  {c.item_name}
                </td>
                <td className="px-3 py-2 text-end font-mono font-bold text-blue-700">{c.avg_cost.toFixed(2)}</td>
                <td className="px-3 py-2 text-end font-mono text-gray-500">{c.old_cost.toFixed(2)}</td>
                <td className="px-3 py-2 text-end font-mono font-medium">{c.new_cost.toFixed(2)}</td>
                <td className={`px-3 py-2 text-end font-mono font-medium ${c.direction === 'up' ? 'text-red-600' : c.direction === 'down' ? 'text-green-600' : ''}`}>
                  {c.delta > 0 ? '+' : ''}{c.delta.toFixed(2)}
                </td>
                <td className={`px-3 py-2 text-end font-mono font-medium ${c.direction === 'up' ? 'text-red-600' : c.direction === 'down' ? 'text-green-600' : ''}`}>
                  {c.delta_pct > 0 ? '+' : ''}{c.delta_pct}%
                </td>
                <td className="px-3 py-2 text-end text-gray-500 text-xs">{c.warehouse || '—'}</td>
                <td className="px-3 py-2 text-end text-gray-400 text-xs">{c.date ? new Date(c.date).toLocaleDateString('ar-EG') : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {visibleChanges.length === 0 && (
          <div className="p-8 text-center text-gray-400">لا توجد تغيرات في الأسعار في هذه الفترة</div>
        )}
      </div>
    </>
  );
}

function CostImpactTab({ data }: { data: any }) {
  if (!data) return <Loading />;
  const totalDelta = data.total_delta || 0;
  return (
    <>
      <div className={`rounded-xl p-5 shadow ${totalDelta >= 0 ? 'bg-gradient-to-r from-red-600 to-orange-500' : 'bg-gradient-to-r from-green-600 to-teal-500'} text-white`}>
        <div className="text-sm opacity-80">إجمالي أثر تغيرات الأسعار على المنيوهات</div>
        <div className="text-3xl font-bold mt-1">
          {totalDelta >= 0 ? '+' : ''}{totalDelta.toLocaleString()} ج
        </div>
        <div className="text-xs opacity-70 mt-0.5">
          {totalDelta >= 0 ? '🔺 زيادة في التكاليف' : '🟢 توفير في التكاليف'}
        </div>
      </div>

      {data.impacts.map((imp: any, i: number) => (
        <div key={i} className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <div className={`px-4 py-2.5 border-b font-medium text-sm flex items-center justify-between ${
            imp.direction === 'up' ? 'bg-red-50 border-red-100 text-red-700' : 'bg-green-50 border-green-100 text-green-700'
          }`}>
            <span>
              {imp.direction === 'up' ? '🔴' : '🟢'} {imp.ingredient_name}
              {' · قديم '}{imp.old_cost} → {'جديد '}{imp.new_cost} ج
              {' · متوسط '}
              <span className="text-blue-700 font-bold">{imp.avg_cost || '—'}</span> ج
              {' ('}{imp.delta_pct > 0 ? '+' : ''}{imp.delta_pct}%)
              {imp.is_unusual && <span className="mr-2 text-xs bg-white px-1.5 py-0.5 rounded-full">⚠️ غير طبيعي</span>}
            </span>
            <span className="text-xs opacity-70">أثر على {imp.recipes_affected} ريسيبي</span>
          </div>
          {imp.recipes.length > 0 && (
            <table className="w-full text-sm">
              <thead><tr className="text-xs text-gray-500 border-b border-gray-50">
                <th className="px-3 py-2 text-start font-medium">الريسيبي</th>
                <th className="px-3 py-2 text-end font-medium">التكلفة القديمة</th>
                <th className="px-3 py-2 text-end font-medium">التكلفة الجديدة</th>
                <th className="px-3 py-2 text-end font-medium">الفرق</th>
                <th className="px-3 py-2 text-end font-medium">%</th>
              </tr></thead>
              <tbody className="divide-y divide-gray-50">
                {imp.recipes.map((r: any, j: number) => (
                  <tr key={j} className="hover:bg-gray-50/30">
                    <td className="px-3 py-2 font-medium text-gray-800">{r.recipe_name}</td>
                    <td className="px-3 py-2 text-end font-mono">{r.old_line_total.toFixed(2)} ج</td>
                    <td className="px-3 py-2 text-end font-mono">{r.new_line_total.toFixed(2)} ج</td>
                    <td className={`px-3 py-2 text-end font-mono font-medium ${r.delta >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                      {r.delta >= 0 ? '+' : ''}{r.delta.toFixed(2)} ج
                    </td>
                    <td className={`px-3 py-2 text-end font-mono font-medium ${r.delta >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                      {r.delta_pct >= 0 ? '+' : ''}{r.delta_pct}%
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      ))}

      {data.impacts.length === 0 && (
        <div className="bg-white border border-gray-100 rounded-xl p-8 text-center text-gray-400">
          ✅ لا توجد تغيرات في الأسعار أثرت على المنيوهات مؤخراً
        </div>
      )}
    </>
  );
}

function StockValueTab({ data }: { data: any }) {
  if (!data) return <Loading />;
  return (
    <>
      <div className="bg-gradient-to-r from-emerald-600 to-emerald-500 rounded-xl p-6 shadow text-white">
        <div className="text-sm opacity-80">💰 إجمالي قيمة المخزون</div>
        <div className="text-4xl font-bold mt-2">{data.total_value.toLocaleString()} ج</div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {data.warehouses.map((wh: any) => (
          <div key={wh.warehouse_id} className="bg-white border border-gray-100 rounded-xl p-5 shadow-sm">
            <div className="flex items-center justify-between">
              <div>
                <div className="font-bold text-gray-800">{wh.name}</div>
                <div className="text-xs text-gray-400 mt-0.5">
                  {wh.type === 'main' ? '🏭 رئيسي' : wh.type === 'branch' ? '🏪 فرع' : '🏗️ فرعي'}
                  {' · '}{wh.items_count} صنف
                </div>
              </div>
              <div className="text-left">
                <div className="text-xl font-bold text-emerald-600">{wh.total_value.toLocaleString()} ج</div>
                <div className="text-xs text-gray-400">{wh.pct}% من الإجمالي</div>
              </div>
            </div>
            <div className="mt-3 bg-gray-100 rounded-full h-2 overflow-hidden">
              <div className="bg-emerald-500 h-full rounded-full transition-all" style={{ width: `${wh.pct}%` }} />
            </div>
          </div>
        ))}
      </div>
    </>
  );
}

function ParetoTab() {
  const [menuId, setMenuId] = useState<string>('');
  const [branchId, setBranchId] = useState<string>('');

  const { data: warehouses = [] } = useQuery({
    queryKey: ['wh-all'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });
  const branches = warehouses.filter((w: any) => w.type === 'branch');

  const { data: menus = [] } = useQuery({
    queryKey: ['menu-menus-pareto', branchId],
    queryFn: () => api.get('/menu-engineering/menus', { params: { branch_id: branchId } }).then((r) => r.data),
    enabled: !!branchId,
  });

  const { data: pareto, isLoading } = useQuery({
    queryKey: ['analytics-pareto', menuId, branchId],
    queryFn: () => api.get('/menu-engineering/analytics/cost-contribution', {
      params: { menu_id: menuId || undefined, branch_id: branchId || undefined },
    }).then((r) => r.data),
  });

  return (
    <>
      <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm flex gap-3">
        <select value={branchId} onChange={(e) => { setBranchId(e.target.value); setMenuId(''); }}
          className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none flex-1">
          <option value="">-- كل الفروع --</option>
          {branches.map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
        <select value={menuId} onChange={(e) => setMenuId(e.target.value)}
          className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none flex-1">
          <option value="">-- كل المنيوهات --</option>
          {menus.map((m: any) => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
      </div>

      {isLoading && <Loading />}

      {pareto?.recipes?.map((recipe: any, i: number) => (
        <div key={recipe.recipe_id || i} className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <div className="bg-gray-50 px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <div className="font-bold text-gray-700">
              📋 {recipe.recipe_name}
              <span className="mr-3 text-sm font-normal text-gray-400">
                التكلفة: {recipe.total_cost.toFixed(2)} ج · البيع: {recipe.selling_price.toFixed(2)} ج · FC: {recipe.food_cost_pct}%
              </span>
            </div>
            {recipe.food_cost_pct > 35 && (
              <span className="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full">⚠️ مرتفع</span>
            )}
          </div>

          <table className="w-full text-sm">
            <thead><tr className="text-xs text-gray-500 border-b border-gray-50">
              <th className="px-3 py-2 text-center font-medium w-8">Group</th>
              <th className="px-3 py-2 text-start font-medium">المكون</th>
              <th className="px-3 py-2 text-end font-medium">التكلفة</th>
              <th className="px-3 py-2 text-end font-medium">%</th>
              <th className="px-3 py-2 text-end font-medium">تراكمي %</th>
              <th className="px-3 py-2 text-end font-medium">الشريط</th>
            </tr></thead>
            <tbody className="divide-y divide-gray-50">
              {recipe.ingredients.map((ing: any, j: number) => (
                <tr key={j} className={`hover:bg-gray-50/30 ${ing.pareto_group === 'A' ? 'bg-red-50/30' : ing.pareto_group === 'B' ? 'bg-amber-50/30' : ''}`}>
                  <td className="px-3 py-2 text-center">
                    <span className={`text-xs font-bold px-1.5 py-0.5 rounded ${
                      ing.pareto_group === 'A' ? 'bg-red-100 text-red-700' :
                      ing.pareto_group === 'B' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500'
                    }`}>{ing.pareto_group}</span>
                  </td>
                  <td className="px-3 py-2 font-medium text-gray-800">{ing.ingredient_name}</td>
                  <td className="px-3 py-2 text-end font-mono">{ing.line_total.toFixed(2)} ج</td>
                  <td className="px-3 py-2 text-end font-mono font-medium">{ing.pct}%</td>
                  <td className="px-3 py-2 text-end font-mono text-gray-500">{ing.cumulative_pct}%</td>
                  <td className="px-3 py-2">
                    <div className="bg-gray-100 rounded-full h-3 overflow-hidden">
                      <div className={`h-full rounded-full ${
                        ing.pareto_group === 'A' ? 'bg-red-500' :
                        ing.pareto_group === 'B' ? 'bg-amber-500' : 'bg-green-500'
                      }`} style={{ width: `${Math.min(ing.pct, 100)}%` }} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}

      {pareto?.recipes?.length === 0 && (
        <div className="bg-white border border-gray-100 rounded-xl p-8 text-center text-gray-400">
          لا توجد ريسيبيات نشطة — اختر فرعاً أو منيو
        </div>
      )}
    </>
  );
}

function Loading() {
  return <div className="text-center text-gray-400 py-12">جاري التحميل...</div>;
}
