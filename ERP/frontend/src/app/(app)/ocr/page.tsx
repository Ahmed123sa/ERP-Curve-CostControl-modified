'use client';

import { useState, useRef, useCallback, useEffect } from 'react';
import { api } from '@/lib/api';
import { useAuthStore } from '@/lib/store';
import { PageHeader } from '@/components/ui/AppShell';
import { Upload, Download, Plus, X, Search, Trash2, Layers } from 'lucide-react';
import toast from 'react-hot-toast';
import * as XLSX from 'xlsx';

// ── Types ──
interface Row { id: string; item: string; unit: string; qty: string; price: string; }
interface Doc { id: string; file: File; imageUrl: string; rows: Row[]; }
interface MemoryItem { id: string; name: string; unit: string; category: string; default_cost: number; }

const SESSION_KEY = 'ocr_session';

// ── Levenshtein ──
function getEditDistance(a: string, b: string): number {
  if (a.length === 0) return b.length;
  if (b.length === 0) return a.length;
  const matrix = Array(a.length + 1).fill(null).map(() => Array(b.length + 1).fill(null));
  for (let i = 0; i <= a.length; i++) matrix[i][0] = i;
  for (let j = 0; j <= b.length; j++) matrix[0][j] = j;
  for (let i = 1; i <= a.length; i++)
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      matrix[i][j] = Math.min(matrix[i - 1][j] + 1, matrix[i][j - 1] + 1, matrix[i - 1][j - 1] + cost);
    }
  return matrix[a.length][b.length];
}

function findClosestVocabMatch(word: string, dict: string[]): string {
  if (!word || word.length < 2 || dict.length === 0) return word;
  const wordLower = word.toLowerCase();
  if (dict.includes(word)) return word;
  const exactMatch = dict.find(k => k.toLowerCase() === wordLower);
  if (exactMatch) return exactMatch;
  let bestMatch = word;
  let highestSimilarity = 0;
  for (const known of dict) {
    const knownLower = known.toLowerCase();
    if (knownLower.includes(wordLower) || wordLower.includes(knownLower)) {
      const lenRatio = Math.min(word.length, known.length) / Math.max(word.length, known.length);
      if (lenRatio >= 0.5) return word;
    }
    if (Math.min(word.length, known.length) / Math.max(word.length, known.length) < 0.5) continue;
    const dist = getEditDistance(word, known);
    const maxLen = Math.max(word.length, known.length);
    const similarity = (maxLen - Math.min(dist, maxLen)) / maxLen;
    if (similarity >= 0.85 && similarity > highestSimilarity) {
      highestSimilarity = similarity;
      bestMatch = known;
    }
  }
  return highestSimilarity >= 0.85 ? bestMatch : word;
}

// ── Session persistence ──
function saveSession(docs: Doc[], activeId: string | null) {
  try {
    const data = docs.map(d => ({
      id: d.id, imageUrl: d.imageUrl, fileName: d.file.name, rows: d.rows, fileSize: d.file.size, fileType: d.file.type,
    }));
    localStorage.setItem(SESSION_KEY, JSON.stringify({ docs: data, activeId }));
  } catch { /* quota exceeded */ }
}

function loadSession(): { docs: Doc[]; activeId: string | null } | null {
  try {
    const raw = localStorage.getItem(SESSION_KEY);
    if (!raw) return null;
    const { docs, activeId } = JSON.parse(raw);
    const restored: Doc[] = docs.map((d: any) => ({
      id: d.id, imageUrl: d.imageUrl,
      file: new File([], d.fileName, { type: d.fileType }),
      rows: d.rows,
    }));
    return { docs: restored, activeId };
  } catch { return null; }
}

