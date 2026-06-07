'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useAuthStore } from '@/lib/store';
import { ShoppingCart, Truck, AlertTriangle, Package, Store, AlertCircle, TrendingUp, ClipboardList } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import KpiCard from './components/KpiCard';
import WarehouseTable from './components/WarehouseTable';
import BranchTable from './components/BranchTable';
import { DiffPieChart, TopDiffItems, TrendChart } from './components/DashboardCharts';

export default function DashboardPage() {
  const { currentClient } = useAuthStore();
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));

  const { data: kpis, isLoading: kpiLoading } = useQuery({
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

  const { data: smart } = useQuery({
    queryKey: ['smart-summary', currentClient?.id],
    queryFn: () => api.get('/dashboard/smart-summary').then((r) => r.data),
    enabled: !!currentClient,
  });

  const { data: diffsByWh } = useQuery({
    queryKey: ['diffs-by-wh', currentClient?.id, month],
    queryFn: () => api.get('/dashboard/diffs-by-warehouse', { params: { month } }).then((r) => r.data),
    enabled: !!currentClient,
  });

  const { data: topItems } = useQuery({
    queryKey: ['top-diff-items', currentClient?.id, month],
    queryFn: () => api.get('/dashboard/top-diff-items', { params: { month } }).then((r) => r.data),
    enabled: !!currentClient,
  });

  const branches = warehouses.filter((w: any) => w.type === 'branch');
  const mainSub = warehouses.filter((w: any) => w.type !== 'branch');

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
          <div className="flex items-center gap-2">
            <input type="month" value={month} onChange={(e) => setMonth(e.target.value)}
              className="border border-gray-200 rounded-lg px-2 py-1 text-xs outline-none" />
            <button
              onClick={() => downloadExport(`${api.defaults.baseURL}/dashboard/export?month=${month}`, `مؤشرات_${month}.xlsx`)}
              className="text-xs text-blue-600 hover:underline bg-transparent border-none cursor-pointer"
            >
              تصدير إكسيل
            </button>
          </div>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6" dir="rtl">
        {/* KPI Cards */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <KpiCard
            icon={<ShoppingCart size={16} />}
            iconBg="bg-blue-50" iconColor="text-blue-600"
            label="إجمالي المشتريات"
            value={kpis?.total_purchases}
            unit="ج"
            change={kpis?.purchases_change}
            changeLabel="عن السابق"
            sparklineData={trend?.map((t: any) => ({ value: t.purchases }))?.reverse()}
            isLoading={kpiLoading}
          />
          <KpiCard
            icon={<Truck size={16} />}
            iconBg="bg-gray-50" iconColor="text-gray-600"
            label="قيمة المنصرف"
            value={kpis?.total_dispatched}
            unit="ج"
            change={kpis?.dispatched_change}
            sparklineData={trend?.map((t: any) => ({ value: t.dispatched }))?.reverse()}
            isLoading={kpiLoading}
          />
          <KpiCard
            icon={<AlertTriangle size={16} />}
            iconBg={(kpis?.total_diffs ?? 0) < 0 ? 'bg-red-50' : 'bg-green-50'}
            iconColor={(kpis?.total_diffs ?? 0) < 0 ? 'text-red-600' : 'text-green-600'}
            label="إجمالي الفروق"
            value={kpis?.total_diffs}
            unit="ج"
            change={kpis?.diffs_change}
            sparklineData={trend?.map((t: any) => ({ value: t.diffs }))?.reverse()}
            isLoading={kpiLoading}
          />
          <Card className="border-gray-100 shadow-sm hover:shadow-md transition-shadow">
            <CardContent className="p-4">
              <div className="flex items-start justify-between">
                <div className="w-9 h-9 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                  <Package size={16} />
                </div>
              </div>
              <div className="mt-3">
                <div className="text-xs text-gray-500">قيمة المخزون</div>
                <div className="text-xl font-bold mt-0.5 font-mono tracking-tight">
                  {kpiLoading ? (
                    <span className="text-gray-300">...</span>
                  ) : (
                    <>{((kpis?.total_stock_value_warehouses ?? 0) + (kpis?.total_stock_value_branches ?? 0)).toLocaleString('en-US')} <span className="text-xs font-normal text-gray-400 mr-0.5">ج</span></>
                  )}
                </div>
              </div>
              <div className="mt-2 pt-2 border-t border-gray-100 flex justify-between text-[11px]">
                <div className="flex items-center gap-1 text-gray-500">
                  <Store size={12} />
                  <span>المخازن</span>
                  <span className="font-mono font-medium text-gray-700">{kpis?.total_stock_value_warehouses?.toLocaleString('en-US') ?? '—'}</span>
                </div>
                <div className="flex items-center gap-1 text-gray-500">
                  <Store size={12} />
                  <span>الفروع</span>
                  <span className="font-mono font-medium text-gray-700">{kpis?.total_stock_value_branches?.toLocaleString('en-US') ?? '—'}</span>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Smart Analytics Widgets */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <a href="/menu-engineering/analytics" className="flex items-center gap-3 bg-red-50 border border-red-200 rounded-xl p-3 shadow-sm hover:shadow-md transition-shadow">
            <div className="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center text-red-600">
              <AlertCircle size={16} />
            </div>
            <div>
              <div className="text-[11px] text-red-600 font-medium">إنذار المخزون</div>
              <div className="text-lg font-bold text-red-700">{smart?.critical_count ?? '...'}</div>
              <div className="text-[10px] text-red-400">حرج · {smart?.warning_count ?? 0} إنذار</div>
            </div>
          </a>
          <a href="/menu-engineering/analytics" className="flex items-center gap-3 bg-orange-50 border border-orange-200 rounded-xl p-3 shadow-sm hover:shadow-md transition-shadow">
            <div className="w-8 h-8 rounded-lg bg-orange-100 flex items-center justify-center text-orange-600">
              <TrendingUp size={16} />
            </div>
            <div>
              <div className="text-[11px] text-orange-600 font-medium">تغيرات أسعار</div>
              <div className="text-lg font-bold text-orange-700">{smart?.recent_price_changes?.length ?? '...'}</div>
              <div className="text-[10px] text-orange-400">آخر تغير</div>
            </div>
          </a>
          <a href="/menu-engineering/analytics" className="flex items-center gap-3 bg-emerald-50 border border-emerald-200 rounded-xl p-3 shadow-sm hover:shadow-md transition-shadow">
            <div className="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-600">
              <Package size={16} />
            </div>
            <div>
              <div className="text-[11px] text-emerald-600 font-medium">قيمة المخزون</div>
              <div className="text-lg font-bold text-emerald-700">{smart?.stock_value ? `${(smart.stock_value / 1000).toFixed(1)}k` : '...'}</div>
              <div className="text-[10px] text-emerald-400">جنيه</div>
            </div>
          </a>
          <a href="/menu-engineering/analytics" className="flex items-center gap-3 bg-blue-50 border border-blue-200 rounded-xl p-3 shadow-sm hover:shadow-md transition-shadow">
            <div className="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center text-blue-600">
              <ClipboardList size={16} />
            </div>
            <div>
              <div className="text-[11px] text-blue-600 font-medium">مشتريات الشهر</div>
              <div className="text-lg font-bold text-blue-700">{smart?.monthly_purchase_count ?? '...'}</div>
              <div className="text-[10px] text-blue-400">فاتورة شراء</div>
            </div>
          </a>
        </div>

        {/* Charts Row */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <DiffPieChart data={diffsByWh} isLoading={false} />
          <TopDiffItems data={topItems} isLoading={false} />
        </div>

        {/* Trend Chart */}
        <TrendChart data={trend} isLoading={false} />

        {/* Warehouse + Branch Tables */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <WarehouseTable data={mainSub} isLoading={false} />
          <BranchTable data={branches} isLoading={false} />
        </div>
      </div>
    </>
  );
}
