'use client';
import { useState, useRef, useEffect, KeyboardEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

interface GridRow {
  id: string;
  item_id: string;
  item_name: string;
  default_cost: number;
  warehouse_id: string;
  warehouse_name: string;
  qty: string;
  cost: string;
  unit_cost: number;
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
  default_cost:   0,
  warehouse_id:   '',
  warehouse_name: '',
  qty:            '',
  cost:           '',
  unit_cost:      0,
});

const COL_ORDER = ['item', 'warehouse', 'qty', 'cost', 'delete'] as const;
type ColKey = (typeof COL_ORDER)[number];

function nextCol(currentCol: ColKey, type: string): ColKey | null {
  const cols = COL_ORDER.filter(c => c !== 'warehouse' || true);
  const idx = cols.indexOf(currentCol);
  return idx < cols.length - 1 ? cols[idx + 1] : null;
}

function prevCol(currentCol: ColKey, type: string): ColKey | null {
  const cols = COL_ORDER.filter(c => c !== 'warehouse' || true);
  const idx = cols.indexOf(currentCol);
  return idx > 0 ? cols[idx - 1] : null;
}

export function VoucherGrid({ type, date, warehouseId, branchId, orderId, initialData, onSaved }: Props) {
  const qc = useQueryClient();
  const [rows, setRows] = useState<GridRow[]>(() => {
    if (initialData && initialData.length > 0) return [...initialData, emptyRow()];
    return Array.from({ length: 5 }, () => emptyRow());
  });
  const [activeCell, setActiveCell] = useState<{ row: number; col: string } | null>(null);
  const [itemSearch, setItemSearch] = useState('');
  const inputRefs = useRef<Record<string, HTMLElement | null>>({});

  const showWarehouse = !warehouseId;

  useEffect(() => {
    if (initialData && initialData.length > 0) {
      setRows([...initialData, emptyRow()]);
    }
  }, [initialData]);

  const { data: items = [], isLoading: itemsLoading } = useQuery({
    queryKey: ['items'],
    queryFn: () => api.get('/items').then((r) => r.data),
  });

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
      setRows(Array.from({ length: 5 }, () => emptyRow()));
      onSaved?.();
    },
    onError: (err: any) => {
      const msg = err?.response?.data?.message || err?.message || 'حدث خطأ غير متوقع';
      toast.error(msg);
    },
  });

  const updateRow = (idx: number, field: keyof GridRow, value: string) => {
    setRows((prev) => {
      const next = [...prev];
      next[idx] = { ...next[idx], [field]: value };
      if (field === 'qty') {
        const qty = parseFloat(value) || 0;
        if (type === 'dispatch' && next[idx].default_cost > 0) {
          next[idx].cost = String(qty * next[idx].default_cost);
        }
        const cost = parseFloat(next[idx].cost) || 0;
        next[idx].unit_cost = qty > 0 && cost > 0 ? Math.round((cost / qty) * 10000) / 10000 : 0;
      }
      if (field === 'cost' && type !== 'dispatch') {
        const qty  = parseFloat(next[idx].qty) || 0;
        const cost = parseFloat(value) || 0;
        next[idx].unit_cost = qty > 0 && cost > 0 ? Math.round((cost / qty) * 10000) / 10000 : 0;
      }
      if (idx === next.length - 1 && next[idx].item_id) {
        next.push(emptyRow());
      }
      return next;
    });
  };

  const selectItem = (idx: number, item: any) => {
    setRows((prev) => {
      const next = [...prev];
      next[idx] = {
        ...next[idx],
        item_id: item.id,
        item_name: item.name,
        default_cost: item.default_cost || 0,
        cost: type === 'dispatch' && item.default_cost > 0
          ? String((parseFloat(next[idx].qty) || 0) * item.default_cost)
          : next[idx].cost,
      };
      return next;
    });
    setActiveCell(null);
  };

  const deleteRow = (idx: number) => {
    setRows((prev) => prev.length > 1 ? prev.filter((_, i) => i !== idx) : [emptyRow()]);
  };

  const moveTo = (row: number, col: ColKey) => {
    const key = col === 'warehouse' ? `${row}-warehouse` : col === 'delete' ? `${row}-delete` : col === 'cost' ? `${row}-cost` : col === 'qty' ? `${row}-qty` : `${row}-item`;
    const target = inputRefs.current[key] as HTMLElement;
    if (target) {
      target.focus();
      if (target.tagName === 'INPUT' || target.tagName === 'SELECT') {
        (target as HTMLInputElement).select?.();
      }
    }
  };

  const handleKeyDown = (e: KeyboardEvent, idx: number, col: ColKey) => {
    if (e.key === 'Tab') {
      e.preventDefault();
      const next = e.shiftKey ? prevCol(col, type) : nextCol(col, type);
      if (next === null) {
        if (e.shiftKey) {
          if (idx > 0) moveTo(idx - 1, 'cost');
        } else if (idx < rows.length - 1) {
          moveTo(idx + 1, 'item');
        }
      } else {
        moveTo(idx, next);
      }
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (idx < rows.length - 1) moveTo(idx + 1, col);
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (idx > 0) moveTo(idx - 1, col);
    }
  };

  const totalQty  = rows.reduce((s, r) => s + (parseFloat(r.qty) || 0), 0);
  const totalCost = rows.reduce((s, r) => s + (parseFloat(r.cost) || 0), 0);
  const validRows = rows.filter((r) => r.item_id && r.qty).length;

  const filteredItems = items.filter((i: any) =>
    i.name.includes(itemSearch) || itemSearch === ''
  ).slice(0, 10);

  return (
    <div className="space-y-3" dir="rtl">
      <div className="border border-gray-200 rounded-xl overflow-hidden shadow-sm">
        <table className="w-full text-sm">
          <thead className="bg-gray-100">
            <tr className="text-right text-gray-600 text-xs font-semibold">
              <th className="px-4 py-3 w-10">#</th>
              <th className="px-4 py-3">الصنف</th>
              {showWarehouse && <th className="px-4 py-3 min-w-[130px]">المخزن</th>}
              <th className="px-4 py-3 min-w-[100px]">الكمية</th>
              <th className="px-4 py-3 min-w-[120px]">التكلفة</th>
              <th className="px-4 py-3 min-w-[90px] text-gray-400">سعر الوحدة</th>
              <th className="px-4 py-3 w-10"></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row, idx) => {
              const isActive = activeCell?.row === idx;
              const isEmpty = !row.item_id;
              return (
                <tr key={row.id}
                  className={`border-t border-gray-100 transition-colors
                    ${isActive ? 'bg-blue-50/40' : 'hover:bg-gray-50/50'}
                    ${isEmpty ? '' : ''}`}
                >
                  <td className="px-4 py-2 text-gray-300 text-xs text-center">{idx + 1}</td>

                  {/* اسم الصنف */}
                  <td className="px-2 py-1.5 relative">
                    {row.item_id && !isActive ? (
                      <div
                        className="w-full px-3 py-2 text-sm text-gray-800 font-medium cursor-pointer rounded-lg hover:bg-blue-50"
                        onClick={() => { setActiveCell({ row: idx, col: 'item' }); setItemSearch(row.item_name); }}
                      >
                        {row.item_name}
                      </div>
                    ) : (
                      <input
                        ref={el => { if (el) inputRefs.current[`${idx}-item`] = el; }}
                        value={row.item_name}
                        onChange={(e) => {
                          updateRow(idx, 'item_name', e.target.value);
                          setItemSearch(e.target.value);
                          setActiveCell({ row: idx, col: 'item' });
                        }}
                        onFocus={() => { setActiveCell({ row: idx, col: 'item' }); setItemSearch(row.item_name); }}
                        onKeyDown={(e) => handleKeyDown(e, idx, 'item')}
                        placeholder="ابحث عن صنف..."
                        className="w-full px-3 py-2 text-sm border border-blue-200 rounded-lg bg-white outline-none ring-1 ring-blue-100"
                        autoComplete="off"
                      />
                    )}
                    {activeCell?.row === idx && activeCell?.col === 'item' && (() => {
                      if (itemsLoading) {
                        return (
                          <div className="absolute z-50 right-0 top-full mt-1 w-72 bg-white border border-gray-200 rounded-lg shadow-xl">
                            <div className="px-4 py-3 text-sm text-gray-400 text-center">جاري التحميل...</div>
                          </div>
                        );
                      }
                      if (filteredItems.length === 0 && itemSearch) {
                        return (
                          <div className="absolute z-50 right-0 top-full mt-1 w-72 bg-white border border-gray-200 rounded-lg shadow-xl">
                            <div className="px-4 py-3 text-sm text-gray-400 text-center">لا توجد نتائج</div>
                          </div>
                        );
                      }
                      if (filteredItems.length > 0) {
                        return (
                          <div className="absolute z-50 right-0 top-full mt-1 w-72 bg-white border border-gray-200 rounded-lg shadow-xl overflow-hidden max-h-60 overflow-y-auto">
                            {filteredItems.map((item: any) => (
                              <button
                                key={item.id}
                                className="w-full text-right px-4 py-2.5 text-sm hover:bg-blue-50 flex justify-between items-center border-b border-gray-50 last:border-0"
                                onMouseDown={(e) => { e.preventDefault(); selectItem(idx, item); }}
                              >
                                <span className="font-medium">{item.name}</span>
                                <span className="text-xs text-gray-400">{item.unit}</span>
                              </button>
                            ))}
                          </div>
                        );
                      }
                      return null;
                    })()}
                  </td>

                  {/* المخزن */}
                  {showWarehouse && (
                    <td className="px-2 py-1.5">
                      <select
                        ref={el => { if (el) inputRefs.current[`${idx}-warehouse`] = el; }}
                        value={row.warehouse_id}
                        onChange={(e) => updateRow(idx, 'warehouse_id', e.target.value)}
                        onFocus={() => setActiveCell({ row: idx, col: 'warehouse' })}
                        onKeyDown={(e) => handleKeyDown(e, idx, 'warehouse')}
                        className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white outline-none focus:border-blue-300 focus:ring-1 focus:ring-blue-100"
                      >
                        <option value="">اختر...</option>
                        {warehouses.map((w: any) => (
                          <option key={w.id} value={w.id}>{w.name}</option>
                        ))}
                      </select>
                    </td>
                  )}

                  {/* الكمية */}
                  <td className="px-2 py-1.5">
                    <input
                      ref={el => { if (el) inputRefs.current[`${idx}-qty`] = el; }}
                      type="number"
                      value={row.qty}
                      onChange={(e) => updateRow(idx, 'qty', e.target.value)}
                      onFocus={() => setActiveCell({ row: idx, col: 'qty' })}
                      onKeyDown={(e) => handleKeyDown(e, idx, 'qty')}
                      placeholder="0"
                      className="w-full px-3 py-2 text-sm text-left border border-gray-200 rounded-lg outline-none focus:border-blue-300 focus:ring-1 focus:ring-blue-100"
                    />
                  </td>

                  {/* التكلفة */}
                  <td className="px-2 py-1.5">
                    <input
                      ref={el => { if (el) inputRefs.current[`${idx}-cost`] = el; }}
                      type="number"
                      value={row.cost}
                      onChange={(e) => updateRow(idx, 'cost', e.target.value)}
                      onFocus={() => setActiveCell({ row: idx, col: 'cost' })}
                      onKeyDown={(e) => handleKeyDown(e, idx, 'cost')}
                      readOnly={type === 'dispatch'}
                      placeholder="0"
                      className={`w-full px-3 py-2 text-sm text-left border border-gray-200 rounded-lg outline-none focus:border-blue-300 focus:ring-1 focus:ring-blue-100 ${type === 'dispatch' ? 'bg-gray-50 text-gray-500 cursor-default' : ''}`}
                    />
                  </td>
                  <td className="px-4 py-2 text-gray-400 text-xs tabular-nums">
                    {row.unit_cost > 0 ? row.unit_cost.toFixed(2) : '—'}
                  </td>

                  {/* حذف */}
                  <td className="px-2 py-1.5 text-center">
                    <button
                      onClick={() => deleteRow(idx)}
                      className="text-gray-300 hover:text-red-400 hover:bg-red-50 rounded-lg p-1.5 transition-colors"
                      title="حذف"
                    >✕</button>
                  </td>
                </tr>
              );
            })}
          </tbody>

          {validRows > 0 && (
            <tfoot className="bg-gray-50 border-t border-gray-200">
              <tr className="text-sm font-semibold text-gray-700">
                <td className="px-4 py-3 text-gray-400 text-xs">{validRows} صنف</td>
                <td></td>
                {showWarehouse && <td></td>}
                <td className="px-4 py-3 tabular-nums">{totalQty.toLocaleString('ar-EG')}</td>
                <td className="px-4 py-3 tabular-nums">{totalCost.toLocaleString('ar-EG', { minimumFractionDigits: 0 })} ج</td>
                <td></td>
                <td></td>
              </tr>
            </tfoot>
          )}
        </table>
      </div>

      <div className="flex justify-between items-center">
        <button
          type="button"
          onClick={() => setRows((prev) => [...prev, emptyRow()])}
          className="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-xl hover:bg-gray-200 transition-colors"
        >
          + إضافة صف
        </button>
        <button
          onClick={() => saveMutation.mutate()}
          disabled={validRows === 0 || saveMutation.isPending}
          className="px-6 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-xl hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed shadow-sm transition-all"
        >
          {saveMutation.isPending ? 'جاري الحفظ...' : orderId ? `تحديث ${validRows} صنف` : `حفظ ${validRows} صنف`}
        </button>
      </div>
    </div>
  );
}
