'use client';
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
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
  adjustment:    'تسوية',
  return:        'مرتجع',
};

const TYPE_COLORS: Record<string, string> = {
  purchase:      'bg-green-50 text-green-700',
  dispatch:      'bg-blue-50 text-blue-700',
  withdrawal:    'bg-orange-50 text-orange-700',
  production:    'bg-purple-50 text-purple-700',
  transfer:      'bg-yellow-50 text-yellow-700',
  opening:       'bg-gray-50 text-gray-700',
  adjustment:    'bg-amber-50 text-amber-700',
  return:        'bg-teal-50 text-teal-700',
};

export default function VoucherHistoryPage() {
  const qc = useQueryClient();
  const [filters, setFilters] = useState({ date_from: '', date_to: '', type: '' });
  const [selected, setSelected] = useState<Set<string>>(new Set());

  const { data: vouchers, isLoading } = useQuery({
    queryKey: ['vouchers', filters],
    queryFn: () => api.get('/vouchers', { params: filters }).then((r) => r.data),
  });

  const list = vouchers?.data ?? [];
  const total = vouchers?.total ?? 0;

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/vouchers/${id}`),
    onSuccess: () => {
      toast.success('تم حذف الإذن وعكس كافة حركات المخزون ✓');
      qc.invalidateQueries({ queryKey: ['vouchers'] });
      qc.invalidateQueries({ queryKey: ['stock'] });
      qc.invalidateQueries({ queryKey: ['closing'] });
    },
    onError: () => toast.error('حدث خطأ أثناء الحذف')
  });

  const batchDeleteMutation = useMutation({
    mutationFn: (ids: string[]) => Promise.all(ids.map(id => api.delete(`/vouchers/${id}`))),
    onSuccess: () => {
      toast.success('تم حذف الأذون المحددة بنجاح');
      setSelected(new Set());
      qc.invalidateQueries({ queryKey: ['vouchers'] });
      qc.invalidateQueries({ queryKey: ['stock'] });
      qc.invalidateQueries({ queryKey: ['closing'] });
    },
    onError: () => toast.error('خطأ في الحذف')
  });

  const handleDelete = (id: string, label: string) => {
    if (confirm(`⚠️ هل أنت متأكد من حذف "${label}"؟\nسيتم عكس تأثيره على المخزون تماماً.`)) {
      deleteMutation.mutate(id);
    }
  };

  const toggleSelect = (id: string) => {
    setSelected(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  const toggleAll = () => {
    if (selected.size === list.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(list.map((v: any) => v.id)));
    }
  };

  const handleDeleteSelected = () => {
    if (!selected.size) return;
    const openingCount = list.filter((v: any) => selected.has(v.id) && v.type === 'opening').length;
    let msg = `⚠️ سيتم حذف ${selected.size} إذن!\nهذا الإجراء لا يمكن التراجع عنه.`;
    if (openingCount > 0) {
      msg += `\n\n❗ تنبيه: يوجد ${openingCount} إذن افتتاحي (أول المدة) بين المحدد.\nحذفها سيؤدي إلى فقدان أرصدة أول المدة.\n`;
    }
    if (!confirm(msg)) return;
    batchDeleteMutation.mutate(Array.from(selected));
  };

  const hasSelected = selected.size > 0;
  const allSelected = list.length > 0 && selected.size === list.length;

  return (
    <>
      <PageHeader
        title="سجل الحركات"
        subtitle={`${total} إذن مسجل — يمكنك حذف أي إذن لعكس تأثيره على المخازن`}
        actions={
          <div className="flex gap-2">
            {hasSelected && (
              <button
                onClick={handleDeleteSelected}
                disabled={batchDeleteMutation.isPending}
                className="px-4 py-2 bg-red-50 text-red-600 rounded-lg text-sm font-medium hover:bg-red-100 border border-red-200"
              >
                🗑 حذف المحدد ({selected.size})
              </button>
            )}
            <button
              onClick={() => {
                const openingInList = list.filter((v: any) => v.type === 'opening').length;
                let msg = `⚠️ سيتم حذف ${list.length} إذن!\nهذا الإجراء لا يمكن التراجع عنه.`;
                if (openingInList > 0) {
                  msg += `\n\n❗ ${openingInList} إذن افتتاحي (أول المدة) سيتم حذفها أيضاً.\nاستخدم التحديد اليدوي لعزلها قبل الحذف.`;
                }
                if (!confirm(msg)) return;
                Promise.all(list.map((v: any) => api.delete(`/vouchers/${v.id}`))).then(() => {
                  toast.success(`تم حذف ${list.length} إذن بنجاح`);
                  qc.invalidateQueries({ queryKey: ['vouchers'] });
                  qc.invalidateQueries({ queryKey: ['stock'] });
                }).catch(() => toast.error('خطأ في الحذف الجماعي'));
              }}
              className="px-4 py-2 bg-red-50 text-red-600 rounded-lg text-sm font-medium hover:bg-red-100 border border-red-200"
            >
              🗑 حذف كل النتائج ({list.length})
            </button>
          </div>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        {/* Filters */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 flex gap-4 items-end shadow-sm flex-wrap">
          <div className="flex-1 min-w-36">
            <label className="text-[10px] text-gray-400 block mb-1 font-medium">من تاريخ</label>
            <input
              type="date"
              value={filters.date_from}
              onChange={(e) => setFilters({ ...filters, date_from: e.target.value })}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
            />
          </div>
          <div className="flex-1 min-w-36">
            <label className="text-[10px] text-gray-400 block mb-1 font-medium">إلى تاريخ</label>
            <input
              type="date"
              value={filters.date_to}
              onChange={(e) => setFilters({ ...filters, date_to: e.target.value })}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
            />
          </div>
          <div className="flex-1 min-w-36">
            <label className="text-[10px] text-gray-400 block mb-1 font-medium">نوع الإذن</label>
            <select
              value={filters.type}
              onChange={(e) => setFilters({ ...filters, type: e.target.value })}
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
            >
              <option value="">كل الأنواع</option>
              {Object.entries(TYPE_LABELS).map(([k, v]) => (
                <option key={k} value={k}>{v}</option>
              ))}
            </select>
          </div>
          <button
            onClick={() => setFilters({ date_from: '', date_to: '', type: '' })}
            className="py-2 px-4 bg-gray-50 text-gray-500 rounded-lg text-sm hover:bg-gray-100 border border-gray-200"
          >
            مسح الفلاتر
          </button>
        </div>

        {/* Table */}
        <div className="bg-white border border-gray-100 rounded-xl overflow-hidden shadow-sm">
          <table className="w-full text-sm text-right" dir="rtl">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr className="text-gray-500 text-xs">
                <th className="px-2 py-3 w-8">
                  <input
                    type="checkbox"
                    checked={allSelected}
                    onChange={toggleAll}
                    className="rounded border-gray-300"
                  />
                </th>
                <th className="px-4 py-3 font-semibold">التاريخ</th>
                <th className="px-4 py-3 font-semibold">النوع</th>
                <th className="px-4 py-3 font-semibold">المصدر</th>
                <th className="px-4 py-3 font-semibold">الموقع</th>
                <th className="px-4 py-3 font-semibold">عدد الأصناف</th>
                <th className="px-4 py-3 font-semibold">بواسطة</th>
                <th className="px-4 py-3 text-center font-semibold">إجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {isLoading ? (
                <tr>
                  <td colSpan={8} className="px-6 py-12 text-center text-gray-400">
                    <div className="animate-pulse">جاري التحميل...</div>
                  </td>
                </tr>
              ) : list.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-6 py-16 text-center">
                    <div className="text-4xl mb-3">📭</div>
                    <p className="text-gray-400">لا توجد حركات مسجلة</p>
                  </td>
                </tr>
              ) : list.map((v: any) => (
                <tr key={v.id} className={`hover:bg-gray-50 transition-colors ${selected.has(v.id) ? 'bg-blue-50/50' : ''}`}>
                  <td className="px-2 py-3">
                    <input
                      type="checkbox"
                      checked={selected.has(v.id)}
                      onChange={() => toggleSelect(v.id)}
                      className="rounded border-gray-300"
                    />
                  </td>
                  <td className="px-4 py-3 font-medium text-gray-700">
                    {(() => { const [y,m,d] = (v.date||'').split('T')[0].split('-'); return d&&m&&y ? `${d}/${m}/${y}` : v.date; })()}
                  </td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-1 rounded-full text-[10px] font-bold ${TYPE_COLORS[v.type] ?? 'bg-gray-50 text-gray-600'}`}>
                      {TYPE_LABELS[v.type] ?? v.type}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    {v.source === 'upload' ? (
                      <span className="text-purple-600 bg-purple-50 px-2 py-0.5 rounded-full text-[10px] font-medium">📤 رفع</span>
                    ) : (
                      <span className="text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full text-[10px] font-medium">📝 يدوي</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-gray-600 text-sm">
                    {v.type === 'dispatch'
                      ? (v.branch?.name ?? v.warehouse?.name ?? '—')
                      : (v.warehouse?.name ?? '—')}
                  </td>
                  <td className="px-4 py-3">
                    <span className="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs">
                      {v.lines_count ?? 0} صنف
                    </span>
                  </td>
                  <td className="px-4 py-3 text-xs text-gray-400">
                    {v.creator?.name ?? 'آلي (Excel)'}
                  </td>
                  <td className="px-4 py-3 text-center">
                    <div className="flex items-center justify-center gap-2">
                      <Link
                        href={`/vouchers/${v.id}/edit`}
                        className="text-blue-400 hover:text-blue-600 hover:bg-blue-50 px-3 py-1 rounded-lg text-xs font-medium transition-colors inline-block"
                      >
                        ✏️ تعديل
                      </Link>
                      <button
                        onClick={() => handleDelete(v.id, `${TYPE_LABELS[v.type] ?? v.type} — ${(v.date||'').split('T')[0]}`)}
                        disabled={deleteMutation.isPending}
                        className="text-red-400 hover:text-red-600 hover:bg-red-50 px-3 py-1 rounded-lg text-xs font-medium transition-colors disabled:opacity-40"
                      >
                        🗑 حذف
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
