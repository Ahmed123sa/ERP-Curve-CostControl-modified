'use client';
import React, { useState, useMemo, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { payrollApi } from '@/lib/payroll/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function PayrollAttendancePage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());
  const [popup, setPopup] = useState<{ employee: any; day: number; record?: any } | null>(null);
  const [shiftStart, setShiftStart] = useState('09:00');
  const [shiftEnd, setShiftEnd] = useState('18:00');

  const daysInMonth = new Date(year, month, 0).getDate();

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
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['payroll-attendance'] });
      qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
      setPopup(null);
      toast.success(res.message);
    },
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => payrollApi.deleteAttendance(id),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['payroll-attendance'] });
      qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
      toast.success(res.message);
    },
  });

  const recordMap = useMemo(() => {
    const map: Record<string, Record<number, any>> = {};
    records.forEach((r: any) => {
      const day = new Date(r.date).getDate();
      if (!map[r.employee_id]) map[r.employee_id] = {};
      map[r.employee_id][day] = r;
    });
    return map;
  }, [records]);

  const isFriday = useCallback((day: number) => {
    return new Date(year, month - 1, day).getDay() === 5;
  }, [year, month]);

  const openPopup = (employee: any, day: number) => {
    const record = recordMap[employee.id]?.[day];
    if (record) {
      setShiftStart(record.shift_start?.substring(0, 5) || '09:00');
      setShiftEnd(record.shift_end?.substring(0, 5) || '18:00');
    } else {
      setShiftStart('09:00');
      setShiftEnd('18:00');
    }
    setPopup({ employee, day, record });
  };

  const handleSave = () => {
    if (!popup) return;
    storeMut.mutate({
      employee_id: popup.employee.id,
      date: `${year}-${String(month).padStart(2, '0')}-${String(popup.day).padStart(2, '0')}`,
      shift_start: shiftStart,
      shift_end: shiftEnd,
    });
  };

  const handleDelete = () => {
    if (!popup?.record) return;
    if (confirm('حذف هذا التسجيل؟')) {
      deleteMut.mutate(popup.record.id);
    }
  };

  const computeHours = (start: string, end: string) => {
    const s = new Date(`2000-01-01T${start}`);
    let e = new Date(`2000-01-01T${end}`);
    if (e <= s) e = new Date(e.getTime() + 86400000);
    return ((e.getTime() - s.getTime()) / 3600000).toFixed(2);
  };

  const summary = useMemo(() => {
    let totalPresent = 0;
    let totalOvertimeMins = 0;
    let doubleShiftCount = 0;
    records.forEach((r: any) => {
      if (r.total_hours > 0) totalPresent++;
      totalOvertimeMins += r.overtime_minutes;
      if (r.is_double_shift) doubleShiftCount++;
    });
    return { totalPresent, totalOvertimeMins, doubleShiftCount };
  }, [records]);

  return (
    <div className="flex flex-col h-full">
      <PageHeader title="الحضور والانصراف" subtitle="سجل حضور الموظفين - عرض شهري" actions={
        <div className="flex gap-2 items-center">
          <select className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={month} onChange={(e) => setMonth(parseInt(e.target.value))}>
            {Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
          </select>
          <select className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={year} onChange={(e) => setYear(parseInt(e.target.value))}>
            {[2024, 2025, 2026, 2027].map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
        </div>
      } />

      <div className="flex gap-4 px-6 py-3">
        <div className="bg-white border border-gray-200 rounded-lg px-4 py-2 flex-1 text-center">
          <div className="text-xs text-gray-500">حاضر</div>
          <div className="text-lg font-bold text-green-700">{summary.totalPresent}</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-lg px-4 py-2 flex-1 text-center">
          <div className="text-xs text-gray-500">عدد التسجيلات</div>
          <div className="text-lg font-bold text-gray-700">{records.length}</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-lg px-4 py-2 flex-1 text-center">
          <div className="text-xs text-gray-500">أوفر تايم (دق)</div>
          <div className="text-lg font-bold text-blue-700">{summary.totalOvertimeMins.toFixed(0)}</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-lg px-4 py-2 flex-1 text-center">
          <div className="text-xs text-gray-500">تطبيق</div>
          <div className="text-lg font-bold text-indigo-700">{summary.doubleShiftCount}</div>
        </div>
      </div>

      <div className="flex-1 overflow-auto px-6 pb-6">
        {isLoading ? (
          <div className="text-center text-gray-400 py-10">جاري التحميل...</div>
        ) : employees.length === 0 ? (
          <div className="text-center text-gray-400 py-10">لا يوجد موظفين</div>
        ) : (
          <div className="bg-white rounded-lg border border-gray-200 overflow-auto shadow-sm" style={{ maxHeight: 'calc(100vh - 260px)' }}>
            <table className="w-full text-sm border-collapse">
              <thead>
                <tr className="sticky top-0 bg-gray-50 z-10 shadow-sm">
                  <th className="sticky right-0 bg-gray-50 px-3 py-2.5 text-right font-medium text-gray-600 border-b border-l border-gray-200 min-w-[160px] z-20">
                    الموظف
                  </th>
                  {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((day) => {
                    const fri = isFriday(day);
                    return (
                      <th key={day} className={`px-1.5 py-2 text-center font-medium text-xs border-b border-gray-200 min-w-[38px] ${fri ? 'bg-red-50 text-red-400' : 'text-gray-600'}`}>
                        {day}
                        <div className={`text-[10px] leading-tight ${fri ? 'text-red-300' : 'text-gray-400'}`}>{fri ? 'ج' : ''}</div>
                      </th>
                    );
                  })}
                </tr>
              </thead>
              <tbody>
                {employees.map((emp: any) => (
                  <tr key={emp.id} className="hover:bg-blue-50/40 transition-colors">
                    <td className="sticky right-0 bg-white px-3 py-1.5 text-sm font-medium border-b border-l border-gray-200 whitespace-nowrap z-10">
                      {emp.name}
                    </td>
                    {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((day) => {
                      const fri = isFriday(day);
                      const record = recordMap[emp.id]?.[day];
                      const present = !!record;
                      const isDoubleShift = record?.is_double_shift;

                      let bgColor = 'bg-white';
                      let textColor = 'text-gray-400';
                      let cursor = 'cursor-pointer';
                      let content = '—';

                      if (fri) {
                        bgColor = 'bg-gray-100';
                        cursor = 'cursor-default';
                        content = '';
                      } else if (present) {
                        bgColor = isDoubleShift ? 'bg-indigo-100' : 'bg-green-50';
                        textColor = isDoubleShift ? 'text-indigo-700' : 'text-green-700';
                        content = record.total_hours.toFixed(1);
                      } else {
                        textColor = 'text-gray-300';
                        content = '—';
                      }

                      return (
                        <td
                          key={day}
                          className={`px-1.5 py-1.5 text-center border-b border-gray-100 ${bgColor} ${textColor} ${cursor} text-xs font-semibold hover:ring-2 hover:ring-blue-400 hover:ring-inset transition-all`}
                          onClick={() => !fri && openPopup(emp, day)}
                        >
                          {content}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {popup && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center" onClick={() => setPopup(null)}>
          <div className="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm mx-4" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-bold text-lg">{popup.employee.name}</h3>
              <button onClick={() => setPopup(null)} className="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <div className="text-sm text-gray-500 mb-4">
              {year}/{String(month).padStart(2, '0')}/{String(popup.day).padStart(2, '0')}
            </div>
            <div className="space-y-3">
              <div>
                <label className="block text-xs text-gray-500 mb-1">بداية الوردية</label>
                <input type="time" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" value={shiftStart} onChange={(e) => setShiftStart(e.target.value)} />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">نهاية الوردية</label>
                <input type="time" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" value={shiftEnd} onChange={(e) => setShiftEnd(e.target.value)} />
              </div>
              <div className="flex gap-3 text-xs text-gray-500 bg-gray-50 rounded-lg p-2">
                <span>إجمالي: <strong className="text-blue-700">{computeHours(shiftStart, shiftEnd)} س</strong></span>
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <button onClick={handleSave} disabled={storeMut.isPending} className="flex-1 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-50">
                {storeMut.isPending ? 'جاري...' : popup.record ? 'تحديث' : 'تسجيل'}
              </button>
              {popup.record && (
                <button onClick={handleDelete} disabled={deleteMut.isPending} className="px-4 py-2 bg-red-50 text-red-600 text-sm rounded-lg hover:bg-red-100">
                  حذف
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
