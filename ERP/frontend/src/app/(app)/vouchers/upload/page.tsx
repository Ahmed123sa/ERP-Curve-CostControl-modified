'use client';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '@/components/ui/AppShell';
import { VoucherUpload } from '@/components/upload/VoucherUpload';
import { VoucherGrid } from '@/components/grid/VoucherGrid';
import { useAuthStore } from '@/lib/store';
import { api } from '@/lib/api';

export default function UploadPage() {
  const [mode, setMode] = useState<'upload' | 'manual'>('upload');
  const { currentClient } = useAuthStore();
  const today = new Date().toISOString().split('T')[0];
  const [manualType, setManualType] = useState('purchase');
  const [manualDate, setManualDate] = useState(today);
  const [manualBranch, setManualBranch] = useState('');

  const { data: branches = [] } = useQuery({
    queryKey: ['branches'],
    queryFn: () => api.get('/branches').then((r) => r.data),
  });

  return (
    <>
      <PageHeader title="إضافة حركة" subtitle={currentClient?.name ?? ''}
        actions={
          <div className="flex rounded-xl border border-gray-200 overflow-hidden">
            <button onClick={() => setMode('upload')}
              className={`px-4 py-2 text-sm ${mode==='upload' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-50'}`}>
              ⬆ رفع Excel
            </button>
            <button onClick={() => setMode('manual')}
              className={`px-4 py-2 text-sm ${mode==='manual' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-50'}`}>
              ✏ إدخال يدوي
            </button>
          </div>
        }
      />
      <div className="flex-1 overflow-y-auto p-6">
        {mode === 'upload' ? <VoucherUpload /> : (
          <div className="space-y-4">
            <div className="flex gap-3 items-end">
              <div className="flex flex-col gap-1">
                <label className="text-xs text-gray-400 font-medium">نوع الإذن</label>
                <select value={manualType} onChange={(e) => setManualType(e.target.value)}
                  className="px-3 py-2 border border-gray-200 rounded-xl text-sm outline-none">
                  <option value="purchase">وارد مخزن</option>
                  <option value="dispatch">إذن صرف</option>
                </select>
              </div>
              <div className="flex flex-col gap-1">
                <label className="text-xs text-gray-400 font-medium">التاريخ</label>
                <input type="date" value={manualDate} onChange={(e) => setManualDate(e.target.value)}
                  className="px-3 py-2 border border-gray-200 rounded-xl text-sm outline-none" />
              </div>
              {manualType === 'dispatch' && (
                <div className="flex flex-col gap-1">
                  <label className="text-xs text-gray-400 font-medium">الفرع المستلم</label>
                  <select value={manualBranch} onChange={(e) => setManualBranch(e.target.value)}
                    className="px-3 py-2 border border-gray-200 rounded-xl text-sm outline-none">
                    <option value="">اختر الفرع...</option>
                    {branches.map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}
                  </select>
                </div>
              )}
            </div>
            <VoucherGrid type={manualType as any} date={manualDate} branchId={manualBranch || undefined} />
          </div>
        )}
      </div>
    </>
  );
}
