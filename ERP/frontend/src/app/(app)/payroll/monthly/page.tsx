'use client';
import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { payrollApi } from '@/lib/payroll/api';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function PayrollMonthlyPage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());
  const [selectedPayroll, setSelectedPayroll] = useState<string | null>(null);
  const [expandedEmp, setExpandedEmp] = useState<string | null>(null);
  const [bonusItems, setBonusItems] = useState<{ name: string; amount: string }[]>([]);

  const { data: payrolls = [], isLoading } = useQuery({
    queryKey: ['payroll-monthly'],
    queryFn: () => payrollApi.payrolls(),
  });

  const { data: payrollDetail, refetch: refetchDetail } = useQuery({
    queryKey: ['payroll-monthly-detail', selectedPayroll],
    queryFn: () => payrollApi.showPayroll(selectedPayroll!),
    enabled: !!selectedPayroll,
  });

  const calcMut = useMutation({
    mutationFn: () => payrollApi.calculatePayroll(month, year),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: ['payroll-monthly'] }); toast.success(res.message); },
  });

  const approveMut = useMutation({
    mutationFn: (id: string) => payrollApi.approvePayroll(id),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: ['payroll-monthly'] }); toast.success(res.message); },
  });

  const bonusMut = useMutation({
    mutationFn: ({ detailId, items }: { detailId: string; items: any[] }) => payrollApi.updateBonus(detailId, items),
    onSuccess: (res) => { toast.success(res.message); refetchDetail(); },
  });

  const openPayroll = (id: string) => {
    setSelectedPayroll(id);
    setExpandedEmp(null);
    setBonusItems([]);
  };

  const openBonus = (detail: any) => {
    setExpandedEmp(detail.employee_id);
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

  const handleExportExcel = (payrollId: string) => {
    const token = localStorage.getItem('erp_token');
    const link = document.createElement('a');
    link.href = `${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/payroll/monthly/${payrollId}/export-excel?token=${token}`;
    link.click();
    toast.success('جاري التصدير...');
  };

  const currentPayroll = payrollDetail?.details ? payrollDetail : payrolls.find((p: any) => p.id === selectedPayroll);
  const details = currentPayroll?.details ?? [];

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
          <button onClick={() => calcMut.mutate()} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">احسب</button>
        </div>
      } />

      <div className="flex-1 overflow-auto p-6">
        {/* Payroll months list */}
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

        {/* Single payroll detail */}
        {selectedPayroll && currentPayroll && (
          <div>
            <button onClick={() => setSelectedPayroll(null)} className="text-blue-600 hover:text-blue-800 text-sm mb-4">&larr; رجوع للقائمة</button>
            <div className="bg-white rounded-lg border border-gray-200 overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">م</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">الاسم</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">المرتب</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">أيام عمل</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">غياب</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">خصم غياب</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">إضافي راحات</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">أوفر تايم</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">مكافآت</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">سلفة</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">خصم</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">الصافي</th>
                    <th className="px-3 py-2 text-right font-medium text-gray-600 text-xs">إجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {details.map((d: any, i: number) => (
                    <React.Fragment key={d.id}>
                      <tr className="border-b border-gray-100 hover:bg-gray-50 cursor-pointer" onClick={() => openBonus(d)}>
                        <td className="px-3 py-2">{i + 1}</td>
                        <td className="px-3 py-2 font-medium">{d.employee?.name ?? '—'}</td>
                        <td className="px-3 py-2">{d.base_salary_snapshot.toFixed(0)}</td>
                        <td className="px-3 py-2">{d.work_days}</td>
                        <td className="px-3 py-2">{d.absence_days}</td>
                        <td className="px-3 py-2">{d.absence_amount.toFixed(2)}</td>
                        <td className="px-3 py-2">{d.rest_day_ot_amount.toFixed(2)}</td>
                        <td className="px-3 py-2">{d.overtime_amount.toFixed(2)}</td>
                        <td className="px-3 py-2">{d.bonus_total.toFixed(2)}</td>
                        <td className="px-3 py-2">{d.advance_amount.toFixed(2)}</td>
                        <td className="px-3 py-2">{d.total_deductions.toFixed(2)}</td>
                        <td className="px-3 py-2 font-bold text-blue-700">{d.net_salary.toFixed(2)}</td>
                        <td className="px-3 py-2 flex gap-1">
                          <a href={`${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/payroll/monthly/${selectedPayroll}/payslip/${d.employee_id}`} target="_blank" className="text-xs text-blue-600 hover:text-blue-800" onClick={(e) => { e.stopPropagation(); }}>PDF</a>
                        </td>
                      </tr>
                      {/* Bonus items row (expanded card) */}
                      {expandedEmp === d.employee_id && (
                        <tr className="bg-gray-50">
                          <td colSpan={13} className="px-4 py-3">
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
