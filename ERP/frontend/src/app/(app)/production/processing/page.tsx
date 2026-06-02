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

interface DayForm {
  tempId: string;
  date: string;
  processes: ProcessStep[];
  inputs: InputRow[];
  outputs: OutputRow[];
  expanded: boolean;
}

function emptyInput(): InputRow {
  return { tempId: Math.random().toString(36).slice(2), item_id: '', name: '', qty: '', cost_per_kg: '' };
}

function emptyOutput(): OutputRow {
  return { tempId: Math.random().toString(36).slice(2), item_id: '', name: '', qty: '' };
}

function emptyDay(date?: string): DayForm {
  return {
    tempId: Math.random().toString(36).slice(2),
    date: date || new Date().toISOString().slice(0, 10),
    processes: [],
    inputs: [emptyInput()],
    outputs: [emptyOutput()],
    expanded: true,
  };
}

export default function ProcessingPage() {
  const qc = useQueryClient();
  const [name, setName] = useState('');
  const [days, setDays] = useState<DayForm[]>([emptyDay()]);
  const [showForm, setShowForm] = useState(false);
  const [editBatchId, setEditBatchId] = useState<string | null>(null);
  const [expandedBatches, setExpandedBatches] = useState<Set<string>>(new Set());
  const { currentClient } = useAuthStore();
  const [tab, setTab] = useState<'batches' | 'summary'>('batches');
  const today = new Date();
  const [summaryMonth, setSummaryMonth] = useState(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`);
  const [batchesMonth, setBatchesMonth] = useState(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`);

  const { data: items = [] } = useQuery({
    queryKey: ['items', currentClient?.id],
    queryFn: () => api.get('/items').then(r => r.data),
  });

  const { data: batches, isLoading } = useQuery({
    queryKey: ['processing-batches', batchesMonth],
    queryFn: () => api.get('/production/processing', { params: { month: batchesMonth } }).then(r => r.data),
  });

  const { data: summary, isLoading: summaryLoading } = useQuery({
    queryKey: ['processing-summary', summaryMonth],
    queryFn: () => api.get('/production/processing/summary', { params: { month: summaryMonth } }).then(r => r.data),
    enabled: tab === 'summary',
  });

  const { data: dailyRecipes } = useQuery({
    queryKey: ['daily-recipes'],
    queryFn: () => api.get('/production/daily', { params: { month: summaryMonth } }).then(r => r.data),
    enabled: tab === 'summary',
  });

  const itemsById = useMemo(() => {
    const map: Record<string, any> = {};
    items.forEach((i: any) => { map[i.id] = i; });
    return map;
  }, [items]);

  const invalidate = () => qc.invalidateQueries({ queryKey: ['processing-batches'] });
  const invalidateSummary = () => qc.invalidateQueries({ queryKey: ['processing-summary', summaryMonth] });

  const saveMutation = useMutation({
    mutationFn: (payload: any) => api.post('/production/processing', payload),
    onSuccess: () => {
      toast.success('تم حفظ المعالجة');
      invalidate();
      resetForm();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في الحفظ'),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: any }) => api.put(`/production/processing/${id}`, payload),
    onSuccess: () => {
      toast.success('تم تحديث المعالجة');
      invalidate();
      resetForm();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في التحديث'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/production/processing/${id}`),
    onSuccess: () => { toast.success('تم الحذف'); invalidate(); },
  });

  const deleteMonthMutation = useMutation({
    mutationFn: (month: string) => api.post('/production/processing/delete-month', { month }),
    onSuccess: (r: any) => { toast.success(r.data.message); invalidate(); },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في حذف الشهر'),
  });

  const [showSyncModal, setShowSyncModal] = useState(false);
  const [syncItems, setSyncItems] = useState<{ item_id: string; name: string; cost: number; dayId: string }[]>([]);
  const [selectedSyncIds, setSelectedSyncIds] = useState<Set<string>>(new Set());

  const syncMutation = useMutation({
    mutationFn: ({ id, item_ids }: { id: string; item_ids: string[] }) => api.post(`/production/processing/${id}/sync-costs`, { item_ids }),
    onSuccess: (r: any) => {
      toast.success(r.data.message);
      setShowSyncModal(false);
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في التحديث'),
  });

  const [showPostModal, setShowPostModal] = useState(false);
  const [postEntries, setPostEntries] = useState<{ item_id: string; name: string; qty: number; recipe_id: string; day: number }[]>([]);
  const [selectedPostIds, setSelectedPostIds] = useState<Set<string>>(new Set());

  const postToDailyMutation = useMutation({
    mutationFn: (payload: any) => api.post('/production/processing/summary/post-to-daily', payload),
    onSuccess: (r: any) => {
      toast.success(r.data.message);
      setShowPostModal(false);
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في التحويل'),
  });

  const syncItemCostMutation = useMutation({
    mutationFn: ({ item_id, month }: { item_id: string; month: string }) =>
      api.post('/production/processing/summary/sync-item-cost', { item_id, month }),
    onSuccess: (r: any) => {
      toast.success(r.data.message);
      invalidateSummary();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ في تحديث السعر'),
  });

  const toggleBatchExpand = (id: string) => {
    setExpandedBatches(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  const toggleDayExpand = (dayTempId: string) => {
    setDays(prev => prev.map(d => d.tempId === dayTempId ? { ...d, expanded: !d.expanded } : d));
  };

  const resetForm = () => {
    setShowForm(false);
    setEditBatchId(null);
    setName('');
    setDays([emptyDay()]);
  };

  const loadBatchForEdit = async (batchId: string) => {
    try {
      const { data } = await api.get(`/production/processing/${batchId}`);
      setName(data.name || '');
      const loadedDays: DayForm[] = (data.days || []).map((d: any, idx: number) => ({
        tempId: Math.random().toString(36).slice(2),
        date: d.date?.slice(0, 10) || new Date().toISOString().slice(0, 10),
        processes: (d.processes || []).map((p: any) => ({ name: p.name || '', net_weight: String(p.net_weight || '') })),
        inputs: d.inputs.length ? d.inputs.map((i: any) => ({
          tempId: Math.random().toString(36).slice(2),
          item_id: i.item_id,
          name: i.item?.name || '',
          qty: String(i.qty),
          cost_per_kg: String(i.cost_per_kg),
        })) : [emptyInput()],
        outputs: d.outputs.length ? d.outputs.map((o: any) => ({
          tempId: Math.random().toString(36).slice(2),
          item_id: o.item_id,
          name: o.item?.name || '',
          qty: String(o.qty),
        })) : [emptyOutput()],
        expanded: idx === 0,
      }));
      setDays(loadedDays);
      setEditBatchId(batchId);
      setShowForm(true);
    } catch {
      toast.error('فشل تحميل بيانات المعالجة');
    }
  };

  // ── Day handlers ──
  const updateDay = (dayTempId: string, field: string, value: any) => {
    setDays(prev => prev.map(d => d.tempId === dayTempId ? { ...d, [field]: value } : d));
  };

  const addDay = () => {
    const last = days[days.length - 1];
    const newDay = emptyDay();
    // copy items from last day as defaults
    if (last) {
      newDay.inputs = last.inputs.map(i => ({ ...emptyInput(), item_id: i.item_id, name: i.name, cost_per_kg: i.cost_per_kg }));
      newDay.outputs = last.outputs.map(o => ({ ...emptyOutput(), item_id: o.item_id, name: o.name }));
      newDay.processes = last.processes.map(p => ({ ...p }));
    }
    setDays(prev => [...prev, newDay]);
  };

  const removeDay = (dayTempId: string) => {
    if (days.length <= 1) { toast.error('يجب وجود يوم واحد على الأقل'); return; }
    setDays(prev => prev.filter(d => d.tempId !== dayTempId));
  };

  // ── Input handlers (per day) ──
  const updateDayInput = (dayTempId: string, idx: number, field: string, value: string) => {
    setDays(prev => prev.map(d => {
      if (d.tempId !== dayTempId) return d;
      const next = [...d.inputs];
      next[idx] = { ...next[idx], [field]: value };
      if (field === 'item_id') {
        const item = itemsById[value];
        if (item) {
          next[idx].name = item.name || '';
          next[idx].cost_per_kg = String(parseFloat(item.default_cost) || 0);
        }
      }
      return { ...d, inputs: next };
    }));
  };

  const addDayInput = (dayTempId: string) => {
    setDays(prev => prev.map(d => d.tempId === dayTempId ? { ...d, inputs: [...d.inputs, emptyInput()] } : d));
  };

  const removeDayInput = (dayTempId: string, idx: number) => {
    setDays(prev => prev.map(d => d.tempId === dayTempId ? { ...d, inputs: d.inputs.filter((_, i) => i !== idx) } : d));
  };

  // ── Process handlers (per day) ──
  const addDayProcess = (dayTempId: string) => {
    setDays(prev => prev.map(d => d.tempId === dayTempId ? { ...d, processes: [...d.processes, { name: '', net_weight: '' }] } : d));
  };

  const updateDayProcess = (dayTempId: string, idx: number, field: string, value: string) => {
    setDays(prev => prev.map(d => {
      if (d.tempId !== dayTempId) return d;
      const next = [...d.processes];
      next[idx] = { ...next[idx], [field]: value };
      return { ...d, processes: next };
    }));
  };

  const removeDayProcess = (dayTempId: string, idx: number) => {
    setDays(prev => prev.map(d => d.tempId === dayTempId ? { ...d, processes: d.processes.filter((_, i) => i !== idx) } : d));
  };

  // ── Output handlers (per day) ──
  const updateDayOutput = (dayTempId: string, idx: number, field: string, value: string) => {
    setDays(prev => prev.map(d => {
      if (d.tempId !== dayTempId) return d;
      const next = [...d.outputs];
      next[idx] = { ...next[idx], [field]: value };
      if (field === 'item_id') {
        const item = itemsById[value];
        if (item) next[idx].name = item.name || '';
      }
      return { ...d, outputs: next };
    }));
  };

  const addDayOutput = (dayTempId: string) => {
    setDays(prev => prev.map(d => d.tempId === dayTempId ? { ...d, outputs: [...d.outputs, emptyOutput()] } : d));
  };

  const removeDayOutput = (dayTempId: string, idx: number) => {
    setDays(prev => prev.map(d => d.tempId === dayTempId ? { ...d, outputs: d.outputs.filter((_, i) => i !== idx) } : d));
  };

  // ── Calculations (per day) ──
  function calcDay(day: DayForm) {
    const totalInputCost = day.inputs.reduce((s, inp) => s + (parseFloat(inp.qty) || 0) * (parseFloat(inp.cost_per_kg) || 0), 0);
    const totalOutputQty = day.outputs.reduce((s, out) => s + (parseFloat(out.qty) || 0), 0);
    const outputTotals = day.outputs.map(out => {
      const qty = parseFloat(out.qty) || 0;
      const allocPct = totalOutputQty > 0 ? (qty / totalOutputQty) * 100 : 0;
      const totalCost = totalOutputQty > 0 ? totalInputCost * (qty / totalOutputQty) : 0;
      const costPerKg = qty > 0 ? totalCost / qty : 0;
      return { allocPct, totalCost, costPerKg };
    });
    const processChain = (() => {
      const steps: { name: string; net: number; lossPct: number }[] = [];
      let prev = day.inputs.reduce((s, inp) => s + (parseFloat(inp.qty) || 0), 0);
      for (const p of day.processes) {
        const net = parseFloat(p.net_weight) || 0;
        const lossPct = prev > 0 && net > 0 ? ((prev - net) / prev) * 100 : 0;
        steps.push({ name: p.name, net, lossPct });
        if (net > 0) prev = net;
      }
      return steps;
    })();
    return { totalInputCost, totalOutputQty, outputTotals, processChain };
  }

  // ── Submit ──
  const handleSubmit = () => {
    const payload = {
      name: name.trim(),
      notes: null,
      days: days.map((d, idx) => ({
        ...(editBatchId ? { id: (d as any).id || undefined } : {}),
        date: d.date,
        processes: d.processes.filter(p => p.name.trim()).map(p => ({ name: p.name.trim(), net_weight: parseFloat(p.net_weight) || 0 })),
        inputs: d.inputs.filter(inp => inp.item_id && inp.qty).map(inp => ({
          item_id: inp.item_id,
          qty: parseFloat(inp.qty) || 0,
          cost_per_kg: parseFloat(inp.cost_per_kg) || 0,
        })),
        outputs: d.outputs.filter(out => out.item_id && out.qty).map(out => ({
          item_id: out.item_id,
          qty: parseFloat(out.qty) || 0,
        })),
      })),
    };
    if (!payload.name) { toast.error('أدخل اسم المعالجة'); return; }
    const hasInvalidDay = payload.days.some(d => !d.inputs.length || !d.outputs.length);
    if (hasInvalidDay) { toast.error('كل يوم يحتاج مدخل ومخرج واحد على الأقل'); return; }
    if (editBatchId) {
      updateMutation.mutate({ id: editBatchId, payload });
    } else {
      saveMutation.mutate(payload);
    }
  };

  const openSyncModal = () => {
    const items = days.flatMap(d => calcDay(d).outputTotals.map((calc, i) => ({
      item_id: d.outputs[i]?.item_id || '',
      name: d.outputs[i]?.name || '',
      cost: calc.costPerKg,
      dayId: d.tempId,
    }))).filter(i => i.item_id);
    setSyncItems(items);
    setSelectedSyncIds(new Set(items.map(i => i.item_id)));
    setShowSyncModal(true);
  };

  // ── Render helpers ──
  function renderDayForm(day: DayForm, idx: number) {
    const calc = calcDay(day);
    return (
      <div key={day.tempId} className="border border-gray-100 rounded-lg mb-3">
        <div className="flex items-center justify-between px-3 py-2 bg-gray-50 rounded-t-lg cursor-pointer select-none"
          onClick={() => toggleDayExpand(day.tempId)}>
          <div className="flex items-center gap-2">
            <span className="text-xs text-gray-400">{day.expanded ? '▼' : '▶'}</span>
            <span className="text-sm font-medium text-gray-700">
              {day.date || 'تاريخ'} — {calc.totalOutputQty.toFixed(2)} كجم
            </span>
          </div>
          <button onClick={(e) => { e.stopPropagation(); removeDay(day.tempId); }}
            className="text-xs text-red-400 hover:text-red-600">حذف اليوم</button>
        </div>

        {day.expanded && (
          <div className="p-3 space-y-3">
            <div>
              <label className="block text-xs text-gray-500 mb-1">التاريخ</label>
              <input type="date" value={day.date} onChange={(e) => updateDay(day.tempId, 'date', e.target.value)}
                className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-48" />
            </div>

            {/* المدخلات */}
            <div>
              <div className="flex items-center justify-between mb-1">
                <label className="text-xs text-gray-500 font-medium">المدخلات (الخامات)</label>
                <button onClick={() => addDayInput(day.tempId)} className="text-xs text-blue-600">+ أضف مدخل</button>
              </div>
              <table className="w-full text-sm border border-gray-100 rounded-lg overflow-hidden">
                <thead>
                  <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                    <th className="px-3 py-1.5 font-normal">الصنف</th>
                    <th className="px-3 py-1.5 font-normal w-24">الكمية (كجم)</th>
                    <th className="px-3 py-1.5 font-normal w-24">سعر الكيلو</th>
                    <th className="px-3 py-1.5 font-normal w-24 text-left">الإجمالي</th>
                    <th className="px-3 py-1.5 w-8"></th>
                  </tr>
                </thead>
                <tbody>
                  {day.inputs.map((inp, inpIdx) => {
                    const lineTotal = (parseFloat(inp.qty) || 0) * (parseFloat(inp.cost_per_kg) || 0);
                    return (
                      <tr key={inp.tempId} className="border-t border-gray-50">
                        <td className="px-1 py-1">
                          <select value={inp.item_id} onChange={(e) => updateDayInput(day.tempId, inpIdx, 'item_id', e.target.value)}
                            className="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm">
                            <option value="">اختر...</option>
                            {items.map((item: any) => (
                              <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
                            ))}
                          </select>
                        </td>
                        <td className="px-1 py-1">
                          <input type="number" value={inp.qty} onChange={(e) => updateDayInput(day.tempId, inpIdx, 'qty', e.target.value)}
                            className="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" placeholder="0" />
                        </td>
                        <td className="px-1 py-1">
                          <input type="number" value={inp.cost_per_kg} onChange={(e) => updateDayInput(day.tempId, inpIdx, 'cost_per_kg', e.target.value)}
                            className="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" placeholder="0" />
                        </td>
                        <td className="px-2 py-1.5 text-left tabular-nums text-gray-600">
                          {lineTotal > 0 ? lineTotal.toFixed(2) : '—'}
                        </td>
                        <td className="px-1 py-1">
                          <button onClick={() => removeDayInput(day.tempId, inpIdx)} className="text-gray-300 hover:text-red-400 text-xs">✕</button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
                <tfoot>
                  <tr className="bg-gray-50 border-t border-gray-100">
                    <td className="px-3 py-1.5 text-xs text-gray-500 font-medium">إجمالي تكلفة المدخلات</td>
                    <td colSpan={3} className="px-3 py-1.5 text-left tabular-nums font-mono font-bold text-blue-700">
                      {calc.totalInputCost.toFixed(2)} ج
                    </td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>

            {/* العمليات */}
            <div>
              <div className="flex items-center justify-between mb-1">
                <label className="text-xs text-gray-500 font-medium">العمليات (اختياري — أدخل الوزن الصافي بعد كل عملية)</label>
                <button onClick={() => addDayProcess(day.tempId)} className="text-xs text-blue-600">+ أضف عملية</button>
              </div>
              {day.processes.length > 0 && (
                <table className="w-full text-sm border border-gray-100 rounded-lg overflow-hidden">
                  <thead>
                    <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                      <th className="px-3 py-1.5 font-normal">العملية</th>
                      <th className="px-3 py-1.5 font-normal w-28">الوزن الصافي (كجم)</th>
                      <th className="px-3 py-1.5 font-normal w-24 text-left">الفاقد %</th>
                      <th className="px-3 py-1.5 w-8"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {day.processes.map((p, pIdx) => {
                      const prev = pIdx === 0
                        ? day.inputs.reduce((s, inp) => s + (parseFloat(inp.qty) || 0), 0)
                        : (() => { const pp = calc.processChain[pIdx - 1]; return pp ? pp.net : 0; })();
                      const net = parseFloat(p.net_weight) || 0;
                      const lossPct = prev > 0 && net > 0 ? ((prev - net) / prev) * 100 : 0;
                      return (
                        <tr key={pIdx} className="border-t border-gray-50">
                          <td className="px-3 py-1.5">
                            <input type="text" value={p.name} onChange={(e) => updateDayProcess(day.tempId, pIdx, 'name', e.target.value)}
                              placeholder="مثال: تقشير"
                              className="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
                          </td>
                          <td className="px-1 py-1.5">
                            <input type="number" value={p.net_weight} onChange={(e) => updateDayProcess(day.tempId, pIdx, 'net_weight', e.target.value)}
                              className="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" placeholder="0" />
                          </td>
                          <td className="px-3 py-1.5 text-left tabular-nums text-amber-600 text-xs">
                            {lossPct > 0 ? `${lossPct.toFixed(1)}%` : '—'}
                          </td>
                          <td className="px-1 py-1.5">
                            <button onClick={() => removeDayProcess(day.tempId, pIdx)} className="text-gray-300 hover:text-red-400 text-xs">✕</button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>

            {/* المخرجات */}
            <div>
              <div className="flex items-center justify-between mb-1">
                <label className="text-xs text-gray-500 font-medium">المخرجات (المنتج النهائي)</label>
                <button onClick={() => addDayOutput(day.tempId)} className="text-xs text-blue-600">+ أضف مخرج</button>
              </div>
              <table className="w-full text-sm border border-gray-100 rounded-lg overflow-hidden">
                <thead>
                  <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                    <th className="px-3 py-1.5 font-normal">الصنف</th>
                    <th className="px-3 py-1.5 font-normal w-24">الكمية (كجم)</th>
                    <th className="px-3 py-1.5 font-normal w-20 text-left">نسبة التوزيع</th>
                    <th className="px-3 py-1.5 font-normal w-24 text-left">التكلفة الإجمالية</th>
                    <th className="px-3 py-1.5 font-normal w-24 text-left">تكلفة الكيلو</th>
                    <th className="px-3 py-1.5 w-8"></th>
                  </tr>
                </thead>
                <tbody>
                  {day.outputs.map((out, outIdx) => {
                    const calcItem = calc.outputTotals[outIdx] || { allocPct: 0, totalCost: 0, costPerKg: 0 };
                    return (
                      <tr key={out.tempId} className="border-t border-gray-50">
                        <td className="px-1 py-1">
                          <select value={out.item_id} onChange={(e) => updateDayOutput(day.tempId, outIdx, 'item_id', e.target.value)}
                            className="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm">
                            <option value="">اختر...</option>
                            {items.map((item: any) => (
                              <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
                            ))}
                          </select>
                        </td>
                        <td className="px-1 py-1">
                          <input type="number" value={out.qty} onChange={(e) => updateDayOutput(day.tempId, outIdx, 'qty', e.target.value)}
                            className="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" placeholder="0" />
                        </td>
                        <td className="px-2 py-1.5 text-left text-gray-500 tabular-nums text-xs">{calcItem.allocPct.toFixed(1)}%</td>
                        <td className="px-2 py-1.5 text-left font-mono tabular-nums text-blue-700">{calcItem.totalCost > 0 ? calcItem.totalCost.toFixed(2) : '—'}</td>
                        <td className="px-2 py-1.5 text-left font-mono tabular-nums text-green-700">{calcItem.costPerKg > 0 ? calcItem.costPerKg.toFixed(2) + ' ج' : '—'}</td>
                        <td className="px-1 py-1">
                          <button onClick={() => removeDayOutput(day.tempId, outIdx)} className="text-gray-300 hover:text-red-400 text-xs">✕</button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    );
  }

  // ── Render ──
  return (
    <div className="space-y-4" dir="rtl">
      <div className="flex justify-between items-center">
        <div className="flex gap-1 bg-gray-100 rounded-lg p-0.5">
          <button onClick={() => setTab('batches')} className={`px-3 py-1.5 text-sm rounded-lg transition-all ${tab === 'batches' ? 'bg-white shadow-sm font-medium text-blue-700' : 'text-gray-500 hover:text-gray-700'}`}>كل المعالجات</button>
          <button onClick={() => setTab('summary')} className={`px-3 py-1.5 text-sm rounded-lg transition-all ${tab === 'summary' ? 'bg-white shadow-sm font-medium text-blue-700' : 'text-gray-500 hover:text-gray-700'}`}>ملخص شهري</button>
        </div>
        <button onClick={() => { resetForm(); setShowForm(true); }}
          className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
          + معالجة جديدة
        </button>
      </div>

      {tab === 'batches' && (<>
        {!showForm && (
          <div className="border border-gray-100 rounded-xl p-3 bg-white">
            <div className="flex items-center justify-between flex-wrap gap-2">
              <input type="month" value={batchesMonth} onChange={(e) => setBatchesMonth(e.target.value)}
                className="px-3 py-1.5 border border-gray-200 rounded-lg text-sm" />
              <button onClick={() => {
                if (!confirm(`حذف جميع معالجات شهر ${batchesMonth}؟ هذا لن يؤثر على الإنتاج اليومي.`)) return;
                deleteMonthMutation.mutate(batchesMonth);
              }}
                className="px-3 py-1.5 text-xs border border-red-300 text-red-600 rounded-lg hover:bg-red-50">
                حذف معالجات الشهر
              </button>
            </div>
          </div>
        )}
        {showForm && (
          <div className="border border-gray-100 rounded-xl p-4 space-y-4 bg-white">
            <h3 className="font-semibold text-gray-700">{editBatchId ? 'تعديل المعالجة' : 'معالجة جديدة'}</h3>

            <div>
              <label className="block text-xs text-gray-500 mb-1">الاسم / البيان</label>
              <input type="text" value={name} onChange={(e) => setName(e.target.value)}
                placeholder="مثال: مكرونة بينه مستوي"
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
            </div>

            {/* الأيام */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <label className="text-xs text-gray-500 font-medium">تواريخ المعالجة</label>
                <button onClick={addDay} className="text-xs text-blue-600 hover:text-blue-700">+ إضافة يوم</button>
              </div>
              {days.map((day, idx) => renderDayForm(day, idx))}
            </div>

            {/* تحديث سعر المنتجات — يظهر فقط في وضع التعديل */}
            {editBatchId && days.some(d => d.outputs.some(o => o.item_id)) && (
              <div className="flex justify-end">
                <button onClick={openSyncModal} className="px-3 py-1.5 text-xs border border-amber-300 text-amber-700 rounded-lg hover:bg-amber-50">
                  تحديث سعر المنتجات
                </button>
              </div>
            )}

            {showSyncModal && (
              <div className="fixed inset-0 bg-black/30 z-50 flex items-center justify-center" onClick={() => setShowSyncModal(false)}>
                <div className="bg-white rounded-xl shadow-xl p-5 w-full max-w-sm mx-4" onClick={(e) => e.stopPropagation()}>
                  <h4 className="font-semibold text-sm mb-3">تحديث سعر المنتجات</h4>
                  <p className="text-xs text-gray-500 mb-3">اختر المنتجات اللي عاوز تحدث سعرها في الأصناف:</p>
                  <div className="space-y-1 max-h-48 overflow-auto">
                    {Array.from(new Map(syncItems.map(i => [i.item_id, i])).values()).map((item) => (
                      <label key={item.item_id} className="flex items-center gap-2 cursor-pointer text-sm p-1.5 rounded hover:bg-gray-50">
                        <input type="checkbox" checked={selectedSyncIds.has(item.item_id)}
                          onChange={() => { const next = new Set(selectedSyncIds); if (next.has(item.item_id)) next.delete(item.item_id); else next.add(item.item_id); setSelectedSyncIds(next); }}
                          className="rounded border-gray-300" />
                        <span className="flex-1">{item.name}</span>
                        <span className="font-mono text-xs text-green-600">{item.cost.toFixed(2)} ج</span>
                      </label>
                    ))}
                  </div>
                  <div className="flex justify-end gap-2 mt-4 pt-3 border-t border-gray-100">
                    <button onClick={() => setShowSyncModal(false)} className="px-3 py-1.5 text-xs border border-gray-200 rounded-lg hover:bg-gray-50">إلغاء</button>
                    <button onClick={() => { if (editBatchId) syncMutation.mutate({ id: editBatchId, item_ids: Array.from(selectedSyncIds) }); }}
                      disabled={syncMutation.isPending || !selectedSyncIds.size}
                      className="px-3 py-1.5 text-xs bg-amber-600 text-white rounded-lg hover:bg-amber-700 disabled:opacity-40">
                      {syncMutation.isPending ? 'جاري...' : `تحديث (${selectedSyncIds.size})`}
                    </button>
                  </div>
                </div>
              </div>
            )}

            <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
              <button onClick={resetForm} className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">إلغاء</button>
              <button onClick={handleSubmit} disabled={saveMutation.isPending || updateMutation.isPending}
                className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-40">
                {saveMutation.isPending || updateMutation.isPending ? 'جاري الحفظ...' : editBatchId ? '💾 تحديث المعالجة' : '💾 حفظ المعالجة'}
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
              <div key={b.id} className="border border-gray-100 rounded-xl overflow-hidden">
                <div className="flex justify-between items-center p-4 hover:bg-gray-50 transition-all cursor-pointer"
                  onClick={() => toggleBatchExpand(b.id)}>
                  <div className="flex items-center gap-3">
                    <span className="text-xs text-gray-400 w-4">{expandedBatches.has(b.id) ? '▼' : '▶'}</span>
                    <div>
                      <div className="font-medium text-gray-800">{b.name}</div>
                      <div className="text-xs text-gray-400 mt-0.5">
                        {b.dates_count} يوم · {b.dates?.slice(0, 3).join('، ')}{b.dates_count > 3 ? '...' : ''}
                      </div>
                    </div>
                  </div>
                  <div className="flex gap-3 items-center">
                    <span className="px-2 py-0.5 text-xs bg-blue-50 text-blue-700 rounded-full font-mono">
                      {b.total_output_qty} كجم
                    </span>
                    <button onClick={(e) => { e.stopPropagation(); loadBatchForEdit(b.id); }}
                      className="px-2 py-1 text-xs text-blue-600 border border-blue-200 rounded-lg hover:bg-blue-50">
                      عرض
                    </button>
                    <button onClick={(e) => { e.stopPropagation(); if (confirm('حذف المعالجة وجميع أيامها؟')) deleteMutation.mutate(b.id); }}
                      className="px-2 py-1 text-xs text-red-500 border border-red-200 rounded-lg hover:bg-red-50">حذف</button>
                  </div>
                </div>

                {expandedBatches.has(b.id) && b.dates && (
                  <div className="border-t border-gray-100 bg-gray-50 px-4 py-2 space-y-1">
                    {b.dates.map((date: string) => (
                      <div key={date} className="text-sm text-gray-600 flex items-center gap-2">
                        <span className="text-gray-400">📅</span>
                        <span dir="ltr">{date}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </>)} {/* end batches tab */}

      {tab === 'summary' && (<>
        <div className="border border-gray-100 rounded-xl p-3 bg-white">
          <div className="flex items-center justify-between flex-wrap gap-2">
            <input type="month" value={summaryMonth} onChange={(e) => setSummaryMonth(e.target.value)}
              className="px-3 py-1.5 border border-gray-200 rounded-lg text-sm" />
            <div className="flex gap-2">
              <button onClick={() => {
                const a = document.createElement('a');
                a.href = `/api/production/processing/summary/export?month=${summaryMonth}`;
                a.download = `معمل_${summaryMonth}.xlsx`;
                a.click();
              }} className="px-3 py-1.5 text-xs border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50">
                تصدير إكسل
              </button>
              <button onClick={() => {
                if (!summary?.outputs?.length) { toast.error('لا توجد مخرجات للتحويل'); return; }
                const entries = summary.outputs.map((o: any) => ({ item_id: o.item_id, name: o.name, qty: o.total_qty, recipe_id: '', day: parseInt(today.getDate().toString()) }));
                setPostEntries(entries);
                setSelectedPostIds(new Set(entries.map(e => e.item_id)));
                setShowPostModal(true);
              }} className="px-3 py-1.5 text-xs bg-green-600 text-white rounded-lg hover:bg-green-700">
                تحويل للإنتاج اليومي
              </button>
            </div>
          </div>
        </div>

        {summaryLoading ? (
          <p className="text-gray-400 py-4">جاري التحميل...</p>
        ) : !summary?.inputs?.length && !summary?.outputs?.length ? (
          <p className="text-gray-400 py-8 text-center">لا توجد معالجات في هذا الشهر</p>
        ) : (
          <div className="grid grid-cols-1 gap-4">
            <div className="border border-gray-100 rounded-xl p-4 bg-white">
              <h3 className="font-semibold text-sm text-gray-700 mb-3">المدخلات (الخامات)</h3>
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                    <th className="px-3 py-2 font-normal">الصنف</th>
                    <th className="px-3 py-2 font-normal text-left">إجمالي الكمية</th>
                    <th className="px-3 py-2 font-normal text-left">متوسط سعر الكيلو</th>
                    <th className="px-3 py-2 font-normal text-left">إجمالي التكلفة</th>
                  </tr>
                </thead>
                <tbody>
                  {summary.inputs.map((i: any) => (
                    <tr key={i.item_id} className="border-t border-gray-50">
                      <td className="px-3 py-2 text-sm">{i.name}</td>
                      <td className="px-3 py-2 text-left tabular-nums">{i.total_qty.toFixed(2)} {i.unit}</td>
                      <td className="px-3 py-2 text-left tabular-nums text-blue-700">{i.avg_cost_per_kg.toFixed(2)} ج</td>
                      <td className="px-3 py-2 text-left tabular-nums font-medium">{i.total_cost.toFixed(2)} ج</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="bg-gray-50 border-t border-gray-200 font-medium text-sm">
                    <td className="px-3 py-2 text-gray-600">الإجمالي</td>
                    <td className="px-3 py-2 text-left tabular-nums">{summary.totals.total_input_qty.toFixed(2)}</td>
                    <td className="px-3 py-2 text-left tabular-nums">—</td>
                    <td className="px-3 py-2 text-left tabular-nums text-blue-700">{summary.totals.total_input_cost.toFixed(2)} ج</td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <div className="border border-gray-100 rounded-xl p-4 bg-white">
              <h3 className="font-semibold text-sm text-gray-700 mb-3">المخرجات (المنتج النهائي)</h3>
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 text-right text-gray-500 text-xs">
                    <th className="px-3 py-2 font-normal">الصنف</th>
                    <th className="px-3 py-2 font-normal text-left">إجمالي الكمية</th>
                    <th className="px-3 py-2 font-normal text-left">متوسط سعر الكيلو</th>
                    <th className="px-3 py-2 font-normal text-left">إجمالي التكلفة</th>
                    <th className="px-3 py-2 font-normal w-20"></th>
                  </tr>
                </thead>
                <tbody>
                  {summary.outputs.map((o: any) => (
                    <tr key={o.item_id} className="border-t border-gray-50">
                      <td className="px-3 py-2 text-sm">{o.name}</td>
                      <td className="px-3 py-2 text-left tabular-nums">{o.total_qty.toFixed(2)} {o.unit}</td>
                      <td className="px-3 py-2 text-left tabular-nums text-green-700">{o.avg_cost_per_kg.toFixed(2)} ج</td>
                      <td className="px-3 py-2 text-left tabular-nums font-medium">{o.total_cost.toFixed(2)} ج</td>
                      <td className="px-3 py-2">
                        <button onClick={() => syncItemCostMutation.mutate({ item_id: o.item_id, month: summaryMonth })}
                          disabled={syncItemCostMutation.isPending}
                          className="px-2 py-1 text-xs border border-amber-300 text-amber-700 rounded-lg hover:bg-amber-50 disabled:opacity-40 whitespace-nowrap">
                          تحديث السعر
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="bg-gray-50 border-t border-gray-200 font-medium text-sm">
                    <td className="px-3 py-2 text-gray-600">الإجمالي</td>
                    <td className="px-3 py-2 text-left tabular-nums">{summary.totals.total_output_qty.toFixed(2)}</td>
                    <td className="px-3 py-2 text-left tabular-nums">—</td>
                    <td className="px-3 py-2 text-left tabular-nums text-green-700">{summary.totals.total_output_cost.toFixed(2)} ج</td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        )}

        {showPostModal && (
          <div className="fixed inset-0 bg-black/30 z-50 flex items-center justify-center" onClick={() => setShowPostModal(false)}>
            <div className="bg-white rounded-xl shadow-xl p-5 w-full max-w-lg mx-4" onClick={(e) => e.stopPropagation()}>
              <h4 className="font-semibold text-sm mb-3">تحويل للإنتاج اليومي</h4>
              <p className="text-xs text-gray-500 mb-3">اختر الأصناف اللي عاوز ترحلها واربطها بوصفة:</p>
              <div className="space-y-3 max-h-64 overflow-auto mb-4">
                {postEntries.map((entry, idx) => (
                  <div key={entry.item_id} className="flex items-center gap-2 p-2 bg-gray-50 rounded-lg text-sm">
                    <input type="checkbox" checked={selectedPostIds.has(entry.item_id)}
                      onChange={() => { const next = new Set(selectedPostIds); if (next.has(entry.item_id)) next.delete(entry.item_id); else next.add(entry.item_id); setSelectedPostIds(next); }}
                      className="rounded border-gray-300" />
                    <span className={`w-24 truncate font-medium ${selectedPostIds.has(entry.item_id) ? 'text-gray-700' : 'text-gray-400 line-through'}`}>{entry.name}</span>
                    <span className="text-xs text-gray-400 w-16">{entry.qty.toFixed(2)} كجم</span>
                    <select value={entry.recipe_id} onChange={(e) => { const next = [...postEntries]; next[idx] = { ...next[idx], recipe_id: e.target.value }; setPostEntries(next); }}
                      className="flex-1 border border-gray-200 rounded-lg px-2 py-1 text-xs">
                      <option value="">اختر وصفة...</option>
                      {dailyRecipes?.recipes?.map((r: any) => <option key={r.id} value={r.id}>{r.name}</option>)}
                      <option value="">— بدون وصفة (الصنف نفسه)</option>
                    </select>
                    <input type="number" min={1} max={31} value={entry.day} onChange={(e) => { const next = [...postEntries]; next[idx] = { ...next[idx], day: parseInt(e.target.value) || 1 }; setPostEntries(next); }}
                      className="w-14 border border-gray-200 rounded-lg px-2 py-1 text-xs text-center" placeholder="اليوم" />
                  </div>
                ))}
              </div>
              <div className="flex justify-end gap-2 pt-3 border-t border-gray-100">
                <button onClick={() => setShowPostModal(false)} className="px-3 py-1.5 text-xs border border-gray-200 rounded-lg hover:bg-gray-50">إلغاء</button>
                <button onClick={() => {
                  const checked = postEntries.filter(e => selectedPostIds.has(e.item_id));
                  if (!checked.length) { toast.error('اختر صنف واحد على الأقل'); return; }
                  postToDailyMutation.mutate({ month: summaryMonth, entries: checked.map(e => ({ item_id: e.item_id, qty: e.qty, recipe_id: e.recipe_id || null, day: e.day })) });
                }} disabled={postToDailyMutation.isPending}
                  className="px-3 py-1.5 text-xs bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-40">
                  {postToDailyMutation.isPending ? 'جاري...' : `تحويل (${selectedPostIds.size})`}
                </button>
              </div>
            </div>
          </div>
        )}
      </>)} {/* end summary tab */}
    </div>
  );
}
