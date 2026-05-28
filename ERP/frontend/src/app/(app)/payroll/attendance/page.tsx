'use client';
import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { payrollApi } from '@/lib/payroll/api';
import { PageHeader } from '@/components/ui/AppShell';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import toast from 'react-hot-toast';

export default function PayrollAttendancePage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());
  const [selEmp, setSelEmp] = useState('');
  const [date, setDate] = useState(new Date().toISOString().split('T')[0]);
  const [shiftStart, setShiftStart] = useState('09:00');
  const [shiftEnd, setShiftEnd] = useState('18:00');
  const [notes, setNotes] = useState('');

  const { data: employees = [] } = useQuery({
    queryKey: ['payroll-employees'],
    queryFn: () => payrollApi.employees(),
  });

  const { data: records = [], isLoading } = useQuery({
    queryKey: ['payroll-attendance', month, year],
    queryFn: () => payrollApi.attendance(month, year),
  });

  const storeMut = useMutation({
    mutationFn: (data: any) => payrollApi.storeAttendance(data),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: ['payroll-attendance'] }); toast.success(res.message); },
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => payrollApi.deleteAttendance(id),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: ['payroll-attendance'] }); toast.success(res.message); },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selEmp || !date || !shiftStart || !shiftEnd) return;
    storeMut.mutate({ employee_id: selEmp, date, shift_start: shiftStart, shift_end: shiftEnd, notes: notes || undefined });
  };

  const computeHours = (start: string, end: string) => {
    const s = new Date(`2000-01-01T${start}`);
    let e = new Date(`2000-01-01T${end}`);
    if (e <= s) e = new Date(e.getTime() + 86400000);
    return ((e.getTime() - s.getTime()) / 3600000).toFixed(2);
  };

  const getEmpName = (id: string) => employees.find((e: any) => e.id === id)?.name ?? id;

  // Summary
  const totalWorkDays = new Set(records.filter((r: any) => r.total_hours > 0).map((r: any) => r.employee_id + '-' + r.date)).size;
  const totalOvertimeMins = records.reduce((s: number, r: any) => s + r.overtime_minutes, 0);

  return (
    <div className="flex flex-col h-full">
      <PageHeader title="الحضور والانصراف" subtitle="تسجيل الحضور اليومي للموظفين" actions={
        <div className="flex gap-2 items-center">
          <select className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={month} onChange={(e) => setMonth(parseInt(e.target.value))}>
            {Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
          </select>
          <select className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={year} onChange={(e) => setYear(parseInt(e.target.value))}>
            {[2024, 2025, 2026, 2027].map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
        </div>
      } />

      <div className="flex-1 overflow-auto p-6">
        {/* Form */}
        <form onSubmit={handleSubmit} className="bg-white border border-gray-200 rounded-lg p-4 mb-6 flex flex-wrap gap-3 items-end">
          <div>
            <label className="block text-xs text-gray-500 mb-1">الموظف</label>
            <select className="border border-gray-300 rounded-lg px-3 py-2 text-sm min-w-[180px]" value={selEmp} onChange={(e) => setSelEmp(e.target.value)} required>
              <option value="">اختر...</option>
              {employees.map((e: any) => <option key={e.id} value={e.id}>{e.name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">التاريخ</label>
            <input type="date" className="border border-gray-300 rounded-lg px-3 py-2 text-sm" value={date} onChange={(e) => setDate(e.target.value)} required />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">بداية</label>
            <input type="time" className="border border-gray-300 rounded-lg px-3 py-2 text-sm" value={shiftStart} onChange={(e) => setShiftStart(e.target.value)} required />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">انتهاء</label>
            <input type="time" className="border border-gray-300 rounded-lg px-3 py-2 text-sm" value={shiftEnd} onChange={(e) => setShiftEnd(e.target.value)} required />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">مجموع ساعات</label>
            <div className="px-3 py-2 text-sm font-bold text-blue-700 bg-blue-50 rounded-lg min-w-[60px] text-center">
              {selEmp && shiftStart && shiftEnd ? computeHours(shiftStart, shiftEnd) : '0.00'}
            </div>
          </div>
          <button type="submit" className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">تسجيل</button>
        </form>

        {/* Summary */}
        <div className="flex gap-4 mb-4">
          <div className="bg-white border border-gray-200 rounded-lg px-4 py-3 flex-1 text-center">
            <div className="text-xs text-gray-500">إجمالي أيام العمل</div>
            <div className="text-lg font-bold">{totalWorkDays}</div>
          </div>
          <div className="bg-white border border-gray-200 rounded-lg px-4 py-3 flex-1 text-center">
            <div className="text-xs text-gray-500">إجمالي الأوفر تايم (دقائق)</div>
            <div className="text-lg font-bold">{totalOvertimeMins.toFixed(0)}</div>
          </div>
          <div className="bg-white border border-gray-200 rounded-lg px-4 py-3 flex-1 text-center">
            <div className="text-xs text-gray-500">عدد التسجيلات</div>
            <div className="text-lg font-bold">{records.length}</div>
          </div>
        </div>

        {/* Records table */}
        {isLoading ? (
          <div className="text-center text-gray-400 py-10">جاري التحميل...</div>
        ) : records.length === 0 ? (
          <div className="text-center text-gray-400 py-10">لا توجد تسجيلات حضور لهذا الشهر</div>
        ) : (
          <div className="bg-white rounded-lg border border-gray-200 overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">الموظف</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">التاريخ</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">بداية</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">انتهاء</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">إجمالي ساعات</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">أوفر تايم (دق)</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">شيفت مزدوج</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">إجراءات</th>
                </tr>
              </thead>
              <tbody>
                {records.map((r: any) => (
                  <tr key={r.id} className="border-b border-gray-100 hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium">{r.employee?.name ?? getEmpName(r.employee_id)}</td>
                    <td className="px-4 py-3">{r.date}</td>
                    <td className="px-4 py-3">{r.shift_start?.substring(0, 5)}</td>
                    <td className="px-4 py-3">{r.shift_end?.substring(0, 5)}</td>
                    <td className="px-4 py-3 font-bold">{r.total_hours}</td>
                    <td className="px-4 py-3">{r.overtime_minutes > 0 ? r.overtime_minutes : '—'}</td>
                    <td className="px-4 py-3">{r.is_double_shift ? '✓' : '—'}</td>
                    <td className="px-4 py-3">
                      <button onClick={() => { if (confirm('حذف؟')) deleteMut.mutate(r.id); }} className="text-red-500 hover:text-red-700 text-xs">حذف</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
