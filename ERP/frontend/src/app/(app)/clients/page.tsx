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
  const [primaryColor, setPrimaryColor] = useState('');
  const [editModal, setEditModal] = useState<{ id: string; name: string; slug: string; is_active: boolean; logo: string; primary_color: string } | null>(null);

  const logoUrl = (path: string | null | undefined) => {
    if (!path) return null;
    if (path.startsWith('http')) return path;
    const base = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api').replace(/\/api$/, '');
    return `${base}/storage/${path}`;
  };

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
      setPrimaryColor('');
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

  const uploadLogoMutation = useMutation({
    mutationFn: ({ id, file }: { id: string; file: File }) => {
      const fd = new FormData();
      fd.append('logo', file);
      return api.post(`/clients/${id}/logo`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    },
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['clients'] });
      if (editModal && res.data?.logo) {
        setEditModal({ ...editModal, logo: res.data.logo });
      }
    },
  });

  if (user?.role !== 'admin') {
    return <div className="p-10 text-center text-gray-500">عذراً، هذه الصفحة للمديرين فقط.</div>;
  }

  const handleDelete = (id: string) => {
    if (confirm('هل أنت متأكد من حذف هذه الشركة؟')) {
      deleteMutation.mutate(id);
    }
  };

  const handleModalSave = () => {
    if (!editModal) return;
    updateMutation.mutate({ id: editModal.id, data: { name: editModal.name, slug: editModal.slug, is_active: editModal.is_active, logo: editModal.logo || null, primary_color: editModal.primary_color || null } });
    setEditModal(null);
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
          <div className="grid grid-cols-3 gap-4 mb-4">
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
            <div>
              <label className="block text-xs text-gray-400 mb-1">اللون الأساسي</label>
              <div className="flex items-center gap-2" dir="ltr">
                <input type="color" value={primaryColor || '#2563eb'}
                  onChange={(e) => setPrimaryColor(e.target.value)}
                  className="w-10 h-10 rounded border border-gray-200 cursor-pointer flex-shrink-0" />
                <input type="text" placeholder="#2563eb" value={primaryColor}
                  onChange={(e) => setPrimaryColor(e.target.value)}
                  className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
              </div>
            </div>
          </div>
          <div className="flex justify-end">
            <button
              onClick={() => createMutation.mutate({ name, slug, primary_color: primaryColor || undefined })}
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
                <th className="px-6 py-3 font-medium">اللوجو / اللون</th>
                <th className="px-6 py-3 font-medium">الرابط (Slug)</th>
                <th className="px-6 py-3 font-medium text-center">الحالة</th>
                <th className="px-6 py-3 font-medium text-center">الإجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {isLoading ? (
                <tr><td colSpan={5} className="px-6 py-4 text-center">جاري التحميل...</td></tr>
              ) : clients?.map((client: any) => (
                <tr key={client.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 font-medium text-gray-900">{client.name}</td>
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      {client.logo_url && (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={client.logo_url} alt="" className="h-7 w-7 rounded object-contain border" />
                      )}
                      {client.primary_color && (
                        <span className="inline-block w-5 h-5 rounded-full border" style={{ backgroundColor: client.primary_color }} />
                      )}
                      {!client.logo_url && !client.primary_color && <span className="text-gray-300">—</span>}
                    </div>
                  </td>
                  <td className="px-6 py-4 text-gray-500">{client.slug}</td>
                  <td className="px-6 py-4 text-center">
                    <span className={`inline-block px-2 py-1 text-xs rounded-full ${client.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                      {client.is_active ? 'نشط' : 'موقوف'}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-center space-x-reverse space-x-2">
                    <button
                      onClick={() => setEditModal({ id: client.id, name: client.name, slug: client.slug || '', is_active: client.is_active, logo: client.logo || '', primary_color: client.primary_color || '' })}
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

      {editModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" onClick={() => setEditModal(null)}>
          <div className="bg-white rounded-xl p-6 w-[28rem] shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h3 className="text-sm font-bold mb-4">تعديل الشركة</h3>
            <div className="grid grid-cols-2 gap-4 mb-4">
              <div>
                <label className="block text-xs text-gray-400 mb-1">الاسم</label>
                <input type="text" value={editModal.name}
                  onChange={(e) => setEditModal({ ...editModal, name: e.target.value })}
                  className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
              </div>
              <div>
                <label className="block text-xs text-gray-400 mb-1">الاسم المختصر (Slug)</label>
                <input type="text" value={editModal.slug}
                  onChange={(e) => setEditModal({ ...editModal, slug: e.target.value })}
                  className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
              </div>
              <div>
                <label className="block text-xs text-gray-400 mb-1">شعار الشركة</label>
                <div className="flex items-center gap-2 mb-1">
                  <input type="file" accept="image/*"
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (file) uploadLogoMutation.mutate({ id: editModal.id, file });
                    }}
                    className="w-full text-xs file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:text-xs file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" />
                </div>
                {uploadLogoMutation.isPending && <span className="text-xs text-blue-600">جاري الرفع...</span>}
                {logoUrl(editModal.logo) && (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={logoUrl(editModal.logo)!} alt="logo" className="h-8 mt-1 rounded" />
                )}
              </div>
              <div>
                <label className="block text-xs text-gray-400 mb-1">اللون الأساسي</label>
                <div className="flex items-center gap-2">
                  <input type="color" value={editModal.primary_color || '#2563eb'}
                    onChange={(e) => setEditModal({ ...editModal, primary_color: e.target.value })}
                    className="w-10 h-10 rounded border border-gray-200 cursor-pointer" />
                  <input type="text" value={editModal.primary_color}
                    onChange={(e) => setEditModal({ ...editModal, primary_color: e.target.value })}
                    className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>
              </div>
            </div>
            <label className="flex items-center gap-2 text-sm mb-4">
              <input type="checkbox" checked={editModal.is_active}
                onChange={(e) => setEditModal({ ...editModal, is_active: e.target.checked })}
                className="rounded" />
              الشركة نشطة
            </label>
            <div className="flex justify-end gap-2">
              <button onClick={() => setEditModal(null)} className="px-4 py-2 text-sm border border-gray-200 rounded-lg">إلغاء</button>
              <button onClick={handleModalSave} className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">حفظ</button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
