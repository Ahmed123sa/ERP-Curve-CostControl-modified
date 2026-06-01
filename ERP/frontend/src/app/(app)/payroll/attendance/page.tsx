'use client';
import React, { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { payrollApi } from '@/lib/payroll/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function PayrollAttendancePage() {
  const qc = useQueryClient();
  const now = new Date();
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());
  const [selectedEmpId, setSelectedEmpId] = useState<string | null>(null);
  const [popup, setPopup] = useState<{ employee: any; day: number; record?: any } | null>(null);
  const [shiftStart, setShiftStart] = useState('09:00');
  const [shiftEnd, setShiftEnd] = useState('18:00');

  const daysInMonth = new Date(year, month, 0).getDate();

  const { data: employees = [] } = useQuery({
    queryKey: ['payroll-employees'],
    queryFn: () => payrollApi.employees(),
  });

  const { data: records = [], isLoading } = useQuery({
    queryKey: ['payroll-attendance', month, year, selectedEmpId],
    queryFn: () => payrollApi.attendance(month, year, selectedEmpId ?? undefined),
    enabled: !!selectedEmpId,
  });

  const { data: advances = { total: 0, daily: {} } } = useQuery({
    queryKey: ['payroll-employee-advances', month, year, selectedEmpId],
    queryFn: () => payrollApi.employeeAdvances(selectedEmpId!, month, year),
    enabled: !!selectedEmpId,
  });

  const storeMut = useMutation({
    mutationFn: (data: any) => payrollApi.storeAttendance(data),
    onSuccess: (res) => {
      const newRecord = res.record;
      qc.setQueryData(['payroll-attendance', month, year, selectedEmpId], (old: any[]) => {
        if (!old) return [newRecord];
        const idx = old.findIndex((r: any) => r.date === newRecord.date);
        if (idx >= 0) {
          const updated = [...old];
          updated[idx] = newRecord;
          return updated;
        }
        return [...old, newRecord];
      });
      qc.invalidateQueries({ queryKey: ['payroll-attendance'] });
      qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
      setPopup(null);
      toast.success(res.message);
    },
    onError: (err: any) => {
      const msg = err?.response?.data?.message || err?.message || 'حدث خطأ أثناء التسجيل';
      toast.error(msg);
    },
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => payrollApi.deleteAttendance(id),
    onSuccess: (res, deletedId) => {
      qc.setQueryData(['payroll-attendance', month, year, selectedEmpId], (old: any[]) => {
        if (!old) return [];
        return old.filter((r: any) => r.id !== deletedId);
      });
      qc.invalidateQueries({ queryKey: ['payroll-attendance'] });
      qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
      setPopup(null);
      toast.success(res.message);
    },
    onError: (err: any) => {
      const msg = err?.response?.data?.message || err?.message || 'حدث خطأ أثناء الحذف';
      toast.error(msg);
    },
  });

  const dayFromDate = (dateStr: string) => {
    const parts = dateStr.split(/[-T\s]/);
    return parseInt(parts[2], 10);
  };

  const recordMap = useMemo(() => {
    const map: Record<number, any> = {};
    records.forEach((r: any) => {
      const day = dayFromDate(r.date);
      if (!isNaN(day)) map[day] = r;
    });
    return map;
  }, [records]);

  const selectedEmployee = useMemo(() => {
    return employees.find((e: any) => e.id === selectedEmpId) ?? null;
  }, [employees, selectedEmpId]);

  const openPopup = (day: number) => {
    if (!selectedEmployee) return;
    const record = recordMap[day];
    if (record) {
      setShiftStart(record.shift_start?.substring(0, 5) || '09:00');
      setShiftEnd(record.shift_end?.substring(0, 5) || '18:00');
    } else {
      setShiftStart('09:00');
      setShiftEnd('18:00');
    }
    setPopup({ employee: selectedEmployee, day, record });
  };

  const handleSave = () => {
    if (!popup || !selectedEmployee) return;
    storeMut.mutate({
      employee_id: selectedEmployee.id,
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
    return ((e.getTime() - s.getTime()) / 3600000);
  };

  const dayNames = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

  const getShiftType = (record: any, shiftHours: number) => {
    if (!record) return null;
    const totalHours = record.total_hours;
    const overtime = Math.max(totalHours - shiftHours, 0);
    if (record.is_double_shift) return { type: 'تطبيق', color: 'bg-indigo-100 text-indigo-700' };
    if (overtime > 0) return { type: 'إضافي', color: 'bg-amber-50 text-amber-700' };
    if (totalHours > 0) return { type: 'عادي', color: 'bg-green-50 text-green-700' };
    return null;
  };

  const summary = useMemo(() => {
    const shiftHours = selectedEmployee?.shift_hours ?? 9;
    let totalPresent = 0, totalHours = 0, totalOvertimeMins = 0, doubleShiftCount = 0;
    records.forEach((r: any) => {
      if (r.total_hours > 0) totalPresent++;
      totalHours += r.total_hours;
      totalOvertimeMins += r.overtime_minutes;
      if (r.is_double_shift) doubleShiftCount++;
    });
    const overtimeHours = totalOvertimeMins / 60;
    const restDayOT = Math.max(4 - (daysInMonth - totalPresent), 0);
    return { totalPresent, totalHours, totalOvertimeMins, overtimeHours, doubleShiftCount, restDayOT };
  }, [records, daysInMonth, selectedEmployee]);

  const handleExport = async () => {
    if (!selectedEmpId) return;
    try {
      const res = await api.get('/payroll/attendance/export', {
        params: { month, year, employee_id: selectedEmpId },
        responseType: 'blob',
      });
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const link = document.createElement('a');
      link.href = url;
      link.download = `حضور_وانصراف_${selectedEmployee?.name}_${month}_${year}.xlsx`;
      link.click();
      window.URL.revokeObjectURL(url);
      toast.success('تم التصدير');
    } catch (err: any) {
      let msg = err?.message || 'فشل التصدير';
      if (err?.response?.data instanceof Blob) {
        try {
          const text = await err.response.data.text();
          const json = JSON.parse(text);
          msg = json.message || msg;
        } catch { /* ignore */ }
      } else {
        msg = err?.response?.data?.message || msg;
      }
      toast.error(msg);
    }
  };

  return (
    <div className="flex flex-col h-full">
      <PageHeader title="الحضور والانصراف" subtitle="اختر موظفاً لعرض وتسجيل حضوره" actions={
        <div className="flex gap-2 items-center flex-wrap">
          <select className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={month} onChange={(e) => { setMonth(parseInt(e.target.value)); setSelectedEmpId(null); }}>
            {Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
          </select>
          <select className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={year} onChange={(e) => { setYear(parseInt(e.target.value)); setSelectedEmpId(null); }}>
            {[2024, 2025, 2026, 2027].map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
          {selectedEmpId && (
            <button onClick={handleExport} className="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">
              <svg className="inline w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
              تصدير إكسل
            </button>
          )}
        </div>
      } />

      {/* Employee cards */}
      <div className="px-6 py-3">
        <div className="flex flex-wrap gap-2">
          {employees.map((emp: any) => {
            const isSelected = emp.id === selectedEmpId;
            return (
              <button
                key={emp.id}
                onClick={() => setSelectedEmpId(isSelected ? null : emp.id)}
                className={`px-4 py-3 rounded-xl border-2 text-right transition-all min-w-[150px] flex-1 basis-[160px] ${
                  isSelected
                    ? 'border-blue-500 bg-blue-50 shadow-md'
                    : 'border-gray-200 bg-white hover:border-blue-300 hover:shadow-sm'
                }`}
              >
                <div className="font-semibold text-sm text-gray-800">{emp.name}</div>
                <div className="text-xs text-gray-400 mt-0.5">{emp.job_title ?? '—'}</div>
                {isSelected && (
                  <div className="text-[10px] text-blue-600 mt-1 font-medium">✓ مختار</div>
                )}
              </button>
            );
          })}
        </div>
      </div>

      {!selectedEmpId || !selectedEmployee ? (
        employees.length > 0 ? (
          <div className="flex-1 flex items-center justify-center text-gray-400 text-lg">اختر موظفاً من القائمة أعلاه</div>
        ) : (
          <div className="flex-1 flex items-center justify-center text-gray-400 text-lg">لا يوجد موظفين</div>
        )
      ) : (
        <>
          {/* Summary card */}
          <div className="px-6 mb-3">
            <div className="bg-gradient-to-l from-blue-50 to-indigo-50 border border-blue-200 rounded-xl p-4 shadow-sm">
              <div className="flex items-center justify-between mb-3">
                <div>
                  <span className="font-bold text-base text-gray-800">{selectedEmployee.name}</span>
                  <span className="text-xs text-gray-500 mr-2">{selectedEmployee.job_title ?? ''}</span>
                </div>
                <div className="text-xs text-gray-400">{month}/{year}</div>
              </div>
              <div className="grid grid-cols-6 gap-3 text-center">
                <div className="bg-white/70 rounded-lg px-2 py-1.5">
                  <div className="text-[10px] text-gray-400">أيام العمل</div>
                  <div className="text-sm font-bold text-green-700">{summary.totalPresent} / {daysInMonth}</div>
                </div>
                <div className="bg-white/70 rounded-lg px-2 py-1.5">
                  <div className="text-[10px] text-gray-400">إجمالي الساعات</div>
                  <div className="text-sm font-bold text-gray-700">{summary.totalHours.toFixed(1)}</div>
                </div>
                <div className="bg-white/70 rounded-lg px-2 py-1.5">
                  <div className="text-[10px] text-gray-400">أوفر تايم (س)</div>
                  <div className="text-sm font-bold text-blue-700">{summary.overtimeHours.toFixed(1)}</div>
                </div>
                <div className="bg-white/70 rounded-lg px-2 py-1.5">
                  <div className="text-[10px] text-gray-400">تطبيق</div>
                  <div className="text-sm font-bold text-indigo-700">{summary.doubleShiftCount}</div>
                </div>
                <div className="bg-white/70 rounded-lg px-2 py-1.5">
                  <div className="text-[10px] text-gray-400">إضافي راحات (ي)</div>
                  <div className="text-sm font-bold text-amber-700">{summary.restDayOT}</div>
                </div>
                <div className="bg-white/70 rounded-lg px-2 py-1.5">
                  <div className="text-[10px] text-gray-400">السلف</div>
                  <div className="text-sm font-bold text-rose-700">{advances.total.toFixed(2)}</div>
                </div>
              </div>
            </div>
          </div>

          {/* Data table */}
          <div className="flex-1 overflow-auto px-6 pb-6">
            {isLoading ? (
              <div className="text-center text-gray-400 py-10">جاري التحميل...</div>
            ) : (
              <div className="bg-white rounded-xl border border-gray-200 overflow-auto shadow-sm" style={{ maxHeight: 'calc(100vh - 480px)' }}>
                <table className="w-full text-sm border-collapse">
                  <thead>
                    <tr className="sticky top-0 z-10 bg-gradient-to-l from-gray-800 to-gray-700 text-white shadow-sm">
                      <th className="px-3 py-2.5 text-center font-medium text-[11px] border-b border-gray-600 min-w-[50px]">#</th>
                      <th className="px-3 py-2.5 text-center font-medium text-[11px] border-b border-gray-600 min-w-[50px]">التاريخ</th>
                      <th className="px-3 py-2.5 text-center font-medium text-[11px] border-b border-gray-600 min-w-[40px]">اليوم</th>
                      <th className="px-3 py-2.5 text-center font-medium text-[11px] border-b border-gray-600 min-w-[70px]">البداية</th>
                      <th className="px-3 py-2.5 text-center font-medium text-[11px] border-b border-gray-600 min-w-[70px]">النهاية</th>
                      <th className="px-3 py-2.5 text-center font-medium text-[11px] border-b border-gray-600 min-w-[55px]">إجمالي</th>
                      <th className="px-3 py-2.5 text-center font-medium text-[11px] border-b border-gray-600 min-w-[60px]">النوع</th>
                      <th className="px-3 py-2.5 text-center font-medium text-[11px] border-b border-gray-600 min-w-[50px]">أوفر</th>
                    </tr>
                  </thead>
                  <tbody>
                    {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((day) => {
                      const record = recordMap[day];
                      const shiftHours = selectedEmployee.shift_hours ?? 9;
                      const shiftType = getShiftType(record, shiftHours);
                      const present = !!record;
                      const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                      const d = new Date(dateStr);
                      const dayName = dayNames[d.getDay()];

                      return (
                        <tr
                          key={day}
                          onClick={() => openPopup(day)}
                          className={`border-b border-gray-100 cursor-pointer transition-all ${
                            present ? 'hover:bg-blue-50/60' : 'hover:bg-gray-50'
                          } ${day % 2 === 0 ? 'bg-white' : 'bg-gray-50/30'}`}
                        >
                          <td className="px-3 py-2 text-center text-xs text-gray-400 font-medium">{day}</td>
                          <td className="px-3 py-2 text-center text-xs text-gray-600">{dateStr}</td>
                          <td className={`px-3 py-2 text-center text-[11px] font-medium ${d.getDay() === 5 ? 'text-red-400' : 'text-gray-500'}`}>{dayName}</td>
                          <td className="px-3 py-2 text-center text-xs font-medium text-gray-700">{record?.shift_start?.substring(0, 5) ?? '—'}</td>
                          <td className="px-3 py-2 text-center text-xs font-medium text-gray-700">{record?.shift_end?.substring(0, 5) ?? '—'}</td>
                          <td className={`px-3 py-2 text-center text-xs font-bold ${present ? 'text-gray-800' : 'text-gray-300'}`}>
                            {present ? record.total_hours.toFixed(1) : '—'}
                          </td>
                          <td className="px-3 py-2 text-center">
                            {shiftType ? (
                              <span className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold ${shiftType.color}`}>
                                {shiftType.type}
                              </span>
                            ) : (
                              <span className="text-[10px] text-gray-300">—</span>
                            )}
                          </td>
                          <td className={`px-3 py-2 text-center text-xs font-semibold ${record?.is_double_shift ? 'text-indigo-600' : record?.overtime_minutes > 0 ? 'text-blue-600' : 'text-gray-300'}`}>
                            {record?.is_double_shift ? 'تطبيق' : record?.overtime_minutes > 0 ? (record.overtime_minutes / 60).toFixed(1) : '—'}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </>
      )}

      {popup && selectedEmployee && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center" onClick={() => setPopup(null)}>
          <div className="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm mx-4" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-bold text-lg">{selectedEmployee.name}</h3>
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
                <span>إجمالي: <strong className="text-blue-700">{computeHours(shiftStart, shiftEnd).toFixed(2)} س</strong></span>
                <span>أوفر تايم: <strong className="text-green-700">{Math.max(0, computeHours(shiftStart, shiftEnd) - (selectedEmployee.shift_hours ?? 9)).toFixed(2)} س</strong></span>
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