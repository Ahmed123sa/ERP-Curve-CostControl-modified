'use client';
import { useState, useEffect, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

export default function MarketPricesPage() {
  const qc = useQueryClient();
  const today = new Date().toISOString().slice(0, 10);
  const [date, setDate] = useState(today);
  const [tab, setTab] = useState<'scrape' | 'tracked'>('scrape');

  const { data: marketItems = [], isLoading: itemsLoading } = useQuery({
    queryKey: ['market-items', date],
    queryFn: () => api.get('/production/market-prices', { params: { date } }).then(r => r.data),
  });

  const { data: scraped = null, isLoading: scrapeLoading } = useQuery({
    queryKey: ['market-scrape'],
    queryFn: () => api.get('/production/market-prices/scrape').then(r => r.data),
    refetchInterval: 300_000,
  });

  const addItemMutation = useMutation({
    mutationFn: (body: { item_name: string; unit?: string; price?: number }) =>
      api.post('/production/market-prices/items', body).then(async () => {
        if (body.price) {
          await api.post('/production/market-prices', {
            date: today,
            prices: [{ item_name: body.item_name, price: body.price }],
          });
        }
      }),
    onSuccess: () => {
      toast.success('تم إضافة الصنف');
      qc.invalidateQueries({ queryKey: ['market-items'] });
      qc.invalidateQueries({ queryKey: ['market-items-available'] });
      qc.invalidateQueries({ queryKey: ['market-scrape'] });
      qc.invalidateQueries({ queryKey: ['market-latest'] });
    },
    onError: (err: any) => toast.error(err?.response?.data?.message || 'خطأ'),
  });

  const removeItemMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/production/market-prices/items/${id}`),
    onSuccess: () => {
      toast.success('تم حذف الصنف');
      qc.invalidateQueries({ queryKey: ['market-items'] });
      qc.invalidateQueries({ queryKey: ['market-scrape'] });
      qc.invalidateQueries({ queryKey: ['market-latest'] });
    },
  });

  const saveMutation = useMutation({
    mutationFn: (prices: any[]) => api.post('/production/market-prices', { date, prices }),
    onSuccess: () => {
      toast.success('تم حفظ الأسعار');
      qc.invalidateQueries({ queryKey: ['market-items', date] });
      qc.invalidateQueries({ queryKey: ['market-latest'] });
    },
    onError: () => toast.error('خطأ في الحفظ'),
  });

  const [priceInputs, setPriceInputs] = useState<Record<string, string>>({});
  const [showAddForm, setShowAddForm] = useState(false);
  const [newItemName, setNewItemName] = useState('');
  const [newItemUnit, setNewItemUnit] = useState('كجم');
  const [newItemPrice, setNewItemPrice] = useState('');

  const scrapedPriceByName = useMemo(() => {
    const map: Record<string, number> = {};
    if (!scraped) return map;
    for (const key of Object.keys(scraped)) {
      const section = (scraped as any)[key];
      if (!Array.isArray(section)) continue;
      for (const item of section) {
        if (item.price && !map[item.name]) map[item.name] = item.price;
      }
    }
    return map;
  }, [scraped]);

  useEffect(() => {
    if (!marketItems.length) return;
    const map: Record<string, string> = {};
    marketItems.forEach((m: any) => {
      if (m.price !== null) {
        map[m.item_name] = String(m.price);
      } else if (scrapedPriceByName[m.item_name]) {
        map[m.item_name] = String(scrapedPriceByName[m.item_name]);
      } else {
        map[m.item_name] = '';
      }
    });
    setPriceInputs(map);
  }, [marketItems, scrapedPriceByName]);

  const handleSave = () => {
    const prices = Object.entries(priceInputs)
      .filter(([_, val]) => val.trim() !== '')
      .map(([item_name, val]) => ({ item_name, price: parseFloat(val) || 0 }));
    if (!prices.length) { toast('لا توجد أسعار للحفظ'); return; }
    saveMutation.mutate(prices);
  };

  const trackedNames = new Set(marketItems.map((m: any) => m.item_name));

  const renderScrapedSection = (title: string, items: any[], section: string) => {
    if (!items.length) return null;
    return (
      <div key={section} className="mb-6">
        <h3 className="text-sm font-semibold text-gray-600 mb-2">{title}</h3>
        <div className="flex flex-wrap gap-2">
          {items.map((item: any) => {
            const tracked = trackedNames.has(item.name);
            return (
              <div key={`${section}-${item.name}`}
                className={`px-3 py-2 rounded-lg border text-sm flex items-center gap-2 ${
                  tracked ? 'bg-green-50 border-green-200' : 'bg-white border-gray-100 hover:border-blue-200'
                }`}>
                <span className="font-medium text-gray-800">{item.name}</span>
                <span className="text-gray-400 text-xs">{item.unit}</span>
                {item.price && <span className="text-blue-700 font-mono">{item.price}</span>}
                {!tracked && (
                  <button onClick={() => addItemMutation.mutate({ item_name: item.name, unit: item.unit, price: item.price })}
                    className="text-xs text-blue-600 hover:text-blue-800 mr-1">➕</button>
                )}
                {tracked && <span className="text-xs text-green-600">✓</span>}
              </div>
            );
          })}
        </div>
      </div>
    );
  };

  const sections = scraped ? [
    { title: 'أسعار الدواجن اليوم', section: 'poultry',   items: scraped.poultry },
    { title: 'اسعار الخامات',        section: 'materials', items: scraped.materials },
    { title: 'بورصة الكتاكيت',       section: 'chicks',    items: scraped.chicks },
    { title: 'بورصة الدواجن',        section: 'exchange',  items: scraped.exchange },
    { title: 'أسعار الأعلاف',        section: 'feed',      items: scraped.feed },
  ] : [];

  return (
    <div className="space-y-4" dir="rtl">
      <div className="flex gap-1 bg-gray-100 rounded-lg p-1 w-fit">
        <button onClick={() => setTab('scrape')}
          className={`px-4 py-1.5 text-sm rounded-md transition-colors ${
            tab === 'scrape' ? 'bg-white shadow text-gray-800 font-medium' : 'text-gray-500 hover:text-gray-700'
          }`}>📡 الأسعار الحية</button>
        <button onClick={() => setTab('tracked')}
          className={`px-4 py-1.5 text-sm rounded-md transition-colors ${
            tab === 'tracked' ? 'bg-white shadow text-gray-800 font-medium' : 'text-gray-500 hover:text-gray-700'
          }`}>📋 المتابعة ({marketItems.length})</button>
      </div>

      {tab === 'scrape' && (
        <div>
          {scrapeLoading ? (
            <p className="text-gray-400">جاري تحميل الأسعار من البورصة...</p>
          ) : !scraped ? (
            <p className="text-gray-400">تعذر الاتصال بالموقع</p>
          ) : (
            sections.map(s => renderScrapedSection(s.title, s.items, s.section))
          )}
        </div>
      )}

      {tab === 'tracked' && (
        <div>
          {itemsLoading ? (
            <p className="text-gray-400">جاري التحميل...</p>
          ) : marketItems.length === 0 ? (
            <div className="bg-white border border-gray-100 rounded-xl p-12 text-center">
              <p className="text-gray-400 mb-3">لم تضف أي أصناف للمتابعة بعد</p>
              <p className="text-xs text-gray-300">اضغط &quot;➕ إضافة صنف يدوي&quot; أو اذهب إلى &quot;الأسعار الحية&quot; واضغط ➕</p>
            </div>
          ) : (
            <>
              <div className="flex items-center gap-3 mb-3">
                <h3 className="text-sm font-semibold text-gray-600">الأصناف المتابعة</h3>
                <input type="date" value={date} onChange={(e) => { setDate(e.target.value); setPriceInputs({}); }}
                  className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none" />
                <button onClick={() => { setShowAddForm(v => !v); setNewItemName(''); setNewItemUnit('كجم'); setNewItemPrice(''); }}
                  className="px-3 py-1.5 text-sm bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 border border-blue-200">
                  {showAddForm ? '❌ إلغاء' : '➕ إضافة صنف يدوي'}
                </button>
              </div>
              {showAddForm && (
                <div className="bg-blue-50/30 border border-blue-100 rounded-xl p-4 mb-4 flex items-end gap-3">
                  <div className="flex-1">
                    <label className="text-xs text-gray-500 block mb-1">اسم الصنف</label>
                    <input value={newItemName} onChange={e => setNewItemName(e.target.value)}
                      className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400" placeholder="مثال: بانيه" />
                  </div>
                  <div className="w-24">
                    <label className="text-xs text-gray-500 block mb-1">الوحدة</label>
                    <input value={newItemUnit} onChange={e => setNewItemUnit(e.target.value)}
                      className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400 text-center" />
                  </div>
                  <div className="w-32">
                    <label className="text-xs text-gray-500 block mb-1">السعر (اختياري)</label>
                    <input type="number" step="0.01" min="0" value={newItemPrice} onChange={e => setNewItemPrice(e.target.value)}
                      className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-blue-400 text-center" placeholder="٠" />
                  </div>
                  <button onClick={() => {
                    if (!newItemName.trim()) { toast('ادخل اسم الصنف'); return; }
                    addItemMutation.mutate(
                      { item_name: newItemName.trim(), unit: newItemUnit.trim(), price: newItemPrice ? parseFloat(newItemPrice) : undefined },
                      { onSuccess: () => { setShowAddForm(false); setNewItemName(''); setNewItemPrice(''); } }
                    );
                  }} disabled={addItemMutation.isPending}
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
                    {addItemMutation.isPending ? '...' : 'إضافة'}
                  </button>
                </div>
              )}
              <div className="bg-white border border-gray-100 rounded-xl overflow-hidden">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-gray-50 text-gray-500 text-xs border-b border-gray-100">
                      <th className="px-4 py-3 text-right font-medium">الصنف</th>
                      <th className="px-4 py-3 text-right font-medium">الوحدة</th>
                      <th className="px-4 py-3 text-center font-medium">آخر سعر</th>
                      <th className="px-4 py-3 text-center font-medium">آخر تحديث</th>
                      <th className="px-4 py-3 text-center font-medium">سعر {date}</th>
                      <th className="px-4 py-3 text-center w-16"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {marketItems.map((m: any) => (
                      <tr key={m.id} className="hover:bg-gray-50/50">
                        <td className="px-4 py-3 font-medium text-gray-800">{m.item_name}</td>
                        <td className="px-4 py-3 text-gray-400">{m.unit}</td>
                        <td className="px-4 py-3 text-center font-mono text-blue-700">
                          {m.last_price !== null ? `${m.last_price} ج` : '—'}
                        </td>
                        <td className="px-4 py-3 text-center text-xs text-gray-400">
                          {m.last_date ?? '—'}
                        </td>
                        <td className="px-4 py-3 text-center">
                          <input type="number" step="0.01" min="0"
                            value={priceInputs[m.item_name] ?? ''}
                            onChange={(e) => setPriceInputs(prev => ({ ...prev, [m.item_name]: e.target.value }))}
                            className="w-28 px-2 py-1 text-sm text-center border border-gray-200 rounded-lg outline-none focus:border-blue-400"
                            placeholder="السعر" />
                        </td>
                        <td className="px-4 py-3 text-center">
                          <button onClick={() => { if (confirm('حذف هذا الصنف من المتابعة؟')) removeItemMutation.mutate(m.id); }}
                            className="text-xs text-red-400 hover:text-red-700">✕</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="flex justify-end mt-3">
                <button onClick={handleSave} disabled={saveMutation.isPending}
                  className="px-6 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                  {saveMutation.isPending ? 'جاري الحفظ...' : '💾 حفظ'}
                </button>
                <span className="text-xs text-gray-400 self-center mr-2">احفظ الأسعار المدخلة لليوم</span>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}
