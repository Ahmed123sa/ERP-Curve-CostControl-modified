'use client';
import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

interface SlaughterItem {
  id?: string;
  item_id: string;
  warehouse_id: string;
  unit: string;
  weight: string;
  selling_price: string;
}

interface SlaughterForm {
  date: string;
  animal_name: string;
  live_weight: string;
  price_per_kg: string;
  transport_slaughter_cost: string;
  notes: string;
  items: SlaughterItem[];
}

export default function SlaughterPage() {
  const qc = useQueryClient();
  const today = new Date().toISOString().slice(0, 10);

  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<SlaughterForm>({
    date: today, animal_name: '', live_weight: '', price_per_kg: '',
    transport_slaughter_cost: '', notes: '', items: [],
  });

  const { data: slaughters = [], isLoading } = useQuery({
    queryKey: ['slaughters'],
    queryFn: () => api.get('/production/slaughter').then(r => r.data),
  });

  const { data: items } = useQuery({
    queryKey: ['items-select'],
    queryFn: () => api.get('/items?per_page=500').then(r => r.data?.data ?? r.data ?? []),
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then(r => r.data),
  });

  const { data: slaughterDetail, refetch: refetchDetail } = useQuery({
    queryKey: ['slaughter', selectedId],
    queryFn: () => api.get(`/production/slaughter/${selectedId}`).then(r => r.data),
    enabled: !!selectedId,
  });

  useEffect(() => {
    if (slaughterDetail) {
      const s = slaughterDetail;
      setForm({
        date: s.date || today,
        animal_name: s.animal_name || '',
        live_weight: String(s.live_weight ?? ''),
        price_per_kg: String(s.price_per_kg ?? ''),
        transport_slaughter_cost: String(s.transport_slaughter_cost ?? ''),
        notes: s.notes || '',
        items: (s.items || []).map((i: any) => ({
          id: i.id,
          item_id: i.item_id,
          warehouse_id: i.warehouse_id || '',
          unit: i.unit || 'كجم',
          weight: String(i.weight ?? ''),
          selling_price: String(i.selling_price ?? ''),
        })),
      });
      setShowForm(true);
    }
  }, [slaughterDetail, today]);

  const saveMutation = useMutation({
    mutationFn: (data: any) => {
      const url = selectedId ? `/production/slaughter/${selectedId}` : '/production/slaughter';
      return api[selectedId ? 'put' : 'post'](url, data);
    },
    onSuccess: () => {
      toast.success(selectedId ? 'تم تحديث التصفية' : 'تم إضافة التصفية');
      qc.invalidateQueries({ queryKey: ['slaughters'] });
      cancelEdit();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في الحفظ'),
  });

  const postMutation = useMutation({
    mutationFn: (id: string) => api.post(`/production/slaughter/${id}/post`),
    onSuccess: (r: any) => {
      toast.success(r.data?.message || 'تم الترحيل');
      qc.invalidateQueries({ queryKey: ['slaughters'] });
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في الترحيل'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/production/slaughter/${id}`),
    onSuccess: () => {
      toast.success('تم حذف التصفية');
      qc.invalidateQueries({ queryKey: ['slaughters'] });
      cancelEdit();
    },
    onError: () => toast.error('خطأ في الحذف'),
  });

  const cancelEdit = () => {
    setSelectedId(null);
    setShowForm(false);
    setForm({ date: today, animal_name: '', live_weight: '', price_per_kg: '', transport_slaughter_cost: '', notes: '', items: [] });
  };

  const editSlaughter = (id: string) => {
    setSelectedId(id);
    refetchDetail();
  };

  const itemsList = Array.isArray(items) ? items : [];
  const warehousesList = Array.isArray(warehouses) ? warehouses : [];

  const addItem = () => {
    setForm(prev => ({ ...prev, items: [...prev.items, { item_id: '', warehouse_id: '', unit: 'كجم', weight: '', selling_price: '' }] }));
  };

  const updateItem = (idx: number, field: keyof SlaughterItem, val: string) => {
    const updated = [...form.items];
    (updated[idx] as any)[field] = val;
    setForm({ ...form, items: updated });
  };

  const removeItem = (idx: number) => {
    setForm({ ...form, items: form.items.filter((_, i) => i !== idx) });
  };

  const handleSave = () => {
    if (!form.animal_name.trim()) { toast('اكتب نوع الدبيحة'); return; }
    if (!form.items.length) { toast('أضف صنف واحد على الأقل من المخرجات'); return; }

    saveMutation.mutate({
      date: form.date,
      animal_name: form.animal_name.trim(),
      live_weight: parseFloat(form.live_weight) || 0,
      price_per_kg: parseFloat(form.price_per_kg) || 0,
      transport_slaughter_cost: parseFloat(form.transport_slaughter_cost) || 0,
      notes: form.notes || undefined,
      items: form.items.map((item, idx) => ({
        id: item.id || undefined,
        item_id: item.item_id,
        warehouse_id: item.warehouse_id || null,
        unit: item.unit || 'كجم',
        weight: parseFloat(item.weight) || 0,
        selling_price: parseFloat(item.selling_price) || 0,
        sort_order: idx,
      })),
    });
  };

  const liveCost = (parseFloat(form.live_weight) || 0) * (parseFloat(form.price_per_kg) || 0);
  const transportCost = parseFloat(form.transport_slaughter_cost) || 0;
  const grandTotalCost = liveCost + transportCost;

  const itemsById = Object.fromEntries(itemsList.map((i: any) => [i.id, i]));
  const warehousesById = Object.fromEntries(warehousesList.map((w: any) => [w.id, w]));

  return (
    <div className="space-y-4" dir="rtl">
      {/* ── Header ───────────────────────────────────── */}
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold text-gray-800">تصفية ذبيحة</h2>
        <button onClick={() => { cancelEdit(); setShowForm(true); }}
          className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
          ➕ تصفية جديدة
        </button>
      </div>

      {/* ── History List ──────────────────────────────── */}
      {!showForm && (
        isLoading ? (
          <p className="text-gray-400">جاري التحميل...</p>
        ) : slaughters.length === 0 ? (
          <div className="bg-white border border-gray-100 rounded-xl p-12 text-center">
            <p className="text-gray-400 mb-2">لا توجد تصفيات بعد</p>
            <p className="text-xs text-gray-300">اضغط "➕ تصفية جديدة" للبدء</p>
          </div>
        ) : (
          <div className="space-y-2">
            {slaughters.map((s: any) => (
              <div key={s.id} onClick={() => editSlaughter(s.id)}
                className="bg-white border border-gray-100 rounded-xl px-4 py-3 hover:border-blue-200 cursor-pointer transition-colors flex items-center justify-between">
                <div className="flex items-center gap-4">
                  <div className="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center text-blue-600 font-bold">
                    {s.animal_name?.charAt(0) || '?'}
                  </div>
                  <div>
                    <div className="font-medium text-gray-800">{s.animal_name}</div>
                    <div className="text-xs text-gray-400">{s.date} · {s.live_weight} كجم</div>
                  </div>
                </div>
                <div className="flex items-center gap-4">
                  <span className="text-xs text-gray-400 bg-gray-50 px-2 py-1 rounded">{s.items_count} أصناف</span>
                  <span className="font-mono font-bold text-blue-700">{s.total_cost.toLocaleString()} ج</span>
                </div>
              </div>
            ))}
          </div>
        )
      )}

      {/* ── Edit / New Form ───────────────────────────── */}
      {showForm && (
        <div className="space-y-4">
          {/* Header Info Cards */}
          <div className="grid grid-cols-5 gap-3">
            <div>
              <label className="text-xs text-gray-500 block mb-1">التاريخ</label>
              <input type="date" value={form.date} onChange={e => setForm({ ...form, date: e.target.value })}
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400" />
            </div>
            <div>
              <label className="text-xs text-gray-500 block mb-1">نوع الدبيحة</label>
              <input value={form.animal_name} onChange={e => setForm({ ...form, animal_name: e.target.value })}
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400" placeholder="مثال: خروف، عجل، فراخ" />
            </div>
            <div>
              <label className="text-xs text-gray-500 block mb-1">وزن قائم (كجم)</label>
              <input type="number" step="0.01" min="0" value={form.live_weight}
                onChange={e => setForm({ ...form, live_weight: e.target.value })}
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400" />
            </div>
            <div>
              <label className="text-xs text-gray-500 block mb-1">سعر الكيلو قائم</label>
              <input type="number" step="0.01" min="0" value={form.price_per_kg}
                onChange={e => setForm({ ...form, price_per_kg: e.target.value })}
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400" />
            </div>
            <div>
              <label className="text-xs text-gray-500 block mb-1">نقل + ذبح</label>
              <input type="number" step="0.01" min="0" value={form.transport_slaughter_cost}
                onChange={e => setForm({ ...form, transport_slaughter_cost: e.target.value })}
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400" />
            </div>
          </div>

          {/* Cost Summary */}
          <div className="bg-gradient-to-l from-blue-50 to-transparent border border-blue-100 rounded-xl px-4 py-3 flex items-center gap-6 text-sm">
            <span className="text-gray-500">إجمالي التكلفة:</span>
            <span className="font-mono font-bold text-xl text-blue-700">{grandTotalCost.toLocaleString('ar-EG', { maximumFractionDigits: 2 })} ج</span>
            {liveCost > 0 && (
              <>
                <span className="text-gray-400 text-xs">= {liveCost.toLocaleString()} ج (وزن) + {transportCost.toLocaleString()} ج (نقل)</span>
              </>
            )}
          </div>

          {/* Output Items Table */}
          <div className="bg-white border border-gray-100 rounded-xl overflow-hidden">
            <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
              <h3 className="text-sm font-semibold text-gray-600">مخرجات التصفية</h3>
              <button onClick={addItem}
                className="px-3 py-1.5 text-xs bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 border border-blue-200">
                ➕ إضافة صنف
              </button>
            </div>
            {form.items.length === 0 ? (
              <div className="p-8 text-center text-gray-400 text-sm">
                لم تضف أي مخرجات — اضغط "➕ إضافة صنف"
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-gray-50 text-gray-500 text-xs border-b border-gray-100">
                      <th className="px-3 py-2.5 text-right font-medium">#</th>
                      <th className="px-3 py-2.5 text-right font-medium">الصنف</th>
                      <th className="px-3 py-2.5 text-center font-medium">المخزن</th>
                      <th className="px-3 py-2.5 text-center font-medium">الوحدة</th>
                      <th className="px-3 py-2.5 text-center font-medium">الوزن/العدد</th>
                      <th className="px-3 py-2.5 text-center font-medium">سعر البيع</th>
                      <th className="px-3 py-2.5 text-center font-medium">الإجمالي</th>
                      <th className="px-3 py-2.5 text-center font-medium">نسبة التحميل</th>
                      <th className="px-3 py-2.5 text-center font-medium">سعر الكيلو الفعلي</th>
                      <th className="px-3 py-2.5 text-center w-12"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {form.items.map((item, idx) => {
                      const w = parseFloat(item.weight) || 0;
                      const sp = parseFloat(item.selling_price) || 0;
                      const total = w * sp;
                      const allItemsTotal = form.items.reduce((sum, it) => sum + ((parseFloat(it.weight) || 0) * (parseFloat(it.selling_price) || 0)), 0);
                      const allocPct = allItemsTotal > 0 ? (total / allItemsTotal * 100) : 0;
                      const actualCost = allocPct > 0 && w > 0 ? (grandTotalCost * allocPct / 100) / w : 0;

                      return (
                        <tr key={idx} className="hover:bg-gray-50/50">
                          <td className="px-3 py-2 text-gray-400 text-xs">{idx + 1}</td>
                          <td className="px-3 py-2 min-w-[180px]">
                            <select value={item.item_id} onChange={e => {
                              updateItem(idx, 'item_id', e.target.value);
                              const sel = itemsById[e.target.value];
                              if (sel) updateItem(idx, 'unit', sel.unit || 'كجم');
                            }}
                              className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400 bg-white">
                              <option value="">اختر...</option>
                              {itemsList.map((i: any) => (
                                <option key={i.id} value={i.id}>{i.name}</option>
                              ))}
                            </select>
                          </td>
                          <td className="px-3 py-2 min-w-[150px]">
                            <select value={item.warehouse_id} onChange={e => updateItem(idx, 'warehouse_id', e.target.value)}
                              className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400 bg-white">
                              <option value="">اختر المخزن...</option>
                              {warehousesList.map((w: any) => (
                                <option key={w.id} value={w.id}>{w.name}</option>
                              ))}
                            </select>
                          </td>
                          <td className="px-3 py-2 text-center">
                            <input value={item.unit} onChange={e => updateItem(idx, 'unit', e.target.value)}
                              className="w-16 px-2 py-1.5 text-sm text-center border border-gray-200 rounded-lg outline-none focus:border-blue-400" />
                          </td>
                          <td className="px-3 py-2 text-center">
                            <input type="number" step="0.01" min="0" value={item.weight}
                              onChange={e => updateItem(idx, 'weight', e.target.value)}
                              className="w-24 px-2 py-1.5 text-sm text-center border border-gray-200 rounded-lg outline-none focus:border-blue-400" placeholder="0" />
                          </td>
                          <td className="px-3 py-2 text-center">
                            <input type="number" step="0.01" min="0" value={item.selling_price}
                              onChange={e => updateItem(idx, 'selling_price', e.target.value)}
                              className="w-24 px-2 py-1.5 text-sm text-center border border-gray-200 rounded-lg outline-none focus:border-blue-400" placeholder="0" />
                          </td>
                          <td className="px-3 py-2 text-center font-mono text-gray-700">{total.toFixed(2)}</td>
                          <td className="px-3 py-2 text-center font-mono text-purple-700">{allocPct.toFixed(2)}%</td>
                          <td className="px-3 py-2 text-center font-mono font-medium text-blue-700">
                            {actualCost > 0 ? actualCost.toFixed(2) : '—'}
                          </td>
                          <td className="px-3 py-2 text-center">
                            <button onClick={() => removeItem(idx)}
                              className="text-xs text-red-400 hover:text-red-700">✕</button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                  <tfoot>
                    <tr className="bg-gray-50 text-xs font-medium">
                      <td colSpan={6} className="px-3 py-2 text-left text-gray-500">الإجمالي</td>
                      <td className="px-3 py-2 text-center font-mono text-gray-800">
                        {form.items.reduce((sum, it) => sum + ((parseFloat(it.weight) || 0) * (parseFloat(it.selling_price) || 0)), 0).toFixed(2)}
                      </td>
                      <td className="px-3 py-2 text-center font-mono text-purple-800">100%</td>
                      <td colSpan={2}></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}
          </div>

          {/* Notes */}
          <div>
            <input value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })}
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400" placeholder="ملاحظات (اختياري)" />
          </div>

          {/* Action Buttons */}
          <div className="flex items-center justify-between pt-2 border-t border-gray-100">
            <div className="flex gap-2">
              {selectedId && (
                <>
                  <button onClick={() => postMutation.mutate(selectedId)} disabled={postMutation.isPending}
                    className="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 flex items-center gap-1.5">
                    <span>📤</span> {postMutation.isPending ? 'جاري...' : 'ترحيل للإنتاج اليومي'}
                  </button>
                  <button onClick={() => { if (confirm('حذف هذه التصفية؟')) deleteMutation.mutate(selectedId); }}
                    className="px-4 py-2 text-sm bg-red-50 text-red-600 rounded-lg hover:bg-red-100 border border-red-200">
                    حذف
                  </button>
                </>
              )}
            </div>
            <div className="flex gap-2">
              <button onClick={cancelEdit}
                className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">
                إلغاء
              </button>
              <button onClick={handleSave} disabled={saveMutation.isPending}
                className="px-6 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 font-medium flex items-center gap-1.5">
                <span>💾</span> {saveMutation.isPending ? 'جاري الحفظ...' : 'حفظ التصفية'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
