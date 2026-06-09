'use client';
import React, { useState, useCallback, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { financialApi } from '@/lib/financial/api';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import toast from 'react-hot-toast';

function CatModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const { data: catList = [] } = useQuery({
    queryKey: ['financial-categories'],
    queryFn: () => financialApi.categories(),
    enabled: open,
  });
  const [sorted, setSorted] = useState<any[]>([]);
  useEffect(() => { if (catList.length) setSorted([...catList]); }, [catList]);

  const [newName, setNewName] = useState('');
  const [newIsPurchase, setNewIsPurchase] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [editName, setEditName] = useState('');
  const [editIsPurchase, setEditIsPurchase] = useState(false);

  const addCat = useMutation({
    mutationFn: (data: { name: string; is_purchase: boolean }) => api.post('/financial/categories', data).then((r) => r.data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['financial-categories'] }); setNewName(''); setNewIsPurchase(false); toast.success('تمت الإضافة'); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  const editCat = useMutation({
    mutationFn: ({ id, name, is_purchase }: { id: string; name: string; is_purchase: boolean }) => api.put(`/financial/categories/${id}`, { name, is_purchase }).then((r) => r.data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['financial-categories'] }); setEditId(null); toast.success('تم التحديث'); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  const delCat = useMutation({
    mutationFn: (id: string) => api.delete(`/financial/categories/${id}`).then((r) => r.data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['financial-categories'] }); toast.success('تم الحذف'); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  const reorderCat = useMutation({
    mutationFn: (cats: { id: string; sort_order: number }[]) => api.put('/financial/categories/reorder', { categories: cats }).then((r) => r.data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['financial-categories'] }); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  const [dragIdx, setDragIdx] = useState<number | null>(null);

  function handleDragStart(idx: number) {
    if (editId) return;
    setDragIdx(idx);
  }

  function handleDragOver(e: React.DragEvent, idx: number) {
    if (dragIdx === null || dragIdx === idx || editId) return;
    e.preventDefault();
    const arr = [...sorted];
    const [moved] = arr.splice(dragIdx, 1);
    arr.splice(idx, 0, moved);
    setDragIdx(idx);
    setSorted(arr);
  }

  function handleDragEnd() {
    if (dragIdx === null) return;
    const ordered = sorted.map((c: any, i: number) => ({ id: c.id, sort_order: i }));
    reorderCat.mutate(ordered);
    setDragIdx(null);
  }

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
              onKeyDown={(e) => { if (e.key === 'Enter' && newName) addCat.mutate({ name: newName, is_purchase: newIsPurchase }); }} />
            <button onClick={() => newName && addCat.mutate({ name: newName, is_purchase: newIsPurchase })} disabled={!newName || addCat.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 whitespace-nowrap">إضافة</button>
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
            <input type="checkbox" checked={newIsPurchase} onChange={(e) => setNewIsPurchase(e.target.checked)}
              className="w-4 h-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500" />
            مشتريات (تظهر في وارد مخزن)
          </label>
          <div className="divide-y divide-gray-50 max-h-52 overflow-y-auto border border-gray-100 rounded-lg">
            {sorted.map((c: any, idx: number) => (
              <div key={c.id}
                draggable={editId !== c.id}
                onDragStart={() => handleDragStart(idx)}
                onDragOver={(e) => handleDragOver(e, idx)}
                onDragEnd={handleDragEnd}
                className={`px-3 py-2 flex items-center justify-between transition-colors
                  ${dragIdx === idx ? 'opacity-40 bg-blue-50' : 'hover:bg-gray-50'}
                  ${editId === c.id ? 'bg-blue-50/40' : 'cursor-grab active:cursor-grabbing'}`}>
                {editId === c.id ? (
                  <div className="flex flex-col gap-2 flex-1">
                    <input value={editName} onChange={(e) => setEditName(e.target.value)}
                      className="w-full border border-gray-200 rounded px-2 py-1 text-sm"
                      onKeyDown={(e) => { if (e.key === 'Enter') editCat.mutate({ id: c.id, name: editName, is_purchase: editIsPurchase }); }} />
                    <label className="flex items-center gap-2 text-xs text-gray-500 cursor-pointer">
                      <input type="checkbox" checked={editIsPurchase} onChange={(e) => setEditIsPurchase(e.target.checked)}
                        className="w-3.5 h-3.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500" />
                      مشتريات
                    </label>
                    <div className="flex gap-2">
                      <button onClick={() => editCat.mutate({ id: c.id, name: editName, is_purchase: editIsPurchase })} className="text-green-600 text-xs font-medium">حفظ</button>
                      <button onClick={() => setEditId(null)} className="text-gray-400 text-xs">إلغاء</button>
                    </div>
                  </div>
                ) : (
                  <>
                    <div className="flex items-center gap-2">
                      <span className="text-gray-300 cursor-grab select-none text-sm">⠿</span>
                      <span className="text-sm text-gray-700 flex items-center gap-2">
                        {c.name}
                        {c.is_purchase && <span className="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full">مشتريات</span>}
                      </span>
                    </div>
                    <div className="flex gap-2">
                      <button onClick={() => { setEditId(c.id); setEditName(c.name); setEditIsPurchase(c.is_purchase); }} className="text-blue-500 text-xs hover:text-blue-700">تعديل</button>
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

type ExRow = {
  amounts: Record<string, string>;
  quantities: Record<string, string>;
  descriptions: Record<string, string>;
  itemIds: Record<string, string>;
};

export default function FinancialDailyPage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.toISOString().slice(0, 7));
  const [selectedDay, setSelectedDay] = useState(now.getDate());
  const [sales, setSales] = useState('');
  const [notes, setNotes] = useState('');
  const [rows, setRows] = useState<ExRow[]>([]);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [isEditing, setIsEditing] = useState(true);
  const [showSaved, setShowSaved] = useState(false);
  const [showCatModal, setShowCatModal] = useState(false);
  const [focusedCat, setFocusedCat] = useState<string | null>(null);
  const [calcExpr, setCalcExpr] = useState('');
  const [calcOpen, setCalcOpen] = useState(false);

  const { data } = useQuery({
    queryKey: ['financial-daily', month],
    queryFn: () => financialApi.dailyEntries(month),
  });

  const { data: catList = [] } = useQuery({
    queryKey: ['financial-categories'],
    queryFn: () => financialApi.categories(),
  });

  const cats = catList as { id: string; name: string }[];
  const entries: any[] = (data as any)?.entries || [];
  const daysInMonth = new Date(parseInt(month.slice(0, 4)), parseInt(month.slice(5, 7)), 0).getDate();
  const savedEntry = entries.find((e: any) => e.date.slice(0, 10) === `${month}-${String(selectedDay).padStart(2, '0')}`);

  const catTotals = cats.reduce((acc, c) => {
    acc[c.id] = rows.reduce((s, r) => s + (parseFloat(r.amounts[c.id]) || 0), 0);
    return acc;
  }, {} as Record<string, number>);
  const totalExpenses = Object.values(catTotals).reduce((s, v) => s + v, 0);
  const netDaily = (parseFloat(sales) || 0) - totalExpenses;

  const loadDay = useCallback((day: number) => {
    setSelectedDay(day);
    const found = entries.find((e: any) =>
      e.date.slice(0, 10) === `${month}-${String(day).padStart(2, '0')}`
    );
    if (found) {
      setEditingId(found.id);
      setIsEditing(false);
      setSales(String(found.total_sales));
      setNotes(found.notes || '');
      const rawRows: ExRow[] = [];
      for (const det of (found.details || [])) {
        const cat = det.expense_category_id;
        let placed = false;
        for (const r of rawRows) {
          if (!r.amounts[cat] && !r.descriptions[cat]) {
            r.amounts[cat] = String(det.amount);
            r.quantities[cat] = det.quantity != null ? String(det.quantity) : '';
            r.descriptions[cat] = det.description || '';
            r.itemIds[cat] = det.item_id || '';
            placed = true;
            break;
          }
        }
        if (!placed) {
          rawRows.push({
            amounts: { [cat]: String(det.amount) },
            quantities: { [cat]: det.quantity != null ? String(det.quantity) : '' },
            descriptions: { [cat]: det.description || '' },
            itemIds: { [cat]: det.item_id || '' },
          });
        }
      }
      setRows(rawRows.length > 0 ? rawRows : Array.from({ length: 12 }, () => ({
        amounts: {}, quantities: {}, descriptions: {}, itemIds: {},
      })));
    } else {
      setEditingId(null);
      setIsEditing(true);
      setSales('');
      setNotes('');
      setRows(Array.from({ length: 12 }, () => ({
        amounts: {}, quantities: {}, descriptions: {}, itemIds: {},
      })));
    }
  }, [entries, month]);

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
      setEditingId(null); setIsEditing(true); setSales(''); setNotes('');
      setRows([{ amounts: {}, quantities: {}, descriptions: {}, itemIds: {} }]);
      qc.invalidateQueries({ queryKey: ['financial-daily', month] });
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ'),
  });

  function handleSave() {
    if (!sales) { toast.error('أدخل المبيعات'); return; }
    const details: any[] = [];
    for (const r of rows) {
      for (const c of cats) {
        const amt = parseFloat(r.amounts[c.id]) || 0;
        if (amt > 0) {
          details.push({
            expense_category_id: c.id,
            amount: amt,
            quantity: r.quantities[c.id] ? parseFloat(r.quantities[c.id]) : null,
            description: r.descriptions[c.id] || undefined,
            item_id: r.itemIds[c.id] || null,
          });
        }
      }
    }
    saveMutation.mutate({
      date: `${month}-${String(selectedDay).padStart(2, '0')}`,
      total_sales: parseFloat(sales),
      notes: notes || undefined,
      details,
    });
  }

  function handleDelete() {
    if (editingId && confirm('حذف هذه اليومية؟')) deleteMutation.mutate(editingId);
  }

  function addRow() {
    setRows([...rows, { amounts: {}, quantities: {}, descriptions: {}, itemIds: {} }]);
  }

  function delRow(i: number) {
    if (rows.length <= 1) {
      setRows([{ amounts: {}, quantities: {}, descriptions: {}, itemIds: {} }]);
      return;
    }
    setRows(rows.filter((_, j) => j !== i));
  }

  function setCell(i: number, catId: string, field: 'amounts' | 'quantities' | 'descriptions' | 'itemIds', val: string) {
    const copy = rows.map((r) => ({
      amounts: { ...r.amounts },
      quantities: { ...r.quantities },
      descriptions: { ...r.descriptions },
      itemIds: { ...r.itemIds },
    }));
    copy[i][field][catId] = val;
    setRows(copy);
  }

  function handleItemSelect(i: number, catId: string, itemId: string, itemName: string) {
    const copy = rows.map((r) => ({
      amounts: { ...r.amounts },
      quantities: { ...r.quantities },
      descriptions: { ...r.descriptions },
      itemIds: { ...r.itemIds },
    }));
    copy[i].itemIds[catId] = itemId;
    copy[i].descriptions[catId] = itemName;
    setRows(copy);
  }

  const isReadOnly = editingId !== null && !isEditing;
  const inpCls = 'w-full border border-gray-200 rounded px-1.5 py-1 text-xs text-left outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-400 bg-white';
  const inpRoCls = isReadOnly ? ' bg-gray-50 text-gray-700 cursor-default' : '';
  const inpAmt = `${inpCls} font-medium no-spinner${inpRoCls}`;
  const inpQty = `${inpCls} text-gray-700 w-20 text-center no-spinner${inpRoCls}`;
  const inpDesc = `${inpCls} text-gray-700${isReadOnly ? ' cursor-default' : ' cursor-pointer'}${inpRoCls}`;

  function computeCalc(expr: string): string | null {
    if (!expr.trim()) return null;
    try {
      const sanitized = expr.replace(/[^0-9+\-*/().%]/g, '');
      const r = Function('"use strict"; return (' + sanitized + ')')();
      if (isFinite(r)) return Number.isInteger(r) ? String(r) : r.toFixed(3);
    } catch {}
    return null;
  }

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="اليومية المالية"
        subtitle="إدخال المصروفات والمبيعات — نظام الأعمدة"
        actions={
          <div className="flex gap-2">
            <button onClick={() => {
              api.get('/financial/daily-entries/export/warehouse-incoming', { params: { month, day: selectedDay }, responseType: 'blob' })
                .then((r) => {
                  const url = window.URL.createObjectURL(new Blob([r.data]));
                  const a = document.createElement('a'); a.href = url;
                  a.download = `وارد_مخزن_${selectedDay}_${month.split('-')[1]}.xlsx`; a.click();
                  window.URL.revokeObjectURL(url);
                  toast.success('تم التصدير');
                })
                .catch(() => toast.error('فشل التصدير'));
            }}
              className="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm hover:bg-amber-700 shadow-sm font-medium">
              🏭 وارد مخزن اليوم
            </button>
            <button onClick={() => {
              api.get('/financial/daily-entries/export/single-day', { params: { month, day: selectedDay }, responseType: 'blob' })
                .then((r) => {
                  const url = window.URL.createObjectURL(new Blob([r.data]));
                  const a = document.createElement('a'); a.href = url;
                  const [y2, m2] = month.split('-');
                  a.download = `مالي_${m2}_${y2}_يوم${selectedDay}.xlsx`; a.click();
                  window.URL.revokeObjectURL(url);
                  toast.success('تم التصدير');
                })
                .catch(() => toast.error('فشل التصدير'));
            }}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 shadow-sm font-medium">
              📄 تصدير اليومية
            </button>
            <button onClick={() => {
              api.get('/financial/daily-entries/export/excel', { params: { month }, responseType: 'blob' })
                .then((r) => {
                  const url = window.URL.createObjectURL(new Blob([r.data]));
                  const a = document.createElement('a'); a.href = url;
                  const [y, m] = month.split('-');
                  a.download = `مالي_${m}_${y}.xlsx`; a.click();
                  window.URL.revokeObjectURL(url);
                  toast.success('تم التصدير');
                })
                .catch(() => toast.error('فشل التصدير'));
            }}
              className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 shadow-sm font-medium">
              ⬇ تصدير كل الشهر
            </button>
            <button onClick={() => setShowCatModal(true)}
              className="px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-lg text-sm hover:bg-gray-50 shadow-sm">
              إدارة الفئات
            </button>
          </div>
        }
      />

          <style>{`
            .no-spinner::-webkit-inner-spin-button,
            .no-spinner::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
            .no-spinner { -moz-appearance: textfield; }
          `}</style>
          <div className="flex-1 overflow-y-auto p-6 space-y-4">
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

        {/* Quick Calculator */}
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <button onClick={() => setCalcOpen(!calcOpen)} className="w-full px-5 py-2 flex items-center gap-2 hover:bg-gray-50 transition-colors text-sm text-gray-500">
            <span className="text-lg">🧮</span> حاسبة
            <span className="mr-auto text-gray-300">{calcOpen ? '▲' : '▼'}</span>
          </button>
          {calcOpen && (
            <div className="px-5 py-3 border-t border-gray-100 bg-gray-50/50 flex flex-wrap items-center gap-3">
              <input type="text" value={calcExpr} onChange={(e) => setCalcExpr(e.target.value)}
                placeholder="أدخل العملية الحسابية مثل 150+200*3"
                className="flex-1 min-w-[250px] border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-400 font-mono text-left" />
              {(() => {
                const result = computeCalc(calcExpr);
                return result !== null ? (
                  <div className="flex items-center gap-2">
                    <span className="text-sm text-gray-500">=</span>
                    <span className="text-lg font-bold text-green-700 font-mono">{result}</span>
                    <button onClick={() => { navigator.clipboard.writeText(result); toast.success('تم النسخ'); }}
                      className="text-xs text-blue-500 hover:text-blue-700">نسخ</button>
                  </div>
                ) : null;
              })()}
            </div>
          )}
        </div>

        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <div className="bg-gradient-to-l from-blue-50 to-white px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <div className="flex items-center gap-3">
              <span className="font-bold text-gray-900">
                كافيه — يوم {selectedDay}/{month}
              </span>
              {savedEntry && <span className="text-xs text-green-600 bg-green-50 px-2 py-0.5 rounded-full">✓ مسجلة</span>}
            </div>
            <div className="flex gap-2">
              {editingId && !isEditing && (
                <button onClick={() => setIsEditing(true)}
                  className="text-sm text-blue-600 hover:text-blue-800 border border-blue-200 px-3 py-1 rounded-lg hover:bg-blue-50 font-medium">
                  ✏️ تعديل
                </button>
              )}
              {editingId && isEditing && (
                <button onClick={handleDelete}
                  className="text-sm text-red-500 hover:text-red-700 border border-red-200 px-3 py-1 rounded-lg hover:bg-red-50">
                  حذف
                </button>
              )}
            </div>
          </div>

          <div className="overflow-auto max-h-[500px]" style={{ direction: 'ltr' }}>
            <table className="text-xs border-collapse" style={{ direction: 'rtl', minWidth: cats.length * 280 + 80 }}>
              <thead>
                <tr className="sticky top-0 z-20 bg-gradient-to-b from-gray-100 to-gray-50 shadow-sm">
                  <th className="sticky right-0 z-30 bg-gray-100 px-2 py-2 border-b border-l border-gray-200 min-w-[40px] text-gray-600 font-bold text-sm">#</th>
                  {cats.map((c) => {
                    const isPur = (c as any).is_purchase;
                    const isFocused = focusedCat === c.id;
                    return (
                      <th key={c.id} colSpan={3} className={`px-2 py-2 border-b border-l border-gray-200 font-bold min-w-[300px] whitespace-nowrap text-center transition-colors
                        ${isFocused ? 'bg-blue-100 text-blue-800 ring-2 ring-inset ring-blue-300' : isPur ? 'bg-amber-50 text-amber-800' : 'text-gray-700 bg-gray-50/50'}`}>
                        {c.name}
                        {isPur && <span className="mr-1 text-[10px] bg-amber-200 text-amber-800 px-1.5 py-0.5 rounded-full">مشتريات</span>}
                      </th>
                    );
                  })}
                  <th className="px-2 py-2 border-b border-gray-200 min-w-[80px] text-red-700 font-bold bg-red-50/50 text-center">الإجمالي</th>
                </tr>
                <tr className="sticky top-[38px] z-20 bg-gradient-to-b from-gray-50 to-white shadow-sm">
                  <th className="sticky right-0 z-30 bg-white px-2 py-1.5 border-b border-l border-gray-200"></th>
                  {cats.map((c) => {
                    const isFocused = focusedCat === c.id;
                    return (
                      <React.Fragment key={c.id}>
                        <th className={`px-2 py-1.5 border-b border-l border-gray-200 text-gray-400 font-medium text-[11px] w-20 transition-colors ${isFocused ? 'bg-blue-50' : 'bg-white/80'}`}>الكمية</th>
                        <th className={`px-2 py-1.5 border-b border-l border-gray-200 text-gray-400 font-medium text-[11px] w-24 transition-colors ${isFocused ? 'bg-blue-50' : 'bg-white/80'}`}>المبلغ</th>
                        <th className={`px-2 py-1.5 border-b border-l border-gray-200 text-gray-400 font-medium text-[11px] transition-colors ${isFocused ? 'bg-blue-50' : 'bg-white/80'}`}>البيان</th>
                      </React.Fragment>
                    );
                  })}
                  <th className="px-2 py-1.5 border-b border-gray-200 text-gray-400 font-medium bg-white/80 text-[11px]"></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => {
                  const rowTotal = cats.reduce((s, c) => s + (parseFloat(r.amounts[c.id]) || 0), 0);
                  return (
                    <tr key={i} className="hover:bg-blue-50/20 transition-colors border-b border-gray-50">
                      <td className="sticky right-0 z-10 bg-white px-2 py-1 border-l border-gray-50 text-center">
                        <div className="flex items-center justify-center gap-1">
                          <span className="text-gray-500 text-xs font-medium">{i + 1}</span>
                          {!isReadOnly && <button onClick={() => delRow(i)} className="text-red-200 hover:text-red-400 text-[10px]">✕</button>}
                        </div>
                      </td>
                      {cats.map((c) => {
                        const isFocused = focusedCat === c.id;
                        const bgCell = isFocused ? 'bg-blue-50/30' : '';
                        return (
                          <React.Fragment key={c.id}>
                            <td className={`px-1 py-0.5 border-l border-gray-50 transition-colors ${bgCell}`}>
                              <input type="number" value={r.quantities[c.id] ?? ''}
                                onChange={(e) => setCell(i, c.id, 'quantities', e.target.value)}
                                onFocus={() => setFocusedCat(c.id)}
                                onBlur={() => setFocusedCat(null)}
                                disabled={isReadOnly}
                                className={inpQty} placeholder="0" />
                            </td>
                            <td className={`px-1 py-0.5 border-l border-gray-50 transition-colors ${bgCell}`}>
                              <input type="number" value={r.amounts[c.id] ?? ''}
                                onChange={(e) => setCell(i, c.id, 'amounts', e.target.value)}
                                onFocus={() => setFocusedCat(c.id)}
                                onBlur={() => setFocusedCat(null)}
                                disabled={isReadOnly}
                                className={inpAmt} placeholder="0" />
                            </td>
                            <td className={`px-1 py-0.5 border-l border-gray-50 transition-colors ${bgCell}`} style={{ minWidth: 160 }}>
                              <ItemSelectCell
                                categoryId={c.id}
                                isPurchase={(c as any).is_purchase}
                                value={r.itemIds[c.id] || ''}
                                displayValue={r.descriptions[c.id] || ''}
                                onSelect={(itemId, itemName) => handleItemSelect(i, c.id, itemId, itemName)}
                                onTextChange={(text) => setCell(i, c.id, 'descriptions', text)}
                                onFocus={() => setFocusedCat(c.id)}
                                onBlur={() => setFocusedCat(null)}
                                disabled={isReadOnly}
                                className={inpDesc}
                              />
                            </td>
                          </React.Fragment>
                        );
                      })}
                      <td className="px-2 py-1 text-left font-medium text-red-600 bg-red-50/20">
                        {rowTotal > 0 ? rowTotal.toFixed(2) : ''}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
              <tfoot>
                <tr className="sticky bottom-0 z-20 bg-gradient-to-t from-gray-200 to-gray-100 border-t-2 border-gray-300 font-bold shadow-sm">
                  <td className="sticky right-0 z-30 bg-gray-200 px-2 py-2 border-t border-l border-gray-300 text-center text-sm">SUM</td>
                  {cats.map((c) => (
                    <React.Fragment key={c.id}>
                      <td className="px-2 py-2 border-t border-l border-gray-300"></td>
                      <td className="px-2 py-2 border-t border-l border-gray-300 text-left text-gray-800 text-sm font-bold">
                        {catTotals[c.id] > 0 ? catTotals[c.id].toFixed(2) : ''}
                      </td>
                      <td className="px-2 py-2 border-t border-l border-gray-300"></td>
                    </React.Fragment>
                  ))}
                  <td className="px-2 py-2 border-t border-gray-300 text-left text-red-700 text-sm font-bold">
                    {totalExpenses > 0 ? totalExpenses.toFixed(2) : ''}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div className="px-5 py-3 border-t border-gray-100 bg-gray-50/70 flex items-center gap-3">
            <button onClick={addRow} disabled={isReadOnly} className="px-4 py-2 bg-blue-50 text-blue-700 border border-blue-200 rounded-lg text-sm hover:bg-blue-100 hover:border-blue-300 font-medium transition-all shadow-sm flex items-center gap-1 disabled:opacity-40 disabled:cursor-not-allowed">
              <span className="text-lg leading-none">+</span> إضافة صف
            </button>
            <span className="text-xs text-gray-400">({rows.length} صفوف)</span>
          </div>

          <div className="px-5 py-3 border-t border-gray-100 bg-gray-50 flex flex-wrap items-center gap-4">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium text-gray-700">إجمالي المبيعات:</span>
              <input type="number" value={sales} onChange={(e) => setSales(e.target.value)}
                disabled={isReadOnly}
                className="w-32 border border-green-300 rounded-lg px-3 py-1.5 text-base font-bold text-green-700 text-left outline-none focus:ring-2 focus:ring-green-500 bg-white disabled:bg-gray-100 disabled:cursor-not-allowed"
                placeholder="0" />
              <span className="text-xs text-gray-400">جنيه</span>
            </div>
            <div className="flex items-center gap-4 text-sm">
              <span>إجمالي المصروفات: <strong className="text-red-700">{totalExpenses.toFixed(2)}</strong></span>
              <span>صافي اليوم: <strong className={netDaily >= 0 ? 'text-green-700' : 'text-red-700'}>{netDaily.toFixed(2)}</strong></span>
            </div>
            <input value={notes} onChange={(e) => setNotes(e.target.value)}
              disabled={isReadOnly}
              placeholder="ملاحظات..."
              className="border-0 border-b border-dashed border-gray-300 px-1 py-1 text-sm outline-none focus:border-blue-400 bg-transparent w-48 text-left disabled:cursor-not-allowed" />
            <div className="mr-auto flex gap-2">
              <button onClick={handleSave} disabled={isReadOnly || saveMutation.isPending}
                className="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 font-medium shadow-sm">
                {saveMutation.isPending ? '...جاري' : (!editingId ? 'حفظ اليومية' : isEditing ? 'تحديث' : 'تعديل')}
              </button>
            </div>
          </div>
        </div>

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
                          <td className="px-4 py-2 text-gray-600">{e.notes || '—'}</td>
                          <td className="px-4 py-2"><span className="text-blue-500 text-xs">عرض</span></td>
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

      <CatModal open={showCatModal} onClose={() => setShowCatModal(false)} />
    </div>
  );
}

function ItemSelectCell({
  categoryId, isPurchase, value, displayValue, onSelect, onTextChange, className, disabled, onFocus, onBlur,
}: {
  categoryId: string;
  isPurchase: boolean;
  value: string;
  displayValue: string;
  onSelect: (itemId: string, itemName: string) => void;
  onTextChange: (text: string) => void;
  className: string;
  disabled?: boolean;
  onFocus?: () => void;
  onBlur?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const wrapperRef = React.useRef<HTMLDivElement>(null);
  const inputRef = React.useRef<HTMLInputElement>(null);

  const { data: items = [] } = useQuery({
    queryKey: ['financial-items', categoryId],
    queryFn: () => financialApi.items(categoryId),
    enabled: open && isPurchase,
  });

  React.useEffect(() => {
    const handleClick = (e: MouseEvent) => {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, []);

  const filtered = React.useMemo(() => {
    if (!search.trim()) return items;
    const q = search.toLowerCase();
    return items.filter((o: any) => o.name.toLowerCase().includes(q));
  }, [items, search]);

  if (!isPurchase) {
    return (
      <input type="text" value={displayValue}
        onChange={(e) => onTextChange(e.target.value)}
        onFocus={onFocus} onBlur={onBlur}
        disabled={disabled}
        placeholder="..." className={className} />
    );
  }

  return (
    <div ref={wrapperRef} className="relative">
      <input
        ref={inputRef}
        type="text"
        value={displayValue}
        onFocus={() => { if (!disabled) { setOpen(true); setSearch(''); } onFocus?.(); }}
        onBlur={onBlur}
        onChange={(e) => {
          if (disabled) return;
          onTextChange(e.target.value);
          setSearch(e.target.value);
          if (!open) setOpen(true);
        }}
        placeholder="..."
        className={className}
      />
      {open && (
        <div className="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden" style={{ minWidth: 200 }}>
          <div className="p-2 border-b border-gray-100">
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="ابحث عن صنف..."
              className="w-full border border-gray-200 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-blue-300"
              onClick={(e) => e.stopPropagation()}
            />
          </div>
          <div className="max-h-48 overflow-y-auto">
            {filtered.length === 0 ? (
              <div className="px-3 py-4 text-sm text-gray-400 text-center">
                {search ? 'لا توجد نتائج' : 'لا توجد أصناف لهذه الفئة'}
              </div>
            ) : (
              filtered.map((opt: any) => (
                <button
                  key={opt.id}
                  type="button"
                  onMouseDown={() => { onSelect(opt.id, opt.name); setOpen(false); }}
                  className={`w-full text-right px-3 py-2 text-sm flex items-center justify-between hover:bg-blue-50
                    ${value === opt.id ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700'}`}
                >
                  <span>{opt.name}</span>
                  {opt.unit && <span className="text-xs text-gray-400">{opt.unit}</span>}
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