// ── Add batch rows modal ──
function BatchAddModal({ open, onClose, onAdd }: { open: boolean; onClose: () => void; onAdd: (n: number) => void }) {
  const [count, setCount] = useState(10);
  if (!open) return null;
  return (
    <div className="fixed inset-0 bg-black/30 z-50 flex items-center justify-center" onClick={e => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="bg-white rounded-xl shadow-xl p-6 w-80" onClick={e => e.stopPropagation()}>
        <h3 className="font-bold text-gray-800 mb-3">إضافة صفوف</h3>
        <input type="number" min={1} max={500} value={count} onChange={e => setCount(Math.min(500, Math.max(1, parseInt(e.target.value) || 1)))}
          className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400 mb-4" />
        <div className="flex gap-2 justify-end">
          <button onClick={onClose} className="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50">إلغاء</button>
          <button onClick={() => { onAdd(count); onClose(); }} className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">إضافة</button>
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════
//  MAIN PAGE
// ═══════════════════════════════════════════
export default function OcrPage() {
  const { currentClient } = useAuthStore();

  const [docs, setDocs] = useState<Doc[]>([]);
  const [activeDocId, setActiveDocId] = useState<string | null>(null);
  const activeDoc = docs.find(d => d.id === activeDocId);

  const [memoryItems, setMemoryItems] = useState<MemoryItem[]>([]);
  const [memoryNames, setMemoryNames] = useState<string[]>([]);
  const [memoryLoading, setMemoryLoading] = useState(false);
  const [imageZoom, setImageZoom] = useState(100);
  const [imageRotation, setImageRotation] = useState(0);
  const [imagePosition, setImagePosition] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 });
  const [activeDropdown, setActiveDropdown] = useState<string | null>(null);
  const [batchOpen, setBatchOpen] = useState(false);
  const [showMemory, setShowMemory] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const dropRef = useRef<HTMLDivElement>(null);

  // Restore session
  useEffect(() => {
    const saved = loadSession();
    if (saved && saved.docs.length > 0) {
      setDocs(saved.docs);
      setActiveDocId(saved.activeId);
    }
  }, []);

  // Save session on change
  useEffect(() => { if (docs.length > 0) saveSession(docs, activeDocId); }, [docs, activeDocId]);

  // Load memory from API with localStorage cache
  const loadMemory = useCallback(async (force = false) => {
    if (!currentClient?.id) return;
    const cacheKey = `ocr_memory_${currentClient.id}`;
    if (!force) {
      try {
        const cached = localStorage.getItem(cacheKey);
        if (cached) {
          const items: MemoryItem[] = JSON.parse(cached);
          setMemoryItems(items);
          setMemoryNames(items.map(i => i.name));
          return;
        }
      } catch { /* ignore */ }
    }
    setMemoryLoading(true);
    try {
      const res = await api.get('/menu-engineering/ocr/items');
      const items: MemoryItem[] = res.data;
      setMemoryItems(items);
      setMemoryNames(items.map(i => i.name));
      try { localStorage.setItem(cacheKey, JSON.stringify(items)); } catch { /* quota */ }
    } catch { toast.error('فشل تحميل الأصناف'); }
    setMemoryLoading(false);
  }, [currentClient?.id]);

  useEffect(() => { loadMemory(); }, [loadMemory]);

  const updateDoc = useCallback((id: string, updates: Partial<Doc>) => {
    setDocs(prev => prev.map(d => d.id === id ? { ...d, ...updates } : d));
  }, []);

  const updateRow = useCallback((docId: string, rowId: string, updates: Partial<Row>) => {
    setDocs(prev => prev.map(d => {
      if (d.id !== docId) return d;
      return { ...d, rows: d.rows.map(r => r.id === rowId ? { ...r, ...updates } : r) };
    }));
  }, []);

  const addRow = (docId: string, index: number) => {
    setDocs(prev => prev.map(d => {
      if (d.id !== docId) return d;
      const newRows = [...d.rows];
      newRows.splice(index + 1, 0, { id: `r-${Date.now()}-${Math.random().toString(36).substr(2, 4)}`, item: '', unit: '', qty: '', price: '' });
      return { ...d, rows: newRows };
    }));
  };

  const addBatchRows = (docId: string, count: number) => {
    const newRows: Row[] = Array.from({ length: count }, () => ({
      id: `r-${Date.now()}-${Math.random().toString(36).substr(2, 4)}`, item: '', unit: '', qty: '', price: '',
    }));
    setDocs(prev => prev.map(d => d.id === docId ? { ...d, rows: [...d.rows, ...newRows] } : d));
    toast.success(`تم إضافة ${count} صف`);
  };

  const removeRow = (docId: string, rowId: string) => {
    setDocs(prev => prev.map(d => {
      if (d.id !== docId) return d;
      return { ...d, rows: d.rows.filter(r => r.id !== rowId) };
    }));
  };

  // Drag & drop handlers
  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    if (e.dataTransfer.files.length > 0) handleFiles(e.dataTransfer.files);
  }, []);

  const handleDragOver = (e: React.DragEvent) => { e.preventDefault(); };

  // Image file upload (no auto OCR)
  const handleFiles = (files: FileList) => {
    for (let i = 0; i < files.length; i++) {
      const file = files[i];
      if (!file.type.startsWith('image/')) { toast.error(`${file.name}: ليس ملف صورة`); continue; }
      const id = Math.random().toString(36).substr(2, 9);
      const url = URL.createObjectURL(file);
      const newDoc: Doc = { id, file, imageUrl: url, rows: [] };
      setDocs(prev => [...prev, newDoc]);
      if (i === 0 && !activeDocId) setActiveDocId(id);
    }
    if (files.length > 0) toast.success(`تم رفع ${files.length} صورة`);
  };

  const exportExcel = () => {
    if (!activeDoc || activeDoc.rows.length === 0) return toast.error('لا يوجد بيانات للتصدير');
    const data: any[] = [{ 'م': `--- ${activeDoc.file.name} ---` }];
    activeDoc.rows.filter(r => r.item.trim().length > 0).forEach((r, i) => {
      data.push({ 'م': i + 1, 'الصنف': r.item, 'الوحدة': r.unit, 'الكمية': r.qty, 'السعر': r.price });
    });
    if (data.length <= 1) return toast.error('لا يوجد بيانات للتصدير!');
    const ws = XLSX.utils.json_to_sheet(data);
    ws['!cols'] = [{ wch: 6 }, { wch: 30 }, { wch: 10 }, { wch: 12 }, { wch: 12 }];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'الجرد');
    XLSX.writeFile(wb, `جرد_${new Date().toLocaleDateString('ar-EG')}.xlsx`);
    toast.success('تم التصدير بنجاح');
  };

  const clearAll = () => {
    if (docs.length === 0) return;
    if (!window.confirm('مسح كل الصور والبيانات؟')) return;
    docs.forEach(d => URL.revokeObjectURL(d.imageUrl));
    setDocs([]);
    setActiveDocId(null);
    localStorage.removeItem(SESSION_KEY);
  };

  // ── Suggestions: show items matching typed text ──
  const getSuggestions = (query: string): MemoryItem[] => {
    if (!query.trim()) return [];
    const q = query.trim().toLowerCase();
    return memoryItems.filter(m => m.name.toLowerCase().includes(q)).slice(0, 20);
  };

  return (
    <div className="flex-1 flex flex-col h-full" dir="rtl">
      <PageHeader title="قارئ OCR" subtitle="إدخال يدوي مع اقتراحات من الأصناف" />

      <div className="flex-1 flex overflow-hidden">
        {/* ─── Sidebar ─── */}
        <aside className="w-64 bg-white border-l border-gray-200 flex flex-col flex-shrink-0">
          <div className="p-4 border-b border-gray-200 space-y-2">
            <button onClick={() => fileInputRef.current?.click()}
              className="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-all">
              <Upload className="w-4 h-4" /> رفع صورة
            </button>
            <input ref={fileInputRef} type="file" multiple accept="image/*" className="hidden" onChange={e => e.target.files && handleFiles(e.target.files)} />
            <div ref={dropRef} onDrop={handleDrop} onDragOver={handleDragOver}
              className="border-2 border-dashed border-gray-300 rounded-lg p-3 text-center text-[10px] text-gray-400 hover:border-blue-400 hover:text-blue-500 transition-colors cursor-pointer"
              onClick={() => fileInputRef.current?.click()}>
              أو اسحب الصور هنا
            </div>
          </div>

          <div className="flex-1 overflow-y-auto p-3 space-y-2">
            {docs.map(doc => (
              <div key={doc.id} onClick={() => setActiveDocId(doc.id)}
                className={`p-3 rounded-lg border cursor-pointer transition-all ${activeDocId === doc.id ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:bg-gray-50'}`}>
                <div className="flex gap-3 items-center">
                  <img src={doc.imageUrl} className="w-10 h-10 rounded object-cover border border-gray-200" />
                  <div className="min-w-0 flex-1">
                    <div className="text-xs font-medium text-gray-800 truncate">{doc.file.name}</div>
                    <div className="text-[10px] text-gray-500">{doc.rows.length} سطر</div>
                  </div>
                  <button onClick={e => { e.stopPropagation(); setDocs(prev => prev.filter(d => d.id !== doc.id)); if (activeDocId === doc.id) setActiveDocId(null); }}
                    className="text-gray-400 hover:text-red-500 p-1"><Trash2 className="w-3 h-3" /></button>
                </div>
              </div>
            ))}
            {docs.length === 0 && (
              <div className="text-center text-gray-400 py-8 text-sm">ارفع صورة للبدء</div>
            )}
          </div>

          {/* Memory */}
          <div className="border-t border-gray-200">
            <button onClick={() => setShowMemory(!showMemory)}
              className="w-full flex items-center justify-between px-4 py-3 text-xs font-medium text-gray-500 hover:bg-gray-50">
              <span>الذاكرة ({memoryNames.length})</span>
              <span>{showMemory ? '▲' : '▼'}</span>
            </button>
            {showMemory && (
              <div className="p-3 max-h-40 overflow-y-auto bg-gray-50/50">
                {memoryLoading && <p className="text-[10px] text-blue-500">جاري التحميل...</p>}
                {!memoryLoading && memoryItems.length === 0 && <p className="text-[10px] text-gray-400">لا توجد أصناف</p>}
                <div className="flex flex-wrap gap-1">
                  {memoryItems.slice(0, 50).map(m => (
                    <span key={m.id} className="px-2 py-0.5 bg-white border border-gray-200 text-[10px] text-gray-600 rounded">
                      {m.name} <span className="text-gray-400">{m.unit}</span>
                    </span>
                  ))}
                </div>
                <button onClick={() => loadMemory(true)} className="mt-2 text-[10px] text-blue-600 hover:text-blue-800">
                  تحديث من الخادم
                </button>
              </div>
            )}
          </div>
        </aside>

        {/* ─── Main ─── */}
        <main className="flex-1 flex flex-col bg-gray-50/30">
          {activeDoc ? (
            <div className="flex-1 flex overflow-hidden">
              {/* Table */}
              <div className="flex-1 p-4 flex flex-col overflow-hidden">
                {/* Toolbar */}
                <div className="flex items-center gap-2 mb-3 flex-wrap">
                  <button onClick={() => addRow(activeDoc.id, -1)}
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 flex items-center gap-2">
                    <Plus className="w-4 h-4" /> إضافة صف
                  </button>
                  <button onClick={() => setBatchOpen(true)}
                    className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 flex items-center gap-2">
                    <Layers className="w-4 h-4" /> إضافة عدة صفوف
                  </button>
                  <button onClick={() => updateDoc(activeDoc.id, { rows: [] })}
                    className="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 border">
                    مسح الجدول
                  </button>
                  <button onClick={exportExcel}
                    className="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 flex items-center gap-2">
                    <Download className="w-4 h-4" /> Excel
                  </button>
                  {docs.length > 1 && (
                    <button onClick={clearAll}
                      className="px-3 py-2 text-xs text-gray-500 hover:text-red-600 border rounded-lg">
                      مسح الكل
                    </button>
                  )}
                </div>

                {/* Table count */}
                <div className="text-xs text-gray-500 mb-2 flex items-center gap-3">
                  <span className="bg-white px-3 py-1.5 rounded-lg border border-gray-200">
                    <strong className="text-gray-800">{activeDoc.rows.length}</strong> صف
                  </span>
                  <span className="bg-white px-3 py-1.5 rounded-lg border border-gray-200">
                    <strong className="text-emerald-700">{activeDoc.rows.filter(r => r.item.trim()).length}</strong> معبأ
                  </span>
                </div>

                {/* Data table */}
                <div className="flex-1 bg-white border border-gray-200 rounded-lg overflow-hidden flex flex-col">
                  <div className="grid grid-cols-12 gap-0 bg-gradient-to-r from-blue-50 to-indigo-50 border-b border-gray-200 text-xs font-bold text-gray-700">
                    <div className="col-span-1 p-2.5 text-center">#</div>
                    <div className="col-span-5 p-2.5">الصنف</div>
                    <div className="col-span-2 p-2.5 text-center">الوحدة</div>
                    <div className="col-span-2 p-2.5 text-center">الكمية</div>
                    <div className="col-span-2 p-2.5 text-center">السعر</div>
                  </div>
                  <div className="overflow-y-auto flex-1">
                    {activeDoc.rows.length === 0 && (
                      <div className="flex flex-col items-center justify-center py-16 text-gray-400">
                        <Search className="w-8 h-8 mb-2" />
                        <p className="text-sm">لا توجد بيانات — أضف صفوفاً يدوياً</p>
                        <div className="flex gap-2 mt-3">
                          <button onClick={() => addRow(activeDoc.id, -1)} className="px-4 py-1.5 border border-gray-300 rounded-lg text-xs hover:bg-gray-50">إضافة صف</button>
                          <button onClick={() => setBatchOpen(true)} className="px-4 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-xs hover:bg-indigo-200">إضافة عدة صفوف</button>
                        </div>
                      </div>
                    )}
                    {activeDoc.rows.map((r, i) => (
                      <div key={r.id} className="grid grid-cols-12 gap-0 items-center border-b border-gray-100 hover:bg-blue-50/30 group">
                        <div className="col-span-1 p-2 text-center text-xs text-gray-400 relative">
                          {i + 1}
                          <button onClick={() => removeRow(activeDoc.id, r.id)}
                            className="absolute inset-0 opacity-0 group-hover:opacity-100 flex items-center justify-center">
                            <X className="w-3 h-3 text-red-500 bg-white rounded-full" />
                          </button>
                        </div>
                        <div className="col-span-5 p-1 relative">
                          <input value={r.item} onFocus={() => setActiveDropdown(r.id)}
                            onChange={e => updateRow(activeDoc.id, r.id, { item: e.target.value })}
                            onBlur={() => setTimeout(() => setActiveDropdown(null), 200)}
                            className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded outline-none focus:border-blue-400 bg-white"
                            placeholder="اكتب اسم الصنف..." />
                          {activeDropdown === r.id && r.item.trim().length > 0 && (
                            <div className="absolute z-50 w-[300%] mt-1 bg-white border border-gray-200 rounded-lg shadow-xl overflow-hidden">
                              <div className="max-h-60 overflow-y-auto p-1">
                                {getSuggestions(r.item).map(m => (
                                  <button key={m.id} type="button"
                                    onMouseDown={e => { e.preventDefault(); updateRow(activeDoc.id, r.id, { item: m.name }); setActiveDropdown(null); }}
                                    className="w-full text-right px-3 py-2 text-sm hover:bg-blue-50 rounded flex items-center justify-between gap-2 border-b border-gray-50 last:border-0">
                                    <span className="font-medium text-gray-800">{m.name}</span>
                                    <span className="text-[10px] text-gray-400 shrink-0">
                                      {m.unit}{m.category ? ` · ${m.category}` : ''}
                                    </span>
                                  </button>
                                ))}
                                {getSuggestions(r.item).length === 0 && (
                                  <div className="px-3 py-2 text-xs text-gray-400">لا توجد نتائج</div>
                                )}
                              </div>
                            </div>
                          )}
                        </div>
                        <div className="col-span-2 p-1">
                          <input value={r.unit} onChange={e => updateRow(activeDoc.id, r.id, { unit: e.target.value })}
                            className="w-full px-2 py-1.5 text-sm text-center border border-gray-200 rounded outline-none focus:border-blue-400 bg-white" placeholder="كجم" />
                        </div>
                        <div className="col-span-2 p-1">
                          <input value={r.qty} onChange={e => updateRow(activeDoc.id, r.id, { qty: e.target.value })}
                            className="w-full px-2 py-1.5 text-sm text-center border border-gray-200 rounded outline-none focus:border-blue-400 bg-white font-medium text-blue-700"
                            placeholder="0" />
                        </div>
                        <div className="col-span-2 p-1 relative">
                          <input value={r.price} onChange={e => updateRow(activeDoc.id, r.id, { price: e.target.value })}
                            className="w-full px-2 py-1.5 text-sm text-center border border-gray-200 rounded outline-none focus:border-blue-400 bg-white"
                            placeholder="0.00" />
                          <button onClick={() => addRow(activeDoc.id, i)}
                            className="absolute -left-1 top-1/2 -translate-y-1/2 opacity-0 group-hover:opacity-100 p-1 bg-blue-600 text-white rounded text-[10px] leading-none"><Plus className="w-3 h-3" /></button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>

              {/* Image viewer */}
              <div className="w-[40%] bg-gray-900 p-4 flex flex-col relative overflow-hidden">
                <div className="absolute top-2 left-2 z-30 flex items-center gap-1 bg-black/70 rounded-lg p-1.5">
                  <button onClick={() => setImageZoom(Math.max(25, imageZoom - 25))} className="w-7 h-7 bg-gray-700 hover:bg-gray-600 text-white rounded text-xs">−</button>
                  <span className="text-white text-xs font-bold w-10 text-center">{imageZoom}%</span>
                  <button onClick={() => setImageZoom(Math.min(300, imageZoom + 25))} className="w-7 h-7 bg-gray-700 hover:bg-gray-600 text-white rounded text-xs">+</button>
                  <button onClick={() => { setImageZoom(100); setImagePosition({ x: 0, y: 0 }); setImageRotation(0); }} className="px-2 h-7 bg-blue-600 hover:bg-blue-500 text-white rounded text-xs font-medium">إعادة</button>
                  <button onClick={() => setImageRotation((imageRotation - 15) % 360)} className="w-7 h-7 bg-gray-700 hover:bg-gray-600 text-white rounded text-xs">↶</button>
                  <button onClick={() => setImageRotation((imageRotation + 15) % 360)} className="w-7 h-7 bg-gray-700 hover:bg-gray-600 text-white rounded text-xs">↷</button>
                </div>
                <div className="flex-1 flex items-center justify-center overflow-auto"
                  onMouseDown={e => { setIsDragging(true); setDragStart({ x: e.clientX - imagePosition.x, y: e.clientY - imagePosition.y }); }}
                  onMouseMove={e => { if (isDragging) setImagePosition({ x: e.clientX - dragStart.x, y: e.clientY - dragStart.y }); }}
                  onMouseUp={() => setIsDragging(false)}
                  onMouseLeave={() => setIsDragging(false)}>
                  <img src={activeDoc.imageUrl}
                    style={{ transform: `translate(${imagePosition.x}px, ${imagePosition.y}px) scale(${imageZoom / 100}) rotate(${imageRotation}deg)`, cursor: isDragging ? 'grabbing' : 'grab' }}
                    className="max-w-full max-h-full object-contain select-none" draggable={false} />
                </div>
              </div>
            </div>
          ) : (
            <div className="flex-1 flex flex-col items-center justify-center text-gray-400">
              <Upload className="w-16 h-16 mb-4 opacity-30" />
              <h2 className="text-xl font-bold mb-1 opacity-50">ارفع صورة للبدء</h2>
              <p className="text-sm opacity-50">اسحب صورة أو اضغط على رفع — ثم اكتب الأصناف يدوياً</p>
            </div>
          )}
        </main>
      </div>

      <BatchAddModal open={batchOpen} onClose={() => setBatchOpen(false)}
        onAdd={n => { if (activeDocId) addBatchRows(activeDocId, n); }} />
    </div>
  );
}
