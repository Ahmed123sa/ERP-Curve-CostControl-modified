'use client';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/lib/store';
import { ClientShell } from '@/components/ui/ClientShell';

export default function ClientLayout({ children }: { children: React.ReactNode }) {
  const { user } = useAuthStore();
  const router = useRouter();
  const [ready, setReady] = useState(false);

  useEffect(() => {
    const unsub = useAuthStore.persist.onFinishHydration(() => setReady(true));
    if (useAuthStore.persist.hasHydrated()) setReady(true);
    return unsub;
  }, []);

  useEffect(() => {
    if (ready && !user) router.replace('/login');
    if (ready && user && user.portal !== 'client') router.replace('/dashboard');
  }, [ready, user, router]);

  if (!ready || !user) return null;

  return <ClientShell>{children}</ClientShell>;
}
