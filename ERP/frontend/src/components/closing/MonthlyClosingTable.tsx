'use client';
// components/closing/MonthlyClosingTable.tsx
// تقفيل الشهر — نفس شيت "تقفيل خامات جديد" بالظبط

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';
import clsx from 'clsx';

interface ClosingRow {
  id: string;
  item_name: string;
  unit: string;
  opening_qty: number;
  opening_value: number;
  in_qty: number;
  in_value: number;
  out_qty: number;
  avg_cost: number;
  closing_qty_theoretical: number;
  closing_qty_actual: number | null;
  closing_value: number;
  diff_qty: number;
  diff_value: number;
  is_locked: boolean;
}

interface Props {
  clientId: string;
  warehouseId: string;
  month: string; // 2024-04
}

export function MonthlyClosingTable({ clientId, warehouseId, month }: Props) {
  const qc = useQueryClient();
  const [editingActual, setEditingActual] = useState<Record<string, string>>({});
  const [isLocking, setIsLocking] = useState(false);

  // جيب بيانات التقفيل
  const { data, isLoading } = useQuery({
    queryKey: ['closing', clientId, warehouseId, month],
    queryFn: () =>
      api.get('/closing', { params: { warehouse_id: warehouseId, month } })
         .then((r) => r.data),
  });

  // توليد التقفيل
  const generateMutation = useMutation({
    mutationFn: () => api.post('/closing/generate', { warehouse_id: warehouseId, month }),
    onSuccess: () => {
      toast.success('تم توليد التقفيل');
      qc.invalidateQueries({ queryKey: ['closing'] });
    },
  });

  // تحديث جرد فعلي
  const updateActualMutation = useMutation({
    mutationFn: ({ closingId, actual }: { closingId: string; actual: number }) =>
      api.patch(`/closing/${closingId}/actual`, { closing_qty_actual: actual }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['closing'] });
    },
  });

  // إقفال الشهر
  const lockMutation = useMutation({
    mutationFn: () => api.post('/closing/lock', { warehouse_id: warehouseId, month }),
    onSuccess: () => {
      toast.success('تم إقفال الشهر ✓');
      qc.invalidateQueries({ queryKey: ['closing'] });
      setIsLocking(false);
    },
  });

  // تصدير Excel
  const exportClosing = () => {
    window.open(
      `${process.env.NEXT_PUBLIC_API_URL}/closing/export?warehouse_id=${warehouseId}&month=${month}`,
      '_blank',
    );
  };

  const rows: ClosingRow[] = data?.data ?? [];
  const isLocked = rows.some((r) => r.is_locked);

  // مجاميع
  const totals = rows.reduce(
    (acc, r) => ({
      opening_value: acc.opening_value + r.opening_value,
      in_value:      acc.in_value + r.in_value,
      closing_value: acc.closing_value + r.closing_value,
      diff_value:    acc.diff_value + r.diff_value,
    }),
    { opening_value: 0, in_value: 0, closing_value: 0, diff_value: 0 },
  );

  return (
    <div className="space-y-4" dir="rtl">
      {/* Toolbar */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <h3 className="font-semibold text-gray-800">تقفيل {month}</h3>
          {isLocked && (
            <span className="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded-full">
              مقفول ✓
            </span>
          )}
        </div>
        <div className="flex gap-2">
          {!isLocked && (
            <button
              onClick={() => generateMutation.mutate()}
              disabled={generateMutation.isPending}
              className="px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50"
            >
              {generateMutation.isPending ? 'جاري التوليد...' : '↻ تحديث'}
            </button>
          )}
          <button
            onClick={exportClosing}
            className="px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50"
          >
            ↓ Excel
          </button>
          {!isLocked && rows.length > 0 && (
            <button
              onClick={() => {
                if (window.confirm('هل تأكد من إقفال الشهر؟ مش هينعدل بعد كده')) {
                  lockMutation.mutate();
                }
              }}
              className="px-3 py-1.5 text-sm bg-gray-800 text-white rounded-lg hover:bg-gray-900"
            >
              🔒 إقفال الشهر
            </button>
          )}
        </div>
      </div>

      {/* الجدول */}
      {isLoading ? (
        <div className="text-center py-12 text-gray-400 text-sm">جاري التحميل...</div>
      ) : rows.length === 0 ? (
        <div className="text-center py-12">
          <p className="text-gray-400 text-sm mb-3">مفيش بيانات تقفيل للشهر ده</p>
          <button
            onClick={() => generateMutation.mutate()}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg"
          >
            توليد التقفيل
          </button>
        </div>
      ) : (
        <div className="border border-gray-100 rounded-xl overflow-x-auto">
          <table className="w-full text-sm min-w-[900px]">
            <thead className="sticky top-0 z-10 bg-gray-50 text-xs text-gray-500">
              <tr>
                <th className="px-3 py-2.5 text-right font-medium sticky right-0 bg-gray-50 z-10">الصنف</th>
                <th className="px-3 py-2.5 font-medium">الوحدة</th>
                <th className="px-3 py-2.5 font-medium" colSpan={2}>أول المدة</th>
                <th className="px-3 py-2.5 font-medium" colSpan={2}>الوارد</th>
                <th className="px-3 py-2.5 font-medium">المنصرف</th>
                <th className="px-3 py-2.5 font-medium">متوسط السعر</th>
                <th className="px-3 py-2.5 font-medium">آخر المدة (نظري)</th>
                <th className="px-3 py-2.5 font-medium">آخر المدة (فعلي)</th>
                <th className="px-3 py-2.5 font-medium">الفرق كمية</th>
                <th className="px-3 py-2.5 font-medium">قيمة الفرق</th>
              </tr>
              <tr className="text-gray-400 text-xs border-t border-gray-100">
                <th className="sticky right-0 bg-gray-50 z-10"></th>
                <th></th>
                <th className="px-3 py-1">كمية</th><th className="px-3 py-1">قيمة</th>
                <th className="px-3 py-1">كمية</th><th className="px-3 py-1">قيمة</th>
                <th className="px-3 py-1">كمية</th>
                <th></th>
                <th></th><th></th><th></th><th></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const diffSign  = row.diff_qty !== 0 ? (row.diff_qty > 0 ? 'neg' : 'pos') : 'zero';
                const actualKey = `actual-${row.id}`;

                return (
                  <tr key={row.id} className="border-t border-gray-50 hover:bg-gray-50/50">
                    <td className="px-3 py-2 font-medium text-gray-800 sticky right-0 bg-white z-10">
                      {row.item_name}
                    </td>
                    <td className="px-3 py-2 text-gray-500">{row.unit}</td>

                    {/* أول المدة */}
                    <td className="px-3 py-2 tabular-nums">{row.opening_qty.toLocaleString()}</td>
                    <td className="px-3 py-2 tabular-nums text-gray-500">
                      {row.opening_value.toLocaleString('ar-EG', { maximumFractionDigits: 0 })}
                    </td>

                    {/* الوارد */}
                    <td className="px-3 py-2 tabular-nums">{row.in_qty.toLocaleString()}</td>
                    <td className="px-3 py-2 tabular-nums text-gray-500">
                      {row.in_value.toLocaleString('ar-EG', { maximumFractionDigits: 0 })}
                    </td>

                    {/* المنصرف */}
                    <td className="px-3 py-2 tabular-nums">{row.out_qty.toLocaleString()}</td>

                    {/* متوسط السعر */}
                    <td className="px-3 py-2 tabular-nums text-gray-600">
                      {row.avg_cost.toFixed(2)}
                    </td>

                    {/* آخر المدة نظري */}
                    <td className="px-3 py-2 tabular-nums">{row.closing_qty_theoretical.toLocaleString()}</td>

                    {/* آخر المدة فعلي — قابل للتعديل */}
                    <td className="px-1 py-1">
                      {isLocked ? (
                        <span className="px-2 tabular-nums">{row.closing_qty_actual ?? '—'}</span>
                      ) : (
                        <input
                          type="number"
                          value={editingActual[actualKey] ?? (row.closing_qty_actual ?? '')}
                          onChange={(e) => setEditingActual((p) => ({ ...p, [actualKey]: e.target.value }))}
                          onBlur={() => {
                            const v = parseFloat(editingActual[actualKey] ?? '');
                            if (!isNaN(v) && v !== row.closing_qty_actual) {
                              updateActualMutation.mutate({ closingId: row.id, actual: v });
                            }
                          }}
                          placeholder="أدخل جرد فعلي"
                          className="w-28 px-2 py-1 text-sm border border-transparent rounded
                                     focus:border-blue-300 focus:outline-none bg-transparent"
                        />
                      )}
                    </td>

                    {/* الفرق */}
                    <td className={clsx('px-3 py-2 tabular-nums font-medium', {
                      'text-red-600': diffSign === 'neg',
                      'text-green-600': diffSign === 'pos',
                      'text-gray-400': diffSign === 'zero',
                    })}>
                      {row.diff_qty !== 0 ? (row.diff_qty > 0 ? '+' : '') + row.diff_qty.toFixed(2) : '—'}
                    </td>

                    {/* قيمة الفرق */}
                    <td className={clsx('px-3 py-2 tabular-nums font-medium', {
                      'text-red-600': row.diff_value < 0,
                      'text-green-600': row.diff_value > 0,
                      'text-gray-400': row.diff_value === 0,
                    })}>
                      {row.diff_value !== 0
                        ? (row.diff_value > 0 ? '+' : '') +
                          row.diff_value.toLocaleString('ar-EG', { maximumFractionDigits: 0 }) + ' ج'
                        : '—'}
                    </td>
                  </tr>
                );
              })}
            </tbody>

            {/* المجاميع */}
            <tfoot className="bg-gray-50 border-t-2 border-gray-200 font-semibold text-sm">
              <tr>
                <td className="px-3 py-3 sticky right-0 bg-gray-50 z-10">الإجمالي</td>
                <td></td>
                <td></td>
                <td className="px-3 py-3 tabular-nums">
                  {totals.opening_value.toLocaleString('ar-EG', { maximumFractionDigits: 0 })} ج
                </td>
                <td></td>
                <td className="px-3 py-3 tabular-nums">
                  {totals.in_value.toLocaleString('ar-EG', { maximumFractionDigits: 0 })} ج
                </td>
                <td></td><td></td><td></td><td></td><td></td>
                <td className={clsx('px-3 py-3 tabular-nums', {
                  'text-red-600': totals.diff_value < 0,
                  'text-green-600': totals.diff_value > 0,
                })}>
                  {totals.diff_value !== 0
                    ? (totals.diff_value > 0 ? '+' : '') +
                      totals.diff_value.toLocaleString('ar-EG', { maximumFractionDigits: 0 }) + ' ج'
                    : '—'}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </div>
  );
}
