'use client';
// src/components/ui/ClientShell.tsx

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/lib/store';
import clsx from 'clsx';
import { ThemeToggle } from './ThemeToggle';

const HREF_MODULE: Record<string, string> = {
  '/client/dashboard': 'dashboard',
  '/client/stock': 'inventory',
  '/client/stock/movement': 'inventory',
  '/client/stock/opening': 'inventory',
  '/client/stock/closing': 'inventory',
  '/client/reports/financial-details': 'reports.financial',
  '/client/reports/diffs': 'reports.diffs',
  '/client/reports/cost': 'reports.food-cost',
  '/client/reports/purchases': 'purchases',
  '/client/reports/menu-engineering': 'menu_engineering',
  '/client/reports/expenses': 'expenses',
  '/client/reports/financial': 'reports.financial',
  '/client/analytics': 'analytics',
};

const CLIENT_NAV = [
  {
    section: 'الرئيسية',
    items: [
      { href: '/client/dashboard', icon: '▦', label: 'لوحة التحكم' },
    ],
  },
  {
    section: 'المخزون',
    items: [
      { href: '/client/stock', icon: '◫', label: 'الرصيد الحالي' },
      { href: '/client/stock/movement', icon: '↔', label: 'حركة الأصناف' },
    ],
  },
  {
    section: 'التقارير',
    items: [
      { href: '/client/reports/financial-details', icon: '₿', label: 'التفاصيل المالية' },
      { href: '/client/reports/diffs', icon: '!', label: 'الفروق والهدر' },
      { href: '/client/reports/cost', icon: '%', label: 'تحليل التكاليف' },
      { href: '/client/reports/purchases', icon: 'Σ', label: 'المشتريات' },
      { href: '/client/reports/menu-engineering', icon: '♨', label: 'Menu Engineering' },
      { href: '/client/reports/expenses', icon: '€', label: 'المصروفات' },
      { href: '/client/reports/financial', icon: 'F', label: 'مالي' },
    ],
  },
  {
    section: 'التحليلات',
    items: [
      { href: '/client/analytics', icon: '🔬', label: 'التحليلات الذكية' },
    ],
  },
];

function hexToRgb(hex: string): string {
  const c = hex.replace('#', '');
  const big = parseInt(c.length === 3 ? c.split('').map((x) => x + x).join('') : c, 16);
  return `${(big >> 16) & 255}, ${(big >> 8) & 255}, ${big & 255}`;
}

export function ClientShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { user, currentClient, logout } = useAuthStore();
  const [modules, setModules] = useState<string[]>([]);
  const [primaryColor, setPrimaryColor] = useState('#2563eb');
  const [logoUrl, setLogoUrl] = useState<string | null>(null);
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const mq = window.matchMedia('(max-width: 768px)');
    setIsMobile(mq.matches);
    const handler = (e: MediaQueryListEvent) => {
      setIsMobile(e.matches);
      if (e.matches) setSidebarOpen(false);
    };
    mq.addEventListener('change', handler);
    return () => mq.removeEventListener('change', handler);
  }, []);

  const token = typeof window !== 'undefined' ? localStorage.getItem('erp_token') : null;

  useEffect(() => {
    if (!token) return;
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
      .then((d) => {
        if (d) {
          if (d.primary_color) setPrimaryColor(d.primary_color);
          if (d.logo) setLogoUrl(d.logo);
        }
      })
      .catch(() => {});
  }, [token]);

  const filteredNav = CLIENT_NAV.map((group) => ({
    ...group,
    items: group.items.filter((item) => {
      const mod = HREF_MODULE[item.href];
      return !mod || modules.includes(mod);
    }),
  })).filter((g) => g.items.length > 0);

  return (
    <div className="flex h-screen bg-gray-50 dark:bg-gray-950 font-sans" dir="rtl">
      {isMobile && sidebarOpen && (
        <div
          className="fixed inset-0 bg-black/40 z-30"
          onClick={() => setSidebarOpen(false)}
        />
      )}
      <aside className={clsx(
        'bg-white dark:bg-gray-900 border-l border-gray-100 dark:border-gray-800 flex flex-col flex-shrink-0 transition-all duration-200 overflow-hidden z-40',
        isMobile ? 'fixed right-0 top-0 bottom-0 shadow-xl' : 'relative',
        sidebarOpen ? 'w-52' : 'w-0',
      )}>
        <div className="px-4 py-4 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
          {logoUrl && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logoUrl} alt="Curve" className="h-8 mb-1" />
          )}
          <div className="font-semibold text-gray-900 dark:text-gray-100 text-sm whitespace-nowrap" style={{ color: primaryColor }}>Curve</div>
          {currentClient && (
            <div className="text-xs text-gray-400 mt-0.5 whitespace-nowrap">{currentClient.name}</div>
          )}
        </div>

        <nav className="flex-1 overflow-y-auto py-2">
          {filteredNav.map((group) => (
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

      <main className="flex-1 flex flex-col overflow-hidden" style={{ '--client-primary': primaryColor, '--client-primary-rgb': hexToRgb(primaryColor) } as React.CSSProperties}>
        <div className="flex items-center justify-center gap-4 py-3 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex-shrink-0">
          <span className="text-lg font-bold text-gray-800 dark:text-gray-200">{currentClient?.name ?? '—'}</span>
        </div>
        {children}
      </main>
    </div>
  );
}
