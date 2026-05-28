'use client';

import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import toast from 'react-hot-toast';
import { SearchableSelect } from '@/components/ui/SearchableSelect';

export default function MappingsPage() {
  const { currentClient } = useAuthStore();
  const [tab, setTab] = useState<'items' | 'locations'>('items');
  const [search, setSearch] = useState('');
  const [editId, setEditId] = useState<string | null>(null);
  const [editSource, setEditSource] = useState('');
  const [editTargetId, setEditTargetId] = useState('');
  const [editComposite, setEditComposite] = useState('');
  const [oldItemId, setOldItemId] = useState<string | null>(null);
  const [remapping, setRemapping] = useState(false);
  const [showAddModal, setShowAddModal] = useState(false);
  const [addSourceName, setAddSourceName] = useState('');
  const [addItemId, setAddItemId] = useState('');
  const [locSearch, setLocSearch] = useState('');
  const [editVoucherType, setEditVoucherType] = useState('');

  const voucherTypeOptions = [
    { value: '', label: '— تلقائي —' },
    { value: 'purchase', label: 'إذن استلام (وارد)' },
    { value: 'dispatch', label: 'إذن صرف (منصرف)' },
    { value: 'withdrawal', label: 'سحب' },
    { value: 'return', label: 'مرتجع' },
    { value: 'adjustment', label: 'تسوية' },
    { value: 'opening', label: 'افتتاحي' },
  ];

  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['mappings', currentClient?.id],
    queryFn: () => api.get('/mappings').then(r => r.data),
    enabled: !!currentClient,
    refetchOnMount: true,
    staleTime: 0,
  });

  const { data: allItems = [] } = useQuery({
    queryKey: ['items', currentClient?.id, 'mappings'],
    queryFn: () => api.get('/items').then(r => r.data),
    enabled: !!currentClient,
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses', currentClient?.id, 'mappings'],
    queryFn: () => api.get('/warehouses').then(r => r.data),
    enabled: !!currentClient,
  });

  const { data: branches = [] } = useQuery({
    queryKey: ['branches', currentClient?.id],
    queryFn: () => api.get('/branches').then(r => r.data),
    enabled: !!currentClient,
  });

  const updateItemMapping = useMutation({
    mutationFn: ({ mapping_id, source_name, item_id }: any) =>
      api.post('/mappings/item', { mapping_id, source_name, item_id }),
  });

  const remapMutation = useMutation({
    mutationFn: ({ source_name, old_item_id, new_item_id }: any) =>
      api.post('/mappings/remap-item', { source_name, old_item_id, new_item_id }),
  });

  const updateLocationMapping = useMutation({
    mutationFn: ({ source_name, target_type, target_id, voucher_type }: any) =>
      api.post('/mappings/location', { source_name, target_type, target_id, voucher_type }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['mappings', currentClient?.id] });
      setEditId(null);
      toast.success('تم تحديث الربط');
    },
    onError: () => { toast.error('فشل تحديث الربط'); },
  });

  const deleteItemMapping = useMutation({
    mutationFn: (id: string) => api.delete(`/mappings/item/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['mappings', currentClient?.id] });
      toast.success('تم حذف الربط');
    },
    onError: () => { toast.error('فشل حذف الربط'); },
  });

  const deleteLocationMapping = useMutation({
    mutationFn: (id: string) => api.delete(`/mappings/location/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['mappings', currentClient?.id] });
      toast.success('تم حذف الربط');
    },
    onError: () => { toast.error('فشل حذف الربط'); },
  });

  const startEdit = (m: any) => {
    setEditId(m.id);
    setEditSource(m.source_name);
    setEditTargetId(m.item_id ?? '');
    setOldItemId(m.item_id ?? null);
  };

  const startEditLocation = (m: any) => {
    setEditId(m.id);
    setEditSource(m.source_name);
    setEditComposite(`${m.target_type}::${m.target_id}`);
    setEditVoucherType(m.voucher_type ?? '');
  };

  const saveItemEdit = () => {
    if (!editSource || !editTargetId) return;
    const oldId = oldItemId;
    updateItemMapping.mutate(
      { mapping_id: editId, source_name: editSource, item_id: editTargetId },
      {
        onSuccess: () => {
          queryClient.invalidateQueries({ queryKey: ['mappings', currentClient?.id] });
          setEditId(null);
          toast.success('تم تحديث الربط');
          if (oldId && oldId !== editTargetId) {
            setTimeout(() => {
              if (window.confirm('هل تريد إعادة ربط جميع الإدخالات السابقة لهذا الاسم بالصنف الجديد؟')) {
                setRemapping(true);
                remapMutation.mutate(
                  { source_name: editSource, old_item_id: oldId, new_item_id: editTargetId },
                  {
                    onSuccess: (res: any) => {
                      toast.success(res.data?.message || 'تم إعادة ربط الإدخالات السابقة');
                    },
                    onError: () => { toast.error('فشل إعادة ربط الإدخالات السابقة'); },
                    onSettled: () => { setRemapping(false); },
                  }
                );
              }
            }, 200);
          }
        },
        onError: () => { toast.error('فشل تحديث الربط'); },
      }
    );
  };

  const saveLocationEdit = () => {
    const [type, id] = editComposite.split('::');
    if (!editSource || !id) return;
    updateLocationMapping.mutate({ source_name: editSource, target_type: type, target_id: id, voucher_type: editVoucherType || null });
  };

  const cancelEdit = () => {
    setEditId(null);
    setEditTargetId('');
    setEditComposite('');
  };

  const confirmDelete = (type: 'item' | 'location', id: string) => {
    if (window.confirm('هل أنت متأكد من حذف هذا الربط؟')) {
      if (type === 'item') deleteItemMapping.mutate(id);
      else deleteLocationMapping.mutate(id);
    }
  };

  const saveNewMapping = () => {
    if (!addSourceName.trim() || !addItemId) return;
    updateItemMapping.mutate(
      { source_name: addSourceName.trim(), item_id: addItemId },
      {
        onSuccess: () => {
          queryClient.invalidateQueries({ queryKey: ['mappings', currentClient?.id] });
          setShowAddModal(false);
          setAddSourceName('');
          setAddItemId('');
          toast.success('تم إضافة الربط');
        },
        onError: () => { toast.error('فشل إضافة الربط'); },
      }
    );
  };

  const isNew = (id: string) => id.startsWith('__new__') || id.startsWith('__manual__');

  const confidenceBadge = (val: number | null | undefined) => {
    if (val == null || val === 0) return <span className="text-xs text-gray-400">—</span>;
    if (val >= 100) return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">100</span>;
    if (val >= 70) return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">{val}</span>;
    return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">{val}</span>;
  };

  const filteredLocations = useMemo(() => {
    const list = data?.locations || [];
    if (!locSearch.trim()) return list;
    const q = locSearch.toLowerCase();
    return list.filter((m: any) =>
      (m.source_name || '').toLowerCase().includes(q)
      || (m.target_name || '').toLowerCase().includes(q)
    );
  }, [data?.locations, locSearch]);

  const locations = data?.locations ?? [];

  // Flat item mappings list
  const filteredMappings = useMemo(() => {
    const list = (data?.items || []).filter((m: any) => {
      if (!search.trim()) return true;
      const q = search.toLowerCase();
      return (m.source_name || '').toLowerCase().includes(q)
        || (m.item?.name || '').toLowerCase().includes(q)
        || (m.context || '').toLowerCase().includes(q);
    });
    return list.sort((a: any, b: any) => {
      const aNew = isNew(a.id) ? 0 : 1;
      const bNew = isNew(b.id) ? 0 : 1;
      if (aNew !== bNew) return aNew - bNew;
      return (a.source_name || '').localeCompare(b.source_name || '', 'ar');
    });
  }, [data?.items, search]);

  return (
    <div className="p-4 max-w-5xl mx-auto" dir="rtl">
      <div className="mb-4 flex items-center justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-xl font-bold text-gray-800">إدارة ربط الأسماء</h1>
            {remapping && (
              <span className="inline-flex items-center gap-1.5 text-xs text-blue-600 bg-blue-50 px-2.5 py-1 rounded-full">
                <svg className="animate-spin h-3 w-3" viewBox="0 0 24 24" fill="none">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                جاري إعادة الربط...
              </span>
            )}
          </div>
          <p className="text-sm text-gray-500 mt-1">مراجعة وتعديل روابط أسماء الملفات بالأصناف والمواقع في النظام</p>
        </div>
      </div>

      <div className="flex gap-2 mb-4 border-b border-gray-200 pb-2">
        <button
          onClick={() => { setTab('items'); cancelEdit(); }}
          className={`px-4 py-2 text-sm font-medium rounded-t-lg transition-colors ${tab === 'items' ? 'bg-blue-50 text-blue-700 border-b-2 border-blue-500' : 'text-gray-500 hover:text-gray-700'}`}
        >
          ربط الأصناف
        </button>
        <button
          onClick={() => { setTab('locations'); cancelEdit(); }}
          className={`px-4 py-2 text-sm font-medium rounded-t-lg transition-colors ${tab === 'locations' ? 'bg-blue-50 text-blue-700 border-b-2 border-blue-500' : 'text-gray-500 hover:text-gray-700'}`}
        >
          ربط المواقع
        </button>
      </div>

      {/* Search bar + Add button — items tab only */}
      {tab === 'items' && (
        <div className="mb-4 flex items-center justify-between gap-2">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="ابحث في الاسم المصدر أو الصنف..."
            className="max-w-md border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <button
            onClick={() => setShowAddModal(true)}
            className="shrink-0 bg-blue-600 text-white px-4 py-2.5 rounded-xl text-sm font-medium hover:bg-blue-700 transition-colors"
          >
            + إضافة ربط جديد
          </button>
        </div>
      )}

      {isLoading ? (
        <div className="text-center py-8 text-gray-400">جاري التحميل...</div>
      ) : tab === 'items' ? (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 text-gray-500 text-xs">
                <th className="px-4 py-3 text-right font-medium">الاسم في الملف</th>
                <th className="px-4 py-3 text-right font-medium">← الصنف في النظام</th>
                <th className="px-4 py-3 text-right font-medium">السياق</th>
                <th className="px-4 py-3 text-center font-medium w-16">الثقة</th>
                <th className="px-4 py-3 text-center font-medium w-24">إجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {filteredMappings.length === 0 && (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">
                  {search ? 'لا توجد نتائج للبحث' : 'لا توجد روابط أصناف'}
                </td></tr>
              )}
              {filteredMappings.map((m: any) => (
                <tr key={m.id} className="hover:bg-gray-50/50">
                  <td className="px-4 py-2.5 font-medium text-gray-700">{m.source_name}</td>
                  {editId === m.id ? (
                    <>
                      <td className="px-4 py-2.5">
                        <SearchableSelect
                          value={editTargetId}
                          onChange={setEditTargetId}
                          options={allItems}
                          placeholder="اختر الصنف..."
                        />
                      </td>
                      <td className="px-4 py-2.5 text-gray-400 text-xs">{m.context || '—'}</td>
                      <td className="px-4 py-2.5 text-center text-gray-400">—</td>
                      <td className="px-4 py-2.5 text-center whitespace-nowrap">
                        <button onClick={saveItemEdit} className="text-green-600 hover:text-green-800 text-xs font-bold ml-2">حفظ</button>
                        <button onClick={cancelEdit} className="text-gray-400 hover:text-gray-600 text-xs">إلغاء</button>
                      </td>
                    </>
                  ) : (
                    <>
                      <td className="px-4 py-2.5">
                        {m.item?.name ? (
                          <span className="inline-flex items-center gap-1.5 bg-blue-50 text-blue-700 font-medium px-2.5 py-1 rounded-lg text-xs">
                            {m.item?.name}
                          </span>
                        ) : (
                          <span className="text-red-400 text-xs">— غير مرتبط</span>
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-gray-400 text-xs">{m.context || '—'}</td>
                      <td className="px-4 py-2.5 text-center">{confidenceBadge(m.confidence)}</td>
                      <td className="px-4 py-2.5 text-center whitespace-nowrap">
                        <button onClick={() => startEdit(m)} className="text-blue-500 hover:text-blue-700 text-xs ml-3">
                          {isNew(m.id) ? 'إضافة ربط' : 'تعديل'}
                        </button>
                        {!isNew(m.id) && (
                          <button onClick={() => confirmDelete('item', m.id)} className="text-red-400 hover:text-red-600 text-xs">حذف</button>
                        )}
                      </td>
                    </>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
          <div className="px-4 py-2 border-t border-gray-50 text-xs text-gray-400">
            إجمالي: {filteredMappings.length} ربط
          </div>
        </div>
      ) : (
        <>
        {/* Locations search */}
        <div className="mb-4 flex items-center justify-between gap-2">
          <input
            type="text"
            value={locSearch}
            onChange={(e) => setLocSearch(e.target.value)}
            placeholder="ابحث في اسم الموقع..."
            className="max-w-md border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 text-gray-500 text-xs">
                <th className="px-4 py-3 text-right font-medium">الاسم في الملف</th>
                <th className="px-4 py-3 text-right font-medium">الموقع في النظام</th>
                <th className="px-4 py-3 text-right font-medium">النوع</th>
                <th className="px-4 py-3 text-right font-medium w-32">نوع الإذن</th>
                <th className="px-4 py-3 text-center font-medium w-16">الثقة</th>
                <th className="px-4 py-3 text-center font-medium w-24">إجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {filteredLocations.length === 0 && (
                <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">
                  {locSearch ? 'لا توجد نتائج للبحث' : 'لا توجد روابط مواقع'}
                </td></tr>
              )}
              {filteredLocations.map((m: any) => {
                const selectVal = editId === m.id ? editComposite : `${m.target_type}::${m.target_id}`;
                return (
                  <tr key={m.id} className="hover:bg-gray-50/50">
                    <td className="px-4 py-2.5 font-medium text-gray-700">{m.source_name}</td>
                    {editId === m.id ? (
                      <>
                        <td className="px-4 py-2.5">
                          <select
                            value={selectVal}
                            onChange={(e) => setEditComposite(e.target.value)}
                            className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                          >
                            <option value="">اختر الموقع...</option>
                            <optgroup label="المخازن">
                              {warehouses.map((w: any) => (
                                <option key={`wh-${w.id}`} value={`warehouse::${w.id}`}>{w.name}</option>
                              ))}
                            </optgroup>
                            <optgroup label="الفروع">
                              {branches.map((b: any) => (
                                <option key={`br-${b.id}`} value={`branch::${b.id}`}>{b.name}</option>
                              ))}
                            </optgroup>
                          </select>
                        </td>
                        <td className="px-4 py-2.5 text-gray-400 text-xs">—</td>
                        <td className="px-4 py-2.5">
                          <select
                            value={editVoucherType}
                            onChange={(e) => setEditVoucherType(e.target.value)}
                            className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                          >
                            {voucherTypeOptions.map(o => (
                              <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                          </select>
                        </td>
                        <td className="px-4 py-2.5 text-center text-gray-400">—</td>
                        <td className="px-4 py-2.5 text-center whitespace-nowrap">
                          <button onClick={saveLocationEdit} className="text-green-600 hover:text-green-800 text-xs font-bold ml-2">حفظ</button>
                          <button onClick={cancelEdit} className="text-gray-400 hover:text-gray-600 text-xs">إلغاء</button>
                        </td>
                      </>
                    ) : (
                      <>
                        <td className="px-4 py-2.5 text-blue-700 font-medium">{m.target_name || '—'}</td>
                        <td className="px-4 py-2.5 text-gray-400 text-xs">{m.target_type === 'warehouse' ? 'مخزن' : 'فرع'}</td>
                        <td className="px-4 py-2.5 text-gray-500 text-xs">{voucherTypeOptions.find(o => o.value === m.voucher_type)?.label || '—'}</td>
                        <td className="px-4 py-2.5 text-center">{confidenceBadge(m.confidence)}</td>
                        <td className="px-4 py-2.5 text-center whitespace-nowrap">
                          <button onClick={() => startEditLocation(m)} className="text-blue-500 hover:text-blue-700 text-xs ml-3">تعديل</button>
                          <button onClick={() => confirmDelete('location', m.id)} className="text-red-400 hover:text-red-600 text-xs">حذف</button>
                        </td>
                      </>
                    )}
                  </tr>
                );
              })}
            </tbody>
          </table>
          <div className="px-4 py-2 border-t border-gray-50 text-xs text-gray-400">
            إجمالي: {filteredLocations.length} ربط
          </div>
        </div>
        </>
      )}

      {/* Add new mapping modal */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setShowAddModal(false)}>
          <div className="bg-white rounded-xl shadow-xl p-6 w-full max-w-md mx-4" onClick={(e) => e.stopPropagation()}>
            <h2 className="text-lg font-bold mb-4">إضافة ربط جديد</h2>
            <div className="space-y-4">
              <div>
                <label className="block text-sm text-gray-600 mb-1">الاسم في الملف</label>
                <input
                  type="text"
                  value={addSourceName}
                  onChange={(e) => setAddSourceName(e.target.value)}
                  className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                  placeholder="أدخل اسم الصنف في الملف المصدر..."
                  autoFocus
                />
              </div>
              <div>
                <label className="block text-sm text-gray-600 mb-1">الصنف في النظام</label>
                <SearchableSelect value={addItemId} onChange={setAddItemId} options={allItems} placeholder="اختر الصنف..." />
              </div>
            </div>
            <div className="flex justify-end gap-2 mt-6">
              <button onClick={() => setShowAddModal(false)} className="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">إلغاء</button>
              <button
                onClick={saveNewMapping}
                disabled={!addSourceName.trim() || !addItemId}
                className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                حفظ
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
