'use client';

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useAuthStore } from '@/lib/store';
import { useState } from 'react';

export default function ClientsPage() {
  const { user, setUser } = useAuthStore();
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');

  const { data: clients, isLoading } = useQuery({
    queryKey: ['clients'],
    queryFn: () => api.get('/clients').then((r) => r.data),
  });

  const refreshUser = async () => {
    const { data } = await api.get('/auth/me');
    setUser(data);
  };

  const createMutation = useMutation({
    mutationFn: (newData: any) => api.post('/clients', newData),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['clients'] });
      setName('');
      setSlug('');
      refreshUser();
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => api.patch(`/clients/${id}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['clients'] });
      refreshUser();
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/clients/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['clients'] });
      refreshUser();
    },
  });

  if (user?.role !== 'admin') {
    return <div className="p-10 text-center text-gray-500">عذراً، هذه الصفحة للمديرين فقط.</div>;
  }

  const handleEdit = (client: any) => {
    const newName = prompt('أدخل الاسم الجديد للشركة:', client.name);
    if (newName && newName !== client.name) {
      updateMutation.mutate({ id: client.id, data: { name: newName } });
    }
  };

  const handleDelete = (id: string) => {
    if (confirm('هل أنت متأكد من حذف هذه الشركة؟ سيتم حذف كافة البيانات المرتبطة بها!')) {
      deleteMutation.mutate(id);
    }
  };

  return (
    <>
      <PageHeader
        title="إدارة الشركات"
        subtitle="إضافة وتعديل الشركات والعملاء في النظام"
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        <div className="bg-white border border-gray-100 rounded-xl p-4">
          <h3 className="text-sm font-medium mb-4">إضافة شركة جديدة</h3>
          <div className="grid grid-cols-2 gap-4 mb-4">
            <div>
              <label className="block text-xs text-gray-400 mb-1">اسم الشركة</label>
              <input
                type="text"
                placeholder="مثلاً: مستر شريمب"
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label className="block text-xs text-gray-400 mb-1">الاسم المختصر (Slug)</label>
              <input
                type="text"
                placeholder="مثلاً: mr-shrimp"
                value={slug}
                onChange={(e) => setSlug(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div className="flex justify-end">
            <button
              onClick={() => createMutation.mutate({ name, slug })}
              disabled={!name || !slug || createMutation.isPending}
              className="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
            >
              إنشاء الشركة
            </button>
          </div>
        </div>

        <div className="bg-white border border-gray-100 rounded-xl overflow-hidden">
          <table className="w-full text-sm" dir="rtl">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr className="text-right text-xs text-gray-500 uppercase">
                <th className="px-6 py-3 font-medium">الشركة</th>
                <th className="px-6 py-3 font-medium">الرابط (Slug)</th>
                <th className="px-6 py-3 font-medium text-center">الإجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {isLoading ? (
                <tr><td colSpan={3} className="px-6 py-4 text-center">جاري التحميل...</td></tr>
              ) : clients?.map((client: any) => (
                <tr key={client.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 font-medium text-gray-900">{client.name}</td>
                  <td className="px-6 py-4 text-gray-500">{client.slug}</td>
                  <td className="px-6 py-4 text-center space-x-reverse space-x-2">
                    <button
                      onClick={() => handleEdit(client)}
                      className="text-blue-600 hover:underline"
                    >
                      تعديل
                    </button>
                    <button
                      onClick={() => handleDelete(client.id)}
                      className="text-red-600 hover:underline"
                    >
                      حذف
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
