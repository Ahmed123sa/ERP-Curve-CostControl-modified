'use client';

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useAuthStore } from '@/lib/store';
import { useState } from 'react';
import toast from 'react-hot-toast';

export default function ItemsPage() {
  const { currentClient } = useAuthStore();
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [unit, setUnit] = useState('');
  const [category, setCategory] = useState('');
  const [cost, setCost] = useState('');

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editData, setEditData] = useState<any>({});

  const { data: items, isLoading } = useQuery({
    queryKey: ['items', currentClient?.id],
    queryFn: () => api.get('/items').then((r) => r.data),
    enabled: !!currentClient,
  });

  const { data: warehouses } = useQuery({
    queryKey: ['warehouses', currentClient?.id],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
    enabled: !!currentClient,
  });

  const createMutation = useMutation({
    mutationFn: (newData: any) => api.post('/items', newData),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items'] });
      setName('');
      setUnit('');
      setCategory('');
      setCost('');
      toast.success('تمت إضافة الصنف بنجاح');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => api.put(`/items/${id}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items'] });
      setEditingId(null);
      toast.success('تم تحديث الصنف');
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/items/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items'] });
      toast.success('تم حذف الصنف');
    },
  });

  const bulkDeleteMutation = useMutation({
    mutationFn: () => api.delete('/items/bulk'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items'] });
      toast.success('تم حذف جميع الأصناف بنجاح');
    },
  });

  const startEditing = (item: any) => {
    setEditingId(item.id);
    setEditData({ 
      name: item.name, 
      default_cost: item.default_cost || 0, 
      unit: item.unit,
      default_warehouse_id: item.default_warehouse_id || ''
    });
  };

  const saveEdit = (id: string) => {
    updateMutation.mutate({ id, data: editData });
  };

  return (
    <>
      <PageHeader
        title="الأصناف والأسعار"
        subtitle="تعريف المنتجات والمواد الخام"
        actions={
          <div className="flex items-center gap-3">
            <button
              onClick={() => {
                if (window.confirm('هل أنت متأكد من حذف جميع الأصناف بالكامل؟ لا يمكن التراجع عن هذه الخطوة!')) {
                  bulkDeleteMutation.mutate();
                }
              }}
              className="px-4 py-2 bg-red-50 text-red-600 border border-red-200 rounded-lg text-sm font-medium hover:bg-red-100 transition-colors"
            >
              🗑️ حذف كل الأصناف
            </button>
            <label className="cursor-pointer px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 flex items-center gap-2">
              <span>استيراد من Excel 📥</span>
              <input 
                type="file" 
                className="hidden" 
                accept=".xlsx,.xls" 
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) {
                    const form = new FormData();
                    form.append('file', file);
                    api.post('/items/import', form, {
                      headers: { 'Content-Type': 'multipart/form-data' }
                    }).then(() => {
                      toast.success('تم الاستيراد بنجاح');
                      queryClient.invalidateQueries({ queryKey: ['items'] });
                    }).catch(() => toast.error('خطأ في الاستيراد'));
                  }
                }}
              />
            </label>
          </div>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        {/* صندوق إضافة صنف جديد */}
        <div className="bg-white border border-gray-100 rounded-xl p-4">
          <h3 className="text-sm font-medium mb-4 text-gray-800">إضافة صنف جديد</h3>
          <div className="grid grid-cols-4 gap-4 mb-4">
            <input
              type="text"
              placeholder="اسم الصنف"
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm"
            />
            <input
              type="text"
              placeholder="الوحدة (كيلو، عدد...)"
              value={unit}
              onChange={(e) => setUnit(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm"
            />
            <input
              type="number"
              placeholder="السعر (اختياري)"
              value={cost}
              onChange={(e) => setCost(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm"
            />
            <input
              type="text"
              placeholder="التصنيف"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm"
            />
          </div>
          <div className="flex justify-end">
            <button
              onClick={() => createMutation.mutate({ name, unit, category, default_cost: parseFloat(cost || '0') })}
              disabled={!name || !unit || createMutation.isPending}
              className="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
            >
              ➕ إضافة صنف
            </button>
          </div>
        </div>

        {/* جدول الأصناف */}
        <div className="bg-white border border-gray-100 rounded-xl overflow-hidden">
          <table className="w-full text-sm" dir="rtl">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr className="text-right text-xs text-gray-500 uppercase">
                <th className="px-6 py-3 font-medium w-16">#</th>
                <th className="px-6 py-3 font-medium w-1/4">الاسم</th>
                <th className="px-6 py-3 font-medium">الوحدة</th>
                <th className="px-6 py-3 font-medium">السعر (Cost)</th>
                <th className="px-6 py-3 font-medium">المخزن الافتراضي</th>
                <th className="px-6 py-3 font-medium">التصنيف</th>
                <th className="px-6 py-3 font-medium text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {isLoading ? (
                <tr><td colSpan={7} className="px-6 py-12 text-center text-gray-400">جاري التحميل...</td></tr>
              ) : items?.length === 0 ? (
                <tr><td colSpan={7} className="px-6 py-12 text-center text-gray-400">لا توجد أصناف، يمكنك إضافتها يدوياً أو استيرادها من إكسيل.</td></tr>
              ) : items?.map((item: any, idx: number) => {
                const isEditing = editingId === item.id;
                const currentWarehouse = warehouses?.find((w: any) => w.id === item.default_warehouse_id);
                return (
                  <tr key={item.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-6 py-4 text-gray-400">{idx + 1}</td>
                    
                    <td className="px-6 py-4 font-medium text-gray-900">
                      {isEditing ? (
                        <input 
                          type="text" 
                          value={editData.name} 
                          onChange={(e) => setEditData({...editData, name: e.target.value})}
                          className="border border-gray-300 rounded px-2 py-1 w-full" 
                        />
                      ) : (
                        item.name
                      )}
                    </td>

                    <td className="px-6 py-4 text-gray-600">
                      {isEditing ? (
                        <input 
                          type="text" 
                          value={editData.unit} 
                          onChange={(e) => setEditData({...editData, unit: e.target.value})}
                          className="border border-gray-300 rounded px-2 py-1 w-20" 
                        />
                      ) : (
                        item.unit
                      )}
                    </td>

                    <td className="px-6 py-4 font-semibold text-blue-600">
                      {isEditing ? (
                        <input 
                          type="number" 
                          value={editData.default_cost} 
                          onChange={(e) => setEditData({...editData, default_cost: parseFloat(e.target.value) || 0})}
                          className="border border-gray-300 rounded px-2 py-1 w-24 text-left" 
                          dir="ltr"
                        />
                      ) : (
                        <span dir="ltr">{Number(item.default_cost || 0).toLocaleString()} ج.م</span>
                      )}
                    </td>

                    <td className="px-6 py-4 text-gray-500">
                      {isEditing ? (
                        <select
                          value={editData.default_warehouse_id}
                          onChange={(e) => setEditData({...editData, default_warehouse_id: e.target.value})}
                          className="border border-gray-300 rounded px-2 py-1 w-full text-xs"
                        >
                          <option value="">-- اختر المخزن --</option>
                          {warehouses?.map((w: any) => (
                            <option key={w.id} value={w.id}>{w.name}</option>
                          ))}
                        </select>
                      ) : (
                        <span className={`text-xs px-2 py-1 rounded ${currentWarehouse ? 'bg-gray-100' : 'text-gray-400 italic'}`}>
                          {currentWarehouse ? currentWarehouse.name : 'الرئيسي (تلقائي)'}
                        </span>
                      )}
                    </td>

                    <td className="px-6 py-4 text-gray-500">{item.category}</td>

                    <td className="px-6 py-4 text-center">
                      {isEditing ? (
                        <div className="flex items-center justify-center gap-2">
                          <button onClick={() => saveEdit(item.id)} className="text-green-600 hover:text-green-800 font-medium">حفظ</button>
                          <button onClick={() => setEditingId(null)} className="text-gray-400 hover:text-gray-600">إلغاء</button>
                        </div>
                      ) : (
                        <div className="flex items-center justify-center gap-3">
                          <button onClick={() => startEditing(item)} className="text-blue-600 hover:text-blue-800" title="تعديل">
                            ✏️
                          </button>
                          <button 
                            onClick={() => {
                              if (window.confirm('هل أنت متأكد من حذف هذا الصنف؟')) {
                                deleteMutation.mutate(item.id);
                              }
                            }} 
                            className="text-red-500 hover:text-red-700" title="حذف"
                          >
                            🗑️
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
