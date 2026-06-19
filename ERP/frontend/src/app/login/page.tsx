'use client';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/lib/store';
import toast from 'react-hot-toast';

export default function LoginPage() {
  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading]   = useState(false);
  const [logoUrl, setLogoUrl]   = useState<string | null>(null);
  const { user, token, login }  = useAuthStore();
  const router                  = useRouter();

  useEffect(() => {
    if (user && token) {
      if (user.portal === 'client') {
        router.replace('/client/dashboard');
      } else if (user.clients && user.clients.length > 1) {
        router.replace('/select-client');
      } else {
        router.replace('/dashboard');
      }
    }
  }, [user, token, router]);

  useEffect(() => {
    fetch('http://localhost:8000/api/settings/logo', {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((r) => r.ok ? r.json() : null)
      .then((d) => { if (d?.logo_url) setLogoUrl(d.logo_url); })
      .catch(() => {});
  }, [token]);

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    try {
      await login(email, password);
      const { user } = useAuthStore.getState();
      if (user) {
        if (user.portal === 'client') {
          router.replace('/client/dashboard');
        } else if (user.clients && user.clients.length > 1) {
          router.replace('/select-client');
        } else {
          router.replace('/dashboard');
        }
      }
    } catch {
      toast.error('بيانات الدخول غلط');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          {logoUrl && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logoUrl} alt="Curve" className="h-14 mx-auto mb-3" />
          )}
          <h1 className="text-2xl font-bold text-gray-900">Curve</h1>
          <p className="text-gray-500 text-sm mt-1">نظام إدارة التكاليف</p>
        </div>
        <form onSubmit={handleLogin} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">البريد الإلكتروني</label>
            <input type="email" value={email} onChange={e => setEmail(e.target.value)}
              className="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400" required />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">كلمة المرور</label>
            <input type="password" value={password} onChange={e => setPassword(e.target.value)}
              className="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400" required />
          </div>
          <button type="submit" disabled={loading}
            className="w-full py-2.5 bg-blue-600 text-white text-sm font-medium rounded-xl hover:bg-blue-700 disabled:opacity-50">
            {loading ? 'جاري الدخول...' : 'دخول'}
          </button>
        </form>
      </div>
    </div>
  );
}
