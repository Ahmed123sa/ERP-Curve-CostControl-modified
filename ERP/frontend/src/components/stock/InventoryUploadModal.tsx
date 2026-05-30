'use client';
import { useState, Fragment, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import toast from 'react-hot-toast';

interface Props {
  warehouseId: string;
  month: string;
  type: 'opening' | 'final';
  onSuccess: () => void;
}

export function InventoryUploadModal({ warehouseId, month, type, onSuccess }: Props) {
  const qc = useQueryClient();
  const [isOpen, setIsOpen] = useState(false);
  const [step, setStep] = useState<'upload' | 'mapping' | 'preview'>('upload');
  const [file, setFile] = useState<File | null>(null);
  const [parsedData, setParsedData] = useState<any>(null);
  const [mappingItems, setMappingItems] = useState<any[]>([]);
  const [validationError, setValidationError] = useState<string | null>(null);
  const { currentClient } = useAuthStore();

  // جلب الأصناف للربط اليدوي
  const { data: items = [] } = useQuery({
    queryKey: ['items', currentClient?.id],
    queryFn: () => api.get('/items').then((r) => r.data),
    enabled: step === 'mapping' || step === 'preview'
  });

  // 1. مرحلة التحليل (Parse)
  const parseMutation = useMutation({
    mutationFn: (file: File) => {
      const formData = new FormData();
      formData.append('file', file);
      // نترك المتصفح يحدد الـ Content-Type والـ boundary تلقائياً
      return api.post('/inventory/parse', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });
    },
    onSuccess: (res) => {
      setParsedData(res.data);
      const needsReview = res.data.items.filter((i: any) => i.needs_review);
      if (needsReview.length > 0) {
        setMappingItems(needsReview);
        setStep('mapping');
      } else {
        setStep('preview');
      }
    },
    onError: (e: any) => toast.error(e?.message || 'خطأ في معالجة الملف')
  });

  // 2. حفظ الربط (Mapping)
  const saveMappingMutation = useMutation({
    mutationFn: (mapping: { source_name: string; item_id: string }) => 
      api.post('/mappings/item', mapping),
    onSuccess: () => {
      // بعد حفظ الربط، نعيد التحليل لتطبيق الربط الجديد
      if (file) parseMutation.mutate(file);
    }
  });

  // 3. تأكيد الرفع النهائي
  const confirmMutation = useMutation({
    mutationFn: () => api.post('/inventory/confirm', {
      warehouse_id: warehouseId,
      month: month,
      type: type,
      items: (parsedData?.items || []).map((i: any) => ({
        item_id: i.item_id,
        qty: i.qty,
        cost: i.cost ?? 0
      }))
    }),
    onSuccess: () => {
      toast.success('تم رفع البيانات بنجاح ✓');
      setIsOpen(false);
      onSuccess();
      qc.invalidateQueries({ queryKey: ['opening-prefill'] });
      qc.invalidateQueries({ queryKey: ['closing'] });
      qc.invalidateQueries({ queryKey: ['closing-actual'] });
    },
    onError: (e: any) => {
      const message = e?.response?.data?.message ?? e?.message ?? 'خطأ في الحفظ';
      toast.error(message);
    }
  });

  // التحقق من صحة الكمية (لا يجب أن تكون سالبة)
  useEffect(() => {
    if (step === 'preview' && parsedData?.items) {
      const negativeItems = parsedData.items.filter((item: any) => item.qty < 0);
      if (negativeItems.length > 0) {
        setValidationError(`يوجد ${negativeItems.length} صنف بكمية سالبة. يرجى تصحيح الكمية قبل الحفظ.`);
      } else {
        setValidationError(null);
      }
    }
  }, [step, parsedData]);

  const reset = () => {
    setStep('upload');
    setFile(null);
    setParsedData(null);
    setMappingItems([]);
  };

  if (!isOpen) return (
    <button
      onClick={() => setIsOpen(true)}
      disabled={!warehouseId}
      className="flex items-center gap-2 px-4 py-2 bg-blue-50 text-blue-700 rounded-lg text-sm font-medium hover:bg-blue-100 disabled:opacity-50 transition-colors border border-blue-200"
    >
      <span>📥 رفع من إكسيل</span>
    </button>
  );

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" dir="rtl">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        {/* Header */}
        <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
          <div>
            <h3 className="font-bold text-gray-900">رفع جرد من ملف إكسيل</h3>
            <p className="text-xs text-gray-500 mt-0.5">
              {type === 'opening' ? 'أرصدة افتتاحية' : 'جرد نهائي فعلي'} - {month}
            </p>
          </div>
          <button onClick={() => setIsOpen(false)} className="text-gray-400 hover:text-gray-600 p-1">✕</button>
        </div>

        {/* Content */}
        <div className="p-6 min-h-[300px] max-h-[60vh] overflow-y-auto">
          
          {step === 'upload' && (
            <div className="flex flex-col items-center justify-center border-2 border-dashed border-gray-200 rounded-xl p-12 hover:border-blue-400 transition-colors">
              <div className="text-4xl mb-4 text-gray-300">📊</div>
              <p className="text-sm text-gray-600 mb-6 text-center">قم بسحب ملف الإكسيل هنا أو اضغط للاختيار<br/><span className="text-[10px] text-gray-400">(العمود الأول: الاسم، العمود الثاني: الكمية)</span></p>
              <input 
                type="file" 
                accept=".xlsx,.xls,.csv" 
                onChange={(e) => {
                  const f = e.target.files?.[0];
                  if (f) {
                    setFile(f);
                    parseMutation.mutate(f);
                  }
                }}
                className="hidden" 
                id="inv-file-upload"
              />
              <label 
                htmlFor="inv-file-upload"
                className="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 cursor-pointer shadow-md"
              >
                {parseMutation.isPending ? 'جاري التحليل...' : 'اختار الملف'}
              </label>
            </div>
          )}

          {step === 'mapping' && (
            <div className="space-y-4">
              <div className="bg-amber-50 border border-amber-100 p-3 rounded-lg flex items-start gap-3">
                <span className="text-amber-600">⚠️</span>
                <p className="text-xs text-amber-800 leading-relaxed">هناك أصناف في الملف لا يعرفها النظام. يرجى ربطها بالأصناف الصحيحة لمرة واحدة فقط وسيتذكرها النظام لاحقاً.</p>
              </div>
              <div className="divide-y divide-gray-100">
                {mappingItems.map((mi, idx) => (
                  <div key={idx} className="py-3 flex items-center justify-between gap-4">
                    <span className="text-sm font-medium text-gray-700 truncate max-w-[200px]">{mi.source_name}</span>
                    <select
                      onChange={(e) => {
                        const itemId = e.target.value;
                        if (itemId) {
                          saveMappingMutation.mutate({ source_name: mi.source_name, item_id: itemId });
                        }
                      }}
                      className="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-1 focus:ring-blue-500 outline-none bg-white"
                    >
                      <option value="">اختر الصنف المطابق...</option>
                      {items.map((it: any) => <option key={it.id} value={it.id}>{it.name}</option>)}
                    </select>
                  </div>
                ))}
              </div>
            </div>
          )}

{step === 'preview' && (
              <div className="space-y-4">
                {validationError && (
                  <div className="bg-red-50 border border-red-100 p-3 rounded-lg text-red-800 text-xs flex items-center justify-between">
                    <span>{validationError}</span>
                  </div>
                )}
                {!validationError && (
                  <div className="bg-green-50 border border-green-100 p-3 rounded-lg text-green-800 text-xs flex items-center justify-between">
                    <span>تم التعرف على كل الأصناف بنجاح ✓</span>
                    <span className="font-bold">إجمالي: {parsedData?.summary?.total_rows} صنف</span>
                  </div>
                )}
                <table className="w-full text-xs text-right border border-gray-100 rounded-lg overflow-hidden">
                  <thead className="bg-gray-50 border-b border-gray-100 text-gray-500">
                    <tr>
                      <th className="p-2 font-medium">الاسم في الإكسيل</th>
                      <th className="p-2 font-medium">الاسم في النظام</th>
                      <th className="p-2 font-medium text-center">الكمية</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {parsedData?.items?.map((i: any, idx: number) => (
                      <tr key={idx} className={i.qty < 0 ? 'bg-red-50/50' : 'hover:bg-gray-50/50'}>
                        <td className="p-2 text-gray-400">{i.source_name}</td>
                        <td className="p-2 font-medium text-gray-900">{i.item_name}</td>
                        <td className="p-2 text-center font-bold text-blue-700">{i.qty}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

        </div>

        {/* Footer */}
        <div className="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-between">
          <button 
            onClick={reset}
            className="text-sm text-gray-500 hover:text-gray-700"
          >
            إعادة البدء
          </button>
          
          <div className="flex gap-3">
            <button 
              onClick={() => setIsOpen(false)}
              className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800"
            >
              إلغاء
            </button>
{step === 'preview' && (
                <button 
                  onClick={() => validationError ? null : confirmMutation.mutate()}
                  disabled={confirmMutation.isPending || !!validationError}
                  className="px-6 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 shadow-md shadow-green-200"
                >
                  {confirmMutation.isPending ? 'جاري الحفظ...' : 'تأكيد وحفظ البيانات'}
                </button>
              )}
          </div>
        </div>
      </div>
    </div>
  );
}