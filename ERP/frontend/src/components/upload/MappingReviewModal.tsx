'use client';

import { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

interface Props {
  voucher: any;
  onResolve: (updatedVoucher: any) => void;
  onClose: () => void;
}

export function MappingReviewModal({ voucher, onResolve, onClose }: Props) {
  const [resolutions, setResolutions] = useState<Record<number, string>>({});
  const [itemSearch, setItemSearch] = useState<Record<string, string>>({});
  const [warehouseId, setWarehouseId] = useState<string>(voucher.location?.id || '');

  const { data: items = [] } = useQuery({
    queryKey: ['items'],
    queryFn: () => api.get('/items').then((r) => r.data),
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses'],
    queryFn: () => api.get('/warehouses').then((r) => r.data),
  });

  const groupedLocations = useMemo(() => {
    return {
      main: warehouses.filter((w: any) => w.type === 'main'),
      sub: warehouses.filter((w: any) => w.type === 'sub'),
      branch: warehouses.filter((w: any) => w.type === 'branch'),
    };
  }, [warehouses]);

  const allLinesResolved = useMemo(() => {
    return voucher.lines.every((line: any, idx: number) => {
      const chosen = resolutions[idx] || line.resolved_item_id || line.item_id;
      return !!chosen;
    });
  }, [voucher.lines, resolutions]);

  const handleFinish = () => {
    const resolvedLines = voucher.lines.map((line: any, idx: number) => {
      const itemId = resolutions[idx] || line.resolved_item_id || line.item_id;
      const itemObj = items.find((i: any) => i.id === itemId);
      
      return {
        ...line,
        item_id: itemId,
        warehouse_id: warehouseId,
        item_name: itemObj?.name || line.item_name,
        needs_review: false, // خلاص تم الربط
      };
    });
    onResolve({
      ...voucher,
      lines: resolvedLines,
      warehouse_id: warehouseId, // حفظ الموقع على مستوى الإذن أيضاً
      has_issues: false
    });
  };

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" dir="rtl">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
        {/* Header */}
        <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50">
          <div>
            <h2 className="text-lg font-bold text-gray-800">مراجعة وربط البيانات</h2>
            <p className="text-xs text-gray-500 mt-0.5">الإذن: {voucher.location_raw} — {voucher.date}</p>
          </div>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6 space-y-6">
          {/* 1. ربط الموقع */}
          <div className="bg-blue-50 border border-blue-100 rounded-xl p-4">
            <h3 className="text-sm font-semibold text-blue-800 mb-3 flex items-center gap-2">
              <span>📍</span> تحديد المستودع / الفرع
            </h3>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs text-blue-600 mb-1 font-medium">الموقع في الملف</label>
                <div className="text-sm font-bold text-blue-900">{voucher.location_raw}</div>
              </div>
              <div>
                <label className="block text-xs text-blue-600 mb-1 font-medium">الربط في النظام</label>
                <select
                  value={warehouseId}
                  onChange={(e) => setWarehouseId(e.target.value)}
                  className="w-full bg-white border border-blue-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                >
                  <option value="">اختر المخزن أو الفرع...</option>
                  {groupedLocations.main.length > 0 && (
                    <optgroup label="المخازن الرئيسية">
                      {groupedLocations.main.map((w: any) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </optgroup>
                  )}
                  {groupedLocations.sub.length > 0 && (
                    <optgroup label="المخازن الفرعية">
                      {groupedLocations.sub.map((w: any) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </optgroup>
                  )}
                  {groupedLocations.branch.length > 0 && (
                    <optgroup label="الفروع">
                      {groupedLocations.branch.map((w: any) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </optgroup>
                  )}
                </select>
              </div>
            </div>
          </div>

      {/* 2. ربط الأصناف */}
      <div className="space-y-3">
        <h3 className="text-sm font-semibold text-gray-800">مراجعة وربط الأصناف</h3>
        <table className="w-full text-sm">
          <thead className="text-right text-xs text-gray-400 border-b border-gray-100">
            <tr>
              <th className="px-4 py-2 font-normal">الصنف في الملف</th>
              <th className="px-4 py-2 font-normal">اختيار الصنف المطابق</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {voucher.lines.map((line: any, idx: number) => {
              const searchKey = String(idx);
              const searchVal = itemSearch[searchKey] || '';
              const filteredItems = items.filter((i: any) =>
                !searchVal || i.name.toLowerCase().includes(searchVal.toLowerCase())
              );
              // For already resolved lines, pre-select current item_id
              const currentResolvedId = resolutions[idx] || line.resolved_item_id || line.item_id || '';
              return (
                <tr key={idx} className={`hover:bg-gray-50/50 ${!line.needs_review && line.resolved_item_id ? 'bg-green-50/30' : ''}`}>
                  <td className="px-4 py-3">
                    <div className="font-medium text-gray-700">{line.source_name}</div>
                    <div className="text-[10px] text-gray-400">{line.unit} — كمية: {line.qty}</div>
                    {!line.needs_review && line.resolved_item_id && (
                      <div className="text-[10px] text-green-600 mt-0.5">✓ مربوط بــ {line.item_name}</div>
                    )}
                  </td>
                  <td className="px-4 py-3 space-y-1.5">
                    <input
                      type="text"
                      value={searchVal}
                      onChange={(e) => setItemSearch(prev => ({ ...prev, [searchKey]: e.target.value }))}
                      placeholder="ابحث عن الصنف..."
                      className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                      dir="rtl"
                    />
                    <select
                      value={currentResolvedId}
                      onChange={(e) => setResolutions(prev => ({ ...prev, [idx]: e.target.value }))}
                      className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                    >
                      <option value="">{line.resolved_item_id ? '— بدون تغيير —' : 'اختر الصنف المطابق...'}</option>
                      {filteredItems.map((item: any) => (
                        <option key={item.id} value={item.id}>{item.name} ({item.unit})</option>
                      ))}
                    </select>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
        </div>

        {/* Footer */}
        <div className="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end gap-3">
          <button
            onClick={onClose}
            className="px-6 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-xl transition-colors"
          >
            إلغاء
          </button>
          <button
            onClick={handleFinish}
            disabled={!allLinesResolved || !warehouseId}
            className="px-8 py-2 text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow-lg shadow-blue-200 disabled:opacity-40 disabled:shadow-none transition-all"
          >
            حفظ الربط والمتابعة ✓
          </button>
        </div>
      </div>
    </div>
  );
}
