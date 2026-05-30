'use client';
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { financialApi } from '@/lib/financial/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

function EditableAmount({ value, onSave, enabled }: { value: number; onSave: (v: number) => void; enabled: boolean }) {
  const [editing, setEditing] = useState(false);
  const [val, setVal] = useState(value);

  if (!enabled) return <span>{value.toLocaleString()}</span>;

  if (editing) {
    return (
      <input
        autoFocus
        type="number"
        value={val}
        onChange={(e) => setVal(Number(e.target.value))}
        onBlur={() => { onSave(val); setEditing(false); }}
        onKeyDown={(e) => { if (e.key === 'Enter') { onSave(val); setEditing(false); } }}
        className="w-28 px-2 py-1 border border-blue-300 rounded text-sm text-left"
      />
    );
  }

  return (
    <span onClick={() => { setVal(value); setEditing(true); }} className="cursor-pointer hover:bg-blue-50 px-2 py-1 rounded text-left tabular-nums">
      {value.toLocaleString()}
    </span>
  );
}

function DetailModal({ detail, onClose }: { detail: any; onClose: () => void }) {
  const qc = useQueryClient();
  const [items, setItems] = useState(detail?.items || []);
  const [newName, setNewName] = useState('');
  const [newAmount, setNewAmount] = useState('0');

  const addMutation = useMutation({
    mutationFn: (d: any) => financialApi.addClosingDetailItem(detail.id, d),
    onSuccess: (res: any) => {
      setItems((prev: any) => [...prev, res.item]);
      setNewName('');
      setNewAmount('0');
      qc.invalidateQueries({ queryKey: ['financial-closing'] });
    },
    onError: () => toast.error('خطأ في الإضافة'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => financialApi.deleteClosingDetailItem(id),
    onSuccess: (_: any, id: string) => {
      setItems((prev: any) => prev.filter((i: any) => i.id !== id));
      qc.invalidateQueries({ queryKey: ['financial-closing'] });
    },
    onError: () => toast.error('خطأ في الحذف'),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onClose}>
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 max-h-[80vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
          <h3 className="font-bold text-lg">تفاصيل {detail.name}</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>

        <div className="flex-1 overflow-y-auto px-6 py-4 space-y-2">
          {items.length === 0 && <p className="text-gray-400 text-center py-8">لا توجد تفاصيل</p>}
          {items.map((item: any) => (
            <div key={item.id} className="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-2.5">
              <span className="font-medium text-gray-700">{item.name}</span>
              <div className="flex items-center gap-3">
                <span className="text-gray-900 font-bold">{Number(item.amount).toLocaleString()}</span>
                <button onClick={() => deleteMutation.mutate(item.id)} className="text-red-400 hover:text-red-600 text-sm">&times;</button>
              </div>
            </div>
          ))}
        </div>

        <div className="border-t border-gray-100 px-6 py-4">
          <div className="flex items-end gap-2">
            <div className="flex-1">
              <label className="text-xs text-gray-500 block mb-1">الاسم</label>
              <input value={newName} onChange={(e) => setNewName(e.target.value)} className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400" placeholder="اسم البند" />
            </div>
            <div className="w-28">
              <label className="text-xs text-gray-500 block mb-1">المبلغ</label>
              <input type="number" value={newAmount} onChange={(e) => setNewAmount(e.target.value)} className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400" />
            </div>
            <button onClick={() => { if (newName.trim()) { addMutation.mutate({ name: newName.trim(), amount: Number(newAmount) || 0 }); } }}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50" disabled={addMutation.isPending || !newName.trim()}>إضافة</button>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function FinancialClosingPage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());
  const [detailModal, setDetailModal] = useState<any>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['financial-closing', month, year],
    queryFn: () => financialApi.closingReports(month, year),
  });

  const reports = (data as any)?.reports || [];
  const report = reports[0];
  const details = report?.details || [];

  const generateMutation = useMutation({
    mutationFn: () => financialApi.generateClosingReport(month, year),
    onSuccess: () => { toast.success('تم توليد التقرير'); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في التوليد'),
  });

  const downloadExcel = async () => {
    if (!report) return;
    try {
      const token = localStorage.getItem('erp_token');
      const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/financial/closing-reports/${report.id}/export-excel`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) { toast.error('خطأ في التحميل'); return; }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = `تقفيل_شهري_${report.month}_${report.year}.xlsx`;
      a.click(); URL.revokeObjectURL(url);
    } catch { toast.error('خطأ في التحميل'); }
  };

  const updateDetailMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => financialApi.updateClosingDetail(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }),
    onError: () => toast.error('خطأ في الحفظ'),
  });

  function isEditable(row: any) {
    return row.row_type === 'manual' || (row.row_type === 'auto' && row.line_type !== 'revenue');
  }

  function hasDetail(row: any) {
    return ['صيانة', 'فواتير أخرى', 'أصول'].includes(row.name) && row.row_type === 'auto';
  }

  function rowStyle(row: any) {
    if (row.row_type === 'section_header') return 'bg-gray-100 font-bold text-gray-700';
    if (row.row_type === 'formula' && ['net_cash', 'net_profit'].includes(row.name)) return 'font-bold text-lg';
    if (row.row_type === 'formula') return 'bg-blue-50 font-medium';
    if (row.line_type === 'revenue') return 'bg-green-50 font-bold';
    return 'hover:bg-gray-50';
  }

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="التقفيل الشهري"
        subtitle="تقرير الأرباح والخسائر (P&L)"
        actions={
          <div className="flex gap-2">
            <button onClick={() => generateMutation.mutate()} disabled={generateMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
              {generateMutation.isPending ? 'جاري...' : '🔄 توليد التقفيل'}
            </button>
            {report && (
              <button onClick={downloadExcel}
                className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700">
                📥 Excel
              </button>
            )}
          </div>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        {/* Filters */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
          <div className="grid grid-cols-2 gap-4 items-end">
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">الشهر</label>
              <select value={month} onChange={(e) => setMonth(Number(e.target.value))}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                {Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">السنة</label>
              <input type="number" value={year} onChange={(e) => setYear(Number(e.target.value))}
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" />
            </div>
          </div>
        </div>

        {isLoading ? (
          <div className="text-center py-12 text-gray-400">جاري التحميل...</div>
        ) : !report ? (
          <div className="text-center py-16 bg-white border border-dashed border-gray-200 rounded-xl">
            <div className="text-5xl mb-4 text-gray-300">📑</div>
            <h3 className="text-lg font-bold text-gray-700 mb-2">لا يوجد تقرير تقفيل لهذا الشهر</h3>
            <p className="text-sm text-gray-500 mb-6">قم بتوليد التقرير من اليوميات المسجلة</p>
            <button onClick={() => generateMutation.mutate()}
              className="px-8 py-3 bg-blue-600 text-white rounded-xl text-sm hover:bg-blue-700">🔄 توليد التقفيل</button>
          </div>
        ) : (
          <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
            {/* Summary Cards */}
            <div className="grid grid-cols-2 md:grid-cols-5 gap-4 p-4 border-b border-gray-100 bg-gradient-to-br from-blue-50 to-white">
              <div className="text-center">
                <div className="text-xs text-gray-500">إجمالي المبيعات</div>
                <div className="text-lg font-bold text-green-700">{Number(report.total_sales).toLocaleString()}</div>
              </div>
              <div className="text-center">
                <div className="text-xs text-gray-500">إجمالي المشتريات</div>
                <div className="text-lg font-bold text-orange-700">{Number(report.total_purchases).toLocaleString()}</div>
              </div>
              <div className="text-center">
                <div className="text-xs text-gray-500">إجمالي المصروفات</div>
                <div className="text-lg font-bold text-red-700">{Number(report.total_expenses).toLocaleString()}</div>
              </div>
              <div className="text-center">
                <div className="text-xs text-gray-500">صافي نقدية</div>
                <div className={`text-lg font-bold ${report.net_cash_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                  {Number(report.net_cash_profit).toLocaleString()}
                </div>
              </div>
              <div className="text-center">
                <div className="text-xs text-gray-500">ربح صافي</div>
                <div className={`text-lg font-bold ${report.net_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                  {Number(report.net_profit).toLocaleString()}
                </div>
              </div>
            </div>

            {/* Report Title */}
            <div className="bg-gray-50 border-b border-gray-100 px-6 py-3 text-center">
              <h3 className="font-bold text-gray-900">تقرير التقفيل الشهري — {report.month}/{report.year}</h3>
            </div>

            {/* Details Table */}
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50 text-gray-500 border-b border-gray-100">
                  <th className="px-6 py-3 text-right w-1/2">البيان</th>
                  <th className="px-6 py-3 text-left w-1/4">القيمة</th>
                  <th className="px-6 py-3 text-left w-1/4">النسبة</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {details.map((row: any) => (
                  <tr key={row.id} className={rowStyle(row)}>
                    <td className={`px-6 py-2.5 ${row.row_type === 'section_header' ? 'flex items-center gap-2' : ''}`}>
                      {row.row_type === 'section_header' ? (
                        <>
                          <span className="text-gray-400 text-xs">{'—'.repeat(20)}</span>
                          <span>{row.name}</span>
                        </>
                      ) : (
                        <span className={`${row.row_type === 'formula' ? 'pr-4' : ''} ${row.line_type === 'revenue' || row.line_type === 'profit' ? 'font-bold' : ''}`}>
                          {row.row_type === 'formula' ? '═ ' : ''}{row.line_type === 'revenue' ? '💰 ' : row.line_type === 'profit' ? '💵 ' : ''}{row.name}
                        </span>
                      )}
                      {hasDetail(row) && (
                        <button onClick={() => setDetailModal(row)} className="mr-2 text-blue-500 hover:text-blue-700 text-xs" title="التفاصيل">
                          📋
                        </button>
                      )}
                    </td>
                    <td className="px-6 py-2.5 text-left tabular-nums">
                      {row.row_type === 'section_header' ? (
                        <span className="text-gray-400">—</span>
                      ) : (
                        <EditableAmount
                          value={Number(row.amount)}
                          enabled={isEditable(row)}
                          onSave={(v) => updateDetailMutation.mutate({ id: row.id, data: { amount: v } })}
                        />
                      )}
                    </td>
                    <td className="px-6 py-2.5 text-left tabular-nums text-gray-500">
                      {row.row_type === 'section_header' ? '—' : (row.percentage ? row.percentage.toFixed(1) + '%' : '—')}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {/* Key Metrics */}
            <div className="bg-gray-50 border-t border-gray-100 p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
              <div>
                <div className="text-xs text-gray-500">نسبة صافي نقدي</div>
                <div className={`text-lg font-bold ${report.percentages_json?.net_cash_percentage >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                  {report.percentages_json?.net_cash_percentage ? report.percentages_json.net_cash_percentage.toFixed(2) + '%' : '—'}
                </div>
              </div>
              <div>
                <div className="text-xs text-gray-500">نسبة الربح الصافي</div>
                <div className={`text-lg font-bold ${report.percentages_json?.net_profit_percentage >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                  {report.percentages_json?.net_profit_percentage ? report.percentages_json.net_profit_percentage.toFixed(2) + '%' : '—'}
                </div>
              </div>
              <div>
                <div className="text-xs text-gray-500">معدل المصروفات</div>
                <div className="font-bold text-orange-700">
                  {report.total_sales > 0 ? ((report.total_expenses / report.total_sales) * 100).toFixed(1) + '%' : '—'}
                </div>
              </div>
              <div>
                <div className="text-xs text-gray-500">معدل المشتريات</div>
                <div className="font-bold text-blue-700">
                  {report.total_sales > 0 ? ((report.total_purchases / report.total_sales) * 100).toFixed(1) + '%' : '—'}
                </div>
              </div>
            </div>
          </div>
        )}
      </div>

      {detailModal && <DetailModal detail={detailModal} onClose={() => setDetailModal(null)} />}
    </div>
  );
}
