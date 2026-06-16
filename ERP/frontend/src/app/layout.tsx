import type { Metadata } from 'next';
import './globals.css';
import { Providers } from './providers';
import { Inter } from "next/font/google";
import { cn } from "@/lib/utils";

const geist = Inter({subsets:['latin'],variable:'--font-sans'});

export const metadata: Metadata = {
  title: 'Curve — نظام إدارة التكاليف',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ar" dir="rtl" suppressHydrationWarning className={cn("font-sans", geist.variable)}>
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
