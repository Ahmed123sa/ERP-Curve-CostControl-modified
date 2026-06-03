'use client';
import { useState, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useSearchParams, useRouter } from 'next/navigation';
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
  closing:       'آخر المدة',
  adjustment:    'تسوية',
  return:        'مرتجع',
};

const TYPE_COLORS: Record<string, string> = {
  purchase:      'bg-green-50 text-green-700 border border-green-200',
  dispatch:      'bg-blue-50 text-blue-700 border border-blue-200',
  withdrawal:    'bg-orange-50 text-orange-700 border border-orange-200',
  production:    'bg-purple-50 text-purple-700 border border-purple-200',
  transfer:      'bg-yellow-50 text-yellow-700 border border-yellow-200',
  opening:       'bg-gray-50 text-gray-700 border border-gray-200',
  closing:       'bg-rose-50 text-rose-700 border border-rose-200',
  adjustment:    'bg-amber-50 text-amber-700 border border-amber-200',
  return:        'bg-teal-50 text-teal-700 border border-teal-200',
};

const MONTHS = [
  'يناير', 'فبراير', 'مارس', 'إبريل',
  'مايو', 'يونيو', 'يوليو', 'أغسطس',
  'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر',
];

export default function VoucherHistoryPage() {
  const qc = useQueryClient();
  const router = useRouter();
  const searchParams = useSearchParams();

  const month    = searchParams.get('month') ?? '';
  const dateFrom = searchParams.get('date_from') ?? '';
  const dateTo   = searchParams.get('date_to') ?? '';
  const type     = searchParams.get('type') ?? '';

  const [selected, setSelected] = useState<Set<string>>(new Set());

  const updateUrl = useCallback((updates: Record<string, string>) => {
    const p = new URLSearchParams(searchParams.toString());
    Object.entries(updates).forEach(([k, v]) => {
      if (v) p.set(k, v);
      else p.delete(k);
    });
    router.replace(`/vouchers/history?${p.toString()}`, { scroll: false });
  }, [searchParams, router]);

  const handleMonthChange = (m: string) => {
    setSelected(new Set());
    if (m) {
      const [y, mon] = m.split('-');
      const lastDay = new Date(+y, +mon, 0).getDate();
      updateUrl({
        month: m,
        date_from: `${m}-01`,
        date_to: `${m}-${String(lastDay).padStart(2, '0')}`,
        type: '',
      });
    } else {
      updateUrl({ month: '', date_from: '', date_to: '', type: '' });
    }
  };

  const filters = { date_from: dateFrom, date_to: dateTo, type };
  const showTable = !!month || !!dateFrom || !!dateTo;

  const { data: vouchers, isLoading } = useQuery({
    queryKey: ['vouchers', filters],
    queryFn: () => api.get('/vouchers', { params: filters }).then((r) => r.data),
    enabled: showTable,
  });

  const list  = vouchers?.data ?? [];
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

  const monthName = month ? MONTHS[+month.split('-')[1] - 1] ?? '' : '';

  const activeFilterCount = [dateFrom, dateTo, type].filter(Boolean).length;

  return (
    <>
      <PageHeader
        title="سجل الحركات"
        subtitle={showTable ? `${total} إذن مسجل` : 'اختر الشهر أولاً لعرض الحركات'}
        actions={
          showTable && hasSelected && (
            <button
              onClick={handleDeleteSelected}
              disabled={batchDeleteMutation.isPending}
              className="px-4 py-2 bg-red-50 text-red-600 rounded-lg text-sm font-medium hover:bg-red-100 border border-red-200 transition-colors"
            >
              حذف المحدد ({selected.size})
            </button>
          )
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        {/* Month selector */}
        <div className={`rounded-xl p-5 shadow-sm ${month ? 'bg-white border border-gray-100' : 'bg-gradient-to-br from-blue-50 to-indigo-50/50 border-2 border-blue-200'}`}>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <span className="text-xl">📅</span>
              <div>
                <label className="text-xs text-gray-400 block mb-1 font-medium">اختر الشهر</label>
                <input
                  type="month"
                  value={month}
                  onChange={(e) => handleMonthChange(e.target.value)}
                  className="border-2 border-blue-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none bg-white"
                />
              </div>
            </div>
            {month && (
              <div className="flex items-center gap-3">
                <div className="hidden sm:block text-left">
                  <div className="text-sm font-bold text-gray-700">{monthName} {month.split('-')[0]}</div>
                  <div className="text-xs text-gray-400">{dateFrom || '—'} → {dateTo || '—'}</div>
                </div>
                <button
                  onClick={() => handleMonthChange('')}
                  className="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
                  title="تغيير الشهر"
                >
                  <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            )}
          </div>
        </div>

        {showTable ? (
          <>
            {/* Secondary filters */}
            <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
              <div className="flex items-center gap-3 mb-3">
                <span className="text-xs text-gray-400 font-medium">فلاتر إضافية</span>
                {activeFilterCount > 0 && (
                  <span className="text-[10px] bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full">
                    {activeFilterCount} نشط
                  </span>
                )}
              </div>
              <div className="flex gap-3 items-end flex-wrap">
                <div className="flex-1 min-w-32">
                  <label className="text-[10px] text-gray-400 block mb-1">من تاريخ</label>
                  <input
                    type="date"
                    value={dateFrom}
                    onChange={(e) => { setSelected(new Set()); updateUrl({ date_from: e.target.value }); }}
                    className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                  />
                </div>
                <div className="flex-1 min-w-32">
                  <label className="text-[10px] text-gray-400 block mb-1">إلى تاريخ</label>
                  <input
                    type="date"
                    value={dateTo}
                    onChange={(e) => { setSelected(new Set()); updateUrl({ date_to: e.target.value }); }}
                    className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                  />
                </div>
                <div className="flex-1 min-w-32">
                  <label className="text-[10px] text-gray-400 block mb-1">نوع الإذن</label>
                  <select
                    value={type}
                    onChange={(e) => { setSelected(new Set()); updateUrl({ type: e.target.value }); }}
                    className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                  >
                    <option value="">كل الأنواع</option>
                    {Object.entries(TYPE_LABELS).map(([k, v]) => (
                      <option key={k} value={k}>{v}</option>
                    ))}
                  </select>
                </div>
                <button
                  onClick={() => {
                    setSelected(new Set());
                    if (month) {
                      const [y, mon] = month.split('-');
                      const lastDay = new Date(+y, +mon, 0).getDate();
                      updateUrl({ date_from: `${month}-01`, date_to: `${month}-${String(lastDay).padStart(2, '0')}`, type: '' });
                    } else {
                      updateUrl({ date_from: '', date_to: '', type: '' });
                    }
                  }}
                  className="py-2 px-4 bg-gray-50 text-gray-500 rounded-lg text-sm hover:bg-gray-100 border border-gray-200 transition-colors"
                >
                  مسح
                </button>
              </div>
            </div>

            {/* Table */}
            <div className="bg-white border border-gray-100 rounded-xl overflow-hidden shadow-sm">
              <table className="w-full text-sm text-right" dir="rtl">
                <thead>
                  <tr className="text-gray-500 text-xs border-b border-gray-100 bg-gray-50/80">
                    <th className="px-2 py-3.5 w-8">
                      <input
                        type="checkbox"
                        checked={allSelected}
                        onChange={toggleAll}
                        className="rounded border-gray-300"
                      />
                    </th>
                    <th className="px-4 py-3.5 font-semibold">التاريخ</th>
                    <th className="px-4 py-3.5 font-semibold">النوع</th>
                    <th className="px-4 py-3.5 font-semibold">المصدر</th>
                    <th className="px-4 py-3.5 font-semibold">الموقع</th>
                    <th className="px-4 py-3.5 font-semibold">عدد الأصناف</th>
                    <th className="px-4 py-3.5 font-semibold">بواسطة</th>
                    <th className="px-4 py-3.5 text-center font-semibold">إجراءات</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {isLoading ? (
                    <tr>
                      <td colSpan={8} className="px-6 py-12 text-center text-gray-400">
                        <div className="flex items-center justify-center gap-2">
                          <svg className="animate-spin h-4 w-4 text-blue-500" viewBox="0 0 24 24">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                          </svg>
                          جاري التحميل...
                        </div>
                      </td>
                    </tr>
                  ) : list.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-6 py-16 text-center">
                        <div className="text-4xl mb-3">📭</div>
                        <p className="text-gray-400">لا توجد حركات في هذا النطاق</p>
                      </td>
                    </tr>
                  ) : list.map((v: any) => (
                    <tr
                      key={v.id}
                      className={`hover:bg-blue-50/40 transition-colors ${selected.has(v.id) ? 'bg-blue-50' : ''}`}
                    >
                      <td className="px-2 py-3.5">
                        <input
                          type="checkbox"
                          checked={selected.has(v.id)}
                          onChange={() => toggleSelect(v.id)}
                          className="rounded border-gray-300"
                        />
                      </td>
                      <td className="px-4 py-3.5 font-medium text-gray-700">
                        {(() => { const [y,m,d] = (v.date||'').split('T')[0].split('-'); return d&&m&&y ? `${d}/${m}/${y}` : v.date; })()}
                      </td>
                      <td className="px-4 py-3.5">
                        <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold ${TYPE_COLORS[v.type] ?? 'bg-gray-50 text-gray-600 border border-gray-200'}`}>
                          {TYPE_LABELS[v.type] ?? v.type}
                        </span>
                      </td>
                      <td className="px-4 py-3.5">
                        {v.source === 'upload' ? (
                          <span className="text-purple-600 bg-purple-50 px-2.5 py-1 rounded-full text-[10px] font-medium border border-purple-200">رفع</span>
                        ) : (
                          <span className="text-blue-600 bg-blue-50 px-2.5 py-1 rounded-full text-[10px] font-medium border border-blue-200">يدوي</span>
                        )}
                      </td>
                      <td className="px-4 py-3.5 text-gray-600 text-sm">
                        {v.type === 'dispatch'
                          ? (v.branch?.name ?? v.warehouse?.name ?? '—')
                          : (v.warehouse?.name ?? '—')}
                      </td>
                      <td className="px-4 py-3.5">
                        <span className="bg-gray-100 text-gray-600 px-2.5 py-1 rounded-full text-xs font-medium">
                          {v.lines_count ?? 0} صنف
                        </span>
                      </td>
                      <td className="px-4 py-3.5 text-xs text-gray-400">
                        {v.creator?.name ?? 'آلي'}
                      </td>
                      <td className="px-4 py-3.5 text-center">
                        <div className="flex items-center justify-center gap-1.5">
                          <Link
                            href={`/vouchers/${v.id}/edit`}
                            className="text-blue-500 hover:text-blue-700 hover:bg-blue-50 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors inline-flex items-center gap-1"
                          >
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                              <path strokeLinecap="round" strokeLinejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            تعديل
                          </Link>
                          <button
                            onClick={() => handleDelete(v.id, `${TYPE_LABELS[v.type] ?? v.type} — ${(v.date||'').split('T')[0]}`)}
                            disabled={deleteMutation.isPending}
                            className="text-red-400 hover:text-red-600 hover:bg-red-50 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors disabled:opacity-40 inline-flex items-center gap-1"
                          >
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                              <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            حذف
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        ) : (
          <div className="bg-white border border-gray-100 rounded-xl p-16 text-center shadow-sm">
            <div className="text-6xl mb-4 opacity-60">📅</div>
            <p className="text-gray-400 text-lg font-medium">اختر الشهر لعرض الحركات</p>
            <p className="text-gray-300 text-sm mt-2">سيتم عرض جميع الأذون المسجلة في هذا الشهر</p>
          </div>
        )}
      </div>
    </>
  );
}