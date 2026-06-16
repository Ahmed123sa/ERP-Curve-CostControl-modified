'use client';
import { toast } from 'react-hot-toast';

const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api';

function doExport(baseUrl: string, params: Record<string, string>, ext: 'xlsx' | 'pdf') {
  const q = new URLSearchParams({ ...params, format: ext }).toString();
  const token = localStorage.getItem('erp_token');
  fetch(`${API}${baseUrl}?${q}`, { headers: { Authorization: `Bearer ${token}` } })
    .then((r) => { if (!r.ok) throw new Error(); return r.blob(); })
    .then((blob) => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `report.${ext}`;
      a.click();
      URL.revokeObjectURL(a.href);
      toast.success(`تم تصدير ${ext === 'xlsx' ? 'الإكسيل' : 'PDF'} ✓`);
    })
    .catch(() => toast.error('خطأ في التصدير'));
}

export function ExportButtons({ baseUrl, params, disabled }: { baseUrl: string; params: Record<string, string>; disabled?: boolean }) {
  return (
    <div className="flex gap-1">
      <button onClick={() => doExport(baseUrl, params, 'xlsx')} disabled={disabled}
        className="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700 disabled:opacity-40">
        إكسيل
      </button>
      <button onClick={() => doExport(baseUrl, params, 'pdf')} disabled={disabled}
        className="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-medium hover:bg-red-700 disabled:opacity-40">
        PDF
      </button>
    </div>
  );
}
