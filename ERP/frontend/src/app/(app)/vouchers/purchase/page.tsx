'use client';
import { PageHeader } from '@/components/ui/AppShell';
import { VoucherGrid } from '@/components/grid/VoucherGrid';
import { useState } from 'react';
import { useAuthStore } from '@/lib/store';

export default function PurchasePage() {
  const { currentClient } = useAuthStore();
  const today = new Date().toISOString().split('T')[0];
  const [date, setDate] = useState(today);

  return (
    <>
      <PageHeader title="وارد مخزن (مشتريات)" subtitle={currentClient?.name ?? ''} />
      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        <div className="bg-white border border-gray-100 rounded-xl p-4 flex gap-4 items-center">
          <label className="text-sm font-medium text-gray-600">تاريخ المشتريات:</label>
          <input 
            type="date" 
            value={date} 
            onChange={(e) => setDate(e.target.value)}
            className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
          />
        </div>
        <VoucherGrid type="purchase" date={date} />
      </div>
    </>
  );
}
