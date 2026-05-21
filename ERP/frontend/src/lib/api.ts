// src/lib/api.ts
// Axios instance مربوط بالـ backend

import axios from 'axios';
import toast from 'react-hot-toast';

export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  withCredentials: false,
});

// Request interceptor — بيضيف الـ token تلقائياً
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('erp_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Response interceptor — بيعالج الـ errors
api.interceptors.response.use(
  (res) => res,
  (err) => {
    const status  = err.response?.status;
    const message = err.response?.data?.message ?? 'حدث خطأ غير متوقع';

    if (status === 401) {
      localStorage.removeItem('erp_token');
      window.location.href = '/login';
    } else if (status === 422) {
      // Validation errors
      const errors = err.response?.data?.errors ?? {};
      Object.values(errors).flat().forEach((e: any) => toast.error(e));
    } else if (status >= 500) {
      toast.error('خطأ في الخادم — يرجى التواصل مع المسؤول');
    } else {
      toast.error(message);
    }

    return Promise.reject(err);
  },
);
