'use client';

import { ReactNode } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';

export default function MenuEngineeringLayout({ children }: { children: ReactNode }) {
  const path = usePathname();
  const tabs = [
    { href: '/menu-engineering', label: 'الوصفات' },
    { href: '/menu-engineering/ingredients', label: 'المكونات' },
    { href: '/menu-engineering/report', label: 'التقارير' },
    { href: '/menu-engineering/reconciliation', label: 'التسوية' },
    { href: '/menu-engineering/analytics', label: '🔬 التحليلات الذكية' },
  ];

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50/50" dir="rtl">
      <div className="bg-white border-b border-gray-200 px-6 py-0 flex gap-0">
        {tabs.map((t) => (
          <Link
            key={t.href}
            href={t.href}
            className={`px-5 py-3 text-sm font-medium border-b-2 transition-colors ${
              path === t.href
                ? 'border-blue-600 text-blue-700'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            {t.label}
          </Link>
        ))}
      </div>
      <div className="flex-1 overflow-y-auto p-6">{children}</div>
    </div>
  );
}
