'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/lib/store';
import { api } from '@/lib/api';
import clsx from 'clsx';
import { ThemeToggle } from './ThemeToggle';
import {
  LayoutDashboard, Package, ArrowLeftRight, Coins, AlertTriangle,
  Percent, ShoppingCart, UtensilsCrossed, Wallet, TrendingUp,
  Sparkles, LogOut, Menu, X, ChevronLeft,
} from 'lucide-react';

const HREF_MODULE: Record<string, string> = {
  '/client/dashboard': 'dashboard',
  '/client/stock': 'inventory',
  '/client/stock/movement': 'inventory',
  '/client/stock/opening': 'inventory',
  '/client/stock/closing': 'inventory',
  '/client/reports/financial-details': 'reports.financial',
  '/client/reports/diffs': 'reports.diffs',
  '/client/reports/cost': 'reports.food-cost',
  '/client/reports/purchases': 'vouchers.purchase',
  '/client/reports/menu-engineering': 'menu-engineering',
  '/client/reports/expenses': 'expenses',
  '/client/reports/financial': 'reports.financial',
  '/client/analytics': 'analytics',
};

const NAV_ICONS: Record<string, React.ReactNode> = {
  '/client/dashboard': <LayoutDashboard size={18} />,
  '/client/stock': <Package size={18} />,
  '/client/stock/movement': <ArrowLeftRight size={18} />,
  '/client/reports/financial-details': <Coins size={18} />,
  '/client/reports/diffs': <AlertTriangle size={18} />,
  '/client/reports/cost': <Percent size={18} />,
  '/client/reports/purchases': <ShoppingCart size={18} />,
  '/client/reports/menu-engineering': <UtensilsCrossed size={18} />,
  '/client/reports/expenses': <Wallet size={18} />,
  '/client/reports/financial': <TrendingUp size={18} />,
  '/client/analytics': <Sparkles size={18} />,
};

const SECTION_DESC: Record<string, string> = {
  'الرئيسية': 'نظرة عامة سريعة',
  'المخزون': 'إدارة الأصناف والمستودعات',
  'التقارير': 'تحليلات وتقارير مالية',
  'التحليلات': 'ذكاء اصطناعي وتحليلات متقدمة',
};

