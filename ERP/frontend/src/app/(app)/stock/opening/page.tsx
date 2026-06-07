'use client';
import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import { PageHeader } from '@/components/ui/AppShell';
import { InventoryUploadModal } from '@/components/stock/InventoryUploadModal';
import toast from 'react-hot-toast';

export default function OpeningBalancePage() {
  const qc = useQueryClient();
  const [warehouseId, setWarehouseId] = useState('');
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [lines, setLines] = useState<{ item_id: string; item_name: string; unit: string; qty: number; cost: number; unit_price: number }[]>([]);
  const { currentClient } = useAuthStore();

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const { data: items = [] } = useQuery({
    queryKey: ['items', currentClient?.id],
    queryFn: () => api.get('/items').then((r) => r.data),
  });

  const { data: existingOpening = [], isFetching: isFetchingExisting } = useQuery({
    queryKey: ['opening-prefill', warehouseId, month],
    queryFn: () => api.get('/stock/opening', { params: { warehouse_id: warehouseId, month } }).then((r) => r.data.data),
    enabled: !!warehouseId && !!month,
  });

  useEffect(() => {
    setLines([]);
  }, [warehouseId, month]);

  useEffect(() => {
    if (warehouseId && items.length > 0 && !isFetchingExisting) {
      const existingByItem = new Map(existingOpening.map((r: any) => [r.item_id, r]));
      const allLines = items.map((i: any) => {
        const ex = existingByItem.get(i.id) as any;
        const unitPrice = Number(i.default_cost || 0);
        const existingQty = ex ? Number(ex.qty || 0) : 0;
        return {
          item_id: i.id,
          item_name: i.name,
          unit: i.unit || '',
          qty: existingQty,
          cost: existingQty > 0 ? existingQty * unitPrice : 0,
          unit_price: unitPrice,
        };
      });
      setLines(allLines);
    }
  }, [warehouseId, month, items.length, existingOpening, isFetchingExisting]);

  const addLine = () => {
    setLines([...lines, { item_id: '', item_name: '', unit: '', qty: 0, cost: 0, unit_price: 0 }]);
  };

  const removeLine = (idx: number) => {
    setLines(lines.filter((_, i) => i !== idx));
  };

  const round2 = (n: number) => Math.round(n * 100) / 100;

  const updateLine = (idx: number, field: string, value: any) => {
    setLines(lines.map((l, i) => {
      if (i !== idx) return l;
      const updated = { ...l, [field]: value };
      if (field === 'qty') {
        updated.cost = round2(updated.qty * updated.unit_price);
      }
      return updated;
    }));
  };

  const setItemFromSelect = (idx: number, itemId: string) => {
    const item = items.find((i: any) => i.id === itemId);
    if (item) {
      const unitPrice = Number(item.default_cost || 0);
      updateLine(idx, 'item_id', item.id);
      updateLine(idx, 'item_name', item.name);
      updateLine(idx, 'unit', item.unit || '');
      updateLine(idx, 'unit_price', unitPrice);
    }
  };

  const submitMutation = useMutation({
    mutationFn: () => {
      const validLines = lines.filter(l => l.item_id);
      if (!validLines.length) throw new Error('لا توجد أصناف مدخلة');
      return api.post('/vouchers/manual', {
        type: 'opening',
        date: month + '-01',
        warehouse_id: warehouseId,
        lines: validLines.map(l => ({
          item_id: l.item_id,
          warehouse_id: warehouseId,
          qty: l.qty,
          cost: l.cost,
        })),
      });
    },
    onSuccess: () => {
      toast.success('تم حفظ الأرصدة الافتتاحية بنجاح ✓');
      qc.invalidateQueries({ queryKey: ['opening-prefill', warehouseId, month] });
    },
    onError: (e: any) => toast.error(e?.message || 'خطأ في الحفظ')
  });

  const totalValue = lines.reduce((s, l) => s + l.cost, 0);

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="رصيد أول المدة"
        subtitle="إدخال الأرصدة الافتتاحية للمخازن — جرد بداية التشغيل"
        actions={
          <div className="flex gap-2">
            <InventoryUploadModal 
              warehouseId={warehouseId} 
              month={month} 
              type="opening" 
              onSuccess={() => {
                qc.invalidateQueries({ queryKey: ['opening-prefill', warehouseId, month] });
              }} 
            />
            <button
              onClick={() => submitMutation.mutate()}
              disabled={!warehouseId || lines.length === 0 || submitMutation.isPending}
              className="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-40"
            >
              {submitMutation.isPending ? 'جاري الحفظ...' : `حفظ (${lines.length} صنف — ${totalValue.toLocaleString()} ج)`}
            </button>
          </div>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        {/* Controls */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
          <div className="grid grid-cols-2 gap-4 items-end">
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">المخزن *</label>
              <select
                value={warehouseId}
                onChange={(e) => setWarehouseId(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              >
                <option value="">اختر المخزن...</option>
                {warehouses.map((w: any) => <option key={w.id} value={w.id}>{w.name}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">الشهر</label>
              <input
                type="month"
                value={month}
                onChange={(e) => setMonth(e.target.value)}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
            </div>
          </div>
        </div>

        {/* Table */}
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <table className="w-full text-sm text-right" dir="rtl">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr className="text-gray-500 text-xs">
                <th className="px-4 py-3 font-semibold">الصنف</th>
                <th className="px-4 py-3 font-semibold w-20">الوحدة</th>
                <th className="px-4 py-3 font-semibold w-36">الكمية</th>
                <th className="px-4 py-3 font-semibold w-40">إجمالي التكلفة (ج)</th>
                <th className="px-4 py-3 font-semibold w-32 text-gray-400">سعر الوحدة</th>
                <th className="px-4 py-3 w-10"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {lines.map((line, idx) => (
                <tr key={idx} className={`hover:bg-blue-50/20 ${line.qty > 0 ? 'bg-green-50/10' : ''}`}>
                  <td className="px-4 py-2">
                    {line.item_name ? (
                      <span className="font-medium text-gray-800">{line.item_name}</span>
                    ) : (
                      <select
                        value={line.item_id}
                        onChange={(e) => setItemFromSelect(idx, e.target.value)}
                        className="w-full border border-gray-200 rounded-lg px-2 py-1 text-sm"
                      >
                        <option value="">اختر الصنف...</option>
                        {items.map((i: any) => <option key={i.id} value={i.id}>{i.name}</option>)}
                      </select>
                    )}
                  </td>
                  <td className="px-4 py-2 text-gray-500">{line.unit}</td>
                  <td className="px-4 py-2">
                    <input
                      type="number"
                      value={line.qty || ''}
                      onChange={(e) => updateLine(idx, 'qty', parseFloat(e.target.value) || 0)}
                      placeholder="0"
                      className="w-full border border-gray-200 rounded-lg px-2 py-1 text-sm text-left font-bold text-blue-700 focus:ring-2 focus:ring-blue-300 outline-none"
                    />
                  </td>
                  <td className="px-4 py-2">
                    <input
                      type="number"
                      value={line.cost || ''}
                      readOnly
                      placeholder="0"
                      className="w-full border border-gray-100 bg-gray-50 rounded-lg px-2 py-1 text-sm text-left text-gray-600 outline-none cursor-default"
                    />
                  </td>
                  <td className="px-4 py-2 text-gray-600 text-xs font-mono font-medium">
                    {line.unit_price > 0 ? line.unit_price.toFixed(2) : '—'}
                  </td>
                  <td className="px-4 py-2 text-center">
                    <button onClick={() => removeLine(idx)} className="text-gray-300 hover:text-red-500 text-sm">✕</button>
                  </td>
                </tr>
              ))}
              {lines.length === 0 && (
                <tr>
                  <td colSpan={6} className="p-8 text-center text-gray-400 italic">
                    اختر المخزن لعرض الأصناف...
                  </td>
                </tr>
              )}
            </tbody>
            <tfoot className="bg-gray-50 border-t border-gray-100">
              <tr>
                <td colSpan={2} className="px-4 py-3 text-sm text-gray-500">
                  {lines.length} صنف
                </td>
                <td colSpan={4} className="px-4 py-3 text-sm font-bold text-gray-800">
                  إجمالي قيمة أول المدة: {totalValue.toLocaleString()} ج
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        {/* زر إضافة صنف في الأسفل */}
        <div className="flex gap-2">
          <button
            onClick={addLine}
            className="px-4 py-2 bg-gray-50 text-gray-700 rounded-lg text-sm hover:bg-gray-100 border border-gray-200"
          >
            + إضافة صنف يدوي
          </button>
        </div>
      </div>
    </div>
  );
}