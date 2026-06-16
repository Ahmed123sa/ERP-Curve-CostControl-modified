'use client';
import { PageHeader } from '@/components/ui/AppShell';
import { VoucherGrid } from '@/components/grid/VoucherGrid';
import { useState } from 'react';
import { useAuthStore } from '@/lib/store';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import Link from 'next/link';
import toast from 'react-hot-toast';

const TYPE_LABELS: Record<string, string> = {
  purchase:      'وارد مخزن',
  dispatch:      'إذن صرف فرع',
  withdrawal:    'مسحوبات',
  external_sale: 'مبيعات خارجية',
  production:    'إنتاج',
  transfer:      'تحويل',
  opening:       'أول المدة',
  closing:       'آخر المدة',
  adjustment:    'تسوية',
  return:        'مرتجع',
  branch_return:   'مرتجع للمخزن',
  branch_transfer: 'تحويل فرع',
};

export default function PurchasePage() {
  const qc = useQueryClient();
  const { currentClient } = useAuthStore();
  const today = new Date().toISOString().split('T')[0];
  const [date, setDate] = useState(today);

  const { data: savedOrders = [], refetch: refetchSaved } = useQuery({
    queryKey: ['vouchers-saved', 'purchase', date],
    queryFn: () =>
      api.get('/vouchers', {
        params: {
          type: 'purchase',
          date_from: date,
          date_to: date,
          include_lines: 'true',
          per_page: 200,
        },
      }).then((r) => r.data?.data ?? []),
    enabled: !!date,
  });

  const hasSaved = savedOrders.length > 0;

  const handleDelete = async (id: string, label: string) => {
    if (!confirm(`⚠️ هل أنت متأكد من حذف "${label}"؟\nسيتم عكس تأثيره على المخزون تماماً.`)) return;
    try {
      await api.delete(`/vouchers/${id}`);
      toast.success('تم حذف الإذن وعكس حركات المخزون ✓');
      qc.invalidateQueries({ queryKey: ['vouchers'] });
      qc.invalidateQueries({ queryKey: ['stock'] });
      qc.invalidateQueries({ queryKey: ['closing'] });
      refetchSaved();
    } catch {
      toast.error('حدث خطأ أثناء الحذف');
    }
  };

  return (
    <>
      <PageHeader title="وارد مخزن (مشتريات)" subtitle={currentClient?.name ?? ''} />
      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        {/* Filters */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 flex gap-4 items-center shadow-sm">
          <div className="flex flex-col gap-1">
            <label className="text-xs font-medium text-gray-400">التاريخ</label>
            <input
              type="date"
              value={date}
              onChange={(e) => setDate(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
            />
          </div>
        </div>

        {/* Saved Orders */}
        {hasSaved && (
          <div className="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
            <div className="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
              <h3 className="text-sm font-semibold text-gray-700">
                📋 السجلات المحفوظة — {savedOrders.reduce((s: number, o: any) => s + (o.lines?.length || 0), 0)} صنف
              </h3>
              <span className="text-xs text-gray-400">تم الحفظ</span>
            </div>
            {savedOrders.map((order: any) => (
              <div key={order.id} className="border-b border-gray-100 last:border-0">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-right text-gray-500 text-xs bg-gray-50/50">
                      <th className="px-4 py-2 font-medium">#</th>
                      <th className="px-4 py-2 font-medium">الصنف</th>
                      <th className="px-4 py-2 font-medium">المخزن</th>
                      <th className="px-4 py-2 font-medium">الكمية</th>
                      <th className="px-4 py-2 font-medium">التكلفة</th>
                      <th className="px-4 py-2"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {(order.lines || []).map((line: any, li: number) => (
                      <tr key={line.id} className="border-t border-gray-50 hover:bg-gray-50/50">
                        <td className="px-4 py-2 text-gray-400 text-xs">{li + 1}</td>
                        <td className="px-4 py-2 font-medium text-gray-800">{line.item?.name || '—'}</td>
                        <td className="px-4 py-2 text-gray-500">{line.warehouse?.name || order.warehouse?.name || '—'}</td>
                        <td className="px-4 py-2 tabular-nums">{line.qty}</td>
                        <td className="px-4 py-2 tabular-nums">{line.total_cost?.toLocaleString('ar-EG') || '—'} ج</td>
                        <td className="px-4 py-2 text-left">
                          <div className="flex gap-1">
                            <Link
                              href={`/vouchers/${order.id}/edit`}
                              className="text-blue-400 hover:text-blue-600 hover:bg-blue-50 px-2 py-1 rounded text-xs transition-colors"
                            >✏️</Link>
                            <button
                              onClick={() => handleDelete(order.id, `${TYPE_LABELS[order.type] || 'إذن'} — ${order.date}`)}
                              className="text-red-400 hover:text-red-600 hover:bg-red-50 px-2 py-1 rounded text-xs transition-colors"
                            >🗑</button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="bg-gray-50/50 border-t border-gray-100">
                    <tr className="text-xs font-semibold text-gray-600">
                      <td className="px-4 py-2">{order.lines?.length || 0} صنف</td>
                      <td></td>
                      <td></td>
                      <td className="px-4 py-2 tabular-nums">
                        {(order.lines || []).reduce((s: number, l: any) => s + (parseFloat(l.qty) || 0), 0).toLocaleString('ar-EG')}
                      </td>
                      <td className="px-4 py-2 tabular-nums">
                        {(order.lines || []).reduce((s: number, l: any) => s + (parseFloat(l.total_cost) || 0), 0).toLocaleString('ar-EG')} ج
                      </td>
                      <td></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            ))}
          </div>
        )}

        {/* Entry Grid */}
        <div className="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-700 mb-3">إضافة أصناف جديدة</h3>
          <VoucherGrid
            type="purchase"
            date={date}
            onSaved={() => refetchSaved()}
          />
        </div>
      </div>
    </>
  );
}
