'use client';
import { useParams, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { VoucherGrid } from '@/components/grid/VoucherGrid';
import { useState, useCallback } from 'react';
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

export default function EditVoucherPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();

  const { data: voucher, isLoading } = useQuery({
    queryKey: ['voucher', id],
    queryFn: () => api.get(`/vouchers/${id}`).then((r) => r.data),
  });

  const [navigating, setNavigating] = useState(false);

  const handleDateChange = useCallback(async (newDate: string) => {
    if (!voucher || !newDate) return;
    setNavigating(true);
    try {
      const { data } = await api.get('/vouchers', {
        params: { date_from: newDate, date_to: newDate, type: voucher.type, per_page: 1 },
      });
      const target = data.data?.[0];
      if (target && target.id !== id) {
        router.push(`/vouchers/${target.id}/edit`);
      } else if (target && target.id === id) {
        // نفس الإذن — ما فيش داعي للتنقل
        setNavigating(false);
      } else {
        toast.error(`لا يوجد إذن ${TYPE_LABELS[voucher.type] ?? voucher.type} في هذا التاريخ`);
        setNavigating(false);
      }
    } catch {
      toast.error('حدث خطأ أثناء البحث');
      setNavigating(false);
    }
  }, [voucher, id, router]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64 text-gray-400">
        جاري تحميل بيانات الإذن...
      </div>
    );
  }

  if (!voucher) {
    return (
      <div className="flex items-center justify-center h-64 text-red-400">
        لم يتم العثور على الإذن
      </div>
    );
  }

  if (voucher.type === 'closing') {
    return (
      <div className="flex items-center justify-center h-64 text-amber-600 flex-col gap-4">
        <span className="text-4xl">🔒</span>
        <span>لا يمكن تعديل إذن آخر المدة</span>
        <a href="/vouchers/history" className="text-blue-500 underline text-sm">العودة للسجل</a>
      </div>
    );
  }

  const displayDate = (voucher.date || '').split('T')[0];
  const initialRows = (voucher.lines ?? []).map((line: any) => ({
    id:             Math.random().toString(36).slice(2),
    item_id:        line.item_id,
    item_name:      line.item?.name ?? '',
    warehouse_id:   line.warehouse_id,
    warehouse_name: line.warehouse?.name ?? '',
    qty:            String(line.qty ?? ''),
    cost:           String(line.total_cost ?? ''),
    unit_cost:      line.unit_cost ?? 0,
  }));

  const handleSaved = () => {
    router.push('/vouchers/history');
  };

  return (
    <>
      <div className="px-6 pt-4">
        <button
          onClick={() => router.back()}
          className="flex items-center gap-1 text-sm text-gray-400 hover:text-gray-600 transition-colors"
        >
          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
          </svg>
          رجوع للسجل
        </button>
      </div>
      <PageHeader
        title={`تعديل ${TYPE_LABELS[voucher.type] ?? 'إذن'}`}
        subtitle={`#${voucher.id} — ${displayDate}`}
      />
      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        <div className="bg-white border border-gray-100 rounded-xl p-4 flex gap-4 items-center">
          <div className="flex flex-col gap-1">
            <label className="text-xs font-medium text-gray-400">التاريخ</label>
            <div className="flex items-center gap-2">
              <input
                type="date"
                value={displayDate}
                onChange={(e) => handleDateChange(e.target.value)}
                className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
              {navigating && (
                <span className="text-xs text-blue-500 animate-pulse">جاري التحميل...</span>
              )}
            </div>
          </div>
          <div className="flex flex-col gap-1">
            <label className="text-xs font-medium text-gray-400">النوع</label>
            <span className="px-3 py-2 text-sm font-medium text-gray-700">
              {TYPE_LABELS[voucher.type] ?? voucher.type}
            </span>
          </div>
          {voucher.branch && (
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-gray-400">الفرع</label>
              <span className="px-3 py-2 text-sm text-gray-600">{voucher.branch.name}</span>
            </div>
          )}
          {voucher.warehouse && !voucher.branch && (
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-gray-400">المخزن</label>
              <span className="px-3 py-2 text-sm text-gray-600">{voucher.warehouse.name}</span>
            </div>
          )}
        </div>
        <VoucherGrid
          type={voucher.type}
          date={displayDate}
          warehouseId={voucher.warehouse_id}
          branchId={voucher.branch_id}
          orderId={id}
          initialData={initialRows}
          onSaved={handleSaved}
        />
      </div>
    </>
  );
}
