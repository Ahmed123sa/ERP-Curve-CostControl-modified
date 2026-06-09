'use client';
import { useState, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { financialApi } from '@/lib/financial/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useAuthStore } from '@/lib/store';
import toast from 'react-hot-toast';
function EditableAmount({ value, onSave, enabled }: { value: number; onSave: (v: number) => void; enabled: boolean }) {
  const [editing, setEditing] = useState(false);
  const [val, setVal] = useState(value);

  if (!enabled) return <span className="tabular-nums">{value.toLocaleString()}</span>;

  if (editing) {
    return (
      <input autoFocus type="number" value={val}
        onChange={(e) => setVal(Number(e.target.value))}
        onBlur={() => { onSave(val); setEditing(false); }}
        onKeyDown={(e) => { if (e.key === 'Enter') { onSave(val); setEditing(false); } }}
        className="w-28 px-2 py-1 border border-blue-300 rounded text-sm text-left" />
    );
  }
  return (
    <span onClick={() => { setVal(value); setEditing(true); }}
      className="cursor-pointer hover:bg-blue-50 px-2 py-1 rounded text-left tabular-nums">
      {value.toLocaleString()}
    </span>
  );
}

function DetailSubRow({ detailId }: { detailId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['detail-entries', detailId],
    queryFn: () => financialApi.getDetailEntries(detailId),
  });

  const entries = (data as any)?.entries || [];
  const total = entries.reduce((s: number, e: any) => s + Number(e.amount), 0);

  if (isLoading) return <td colSpan={4} className="px-6 py-3 text-gray-400 text-sm">جاري التحميل...</td>;
  if (entries.length === 0) return <td colSpan={4} className="px-6 py-3 text-gray-400 text-sm">لا توجد قيود يومية</td>;

  return (
    <td colSpan={4} className="px-6 py-2">
      <div className="bg-gray-50 border border-gray-200 rounded-lg overflow-hidden mr-6">
        <table className="w-full text-xs">
          <thead>
            <tr className="bg-gray-200 text-gray-600 border-b">
              <th className="px-3 py-1.5 text-right w-24">التاريخ</th>
              <th className="px-3 py-1.5 text-right">التفاصيل</th>
              <th className="px-3 py-1.5 text-left w-28">المبلغ</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {entries.map((entry: any) => (
              <tr key={entry.id} className="hover:bg-gray-100">
                <td className="px-3 py-1.5 text-gray-500">{entry.date}</td>
                <td className="px-3 py-1.5">{entry.description || entry.entry_notes || '—'}</td>
                <td className="px-3 py-1.5 text-left font-medium tabular-nums">{Number(entry.amount).toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="bg-gray-100 border-t border-gray-200 px-3 py-1.5 flex justify-between text-sm font-bold">
          <span>الإجمالي</span>
          <span className="tabular-nums">{total.toLocaleString()}</span>
        </div>
      </div>
    </td>
  );
}

function LinkModal({ row, data, onClose, onApply }: { row: any; data: any; onClose: () => void; onApply?: (value: number) => void }) {
  const employees = data?.employees || [];
  const totalAll = data?.total_all || 0;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onClose}>
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-2xl mx-4 max-h-[85vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
          <h3 className="font-bold text-lg">ربط بالرواتب — {row.name}</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <div className="px-6 py-4 overflow-y-auto space-y-4">
          {!data ? (
            <div className="text-center py-8 text-gray-400">جاري التحميل...</div>
          ) : employees.length === 0 ? (
            <div className="text-center py-8 text-gray-400">
              لا توجد رواتب معتمدة لهذا الشهر
              {data.status === null && ' (قم باعتماد كشف الرواتب أولاً)'}
            </div>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-200 text-gray-600">
                  <th className="px-3 py-2 text-right">الموظف</th>
                  <th className="px-3 py-2 text-left w-24">الأساسي</th>
                  <th className="px-3 py-2 text-left w-20">أيام</th>
                  <th className="px-3 py-2 text-left w-20">السلف</th>
                  <th className="px-3 py-2 text-left w-20">الخصم</th>
                  <th className="px-3 py-2 text-left w-24">الصافي</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {employees.map((emp: any, i: number) => (
                  <tr key={i} className="hover:bg-gray-50">
                    <td className="px-3 py-2 font-medium">{emp.name}</td>
                    <td className="px-3 py-2 text-left tabular-nums">{emp.base_salary.toLocaleString()}</td>
                    <td className="px-3 py-2 text-left tabular-nums">{emp.work_days}</td>
                    <td className="px-3 py-2 text-left tabular-nums">{emp.advance_amount.toLocaleString()}</td>
                    <td className="px-3 py-2 text-left tabular-nums">{emp.total_deductions.toLocaleString()}</td>
                    <td className="px-3 py-2 text-left tabular-nums font-bold">{emp.net_salary.toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="bg-gray-50 font-bold border-t-2 border-gray-300">
                  <td className="px-3 py-2">الإجمالي</td>
                  <td className="px-3 py-2 text-left tabular-nums text-blue-700" colSpan={5}>{totalAll.toLocaleString()}</td>
                </tr>
              </tfoot>
            </table>
          )}
        </div>
        <div className="px-6 py-3 border-t border-gray-100 flex items-center justify-between shrink-0">
          <span className="text-sm text-gray-500">إجمالي الرواتب المعتمدة: <strong>{totalAll.toLocaleString()}</strong></span>
          <div className="flex gap-2">
            <button onClick={() => onApply?.(totalAll)} disabled={!totalAll}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">تطبيق القيمة</button>
            <button onClick={onClose} className="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">إغلاق</button>
          </div>
        </div>
      </div>
    </div>
  );
}

function FormulaModal({ detail, details, onClose, onSave }: { detail: any; details: any[]; onClose: () => void; onSave: (formula: any) => void }) {
  const [type, setType] = useState(detail?.formula_config?.type || 'sum');
  const [selectedKeys, setSelectedKeys] = useState<string[]>(
    detail?.formula_config?.keys || (detail?.formula_config?.a ? [detail.formula_config.a, detail.formula_config.b].filter(Boolean) : [])
  );
  const [customExpr, setCustomExpr] = useState(detail?.formula_config?.expression || '');
  const availableAll = details.filter((d: any) => d.id !== detail.id && d.row_type !== 'section_header');
  const available = type === 'custom' ? availableAll : availableAll.filter((d: any) => d.line_type === detail.line_type);

  function toggleKey(key: string) {
    setSelectedKeys((prev) => prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]);
  }

  function insertKey(key: string) {
    setCustomExpr((prev) => {
      const trimmed = prev.trim();
      return trimmed ? trimmed + ' + ' + key : key;
    });
  }

  function handleSave() {
    if (type === 'custom') {
      onSave({ type: 'custom', expression: customExpr });
    } else if (type === 'sum') {
      onSave({ type: 'sum', keys: selectedKeys });
    } else if (type === 'subtract' && selectedKeys.length >= 2) {
      onSave({ type: 'subtract', a: selectedKeys[0], b: selectedKeys[1] });
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onClose}>
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-2xl mx-4" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
          <h3 className="font-bold text-lg">معادلة {detail.name}</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <div className="px-6 py-4 space-y-4">
          <div>
            <label className="text-xs font-medium text-gray-500 block mb-2">نوع المعادلة</label>
            <div className="flex gap-4">
              <label className="flex items-center gap-2 cursor-pointer">
                <input type="radio" name="ftype" value="sum" checked={type === 'sum'} onChange={() => setType('sum')} />
                <span>SUM (جمع)</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input type="radio" name="ftype" value="subtract" checked={type === 'subtract'} onChange={() => setType('subtract')} />
                <span>طرح (A - B)</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input type="radio" name="ftype" value="custom" checked={type === 'custom'} onChange={() => setType('custom')} />
                <span>نص مخصص</span>
              </label>
            </div>
          </div>
          {type === 'custom' ? (
            <div className="space-y-3">
              <div>
                <label className="text-xs font-medium text-gray-500 block mb-2">الصيغة (استخدم +, -, *, /)</label>
                <input type="text" value={customExpr} onChange={(e) => setCustomExpr(e.target.value)}
                  placeholder={'مثال: revenue - total_purchases'}
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono" />
              </div>
              <div>
                <label className="text-xs font-medium text-gray-500 block mb-2">اختر واضغط للإدراج</label>
                <div className="max-h-48 overflow-y-auto border border-gray-200 rounded-lg p-1.5 space-y-0.5">
                  {available.map((d: any) => (
                    <button key={d.row_key || d.id} type="button" onClick={() => insertKey(d.row_key)}
                      className="w-full flex items-center justify-between px-3 py-1.5 rounded text-sm hover:bg-blue-50 hover:text-blue-700 text-right">
                      <span className="font-medium">{d.name}</span>
                      <span className="text-xs text-gray-400 font-mono mr-2">{d.row_key} ({d.amount.toLocaleString()})</span>
                    </button>
                  ))}
                  {available.length === 0 && <p className="text-gray-400 text-center py-4 text-sm">لا توجد بنود متاحة</p>}
                </div>
              </div>
            </div>
          ) : (
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-2">{type === 'sum' ? 'اختر البنود للجمع' : 'اختر A ثم B للطرح'}</label>
              <div className="max-h-60 overflow-y-auto border border-gray-200 rounded-lg p-2 space-y-1">
                {available.map((d: any) => (
                  <label key={d.row_key || d.id} className={`flex items-center gap-2 px-3 py-2 rounded cursor-pointer ${selectedKeys.includes(d.row_key) ? 'bg-blue-50 text-blue-700' : 'hover:bg-gray-50'}`}>
                    <input type={type === 'subtract' ? 'radio' : 'checkbox'} name="f-row"
                      checked={selectedKeys.includes(d.row_key)} onChange={() => toggleKey(d.row_key)} />
                    <span>{d.name} ({d.amount.toLocaleString()})</span>
                  </label>
                ))}
                {available.length === 0 && <p className="text-gray-400 text-center py-4">لا توجد بنود متاحة</p>}
              </div>
            </div>
          )}
          <div className="flex gap-2 justify-end pt-2 border-t border-gray-100">
            <button onClick={onClose} className="px-4 py-2 text-gray-600 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
            <button onClick={handleSave}
              disabled={type === 'custom' ? !customExpr.trim() : selectedKeys.length < (type === 'subtract' ? 2 : 1)}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">حفظ المعادلة</button>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function FinancialClosingPage() {
  const qc = useQueryClient();
  const { currentClient } = useAuthStore();
  const now = new Date();
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());
  const [formulaModal, setFormulaModal] = useState<any>(null);
  const [showAddRow, setShowAddRow] = useState<string | null>(null);
  const [addRowName, setAddRowName] = useState('');
  const [addRowAmount, setAddRowAmount] = useState('0');
  const [selectedReportId, setSelectedReportId] = useState<string | null>(null);
  const [expandedDetails, setExpandedDetails] = useState<Set<string>>(new Set());
  const [linkModal, setLinkModal] = useState<{ row: any; type: 'salaries' } | null>(null);
  const [selectedDetailIds, setSelectedDetailIds] = useState<Set<string>>(new Set());

  const toggleSelectedId = useCallback((id: string) => {
    setSelectedDetailIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }, []);

  const toggleDetail = useCallback((id: string) => {
    setExpandedDetails((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }, []);

  const { data, isLoading } = useQuery({
    queryKey: ['financial-closing', month, year],
    queryFn: () => financialApi.closingReports(month, year),
  });

  const reports = (data as any)?.reports || [];
  const reportData = useQuery({
    queryKey: ['financial-closing-report', selectedReportId],
    queryFn: () => financialApi.showClosingReport(selectedReportId!),
    enabled: !!selectedReportId,
  });

  const report = selectedReportId ? (reportData.data as any)?.report : reports[0];
  const details = report?.details || [];
  const status = report?.status || 'draft';

  const allSelectable = details.filter((r: any) => r.category_id && r.row_type !== 'section_header');
  const allSelected = allSelectable.length > 0 && allSelectable.every((r: any) => selectedDetailIds.has(r.id));

  const toggleSelectAll = useCallback(() => {
    if (allSelected) {
      setSelectedDetailIds(new Set());
    } else {
      setSelectedDetailIds(new Set(allSelectable.map((r: any) => r.id)));
    }
  }, [allSelected, allSelectable]);

  const generateMutation = useMutation({
    mutationFn: () => financialApi.generateClosingReport(month, year),
    onSuccess: (res: any) => { toast.success('تم توليد التقرير'); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); setSelectedReportId(res.report?.id || null); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في التوليد'),
  });

  const monthNames = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

  const downloadExport = async (format: 'excel' | 'pdf') => {
    if (!report) return;
    try {
      const token = localStorage.getItem('erp_token');
      const detailIds = Array.from(selectedDetailIds);
      const params = new URLSearchParams();
      if (detailIds.length > 0) params.set('detail_ids', detailIds.join(','));
      const qs = params.toString();
      const ext = format === 'excel' ? 'xlsx' : 'pdf';
      const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/financial/closing-reports/${report.id}/export-${format}${qs ? '?' + qs : ''}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) { toast.error('خطأ في التحميل'); return; }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = `تقرير مالي ${currentClient?.name || ''} ${monthNames[report.month] || report.month} ${report.year}.${ext}`;
      a.click(); URL.revokeObjectURL(url);
    } catch { toast.error('خطأ في التحميل'); }
  };

  const updateDetailMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => financialApi.updateClosingDetail(id, data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); if (selectedReportId) qc.invalidateQueries({ queryKey: ['financial-closing-report', selectedReportId] }); },
    onError: () => toast.error('خطأ في الحفظ'),
  });

  const addDetailMutation = useMutation({
    mutationFn: (d: any) => financialApi.addClosingDetail(report.id, d),
    onSuccess: () => { toast.success('تمت الإضافة'); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); setShowAddRow(null); setAddRowName(''); setAddRowAmount('0'); },
    onError: () => toast.error('خطأ في الإضافة'),
  });

  const deleteDetailMutation = useMutation({
    mutationFn: (id: string) => financialApi.deleteClosingDetail(id),
    onSuccess: () => { toast.success('تم الحذف'); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); },
    onError: () => toast.error('خطأ في الحذف'),
  });

  const resetToAutoMutation = useMutation({
    mutationFn: (id: string) => financialApi.resetClosingDetailToAuto(id),
    onSuccess: () => { toast.success('تم إعادة التعيين التلقائي'); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); },
    onError: () => toast.error('خطأ في إعادة التعيين'),
  });

  const updateFormulaMutation = useMutation({
    mutationFn: ({ id, formula }: { id: string; formula: any }) => financialApi.updateClosingDetailFormula(id, formula),
    onSuccess: () => { toast.success('تم حفظ المعادلة'); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); if (selectedReportId) qc.invalidateQueries({ queryKey: ['financial-closing-report', selectedReportId] }); setFormulaModal(null); },
    onError: () => toast.error('خطأ في حفظ المعادلة'),
  });

  const approveMutation = useMutation({
    mutationFn: () => financialApi.approveClosingReport(report.id),
    onSuccess: () => { toast.success('تم اعتماد التقرير'); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في الاعتماد'),
  });

  const closeMutation = useMutation({
    mutationFn: () => financialApi.closeClosingReport(report.id),
    onSuccess: () => { toast.success('تم إغلاق التقرير'); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في الإغلاق'),
  });

  const reopenMutation = useMutation({
    mutationFn: () => financialApi.reopenClosingReport(report.id),
    onSuccess: () => { toast.success('تم إعادة فتح التقرير'); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في إعادة الفتح'),
  });

  const linkSalariesQuery = useQuery({
    queryKey: ['link-salaries', report?.id],
    queryFn: () => financialApi.linkSalaries(report!.id),
    enabled: linkModal?.type === 'salaries' && !!report,
  });

  const applyLinkValueMutation = useMutation({
    mutationFn: ({ detailId, value }: { detailId: string; value: number }) => financialApi.applyLinkValue(detailId, value),
    onSuccess: () => { toast.success('تم تطبيق القيمة'); setLinkModal(null); qc.invalidateQueries({ queryKey: ['financial-closing', month, year] }); },
    onError: () => toast.error('خطأ في تطبيق القيمة'),
  });

  function isEditable(row: any) {
    return status === 'draft' && (row.row_type === 'manual' || (row.row_type === 'auto' && row.line_type !== 'revenue'));
  }

  function canDelete(row: any) {
    return status === 'draft' && row.row_type === 'manual' && !row.category_id;
  }

  function canResetToAuto(row: any) {
    return status === 'draft' && row.row_type === 'manual' && row.category_id;
  }

  function isFormulaRow(row: any) {
    return row.row_type === 'formula';
  }

  function showDetails(row: any): boolean {
    if (row.row_type === 'section_header' || row.line_type === 'revenue' || !row.category_id) return false;
    const noDetails = ['رواتب', 'إيجارات', 'إيجارات عاملين'];
    if (noDetails.includes(row.name)) return false;
    return true;
  }

  function shouldLinkSalaries(row: any): boolean {
    return row.name.includes('راتب') || row.name.includes('رواتب');
  }

  function rowStyle(row: any) {
    if (row.row_type === 'section_header') return 'bg-gray-100 font-bold text-gray-700';
    if (row.row_type === 'formula') return row.row_key === 'net_cash' || row.row_key === 'net_profit' ? 'bg-green-50 font-bold text-base' : 'bg-blue-50 font-medium';
    if (row.line_type === 'revenue') return 'bg-green-50 font-bold';
    return 'hover:bg-gray-50';
  }

  function statusBadge(s: string) {
    switch (s) {
      case 'draft': return <span className="px-2 py-0.5 bg-yellow-100 text-yellow-800 rounded-full text-xs font-medium">مسودة</span>;
      case 'approved': return <span className="px-2 py-0.5 bg-green-100 text-green-800 rounded-full text-xs font-medium">معتمد</span>;
      case 'closed': return <span className="px-2 py-0.5 bg-gray-200 text-gray-700 rounded-full text-xs font-medium">مغلق</span>;
      default: return <span className="px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full text-xs">{s}</span>;
    }
  }

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader title="التقفيل الشهري" subtitle="تقرير الأرباح والخسائر (P&L)"
        actions={
          <div className="flex gap-2">
            {report && status !== 'draft' && <button onClick={() => reopenMutation.mutate()} disabled={reopenMutation.isPending} className="px-3 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 disabled:opacity-50">إعادة فتح</button>}
            {report && status === 'draft' && <button onClick={() => approveMutation.mutate()} disabled={approveMutation.isPending} className="px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700 disabled:opacity-50">اعتماد</button>}
            {report && status === 'approved' && <button onClick={() => closeMutation.mutate()} disabled={closeMutation.isPending} className="px-3 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 disabled:opacity-50">إغلاق</button>}
            <button onClick={() => generateMutation.mutate()} disabled={generateMutation.isPending} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">{generateMutation.isPending ? 'جاري...' : 'توليد التقفيل'}</button>
            {report && <button onClick={() => downloadExport('excel')} className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700">Excel</button>}
            {report && <button onClick={() => downloadExport('pdf')} className="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">PDF</button>}
          </div>
        }
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
          <div className="grid grid-cols-2 gap-4 items-end">
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">الشهر</label>
              <select value={month} onChange={(e) => setMonth(Number(e.target.value))} className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                {Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-500 block mb-1">السنة</label>
              <input type="number" value={year} onChange={(e) => setYear(Number(e.target.value))} className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" />
            </div>
          </div>
          {reports.length > 1 && (
            <div className="mt-3 border-t border-gray-100 pt-3">
              <label className="text-xs font-medium text-gray-500 block mb-1">تقرير موجود</label>
              <select value={selectedReportId || reports[0]?.id || ''} onChange={(e) => setSelectedReportId(e.target.value || null)} className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                {reports.map((r: any) => <option key={r.id} value={r.id}>شهر {r.month} / {r.year} — {r.status}</option>)}
              </select>
            </div>
          )}
        </div>

        {isLoading ? (
          <div className="text-center py-12 text-gray-400">جاري التحميل...</div>
        ) : !report ? (
          <div className="text-center py-16 bg-white border border-dashed border-gray-200 rounded-xl">
            <div className="text-5xl mb-4 text-gray-300">📑</div>
            <h3 className="text-lg font-bold text-gray-700 mb-2">لا يوجد تقرير تقفيل لهذا الشهر</h3>
            <p className="text-sm text-gray-500 mb-6">قم بتوليد التقرير من اليوميات المسجلة</p>
            <button onClick={() => generateMutation.mutate()} className="px-8 py-3 bg-blue-600 text-white rounded-xl text-sm hover:bg-blue-700">توليد التقفيل</button>
          </div>
        ) : (
          <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
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
                <div className={`text-lg font-bold ${report.net_cash_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>{Number(report.net_cash_profit).toLocaleString()}</div>
              </div>
              <div className="text-center">
                <div className="text-xs text-gray-500">ربح صافي</div>
                <div className={`text-lg font-bold ${report.net_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>{Number(report.net_profit).toLocaleString()}</div>
              </div>
            </div>

            <div className="bg-gray-50 border-b border-gray-100 px-6 py-3 flex items-center justify-between">
              <h3 className="font-bold text-gray-900">تقرير التقفيل — {report.month}/{report.year}</h3>
              {statusBadge(report.status)}
            </div>

            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50 text-gray-500 border-b border-gray-100">
                  <th className="px-2 py-3 w-10 text-center">
                    <input type="checkbox" checked={allSelected} onChange={toggleSelectAll}
                      className="accent-blue-600 cursor-pointer" title="تحديد الكل للتصدير" />
                  </th>
                  <th className="px-6 py-3 text-right">البيان</th>
                  <th className="px-6 py-3 text-left w-28">القيمة</th>
                  <th className="px-6 py-3 text-center w-20">%</th>
                  <th className="px-6 py-3 text-left w-24"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {details.map((row: any) => (
                  <tr key={row.id} className={rowStyle(row)}>
                    <td className="px-2 py-2.5 text-center">
                      {row.row_type !== 'section_header' && row.category_id && (
                        <input type="checkbox" checked={selectedDetailIds.has(row.id)}
                          onChange={() => toggleSelectedId(row.id)}
                          className="accent-blue-600 cursor-pointer" title="تصدير تفاصيل القيد" />
                      )}
                    </td>
                    <td className="px-6 py-2.5">
                      {row.row_type === 'section_header' ? (
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-2">
                            <span className="text-gray-400 text-xs">{'—'.repeat(30)}</span>
                            <span>{row.name}</span>
                          </div>
                          {status === 'draft' && (
                            <button onClick={() => setShowAddRow(showAddRow === row.line_type ? null : row.line_type)}
                              className="flex items-center gap-1 text-blue-600 hover:text-blue-800 text-xs px-2.5 py-1.5 rounded hover:bg-blue-50 border border-blue-200">+ إضافة بند</button>
                          )}
                        </div>
                      ) : (
                        <div className="flex items-center gap-2">
                          {showDetails(row) && (
                            <button onClick={() => toggleDetail(row.id)}
                              className="text-blue-500 hover:text-blue-700 text-sm px-1 rounded hover:bg-blue-50"
                              title={expandedDetails.has(row.id) ? 'إخفاء التفاصيل' : 'عرض التفاصيل'}>
                              {expandedDetails.has(row.id) ? '🔽' : '📋'}
                            </button>
                          )}
                          <span className={`${row.row_type === 'formula' ? 'pr-4' : ''} ${row.line_type === 'revenue' ? 'font-bold' : row.line_type === 'profit' ? 'font-bold' : ''}`}>
                            {row.row_type === 'formula' ? '═ ' : ''}{row.name}
                          </span>
                          {row.formula_text && (
                            <span className="text-xs text-gray-400" dir="ltr">{row.formula_text}</span>
                          )}
                        </div>
                      )}
                    </td>
                    <td className="px-6 py-2.5 text-left tabular-nums">
                      {row.row_type === 'section_header' ? (
                        <span className="text-gray-400">—</span>
                      ) : (
                        <EditableAmount value={Number(row.amount)} enabled={isEditable(row)}
                          onSave={(v) => updateDetailMutation.mutate({ id: row.id, data: { amount: v } })} />
                      )}
                    </td>
                    <td className="px-6 py-2.5 text-center tabular-nums text-gray-500 text-xs">
                      {row.row_type === 'section_header' ? '—' : (row.percentage ? (row.percentage / 100).toLocaleString(undefined, { style: 'percent', minimumFractionDigits: 1 }) : '—')}
                    </td>
                    <td className="px-6 py-2.5 text-left">
                      {row.row_type !== 'section_header' && (
                        <div className="flex items-center gap-1">
                          {shouldLinkSalaries(row) && (
                            <button onClick={() => setLinkModal({ row, type: 'salaries' })}
                              className="text-indigo-600 hover:text-indigo-800 text-xs px-1.5 py-1 rounded hover:bg-indigo-50"
                              title="ربط بالرواتب">📋</button>
                          )}
                          {isFormulaRow(row) && (
                            <button onClick={() => setFormulaModal(row)}
                              className="text-purple-600 hover:text-purple-800 text-xs px-1.5 py-1 rounded hover:bg-purple-50"
                              title="تعديل المعادلة">∑</button>
                          )}
                          {canResetToAuto(row) && (
                            <button onClick={() => resetToAutoMutation.mutate(row.id)}
                              className="text-amber-600 hover:text-amber-800 text-xs px-1.5 py-1 rounded hover:bg-amber-50"
                              title="إعادة التعيين التلقائي">↺</button>
                          )}
                          {canDelete(row) && (
                            <button onClick={() => { if (confirm('حذف هذا البند؟')) deleteDetailMutation.mutate(row.id); }}
                              className="text-red-400 hover:text-red-600 text-xs px-1.5 py-1 rounded hover:bg-red-50" title="حذف">&times;</button>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}

                {/* Inline Detail Rows for expanded items */}
                {details.filter((r: any) => expandedDetails.has(r.id)).map((row: any) => (
                  <tr key={'detail-' + row.id} className="bg-gray-50/50">
                    <DetailSubRow detailId={row.id} />
                  </tr>
                ))}
              </tbody>
            </table>

            {/* Add Row Inline Form */}
            {showAddRow && status === 'draft' && (
              <div className="px-6 py-3 bg-gray-50 border-t border-gray-100">
                <div className="flex items-end gap-2">
                  <div className="flex-1">
                    <label className="text-xs text-gray-500 block mb-1">اسم البند الجديد</label>
                    <input value={addRowName} onChange={(e) => setAddRowName(e.target.value)} className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400" placeholder="اسم البند" />
                  </div>
                  <div className="w-28">
                    <label className="text-xs text-gray-500 block mb-1">المبلغ</label>
                    <input type="number" value={addRowAmount} onChange={(e) => setAddRowAmount(e.target.value)} className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400" />
                  </div>
                  <button onClick={() => { if (addRowName.trim()) addDetailMutation.mutate({ name: addRowName.trim(), amount: Number(addRowAmount) || 0, line_type: showAddRow }); }}
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50" disabled={addDetailMutation.isPending || !addRowName.trim()}>إضافة</button>
                  <button onClick={() => { setShowAddRow(null); setAddRowName(''); setAddRowAmount('0'); }} className="px-4 py-2 text-gray-600 border border-gray-200 rounded-lg text-sm hover:bg-gray-50">إلغاء</button>
                </div>
              </div>
            )}

            <div className="bg-gray-50 border-t border-gray-100 p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
              <div>
                <div className="text-xs text-gray-500">نسبة صافي نقدي</div>
                <div className={`text-lg font-bold ${report.percentages_json?.net_cash_percentage >= 0 ? 'text-green-700' : 'text-red-700'}`}>{report.percentages_json?.net_cash_percentage ? report.percentages_json.net_cash_percentage.toFixed(2) + '%' : '—'}</div>
              </div>
              <div>
                <div className="text-xs text-gray-500">نسبة الربح الصافي</div>
                <div className={`text-lg font-bold ${report.percentages_json?.net_profit_percentage >= 0 ? 'text-green-700' : 'text-red-700'}`}>{report.percentages_json?.net_profit_percentage ? report.percentages_json.net_profit_percentage.toFixed(2) + '%' : '—'}</div>
              </div>
              <div>
                <div className="text-xs text-gray-500">معدل المصروفات</div>
                <div className="font-bold text-orange-700">{report.total_sales > 0 ? ((report.total_expenses / report.total_sales) * 100).toFixed(1) + '%' : '—'}</div>
              </div>
              <div>
                <div className="text-xs text-gray-500">معدل المشتريات</div>
                <div className="font-bold text-blue-700">{report.total_sales > 0 ? ((report.total_purchases / report.total_sales) * 100).toFixed(1) + '%' : '—'}</div>
              </div>
            </div>
          </div>
        )}
      </div>

      {formulaModal && (
        <FormulaModal detail={formulaModal} details={details}
          onClose={() => setFormulaModal(null)} onSave={(formula: any) => updateFormulaMutation.mutate({ id: formulaModal.id, formula })} />
      )}

      {linkModal && (
        <LinkModal row={linkModal.row}
          data={linkSalariesQuery.data}
          onClose={() => setLinkModal(null)}
          onApply={(value) => applyLinkValueMutation.mutate({ detailId: linkModal.row.id, value })} />
      )}
    </div>
  );
}
