'use client';
// src/components/ui/AppShell.tsx

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/lib/store';
import clsx from 'clsx';
import toast from 'react-hot-toast';
import { ThemeToggle } from './ThemeToggle';

const PERMISSION_MAP: Record<string, string> = {
  '/dashboard': 'dashboard',
  '/vouchers/purchase': 'vouchers.purchase',
  '/vouchers/dispatch': 'vouchers.dispatch',
  '/transfers': 'vouchers.dispatch',
  '/vouchers/upload': 'vouchers.upload',
  '/vouchers/history': 'vouchers.history',
  '/closing': 'closing',
  '/clients': 'clients',
  '/production': 'production',
  '/stock': 'stock.current',
  '/stock/movement': 'stock.movement',
  '/stock/opening': 'stock.opening',
  '/stock/closing': 'stock.closing',
  '/menu-engineering': 'menu-engineering',
  '/ocr': 'menu-engineering',
  '/reports/financial-details': 'reports.financial',
  '/reports/diffs': 'reports.diffs',
  '/reports/cost': 'reports.food-cost',
  '/items': 'items',
  '/warehouses': 'warehouses',
  '/mappings': 'mappings',
  '/users': 'users',
  '/settings': 'settings',
  '/financial/daily': 'financial.daily',
  '/financial/monthly': 'financial.monthly',
  '/financial/closing': 'financial.closing',
  '/financial/advances': 'financial.advances',
  '/payroll/employees': 'payroll.manage',
  '/payroll/attendance': 'payroll.manage',
  '/payroll/monthly': 'payroll.manage',
};

const HREF_MODULE: Record<string, string> = {
  '/dashboard': 'dashboard',
  '/vouchers/purchase': 'vouchers.purchase',
  '/vouchers/dispatch': 'vouchers.dispatch',
  '/transfers': 'vouchers.dispatch',
  '/vouchers/upload': 'vouchers.upload',
  '/vouchers/history': 'vouchers.history',
  '/closing': 'closing',
  '/stock': 'inventory',
  '/stock/movement': 'inventory',
  '/stock/opening': 'inventory',
  '/stock/closing': 'inventory',
  '/menu-engineering': 'menu-engineering',
  '/ocr': 'menu-engineering',
  '/reports/financial-details': 'reports.financial',
  '/reports/diffs': 'reports.diffs',
  '/reports/cost': 'reports.food-cost',
  '/items': 'items',
  '/mappings': 'mappings',
  '/financial/daily': 'financial.daily',
  '/financial/monthly': 'financial.monthly',
  '/financial/closing': 'financial.closing',
  '/financial/advances': 'financial.advances',
};

