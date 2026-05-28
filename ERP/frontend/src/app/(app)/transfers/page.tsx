'use client';
import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

interface ItemRow {
  tempId: string;
  item_id: string;
  qty: string;
  unit_cost: string;
}

const TYPES: Record<string, string> = {
  branch_transfer: 'تحويل',
  branch_return:   'مرتجع للمخزن',
};

const TYPE_COLORS: Record<string, string> = {
  branch_transfer: 'bg-yellow-50 text-yellow-700',
  branch_return:   'bg-teal-50 text-teal-700',
};

function emptyRow(): ItemRow {
  return {
    tempId: Math.random().toString(36).slice(2),
    item_id: '', qty: '', unit_cost: '',
  };
}

export default function TransfersPage() {
  const qc = useQueryClient();
  const today = new Date();
  const [month, setMonth] = useState(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`);
  const [mode, setMode] = useState<'transfer' | 'return'>('transfer');
  const [date, setDate] = useState(today.toISOString().split('T')[0]);
  const [fromBranchId, setFromBranchId] = useState('');
  const [toBranchId, setToBranchId] = useState('');
  const [toWarehouseId, setToWarehouseId] = useState('');
  const [rows, setRows] = useState<ItemRow[]>([emptyRow()]);

  const { data: items = [] } = useQuery({
    queryKey: ['items'],
    queryFn: () => api.get('/items').then(r => r.data),
  });

  const { data: branches = [] } = useQuery({
    queryKey: ['branches'],
    queryFn: () => api.get('/branches').then(r => r.data),
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then(r => r.data),
  });

  const { data: records, isLoading } = useQuery({
    queryKey: ['branch-transfers', month],
    queryFn: () => api.get('/stock/branch-transfers', { params: { month } }).then(r => r.data),
  });

  const itemsById = useMemo(() => {
    const map: Record<string, any> = {};
    items.forEach((i: any) => { map[i.id] = i; });
    return map;
  }, [items]);

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['branch-transfers', month] });
    qc.invalidateQueries({ queryKey: ['stock'] });
  };

  const saveTransfer = useMutation({
    mutationFn: (payload: any) => {
      if (mode === 'transfer') {
        return api.post('/stock/branch-transfer', payload);
      }
      return api.post('/stock/branch-return', payload);
    },
    onSuccess: () => {
      toast.success(mode === 'transfer' ? 'تم التحويل بنجاح' : 'تم الإرجاع بنجاح');
      invalidate();
      setRows([emptyRow()]);
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في الحفظ'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/stock/branch-transfers/${id}`),
    onSuccess: () => { toast.success('تم الحذف وعكس الحركات'); invalidate(); },
  });

  const updateRow = (idx: number, field: string, value: string) => {
    setRows(prev => {
      const next = [...prev];
      next[idx] = { ...next[idx], [field]: value };
      if (field === 'item_id') {
        const item = itemsById[value];
        if (item) next[idx].unit_cost = String(parseFloat(item.default_cost) || 0);
      }
      return next;
    });
  };

  const addRow = () => setRows(prev => [...prev, emptyRow()]);
  const removeRow = (idx: number) => setRows(prev => prev.filter((_, i) => i !== idx));

  const handleSave = () => {
    if (!fromBranchId) { toast.error('اختر الفرع المصدر'); return; }
    if (mode === 'transfer' && !toBranchId) { toast.error('اختر الفرع الهدف'); return; }
    if (mode === 'return' && !toWarehouseId) { toast.error('اختر المخزن الهدف'); return; }

    const lines = rows.filter(r => r.item_id && parseFloat(r.qty) > 0).map(r => ({
      item_id: r.item_id,
      qty: parseFloat(r.qty) || 0,
      unit_cost: parseFloat(r.unit_cost) || 0,
    }));
    if (!lines.length) { toast.error('أضف صنف واحد على الأقل بكمية'); return; }

    const payload: any = { date, items: lines };
    if (mode === 'transfer') {
      payload.from_branch_id = fromBranchId;
      payload.to_branch_id = toBranchId;
    } else {
      payload.branch_id = fromBranchId;
      payload.warehouse_id = toWarehouseId;
    }
    saveTransfer.mutate(payload);
  };

  const mainWarehouses = warehouses.filter((w: any) => w.type !== 'branch');

  return (
    <div className="space-y-4" dir="rtl">
      <h2 className="text-lg font-semibold text-gray-800">تحويلات ومرتجعات الفروع</h2>

      {/* Month + Mode */}
      <div className="flex items-center gap-3 flex-wrap">
        <input type="month" value={month} onChange={e => setMonth(e.target.value)}
          className="px-3 py-2 border border-gray-200 rounded-xl text-sm" />
        <div className="flex bg-gray-100 rounded-lg p-0.5">
          <button onClick={() => setMode('transfer')}
            className={`px-4 py-1.5 text-sm rounded-md ${mode === 'transfer' ? 'bg-white shadow text-blue-700 font-medium' : 'text-gray-500'}`}>
            تحويل
          </button>
          <button onClick={() => setMode('return')}
            className={`px-4 py-1.5 text-sm rounded-md ${mode === 'return' ? 'bg-white shadow text-blue-700 font-medium' : 'text-gray-500'}`}>
            مرتجع للمخزن
          </button>
        </div>
      </div>

      {/* Form */}
      <div className="border border-gray-100 rounded-xl p-4 bg-white space-y-4">
        <div className="flex gap-4 flex-wrap">
          <div className="min-w-[180px] flex-1">
            <label className="text-xs text-gray-500 block mb-1">من الفرع</label>
            <select value={fromBranchId} onChange={e => setFromBranchId(e.target.value)}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
              <option value="">اختر...</option>
              {branches.map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          {mode === 'transfer' ? (
            <div className="min-w-[180px] flex-1">
              <label className="text-xs text-gray-500 block mb-1">إلى الفرع</label>
              <select value={toBranchId} onChange={e => setToBranchId(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                <option value="">اختر...</option>
                {branches.filter((b: any) => b.id !== fromBranchId).map((b: any) => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </select>
            </div>
          ) : (
            <div className="min-w-[180px] flex-1">
              <label className="text-xs text-gray-500 block mb-1">إلى المخزن</label>
              <select value={toWarehouseId} onChange={e => setToWarehouseId(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                <option value="">اختر...</option>
                {mainWarehouses.map((w: any) => <option key={w.id} value={w.id}>{w.name}</option>)}
              </select>
            </div>
          )}
          <div className="min-w-[140px]">
            <label className="text-xs text-gray-500 block mb-1">التاريخ</label>
            <input type="date" value={date} onChange={e => setDate(e.target.value)}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
          </div>
        </div>

        {/* Items Grid */}
        <div className="border border-gray-100 rounded-lg overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                <th className="px-3 py-2 font-medium min-w-[180px]">الصنف</th>
                <th className="px-3 py-2 font-medium w-24">الكمية</th>
                <th className="px-3 py-2 font-medium w-28">سعر الوحدة</th>
                <th className="px-3 py-2 font-medium w-28 text-left">الإجمالي</th>
                <th className="px-3 py-2 w-8"></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, idx) => {
                const qty = parseFloat(row.qty) || 0;
                const cost = parseFloat(row.unit_cost) || 0;
                const total = qty * cost;
                return (
                  <tr key={row.tempId} className="border-t border-gray-50">
                    <td className="px-1 py-1">
                      <select value={row.item_id}
                        onChange={e => updateRow(idx, 'item_id', e.target.value)}
                        className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm">
                        <option value="">اختر...</option>
                        {items.map((item: any) => (
                          <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
                        ))}
                      </select>
                    </td>
                    <td className="px-1 py-1">
                      <input type="number" value={row.qty}
                        onChange={e => updateRow(idx, 'qty', e.target.value)}
                        className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-center"
                        placeholder="0" step="0.01" />
                    </td>
                    <td className="px-1 py-1">
                      <input type="number" value={row.unit_cost}
                        onChange={e => updateRow(idx, 'unit_cost', e.target.value)}
                        className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-center"
                        placeholder="auto" step="0.01" />
                    </td>
                    <td className="px-3 py-1.5 text-left font-mono font-medium text-blue-700">
                      {total > 0 ? total.toFixed(2) : '—'}
                    </td>
                    <td className="px-1 py-1">
                      <button onClick={() => removeRow(idx)}
                        className="text-gray-300 hover:text-red-400 text-xs">✕</button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr className="border-t border-gray-100">
                <td colSpan={5} className="px-3 py-1.5">
                  <button onClick={addRow}
                    className="text-xs text-blue-600 hover:text-blue-700">+ أضف صنف</button>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div className="flex justify-end">
          <button onClick={handleSave} disabled={saveTransfer.isPending}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40">
            {saveTransfer.isPending ? 'جاري الحفظ...' : mode === 'transfer' ? '💾 حفظ التحويل' : '💾 حفظ المرتجع'}
          </button>
        </div>
      </div>

      {/* Records List */}
      {isLoading ? (
        <p className="text-gray-400 text-center py-4">جاري التحميل...</p>
      ) : !records?.length ? (
        <p className="text-gray-400 text-center py-8">لا توجد تحويلات أو مرتجعات لهذا الشهر</p>
      ) : (
        <div className="space-y-2">
          {records.map((r: any) => (
            <div key={r.id}
              className={`border rounded-xl p-4 flex justify-between items-start gap-2 flex-wrap ${r.type === 'branch_return' ? 'border-teal-100' : 'border-yellow-100'}`}>
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <span className={`px-2 py-0.5 text-xs rounded-full ${TYPE_COLORS[r.type] || 'bg-gray-50'}`}>
                    {TYPES[r.type] || r.type}
                  </span>
                  <span className="text-xs text-gray-400">{r.date}</span>
                </div>
                <div className="text-sm text-gray-700 mt-1">
                  {r.lines_count} صنف | إجمالي {r.lines.reduce((s: number, l: any) => s + (l.total_cost || 0), 0).toFixed(2)} ج
                </div>
                <div className="text-xs text-gray-400 mt-0.5">
                  {r.items?.map((l: any) => `${l.item?.name || '?'} (${l.qty})`).join(' · ') ||
                   r.lines?.map((l: any) => `${l.item?.name || '?'} (${l.qty})`).join(' · ')}
                </div>
              </div>
              <button onClick={() => { if (confirm('حذف هذه العملية؟')) deleteMutation.mutate(r.id); }}
                className="px-2.5 py-1 text-xs border border-red-200 text-red-500 rounded-lg hover:bg-red-50">حذف</button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
