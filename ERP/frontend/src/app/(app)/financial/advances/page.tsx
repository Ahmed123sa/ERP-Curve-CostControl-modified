'use client';
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { financialApi } from '@/lib/financial/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function FinancialAdvancesPage() {
  const qc = useQueryClient();
  const today = new Date().toISOString().slice(0, 7);
  const [month, setMonth] = useState(today);
  const [employeeName, setEmployeeName] = useState('');
  const [editCell, setEditCell] = useState<{ employee_id: string; day: number } | null>(null);
  const [cellAmount, setCellAmount] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['financial-advances', month],
    queryFn: () => financialApi.advances(month),
  });

  const { data: empData } = useQuery({
    queryKey: ['financial-employees'],
    queryFn: () => financialApi.employees(),
  });

  const employees = (empData as any)?.employees || [];
  const matrix = (data as any)?.matrix || {};
  const daysInMonth = (data as any)?.days_in_month || 30;

  const addEmployeeMutation = useMutation({
    mutationFn: () => financialApi.storeEmployee(employeeName),
    onSuccess: () => {
      toast.success('تم إضافة الموظف');
      setEmployeeName('');
      qc.invalidateQueries({ queryKey: ['financial-employees'] });
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في الإضافة'),
  });

  const saveAdvanceMutation = useMutation({
    mutationFn: (adv: any) => financialApi.storeAdvance(adv),
    onSuccess: () => {
      toast.success('تم حفظ السلفة');
      setEditCell(null);
      setCellAmount('');
      qc.invalidateQueries({ queryKey: ['financial-advances', month] });
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'خطأ في الحفظ'),
  });

  function handleCellClick(employeeId: string, day: number, currentAmount: number) {
    setEditCell({ employee_id: employeeId, day });
    setCellAmount(String(currentAmount || ''));
  }

  function handleCellSave() {
    if (!editCell) return;
    const dateStr = `${month}-${String(editCell.day).padStart(2, '0')}`;
    saveAdvanceMutation.mutate({
      employee_id: editCell.employee_id,
      date: dateStr,
      amount: parseFloat(cellAmount) || 0,
    });
  }

  return (
    <div className="flex flex-col h-full bg-gray-50/50" dir="rtl">
      <PageHeader
        title="السلف"
        subtitle="إدارة سلف الموظفين اليومية"
      />

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        {/* Filters */}
        <div className="bg-white border border-gray-100 rounded-xl p-4 shadow-sm flex items-end gap-4">
          <div>
            <label className="text-xs font-medium text-gray-500 block mb-1">الشهر</label>
            <input type="month" value={month} onChange={(e) => setMonth(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" />
          </div>
          <div className="flex-1"></div>
          <div className="flex gap-2">
            <input type="text" placeholder="اسم الموظف" value={employeeName}
              onChange={(e) => setEmployeeName(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" />
            <button onClick={() => addEmployeeMutation.mutate()} disabled={!employeeName || addEmployeeMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
              إضافة موظف
            </button>
          </div>
        </div>

        {isLoading ? (
          <div className="text-center py-12 text-gray-400">جاري التحميل...</div>
        ) : employees.length === 0 ? (
          <div className="text-center py-16 bg-white border border-dashed border-gray-200 rounded-xl">
            <div className="text-5xl mb-4 text-gray-300">💰</div>
            <h3 className="text-lg font-bold text-gray-700 mb-2">لا يوجد موظفين</h3>
            <p className="text-sm text-gray-500">أضف موظفين للبدء في تسجيل السلف</p>
          </div>
        ) : (
          <div className="bg-white border border-gray-100 rounded-xl shadow-sm overflow-x-auto">
            <table className="w-full text-sm text-right text-nowrap">
              <thead className="bg-gray-50 border-b border-gray-100 text-gray-600">
                <tr>
                  <th className="px-3 py-2 sticky right-0 bg-gray-50">الموظف</th>
                  {Array.from({ length: daysInMonth }, (_, i) => (
                    <th key={i + 1} className="px-2 py-2 text-center text-xs w-10">{i + 1}</th>
                  ))}
                  <th className="px-3 py-2 text-center">الإجمالي</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {employees.map((emp: any) => {
                  const empData = matrix[emp.id];
                  const total = empData?.total || 0;
                  return (
                    <tr key={emp.id} className="hover:bg-gray-50">
                      <td className="px-3 py-2 font-medium sticky right-0 bg-white">{emp.name}</td>
                      {Array.from({ length: daysInMonth }, (_, i) => {
                        const day = i + 1;
                        const amount = empData?.days?.[day] || 0;
                        const isEditing = editCell?.employee_id === emp.id && editCell?.day === day;
                        return (
                          <td key={day} className="px-2 py-2 text-center">
                            {isEditing ? (
                              <div className="flex gap-1 items-center justify-center">
                                <input type="number" value={cellAmount} onChange={(e) => setCellAmount(e.target.value)}
                                  className="w-16 border border-blue-300 rounded px-1 py-0.5 text-xs text-center" autoFocus
                                  onKeyDown={(e) => { if (e.key === 'Enter') handleCellSave(); if (e.key === 'Escape') setEditCell(null); }} />
                                <button onClick={handleCellSave} className="text-green-600 text-xs">✓</button>
                                <button onClick={() => setEditCell(null)} className="text-red-500 text-xs">✕</button>
                              </div>
                            ) : (
                              <button onClick={() => handleCellClick(emp.id, day, amount)}
                                className={`w-full text-center rounded ${amount > 0 ? 'text-blue-600 font-medium' : 'text-gray-300 hover:text-gray-500'}`}>
                                {amount > 0 ? amount : '—'}
                              </button>
                            )}
                          </td>
                        );
                      })}
                      <td className="px-3 py-2 text-center font-bold text-blue-700">{total}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