export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { user, currentClient, logout, switchClient } = useAuthStore();
  const qc = useQueryClient();
  const [clientMenuOpen, setClientMenuOpen] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [logoUrl, setLogoUrl] = useState<string | null>(null);
  const [modules, setModules] = useState<string[]>([]);
  const [primaryColor, setPrimaryColor] = useState('#2563eb');

  const permissions = user?.permissions ?? [];
  const hasPerm = (href: string) => {
    const perm = PERMISSION_MAP[href];
    return !perm || permissions.includes(perm);
  };
  const hasModule = (href: string) => {
    const mod = HREF_MODULE[href];
    return !mod || modules.includes(mod);
  };

  const NAV = [
    {
      section: 'الرئيسية',
      items: [
        { href: '/dashboard',    icon: '▦',  label: 'لوحة التحكم' },
      ].filter((i) => hasPerm(i.href) && hasModule(i.href)),
    },
    {
      section: 'الحركة اليومية',
      items: [
        { href: '/vouchers/purchase', icon: '📥',  label: 'وارد مخزن' },
        { href: '/vouchers/dispatch', icon: '📤',  label: 'إذن صرف' },
        { href: '/transfers',         icon: '↩',   label: 'التحويلات والمرتجعات' },
        { href: '/vouchers/upload',   icon: '☁',   label: 'رفع Excel' },
        { href: '/vouchers/history',  icon: '📋',  label: 'سجل الحركات' },
        { href: '/closing', icon: '📊', label: 'التقفيل الشهري' },
        { href: '/clients', icon: '🏢', label: 'إدارة الشركات' },
        { href: '/production',         icon: '⚙',  label: 'الإنتاج اليومي' },
      ].filter((i) => hasPerm(i.href)),
    },
    {
      section: 'المخزون',
      items: [
        { href: '/stock',         icon: '◫',  label: 'الرصيد الحالي' },
        { href: '/stock/movement',icon: '↔',  label: 'حركة الأصناف' },
        { href: '/stock/opening', icon: '⚐',  label: 'الأرصدة الافتتاحية' },
        { href: '/stock/closing', icon: '🏁',  label: 'جرد آخر المدة' },
      ].filter((i) => hasPerm(i.href) && hasModule(i.href)),
    },
    {
      section: 'هندسة القائمة',
      items: [
        { href: '/menu-engineering', icon: '📝',  label: 'Menu Engineering' },
        { href: '/ocr',              icon: '🔍',  label: 'قارئ OCR' },
      ].filter((i) => hasPerm(i.href) && hasModule(i.href)),
    },
    {
      section: 'المالية',
      items: [
        { href: '/financial/daily',    icon: '📋',  label: 'اليومية' },
        { href: '/financial/monthly',  icon: '📊',  label: 'التجميع الشهري' },
        { href: '/financial/closing',  icon: '📑',  label: 'التقفيل' },
        { href: '/financial/advances', icon: '💰',  label: 'السلف' },
      ].filter((i) => hasPerm(i.href) && hasModule(i.href)),
    },
    {
      section: 'الرواتب',
      items: [
        { href: '/payroll/employees', icon: '👤',  label: 'الموظفين' },
        { href: '/payroll/attendance', icon: '⏰',  label: 'الحضور والانصراف' },
        { href: '/payroll/monthly',  icon: '🧾',  label: 'المرتبات الشهرية' },
      ].filter((i) => hasPerm(i.href)),
    },
    {
      section: 'التقارير',
      items: [
        { href: '/reports/financial-details', icon: '₿',  label: 'التفاصيل المالية' },
        { href: '/reports/diffs', icon: '!',  label: 'الفروق والهدر' },
        { href: '/reports/cost',  icon: '%',  label: 'Food Cost %' },
      ].filter((i) => hasPerm(i.href) && hasModule(i.href)),
    },
    {
      section: 'الإعداد',
      items: [
        { href: '/clients',         icon: '🏢',  label: 'الشركات' },
        { href: '/items',         icon: '☰',  label: 'الأصناف والأسعار' },
        { href: '/warehouses',    icon: '▣',  label: 'المخازن والفروع' },
        { href: '/mappings',      icon: '⇌',  label: 'ربط الأسماء' },
        { href: '/users',         icon: '◉',  label: 'المستخدمين' },
        { href: '/settings',      icon: '⚙',  label: 'الإعدادات' },
      ].filter((i) => hasPerm(i.href) && hasModule(i.href)),
    },
  ];

  const token = typeof window !== 'undefined' ? localStorage.getItem('erp_token') : null;

  useEffect(() => {
    if (!currentClient?.id || !token) return;
    fetch(`http://localhost:8000/api/settings/logo`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((r) => r.ok ? r.json() : null)
      .then((d) => { if (d?.logo_url) setLogoUrl(d.logo_url); })
      .catch(() => {});
    fetch(`http://localhost:8000/api/client/modules`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((r) => r.ok ? r.json() : [])
      .then((d) => { if (Array.isArray(d)) setModules(d); })
      .catch(() => {});
    fetch(`http://localhost:8000/api/client/settings`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((r) => r.ok ? r.json() : null)
      .then((d) => { if (d?.primary_color) setPrimaryColor(d.primary_color); })
      .catch(() => {});
  }, [currentClient?.id, token]);

  useEffect(() => {
    if (currentClient?.id) qc.invalidateQueries();
  }, [currentClient?.id, qc]);

  return (
    <div className="flex h-screen bg-gray-50 dark:bg-gray-950 font-sans" dir="rtl">
      <aside className={clsx(
        'bg-white dark:bg-gray-900 border-l border-gray-100 dark:border-gray-800 flex flex-col flex-shrink-0 transition-all duration-200 overflow-hidden',
        sidebarOpen ? 'w-52' : 'w-0',
      )}>
        <div className="px-4 py-4 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
          {logoUrl && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logoUrl} alt="Curve" className="h-8 mb-1" />
          )}
          <div className="font-semibold text-gray-900 dark:text-gray-100 text-sm whitespace-nowrap">Curve</div>
          <div className="text-xs text-gray-400 mt-0.5 whitespace-nowrap">نظام إدارة التكاليف</div>
        </div>

        <div className="relative px-3 py-2 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
          <button
            onClick={() => setClientMenuOpen((p) => !p)}
            className="w-full text-right px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700
                       flex items-center justify-between"
          >
            <div className="min-w-0">
              <div className="text-xs text-gray-400 whitespace-nowrap">العميل الحالي</div>
              <div className="text-sm font-medium text-gray-800 dark:text-gray-200 mt-0.5 truncate">
                {currentClient?.name ?? '—'}
              </div>
            </div>
            <span className="text-gray-400 text-xs flex-shrink-0">{clientMenuOpen ? '▲' : '▼'}</span>
          </button>

          {clientMenuOpen && (
            <div className="absolute right-3 left-3 top-full mt-1 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700
                            rounded-lg shadow-lg z-50 overflow-hidden">
              {user?.clients.map((c) => (
                  <button
                    key={c.id}
                    onClick={async () => { await switchClient(c.id); setClientMenuOpen(false); toast.success(`تم التبديل إلى ${c.name}`); }}
                  className={clsx(
                    'w-full text-right px-3 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800',
                    c.id === currentClient?.id && 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-medium',
                  )}
                >
                  {c.name}
                </button>
              ))}
            </div>
          )}
        </div>

        <nav className="flex-1 overflow-y-auto py-2">
          {NAV.map((group) => (
            <div key={group.section} className="mb-1">
              {group.items.length > 0 && (
                <div className="px-4 py-1.5 text-xs text-gray-400 uppercase tracking-wider whitespace-nowrap">
                  {group.section}
                </div>
              )}
              {group.items.map((item) => {
                const active = pathname === item.href;
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    className={clsx(
                      'flex items-center gap-2.5 px-4 py-2 text-sm transition-colors whitespace-nowrap',
                      active
                        ? 'font-medium'
                        : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200',
                    )}
                    style={active ? { backgroundColor: `${primaryColor}15`, color: primaryColor, borderRight: `2px solid ${primaryColor}` } : undefined}
                  >
                    <span className="text-base w-5 text-center flex-shrink-0">{item.icon}</span>
                    <span>{item.label}</span>
                  </Link>
                );
              })}
            </div>
          ))}
        </nav>

        <div className="px-4 py-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between flex-shrink-0">
          <div className="min-w-0">
            <div className="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{user?.name}</div>
            <div className="text-xs text-gray-400 whitespace-nowrap">{user?.role === 'admin' ? 'مدير' : 'كوست كنترول'}</div>
          </div>
          <div className="flex items-center gap-1">
            <ThemeToggle />
            <button
              onClick={logout}
              className="text-xs text-gray-400 hover:text-red-500 px-1 flex-shrink-0"
              title="تسجيل خروج"
            >
              ⏻
            </button>
          </div>
        </div>
      </aside>

      <button
        onClick={() => setSidebarOpen((p) => !p)}
        className="absolute z-40 top-4 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-1.5 py-1 text-sm text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 hover:shadow-sm transition-all"
        style={{ right: sidebarOpen ? 'calc(13rem + 4px)' : '8px' }}
        title={sidebarOpen ? 'إغلاق القائمة' : 'فتح القائمة'}
      >
        {sidebarOpen ? '◀' : '▶'}
      </button>

      <main className="flex-1 flex flex-col overflow-hidden">
        <div className="flex items-center justify-center gap-4 py-3 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex-shrink-0">
          {logoUrl && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logoUrl} alt="Curve" className="h-8" />
          )}
          <span className="text-lg font-bold text-gray-800 dark:text-gray-200">{currentClient?.name ?? '—'}</span>
        </div>
        {children}
      </main>
    </div>
  );
}

export function PageHeader({
  title, subtitle, actions,
}: {
  title: string;
  subtitle?: string;
  actions?: React.ReactNode;
}) {
  return (
    <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900">
      <div>
        <h1 className="font-semibold text-gray-900 dark:text-gray-100">{title}</h1>
        {subtitle && <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{subtitle}</p>}
      </div>
      {actions && <div className="flex gap-2">{actions}</div>}
    </div>
  );
}
