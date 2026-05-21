'use client';
import { PageHeader } from '@/components/ui/AppShell';
import { VoucherGrid } from '@/components/grid/VoucherGrid';
import { useState } from 'react';
import { useAuthStore } from '@/lib/store';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export default function DispatchPage() {
  const { currentClient } = useAuthStore();
  const today = new Date().toISOString().split('T')[0];
  const [date, setDate] = useState(today);
  const [branchId, setBranchId] = useState('');

  const { data: branches = [] } = useQuery({
    queryKey: ['branches'],
    queryFn: () => api.get('/branches').then((r) => r.data),
  });

  return (
    <>
      <PageHeader title="إذن صرف (منصرف فروع)" subtitle={currentClient?.name ?? ''} />
      <div className="flex-1 overflow-y-auto p-6 space-y-4">
        <div className="bg-white border border-gray-100 rounded-xl p-4 flex gap-4 items-center">
          <div className="flex flex-col gap-1">
            <label className="text-xs font-medium text-gray-400">التاريخ</label>
            <input 
              type="date" 
              value={date} 
              onChange={(e) => setDate(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
            />
          </div>
          <div className="flex flex-col gap-1 flex-1">
            <label className="text-xs font-medium text-gray-400">الفرع المستلم</label>
            <select 
              value={branchId} 
              onChange={(e) => setBranchId(e.target.value)}
              className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
            >
              <option value="">اختر الفرع...</option>
              {branches.map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
        </div>
        <VoucherGrid type="dispatch" date={date} branchId={branchId} />
      </div>
    </>
  );
}
