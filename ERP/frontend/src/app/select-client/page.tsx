'use client';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/lib/store';
import toast from 'react-hot-toast';

export default function SelectClientPage() {
  const { user, token, currentClient, switchClient } = useAuthStore();
  const router = useRouter();
  const [loading, setLoading] = useState<string | null>(null);

  useEffect(() => {
    if (!user || !token) router.replace('/login');
  }, [user, token, router]);

  const handleSelect = async (clientId: string) => {
    setLoading(clientId);
    try {
      await switchClient(clientId);
      toast.success('تم اختيار الشركة');
      router.push('/dashboard');
    } catch {
      toast.error('حدث خطأ');
    } finally {
      setLoading(null);
    }
  };

  if (!user || !token) return null;

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-50 to-blue-50 p-6">
      <div className="w-full max-w-lg">
        <div className="text-center mb-8">
          <h1 className="text-2xl font-bold text-gray-900">Curve</h1>
          <p className="text-gray-500 text-sm mt-1">نظام إدارة التكاليف</p>
          <p className="text-gray-400 text-xs mt-4">أهلاً بك، {user.name}</p>
          <p className="text-gray-400 text-xs mt-1">اختر الشركة التي تريد الدخول إليها</p>
        </div>

        <div className="space-y-3">
          {user.clients?.map((client) => {
            const isActive = currentClient?.id === client.id;
            return (
              <button
                key={client.id}
                onClick={() => handleSelect(client.id)}
                disabled={loading !== null}
                className={`w-full text-right px-5 py-4 rounded-xl border-2 transition-all ${
                  isActive
                    ? 'border-blue-500 bg-blue-50 shadow-sm'
                    : 'border-gray-200 bg-white hover:border-blue-300 hover:shadow-sm'
                } disabled:opacity-50`}
              >
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="font-semibold text-gray-900">{client.name}</h3>
                    <p className="text-xs text-gray-400 mt-0.5">{client.slug}</p>
                  </div>
                  {loading === client.id && (
                    <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin" />
                  )}
                  {isActive && loading !== client.id && (
                    <span className="text-xs text-blue-600 font-medium bg-blue-100 px-2 py-0.5 rounded-full">الحالي</span>
                  )}
                </div>
              </button>
            );
          })}
        </div>

        {(!user.clients || user.clients.length === 0) && (
          <div className="text-center text-gray-400 py-10">لا توجد شركات متاحة</div>
        )}
      </div>
    </div>
  );
}
