'use client';
// components/upload/VoucherUpload.tsx
// رفع ملفات Excel + معاينة + تأكيد

import { useState, useCallback } from 'react';
import { useDropzone } from 'react-dropzone';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';
import { MappingReviewModal } from './MappingReviewModal';

interface UploadedVoucher {
  date: string;
  location_raw: string;
  type: string;
  location: { type: string; id: string; needs_review: boolean };
  lines: VoucherLine[];
  has_issues: boolean;
}

interface VoucherLine {
  source_name: string;
  qty: number;
  cost: number;
  unit_cost: number;
  item_id: string | null;
  item_name: string | null;
  confidence: number;
  needs_review: boolean;
  // بعد المراجعة
  resolved_item_id?: string;
  resolved_warehouse_id?: string;
}

export function VoucherUpload() {
  const qc = useQueryClient();
  const [step, setStep] = useState<'idle' | 'preview' | 'done'>('idle');
  const [vouchers, setVouchers] = useState<UploadedVoucher[]>([]);
  const [availableWarehouses, setAvailableWarehouses] = useState<any[]>([]);
  const [tmpPath, setTmpPath] = useState('');
  const [reviewVoucherIdx, setReviewVoucherIdx] = useState<number | null>(null);

  // ── رفع الملفات ──────────────────────────────────────────
  const uploadMutation = useMutation({
    mutationFn: async (files: File[]) => {
      const allVouchers: UploadedVoucher[] = [];
      const allWarehouses: any[] = [];
      const allErrors: string[] = [];
      let lastPath = '';

      for (const file of files) {
        const form = new FormData();
        form.append('file', file);
        const { data } = await api.post('/vouchers/upload', form, { 
          headers: { 'Content-Type': 'multipart/form-data' } 
        });
        allVouchers.push(...data.vouchers);
        allWarehouses.push(...(data.warehouses || []));
        allErrors.push(...(data.errors || []));
        lastPath = data.tmp_path;
      }

      return { vouchers: allVouchers, warehouses: allWarehouses, errors: allErrors, tmp_path: lastPath };
    },
    onSuccess: (data) => {
      setVouchers(data.vouchers);
      // Remove duplicate warehouses by ID
      const uniqueWH = Array.from(new Map(data.warehouses.map((w: any) => [w.id, w])).values());
      setAvailableWarehouses(uniqueWH);
      setTmpPath(data.tmp_path);
      setStep('preview');
      if (data.vouchers.some((v: UploadedVoucher) => v.has_issues)) {
        toast('في حاجات محتاجة مراجعة — الأصناف باللون البرتقالي', { icon: '⚠️' });
      } else {
        toast.success(`تم قراءة ${data.vouchers.length} إذن بنجاح — راجع وأكد`);
      }
    },
  });

  // ── تأكيد الحفظ ────────────────────────────────────────
  const confirmMutation = useMutation({
    mutationFn: () => api.post('/vouchers/confirm', { vouchers }),
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'خطأ في الحفظ';
      const errors = err.response?.data?.errors;
      if (errors) {
        const firstErr = Object.values(errors)[0] as string[];
        toast.error(`${msg}: ${firstErr[0]}`);
      } else {
        toast.error(msg);
      }
    },
    onSuccess: () => {
      toast.success('تم حفظ الأذون بنجاح ✓');
      qc.invalidateQueries({ queryKey: ['vouchers'] });
      qc.invalidateQueries({ queryKey: ['stock'] });
      setStep('done');
      setVouchers([]);
    },
  });

  const onDrop = useCallback((files: File[]) => {
    if (files.length > 0) uploadMutation.mutate(files);
  }, []);

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    accept: { 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'] },
    multiple: true,
  });

  const hasUnresolved = vouchers.some(
    (v) => v.has_issues || v.lines.some((l) => l.needs_review && !l.resolved_item_id),
  );

  if (step === 'idle' || step === 'done') {
    return (
      <div
        {...getRootProps()}
        className={`border-2 border-dashed rounded-xl p-12 text-center cursor-pointer transition-colors
          ${isDragActive ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:border-gray-300'}`}
      >
        <input {...getInputProps()} />
        <div className="text-4xl mb-3">📤</div>
        <p className="text-gray-600 font-medium">
          {isDragActive ? 'أفلت الملف هنا' : 'اسحب ملف Excel هنا أو اضغط للاختيار'}
        </p>
        <p className="text-gray-400 text-sm mt-1">يدعم: إذن صرف، وارد مخزن (.xlsx)</p>
        {uploadMutation.isPending && (
          <p className="text-blue-500 text-sm mt-3 animate-pulse">جاري قراءة الملف...</p>
        )}
        {step === 'done' && (
          <p className="text-green-600 text-sm mt-3">✓ تم الحفظ — ارفع ملف جديد</p>
        )}
      </div>
    );
  }

  return (
    <div className="space-y-4" dir="rtl">
      <div className="flex items-center justify-between">
        <h3 className="font-semibold text-gray-800">معاينة الأذون — {vouchers.length} إذن</h3>
        <div className="flex gap-2">
          <button
            onClick={() => { setStep('idle'); setVouchers([]); }}
            className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50"
          >
            إلغاء
          </button>
          <button
            onClick={() => confirmMutation.mutate()}
            disabled={hasUnresolved || confirmMutation.isPending}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700
                       disabled:opacity-40 disabled:cursor-not-allowed"
          >
            {confirmMutation.isPending ? 'جاري الحفظ...' : 'تأكيد وحفظ الكل ✓'}
          </button>
        </div>
      </div>

      {vouchers.map((voucher, vi) => (
        <div key={vi} className="border border-gray-100 rounded-xl overflow-hidden">
          {/* Header الإذن */}
          <div className={`px-4 py-3 flex items-center justify-between
            ${voucher.has_issues ? 'bg-amber-50 border-b border-amber-100' : 'bg-gray-50 border-b border-gray-100'}`}>
            <div className="flex items-center gap-3">
              <span className={`px-2 py-0.5 text-xs rounded-full font-medium
                ${voucher.type === 'purchase' ? 'bg-green-100 text-green-700' :
                  voucher.type === 'dispatch' ? 'bg-blue-100 text-blue-700' :
                  'bg-gray-100 text-gray-600'}`}>
                {voucher.type === 'purchase' ? 'مشتريات' :
                 voucher.type === 'dispatch' ? 'إذن صرف' : voucher.type}
              </span>
              <span className="text-sm font-medium">{voucher.location_raw}</span>
              <span className="text-sm text-gray-500">{voucher.date}</span>
            </div>
            {voucher.has_issues && (
              <button
                onClick={() => setReviewVoucherIdx(vi)}
                className="text-sm text-amber-600 hover:text-amber-700 font-medium"
              >
                ⚠ مراجعة الأصناف غير المعروفة
              </button>
            )}
          </div>

          {/* سطور الإذن */}
          <table className="w-full text-sm">
            <thead>
              <tr className="text-right text-gray-400 text-xs border-b border-gray-50">
                <th className="px-4 py-2 font-normal">الصنف</th>
                <th className="px-4 py-2 font-normal">الكمية</th>
                <th className="px-4 py-2 font-normal">Cost إجمالي</th>
                <th className="px-4 py-2 font-normal">حالة الربط</th>
              </tr>
            </thead>
            <tbody>
              {voucher.lines.map((line, li) => {
                return (
                  <tr key={li} className="border-b border-gray-50 last:border-0">
                    <td className="px-4 py-2">
                      <div className="font-medium text-gray-800">
                        {line.resolved_item_id ? line.item_name : line.source_name}
                      </div>
                      {line.needs_review && !line.resolved_item_id && (
                        <div className="text-xs text-amber-500">مش متربط — يحتاج مراجعة</div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-gray-700">{line.qty}</td>
                    <td className="px-4 py-2 text-gray-700">
                      {line.cost > 0 ? line.cost.toLocaleString('ar-EG') + ' ج' : '—'}
                    </td>
                    <td className="px-4 py-2">
                      {!line.needs_review || line.resolved_item_id ? (
                        <span className="text-green-600 text-xs">✓ {line.confidence}%</span>
                      ) : (
                        <span className="text-amber-500 text-xs">⚠ يحتاج ربط</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ))}

      {/* Modal للربط اليدوي */}
      {reviewVoucherIdx !== null && (
        <MappingReviewModal
          voucher={vouchers[reviewVoucherIdx]}
           onResolve={(updatedVoucher) => {
             setVouchers((prev) => 
               prev.map((v, i) => 
                 i === reviewVoucherIdx ? updatedVoucher : v
               ) as UploadedVoucher[]
             );
             setReviewVoucherIdx(null);
           }}
          onClose={() => setReviewVoucherIdx(null)}
        />
      )}
    </div>
  );
}
