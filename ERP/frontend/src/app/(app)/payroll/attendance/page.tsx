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
  const [painting, setPainting] = useState<{ field: 'start' | 'end'; value: string; day: number } | null>(null);
  const [showBatch, setShowBatch] = useState(false);
  const [batchFrom, setBatchFrom] = useState(1);
  const [batchTo, setBatchTo] = useState(31);
  const [batchStart, setBatchStart] = useState('09:00');
  const [batchEnd, setBatchEnd] = useState('18:00');

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

  const paintSave = async (field: 'start' | 'end', day: number) => {
    if (!painting || !selectedEmpId) return;
    const targetRecord = recordMap[day];
    const start = field === 'start' ? painting.value : (targetRecord?.shift_start?.substring(0, 5) || '09:00');
    const end = field === 'end' ? painting.value : (targetRecord?.shift_end?.substring(0, 5) || '18:00');
    try {
      await payrollApi.storeAttendance({
        employee_id: selectedEmpId,
        date: `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`,
        shift_start: start,
        shift_end: end,
      });
      qc.invalidateQueries({ queryKey: ['payroll-attendance', month, year, selectedEmpId] });
      qc.invalidateQueries({ queryKey: ['payroll-attendance'] });
      qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'فشل الحفظ');
    }
  };

  const handlePaintClick = (field: 'start' | 'end', record: any, day: number, e: React.MouseEvent) => {
    e.stopPropagation();
    if (!record) return;
    const value = field === 'start'
      ? (record.shift_start?.substring(0, 5) || '09:00')
      : (record.shift_end?.substring(0, 5) || '18:00');
    if (painting && painting.field === field && painting.day === day) {
      setPainting(null);
      return;
    }
    if (painting && painting.field === field && painting.day !== day) {
      paintSave(field, day);
      return;
    }
    setPainting({ field, value, day });
  };

  const handleSave = () => {
    if (!popup || !selectedEmployee) return;
    const start = parseSmartTime(shiftStart) || shiftStart;
    const end = parseSmartTime(shiftEnd) || shiftEnd;
    storeMut.mutate({
      employee_id: selectedEmployee.id,
      date: `${year}-${String(month).padStart(2, '0')}-${String(popup.day).padStart(2, '0')}`,
      shift_start: start,
      shift_end: end,
    });
  };

  const handleDelete = () => {
    if (!popup?.record) return;
    if (confirm('حذف هذا التسجيل؟')) {
      deleteMut.mutate(popup.record.id);
    }
  };

  const [batchSaving, setBatchSaving] = useState(false);
  const [batchDone, setBatchDone] = useState(0);

  const handleBatchSave = async () => {
    if (!selectedEmpId) return;
    const start = parseSmartTime(batchStart) || batchStart;
    const end = parseSmartTime(batchEnd) || batchEnd;
    const from = Math.max(1, batchFrom);
    const to = Math.min(daysInMonth, batchTo);
    if (from > to) return toast.error('نطاق غير صحيح');
    setBatchSaving(true);
    setBatchDone(0);
    const total = to - from + 1;
    let success = 0;
    for (let day = from; day <= to; day++) {
      try {
        await payrollApi.storeAttendance({
          employee_id: selectedEmpId,
          date: `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`,
          shift_start: start,
          shift_end: end,
        });
        success++;
      } catch { /* skip */ }
      setBatchDone(day - from + 1);
    }
    qc.invalidateQueries({ queryKey: ['payroll-attendance', month, year, selectedEmpId] });
    qc.invalidateQueries({ queryKey: ['payroll-attendance'] });
    qc.invalidateQueries({ queryKey: ['payroll-monthly'] });
    setBatchSaving(false);
    if (success === total) toast.success(`تم تسجيل ${total} أيام`);
    else toast.success(`تم تسجيل ${success} من ${total} أيام`);
  };

  const computeHours = (start: string, end: string) => {
    const s = new Date(`2000-01-01T${start}`);
    let e = new Date(`2000-01-01T${end}`);
    if (e <= s) e = new Date(e.getTime() + 86400000);
    return ((e.getTime() - s.getTime()) / 3600000);
  };

  const parseSmartTime = (val: string): string | null => {
    const s = val.trim().toLowerCase().replace(/\s+/g, '');
    if (!s) return null;
    if (/^\d{1,2}:\d{2}$/.test(s)) {
      const [h, m] = s.split(':').map(Number);
      if (h >= 0 && h <= 23 && m >= 0 && m <= 59) return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
      return null;
    }
    const isPm = s.endsWith('p') || s.endsWith('pm');
    const numStr = s.replace(/pm?$/, '');
    const parts = numStr.split(/[.,:]/);
    let h = parseInt(parts[0], 10);
    let m = parts[1] ? parseInt(parts[1].padEnd(2, '0').substring(0, 2), 10) : 0;
    if (isNaN(h)) return null;
    if (isNaN(m)) m = 0;
    if (isPm && h < 12) h += 12;
    if (!isPm && h === 12) h = 0;
    if (h < 0 || h > 23 || m < 0 || m > 59) return null;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
  };

  const dayNames = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

  const getShiftType = (record: any, shiftHours: number) => {
    if (!record) return null;
    const totalHours = record.total_hours;
    const diff = totalHours - shiftHours;
    if (record.is_double_shift) return { type: 'تطبيق', color: 'bg-indigo-100 text-indigo-700' };
    if (diff > 0) return { type: 'إضافي', color: 'bg-amber-50 text-amber-700' };
    if (diff < 0) return { type: 'عجز', color: 'bg-red-50 text-red-700' };
    if (totalHours > 0) return { type: 'عادي', color: 'bg-green-50 text-green-700' };
    return null;
  };

  const summary = useMemo(() => {
    const shiftHours = selectedEmployee?.shift_hours ?? 9;
    let totalPresent = 0, totalHours = 0, netDiffMins = 0, doubleShiftCount = 0;
    records.forEach((r: any) => {
      if (r.total_hours > 0) totalPresent++;
      totalHours += r.total_hours;
      if (r.is_double_shift) doubleShiftCount++;
      else netDiffMins += (r.total_hours - shiftHours) * 60;
    });
    const overtimeHours = netDiffMins / 60;
    const restDayOT = Math.max(4 - (daysInMonth - totalPresent), 0);
    return { totalPresent, totalHours, netDiffMins, overtimeHours, doubleShiftCount, restDayOT };
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
            <>
              <button onClick={() => setShowBatch(!showBatch)} className={`px-3 py-1.5 text-sm rounded-lg border transition-all ${showBatch ? 'bg-green-100 border-green-300 text-green-700' : 'bg-white border-gray-300 text-gray-600 hover:border-green-300'}`}>
                <svg className="inline w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                تسجيل متعدد
              </button>
              <button onClick={handleExport} className="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">
                <svg className="inline w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                تصدير إكسل
              </button>
            </>
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

      {/* Batch panel */}
      {showBatch && selectedEmpId && (
        <div className="px-6 mb-3 animate-fade-in">
          <div className="bg-green-50 border border-green-200 rounded-xl p-4 shadow-sm">
            <div className="flex items-center gap-4 flex-wrap">
              <div className="flex items-center gap-2 text-sm">
                <span className="text-gray-500">من يوم</span>
                <input type="number" min={1} max={daysInMonth} className="w-16 border border-green-300 rounded-lg px-2 py-1.5 text-sm text-center" value={batchFrom} onChange={(e) => setBatchFrom(Math.max(1, Math.min(daysInMonth, parseInt(e.target.value) || 1)))} />
                <span className="text-gray-500">إلى</span>
                <input type="number" min={1} max={daysInMonth} className="w-16 border border-green-300 rounded-lg px-2 py-1.5 text-sm text-center" value={batchTo} onChange={(e) => setBatchTo(Math.max(1, Math.min(daysInMonth, parseInt(e.target.value) || daysInMonth)))} />
              </div>
              <div className="w-px h-6 bg-green-200" />
              <input type="text" inputMode="numeric" className="w-20 border border-green-300 rounded-lg px-2 py-1.5 text-sm text-left dir-ltr font-mono" placeholder="9 / 8.30p" value={batchStart} onChange={(e) => setBatchStart(e.target.value)} onBlur={() => { const p = parseSmartTime(batchStart); if (p) setBatchStart(p); }} />
              <span className="text-xs text-gray-400">→</span>
              <input type="text" inputMode="numeric" className="w-20 border border-green-300 rounded-lg px-2 py-1.5 text-sm text-left dir-ltr font-mono" placeholder="6 / 5.30p" value={batchEnd} onChange={(e) => setBatchEnd(e.target.value)} onBlur={() => { const p = parseSmartTime(batchEnd); if (p) setBatchEnd(p); }} />
              <button onClick={handleBatchSave} disabled={batchSaving} className="px-4 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 disabled:opacity-50">
                {batchSaving ? `جاري الحفظ ${batchDone}/${batchTo - batchFrom + 1}` : 'تسجيل الأيام المحددة'}
              </button>
              <div className="flex gap-1.5 flex-wrap mr-auto">
                {[
                  { label: 'الشهر كامل', fn: () => { setBatchFrom(1); setBatchTo(daysInMonth); } },
                  { label: 'كل الأحد', fn: () => { const firstSun = 1 + (7 - new Date(year, month - 1, 1).getDay()) % 7; setBatchFrom(firstSun); setBatchTo(firstSun + Math.floor((daysInMonth - firstSun) / 7) * 7); } },
                  { label: 'كل السبت', fn: () => { const firstSat = 1 + (6 - new Date(year, month - 1, 1).getDay() + 7) % 7; setBatchFrom(firstSat); setBatchTo(firstSat + Math.floor((daysInMonth - firstSat) / 7) * 7); } },
                ].map((btn) => (
                  <button key={btn.label} type="button" onClick={btn.fn} className="px-2 py-1 text-[11px] rounded-lg border border-green-200 text-green-600 hover:bg-green-100">
                    {btn.label}
                  </button>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}

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
                  <div className="text-[10px] text-gray-400">صافي الفرق (س)</div>
                  <div className={`text-sm font-bold ${summary.overtimeHours >= 0 ? 'text-blue-700' : 'text-red-600'}`}>{summary.overtimeHours.toFixed(1)}</div>
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

          {painting && (
            <div className="px-6 mb-2">
              <div className="inline-flex items-center gap-2 bg-blue-50 border border-blue-200 rounded-lg px-3 py-1.5 text-xs text-blue-700">
                <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.5 2.5c-.8-.8-2.1-.8-2.8 0l-1.4 1.4 2.8 2.8 1.4-1.4c.8-.8.8-2 0-2.8zM3 17.3V21h3.7l11-11-3.7-3.7L3 17.3z"/></svg>
                <span>الفرشاة: <strong>{painting.value}</strong> — اضغط على {painting.field === 'start' ? 'البداية' : 'النهاية'} لأي يوم للصق</span>
                <button onClick={() => setPainting(null)} className="text-blue-400 hover:text-blue-700 mr-1">&times;</button>
              </div>
            </div>
          )}

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
                            onClick={() => { if (!painting) openPopup(day); }}
                            className={`group border-b border-gray-100 cursor-pointer transition-all ${
                            present ? 'hover:bg-blue-50/60' : 'hover:bg-gray-50'
                          } ${day % 2 === 0 ? 'bg-white' : 'bg-gray-50/30'}`}
                        >
                          <td className="px-3 py-2 text-center text-xs text-gray-400 font-medium">{day}</td>
                          <td className="px-3 py-2 text-center text-xs text-gray-600">{dateStr}</td>
                          <td className={`px-3 py-2 text-center text-[11px] font-medium ${d.getDay() === 5 ? 'text-red-400' : 'text-gray-500'}`}>{dayName}</td>
                          <td className="px-3 py-2 text-center">
                            <div className="inline-flex items-center gap-0.5">
                              <span onClick={(e) => { e.stopPropagation(); if (painting?.field === 'start' && painting.day !== day) { paintSave('start', day); } else if (!painting) { openPopup(day); } }} className={`text-xs font-medium rounded px-1 cursor-pointer transition-all ${record?.shift_start ? 'text-gray-700' : 'text-gray-300'} ${painting?.field === 'start' && painting.day !== day ? 'hover:bg-blue-100' : 'hover:bg-gray-100'}`}>
                                {record?.shift_start?.substring(0, 5) ?? '—'}
                              </span>
                              {record && (
                                <button onClick={(e) => handlePaintClick('start', record, day, e)} className={`p-0.5 rounded transition-all ${painting?.field === 'start' && painting?.day === day ? 'bg-blue-100 text-blue-600 ring-2 ring-blue-400' : 'opacity-0 group-hover:opacity-100 text-gray-300 hover:text-blue-500 hover:bg-blue-50'}`}>
                                  <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.5 2.5c-.8-.8-2.1-.8-2.8 0l-1.4 1.4 2.8 2.8 1.4-1.4c.8-.8.8-2 0-2.8zM3 17.3V21h3.7l11-11-3.7-3.7L3 17.3z"/></svg>
                                </button>
                              )}
                            </div>
                          </td>
                          <td className="px-3 py-2 text-center">
                            <div className="inline-flex items-center gap-0.5">
                              <span onClick={(e) => { e.stopPropagation(); if (painting?.field === 'end' && painting.day !== day) { paintSave('end', day); } else if (!painting) { openPopup(day); } }} className={`text-xs font-medium rounded px-1 cursor-pointer transition-all ${record?.shift_end ? 'text-gray-700' : 'text-gray-300'} ${painting?.field === 'end' && painting.day !== day ? 'hover:bg-blue-100' : 'hover:bg-gray-100'}`}>
                                {record?.shift_end?.substring(0, 5) ?? '—'}
                              </span>
                              {record && (
                                <button onClick={(e) => handlePaintClick('end', record, day, e)} className={`p-0.5 rounded transition-all ${painting?.field === 'end' && painting?.day === day ? 'bg-blue-100 text-blue-600 ring-2 ring-blue-400' : 'opacity-0 group-hover:opacity-100 text-gray-300 hover:text-blue-500 hover:bg-blue-50'}`}>
                                  <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.5 2.5c-.8-.8-2.1-.8-2.8 0l-1.4 1.4 2.8 2.8 1.4-1.4c.8-.8.8-2 0-2.8zM3 17.3V21h3.7l11-11-3.7-3.7L3 17.3z"/></svg>
                                </button>
                              )}
                            </div>
                          </td>
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
                          <td className={`px-3 py-2 text-center text-xs font-semibold ${!record ? 'text-gray-300' : record.is_double_shift ? 'text-indigo-600' : record.total_hours - shiftHours > 0 ? 'text-blue-600' : record.total_hours - shiftHours < 0 ? 'text-red-600' : 'text-gray-300'}`}>
                            {!record ? '—' : record.is_double_shift ? 'تطبيق' : (() => { const d = record.total_hours - shiftHours; return d !== 0 ? d.toFixed(1) : '—'; })()}
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
                <input type="text" inputMode="numeric" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-left dir-ltr font-mono" placeholder="e.g. 9 / 9.30 / 3p / 9-6" value={shiftStart} onChange={(e) => { const v = e.target.value; if (v.includes('-')) { const p = v.split('-'); const s = parseSmartTime(p[0].trim()); const en = parseSmartTime(p[1].trim()); if (s) setShiftStart(s); if (en) setShiftEnd(en); } else { setShiftStart(v); } }} onBlur={() => { const p = parseSmartTime(shiftStart); if (p) setShiftStart(p); }} />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">نهاية الوردية</label>
                <input type="text" inputMode="numeric" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-left dir-ltr font-mono" placeholder="e.g. 6 / 6.30 / 6p / 5.45p" value={shiftEnd} onChange={(e) => setShiftEnd(e.target.value)} onBlur={() => { const p = parseSmartTime(shiftEnd); if (p) setShiftEnd(p); }} />
              </div>
              <div className="flex gap-1.5 flex-wrap">
                {[['09:00-18:00','9-6'], ['10:00-19:00','10-7'], ['08:00-17:00','8-5'], ['12:00-21:00','12-9']].map(([hh, label]) => {
                  const [s, e] = hh.split('-');
                  const active = shiftStart === s && shiftEnd === e;
                  return (
                    <button key={label} type="button" onClick={() => { setShiftStart(s); setShiftEnd(e); }} className={`px-2.5 py-1 text-[11px] rounded-lg border transition-all ${active ? 'bg-blue-100 border-blue-300 text-blue-700 font-semibold' : 'border-gray-200 text-gray-500 hover:border-blue-200'}`}>
                      {label}
                    </button>
                  );
                })}
              </div>
              <div className="flex gap-3 text-xs text-gray-500 bg-gray-50 rounded-lg p-2">
                <span>إجمالي: <strong className="text-blue-700">{computeHours(shiftStart, shiftEnd).toFixed(2)} س</strong></span>
                <span>الفرق: <strong className={computeHours(shiftStart, shiftEnd) - (selectedEmployee.shift_hours ?? 9) >= 0 ? 'text-green-700' : 'text-red-700'}>{(computeHours(shiftStart, shiftEnd) - (selectedEmployee.shift_hours ?? 9)).toFixed(2)} س</strong></span>
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