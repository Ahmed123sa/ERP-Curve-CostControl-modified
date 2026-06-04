'use client';
import React, { useState, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { payrollApi } from '@/lib/payroll/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

const EDITABLE_FIELDS = [
  'work_days', 'absence_days', 'rest_day_ot_days',
  'double_shift_days', 'overtime_hours', 'advance_amount',
  'absence_amount', 'rest_day_ot_amount', 'double_shift_amount', 'overtime_amount',
];

export default function PayrollMonthlyPage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());
  const [selectedPayroll, setSelectedPayroll] = useState<string | null>(null);
  const [expandedEmp, setExpandedEmp] = useState<string | null>(null);
  const [bonusItems, setBonusItems] = useState<{ name: string; amount: string }[]>([]);
  const [editingCell, setEditingCell] = useState<{ detailId: string; field: string } | null>(null);
  const [editValue, setEditValue] = useState('');

  const { data: payrolls = [], isLoading } = useQuery({
    queryKey: ['payroll-monthly'],
    queryFn: () => payrollApi.payrolls(),
  });

  const { data: payrollDetail, refetch: refetchDetail } = useQuery({
    queryKey: ['payroll-monthly-detail', selectedPayroll],
    queryFn: () => payrollApi.showPayroll(selectedPayroll!),
    enabled: !!selectedPayroll,
  });

  const [salaryBaseDays, setSalaryBaseDays] = useState(30);

  const calcMut = useMutation({
    mutationFn: () => payrollApi.calculatePayroll(month, year, salaryBaseDays),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
      toast.success(res.message);
    },
  });

  const updateBaseDaysMut = useMutation({
    mutationFn: ({ id, days }: { id: string; days: number }) => payrollApi.updateBaseDays(id, days),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
      qc.invalidateQueries({ queryKey: ['payroll-monthly-detail'] });
      toast.success(res.message);
    },
  });

  const approveMut = useMutation({
    mutationFn: (id: string) => payrollApi.approvePayroll(id),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
      toast.success(res.message);
    },
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => payrollApi.deletePayroll(id),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
      setSelectedPayroll(null);
      toast.success(res.message);
    },
  });

  const bonusMut = useMutation({
    mutationFn: ({ detailId, items }: { detailId: string; items: any[] }) => payrollApi.updateBonus(detailId, items),
    onSuccess: (res) => { toast.success(res.message); refetchDetail(); },
  });

  const updateCellMut = useMutation({
    mutationFn: ({ detailId, field, value }: { detailId: string; field: string; value: number }) =>
      payrollApi.updateCell(detailId, field, value),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['payroll-monthly-detail'] });
      toast.success('تم التحديث');
    },
  });

  const openPayroll = (id: string) => {
    setSelectedPayroll(id);
    setExpandedEmp(null);
    setBonusItems([]);
    setEditingCell(null);
  };

  const openBonus = (detail: any) => {
    setExpandedEmp(detail.employee_id === expandedEmp ? null : detail.employee_id);
    setBonusItems((detail.bonus_items ?? []).map((b: any) => ({ name: b.name, amount: b.amount.toString() })));
    if (bonusItems.length === 0) setBonusItems([{ name: '', amount: '' }]);
  };

  const addBonusRow = () => setBonusItems([...bonusItems, { name: '', amount: '' }]);
  const removeBonusRow = (i: number) => setBonusItems(bonusItems.filter((_, idx) => idx !== i));
  const updateBonusRow = (i: number, field: 'name' | 'amount', value: string) => {
    const items = [...bonusItems];
    items[i][field] = value;
    setBonusItems(items);
  };

  const saveBonus = (detailId: string) => {
    const items = bonusItems.filter((b) => b.name.trim() && parseFloat(b.amount) > 0).map((b) => ({ name: b.name.trim(), amount: parseFloat(b.amount) }));
    bonusMut.mutate({ detailId, items });
  };

  const startEdit = (detailId: string, field: string, currentValue: number) => {
    setEditingCell({ detailId, field });
    setEditValue(currentValue.toString());
  };

  const saveEdit = (detailId: string, field: string) => {
    const value = parseFloat(editValue);
    if (isNaN(value) || value < 0) return toast.error('قيمة غير صالحة');
    updateCellMut.mutate({ detailId, field, value });
    setEditingCell(null);
    setEditValue('');
  };

  const cancelEdit = () => {
    setEditingCell(null);
    setEditValue('');
  };

  const handleKeyDown = (e: React.KeyboardEvent, detailId: string, field: string) => {
    if (e.key === 'Enter') saveEdit(detailId, field);
    if (e.key === 'Escape') cancelEdit();
  };

  const handleExportExcel = (payrollId: string) => {
    const token = localStorage.getItem('erp_token');
    const link = document.createElement('a');
    link.href = `${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/payroll/monthly/${payrollId}/export-excel?token=${token}`;
    link.click();
    toast.success('جاري التصدير...');
  };

  const currentPayroll = payrollDetail?.details ? payrollDetail : payrolls.find((p: any) => p.id === selectedPayroll);
  const details = currentPayroll?.details ?? [];
  const isApproved = currentPayroll?.status === 'approved';

  const renderCell = (d: any, field: string, label: string, format?: (v: any) => string) => {
    const value = d[field] ?? 0;
    const isEditing = editingCell?.detailId === d.id && editingCell?.field === field;
    const isEditable = !isApproved && EDITABLE_FIELDS.includes(field);

    if (isEditing) {
      return (
        <td className="px-1.5 py-1 border-b border-gray-100">
          <input
            type="number"
            step="0.01"
            min={['overtime_hours', 'overtime_amount'].includes(field) ? undefined : '0'}
            className="w-full border border-blue-400 rounded px-1.5 py-1 text-xs text-center font-medium focus:outline-none focus:ring-2 focus:ring-blue-300"
            value={editValue}
            onChange={(e) => setEditValue(e.target.value)}
            onBlur={() => saveEdit(d.id, field)}
            onKeyDown={(e) => handleKeyDown(e, d.id, field)}
            autoFocus
            onClick={(e) => e.stopPropagation()}
          />
        </td>
      );
    }

    return (
      <td
        className={`px-1.5 py-1.5 border-b border-gray-100 text-xs text-center whitespace-nowrap ${isEditable ? 'cursor-pointer hover:bg-blue-50 hover:ring-1 hover:ring-blue-300 hover:ring-inset transition-all' : ''} font-semibold ${field === 'net_salary' ? 'text-blue-700 text-sm' : 'text-gray-800'}`}
        onClick={() => isEditable && startEdit(d.id, field, value)}
        title={isEditable ? `تعديل ${label}` : label}
      >
        {format ? format(value) : value}
      </td>
    );
  };

  const fmt2 = (v: any) => (v ?? 0).toFixed(2);
  const fmt0 = (v: any) => (v ?? 0).toFixed(0);

  if (selectedPayroll && !payrollDetail) {
    return <div className="text-center text-gray-400 py-10">جاري التحميل...</div>;
  }

  return (
    <div className="flex flex-col h-full">
      <PageHeader title="المرتبات الشهرية" subtitle="حساب وإدارة رواتب الموظفين" actions={
        <div className="flex gap-2 items-center">
          <select className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={month} onChange={(e) => setMonth(parseInt(e.target.value))}>
            {Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
          </select>
          <select className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={year} onChange={(e) => setYear(parseInt(e.target.value))}>
            {[2024, 2025, 2026, 2027].map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
          <select className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={salaryBaseDays} onChange={(e) => setSalaryBaseDays(parseInt(e.target.value))}>
            <option value={28}>28 يوم</option>
            <option value={29}>29 يوم</option>
            <option value={30}>30 يوم</option>
            <option value={31}>31 يوم</option>
          </select>
          <button onClick={() => calcMut.mutate()} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">احسب</button>
        </div>
      } />

      <div className="flex-1 overflow-auto p-6">
        {!selectedPayroll && (
          <>
            {isLoading ? (
              <div className="text-center text-gray-400 py-10">جاري التحميل...</div>
            ) : payrolls.length === 0 ? (
              <div className="text-center text-gray-400 py-10">لم يتم حساب أي شهر بعد — اختر شهر واضغط "احسب"</div>
            ) : (
              <div className="bg-white rounded-lg border border-gray-200 overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 border-b border-gray-200">
                    <tr>
                      <th className="px-4 py-3 text-right font-medium text-gray-600">الشهر</th>
                      <th className="px-4 py-3 text-right font-medium text-gray-600">الحالة</th>
                      <th className="px-4 py-3 text-right font-medium text-gray-600">عدد الموظفين</th>
                      <th className="px-4 py-3 text-right font-medium text-gray-600">صافي الإجمالي</th>
                      <th className="px-4 py-3 text-right font-medium text-gray-600">الإجراءات</th>
                    </tr>
                  </thead>
                  <tbody>
                    {payrolls.map((p: any) => {
                      const totalNet = p.details?.reduce((s: number, d: any) => s + d.net_salary, 0) ?? 0;
                      return (
                        <tr key={p.id} className="border-b border-gray-100 hover:bg-gray-50">
                          <td className="px-4 py-3 font-medium">{p.month}/{p.year}</td>
                          <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded-full text-xs ${p.status === 'approved' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>{p.status === 'approved' ? 'معتمد' : 'مسودة'}</span></td>
                          <td className="px-4 py-3">{p.details?.length ?? 0}</td>
                          <td className="px-4 py-3 font-bold">{totalNet.toFixed(2)}</td>
                          <td className="px-4 py-3 flex gap-2">
                            <button onClick={() => openPayroll(p.id)} className="text-blue-600 hover:text-blue-800 text-xs">عرض</button>
                            {p.status === 'draft' && <button onClick={() => approveMut.mutate(p.id)} className="text-green-600 hover:text-green-800 text-xs">اعتماد</button>}
                            {p.status === 'draft' && <button onClick={() => { if (confirm('حذف مسودة هذا الشهر؟')) deleteMut.mutate(p.id); }} className="text-red-500 hover:text-red-700 text-xs">حذف</button>}
                            <button onClick={() => handleExportExcel(p.id)} className="text-gray-600 hover:text-gray-800 text-xs">إكسل</button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}

        {selectedPayroll && currentPayroll && (
          <div>
            <div className="flex items-center justify-between mb-4">
              <button onClick={() => setSelectedPayroll(null)} className="text-blue-600 hover:text-blue-800 text-sm">&larr; رجوع للقائمة</button>
              <div className="flex gap-2 items-center">
                <span className="text-sm text-gray-500">
                  {currentPayroll.month}/{currentPayroll.year} — 
                  <span className={`mr-1 px-2 py-0.5 rounded-full text-xs ${currentPayroll.status === 'approved' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
                    {currentPayroll.status === 'approved' ? 'معتمد' : 'مسودة'}
                  </span>
                </span>
                <span className="text-sm text-gray-500">|</span>
                <span className="text-sm text-gray-500">أساس الشهر:</span>
                <select className="border border-gray-300 rounded-lg px-2 py-1 text-xs" value={currentPayroll.salary_base_days ?? 30} onChange={(e) => updateBaseDaysMut.mutate({ id: currentPayroll.id, days: parseInt(e.target.value) })} disabled={currentPayroll.status === 'approved'}>
                  <option value={28}>28</option>
                  <option value={29}>29</option>
                  <option value={30}>30</option>
                  <option value={31}>31</option>
                </select>
                {currentPayroll.status === 'draft' && (
                  <button onClick={() => approveMut.mutate(currentPayroll.id)} className="px-3 py-1 bg-green-600 text-white text-xs rounded-lg hover:bg-green-700">اعتماد</button>
                )}
                <button onClick={() => handleExportExcel(currentPayroll.id)} className="px-3 py-1 bg-gray-100 text-gray-700 text-xs rounded-lg hover:bg-gray-200">إكسل</button>
              </div>
            </div>

            <div className="bg-white rounded-lg border border-gray-200 overflow-x-auto shadow-sm">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                  <tr>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">م</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs sticky right-0 bg-gray-50 min-w-[100px]">الاسم</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">المرتب</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">أجر اليوم</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">أجر الساعة</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">أيام عمل</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs" title="إضافي راحات (أيام)">إضافي راحات (ي)</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">تطبيق (ي)</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">غياب (ي)</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">أوفر تايم (س)</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">خصم غياب</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">إضافي راحات</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">قيمة تطبيق</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">أوفر تايم</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">مكافآت</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">سلفة</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs">إجمالي الخصم</th>
                    <th className="px-2 py-2 text-center font-medium text-blue-700 text-xs">الصافي</th>
                    <th className="px-2 py-2 text-center font-medium text-gray-600 text-xs"></th>
                  </tr>
                </thead>
                <tbody>
                  {details.map((d: any, i: number) => (
                    <React.Fragment key={d.id}>
                      <tr className="border-b border-gray-100 hover:bg-blue-50/40 transition-colors cursor-pointer" onClick={() => openBonus(d)}>
                        <td className="px-2 py-1.5 text-xs text-center text-gray-500">{i + 1}</td>
                        <td className="px-2 py-1.5 text-xs font-medium text-center sticky right-0 bg-white border-l border-gray-100">{d.employee?.name ?? '—'}</td>
                        <td className="px-2 py-1.5 text-xs text-center font-semibold">{d.base_salary_snapshot.toFixed(0)}</td>
                        <td className="px-2 py-1.5 text-xs text-center font-semibold">{d.daily_wage_snapshot.toFixed(2)}</td>
                        <td className="px-2 py-1.5 text-xs text-center font-semibold">{d.hourly_wage_snapshot.toFixed(2)}</td>
                        {renderCell(d, 'work_days', 'أيام عمل', fmt0)}
                        {renderCell(d, 'rest_day_ot_days', 'إضافي راحات', fmt0)}
                        {renderCell(d, 'double_shift_days', 'تطبيق', fmt0)}
                        {renderCell(d, 'absence_days', 'غياب', fmt0)}
                        {renderCell(d, 'overtime_hours', 'أوفر تايم', (v) => (v ?? 0).toFixed(1))}
                        {renderCell(d, 'absence_amount', 'خصم غياب', fmt2)}
                        {renderCell(d, 'rest_day_ot_amount', 'إضافي راحات', fmt2)}
                        {renderCell(d, 'double_shift_amount', 'قيمة تطبيق', fmt2)}
                        {renderCell(d, 'overtime_amount', 'أوفر تايم', fmt2)}
                        <td className="px-2 py-1.5 text-xs text-center font-semibold text-green-700">{d.bonus_total.toFixed(2)}</td>
                        {renderCell(d, 'advance_amount', 'سلفة', fmt2)}
                        <td className="px-2 py-1.5 text-xs text-center font-semibold text-red-600">{d.total_deductions.toFixed(2)}</td>
                        <td className="px-2 py-1.5 text-sm text-center font-bold text-blue-700">{d.net_salary.toFixed(2)}</td>
                        <td className="px-2 py-1.5 text-center">
                          <a href={`${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/payroll/monthly/${selectedPayroll}/payslip/${d.employee_id}`} target="_blank" className="text-xs text-blue-600 hover:text-blue-800" onClick={(e) => e.stopPropagation()}>PDF</a>
                        </td>
                      </tr>
                      {expandedEmp === d.employee_id && (
                        <tr className="bg-gray-50">
                          <td colSpan={19} className="px-4 py-3">
                            <div className="max-w-lg" onClick={(e) => e.stopPropagation()}>
                              <h4 className="text-sm font-semibold mb-2">المكافآت — {d.employee?.name}</h4>
                              {bonusItems.map((item, idx) => (
                                <div key={idx} className="flex gap-2 mb-2 items-center">
                                  <input className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm flex-1" placeholder="البيان" value={item.name} onChange={(e) => updateBonusRow(idx, 'name', e.target.value)} />
                                  <input className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm w-24" type="number" step="0.01" min="0" placeholder="القيمة" value={item.amount} onChange={(e) => updateBonusRow(idx, 'amount', e.target.value)} />
                                  <button onClick={() => removeBonusRow(idx)} className="text-red-500 text-xs px-1">×</button>
                                </div>
                              ))}
                              <div className="flex gap-2 mt-2">
                                <button onClick={addBonusRow} className="text-xs text-blue-600 hover:text-blue-800">+ إضافة بند</button>
                                <button onClick={() => saveBonus(d.id)} className="px-3 py-1 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700">حفظ المكافآت</button>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                    </React.Fragment>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
