'use client';
import { useState, useCallback, useRef } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { financialApi } from '@/lib/financial/api';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

function CatModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const { data: catList = [] } = useQuery({
    queryKey: ['financial-categories'],
    queryFn: () => financialApi.categories(),
    enabled: open,
  });
  const [newName, setNewName] = useState('');
  const [editId, setEditId] = useState<string | null>(null);
  const [editName, setEditName] = useState('');

  const addCat = useMutation({
    mutationFn: (name: string) => api.post('/financial/categories', { name }).then((r) => r.data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['financial-categories'] }); setNewName(''); toast.success('تمت الإضافة'); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  const editCat = useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => api.put(`/financial/categories/${id}`, { name }).then((r) => r.data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['financial-categories'] }); setEditId(null); toast.success('تم التحديث'); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  const delCat = useMutation({
    mutationFn: (id: string) => api.delete(`/financial/categories/${id}`).then((r) => r.data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['financial-categories'] }); toast.success('تم الحذف'); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={onClose}>
      <div className="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 max-h-[80vh] overflow-hidden" onClick={(e) => e.stopPropagation()}>
        <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
          <h3 className="font-bold text-gray-900">إدارة الفئات</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl">✕</button>
        </div>
        <div className="p-5 space-y-3">
          <div className="flex gap-2">
            <input value={newName} onChange={(e) => setNewName(e.target.value)}
              placeholder="اسم الفئة الجديدة"
              className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              onKeyDown={(e) => { if (e.key === 'Enter' && newName) addCat.mutate(newName); }} />
            <button onClick={() => newName && addCat.mutate(newName)} disabled={!newName || addCat.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 whitespace-nowrap">إضافة</button>
          </div>
          <div className="divide-y divide-gray-50 max-h-52 overflow-y-auto border border-gray-100 rounded-lg">
            {catList.map((c: any) => (
              <div key={c.id} className="px-3 py-2 flex items-center justify-between hover:bg-gray-50">
                {editId === c.id ? (
                  <div className="flex gap-2 items-center flex-1">
                    <input value={editName} onChange={(e) => setEditName(e.target.value)}
                      className="flex-1 border border-gray-200 rounded px-2 py-1 text-sm"
                      onKeyDown={(e) => { if (e.key === 'Enter') editCat.mutate({ id: c.id, name: editName }); }} />
                    <button onClick={() => editCat.mutate({ id: c.id, name: editName })} className="text-green-600 text-xs font-medium">حفظ</button>
                    <button onClick={() => setEditId(null)} className="text-gray-400 text-xs">إلغاء</button>
                  </div>
                ) : (
                  <>
                    <span className="text-sm text-gray-700">{c.name}</span>
                    <div className="flex gap-2">
                      <button onClick={() => { setEditId(c.id); setEditName(c.name); }} className="text-blue-500 text-xs hover:text-blue-700">تعديل</button>
                      <button onClick={() => { if (confirm(`حذف "${c.name}"?`)) delCat.mutate(c.id); }} className="text-red-400 text-xs hover:text-red-600">حذف</button>
                    </div>
                  </>
                )}
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

export default function FinancialDailyPage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.toISOString().slice(0, 7));
  const [selectedDay, setSelectedDay] = useState(now.getDate());
  const [sales, setSales] = useState('');
  const [notes, setNotes] = useState('');
  const [rows, setRows] = useState<{ cat_id: string; amount: string; desc: string }[]>([]);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [isEditing, setIsEditing] = useState(false);
  const [showSaved, setShowSaved] = useState(false);
  const [showCatModal, setShowCatModal] = useState(false);
  const originalRef = useRef<{ sales: string; notes: string; rows: { cat_id: string; amount: string; desc: string }[] } | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['financial-daily', month],
    queryFn: () => financialApi.dailyEntries(month),
  });

  const { data: catList = [] } = useQuery({
    queryKey: ['financial-categories'],
    queryFn: () => financialApi.categories(),
  });

  const entries = (data as any)?.entries || [];
  const daysInMonth = new Date(parseInt(month.slice(0, 4)), parseInt(month.slice(5, 7)), 0).getDate();

  const savedEntry = entries.find((e: any) => e.date.slice(0, 10) === `${month}-${String(selectedDay).padStart(2, '0')}`);
  const totalExpenses = rows.reduce((s, r) => s + (parseFloat(r.amount) || 0), 0);
  const netDaily = (parseFloat(sales) || 0) - totalExpenses;
  const isViewing = !!editingId && !isEditing;

  // Load day data
  const loadDay = useCallback((day: number) => {
    setSelectedDay(day);
    const found = entries.find((e: any) =>
      e.date.slice(0, 10) === `${month}-${String(day).padStart(2, '0')}`
    );
    if (found) {
      const loadedSales = String(found.total_sales);
      const loadedNotes = found.notes || '';
      const loadedRows = (found.details || []).map((d: any) => ({
        cat_id: d.expense_category_id,
        amount: String(d.amount),
        desc: d.description || '',
      }));
      setEditingId(found.id);
      setIsEditing(false);
      setSales(loadedSales);
      setNotes(loadedNotes);
      setRows(loadedRows);
      originalRef.current = { sales: loadedSales, notes: loadedNotes, rows: loadedRows };
    } else {
      setEditingId(null);
      setIsEditing(false);
      setSales('');
      setNotes('');
      setRows([]);
      originalRef.current = null;
    }
  }, [entries, month]);

  const enterEditMode = () => {
    originalRef.current = { sales, notes, rows: [...rows] };
    setIsEditing(true);
  };

  const cancelEdit = () => {
    if (originalRef.current) {
      setSales(originalRef.current.sales);
      setNotes(originalRef.current.notes);
      setRows(originalRef.current.rows);
    }
    setIsEditing(false);
  };

  const saveMutation = useMutation({
    mutationFn: (body: any) => editingId ? financialApi.updateDailyEntry(editingId, body) : financialApi.storeDailyEntry(body),
    onSuccess: () => {
      toast.success(editingId ? 'تم التحديث' : 'تم الحفظ');
      setIsEditing(false);
      qc.invalidateQueries({ queryKey: ['financial-daily', month] });
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => financialApi.deleteDailyEntry(id),
    onSuccess: () => {
      toast.success('تم الحذف');
      setEditingId(null); setIsEditing(false); setSales(''); setNotes(''); setRows([]);
      originalRef.current = null;
      qc.invalidateQueries({ queryKey: ['financial-daily', month] });
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  function handleSave() {
    if (!sales) { toast.error('أدخل المبيعات'); return; }
    saveMutation.mutate({
      date: `${month}-${String(selectedDay).padStart(2, '0')}`,
      total_sales: parseFloat(sales),
      notes: notes || undefined,
      details: rows.filter((r) => r.cat_id && r.amount).map((r) => ({
        expense_category_id: r.cat_id,
        amount: parseFloat(r.amount),
        description: r.desc || undefined,
      })),
    });
  }

  function handleDelete() {
    if (editingId && confirm('حذف هذه اليومية؟')) deleteMutation.mutate(editingId);
  }

  function addRow() {
    setRows([...rows, { cat_id: catList[0]?.id || '', amount: '', desc: '' }]);
  }

  function updRow(i: number, field: string, val: string) {
    if (isViewing) return;
    const copy = [...rows]; (copy[i] as any)[field] = val; setRows(copy);
  }

  function delRow(i: number) {
    if (isViewing) return;
    setRows(rows.filter((_, j) => j !== i));
  }

  const inputClass = () =>
    isViewing
      ? 'bg-gray-50 text-gray-500 cursor-default border-gray-100'
      : 'bg-white text-gray-900 border-gray-200 focus:ring-2 focus:ring-blue-500';

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="اليومية المالية"
        subtitle="إدخال المصروفات والمبيعات اليومية"
        actions={
          <button onClick={() => setShowCatModal(true)}
            className="px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-lg text-sm hover:bg-gray-50 shadow-sm">
            إدارة الفئات
          </button>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        {/* Month + Day Selector */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <label className="text-sm font-medium text-gray-600">الشهر</label>
            <input type="month" value={month} onChange={(e) => { setMonth(e.target.value); setSelectedDay(1); }}
              className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" />
          </div>
          <div className="flex items-center gap-2 text-sm text-gray-500">
            <span>اختر اليوم:</span>
            <div className="flex gap-1 flex-wrap" style={{ maxWidth: '600px' }}>
              {Array.from({ length: daysInMonth }, (_, i) => {
                const day = i + 1;
                const has = entries.some((e: any) =>
                  e.date.slice(0, 10) === `${month}-${String(day).padStart(2, '0')}`
                );
                return (
                  <button key={day} onClick={() => loadDay(day)}
                    className={`w-8 h-8 rounded text-xs font-medium transition-all
                      ${selectedDay === day ? 'bg-blue-600 text-white shadow-sm ring-2 ring-blue-200' : ''}
                      ${selectedDay !== day && has ? 'bg-green-100 text-green-700 border border-green-300' : ''}
                      ${selectedDay !== day && !has ? 'text-gray-500 hover:bg-gray-100' : ''}`}>
                    {day}
                  </button>
                );
              })}
            </div>
          </div>
        </div>

        {/* Daily Entry Card */}
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          {/* Card Header */}
          <div className="bg-gradient-to-l from-blue-50 to-white px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <div>
              <span className="font-bold text-gray-900">اليوم: {selectedDay}/{month}</span>
              {savedEntry && <span className="mr-3 text-xs text-green-600 bg-green-50 px-2 py-0.5 rounded-full">✓ مسجلة</span>}
            </div>
            <div className="flex gap-2">
              {isViewing && (
                <button onClick={enterEditMode}
                  className="text-sm text-blue-600 hover:text-blue-800 border border-blue-200 px-3 py-1 rounded-lg hover:bg-blue-50">
                  تعديل
                </button>
              )}
              {isEditing && (
                <button onClick={handleDelete}
                  className="text-sm text-red-500 hover:text-red-700 border border-red-200 px-3 py-1 rounded-lg hover:bg-red-50">
                  حذف
                </button>
              )}
            </div>
          </div>

          {/* View mode banner */}
          {isViewing && (
            <div className="px-5 py-2 bg-amber-50 border-b border-amber-100 text-sm text-amber-700">
              أنت في وضع العرض. اضغط <strong>تعديل</strong> لتعديل البيانات.
            </div>
          )}

          {/* Sales Input */}
          <div className="px-5 py-3 border-b border-gray-100 bg-gradient-to-l from-green-50/50 to-white">
            <div className="flex items-center gap-4 flex-wrap">
              <span className="text-sm font-medium text-gray-700">إجمالي المبيعات:</span>
              <input type="number" value={sales}
                disabled={isViewing}
                onChange={(e) => { if (!isViewing) setSales(e.target.value); }}
                className={`w-40 rounded-lg px-3 py-2 text-lg font-bold text-green-700 text-left border ${inputClass()}`}
                placeholder="0" />
              <span className="text-sm text-gray-400">جنيه</span>
              <div className="flex gap-4 mr-auto text-sm">
                <span>المصروفات: <strong className="text-red-600">{totalExpenses.toFixed(2)}</strong></span>
                <span>الصافي: <strong className={netDaily >= 0 ? 'text-green-600' : 'text-red-600'}>{netDaily.toFixed(2)}</strong></span>
              </div>
            </div>
          </div>

          {/* Expenses Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-right">
              <thead>
                <tr className="bg-gray-50 border-y border-gray-100">
                  <th className="px-3 py-2.5 text-gray-500 font-medium w-8">#</th>
                  <th className="px-3 py-2.5 text-gray-500 font-medium">البيان (الوصف)</th>
                  <th className="px-3 py-2.5 text-gray-500 font-medium w-48">الفئة</th>
                  <th className="px-3 py-2.5 text-gray-500 font-medium w-36">المبلغ</th>
                  <th className="px-3 py-2.5 w-10"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {rows.length === 0 ? (
                  <tr><td colSpan={5} className="px-4 py-10 text-center text-gray-300">لا توجد بنود — أضف بنداً</td></tr>
                ) : rows.map((r, i) => (
                  <tr key={i} className={`transition-colors ${isViewing ? '' : 'hover:bg-blue-50/20'}`}>
                    <td className="px-3 py-1.5 text-gray-400 text-xs text-center">{i + 1}</td>
                    <td className="px-3 py-1.5">
                      <input value={r.desc} onChange={(e) => updRow(i, 'desc', e.target.value)}
                        disabled={isViewing}
                        placeholder="وصف المصروف..."
                        className={`w-full border-0 border-b border-dashed px-1 py-1.5 text-sm ${inputClass()}`} />
                    </td>
                    <td className="px-3 py-1.5">
                      <select value={r.cat_id} onChange={(e) => updRow(i, 'cat_id', e.target.value)}
                        disabled={isViewing}
                        className={`w-full rounded-lg px-2 py-1.5 text-sm border ${inputClass()}`}>
                        {catList.map((c: any) => (<option key={c.id} value={c.id}>{c.name}</option>))}
                      </select>
                    </td>
                    <td className="px-3 py-1.5">
                      <input type="number" value={r.amount} onChange={(e) => updRow(i, 'amount', e.target.value)}
                        disabled={isViewing}
                        placeholder="0"
                        className={`w-full rounded-lg px-2 py-1.5 text-sm text-left border ${inputClass()}`} />
                    </td>
                    <td className="px-3 py-1.5 text-center">
                      {!isViewing && (
                        <button onClick={() => delRow(i)} className="text-red-300 hover:text-red-500 text-sm">✕</button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="px-5 py-2 border-t border-gray-100 bg-gray-50/50 flex items-center justify-between">
            {!isViewing && (
              <button onClick={addRow} className="text-blue-600 text-sm hover:text-blue-800 font-medium">+ إضافة بند</button>
            )}
            {isViewing && <div />}
            <input value={notes} onChange={(e) => { if (!isViewing) setNotes(e.target.value); }}
              disabled={isViewing}
              placeholder="ملاحظات..."
              className={`border-0 border-b border-dashed px-1 py-1 text-sm w-64 text-left ${inputClass()}`} />
          </div>

          {/* Save Footer */}
          <div className="px-5 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between">
            <div className="text-sm text-gray-500">
              إجمالي المصروفات: <strong className="text-red-700">{totalExpenses.toFixed(2)}</strong>
              <span className="mx-3">|</span>
              صافي اليوم: <strong className={netDaily >= 0 ? 'text-green-700' : 'text-red-700'}>{netDaily.toFixed(2)}</strong>
            </div>
            <div className="flex gap-2">
              {isEditing && (
                <button onClick={cancelEdit}
                  className="px-4 py-2 border border-gray-300 text-gray-600 rounded-lg text-sm hover:bg-gray-100 font-medium">
                  إلغاء
                </button>
              )}
              {!isViewing && (
                <button onClick={handleSave} disabled={saveMutation.isPending}
                  className="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 font-medium shadow-sm">
                  {saveMutation.isPending ? '...جاري' : (editingId ? 'تحديث' : 'حفظ اليومية')}
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Saved Entries for the Month */}
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <button onClick={() => setShowSaved(!showSaved)} className="w-full px-5 py-3 flex items-center justify-between hover:bg-gray-50 transition-colors">
            <h3 className="font-semibold text-gray-800">اليوميات المسجلة لهذا الشهر</h3>
            <span className="text-gray-400 text-sm">{showSaved ? '▲' : '▼'} ({entries.length})</span>
          </button>
          {showSaved && (
            <div className="border-t border-gray-100 overflow-x-auto">
              {entries.length === 0 ? (
                <div className="text-center py-6 text-gray-400 text-sm">لا توجد يوميات مسجلة</div>
              ) : (
                <table className="w-full text-sm text-right">
                  <thead><tr className="bg-gray-50 text-gray-500">
                    <th className="px-4 py-2">اليوم</th>
                    <th className="px-4 py-2">المبيعات</th>
                    <th className="px-4 py-2">المصروفات</th>
                    <th className="px-4 py-2">الصافي</th>
                    <th className="px-4 py-2">ملاحظات</th>
                    <th className="px-4 py-2"></th>
                  </tr></thead>
                  <tbody className="divide-y divide-gray-50">
                    {entries.map((e: any) => {
                      const eDay = parseInt(e.date.slice(8, 10));
                      return (
                        <tr key={e.id} className="hover:bg-gray-50 cursor-pointer" onClick={() => { loadDay(eDay); setShowSaved(false); }}>
                          <td className="px-4 py-2 font-medium">{eDay}</td>
                          <td className="px-4 py-2">{e.total_sales}</td>
                          <td className="px-4 py-2 text-red-600">{e.total_expenses}</td>
                          <td className={`px-4 py-2 ${e.net_daily >= 0 ? 'text-green-600' : 'text-red-600'}`}>{e.net_daily}</td>
                          <td className="px-4 py-2 text-gray-400">{e.notes || '—'}</td>
                          <td className="px-4 py-2">
                            <span className="text-blue-500 text-xs">عرض</span>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Categories Modal */}
      <CatModal open={showCatModal} onClose={() => setShowCatModal(false)} />
    </div>
  );
}
