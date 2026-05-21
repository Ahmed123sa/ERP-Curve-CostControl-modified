'use client';

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useAuthStore } from '@/lib/store';
import { useState } from 'react';

export default function WarehousesPage() {
  const { currentClient } = useAuthStore();
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [type, setType] = useState('sub');

  const { data: warehouses, isLoading } = useQuery({
    queryKey: ['warehouses', currentClient?.id],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
    enabled: !!currentClient,
  });

  const createMutation = useMutation({
    mutationFn: (newData: any) => api.post('/warehouses', newData),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouses'] });
      setName('');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => api.patch(`/warehouses/${id}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouses'] });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/warehouses/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouses'] });
    },
  });

  const handleEdit = (warehouse: any) => {
    const newName = prompt('أدخل الاسم الجديد للمخزن:', warehouse.name);
    if (newName && newName !== warehouse.name) {
      updateMutation.mutate({ id: warehouse.id, data: { name: newName } });
    }
  };

  const toggleType = (warehouse: any) => {
    const newType = warehouse.type === 'main' ? 'sub' : 'main';
    if (confirm(`هل تريد تحويل هذا الموقع إلى ${newType === 'main' ? 'مخزن رئيسي' : 'فرع/مخزن فرعي'}؟`)) {
      updateMutation.mutate({ id: warehouse.id, data: { type: newType } });
    }
  };

  const handleDelete = (id: string) => {
    if (confirm('هل أنت متأكد من حذف هذا المخزن؟ سيتم حذف كافة بيانات المخزون المرتبطة به!')) {
      deleteMutation.mutate(id);
    }
  };

  return (
    <>
      <PageHeader
        title="المخازن والفروع"
        subtitle="إدارة مواقع التخزين ونقاط البيع"
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        <div className="bg-white border border-gray-100 rounded-xl p-4">
          <h3 className="text-sm font-medium mb-4">إضافة مخزن جديد</h3>
          <div className="flex gap-4">
            <input
              type="text"
              placeholder="اسم المخزن"
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <select
              value={type}
              onChange={(e) => setType(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="main">مخزن رئيسي</option>
              <option value="sub">مخزن فرعي</option>
              <option value="branch">فرع</option>
            </select>
            <button
              onClick={() => createMutation.mutate({ name, type })}
              disabled={!name || createMutation.isPending}
              className="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
            >
              {createMutation.isPending ? 'جاري الإضافة...' : 'إضافة'}
            </button>
          </div>
        </div>

        <div className="bg-white border border-gray-100 rounded-xl overflow-hidden">
          <table className="w-full text-sm" dir="rtl">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr className="text-right text-xs text-gray-500 uppercase">
                <th className="px-6 py-3 font-medium">الاسم</th>
                <th className="px-6 py-3 font-medium">النوع</th>
                <th className="px-6 py-3 font-medium">الحالة</th>
                <th className="px-6 py-3 font-medium text-center">الإجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {isLoading ? (
                <tr><td colSpan={4} className="px-6 py-4 text-center">جاري التحميل...</td></tr>
              ) : warehouses?.map((w: any) => (
                <tr key={w.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 font-medium text-gray-900">{w.name}</td>
                  <td className="px-6 py-4 text-gray-500">
                    <span className={`px-2 py-1 rounded text-[10px] font-medium 
                      ${w.type === 'main' ? 'bg-purple-100 text-purple-700' : 
                        w.type === 'sub' ? 'bg-blue-100 text-blue-700' : 'bg-orange-100 text-orange-700'}`}>
                      {w.type === 'main' ? 'مخزن رئيسي' : w.type === 'sub' ? 'مخزن فرعي' : 'فرع'}
                    </span>
                  </td>
                  <td className="px-6 py-4">
                    <span className={`px-2 py-1 rounded-full text-[10px] font-bold ${w.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                      {w.is_active ? 'نشط' : 'معطل'}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-center space-x-reverse space-x-3">
                    <button
                      onClick={() => handleEdit(w)}
                      className="text-blue-600 hover:underline text-xs"
                    >
                      تعديل الاسم
                    </button>
                    <button
                      onClick={() => {
                        const nextType = w.type === 'main' ? 'sub' : w.type === 'sub' ? 'branch' : 'main';
                        updateMutation.mutate({ id: w.id, data: { type: nextType } });
                      }}
                      className="text-gray-500 hover:underline text-xs"
                    >
                      تغيير النوع
                    </button>
                    <button
                      onClick={() => handleDelete(w.id)}
                      className="text-red-600 hover:underline text-xs"
                    >
                      حذف
                    </button>
                  </td>
                </tr>
              ))}
              {warehouses?.length === 0 && (
                <tr><td colSpan={4} className="px-6 py-4 text-center text-gray-400">لا يوجد مخازن</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
