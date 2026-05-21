'use client';
// components/grid/VoucherGrid.tsx
// شبكة إدخال يدوي — زي Excel بالظبط

import { useState, useRef, useEffect, KeyboardEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

interface GridRow {
  id: string; // temp id للـ UI
  item_id: string;
  item_name: string;
  warehouse_id: string;
  warehouse_name: string;
  qty: string;
  cost: string;
  unit_cost: number; // محسوب
}

interface Props {
  type: string;
  date: string;
  warehouseId?: string;
  branchId?: string;
  orderId?: string;
  initialData?: GridRow[];
  onSaved?: () => void;
}

const emptyRow = (): GridRow => ({
  id:             Math.random().toString(36).slice(2),
  item_id:        '',
  item_name:      '',
  warehouse_id:   '',
  warehouse_name: '',
  qty:            '',
  cost:           '',
  unit_cost:      0,
});

export function VoucherGrid({ type, date, warehouseId, branchId, orderId, initialData, onSaved }: Props) {
  const qc = useQueryClient();
  const [rows, setRows] = useState<GridRow[]>(() => {
    if (initialData && initialData.length > 0) return initialData;
    return [emptyRow()];
  });
  const [activeCell, setActiveCell] = useState<{ row: number; col: string } | null>(null);
  const [itemSearch, setItemSearch] = useState('');
  const inputRefs = useRef<Record<string, HTMLElement | null>>({});

  // جيب قائمة الأصناف
  const { data: items = [] } = useQuery({
    queryKey: ['items'],
    queryFn: () => api.get('/items').then((r) => r.data),
  });

  // جيب المخازن
  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const saveMutation = useMutation({
    mutationFn: () => {
      const lines = rows
        .filter((r) => r.item_id && r.qty)
        .map((r) => ({
          item_id:      r.item_id,
          warehouse_id: r.warehouse_id || warehouseId,
          qty:          parseFloat(r.qty) || 0,
          cost:         parseFloat(r.cost) || 0,
        }));

      const payload = {
        type,
        date,
        warehouse_id: warehouseId,
        branch_id:    branchId,
        lines,
      };

      if (orderId) {
        return api.put(`/vouchers/${orderId}`, payload);
      }
      return api.post('/vouchers/manual', payload);
    },
    onSuccess: () => {
      toast.success(orderId ? 'تم تحديث الإذن ✓' : 'تم الحفظ ✓');
      qc.invalidateQueries({ queryKey: ['vouchers'] });
      qc.invalidateQueries({ queryKey: ['stock'] });
      qc.invalidateQueries({ queryKey: ['closing'] });
      setRows([emptyRow()]);
      onSaved?.();
    },
  });

  // تحديث صف
  const updateRow = (idx: number, field: keyof GridRow, value: string) => {
    setRows((prev) => {
      const next = [...prev];
      next[idx] = { ...next[idx], [field]: value };

      // حساب unit_cost تلقائي
      if (field === 'qty' || field === 'cost') {
        const qty  = parseFloat(field === 'qty' ? value : next[idx].qty) || 0;
        const cost = parseFloat(field === 'cost' ? value : next[idx].cost) || 0;
        next[idx].unit_cost = qty > 0 && cost > 0 ? Math.round((cost / qty) * 10000) / 10000 : 0;
      }

      // أضف صف جديد لو الأخير اتملأ
      if (idx === next.length - 1 && next[idx].item_id) {
        next.push(emptyRow());
      }

      return next;
    });
  };

  // اختيار صنف من الـ dropdown
  const selectItem = (idx: number, item: any) => {
    setRows((prev) => {
      const next = [...prev];
      next[idx] = { ...next[idx], item_id: item.id, item_name: item.name };
      return next;
    });
    setActiveCell(null);
  };

  // حذف صف
  const deleteRow = (idx: number) => {
    setRows((prev) => prev.length > 1 ? prev.filter((_, i) => i !== idx) : [emptyRow()]);
  };

  // مجاميع
  const totalQty  = rows.reduce((s, r) => s + (parseFloat(r.qty) || 0), 0);
  const totalCost = rows.reduce((s, r) => s + (parseFloat(r.cost) || 0), 0);
  const validRows = rows.filter((r) => r.item_id && r.qty).length;

  const filteredItems = items.filter((i: any) =>
    i.name.includes(itemSearch) || itemSearch === ''
  ).slice(0, 10);

  return (
    <div className="space-y-3" dir="rtl">
      {/* الجدول */}
      <div className="border border-gray-100 rounded-xl overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50">
            <tr className="text-right text-gray-500 text-xs">
              <th className="px-3 py-2.5 font-medium w-8">#</th>
              <th className="px-3 py-2.5 font-medium">الصنف</th>
              {!warehouseId && <th className="px-3 py-2.5 font-medium">المخزن</th>}
              <th className="px-3 py-2.5 font-medium w-28">الكمية</th>
              {['purchase','opening','adjustment','return'].includes(type) && (
                <>
                  <th className="px-3 py-2.5 font-medium w-32">Cost إجمالي</th>
                  <th className="px-3 py-2.5 font-medium w-28 text-gray-400">سعر الوحدة</th>
                </>
              )}
              <th className="px-3 py-2.5 w-8"></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row, idx) => (
              <tr key={row.id} className="border-t border-gray-50 hover:bg-gray-50/50 relative">
                {/* رقم السطر */}
                <td className="px-3 py-1.5 text-gray-300 text-xs">{idx + 1}</td>

                {/* اسم الصنف — مع autocomplete */}
                <td className="px-1 py-1 relative">
                   <input
                     ref={el => { if (el) inputRefs.current[`${idx}-item`] = el; }}
                     value={row.item_name}
                     onChange={(e) => {
                       updateRow(idx, 'item_name', e.target.value);
                       setItemSearch(e.target.value);
                       setActiveCell({ row: idx, col: 'item' });
                     }}
                     onFocus={() => setActiveCell({ row: idx, col: 'item' })}
                     placeholder="اكتب اسم الصنف..."
                     className="w-full px-2 py-1.5 text-sm border border-transparent rounded-lg
                                focus:border-blue-300 focus:bg-white focus:outline-none bg-transparent"
                   />
                  {/* Dropdown */}
                  {activeCell?.row === idx && activeCell?.col === 'item' && filteredItems.length > 0 && (
                    <div className="absolute z-50 right-1 top-full mt-1 w-64 bg-white border border-gray-200
                                    rounded-lg shadow-lg overflow-hidden">
                      {filteredItems.map((item: any) => (
                        <button
                          key={item.id}
                          className="w-full text-right px-3 py-2 text-sm hover:bg-blue-50 flex justify-between items-center"
                          onMouseDown={(e) => { e.preventDefault(); selectItem(idx, item); }}
                        >
                          <span>{item.name}</span>
                          <span className="text-xs text-gray-400">{item.unit}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </td>

                {/* الكمية */}
                <td className="px-1 py-1">
                  <input
                    type="number"
                    value={row.qty}
                    onChange={(e) => updateRow(idx, 'qty', e.target.value)}
                    onKeyDown={(e: KeyboardEvent<HTMLInputElement>) => {
                      if (e.key === 'Enter' || e.key === 'Tab') {
                        const nextEl = inputRefs.current[`${idx}-cost`] ?? inputRefs.current[`${idx+1}-item`];
                        (nextEl as HTMLElement)?.focus();
                      }
                    }}
                    placeholder="0"
                    className="w-full px-2 py-1.5 text-sm text-left border border-transparent rounded-lg
                               focus:border-blue-300 focus:bg-white focus:outline-none bg-transparent"
                  />
                </td>

                {/* Cost إجمالي — للمشتريات والتسويات وأول المدة */}
                {['purchase','opening','adjustment','return'].includes(type) && (
                  <>
                     <td className="px-1 py-1">
                       <input
                         ref={el => { if (el) inputRefs.current[`${idx}-cost`] = el; }}
                         type="number"
                         value={row.cost}
                         onChange={(e) => updateRow(idx, 'cost', e.target.value)}
                         onKeyDown={(e: KeyboardEvent<HTMLInputElement>) => {
                           if (e.key === 'Enter' || e.key === 'Tab') {
                             (inputRefs.current[`${idx+1}-item`] as HTMLElement)?.focus();
                           }
                         }}
                         placeholder="0"
                         className="w-full px-2 py-1.5 text-sm text-left border border-transparent rounded-lg
                                    focus:border-blue-300 focus:bg-white focus:outline-none bg-transparent"
                       />
                     </td>
                    {/* سعر الوحدة — محسوب */}
                    <td className="px-3 py-1.5 text-gray-400 text-xs tabular-nums">
                      {row.unit_cost > 0 ? row.unit_cost.toFixed(2) : '—'}
                    </td>
                  </>
                )}

                {/* حذف */}
                <td className="px-2 py-1.5">
                  <button
                    onClick={() => deleteRow(idx)}
                    className="text-gray-300 hover:text-red-400 text-xs px-1"
                  >✕</button>
                </td>
              </tr>
            ))}
          </tbody>

          {/* Footer — مجاميع */}
          {validRows > 0 && (
            <tfoot className="bg-gray-50 border-t border-gray-100">
              <tr className="text-sm font-medium text-gray-700">
                <td className="px-3 py-2.5 text-gray-400 text-xs" colSpan={2}>
                  {validRows} صنف
                </td>
                <td className="px-3 py-2.5 tabular-nums">{totalQty.toLocaleString('ar-EG')}</td>
                {['purchase','opening','adjustment','return'].includes(type) && (
                  <td className="px-3 py-2.5 tabular-nums">
                    {totalCost.toLocaleString('ar-EG', { minimumFractionDigits: 0 })} ج
                  </td>
                )}
              </tr>
            </tfoot>
          )}
        </table>
      </div>

      {/* زرار الحفظ */}
      <div className="flex justify-end">
        <button
          onClick={() => saveMutation.mutate()}
          disabled={validRows === 0 || saveMutation.isPending}
          className="px-6 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-xl
                     hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed"
        >
          {saveMutation.isPending ? 'جاري الحفظ...' : orderId ? `تحديث ${validRows} صنف` : `حفظ ${validRows} صنف`}
        </button>
      </div>
    </div>
  );
}
