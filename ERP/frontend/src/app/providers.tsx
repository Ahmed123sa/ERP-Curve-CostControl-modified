'use client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import { useState } from 'react';
import { ThemeProvider } from '@/components/ui/ThemeProvider';

export function Providers({ children }: { children: React.ReactNode }) {
  const [qc] = useState(() => new QueryClient());
  return (
    <ThemeProvider>
      <QueryClientProvider client={qc}>
        {children}
        <Toaster position="top-center" />
      </QueryClientProvider>
    </ThemeProvider>
  );
}
