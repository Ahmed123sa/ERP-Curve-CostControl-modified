'use client';
import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import toast from 'react-hot-toast';

interface InputRow {
  tempId: string;
  item_id: string;
  name: string;
  qty: string;
  cost_per_kg: string;
}

interface OutputRow {
  tempId: string;
  item_id: string;
  name: string;
  qty: string;
}

interface ProcessStep {
  name: string;
  net_weight: string;
}

function emptyInput(): InputRow {
  return { tempId: Math.random().toString(36).slice(2), item_id: '', name: '', qty: '', cost_per_kg: '' };
}

function emptyOutput(): OutputRow {
  return { tempId: Math.random().toString(36).slice(2), item_id: '', name: '', qty: '' };
}

export default function ProcessingPage() {
  const qc = useQueryClient();
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [name, setName] = useState('');
  const [inputs, setInputs] = useState<InputRow[]>([emptyInput()]);
  const [processes, setProcesses] = useState<ProcessStep[]>([]);
  const [outputs, setOutputs] = useState<OutputRow[]>([emptyOutput()]);
  const [showForm, setShowForm] = useState(false);
  const { currentClient } = useAuthStore();

  const { data: items = [] } = useQuery({
    queryKey: ['items', currentClient?.id],
    queryFn: () => api.get('/items').then(r => r.data),
  });

  const { data: batches, isLoading } = useQuery({
    queryKey: ['processing-batches'],
    queryFn: () => api.get('/production/processing').then(r => r.data),
  });

  const itemsById = useMemo(() => {
    const map: Record<string, any> = {};
    items.forEach((i: any) => { map[i.id] = i; });
    return map;
  }, [items]);

  const invalidate = () => qc.invalidateQueries({ queryKey: ['processing-batches'] });

  const saveMutation = useMutation({
    mutationFn: (payload: any) => api.post('/production/processing', payload),
    onSuccess: () => {
      toast.success('تم حفظ المعالجة');
      invalidate();
      resetForm();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في الحفظ'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/production/processing/${id}`),
    onSuccess: () => { toast.success('تم الحذف'); invalidate(); },
  });

  const resetForm = () => {
    setShowForm(false);
    setDate(new Date().toISOString().slice(0, 10));
    setName('');
    setInputs([emptyInput()]);
    setProcesses([]);
    setOutputs([emptyOutput()]);
  };

  // ── Input handlers ──
  const updateInput = (idx: number, field: string, value: string) => {
    setInputs(prev => {
      const next = [...prev];
      next[idx] = { ...next[idx], [field]: value };
      if (field === 'item_id') {
        const item = itemsById[value];
        if (item) {
          next[idx].name = item.name || '';
          next[idx].cost_per_kg = String(parseFloat(item.default_cost) || 0);
        }
      }
      return next;
    });
  };

  const addInput = () => setInputs(prev => [...prev, emptyInput()]);
  const removeInput = (idx: number) => setInputs(prev => prev.filter((_, i) => i !== idx));

  // ── Process handlers ──
  const addProcess = () => setProcesses(prev => [...prev, { name: '', net_weight: '' }]);
  const updateProcess = (idx: number, field: string, value: string) => {
    setProcesses(prev => {
      const next = [...prev];
      next[idx] = { ...next[idx], [field]: value };
      return next;
    });
  };
  const removeProcess = (idx: number) => setProcesses(prev => prev.filter((_, i) => i !== idx));

  // ── Output handlers ──
  const updateOutput = (idx: number, field: string, value: string) => {
    setOutputs(prev => {
      const next = [...prev];
      next[idx] = { ...next[idx], [field]: value };
      if (field === 'item_id') {
        const item = itemsById[value];
        if (item) next[idx].name = item.name || '';
      }
      return next;
    });
  };

  const addOutput = () => setOutputs(prev => [...prev, emptyOutput()]);
  const removeOutput = (idx: number) => setOutputs(prev => prev.filter((_, i) => i !== idx));

  // ── Calculations ──
  const totalInputCost = useMemo(() =>
    inputs.reduce((s, inp) => s + (parseFloat(inp.qty) || 0) * (parseFloat(inp.cost_per_kg) || 0), 0),
    [inputs],
  );

  const outputTotals = useMemo(() => {
    const totalOutputQty = outputs.reduce((s, out) => s + (parseFloat(out.qty) || 0), 0);
    return outputs.map(out => {
      const qty = parseFloat(out.qty) || 0;
      const allocPct = totalOutputQty > 0 ? (qty / totalOutputQty) * 100 : 0;
      const totalCost = totalOutputQty > 0 ? totalInputCost * (qty / totalOutputQty) : 0;
      const costPerKg = qty > 0 ? totalCost / qty : 0;
      return { allocPct, totalCost, costPerKg };
    });
  }, [outputs, totalInputCost]);

  const totalInputQty = useMemo(() =>
    inputs.reduce((s, inp) => s + (parseFloat(inp.qty) || 0), 0),
    [inputs],
  );

  const processChain = useMemo(() => {
    const steps: { name: string; net: number; lossPct: number }[] = [];
    let prev = totalInputQty;
    for (const p of processes) {
      const net = parseFloat(p.net_weight) || 0;
      const lossPct = prev > 0 && net > 0 ? ((prev - net) / prev) * 100 : 0;
      steps.push({ name: p.name, net, lossPct });
      if (net > 0) prev = net;
    }
    return steps;
  }, [processes, totalInputQty]);

  const estimatedOutputPct = useMemo(() => {
    if (processChain.length === 0) return 100;
    const last = processChain[processChain.length - 1];
    return totalInputQty > 0 && last.net > 0 ? (last.net / totalInputQty) * 100 : 0;
  }, [processChain, totalInputQty]);

  const handleSubmit = () => {
    const payload = {
      date,
      name: name.trim(),
      processes: processes.filter(p => p.name.trim()).map(p => ({ name: p.name.trim(), net_weight: parseFloat(p.net_weight) || 0 })),
      inputs: inputs.filter(inp => inp.item_id && inp.qty).map(inp => ({
        item_id: inp.item_id,
        qty: parseFloat(inp.qty) || 0,
        cost_per_kg: parseFloat(inp.cost_per_kg) || 0,
      })),
      outputs: outputs.filter(out => out.item_id && out.qty).map(out => ({
        item_id: out.item_id,
        qty: parseFloat(out.qty) || 0,
      })),
    };
    if (!payload.inputs.length) { toast.error('أضف مدخل واحد على الأقل'); return; }
    if (!payload.outputs.length) { toast.error('أضف مخرج واحد على الأقل'); return; }
    if (!payload.name) { toast.error('أدخل اسم المعالجة'); return; }
    saveMutation.mutate(payload);
  };

  return (
    <div className="space-y-4" dir="rtl">
      <div className="flex justify-between items-center">
        <h2 className="text-lg font-semibold text-gray-800">معالجة المواد</h2>
        <button onClick={() => { resetForm(); setShowForm(true); }}
          className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
          + معالجة جديدة
        </button>
      </div>

      {showForm && (
        <div className="border border-gray-100 rounded-xl p-4 space-y-4 bg-white">
          <h3 className="font-semibold text-gray-700">معالجة جديدة</h3>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs text-gray-500 mb-1">التاريخ</label>
              <input type="date" value={date} onChange={(e) => setDate(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">الاسم / البيان</label>
              <input type="text" value={name} onChange={(e) => setName(e.target.value)}
                placeholder="مثال: تقشير جمبري"
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
            </div>
          </div>

          {/* المدخلات */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs text-gray-500 font-medium">المدخلات (الخامات)</label>
              <button onClick={addInput} className="text-xs text-blue-600 hover:text-blue-700">+ أضف مدخل</button>
            </div>
            <table className="w-full text-sm border border-gray-100 rounded-lg overflow-hidden">
              <thead>
                <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                  <th className="px-3 py-2 font-normal">الصنف</th>
                  <th className="px-3 py-2 font-normal w-28">الكمية (كجم)</th>
                  <th className="px-3 py-2 font-normal w-28">سعر الكيلو</th>
                  <th className="px-3 py-2 font-normal w-28 text-left">الإجمالي</th>
                  <th className="px-3 py-2 w-8"></th>
                </tr>
              </thead>
              <tbody>
                {inputs.map((inp, idx) => {
                  const lineTotal = (parseFloat(inp.qty) || 0) * (parseFloat(inp.cost_per_kg) || 0);
                  return (
                    <tr key={inp.tempId} className="border-t border-gray-50">
                      <td className="px-1 py-1">
                        <select value={inp.item_id}
                          onChange={(e) => updateInput(idx, 'item_id', e.target.value)}
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm">
                          <option value="">اختر...</option>
                          {items.map((item: any) => (
                            <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
                          ))}
                        </select>
                      </td>
                      <td className="px-1 py-1">
                        <input type="number" value={inp.qty}
                          onChange={(e) => updateInput(idx, 'qty', e.target.value)}
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm" placeholder="0" />
                      </td>
                      <td className="px-1 py-1">
                        <input type="number" value={inp.cost_per_kg}
                          onChange={(e) => updateInput(idx, 'cost_per_kg', e.target.value)}
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm" placeholder="0" />
                      </td>
                      <td className="px-3 py-1.5 text-left font-mono font-medium tabular-nums text-blue-700">
                        {lineTotal > 0 ? lineTotal.toFixed(2) : '—'}
                      </td>
                      <td className="px-1 py-1">
                        <button onClick={() => removeInput(idx)} className="text-gray-300 hover:text-red-400 text-xs">✕</button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
              <tfoot>
                <tr className="bg-gray-50 border-t border-gray-100 font-medium text-sm">
                  <td colSpan={3} className="px-3 py-2 text-gray-600">إجمالي تكلفة المدخلات</td>
                  <td className="px-3 py-2 text-left text-blue-700 tabular-nums">{totalInputCost.toFixed(2)} ج</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>

          {/* العمليات */}
          <div className="border border-blue-100 rounded-lg p-3 bg-blue-50/30 space-y-2">
            <div className="flex items-center justify-between">
              <label className="text-xs text-blue-700 font-medium">العمليات (اختياري — أدخل الوزن الصافي بعد كل عملية)</label>
              <button onClick={addProcess} className="text-xs text-blue-600 hover:text-blue-700">+ أضف عملية</button>
            </div>
            {processes.length > 0 && (
              <div className="space-y-1.5">
                {processChain.map((step, idx) => {
                  const prevWt = idx === 0 ? totalInputQty : processChain[idx - 1].net;
                  return (
                    <div key={idx} className="flex gap-2 items-center">
                      <span className="text-xs text-gray-400 w-14">{prevWt.toFixed(2)} كجم →</span>
                      <input type="text" value={processes[idx].name}
                        onChange={(e) => updateProcess(idx, 'name', e.target.value)}
                        placeholder="اسم العملية"
                        className="w-40 border border-blue-200 rounded-lg px-3 py-1.5 text-sm" />
                      <input type="number" value={processes[idx].net_weight}
                        onChange={(e) => updateProcess(idx, 'net_weight', e.target.value)}
                        placeholder="الوزن الصافي"
                        className="w-28 border border-blue-200 rounded-lg px-3 py-1.5 text-sm" />
                      <span className="text-xs text-gray-400 w-16">بعد العملية</span>
                      {step.lossPct > 0 && (
                        <span className="text-xs text-orange-500 w-20">فاقد {step.lossPct.toFixed(1)}%</span>
                      )}
                      <button onClick={() => removeProcess(idx)} className="text-gray-300 hover:text-red-400 text-xs">✕</button>
                    </div>
                  );
                })}
                {processes.length > 0 && (
                  <div className="text-xs text-blue-600 mt-1">
                    نسبة الصافي المتوقعة: <strong>{estimatedOutputPct.toFixed(1)}%</strong>
                    {totalInputCost > 0 && estimatedOutputPct > 0 && (
                      <span className="mr-2">
                        | التكلفة المتوقعة للكيلو: <strong>{(totalInputCost / (totalInputQty * estimatedOutputPct / 100)).toFixed(2)} ج</strong>
                      </span>
                    )}
                  </div>
                )}
              </div>
            )}
          </div>

          {/* المخرجات */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs text-gray-500 font-medium">المخرجات (المنتج النهائي)</label>
              <button onClick={addOutput} className="text-xs text-blue-600 hover:text-blue-700">+ أضف مخرج</button>
            </div>
            <table className="w-full text-sm border border-gray-100 rounded-lg overflow-hidden">
              <thead>
                <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                  <th className="px-3 py-2 font-normal">الصنف</th>
                  <th className="px-3 py-2 font-normal w-28">الكمية (كجم)</th>
                  <th className="px-3 py-2 font-normal w-24 text-left">نسبة التوزيع</th>
                  <th className="px-3 py-2 font-normal w-28 text-left">التكلفة الإجمالية</th>
                  <th className="px-3 py-2 font-normal w-28 text-left">تكلفة الكيلو</th>
                  <th className="px-3 py-2 w-8"></th>
                </tr>
              </thead>
              <tbody>
                {outputs.map((out, idx) => {
                  const calc = outputTotals[idx] || { allocPct: 0, totalCost: 0, costPerKg: 0 };
                  return (
                    <tr key={out.tempId} className="border-t border-gray-50">
                      <td className="px-1 py-1">
                        <select value={out.item_id}
                          onChange={(e) => updateOutput(idx, 'item_id', e.target.value)}
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm">
                          <option value="">اختر...</option>
                          {items.map((item: any) => (
                            <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
                          ))}
                        </select>
                      </td>
                      <td className="px-1 py-1">
                        <input type="number" value={out.qty}
                          onChange={(e) => updateOutput(idx, 'qty', e.target.value)}
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm" placeholder="0" />
                      </td>
                      <td className="px-3 py-1.5 text-left text-gray-500 tabular-nums">{calc.allocPct.toFixed(1)}%</td>
                      <td className="px-3 py-1.5 text-left font-mono font-medium tabular-nums text-blue-700">
                        {calc.totalCost > 0 ? calc.totalCost.toFixed(2) : '—'}
                      </td>
                      <td className="px-3 py-1.5 text-left font-mono font-bold tabular-nums text-green-700">
                        {calc.costPerKg > 0 ? calc.costPerKg.toFixed(2) + ' ج' : '—'}
                      </td>
                      <td className="px-1 py-1">
                        <button onClick={() => removeOutput(idx)} className="text-gray-300 hover:text-red-400 text-xs">✕</button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
            <button onClick={resetForm} className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">إلغاء</button>
            <button onClick={handleSubmit} disabled={saveMutation.isPending}
              className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40">
              {saveMutation.isPending ? 'جاري الحفظ...' : '💾 حفظ المعالجة'}
            </button>
          </div>
        </div>
      )}

      {/* عرض المعالجات السابقة */}
      {isLoading ? (
        <p className="text-gray-400">جاري التحميل...</p>
      ) : !batches?.length ? (
        <p className="text-gray-400 py-8 text-center">لا توجد معالجات — أضف معالجة جديدة</p>
      ) : (
        <div className="space-y-2">
          {batches.map((b: any) => (
            <div key={b.id} className="border border-gray-100 rounded-xl p-4 hover:border-gray-200">
              <div className="flex justify-between items-start">
                <div>
                  <div className="font-medium text-gray-800">{b.name}</div>
                  <div className="text-xs text-gray-400 mt-0.5">{b.date}</div>
                </div>
                <div className="flex gap-2 items-center">
                  <span className="text-sm text-gray-500">
                    {b.inputs_count} مدخل · {b.outputs_count} مخرج
                  </span>
                  <span className="px-2 py-0.5 text-xs bg-blue-50 text-blue-700 rounded-full font-mono">
                    {b.total_input_cost.toFixed(2)} ج
                  </span>
                  <button onClick={() => { if (confirm('حذف المعالجة؟')) deleteMutation.mutate(b.id); }}
                    className="px-2 py-1 text-xs text-red-500 border border-red-200 rounded-lg hover:bg-red-50">حذف</button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
