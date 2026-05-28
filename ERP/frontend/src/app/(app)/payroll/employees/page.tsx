'use client';
import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { payrollApi } from '@/lib/payroll/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

interface Employee {
  id: string;
  name: string;
  job_title: string | null;
  base_salary: number;
  shift_hours: number;
  daily_wage: number;
  hourly_wage: number;
  is_active: boolean;
}

function EmployeeForm({
  initial, onSave, onCancel,
}: {
  initial?: Employee | null;
  onSave: (data: any) => void;
  onCancel: () => void;
}) {
  const [name, setName] = useState(initial?.name ?? '');
  const [jobTitle, setJobTitle] = useState(initial?.job_title ?? '');
  const [baseSalary, setBaseSalary] = useState(initial?.base_salary?.toString() ?? '');
  const [shiftHours, setShiftHours] = useState(initial?.shift_hours?.toString() ?? '9');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim() || !baseSalary) return;
    onSave({ name: name.trim(), job_title: jobTitle.trim() || null, base_salary: parseFloat(baseSalary), shift_hours: parseFloat(shiftHours) || 9 });
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-3">
      <input className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="الاسم" value={name} onChange={(e) => setName(e.target.value)} required />
      <input className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="المسمى الوظيفي" value={jobTitle} onChange={(e) => setJobTitle(e.target.value)} />
      <input className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" type="number" step="0.01" min="0" placeholder="المرتب الأساسي" value={baseSalary} onChange={(e) => setBaseSalary(e.target.value)} required />
      <input className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" type="number" step="0.5" min="1" max="24" placeholder="ساعات الشيفت (افتراضي 9)" value={shiftHours} onChange={(e) => setShiftHours(e.target.value)} />
      <div className="flex gap-2 pt-2">
        <button type="submit" className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">حفظ</button>
        <button type="button" onClick={onCancel} className="px-4 py-2 bg-gray-200 text-sm rounded-lg hover:bg-gray-300">إلغاء</button>
      </div>
    </form>
  );
}

export default function PayrollEmployeesPage() {
  const qc = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [editEmp, setEditEmp] = useState<Employee | null>(null);

  const { data: employees = [], isLoading } = useQuery({
    queryKey: ['payroll-employees'],
    queryFn: () => payrollApi.employees(),
  });

  const createMut = useMutation({
    mutationFn: (data: any) => payrollApi.storeEmployee(data),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: ['payroll-employees'] }); toast.success(res.message); setShowForm(false); },
  });

  const updateMut = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => payrollApi.updateEmployee(id, data),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: ['payroll-employees'] }); toast.success(res.message); setEditEmp(null); },
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => payrollApi.deleteEmployee(id),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: ['payroll-employees'] }); toast.success(res.message); },
  });

  return (
    <div className="flex flex-col h-full">
      <PageHeader title="الموظفين" subtitle="إدارة بيانات الموظفين للرواتب والحضور" actions={
        <button onClick={() => { setEditEmp(null); setShowForm(true); }} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">+ إضافة موظف</button>
      } />

      <div className="flex-1 overflow-auto p-6">
        {(showForm || editEmp) && (
          <div className="mb-6 p-4 bg-white border border-gray-200 rounded-lg max-w-md">
            <h3 className="font-semibold text-sm mb-3">{editEmp ? 'تعديل موظف' : 'إضافة موظف'}</h3>
            <EmployeeForm initial={editEmp} onSave={(data) => editEmp ? updateMut.mutate({ id: editEmp.id, data }) : createMut.mutate(data)} onCancel={() => { setShowForm(false); setEditEmp(null); }} />
          </div>
        )}

        {isLoading ? (
          <div className="text-center text-gray-400 py-10">جاري التحميل...</div>
        ) : employees.length === 0 ? (
          <div className="text-center text-gray-400 py-10">لا يوجد موظفين بعد</div>
        ) : (
          <div className="bg-white rounded-lg border border-gray-200 overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">م</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">الاسم</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">الوظيفة</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">المرتب الأساسي</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">ساعات الشيفت</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">أجر اليوم</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">أجر الساعة</th>
                  <th className="px-4 py-3 text-right font-medium text-gray-600">إجراءات</th>
                </tr>
              </thead>
              <tbody>
                {employees.map((emp: Employee, i: number) => (
                  <tr key={emp.id} className="border-b border-gray-100 hover:bg-gray-50">
                    <td className="px-4 py-3">{i + 1}</td>
                    <td className="px-4 py-3 font-medium">{emp.name}</td>
                    <td className="px-4 py-3 text-gray-500">{emp.job_title ?? '—'}</td>
                    <td className="px-4 py-3">{emp.base_salary.toFixed(2)}</td>
                    <td className="px-4 py-3">{emp.shift_hours}</td>
                    <td className="px-4 py-3">{emp.daily_wage.toFixed(2)}</td>
                    <td className="px-4 py-3">{emp.hourly_wage.toFixed(2)}</td>
                    <td className="px-4 py-3 flex gap-2">
                      <button onClick={() => { setShowForm(false); setEditEmp(emp); }} className="text-blue-600 hover:text-blue-800 text-xs">تعديل</button>
                      <button onClick={() => { if (confirm('حذف الموظف؟')) deleteMut.mutate(emp.id); }} className="text-red-500 hover:text-red-700 text-xs">حذف</button>
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
