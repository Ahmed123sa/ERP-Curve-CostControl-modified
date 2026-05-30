'use client';
import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import { PageHeader } from '@/components/ui/AppShell';
import { InventoryUploadModal } from '@/components/stock/InventoryUploadModal';
import toast from 'react-hot-toast';

export default function FinalBalancePage() {
  const qc = useQueryClient();
  const [warehouseId, setWarehouseId] = useState('');
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [lines, setLines] = useState<any[]>([]);
  const { currentClient } = useAuthStore();

  // 1. جلب المخازن والأصناف
  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const { data: items = [] } = useQuery({
    queryKey: ['items', currentClient?.id],
    queryFn: () => api.get('/items').then((r) => r.data),
  });

  // 2. جلب الجرد المخزن مسبقاً (لو موجود)
  const { data: existingClosings = [], isLoading: isLoadingExisting, isFetching: isFetchingExisting } = useQuery({
    queryKey: ['closing-actual', warehouseId, month],
    queryFn: () => api.get('/closing', { params: { warehouse_id: warehouseId, month } }).then((r) => r.data.data),
    enabled: !!warehouseId && !!month,
  });

  // تحميل تلقائي للأصناف ودمج البيانات المخزنة - مرة واحدة عند تغيير الاختيارات
  useEffect(() => {
    // بمجرد تغيير المخزن أو الشهر، نصفر القائمة لحين التحميل
    setLines([]); 
  }, [warehouseId, month]);

  useEffect(() => {
    if (warehouseId && items.length > 0 && !isLoadingExisting) {
      const existingMap = new Map(existingClosings.map((c: any) => [c.item_id, c.physical_count]));
      
      const allLines = items.map((item: any) => ({
        item_id: item.id,
        item_name: item.name,
        unit: item.unit || '',
        qty: existingMap.get(item.id) ?? 0,
      }));
      setLines(allLines);
    }
  }, [warehouseId, month, items.length, isLoadingExisting]);

  const updateLine = (idx: number, field: string, value: any) => {
    setLines(lines.map((l, i) => i === idx ? { ...l, [field]: value } : l));
  };

  const submitMutation = useMutation({
    mutationFn: () => {
      const validLines = lines.filter(l => l.item_id);
      return api.post('/closing/bulk-actual', {
        warehouse_id: warehouseId,
        month: month,
        lines: validLines.map(l => ({
          item_id: l.item_id,
          qty: l.qty,
        })),
      });
    },
    onSuccess: () => {
      toast.success('تم حفظ جرد آخر المدة بنجاح ✓');
      qc.invalidateQueries({ queryKey: ['closing-actual', warehouseId, month] });
      qc.invalidateQueries({ queryKey: ['closing'] });
    },
    onError: (e: any) => toast.error(e?.message || 'خطأ في الحفظ')
  });

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="جرد آخر المدة (الفعلي)"
        subtitle="إدخال الكميات الفعلية الموجودة في المخزن في نهاية الشهر"
        actions={
          <div className="flex gap-2">
            <InventoryUploadModal 
              warehouseId={warehouseId} 
              month={month} 
              type="final" 
              onSuccess={() => {
                qc.invalidateQueries({ queryKey: ['closing-actual', warehouseId, month] });
                qc.invalidateQueries({ queryKey: ['closing'] });
              }} 
            />
            <button
              onClick={() => submitMutation.mutate()}
              disabled={!warehouseId || lines.length === 0 || submitMutation.isPending}
              className="px-6 py-2.5 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-40"
            >
              {submitMutation.isPending ? 'جاري الحفظ...' : `حفظ الجرد الفعلي (${lines.length} صنف)`}
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
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 outline-none"
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
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 outline-none"
              />
            </div>
          </div>
        </div>

        {/* Table */}
        <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
          <table className="w-full text-sm text-right">
            <thead className="bg-gray-50 border-b border-gray-100 text-gray-600">
              <tr>
                <th className="p-3 font-medium">الصنف</th>
                <th className="p-3 font-medium text-center">الوحدة</th>
                <th className="p-3 font-medium text-center w-40">الكمية الفعلية (الجرد)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {lines.map((line, idx) => (
                <tr key={idx} className="hover:bg-gray-50/50">
                  <td className="p-3 font-medium text-gray-900">{line.item_name}</td>
                  <td className="p-3 text-center text-gray-500">{line.unit}</td>
                  <td className="p-3">
                    <input
                      type="number"
                      value={line.qty}
                      onChange={(e) => updateLine(idx, 'qty', parseFloat(e.target.value) || 0)}
                      onFocus={(e) => e.target.select()}
                      className="w-full border border-gray-200 rounded px-2 py-1 text-center focus:ring-1 focus:ring-green-500 outline-none font-bold text-green-700"
                    />
                  </td>
                </tr>
              ))}
              {lines.length === 0 && (
                <tr>
                  <td colSpan={3} className="p-8 text-center text-gray-400 italic">
                    اختر المخزن لعرض الأصناف...
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
