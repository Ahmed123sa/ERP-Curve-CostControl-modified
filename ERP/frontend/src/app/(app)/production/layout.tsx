'use client';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { PageHeader } from '@/components/ui/AppShell';

const TABS = [
  { href: '/production',          label: 'الإنتاج اليومي' },
  { href: '/production/slaughter', label: 'تصفية ذبيحة' },
  { href: '/production/processing', label: 'معالجة المواد' },
  { href: '/production/recipes',  label: 'إدارة الوصفات' },
  { href: '/production/market-prices', label: 'أسعار البورصة' },
];

export default function ProductionLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();

  return (
    <>
      <PageHeader title="الإنتاج" />
      <div className="border-b border-gray-100 mb-4">
        <div className="flex gap-4 px-6">
          {TABS.map((tab) => (
            <Link
              key={tab.href}
              href={tab.href}
              className={`px-3 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                pathname === tab.href
                  ? 'border-blue-600 text-blue-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              {tab.label}
            </Link>
          ))}
        </div>
      </div>
      <div className="flex-1 overflow-y-auto px-6 pb-6">{children}</div>
    </>
  );
}
