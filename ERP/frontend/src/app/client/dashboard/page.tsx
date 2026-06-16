'use client';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import KpiCard from '@/app/(app)/dashboard/components/KpiCard';
import {
  DiffPieChart,
  TopDiffItems,
  TrendChart,
} from '@/app/(app)/dashboard/components/DashboardCharts';
import { PageContainer } from '@/components/ui/PageContainer';
import { motion } from 'framer-motion';
import { ShoppingCart, Package, AlertTriangle, Warehouse, UtensilsCrossed, Activity } from 'lucide-react';

const badge = (type: string) => {
  const m: Record<string, string> = {
    purchase: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    dispatch: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    opening: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
  };
  return m[type] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
};

const typeLabel: Record<string, string> = {
  purchase: 'مشتريات', dispatch: 'صرف', opening: 'رصيد افتتاحي', production: 'إنتاج',
};

const containerVariants = {
  hidden: { opacity: 0 },
  show: { opacity: 1, transition: { staggerChildren: 0.08 } },
};

const itemVariants = {
  hidden: { opacity: 0, y: 20 },
  show: { opacity: 1, y: 0, transition: { duration: 0.4 } },
};

export default function ClientDashboardPage() {
  const { currentClient } = useAuthStore();
  const month = new Date().toISOString().slice(0, 7);

  const { data: kpis, isLoading: kpisLoading } = useQuery({
    queryKey: ['client-dashboard-kpis', month],
    queryFn: () => api.get('/client/dashboard/kpis', { params: { month } }).then((r) => r.data),
  });

  const { data: pieData, isLoading: pieLoading } = useQuery({
    queryKey: ['client-dashboard-stock-distribution', month],
    queryFn: () => api.get('/client/dashboard/stock-distribution', { params: { month } }).then((r) => r.data),
  });

  const { data: trendData, isLoading: trendLoading } = useQuery({
    queryKey: ['client-dashboard-monthly-trend'],
    queryFn: () => api.get('/client/dashboard/monthly-trend').then((r) => r.data),
  });

  const { data: topItems, isLoading: topLoading } = useQuery({
    queryKey: ['client-dashboard-top-diff-items', month],
    queryFn: () => api.get('/client/dashboard/top-diff-items', { params: { month } }).then((r) => r.data),
  });

  const sparklinePurchases = trendData?.map((m: any) => ({ value: m.purchases })) ?? [];
  const sparklineDiffs = trendData?.map((m: any) => ({ value: Math.abs(m.diffs) })) ?? [];

  const { data: alerts } = useQuery({
    queryKey: ['client-dashboard-alerts'],
    queryFn: () => api.get('/client/dashboard/alerts').then((r) => r.data),
  });

  const { data: menu } = useQuery({
    queryKey: ['client-dashboard-menu-snapshot'],
    queryFn: () => api.get('/client/dashboard/menu-snapshot').then((r) => r.data),
  });

  const { data: recent } = useQuery({
    queryKey: ['client-dashboard-recent-activity'],
    queryFn: () => api.get('/client/dashboard/recent-activity').then((r) => r.data),
  });

  const { data: smart } = useQuery({
    queryKey: ['client-dashboard-smart-summary'],
    queryFn: () => api.get('/client/dashboard/smart-summary').then((r) => r.data),
  });

  return (
    <PageContainer className="flex-1 overflow-y-auto p-6">
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5 }}
        className="relative mb-8 overflow-hidden rounded-2xl bg-gradient-to-l from-[var(--client-primary)] via-[var(--client-primary)]/80 to-blue-50 dark:to-gray-900 p-6 shadow-lg"
      >
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_left,rgba(255,255,255,0.15),transparent_60%)]" />
        <h1 className="text-2xl font-bold text-white relative">
          {currentClient?.name ?? 'لوحة المعلومات'}
        </h1>
        <p className="text-sm text-white/80 mt-1 relative">
          ملخص الأداء الشهري
        </p>
      </motion.div>

      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="show"
        className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6"
      >
        <motion.div variants={itemVariants}>
          <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 p-[1px]">
            <div className="rounded-xl bg-white dark:bg-gray-900 h-full">
              <KpiCard
                icon={<ShoppingCart size={16} />}
                iconBg="bg-blue-50 dark:bg-blue-900/30"
                iconColor="text-blue-600 dark:text-blue-400"
                label="إجمالي المشتريات"
                value={kpis?.monthly_purchases}
                unit="ج.م"
                change={kpis?.purchases_change}
                sparklineData={sparklinePurchases}
                isLoading={kpisLoading}
              />
            </div>
          </div>
        </motion.div>
        <motion.div variants={itemVariants}>
          <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 p-[1px]">
            <div className="rounded-xl bg-white dark:bg-gray-900 h-full">
              <KpiCard
                icon={<Package size={16} />}
                iconBg="bg-emerald-50 dark:bg-emerald-900/30"
                iconColor="text-emerald-600 dark:text-emerald-400"
                label="قيمة المخزون"
                value={kpis?.stock_value}
                unit="ج.م"
                change={null}
                isLoading={kpisLoading}
              />
            </div>
          </div>
        </motion.div>
        <motion.div variants={itemVariants}>
          <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 p-[1px]">
            <div className="rounded-xl bg-white dark:bg-gray-900 h-full">
              <KpiCard
                icon={<AlertTriangle size={16} />}
                iconBg="bg-amber-50 dark:bg-amber-900/30"
                iconColor="text-amber-600 dark:text-amber-400"
                label="إجمالي الفروق"
                value={kpis?.total_diffs ? Math.abs(kpis.total_diffs) : 0}
                unit="ج.م"
                change={null}
                sparklineData={sparklineDiffs}
                isLoading={kpisLoading}
              />
            </div>
          </div>
        </motion.div>
        <motion.div variants={itemVariants}>
          <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-purple-500 to-purple-700 p-[1px]">
            <div className="rounded-xl bg-white dark:bg-gray-900 h-full">
              <KpiCard
                icon={<Warehouse size={16} />}
                iconBg="bg-purple-50 dark:bg-purple-900/30"
                iconColor="text-purple-600 dark:text-purple-400"
                label="المخازن"
                value={kpis?.warehouse_count}
                unit="مخزن"
                change={null}
                isLoading={kpisLoading}
              />
            </div>
          </div>
        </motion.div>
      </motion.div>

      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="show"
        className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6"
      >
        {[
          { href: '/client/analytics', label: 'إنذارات المخزون', value: smart?.critical_count ?? 0, sub: 'صنف حرج', color: '#ef4444', bg: 'from-red-500/10 to-red-600/5' },
          { href: '/client/analytics', label: 'قيمة المخزون', value: `${(smart?.stock_value ?? 0).toLocaleString()} ج`, sub: 'إجمالي', color: '#10b981', bg: 'from-emerald-500/10 to-emerald-600/5' },
          { href: '/client/analytics', label: 'تغيرات الأسعار', value: smart?.recent_price_changes?.length ?? 0, sub: 'تغيير حديث', color: '#f59e0b', bg: 'from-amber-500/10 to-amber-600/5' },
          { href: '/client/analytics', label: 'المشتريات', value: smart?.monthly_purchase_count ?? 0, sub: 'فاتورة هذا الشهر', color: '#3b82f6', bg: 'from-blue-500/10 to-blue-600/5' },
        ].map((card) => (
          <motion.div key={card.label} variants={itemVariants}>
            <Link
              href={card.href}
              className={`relative block rounded-xl border border-gray-100 dark:border-gray-800 p-4 shadow-sm hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5 overflow-hidden bg-gradient-to-br ${card.bg} backdrop-blur-sm`}
            >
              <div className="absolute inset-0 bg-white/70 dark:bg-gray-900/70 backdrop-blur-sm" />
              <div className="relative">
                <div className="text-xs text-gray-400 mb-1">{card.label}</div>
                <div className="text-lg font-bold" style={{ color: card.color }}>{card.value}</div>
                <div className="text-xs text-gray-400 mt-0.5">{card.sub}</div>
              </div>
            </Link>
          </motion.div>
        ))}
      </motion.div>

      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="show"
        className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6"
      >
        <motion.div variants={itemVariants}><DiffPieChart data={pieData} isLoading={pieLoading} /></motion.div>
        <motion.div variants={itemVariants}><TopDiffItems data={topItems} isLoading={topLoading} /></motion.div>
      </motion.div>

      <motion.div variants={itemVariants} initial="hidden" animate="show">
        <TrendChart data={trendData} isLoading={trendLoading} />
      </motion.div>

      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="show"
        className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6"
      >
        <motion.div variants={itemVariants}>
          <div className="rounded-2xl border border-gray-100 dark:border-gray-800 p-4 shadow-sm backdrop-blur-sm bg-white/80 dark:bg-gray-900/80">
            <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-3 flex items-center gap-2">
              <AlertTriangle size={16} className="text-red-500" />
              إنذارات المخزون
            </h3>
            {!alerts ? (
              <div className="text-gray-400 text-sm">جاري التحميل...</div>
            ) : (
              <div className="space-y-2 text-sm">
                {alerts.summary?.out_of_stock_count > 0 && (
                  <div className="flex items-center justify-between p-2 bg-red-50 dark:bg-red-900/20 rounded-xl">
                    <span className="text-red-700 dark:text-red-300">نفد بالكامل</span>
                    <span className="font-bold text-red-600">{alerts.summary.out_of_stock_count}</span>
                  </div>
                )}
                {alerts.summary?.critical_count > 0 && (
                  <div className="flex items-center justify-between p-2 bg-amber-50 dark:bg-amber-900/20 rounded-xl">
                    <span className="text-amber-700 dark:text-amber-300">أقل من الحد الأدنى</span>
                    <span className="font-bold text-amber-600">{alerts.summary.critical_count}</span>
                  </div>
                )}
                {alerts.summary?.warning_count > 0 && (
                  <div className="flex items-center justify-between p-2 bg-yellow-50 dark:bg-yellow-900/20 rounded-xl">
                    <span className="text-yellow-700 dark:text-yellow-300">يقترب من الحد</span>
                    <span className="font-bold text-yellow-600">{alerts.summary.warning_count}</span>
                  </div>
                )}
                {(!alerts.summary || (alerts.summary.out_of_stock_count + alerts.summary.critical_count + alerts.summary.warning_count) === 0) && (
                  <div className="text-emerald-600 dark:text-emerald-400">جميع الأصناف ضمن الحدود الآمنة</div>
                )}
              </div>
            )}
          </div>
        </motion.div>

        <motion.div variants={itemVariants}>
          <div className="rounded-2xl border border-gray-100 dark:border-gray-800 p-4 shadow-sm backdrop-blur-sm bg-white/80 dark:bg-gray-900/80">
            <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-3 flex items-center gap-2">
              <UtensilsCrossed size={16} className="text-emerald-500" />
              قائمة الطعام
            </h3>
            {!menu ? (
              <div className="text-gray-400 text-sm">جاري التحميل...</div>
            ) : menu.total_recipes === 0 ? (
              <div className="text-gray-400 text-sm">لا توجد وصفات نشطة</div>
            ) : (
              <div className="space-y-2 text-sm">
                <div className="flex items-center justify-between text-emerald-600 dark:text-emerald-400">
                  <span>الأعلى ربحية</span>
                  <span className="text-xs text-gray-400">تكلفة</span>
                </div>
                {menu.most_profitable?.slice(0, 3).map((r: any, i: number) => (
                  <div key={i} className="flex items-center justify-between p-1.5 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg">
                    <span className="truncate max-w-[160px]">{r.name}</span>
                    <span className="font-mono text-xs">{r.fc_pct}%</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </motion.div>

        <motion.div variants={itemVariants}>
          <div className="rounded-2xl border border-gray-100 dark:border-gray-800 p-4 shadow-sm backdrop-blur-sm bg-white/80 dark:bg-gray-900/80">
            <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-3 flex items-center gap-2">
              <Activity size={16} className="text-blue-500" />
              آخر النشاطات
            </h3>
            {!recent ? (
              <div className="text-gray-400 text-sm">جاري التحميل...</div>
            ) : recent.length === 0 ? (
              <div className="text-gray-400 text-sm">لا توجد نشاطات</div>
            ) : (
              <div className="space-y-2 text-sm">
                {recent.map((r: any, i: number) => (
                  <div key={i} className="flex items-center justify-between p-1.5 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div className="flex items-center gap-2 min-w-0">
                      <span className={`px-1.5 py-0.5 rounded text-[10px] font-medium ${badge(r.voucher_type)}`}>
                        {typeLabel[r.voucher_type] ?? r.voucher_type}
                      </span>
                      <span className="truncate max-w-[100px] text-gray-700 dark:text-gray-300">{r.item_name}</span>
                    </div>
                    <span className="font-mono text-xs text-gray-500">{r.date}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </motion.div>
      </motion.div>
    </PageContainer>
  );
}
