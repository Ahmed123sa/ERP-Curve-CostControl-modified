'use client';
import { useState, useMemo, useEffect, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

interface InputRow {
  tempId: string;
  id?: string;
  item_id: string;
  name: string;
  unit: string;
  cost_per_unit: string;
  qty_json: Record<number, string>;
}

function emptyInput(): InputRow {
  return {
    tempId: Math.random().toString(36).slice(2),
    item_id: '', name: '', unit: '', cost_per_unit: '', qty_json: {},
  };
}

function calcTotalQty(inp: InputRow): number {
  return Object.values(inp.qty_json).reduce((s, v) => s + (parseFloat(v) || 0), 0);
}

function calcLineTotal(inp: InputRow): number {
  return calcTotalQty(inp) * (parseFloat(inp.cost_per_unit) || 0);
}

export default function CyclicProductionPage() {
  const qc = useQueryClient();
  const today = new Date();
  const [month, setMonth] = useState(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`);
  const [itemId, setItemId] = useState('');
  const [outputRatio, setOutputRatio] = useState('1');
  const [inputs, setInputs] = useState<InputRow[]>([emptyInput()]);
  const [editId, setEditId] = useState<string | null>(null);
  const [showOutputDialog, setShowOutputDialog] = useState(false);
  const [outputQtyAuto, setOutputQtyAuto] = useState(true);
  const [outputDayQty, setOutputDayQty] = useState<Record<number, string>>({});

  const { data: items = [] } = useQuery({
    queryKey: ['items'],
    queryFn: () => api.get('/items').then(r => r.data),
  });

  const { data: records, isLoading } = useQuery({
    queryKey: ['cyclic-manufacturing', month],
    queryFn: () => api.get('/production/cyclic', { params: { month } }).then(r => r.data),
  });

  const itemsById = useMemo(() => {
    const map: Record<string, any> = {};
    items.forEach((i: any) => { map[i.id] = i; });
    return map;
  }, [items]);

  const daysInMonth = useMemo(() => {
    const [y, m] = month.split('-').map(Number);
    return new Date(y, m, 0).getDate();
  }, [month]);

  const enrichedInputs = useMemo(() =>
    inputs.map(inp => ({
      ...inp,
      totalQty: calcTotalQty(inp),
      lineTotal: calcLineTotal(inp),
    })),
    [inputs],
  );

  const calcOutputPerDay = useCallback(() => {
    const byDay: Record<number, number> = {};
    for (const inp of inputs) {
      for (const [d, q] of Object.entries(inp.qty_json)) {
        const day = parseInt(d);
        const qty = parseFloat(q) || 0;
        byDay[day] = (byDay[day] || 0) + qty;
      }
    }
    const ratio = parseFloat(outputRatio) || 1;
    const result: Record<number, string> = {};
    for (let d = 1; d <= daysInMonth; d++) {
      const sum = byDay[d] || 0;
      result[d] = (sum * ratio).toString();
    }
    return result;
  }, [inputs, outputRatio, daysInMonth]);

  const totalOutput = useMemo(() => {
    if (outputQtyAuto) {
      const dayQty = calcOutputPerDay();
      return Object.values(dayQty).reduce((s, v) => s + (parseFloat(v) || 0), 0);
    }
    return Object.values(outputDayQty).reduce((s, v) => s + (parseFloat(v) || 0), 0);
  }, [outputQtyAuto, calcOutputPerDay, outputDayQty]);

  const totalInputCost = useMemo(() =>
    enrichedInputs.reduce((s, inp) => s + inp.lineTotal, 0),
    [enrichedInputs],
  );

  const avgUnitCost = useMemo(() =>
    totalOutput > 0 ? totalInputCost / totalOutput : 0,
    [totalInputCost, totalOutput],
  );

  const totalInputQty = useMemo(() =>
    enrichedInputs.reduce((s, inp) => s + inp.totalQty, 0),
    [enrichedInputs],
  );

  useEffect(() => {
    if (outputQtyAuto) {
      setOutputDayQty(calcOutputPerDay());
    }
  }, [calcOutputPerDay, outputQtyAuto]);

  const invalidate = () => qc.invalidateQueries({ queryKey: ['cyclic-manufacturing', month] });

  const saveMutation = useMutation({
    mutationFn: (payload: any) => editId
      ? api.put(`/production/cyclic/${editId}`, payload)
      : api.post('/production/cyclic', payload),
    onSuccess: () => {
      toast.success(editId ? 'تم التحديث' : 'تم الحفظ');
      invalidate();
      resetForm();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في الحفظ'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/production/cyclic/${id}`),
    onSuccess: () => { toast.success('تم الحذف'); invalidate(); },
  });

  const updatePriceMutation = useMutation({
    mutationFn: (id: string) => api.post(`/production/cyclic/${id}/update-price`),
    onSuccess: (r: any) => toast.success(r.data.message),
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ'),
  });

  const postMutation = useMutation({
    mutationFn: (id: string) => api.post(`/production/cyclic/${id}/post-to-production`),
    onSuccess: (r: any) => {
      toast.success(r.data.message);
      invalidate();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في الترحيل'),
  });

  const resetForm = () => {
    setEditId(null);
    setItemId('');
    setOutputRatio('1');
    setInputs([emptyInput()]);
    setOutputQtyAuto(true);
    setOutputDayQty({});
  };

  const handleEdit = (record: any) => {
    setEditId(record.id);
    setItemId(record.item_id);
    setOutputRatio(String(record.output_ratio || 1));
    const mapped: InputRow[] = record.inputs.map((inp: any) => ({
      tempId: Math.random().toString(36).slice(2),
      id: inp.id,
      item_id: inp.item_id,
      name: inp.item?.name || '',
      unit: inp.unit || inp.item?.unit || '',
      cost_per_unit: String(inp.cost_per_unit),
      qty_json: Object.fromEntries(
        Object.entries(inp.qty_json || {}).map(([d, q]) => [parseInt(d), String(q)])
      ),
    }));
    setInputs(mapped);
    setOutputQtyAuto(false);
    const dayQty: Record<number, string> = {};
    if (record.output_qty_json) {
      for (const [d, q] of Object.entries(record.output_qty_json)) {
        dayQty[parseInt(d)] = String(q);
      }
    }
    setOutputDayQty(dayQty);
  };

  const updateInputCell = (idx: number, day: number, val: string) => {
    setInputs(prev => {
      const next = [...prev];
      next[idx] = { ...next[idx], qty_json: { ...next[idx].qty_json, [day]: val } };
      return next;
    });
  };

  const updateInputField = (idx: number, field: string, value: string) => {
    setInputs(prev => {
      const next = [...prev];
      next[idx] = { ...next[idx], [field]: value };
      if (field === 'item_id') {
        const item = itemsById[value];
        if (item) {
          next[idx].name = item.name || '';
          next[idx].unit = item.unit || '';
          next[idx].cost_per_unit = String(parseFloat(item.default_cost) || 0);
        }
      }
      return next;
    });
  };

  const addInput = () => setInputs(prev => [...prev, emptyInput()]);
  const removeInput = (idx: number) => setInputs(prev => prev.filter((_, i) => i !== idx));

  const handleSave = () => {
    if (!itemId) { toast.error('اختر الصنف المصنع'); return; }
    const payload = {
      item_id: itemId,
      month,
      total_output_qty: totalOutput,
      output_ratio: parseFloat(outputRatio) || 1,
      output_qty_json: outputDayQty,
      inputs: enrichedInputs.filter(inp => inp.item_id).map(inp => ({
        id: inp.id || undefined,
        item_id: inp.item_id,
        unit: inp.unit,
        cost_per_unit: parseFloat(inp.cost_per_unit) || 0,
        qty_json: inp.qty_json,
        total_qty: inp.totalQty,
      })),
    };
    if (!payload.inputs.length) { toast.error('أضف مدخل واحد على الأقل'); return; }
    saveMutation.mutate(payload);
  };

  const handleEditTotalOutput = () => {
    if (!outputQtyAuto) {
      setShowOutputDialog(true);
      return;
    }
    setOutputDayQty(calcOutputPerDay());
    setOutputQtyAuto(false);
    setShowOutputDialog(true);
  };

  const handleOutputDialogSave = () => {
    setShowOutputDialog(false);
  };

  return (
    <div className="space-y-4" dir="rtl">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <h2 className="text-lg font-semibold text-gray-800">تصنيعات دورية</h2>
        {editId && (
          <button onClick={resetForm}
            className="px-3 py-1.5 text-xs border border-gray-200 rounded-lg hover:bg-gray-50">
            + إضافة جديدة
          </button>
        )}
      </div>

      <div className="border border-gray-100 rounded-xl p-4 space-y-3 bg-white">
        <div className="flex gap-4 flex-wrap">
          <div>
            <label className="text-xs text-gray-500 block mb-1">الشهر</label>
            <input type="month" value={month} onChange={e => setMonth(e.target.value)}
              className="px-3 py-2 border border-gray-200 rounded-xl text-sm" />
          </div>
          <div className="flex-1 min-w-[200px]">
            <label className="text-xs text-gray-500 block mb-1">الصنف المصنع</label>
            <select value={itemId} onChange={e => setItemId(e.target.value)}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
              <option value="">اختر...</option>
              {items.map((item: any) => (
                <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
              ))}
            </select>
          </div>
          <div className="w-28">
            <label className="text-xs text-gray-500 block mb-1">نسبة الإنتاج</label>
            <input type="number" value={outputRatio}
              onChange={e => setOutputRatio(e.target.value)}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
              placeholder="1" step="0.01" />
          </div>
          <div className="text-xs text-gray-400 self-end pb-2">
            مثال: 1 كجم جاف → 2 كجم مستوى، أدخل 2
          </div>
        </div>
      </div>

      <div className="border border-gray-100 rounded-xl overflow-auto bg-white">
        <table className="w-full text-sm border-collapse">
          <thead>
            <tr className="bg-gray-50 text-right text-gray-500 text-xs">
              <th className="px-3 py-2.5 font-medium sticky right-0 bg-gray-50 min-w-[140px]">المدخلات</th>
              <th className="px-3 py-2.5 font-medium min-w-[60px]">الوحدة</th>
              <th className="px-3 py-2.5 font-medium min-w-[70px]">سعر الوحدة</th>
              {Array.from({ length: daysInMonth }, (_, i) => i + 1).map(d => (
                <th key={d} className="px-1.5 py-2.5 font-medium text-center min-w-[40px]">{d}</th>
              ))}
              <th className="px-3 py-2.5 font-medium text-center bg-blue-50 min-w-[70px]">الإجمالي</th>
              <th className="px-3 py-2.5 font-medium text-center bg-amber-50 min-w-[80px]">التكلفة</th>
              <th className="px-3 py-2.5 w-8"></th>
            </tr>
          </thead>
          <tbody>
            {enrichedInputs.map((inp, idx) => (
              <tr key={inp.tempId} className="border-t border-gray-50 hover:bg-gray-50/30">
                <td className="px-1 py-1 sticky right-0 bg-white">
                  <select value={inp.item_id}
                    onChange={e => updateInputField(idx, 'item_id', e.target.value)}
                    className="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm">
                    <option value="">اختر...</option>
                    {items.map((item: any) => (
                      <option key={item.id} value={item.id}>{item.name}</option>
                    ))}
                  </select>
                </td>
                <td className="px-1 py-1 text-xs text-gray-400 text-center">{inp.unit || '—'}</td>
                <td className="px-1 py-1">
                  <input type="number" value={inp.cost_per_unit}
                    onChange={e => updateInputField(idx, 'cost_per_unit', e.target.value)}
                    className="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-center"
                    placeholder="0" step="0.01" />
                </td>
                {Array.from({ length: daysInMonth }, (_, i) => i + 1).map(d => (
                  <td key={d} className="px-0.5 py-1 text-center">
                    <input type="number" value={inp.qty_json[d] ?? ''}
                      onChange={e => updateInputCell(idx, d, e.target.value)}
                      className="w-12 px-1 py-1 text-sm text-center border border-transparent rounded focus:border-blue-300 focus:outline-none bg-transparent"
                      placeholder="—" />
                  </td>
                ))}
                <td className="px-3 py-1.5 text-center font-medium text-blue-700 bg-blue-50/50">
                  {inp.totalQty.toFixed(2)}
                </td>
                <td className="px-3 py-1.5 text-center font-mono font-medium text-amber-700 bg-amber-50/50">
                  {inp.lineTotal > 0 ? inp.lineTotal.toFixed(2) : '—'}
                </td>
                <td className="px-1 py-1">
                  <button onClick={() => removeInput(idx)}
                    className="text-gray-300 hover:text-red-400 text-xs">✕</button>
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <td colSpan={3} className="px-1 py-1">
                <button onClick={addInput}
                  className="text-xs text-blue-600 hover:text-blue-700 px-2 py-1">+ أضف مدخل</button>
              </td>
              <td colSpan={daysInMonth}></td>
              <td className="px-3 py-1.5 text-center font-bold text-blue-800 bg-blue-100/50">
                {totalInputQty.toFixed(2)}
              </td>
              <td className="px-3 py-1.5 text-center font-bold font-mono text-amber-800 bg-amber-100/50">
                {totalInputCost.toFixed(2)}
              </td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div className="border border-green-100 rounded-xl p-4 bg-green-50/20 space-y-3">
        <h3 className="text-sm font-semibold text-green-800">الإنتاج</h3>
        <div className="flex items-center gap-4 flex-wrap">
          <div>
            <span className="text-xs text-gray-500">إجمالي الكمية المنتجة:</span>
            <span className="mr-2 text-lg font-bold text-green-700">{totalOutput.toFixed(2)}</span>
            <span className="text-xs text-gray-400 mr-1">{itemsById[itemId]?.unit || 'كجم'}</span>
            <button onClick={handleEditTotalOutput}
              className="mr-2 text-xs text-blue-600 hover:text-blue-700 border border-blue-200 rounded px-2 py-0.5">
              {outputQtyAuto ? 'تعديل يدوي' : 'تعديل الأيام'}
            </button>
          </div>
          <div>
            <span className="text-xs text-gray-500">متوسط سعر الوحدة:</span>
            <span className="mr-2 text-lg font-bold text-blue-700">{avgUnitCost.toFixed(2)} ج</span>
          </div>
          <div>
            <span className="text-xs text-gray-500">إجمالي تكلفة المدخلات:</span>
            <span className="mr-2 text-lg font-bold text-amber-700">{totalInputCost.toFixed(2)} ج</span>
          </div>
        </div>

        <div className="flex gap-2">
          <button onClick={handleSave} disabled={saveMutation.isPending}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40">
            {saveMutation.isPending ? 'جاري الحفظ...' : '💾 حفظ'}
          </button>
          {editId && (
            <>
              <button onClick={() => updatePriceMutation.mutate(editId)}
                disabled={updatePriceMutation.isPending}
                className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-40">
                🔄 تحديث سعر
              </button>
              <button onClick={() => postMutation.mutate(editId)}
                disabled={postMutation.isPending || records?.find((r: any) => r.id === editId)?.posted_to_production}
                className="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-40">
                {postMutation.isPending ? 'جاري الترحيل...' : '📤 ترحيل للإنتاج اليومي'}
              </button>
            </>
          )}
        </div>
      </div>

      {showOutputDialog && (
        <div className="fixed inset-0 bg-black/30 z-50 flex items-center justify-center">
          <div className="bg-white rounded-xl p-6 w-full max-w-lg max-h-[80vh] overflow-auto">
            <h3 className="font-semibold text-gray-700 mb-4">تعديل كميات الإنتاج اليومي</h3>
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50 text-gray-500 text-xs">
                  <th className="px-3 py-2 font-medium text-right">اليوم</th>
                  <th className="px-3 py-2 font-medium text-center">الكمية المنتجة</th>
                </tr>
              </thead>
              <tbody>
                {Array.from({ length: daysInMonth }, (_, i) => i + 1).map(d => {
                  const hasInput = Object.values(inputs).some(inp =>
                    parseFloat(inp.qty_json[d] || '0') > 0
                  );
                  if (!hasInput && parseFloat(outputDayQty[d] || '0') <= 0) return null;
                  return (
                    <tr key={d} className="border-t border-gray-50">
                      <td className="px-3 py-1.5 text-gray-600">{d}</td>
                      <td className="px-3 py-1.5 text-center">
                        <input type="number" value={outputDayQty[d] ?? ''}
                          onChange={e => setOutputDayQty(prev => ({ ...prev, [d]: e.target.value }))}
                          className="w-24 border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-center" step="0.01" />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
              <tfoot>
                <tr className="border-t border-gray-200 font-medium">
                  <td className="px-3 py-2 text-gray-700">إجمالي</td>
                  <td className="px-3 py-2 text-center text-blue-700">
                    {Object.values(outputDayQty).reduce((s, v) => s + (parseFloat(v) || 0), 0).toFixed(2)}
                  </td>
                </tr>
              </tfoot>
            </table>
            <div className="flex justify-end gap-2 mt-4">
              <button onClick={() => setShowOutputDialog(false)}
                className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">إلغاء</button>
              <button onClick={handleOutputDialogSave}
                className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">موافق</button>
            </div>
          </div>
        </div>
      )}

      {isLoading ? (
        <p className="text-gray-400 py-4 text-center">جاري التحميل...</p>
      ) : !records?.length ? (
        <p className="text-gray-400 py-8 text-center">لا توجد تصنيعات دورية لهذا الشهر</p>
      ) : (
        <div className="space-y-2">
          {records.map((record: any) => (
            <div key={record.id}
              className={`border rounded-xl p-4 hover:border-gray-200 flex justify-between items-start gap-2 flex-wrap ${
                record.posted_to_production ? 'border-green-200 bg-green-50/20' : 'border-gray-100'
              }`}>
              <div className="min-w-0">
                <div className="font-medium text-gray-800">
                  {record.item?.name || '—'}
                  <span className="mr-2 text-xs text-gray-400">{record.item?.unit || ''}</span>
                  {record.posted_to_production && (
                    <span className="mr-2 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">تم الترحيل</span>
                  )}
                </div>
                <div className="text-xs text-gray-400 mt-0.5">
                  {record.inputs?.length || 0} مدخل | الإنتاج {record.total_output_qty.toFixed(2)} | التكلفة {record.total_input_cost.toFixed(2)} ج
                </div>
                <div className="text-xs text-gray-400">
                  متوسط سعر الوحدة: {record.avg_unit_cost.toFixed(2)} ج
                </div>
              </div>
              <div className="flex gap-1.5">
                <button onClick={() => handleEdit(record)}
                  className="px-2.5 py-1 text-xs border border-gray-200 rounded-lg hover:bg-gray-50">تعديل</button>
                <button onClick={() => updatePriceMutation.mutate(record.id)}
                  disabled={updatePriceMutation.isPending}
                  className="px-2.5 py-1 text-xs border border-purple-200 text-purple-700 rounded-lg hover:bg-purple-50">تحديث سعر</button>
                {!record.posted_to_production && (
                  <button onClick={() => postMutation.mutate(record.id)}
                    disabled={postMutation.isPending}
                    className="px-2.5 py-1 text-xs border border-green-200 text-green-700 rounded-lg hover:bg-green-50">ترحيل</button>
                )}
                <button onClick={() => { if (confirm('حذف هذا التصنيع الدوري؟')) deleteMutation.mutate(record.id); }}
                  className="px-2.5 py-1 text-xs border border-red-200 text-red-500 rounded-lg hover:bg-red-50">حذف</button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
