'use client';
import { useState } from 'react';
import { PageHeader } from '@/components/ui/AppShell';
import { VoucherUpload } from '@/components/upload/VoucherUpload';
import { VoucherGrid } from '@/components/grid/VoucherGrid';
import { useAuthStore } from '@/lib/store';

export default function UploadPage() {
  const [mode, setMode] = useState<'upload' | 'manual'>('upload');
  const { currentClient } = useAuthStore();
  const today = new Date().toISOString().split('T')[0];

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
            <div className="flex gap-3">
              <select className="px-3 py-2 border border-gray-200 rounded-xl text-sm">
                <option value="purchase">وارد مخزن</option>
                <option value="dispatch">إذن صرف</option>
              </select>
              <input type="date" defaultValue={today} className="px-3 py-2 border border-gray-200 rounded-xl text-sm" />
            </div>
            <VoucherGrid type="purchase" date={today} />
          </div>
        )}
      </div>
    </>
  );
}
