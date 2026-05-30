'use client';

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useAuthStore } from '@/lib/store';
import { useState, useMemo, useRef } from 'react';
import toast from 'react-hot-toast';

export default function ItemsPage() {
  const { currentClient } = useAuthStore();
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [unit, setUnit] = useState('');
  const [category, setCategory] = useState('');
  const [cost, setCost] = useState('');
  const [minStock, setMinStock] = useState('');
  const [search, setSearch] = useState('');
  const [sortKey, setSortKey] = useState('sort_order');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');
  const [insertId, setInsertId] = useState<string | null>(null);
  const [insertPos, setInsertPos] = useState<'before' | 'after'>('after');
  const [insertName, setInsertName] = useState('');
  const [insertUnit, setInsertUnit] = useState('قطعة');
  const [insertCategory, setInsertCategory] = useState('');
  const [insertCost, setInsertCost] = useState('');
  const [insertMinStock, setInsertMinStock] = useState('');

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editData, setEditData] = useState<any>({});
  const insertRef = useRef<HTMLInputElement>(null);

  const [importItems, setImportItems] = useState<any[]>([]);
  const [importDuplicateNames, setImportDuplicateNames] = useState<string[]>([]);
  const [importEdit, setImportEdit] = useState<Record<string, any>>({});

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
      queryClient.invalidateQueries({ queryKey: ['items', currentClient?.id] });
      setName(''); setUnit(''); setCategory(''); setCost(''); setMinStock('');
      toast.success('تمت إضافة الصنف بنجاح');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => api.put(`/items/${id}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items', currentClient?.id] });
      setEditingId(null);
      toast.success('تم تحديث الصنف');
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/items/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items', currentClient?.id] });
      toast.success('تم حذف الصنف');
    },
  });

  const bulkDeleteMutation = useMutation({
    mutationFn: () => api.delete('/items/bulk'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items', currentClient?.id] });
      toast.success('تم حذف جميع الأصناف بنجاح');
    },
  });

  const moveBottomMutation = useMutation({
    mutationFn: (id: string) => api.put(`/items/${id}/move-bottom`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items', currentClient?.id] });
      toast.success('تم نقل الصنف إلى الأسفل');
    },
  });

  const moveUpMutation = useMutation({
    mutationFn: (id: string) => api.put(`/items/${id}/move-up`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items', currentClient?.id] });
      toast.success('تم نقل الصنف إلى الأعلى');
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message || 'لا يمكن النقل لأعلى');
    },
  });

  const startEditing = (item: any) => {
    setEditingId(item.id);
    setEditData({
      name: item.name,
      default_cost: item.default_cost || 0,
      unit: item.unit,
      default_warehouse_id: item.default_warehouse_id || '',
      min_stock_level: item.min_stock_level ?? '',
      category: item.category || '',
    });
  };

  const saveEdit = (id: string) => {
    const data = { ...editData };
    if (data.min_stock_level === '' || data.min_stock_level === null) data.min_stock_level = null;
    updateMutation.mutate({ id, data });
  };

  const toggleSort = (key: string) => {
    setSortDir(prev => sortKey === key ? (prev === 'asc' ? 'desc' : 'asc') : 'asc');
    setSortKey(key);
  };

  const filteredItems = useMemo(() => {
    if (!items) return [];
    let list = [...items];
    if (search) {
      const q = search.toLowerCase();
      list = list.filter((i: any) => i.name?.toLowerCase().includes(q) || i.category?.toLowerCase().includes(q));
    }
    list.sort((a: any, b: any) => {
      let va = a[sortKey] ?? '';
      let vb = b[sortKey] ?? '';
      if (sortKey === 'default_cost' || sortKey === 'min_stock_level' || sortKey === 'sort_order') { va = parseFloat(va) || 0; vb = parseFloat(vb) || 0; }
      else { va = String(va).toLowerCase(); vb = String(vb).toLowerCase(); }
      return sortDir === 'asc' ? (va > vb ? 1 : -1) : (va < vb ? 1 : -1);
    });
    return list;
  }, [items, search, sortKey, sortDir]);

  const SortIcon = ({ k }: { k: string }) => {
    if (sortKey !== k) return <span className="text-gray-300 mr-1">↕</span>;
    return <span className="text-blue-600 mr-1">{sortDir === 'asc' ? '↑' : '↓'}</span>;
  };

  const startInsert = (id: string, pos: 'before' | 'after') => {
    setInsertId(id);
    setInsertPos(pos);
    setTimeout(() => insertRef.current?.focus(), 50);
  };

  const confirmInsert = () => {
    if (!insertName.trim() || !insertId) return;
    const params: any = {
      name: insertName.trim(),
      unit: insertUnit.trim() || 'قطعة',
      default_cost: parseFloat(insertCost) || 0,
      category: insertCategory.trim() || undefined,
      min_stock_level: insertMinStock ? parseFloat(insertMinStock) : null,
    };
    if (insertPos === 'after') params.after_id = insertId;
    else params.before_id = insertId;
    createMutation.mutate(params);
    setInsertId(null);
    setInsertName('');
    setInsertUnit('قطعة');
    setInsertCategory('');
    setInsertCost('');
    setInsertMinStock('');
  };

  return (
    <>
      <PageHeader title="الأصناف والأسعار" subtitle="تعريف المنتجات والمواد الخام"
        actions={
          <div className="flex items-center gap-2 flex-wrap">
            <button onClick={async () => {
                const res = await api.get('/items/export', { responseType: 'blob' });
                const url = URL.createObjectURL(res.data);
                const a = document.createElement('a'); a.href = url; a.download = 'items.xlsx'; a.click();
                URL.revokeObjectURL(url);
              }} className="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-medium hover:bg-blue-700">📥 تصدير Excel</button>
            <label className="cursor-pointer px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700">📥 استيراد
              <input type="file" className="hidden" accept=".xlsx,.xls" onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) {
                  const form = new FormData(); form.append('file', file);
                  api.post('/items/import', form, { headers: { 'Content-Type': 'multipart/form-data' } })
                    .then((r) => {
                      const { items, duplicates } = r.data;
                      setImportItems(items || []);
                      setImportDuplicateNames(duplicates || []);
                      const editMap: Record<string, any> = {};
                      (items || []).forEach((it: any) => {
                        editMap[it.id] = {
                          name: it.name,
                          default_cost: it.default_cost || 0,
                          unit: it.unit,
                          category: it.category || '',
                          default_warehouse_id: it.default_warehouse_id || '',
                          min_stock_level: it.min_stock_level ?? '',
                        };
                      });
                      setImportEdit(editMap);
                      toast.success(`تم استيراد ${items?.length || 0} صنف`);
                      queryClient.invalidateQueries({ queryKey: ['items', currentClient?.id] });
                    })
                    .catch(() => toast.error('خطأ في الاستيراد'));
                }
              }} />
            </label>
            <label className="cursor-pointer px-3 py-1.5 bg-amber-500 text-white rounded-lg text-xs font-medium hover:bg-amber-600">📥 الحد الأدنى
              <input type="file" className="hidden" accept=".xlsx,.xls" onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) {
                  const form = new FormData(); form.append('file', file);
                  api.post('/items/import-stock-levels', form, { headers: { 'Content-Type': 'multipart/form-data' } })
                    .then((r) => { toast.success(r.data?.message || 'تم التحديث'); queryClient.invalidateQueries({ queryKey: ['items', currentClient?.id] }); })
                    .catch(() => toast.error('خطأ في الاستيراد'));
                }
              }} />
            </label>
            <button onClick={() => { if (window.confirm('هل أنت متأكد من حذف جميع الأصناف بالكامل؟ لا يمكن التراجع عن هذه الخطوة!')) bulkDeleteMutation.mutate(); }}
              className="px-3 py-1.5 bg-red-50 text-red-600 border border-red-200 rounded-lg text-xs font-medium hover:bg-red-100">🗑️ حذف الكل</button>
          </div>
        } />

      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        {/* إضافة صنف جديد */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
          <h3 className="text-xs font-semibold mb-3 text-gray-700">إضافة صنف جديد</h3>
          <div className="flex items-end gap-3 flex-wrap">
            <div className="flex flex-col gap-1">
              <label className="text-[10px] text-gray-400">الاسم</label>
              <input type="text" value={name} onChange={(e) => setName(e.target.value)} className="border border-gray-200 rounded-lg px-3 py-2 text-sm w-44" />
            </div>
            <div className="flex flex-col gap-1">
              <label className="text-[10px] text-gray-400">الوحدة</label>
              <input type="text" value={unit} onChange={(e) => setUnit(e.target.value)} className="border border-gray-200 rounded-lg px-3 py-2 text-sm w-24" />
            </div>
            <div className="flex flex-col gap-1">
              <label className="text-[10px] text-gray-400">السعر</label>
              <input type="number" value={cost} onChange={(e) => setCost(e.target.value)} className="border border-gray-200 rounded-lg px-3 py-2 text-sm w-24" />
            </div>
            <div className="flex flex-col gap-1">
              <label className="text-[10px] text-gray-400">التصنيف</label>
              <input type="text" value={category} onChange={(e) => setCategory(e.target.value)} className="border border-gray-200 rounded-lg px-3 py-2 text-sm w-28" />
            </div>
            <div className="flex flex-col gap-1">
              <label className="text-[10px] text-gray-400">الحد الأدنى</label>
              <input type="number" value={minStock} onChange={(e) => setMinStock(e.target.value)} className="border border-gray-200 rounded-lg px-3 py-2 text-sm w-20" />
            </div>
            <button onClick={() => createMutation.mutate({ name, unit, category, default_cost: parseFloat(cost || '0'), min_stock_level: minStock ? parseFloat(minStock) : null })}
              disabled={!name || !unit || createMutation.isPending}
              className="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">➕ إضافة</button>
          </div>
        </div>

        {/* شريط البحث */}
        <div className="flex items-center gap-3">
          <div className="relative">
            <span className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-300 text-sm">🔍</span>
            <input type="text" placeholder="بحث..." value={search} onChange={(e) => setSearch(e.target.value)}
              className="border border-gray-200 rounded-lg pr-8 pl-3 py-2 text-sm w-56 outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-100" />
          </div>
          {search ? (
            <button onClick={() => setSearch('')} className="text-xs text-gray-400 hover:text-gray-600">مسح</button>
          ) : null}
          <span className="text-xs text-gray-400 mr-auto">{filteredItems.length} / {items?.length || 0} صنف</span>
        </div>

        {/* جدول الأصناف */}
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm" dir="rtl">
              <thead>
                <tr className="bg-gray-50 text-xs text-gray-500 border-b border-gray-100">
                  <th className="px-3 py-3 font-medium w-14 text-center cursor-pointer select-none text-gray-400 hover:text-gray-600" onClick={() => { setSortKey('sort_order'); setSortDir('asc'); }} title="الترتيب الأصلي">#</th>
                  <th className="px-4 py-3 font-medium cursor-pointer select-none text-right" onClick={() => toggleSort('name')}>
                    <SortIcon k="name" />الاسم
                  </th>
                  <th className="px-3 py-3 font-medium cursor-pointer select-none text-right" onClick={() => toggleSort('unit')}>
                    <SortIcon k="unit" />الوحدة
                  </th>
                  <th className="px-3 py-3 font-medium cursor-pointer select-none text-right" onClick={() => toggleSort('default_cost')}>
                    <SortIcon k="default_cost" />السعر
                  </th>
                  <th className="px-3 py-3 font-medium cursor-pointer select-none text-right" onClick={() => toggleSort('min_stock_level')}>
                    <SortIcon k="min_stock_level" />الحد الأدنى
                  </th>
                  <th className="px-3 py-3 font-medium text-right">المخزن</th>
                  <th className="px-3 py-3 font-medium cursor-pointer select-none text-right" onClick={() => toggleSort('category')}>
                    <SortIcon k="category" />التصنيف
                  </th>
                  <th className="px-3 py-3 font-medium text-center">إجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {isLoading ? (
                  <tr><td colSpan={8} className="px-6 py-16 text-center text-gray-300">جاري التحميل...</td></tr>
                ) : filteredItems.length === 0 ? (
                  <tr><td colSpan={8} className="px-6 py-16 text-center text-gray-300">لا توجد أصناف مطابقة.</td></tr>
                ) : filteredItems.flatMap((item: any, idx: number) => {
                  const isEditing = editingId === item.id;
                  const currentWarehouse = warehouses?.find((w: any) => w.id === item.default_warehouse_id);
                  const rows: React.ReactNode[] = [];
                  if (insertId === item.id && insertPos === 'before') {
                    rows.push(
                      <tr key={`insert-${item.id}`} className="bg-indigo-50/70">
                        <td colSpan={8} className="px-4 py-2.5">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-[11px] text-indigo-500 font-medium whitespace-nowrap">
                              إضافة قبل "{item.name}":
                            </span>
                            <input ref={insertRef} type="text" placeholder="اسم الصنف..." value={insertName} onChange={(e) => setInsertName(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-36 outline-none focus:border-indigo-400" />
                            <input type="text" placeholder="الوحدة" value={insertUnit} onChange={(e) => setInsertUnit(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-20 outline-none focus:border-indigo-400" />
                            <input type="number" placeholder="السعر" value={insertCost} onChange={(e) => setInsertCost(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-24 outline-none focus:border-indigo-400" />
                            <input type="text" placeholder="التصنيف..." value={insertCategory} onChange={(e) => setInsertCategory(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-28 outline-none focus:border-indigo-400" />
                            <input type="number" placeholder="الحد الأدنى" value={insertMinStock} onChange={(e) => setInsertMinStock(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-24 outline-none focus:border-indigo-400" />
                            <button onClick={confirmInsert} disabled={!insertName.trim() || createMutation.isPending}
                              className="px-3 py-1.5 bg-indigo-600 text-white rounded text-xs font-medium hover:bg-indigo-700 disabled:opacity-50">إضافة</button>
                            <button onClick={() => setInsertId(null)} className="text-gray-400 hover:text-gray-600 text-xs">إلغاء</button>
                          </div>
                        </td>
                      </tr>
                    );
                  }
                  rows.push(
                    <tr key={item.id} className="hover:bg-gray-50/70 transition-colors group">
                      <td className="px-3 py-3 text-center align-middle">
                        <div className="flex flex-col items-center gap-0 relative">
                          <button onClick={() => startInsert(item.id, 'before')}
                            className="opacity-0 group-hover:opacity-100 text-[9px] leading-none text-indigo-300 hover:text-indigo-500 transition-opacity" title="إدراج قبله">+
                          </button>
                          <span className="text-gray-400 text-xs font-mono leading-tight">{idx + 1}</span>
                          <button onClick={() => startInsert(item.id, 'after')}
                            className="opacity-0 group-hover:opacity-100 text-[9px] leading-none text-indigo-300 hover:text-indigo-500 transition-opacity" title="إدراج بعده">+
                          </button>
                        </div>
                      </td>

                      <td className="px-4 py-3 font-medium text-gray-900">
                        {isEditing ? (
                          <input type="text" value={editData.name} onChange={(e) => setEditData({...editData, name: e.target.value})} className="border border-gray-300 rounded px-2 py-1 w-full text-sm" />
                        ) : (item.name)}
                      </td>
                      <td className="px-3 py-3 text-gray-500">
                        {isEditing ? (
                          <input type="text" value={editData.unit} onChange={(e) => setEditData({...editData, unit: e.target.value})} className="border border-gray-300 rounded px-2 py-1 w-16 text-sm" />
                        ) : (<span className="text-gray-400">{item.unit}</span>)}
                      </td>
                      <td className="px-3 py-3 font-semibold text-blue-600">
                        {isEditing ? (
                          <input type="number" value={editData.default_cost} onChange={(e) => setEditData({...editData, default_cost: parseFloat(e.target.value) || 0})} className="border border-gray-300 rounded px-2 py-1 w-20 text-left text-sm" dir="ltr" />
                        ) : (<span dir="ltr">{Number(item.default_cost || 0).toLocaleString()} ج.م</span>)}
                      </td>
                      <td className="px-3 py-3 text-gray-500">
                        {isEditing ? (
                          <input type="number" value={editData.min_stock_level} onChange={(e) => setEditData({...editData, min_stock_level: e.target.value})} className="border border-gray-300 rounded px-2 py-1 w-16 text-sm" />
                        ) : (<span>{item.min_stock_level ?? '—'}</span>)}
                      </td>
                      <td className="px-3 py-3 text-gray-500">
                        {isEditing ? (
                          <select value={editData.default_warehouse_id} onChange={(e) => setEditData({...editData, default_warehouse_id: e.target.value})} className="border border-gray-300 rounded px-2 py-1 w-28 text-xs">
                            <option value="">-- اختر --</option>
                            {warehouses?.map((w: any) => (<option key={w.id} value={w.id}>{w.name}</option>))}
                          </select>
                        ) : (
                          <span className={`text-[11px] px-2 py-0.5 rounded ${currentWarehouse ? 'bg-gray-100 text-gray-600' : 'text-gray-300 italic'}`}>
                            {currentWarehouse ? currentWarehouse.name : 'رئيسي'}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-3 text-gray-500">
                        {isEditing ? (
                          <input type="text" value={editData.category} onChange={(e) => setEditData({...editData, category: e.target.value})} className="border border-gray-300 rounded px-2 py-1 w-28 text-sm" />
                        ) : (<span>{item.category}</span>)}
                      </td>

                      <td className="px-3 py-3 text-center">
                        {isEditing ? (
                          <div className="flex items-center justify-center gap-1.5">
                            <button onClick={() => saveEdit(item.id)} className="px-2.5 py-1 text-green-700 bg-green-50 rounded text-xs font-medium hover:bg-green-100">حفظ</button>
                            <button onClick={() => setEditingId(null)} className="px-2.5 py-1 text-gray-500 bg-gray-50 rounded text-xs hover:bg-gray-100">إلغاء</button>
                          </div>
                        ) : (
                          <div className="flex items-center justify-center gap-1">
                            <button onClick={() => startEditing(item)} className="w-7 h-7 flex items-center justify-center rounded text-gray-400 hover:text-blue-600 hover:bg-blue-50" title="تعديل">✏️</button>
                            <button onClick={() => moveUpMutation.mutate(item.id)} className="w-7 h-7 flex items-center justify-center rounded text-gray-400 hover:text-orange-600 hover:bg-orange-50" title="نقل لأعلى">⏫</button>
                            <button onClick={() => moveBottomMutation.mutate(item.id)} className="w-7 h-7 flex items-center justify-center rounded text-gray-400 hover:text-orange-600 hover:bg-orange-50" title="نقل لأسفل">⏬</button>
                            <button onClick={() => { if (window.confirm('هل أنت متأكد من حذف هذا الصنف؟')) deleteMutation.mutate(item.id); }}
                              className="w-7 h-7 flex items-center justify-center rounded text-gray-400 hover:text-red-600 hover:bg-red-50" title="حذف">🗑️</button>
                          </div>
                        )}
                      </td>
                    </tr>
                  );
                  if (insertId === item.id && insertPos === 'after') {
                    rows.push(
                      <tr key={`insert-${item.id}`} className="bg-indigo-50/70">
                        <td colSpan={8} className="px-4 py-2.5">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-[11px] text-indigo-500 font-medium whitespace-nowrap">
                              إضافة بعد "{item.name}":
                            </span>
                            <input ref={insertRef} type="text" placeholder="اسم الصنف..." value={insertName} onChange={(e) => setInsertName(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-36 outline-none focus:border-indigo-400" />
                            <input type="text" placeholder="الوحدة" value={insertUnit} onChange={(e) => setInsertUnit(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-20 outline-none focus:border-indigo-400" />
                            <input type="number" placeholder="السعر" value={insertCost} onChange={(e) => setInsertCost(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-24 outline-none focus:border-indigo-400" />
                            <input type="text" placeholder="التصنيف..." value={insertCategory} onChange={(e) => setInsertCategory(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-28 outline-none focus:border-indigo-400" />
                            <input type="number" placeholder="الحد الأدنى" value={insertMinStock} onChange={(e) => setInsertMinStock(e.target.value)}
                              className="border border-gray-200 rounded px-2.5 py-1.5 text-sm w-24 outline-none focus:border-indigo-400" />
                            <button onClick={confirmInsert} disabled={!insertName.trim() || createMutation.isPending}
                              className="px-3 py-1.5 bg-indigo-600 text-white rounded text-xs font-medium hover:bg-indigo-700 disabled:opacity-50">إضافة</button>
                            <button onClick={() => setInsertId(null)} className="text-gray-400 hover:text-gray-600 text-xs">إلغاء</button>
                          </div>
                        </td>
                      </tr>
                    );
                  }
                  return rows;
                })}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {importItems.length > 0 && (
        <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
          <div className="bg-white rounded-xl shadow-xl w-[90vw] max-w-4xl max-h-[85vh] flex flex-col">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
              <h2 className="text-lg font-semibold">نتائج الاستيراد</h2>
              <button onClick={() => setImportItems([])} className="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <div className="flex-1 overflow-y-auto p-6">
              <table className="w-full text-sm" dir="rtl">
                <thead>
                  <tr className="bg-gray-50 text-xs text-gray-500 border-b border-gray-100">
                    <th className="px-3 py-2 font-medium">#</th>
                    <th className="px-3 py-2 font-medium text-right">الاسم</th>
                    <th className="px-3 py-2 font-medium text-right">الوحدة</th>
                    <th className="px-3 py-2 font-medium text-right">السعر</th>
                    <th className="px-3 py-2 font-medium text-right">التصنيف</th>
                    <th className="px-3 py-2 font-medium text-right">المخزن</th>
                    <th className="px-3 py-2 font-medium text-right">الحد الأدنى</th>
                    <th className="px-3 py-2 font-medium text-center"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {importItems.map((item, i) => {
                    const isDup = importDuplicateNames.includes(item.name);
                    const ed = importEdit[item.id];
                    return (
                      <tr key={item.id} className={isDup ? 'bg-yellow-50' : ''}>
                        <td className="px-3 py-2.5 text-center text-gray-400 text-xs">{i + 1}</td>
                        <td className="px-3 py-2.5">
                          <input type="text" value={ed?.name || ''} onChange={(e) => setImportEdit(p => ({ ...p, [item.id]: { ...p[item.id], name: e.target.value } }))}
                            className="border border-gray-200 rounded px-2 py-1 w-full text-sm outline-none focus:border-blue-400" />
                        </td>
                        <td className="px-3 py-2.5">
                          <input type="text" value={ed?.unit || ''} onChange={(e) => setImportEdit(p => ({ ...p, [item.id]: { ...p[item.id], unit: e.target.value } }))}
                            className="border border-gray-200 rounded px-2 py-1 w-16 text-sm outline-none focus:border-blue-400" />
                        </td>
                        <td className="px-3 py-2.5">
                          <input type="number" value={ed?.default_cost || 0} onChange={(e) => setImportEdit(p => ({ ...p, [item.id]: { ...p[item.id], default_cost: parseFloat(e.target.value) || 0 } }))}
                            className="border border-gray-200 rounded px-2 py-1 w-20 text-sm outline-none focus:border-blue-400 text-left" dir="ltr" />
                        </td>
                        <td className="px-3 py-2.5">
                          <input type="text" value={ed?.category || ''} onChange={(e) => setImportEdit(p => ({ ...p, [item.id]: { ...p[item.id], category: e.target.value } }))}
                            className="border border-gray-200 rounded px-2 py-1 w-28 text-sm outline-none focus:border-blue-400" />
                        </td>
                        <td className="px-3 py-2.5">
                          <select value={ed?.default_warehouse_id || ''} onChange={(e) => setImportEdit(p => ({ ...p, [item.id]: { ...p[item.id], default_warehouse_id: e.target.value } }))}
                            className="border border-gray-200 rounded px-2 py-1 w-28 text-xs outline-none focus:border-blue-400">
                            <option value="">-- اختر --</option>
                            {warehouses?.map((w: any) => (<option key={w.id} value={w.id}>{w.name}</option>))}
                          </select>
                        </td>
                        <td className="px-3 py-2.5">
                          <input type="number" value={ed?.min_stock_level ?? ''} onChange={(e) => setImportEdit(p => ({ ...p, [item.id]: { ...p[item.id], min_stock_level: e.target.value } }))}
                            className="border border-gray-200 rounded px-2 py-1 w-16 text-sm outline-none focus:border-blue-400" />
                        </td>
                        <td className="px-3 py-2.5 text-center">
                          <button onClick={() => {
                            updateMutation.mutate({ id: item.id, data: importEdit[item.id] });
                          }} disabled={updateMutation.isPending}
                            className="px-2.5 py-1 text-green-700 bg-green-50 rounded text-xs font-medium hover:bg-green-100 disabled:opacity-50">حفظ</button>
                          {isDup && <span className="block text-[10px] text-amber-600 mt-1">مكرر</span>}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <div className="flex justify-between items-center px-6 py-4 border-t border-gray-100">
              <span className="text-xs text-gray-400">{importItems.length} صنف{importDuplicateNames.length > 0 ? ` — ${importDuplicateNames.length} مكرر` : ''}</span>
              <div className="flex gap-2">
                <button onClick={() => setImportItems([])}
                  className="px-4 py-2 text-gray-500 bg-gray-50 rounded-lg text-sm font-medium hover:bg-gray-100">إغلاق</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}