import { api } from '@/lib/api';

export const payrollApi = {
  // Employees
  employees: () =>
    api.get('/payroll/employees').then((r) => r.data.employees),

  storeEmployee: (data: any) =>
    api.post('/payroll/employees', data).then((r) => r.data),

  updateEmployee: (id: string, data: any) =>
    api.put(`/payroll/employees/${id}`, data).then((r) => r.data),

  deleteEmployee: (id: string) =>
    api.delete(`/payroll/employees/${id}`).then((r) => r.data),

  // Attendance
  attendance: (month: number, year: number, employeeId?: string) =>
    api.get('/payroll/attendance', { params: { month, year, employee_id: employeeId } }).then((r) => r.data.records),

  storeAttendance: (data: any) =>
    api.post('/payroll/attendance', data).then((r) => r.data),

  deleteAttendance: (id: string) =>
    api.delete(`/payroll/attendance/${id}`).then((r) => r.data),

  employeeAdvances: (employeeId: string, month: number, year: number) =>
    api.get('/payroll/employee-advances', { params: { employee_id: employeeId, month, year } }).then((r) => r.data),

  // Monthly payroll
  payrolls: () =>
    api.get('/payroll/monthly').then((r) => r.data.payrolls),

  showPayroll: (id: string) =>
    api.get(`/payroll/monthly/${id}`).then((r) => r.data.payroll),

  calculatePayroll: (month: number, year: number, salaryBaseDays?: number) =>
    api.post('/payroll/monthly/calculate', { month, year, salary_base_days: salaryBaseDays }).then((r) => r.data),

  updateBaseDays: (id: string, salaryBaseDays: number) =>
    api.post(`/payroll/monthly/${id}/update-base-days`, { salary_base_days: salaryBaseDays }).then((r) => r.data),

  approvePayroll: (id: string) =>
    api.post(`/payroll/monthly/${id}/approve`).then((r) => r.data),

  updateBonus: (detailId: string, bonusItems: any[]) =>
    api.post(`/payroll/monthly/bonus/${detailId}`, { bonus_items: bonusItems }).then((r) => r.data),

  updateCell: (detailId: string, field: string, value: number) =>
    api.post(`/payroll/monthly/detail/${detailId}/update-cell`, { field, value }).then((r) => r.data),

  deletePayroll: (id: string) =>
    api.delete(`/payroll/monthly/${id}`).then((r) => r.data),
};
