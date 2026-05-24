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

  const [selectedWarehouses, setSelectedWarehouses] = useState<string[]>([]);

  const { data: warehouses } = useQuery({
    queryKey: ['wh-list'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const inventoryWh = (warehouses || []).filter((w: any) => w.type !== 'branch');

  // ── Inventory Alerts ──
  const { data: alerts } = useQuery({
    queryKey: ['analytics-inventory', selectedWarehouses],
    queryFn: () => api.get('/menu-engineering/analytics/inventory-alerts', {
      params: selectedWarehouses.length ? { warehouse_ids: selectedWarehouses.join(',') } : {} }
    ).then((r) => r.data),
  });

  // ── Top Purchases ──
  const [purchasesWhId, setPurchasesWhId] = useState('');
  const [purchasesMonth, setPurchasesMonth] = useState('');
  const { data: purchases } = useQuery({
    queryKey: ['analytics-purchases', limit, purchasesWhId, purchasesMonth],
    queryFn: () => api.get('/menu-engineering/analytics/top-purchases', {
      params: { limit, warehouse_id: purchasesWhId || undefined, from: purchasesMonth ? `${purchasesMonth}-01` : undefined, to: purchasesMonth ? `${purchasesMonth}-31` : undefined },
    }).then((r) => r.data),
  });

  // ── Price Changes ──
  const [pricesMonth, setPricesMonth] = useState('');
  const { data: prices } = useQuery({
    queryKey: ['analytics-prices', threshold, pricesMonth],
    queryFn: () => api.get('/menu-engineering/analytics/price-changes', {
      params: { threshold, from: pricesMonth ? `${pricesMonth}-01` : undefined, to: pricesMonth ? `${pricesMonth}-31` : undefined },
    }).then((r) => r.data),
  });

  // ── Cost Impact ──
  const [costImpactMonth, setCostImpactMonth] = useState('');
  const { data: costImpact } = useQuery({
    queryKey: ['analytics-cost-impact', costImpactMonth],
    queryFn: () => api.get('/menu-engineering/analytics/cost-impact', {
      params: { from: costImpactMonth ? `${costImpactMonth}-01` : undefined, to: costImpactMonth ? `${costImpactMonth}-31` : undefined },
    }).then((r) => r.data),
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
          {activeTab === 'prices' && (
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
          <>
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-xs text-gray-500 font-medium">المخازن:</span>
              {inventoryWh.map((wh: any) => {
                const active = selectedWarehouses.includes(wh.id);
                return (
                  <button key={wh.id} onClick={() => setSelectedWarehouses(prev =>
                    active ? prev.filter((id: string) => id !== wh.id) : [...prev, wh.id]
                  )} className={`px-3 py-1 text-xs rounded-full border transition-colors ${
                    active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400'
                  }`}>
                    {wh.name}
                  </button>
                );
              })}
              {selectedWarehouses.length > 0 && (
                <button onClick={() => setSelectedWarehouses([])} className="px-2 py-1 text-xs text-gray-400 hover:text-gray-600">
                  ✕ الكل
                </button>
              )}
            </div>
            <InventoryAlertsTab data={alerts} />
          </>
        )}
        {activeTab === 'purchases' && (
          <>
            <div className="bg-white border border-gray-100 rounded-xl p-3 shadow-sm flex items-center gap-3 flex-wrap">
              <span className="text-xs text-gray-400 font-medium">تصفية:</span>
              <select value={purchasesWhId} onChange={(e) => setPurchasesWhId(e.target.value)}
                className="border border-gray-200 rounded-lg px-3 py-2 text-xs outline-none bg-white">
                <option value="">كل المخازن</option>
                {warehouses?.filter((w: any) => w.type !== 'branch').map((w: any) => (
                  <option key={w.id} value={w.id}>{w.name}</option>
                ))}
              </select>
              <input type="month" value={purchasesMonth} onChange={(e) => setPurchasesMonth(e.target.value)}
                className="border border-gray-200 rounded-lg px-3 py-2 text-xs outline-none" />
              {(purchasesWhId || purchasesMonth) && (
                <button onClick={() => { setPurchasesWhId(''); setPurchasesMonth(''); }}
                  className="text-xs text-gray-400 hover:text-red-500 px-2 py-1 rounded hover:bg-red-50">✕ مسح</button>
              )}
            </div>
            <TopPurchasesTab data={purchases} />
          </>
        )}
        {activeTab === 'prices' && (
          <>
            <div className="bg-white border border-gray-100 rounded-xl p-3 shadow-sm flex items-center gap-3 flex-wrap">
              <span className="text-xs text-gray-400 font-medium">تصفية:</span>
              <input type="month" value={pricesMonth} onChange={(e) => setPricesMonth(e.target.value)}
                className="border border-gray-200 rounded-lg px-3 py-2 text-xs outline-none" />
              <label className="text-xs text-gray-400">Threshold:</label>
              <input type="number" value={threshold} onChange={(e) => setThreshold(e.target.value)}
                className="border border-gray-200 rounded-lg px-3 py-2 text-xs outline-none w-20" />%
              {pricesMonth && (
                <button onClick={() => setPricesMonth('')}
                  className="text-xs text-gray-400 hover:text-red-500 px-2 py-1 rounded hover:bg-red-50">✕ مسح</button>
              )}
            </div>
            <PriceChangesTab data={prices} />
          </>
        )}
        {activeTab === 'cost-impact' && (
          <>
            <div className="bg-white border border-gray-100 rounded-xl p-3 shadow-sm flex items-center gap-3 flex-wrap">
              <span className="text-xs text-gray-400 font-medium">تصفية بالشهر:</span>
              <input type="month" value={costImpactMonth} onChange={(e) => setCostImpactMonth(e.target.value)}
                className="border border-gray-200 rounded-lg px-3 py-2 text-xs outline-none" />
              {costImpactMonth && (
                <button onClick={() => setCostImpactMonth('')}
                  className="text-xs text-gray-400 hover:text-red-500 px-2 py-1 rounded hover:bg-red-50">✕ مسح</button>
              )}
            </div>
            <CostImpactTab data={costImpact} />
          </>
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

  const Bar = ({ pct }: { pct: number }) => {
    const color = pct < 25 ? 'bg-red-500' : pct < 75 ? 'bg-amber-500' : 'bg-green-500';
    return (
      <div className="bg-gray-100 rounded-full h-2.5 w-24 overflow-hidden inline-block align-middle">
        <div className={`${color} h-full rounded-full transition-all`} style={{ width: `${Math.min(pct, 100)}%` }} />
      </div>
    );
  };

  const Tables = ({ items, color, label }: { items: any[]; color: string; label: string }) => (
    <div className="bg-white border rounded-xl shadow-sm overflow-hidden">
      <div className={`px-4 py-2.5 border-b font-bold text-sm`} style={{ background: `${color}10`, borderColor: `${color}30`, color }}>
        {label}
      </div>
      <table className="w-full text-sm">
        <thead><tr className="text-xs text-gray-500 border-b border-gray-50">
          <th className="px-3 py-2 text-start font-medium">الصنف</th>
          <th className="px-3 py-2 text-start font-medium">المخزن</th>
          <th className="px-3 py-2 text-end font-medium">الرصيد</th>
          <th className="px-3 py-2 text-end font-medium">الحد</th>
          <th className="px-3 py-2 text-center font-medium">المستوى</th>
          <th className="px-3 py-2 text-end font-medium">معدل الصرف</th>
          <th className="px-3 py-2 text-end font-medium">أيام للنفاد</th>
        </tr></thead>
        <tbody className="divide-y divide-gray-50">
          {items.map((a: any, i: number) => {
            const pct = a.usage_pct ?? 0;
            const urgent = a.days_until_stockout !== null && a.days_until_stockout <= 3;
            return (
              <tr key={i} className="hover:bg-gray-50/30">
                <td className="px-3 py-2 font-medium text-gray-800">{a.item_name}</td>
                <td className="px-3 py-2 text-gray-500 text-xs">{a.warehouse_name}</td>
                <td className="px-3 py-2 text-end font-mono font-medium" style={{ color }}>{a.current_qty} {a.unit}</td>
                <td className="px-3 py-2 text-end font-mono text-gray-400">{a.min_stock_level} {a.unit}</td>
                <td className="px-3 py-2 text-center"><Bar pct={pct} /></td>
                <td className="px-3 py-2 text-end font-mono text-gray-400 text-xs">
                  {a.avg_daily_consumption > 0 ? a.avg_daily_consumption.toFixed(3) : '—'}
                </td>
                <td className="px-3 py-2 text-end">
                  {a.days_until_stockout !== null ? (
                    <span className={`font-mono font-bold text-xs ${urgent ? 'text-red-600 bg-red-50 px-1.5 py-0.5 rounded' : 'text-gray-500'}`}>
                      {urgent ? '⚠️ ' : ''}{a.days_until_stockout} يوم
                    </span>
                  ) : <span className="text-gray-300 text-xs">—</span>}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );

  return (
    <>
      {/* Summary */}
      <div className="grid grid-cols-4 gap-3">
        <div className="bg-gray-900 border border-gray-800 rounded-xl p-4 text-center">
          <div className="text-2xl font-bold text-white">{data.summary.out_of_stock_count}</div>
          <div className="text-xs text-gray-400 mt-1">⚫ منقطع (رصيد = 0)</div>
        </div>
        <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-center">
          <div className="text-2xl font-bold text-red-600">{data.summary.critical_count}</div>
          <div className="text-xs text-red-500 mt-1">🔴 حرج — أقل من الحد</div>
        </div>
        <div className="bg-amber-50 border border-amber-200 rounded-xl p-4 text-center">
          <div className="text-2xl font-bold text-amber-600">{data.summary.warning_count}</div>
          <div className="text-xs text-amber-500 mt-1">🟡 إنذار — وشك يخلص</div>
        </div>
        <div className="bg-green-50 border border-green-200 rounded-xl p-4 text-center">
          <div className="text-2xl font-bold text-green-600">{data.summary.ok_count}</div>
          <div className="text-xs text-green-500 mt-1">🟢 تمام — ضمن الآمن</div>
        </div>
      </div>

      {/* Out of Stock */}
      {data.out_of_stock?.length > 0 && (
        <Tables items={data.out_of_stock} color="#dc2626" label="⚫ منقطع — رصيد صفر" />
      )}

      {/* Critical */}
      {data.critical?.length > 0 && (
        <Tables items={data.critical} color="#ea580c" label="🔴 حرج — أقل من الحد الأدنى" />
      )}

      {/* Warning */}
      {data.warning?.length > 0 && (
        <Tables items={data.warning} color="#d97706" label="🟡 إنذار — وشك النفاد" />
      )}

      {!data.out_of_stock?.length && !data.critical?.length && !data.warning?.length && (
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
        <div className="px-4 py-3 border-b border-gray-100 font-medium text-gray-700 text-sm flex items-center gap-2">
          <span>📈 ترتيب الأصناف حسب قيمة الشراء</span>
        </div>
        <table className="w-full text-sm">
          <thead><tr className="text-xs text-gray-500 border-b border-gray-50">
            <th className="px-3 py-2 text-center font-medium">#</th>
            <th className="px-3 py-2 text-start font-medium">الصنف</th>
            <th className="px-3 py-2 text-start font-medium">التصنيف</th>
            <th className="px-3 py-2 text-end font-medium">عدد مرات الشراء</th>
            <th className="px-3 py-2 text-end font-medium">الكمية</th>
            <th className="px-3 py-2 text-end font-medium">القيمة</th>
            <th className="px-3 py-2 text-end font-medium">%</th>
            <th className="px-3 py-2 text-end font-medium">متوسط سعر الوحدة</th>
          </tr></thead>
          <tbody className="divide-y divide-gray-50">
            {data.items.map((p: any) => (
              <tr key={p.rank} className="hover:bg-gray-50/30">
                <td className="px-3 py-2 text-center text-gray-400 text-xs">{p.rank}</td>
                <td className="px-3 py-2 font-medium text-gray-800">{p.item_name}</td>
                <td className="px-3 py-2 text-gray-400 text-xs">{p.category || '—'}</td>
                <td className="px-3 py-2 text-end font-mono">{p.purchase_count}</td>
                <td className="px-3 py-2 text-end font-mono">{p.total_qty} {p.unit}</td>
                <td className="px-3 py-2 text-end font-mono text-green-700 font-medium">{p.total_value.toLocaleString()} ج</td>
                <td className="px-3 py-2 text-end">
                  <div className="flex items-center gap-2 justify-end">
                    <span className="font-mono text-xs text-gray-500">{p.contribution_pct}%</span>
                    <div className="bg-gray-100 rounded-full h-2 w-12 overflow-hidden">
                      <div className="bg-blue-500 h-full rounded-full" style={{ width: `${Math.min(p.contribution_pct, 100)}%` }} />
                    </div>
                  </div>
                </td>
                <td className="px-3 py-2 text-end font-mono text-gray-500 text-xs">
                  {p.avg_unit_price.toFixed(2)} ج/{p.unit}
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

function PriceChangesTab({ data }: { data: any }) {
  if (!data) return <Loading />;
  const isUp = (d: string) => d === 'up';
  return (
    <>
      <div className={`rounded-xl p-5 shadow text-white ${data.net_impact >= 0 ? 'bg-gradient-to-r from-red-600 to-orange-500' : 'bg-gradient-to-r from-green-600 to-teal-500'}`}>
        <div className="text-sm opacity-80">صافي التأثير المالي لتغيرات الأسعار</div>
        <div className="text-3xl font-bold mt-1">
          {data.net_impact >= 0 ? '+' : ''}{data.net_impact.toLocaleString()} ج
        </div>
        <div className="text-xs opacity-70 mt-0.5">
          {data.net_impact >= 0 ? '🔺 زيادة في التكاليف' : '🟢 توفير في التكاليف'}
          {' · '}{data.count} تغيير
        </div>
      </div>

      <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead><tr className="text-xs text-gray-500 border-b border-gray-50">
            <th className="px-3 py-2 text-start font-medium">الصنف</th>
            <th className="px-3 py-2 text-end font-medium">السعر القديم</th>
            <th className="px-3 py-2 text-end font-medium">السعر الجديد</th>
            <th className="px-3 py-2 text-end font-medium">المتوسط الحالي</th>
            <th className="px-3 py-2 text-end font-medium">%</th>
            <th className="px-3 py-2 text-end font-medium">التأثير المالي</th>
            <th className="px-3 py-2 text-end font-medium">المصدر</th>
            <th className="px-3 py-2 text-end font-medium">التاريخ</th>
          </tr></thead>
          <tbody className="divide-y divide-gray-50">
            {data.changes.map((c: any, i: number) => (
              <tr key={i} className="hover:bg-gray-50/30">
                <td className="px-3 py-2 font-medium text-gray-800">{c.item_name}</td>
                <td className="px-3 py-2 text-end font-mono text-gray-500">{c.old_cost.toFixed(2)}</td>
                <td className="px-3 py-2 text-end font-mono">
                  <span className={`font-bold ${isUp(c.direction) ? 'text-red-600' : 'text-green-600'}`}>
                    {c.new_cost.toFixed(2)}
                  </span>
                  <span className="mr-1">{isUp(c.direction) ? '🔴' : '🟢'}</span>
                </td>
                <td className="px-3 py-2 text-end font-mono font-bold text-blue-700">{c.avg_cost?.toFixed(2) ?? '—'}</td>
                <td className={`px-3 py-2 text-end font-mono font-bold ${isUp(c.direction) ? 'text-red-600' : 'text-green-600'}`}>
                  {c.delta_pct > 0 ? '+' : ''}{c.delta_pct}%
                </td>
                <td className={`px-3 py-2 text-end font-mono font-bold ${c.total_impact >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                  {c.total_impact > 0 ? '+' : ''}{c.total_impact.toLocaleString()} ج
                </td>
                <td className="px-3 py-2 text-end text-gray-500 text-xs">{c.source || '—'}</td>
                <td className="px-3 py-2 text-end text-gray-400 text-xs">{c.date ? new Date(c.date).toLocaleDateString('ar-EG') : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {data.changes.length === 0 && (
          <div className="p-8 text-center text-gray-400">لا توجد تغيرات في الأسعار تتجاوز الحد المحدد</div>
        )}
      </div>
    </>
  );
}

function CostImpactTab({ data }: { data: any }) {
  const [expandedMenu, setExpandedMenu] = useState<string | null>(null);

  if (!data) return <Loading />;
  if (!data.menus?.length) return (
    <div className="bg-white border border-gray-100 rounded-xl p-8 text-center text-gray-400">
      لا توجد منيوات نشطة
    </div>
  );

  const totalDelta = data.menus.reduce((s: number, m: any) => s + m.delta, 0);
  const totalOld = data.menus.reduce((s: number, m: any) => s + m.old_total_cost, 0);
  const totalCurrent = data.menus.reduce((s: number, m: any) => s + m.current_total_cost, 0);
  const totalSp = data.menus.reduce((s: number, m: any) => s + (m.total_selling_price || 0), 0);
  const oldFc = totalSp > 0 ? (totalOld / totalSp * 100) : 0;
  const currentFc = totalSp > 0 ? (totalCurrent / totalSp * 100) : 0;
  const isUp = (d: number) => d >= 0;

  return (
    <>
      <div className={`rounded-xl p-5 shadow text-white ${isUp(totalDelta) ? 'bg-gradient-to-r from-red-600 to-orange-500' : 'bg-gradient-to-r from-green-600 to-teal-500'}`}>
        <div className="text-sm opacity-80">نسبة التكلفة المباشرة (FC%) — إجمالي المنيوهات</div>
        <div className="text-3xl font-bold mt-1">
          {oldFc.toFixed(1)}% <span className="text-lg opacity-60 mx-1">→</span> {currentFc.toFixed(1)}%
        </div>
        <div className="text-xs opacity-70 mt-0.5">
          {isUp(totalDelta) ? '🔺 زيادة في التكاليف' : '🟢 توفير في التكاليف'}
          {' · قديم '}{totalOld.toLocaleString()} ج {' → حالي '}{totalCurrent.toLocaleString()} ج
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {data.menus.map((menu: any) => {
          const menuUp = isUp(menu.delta);
          const expanded = expandedMenu === menu.menu_id;
          return (
            <div key={menu.menu_id} className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
              <button onClick={() => setExpandedMenu(expanded ? null : menu.menu_id)}
                className="w-full text-right p-4 hover:bg-gray-50/50 transition-colors">
                <div className="flex items-start justify-between">
                  <div>
                    <div className="font-bold text-gray-800">🍽️ {menu.menu_name}</div>
                    <div className="text-xs text-gray-400 mt-0.5">{menu.recipes_count} وصفة</div>
                  </div>
                  <div className="text-left">
                    <div className={`text-lg font-bold ${menuUp ? 'text-red-600' : 'text-green-600'}`}>
                      {menuUp ? '🔴' : '🟢'} {menu.fc_delta > 0 ? '+' : ''}{menu.fc_delta}%
                      <span className="text-xs font-normal opacity-70 mr-1">FC</span>
                    </div>
                    <div className={`text-xs font-medium ${menuUp ? 'text-red-500' : 'text-green-500'}`}>
                      {menu.delta > 0 ? '+' : ''}{menu.delta.toLocaleString()} ج
                    </div>
                  </div>
                </div>
                <div className="mt-3 flex items-center gap-2 text-xs text-gray-500">
                  <span className="bg-gray-100 px-2 py-0.5 rounded">FC: {menu.old_fc_pct}%</span>
                  <span className="text-gray-300">→</span>
                  <span className={`px-2 py-0.5 rounded font-medium ${menuUp ? 'bg-red-50 text-red-600' : 'bg-green-50 text-green-600'}`}>
                    FC: {menu.current_fc_pct}%
                  </span>
                </div>
              </button>

              {expanded && (
                <div className="border-t border-gray-100 px-4 py-3 space-y-3">
                  {menu.affected_recipes?.length === 0 ? (
                    <div className="text-center text-gray-400 text-xs py-4">✅ لا توجد تغييرات في هذه المنيو</div>
                  ) : (
                    menu.affected_recipes.map((r: any, j: number) => (
                      <div key={j} className="border border-gray-100 rounded-lg overflow-hidden">
                        {/* Recipe header */}
                        <div className="flex items-center justify-between px-3 py-2 bg-gray-50/70">
                          <div className="flex items-center gap-2">
                            <span className="font-medium text-gray-800 text-sm">🍳 {r.recipe_name}</span>
                            <span className="text-xs text-gray-400">
                              FC: <span className="font-mono">{r.old_fc_pct}%</span>
                              <span className="mx-1">→</span>
                              <span className={`font-mono font-medium ${r.fc_delta >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                                {r.current_fc_pct}%
                              </span>
                            </span>
                          </div>
                          <span className={`text-xs font-medium ${r.total_impact >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                            {r.total_impact > 0 ? '+' : ''}{r.total_impact.toFixed(2)} ج
                          </span>
                        </div>
                        {/* Ingredients table */}
                        {r.ingredients?.length > 0 && (
                          <table className="w-full text-xs">
                            <thead><tr className="text-gray-400 border-b border-gray-50">
                              <th className="px-3 py-1 text-start font-medium pr-8">المكون</th>
                              <th className="px-3 py-1 text-end font-medium">قديم</th>
                              <th className="px-3 py-1 text-end font-medium">جديد</th>
                              <th className="px-3 py-1 text-end font-medium">التأثير</th>
                            </tr></thead>
                            <tbody className="divide-y divide-gray-50">
                              {r.ingredients.map((ing: any, k: number) => (
                                <tr key={k} className="hover:bg-gray-50/30">
                                  <td className="px-3 py-1 text-gray-600 pr-8">{ing.ingredient_name}</td>
                                  <td className="px-3 py-1 text-end text-gray-400 font-mono">{ing.old_unit_cost.toFixed(2)}</td>
                                  <td className={`px-3 py-1 text-end font-mono font-medium ${ing.current_unit_cost >= ing.old_unit_cost ? 'text-red-600' : 'text-green-600'}`}>
                                    {ing.current_unit_cost.toFixed(2)}
                                  </td>
                                  <td className={`px-3 py-1 text-end font-mono font-medium ${ing.delta >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                                    {ing.delta > 0 ? '+' : ''}{ing.delta.toFixed(2)} ج
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        )}
                      </div>
                    ))
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>
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