const CLIENT_NAV = [
  {
    section: 'الرئيسية',
    items: [
      { href: '/client/dashboard', label: 'لوحة التحكم' },
    ],
  },
  {
    section: 'المخزون',
    items: [
      { href: '/client/stock', label: 'الرصيد الحالي' },
      { href: '/client/stock/movement', label: 'حركة الأصناف' },
    ],
  },
  {
    section: 'التقارير',
    items: [
      { href: '/client/reports/financial-details', label: 'التفاصيل المالية' },
      { href: '/client/reports/diffs', label: 'الفروق والهدر' },
      { href: '/client/reports/cost', label: 'تحليل التكاليف' },
      { href: '/client/reports/purchases', label: 'المشتريات' },
      { href: '/client/reports/menu-engineering', label: 'Menu Engineering' },
      { href: '/client/reports/expenses', label: 'المصروفات' },
      { href: '/client/reports/financial', label: 'مالي' },
    ],
  },
  {
    section: 'التحليلات',
    items: [
      { href: '/client/analytics', label: 'التحليلات الذكية' },
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

  useEffect(() => {
    api.get('/client/modules')
      .then((r) => { if (Array.isArray(r.data)) setModules(r.data); })
      .catch(() => {});
    api.get('/client/settings')
      .then((r) => {
        if (r.data) {
          if (r.data.primary_color) setPrimaryColor(r.data.primary_color);
          if (r.data.logo_url) setLogoUrl(r.data.logo_url);
        }
      })
      .catch(() => {});
  }, []);

  const filteredNav = CLIENT_NAV.map((group) => ({
    ...group,
    items: group.items.filter((item) => {
      const mod = HREF_MODULE[item.href];
      return !mod || modules.includes(mod);
    }),
  })).filter((g) => g.items.length > 0);

  return (
    <div className="flex h-screen bg-gray-50 dark:bg-[#0d0d0f] font-sans" dir="rtl">
      {isMobile && sidebarOpen && (
        <div
          className="fixed inset-0 bg-black/40 z-30"
          onClick={() => setSidebarOpen(false)}
        />
      )}
      <aside className={clsx(
        'bg-white dark:bg-gray-900/95 border-l border-gray-100 dark:border-gray-700/50 flex flex-col flex-shrink-0 transition-all duration-200 overflow-hidden z-40',
        isMobile ? 'fixed right-0 top-0 bottom-0 shadow-2xl' : 'relative dark:shadow-lg',
        sidebarOpen ? 'w-52' : 'w-0',
      )}>
        <div className="px-4 py-4 border-b border-gray-100 dark:border-gray-700/50 flex-shrink-0">
          {logoUrl && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logoUrl} alt="Curve" className="h-9 mb-1.5" />
          )}
          <div className="flex items-center gap-2">
            <div className="w-7 h-7 rounded-lg flex items-center justify-center text-white text-xs font-bold" style={{ backgroundColor: primaryColor }}>
              {currentClient?.name?.charAt(0) ?? 'C'}
            </div>
            <div>
              <div className="font-semibold text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap">Curve</div>
              {currentClient && (
                <div className="text-[11px] text-gray-400 truncate max-w-[160px]">{currentClient.name}</div>
              )}
            </div>
          </div>
        </div>

        <nav className="flex-1 overflow-y-auto py-3 scrollbar-thin">
          {filteredNav.map((group) => (
            <div key={group.section} className="mb-2">
              {group.items.length > 0 && (
                <div className="px-4 mb-1">
                  <div className="text-[11px] font-semibold text-gray-400 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap">
                    {group.section}
                  </div>
                  <div className="text-[10px] text-gray-300 dark:text-gray-500 mt-0.5 whitespace-nowrap">
                    {SECTION_DESC[group.section]}
                  </div>
                </div>
              )}
              {group.items.map((item) => {
                const active = pathname === item.href;
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    className={clsx(
                      'relative flex items-center gap-3 mx-2 px-3 py-2 text-sm rounded-xl transition-all duration-200 whitespace-nowrap group',
                      active
                        ? 'font-semibold shadow-sm'
                        : 'text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800/80 hover:text-gray-700 dark:hover:text-gray-100',
                    )}
                    style={active ? { backgroundColor: `${primaryColor}12`, color: primaryColor } : undefined}
                  >
                    <span className={clsx(
                      'flex-shrink-0 transition-colors',
                      active ? '' : 'text-gray-400 dark:text-gray-500 group-hover:text-gray-600 dark:group-hover:text-gray-300',
                    )}>
                      {NAV_ICONS[item.href]}
                    </span>
                    <span>{item.label}</span>
                    {active && (
                      <span className="absolute right-0 top-1/2 -translate-y-1/2 w-0.5 h-5 rounded-full" style={{ backgroundColor: primaryColor }} />
                    )}
                  </Link>
                );
              })}
            </div>
          ))}
        </nav>

        <div className="px-3 py-3 border-t border-gray-100 dark:border-gray-700/50 flex-shrink-0">
          <div className="flex items-center justify-between rounded-xl bg-gray-50 dark:bg-gray-800/70 px-3 py-2">
            <div className="min-w-0 flex items-center gap-2">
              <div className="w-7 h-7 rounded-full bg-gradient-to-br from-gray-300 to-gray-400 dark:from-gray-500 dark:to-gray-600 flex items-center justify-center text-white text-xs font-medium flex-shrink-0">
                {user?.name?.charAt(0) ?? '?'}
              </div>
              <div className="truncate">
                <div className="text-xs font-medium text-gray-700 dark:text-gray-200 truncate max-w-[100px]">{user?.name}</div>
              </div>
            </div>
            <div className="flex items-center gap-0.5">
              <ThemeToggle />
              <button
                onClick={logout}
                className="text-gray-400 hover:text-red-500 p-1.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"
                title="تسجيل خروج"
              >
                <LogOut size={14} />
              </button>
            </div>
          </div>
        </div>
      </aside>

      <button
        onClick={() => setSidebarOpen((p) => !p)}
        className={clsx(
          'absolute z-40 top-4 flex items-center justify-center w-8 h-8 rounded-xl border shadow-sm transition-all duration-200',
          'bg-white dark:bg-gray-800/90 border-gray-200 dark:border-gray-600/50',
          'text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:shadow-md',
          'hover:scale-105 active:scale-95',
        )}
        style={{
          right: isMobile ? '12px' : sidebarOpen ? 'calc(13rem + 4px)' : '12px',
          transform: isMobile && sidebarOpen ? 'translateX(calc(-100% - 8px))' : 'none',
        }}
        title={sidebarOpen ? 'إغلاق القائمة' : 'فتح القائمة'}
      >
        {isMobile ? (
          sidebarOpen ? <X size={16} /> : <Menu size={16} />
        ) : (
          <ChevronLeft size={16} className={clsx('transition-transform duration-200', sidebarOpen ? '' : 'rotate-180')} />
        )}
      </button>

      <main className="flex-1 flex flex-col overflow-hidden" style={{ '--client-primary': primaryColor, '--client-primary-rgb': hexToRgb(primaryColor) } as React.CSSProperties}>
        <div className="flex items-center justify-center gap-4 py-3 bg-white dark:bg-gray-900/95 border-b border-gray-200 dark:border-gray-700/50 flex-shrink-0">
          <span className="text-lg font-bold text-gray-800 dark:text-gray-200">{currentClient?.name ?? '—'}</span>
        </div>
        {children}
      </main>
    </div>
  );
}
