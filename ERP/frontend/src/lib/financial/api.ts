import { api } from '@/lib/api';

export const financialApi = {
  // Categories
  categories: () =>
    api.get('/financial/categories').then((r) => r.data.categories),

  storeCategory: (name: string) =>
    api.post('/financial/categories', { name }).then((r) => r.data),

  updateCategory: (id: string, name: string) =>
    api.put(`/financial/categories/${id}`, { name }).then((r) => r.data),

  deleteCategory: (id: string) =>
    api.delete(`/financial/categories/${id}`).then((r) => r.data),

  // Daily Entries
  dailyEntries: (month: string) =>
    api.get('/financial/daily-entries', { params: { month } }).then((r) => r.data),

  storeDailyEntry: (data: any) =>
    api.post('/financial/daily-entries', data).then((r) => r.data),

  updateDailyEntry: (id: string, data: any) =>
    api.put(`/financial/daily-entries/${id}`, data).then((r) => r.data),

  deleteDailyEntry: (id: string) =>
    api.delete(`/financial/daily-entries/${id}`).then((r) => r.data),

  items: (categoryId?: string) =>
    api.get('/financial/daily-entries/items', { params: { category_id: categoryId } }).then((r) => r.data.items),

  // Monthly Summaries
  monthlySummaries: (month?: number, year?: number) =>
    api.get('/financial/monthly-summaries', { params: { month, year } }).then((r) => r.data),

  generateMonthlySummary: (month: number, year: number) =>
    api.post('/financial/monthly-summaries/generate', { month, year }).then((r) => r.data),

  finalizeMonthlySummary: (id: string) =>
    api.post(`/financial/monthly-summaries/${id}/finalize`).then((r) => r.data),

  // Closing Reports
  closingReports: (month?: number, year?: number) =>
    api.get('/financial/closing-reports', { params: { month, year } }).then((r) => r.data),

  generateClosingReport: (month: number, year: number) =>
    api.post('/financial/closing-reports/generate', { month, year }).then((r) => r.data),

  showClosingReport: (id: string) =>
    api.get(`/financial/closing-reports/${id}`).then((r) => r.data),

  // Advances
  employees: () =>
    api.get('/financial/employees').then((r) => r.data),

  storeEmployee: (name: string) =>
    api.post('/financial/employees', { name }).then((r) => r.data),

  advances: (month: string) =>
    api.get('/financial/advances', { params: { month } }).then((r) => r.data),

  storeAdvance: (data: any) =>
    api.post('/financial/advances', data).then((r) => r.data),
};
