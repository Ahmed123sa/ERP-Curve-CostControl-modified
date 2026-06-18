# Enhancement Plan — تحسينات وتطوير جميع موديولات النظام

## Goal
تحليل شامل لكل موديول في ERP_CostControl، وتحديد الفجوات بين ما هو موجود فعلاً في الـ codebase وما هو مذكور في عرض المبيعات (Proposal)، مع اقتراح تحسينات عملية (جراحية) لكل موديول.

---

## Methodology
1. **قراءة الـ Proposal** — مذكور في العرض 9 موديولات + Roadmap 3 مراحل
2. **مسح الـ codebase الفعلي** — 19 module key من الـ backend، 50+ صفحة frontend، controllers
3. **تحديد "موجود ✅" vs "ناقص ❌"** لكل موديول
4. **مراجعة الاقتراحات للتأكد من أنها مش مكررة أو موجودة فعلاً**
5. **اقتراح تحسينات فقط للفجوات الفعلية**

---

## 1. Executive Command Center — لوحة التحكم

### Current State
موجود في `ClientDashboardController.php` و `dashboard/page.tsx`:
- ✅ KPI Cards (قيمة مخزون، مشتريات، فروق، عدد مخازن)
- ✅ Sparkline Charts (6-month trend)
- ✅ Multi-tier Alerts (Out of Stock, Critical, Warning)
- ✅ Menu Profitability Snapshot (أعلى/أقل 3 وصفات)
- ✅ Activity Timeline (آخر 5 حركات)
- ✅ Smart Summary Cards (4 mini-cards)

### Missing from Proposal
- ❌ Widget-based Dashboard — Drag & Drop لتخصيص الداشبورد
- ❌ Drill-down Analytics — الضغط على KPI يفتح التقرير المفصل

### Proposed Improvements
1. **Clickable KPI Cards** — الضغط على "قيمة المخزون" يودي على `/client/stock`
2. **Date Range Picker** — بدل شهر واحد، المستخدم يختار فترة مخصصة
3. **Comparison Mode** — مقارنة الشهر الحالي بالشهر السابق بالبطاقة نفسها
4. **Loading Skeletons لكل كارد** — مش skeleton واحد كبير

---

## 2. Procurement Intelligence — المشتريات

### Current State
موجود في `vouchers/purchase`, `vouchers/upload`:
- ✅ Purchase Orders (إضافة فواتير مشتريات متعددة الأصناف)
- ✅ Manual Entry مع Search & Select
- ✅ Bulk Upload (Excel مع Column Mapping)
- ✅ Auto Posting to Stock Ledger
- ✅ Export XLSX + PDF
- ✅ **Edit Purchase Voucher** (موجود فعلًا في صفحة المشتريات: رابط تعديل لكل فاتورة)

### Missing from Proposal
- ❌ AI Invoice OCR
- ❌ 3-way Matching (PO → Receipt → Invoice)
- ❌ Vendor Portal

### Proposed Improvements
1. **Pagination في جدول المشتريات** — حالياً `per_page: 200` في `purchase/page.tsx`
2. **Advanced Filters** — فلترة بتاريخ ومخزن ومورد (بدل تاريخ بس)
3. **Vendor Name List** — قائمة بسيطة بأسماء الموردين
4. **Purchase Dashboard** — إجمالي الشهر وتطور المشتريات

---

## 3. Inventory Control Engine — المخزون

### Current State
`stock/`, `stock/movement/`, `stock/opening/`, `stock/closing/`, `closing/`:
- ✅ Real-time Stock Position
- ✅ Movement Journal (Full Audit Trail)
- ✅ Monthly Closing Automation (COGS, Stock Valuation, Variance)
- ✅ Physical Inventory Reconciliation (نظري vs فعلي)
- ✅ Opening/Closing stock adjustments
- ✅ Stock Transfers بين المخازن
- ✅ **Threshold Config** — حقل `min_stock_level` موجود ومعدّل من صفحة الأصناف

### Missing from Proposal
- ❌ Barcode/RFID Integration
- ❌ Auto-reorder Engine (كان موجود وتمت إزالته في مرياشن `drop_max_stock_and_reorder`)
- ❌ Shelf-life Tracking

### Proposed Improvements
1. **Stock Movement Graph** — رسم بياني لحركة الصنف خلال 6 شهور (Recharts)
2. **Export Stock Report** — زر تصدير Excel للمخزون الحالي في `stock/page.tsx`
3. **Zero Stock Highlight** — إضافة تمييز للأصناف اللي رصيدها صفر (تمايز عن الأصناف السالبة)
4. **Barcode Field** — إضافة حقل "باركود" في item master (كان موجود `reorder_qty` واتمسح)
5. **Auto-reorder Alert List** — قائمة الأصناف تحت الحد الأدنى (لأن auto-reorder engine اتمسح من الـ DB)

---

## 4. Menu Engineering & Recipe Costing — هندسة القائمة

### Current State
`menu-engineering/`, `reconciliation/`, `report/`, `analytics/`:
- ✅ Recipe BOM (Bill of Materials) — Handsontable spreadsheet
- ✅ 5-level drill-down: Branches → Menus → Categories → Items → Sheet
- ✅ Reconciliation Engine (نظري vs فعلي)
- ✅ Upload Sales XLSX مع Auto-matching
- ✅ Profitability Analytics (Food Cost %, Contribution Margin)
- ✅ Bulk Operations (Update Qty, Add Item, Replace Item, Delete Item)
- ✅ Copy Menu/Category/Recipe
- ✅ Export Excel + PDF
- ✅ Saved Reconciliations (CRUD)
- ✅ **Recipe Version History** — موجود في `MenuRecipe` (حقل `version`) + `MenuRecipeVersion` (جدول `menu_engineering_recipe_versions` مع `snapshot` JSON)
- ✅ **Search in Recipe Sheet** — موجود في `menu-engineering/page.tsx` — `searchTerm` state + filter (سطر 692) + input (سطر 749)
- ✅ **Profit Margin in Backend** — `MenuRecipe::getMarginPctAttribute()` يحسب `100 - food_cost_pct`

### Missing from Proposal
- ❌ Version Control UI — الـ backend موجود (MenuRecipeVersion) لكن الـ frontend مش بيستخدمه
- ❌ Real-time Sales Feed (POS Integration)
- ❌ AI-driven Price Optimization
- ❌ Dietary & Allergen Tagging

### Proposed Improvements
1. **Version Control UI** — عرض سجل الإصدارات في الواجهة (الـ backend جاهز بجدول `menu_engineering_recipe_versions`)
2. **Recipe Lock** — قفل وصفة لمنع التعديل غير المصرح به
3. **Cost History Chart** — رسم بياني لتغير تكلفة الوصفة مع الوقت
4. **Profit Margin % في تقرير الـ Menu** — إظهار `margin_pct` في `menu-engineering/report/page.tsx` (الـ backend عنده `getMarginPctAttribute`)
5. **Dark Mode لـ report tab** — pages: `report/page.tsx` و `analytics/page.tsx` (analytics بيستخدم `bg-gray-50/50` بس)

---

## 5. Smart Analytics — التحليلات الذكية

### Current State
`client/analytics/` و `menu-engineering/analytics/`:
- ✅ 6 tabs: Inventory Alerts, Top Purchases, Price Changes, Cost Impact, Pareto (ABC), Stock Value
- ✅ Warehouse + Month filtering
- ✅ Color-coded alerts
- ✅ ABC analysis per recipe

### Missing from Proposal
- ❌ Predictive Analytics (ML Model)
- ❌ AI Waste Reduction
- ❌ Automated Variance Explanations (NLG)

### Proposed Improvements
1. **Export Analytics to Excel** — كل tab لها زر تصدير
2. **Share Analytics** — رابط مع الفلاتر (copy URL with query params)
3. **Alert History Page** — سجل الإنذارات (إمتى اتعمل ومين شافه)

---

## 6. Production Management — إدارة الإنتاج

### Current State
`production/`, `processing/`, `cyclic/`, `slaughter/`, `market-prices/`:
- ✅ Daily Production tracking (`production/page.tsx` — 422 سطر)
- ✅ Processing Batches (`processing/page.tsx` — 921 سطر)
- ✅ Cyclic Manufacturing (`cyclic/page.tsx` — 509 سطر)
- ✅ Market Price Monitoring
- ✅ Slaughterhouse Module (Carcass Yield)

### Missing from Proposal
- معظم الميزات موجودة بالفعل

### Proposed Improvements
1. **Production Yield Dashboard** — رسم بياني للـ Yield % لكل batch

---

## 7. Financial Module — المالية

### Current State
`financial/daily/`, `monthly/`, `closing/`, `advances/`:
- ✅ Daily Journal (`daily/page.tsx` — 775 سطر)
- ✅ Monthly Consolidation
- ✅ Financial Closing (`closing/page.tsx` — 665 سطر)
- ✅ Advances & Petty Cash
- ✅ Reports: Financial, Expenses, Diffs
- ✅ Export XLSX + PDF

### Missing from Proposal
- ❌ Full General Ledger
- ❌ Balance Sheet + Income Statement + Cash Flow
- ❌ ERP Integration Gateway

### Proposed Improvements
1. **Cumulative Reports** — أعمدة "الشهر الحالي vs السابق" في التقارير
2. **ربط المصروفات بالفروع** — كل فرع له مصروفاته الخاصة

---

## 8. Payroll & Workforce — الرواتب

### Current State
`payroll/employees/`, `attendance/`, `monthly/`:
- ✅ Employee Master (مع Contract Management)
- ✅ Attendance Tracking
- ✅ Monthly Payroll Computation

### Missing from Proposal
- ❌ Biometric Integration
- ❌ Overtime Engine

### Proposed Improvements
1. **Payroll History Graph** — رسم بياني لتغير الرواتب الشهرية

---

## 9. Cross-cutting Capabilities — خدمات أفقية

### Current State
موجود فعلاً: Warehouses, Transfers, Items, Mappings, Reports Suite, RBAC, White-label

### Proposed Improvements
1. **Activity Log Search** — بحث وتصفية في سجل النشاطات (حالياً `activity_log` بيظهر كـ source_type فقط في analytics)
2. **Client Onboarding Wizard** — خطوات إعداد العميل الجديد

---

## الفجوات الكبيرة — Issues حرجة

### 10. Cost Analysis Page (Placeholder)

**Problem:** `client/reports/cost/page.tsx` مجرد placeholder (9 أسطر):
```tsx
<h1>تحليل التكاليف</h1>
<p>سيتم إضافة تحليل التكاليف قريباً</p>
```

**Solution:** تنفيذ Cost Analysis فعلي:
- Food Cost % trends
- مقارنة بين الفروع
- Benchmarking (أعلى/أقل تكلفة)
- Export Excel

### 11. Client Reports — Diffs Page Gap

**Problem:** `client/reports/diffs/page.tsx`:
- مفيش month selector (شهر ثابت current month)
- بيستخدم endpoint غلط: `/client/stock/warehouse-summary` مش `/client/reports/diffs` الحقيقي (اللي عنده تفاصيل item-wise)

**Solution:** 
- إضافة month selector
- تغيير الـ API endpoint إلى `/client/reports/diffs`
- عرض تفاصيل الفروق (Item-wise) مش warehouse summary بس

### 12. Dark Mode Gaps

**Problem:** `menu-engineering/analytics` يستخدم `bg-gray-50/50` بدون dark variants

**Solution:** إضافة `dark:bg-gray-900/90` و `dark:border-gray-700/50` لكل الكاردات والجداول

---

## Implementation Priority

| Priority | Module | Improvement | Effort |
|----------|--------|-------------|--------|
| P0 | 11. Client Reports | Fix diffs page (month selector + endpoint) | Small |
| P0 | 10. Cost Analysis | تنفيذ الصفحة placeholder | Medium |
| P1 | 12. Dark Mode | Fix menu-engineering analytics | Small |
| P1 | 1. Dashboard | Clickable KPI Cards | Small |
| P1 | 4. Menu Engineering | Version Control UI (backend جاهز) | Medium |
| P1 | 5. Analytics | Export to Excel لكل tab | Small |
| P2 | 3. Inventory | Stock Movement Graph | Medium |
| P2 | 3. Inventory | Export Stock Report | Small |
| P2 | 4. Menu Engineering | Profit Margin % في تقرير menu | Small |
| P2 | 2. Procurement | Pagination + Filters | Medium |
| P3 | 7. Financial | Budget vs Actual | Large |
| P3 | 8. Payroll | Payroll History Graph | Medium |
| P3 | 6. Production | Yield Dashboard | Medium |

---

## Relevant Files

### Frontend
- `src/components/ui/ClientShell.tsx` — Client sidebar + module filtering
- `src/components/ui/AppShell.tsx` — Internal admin shell + module filtering
- `src/app/client/dashboard/page.tsx` — Client dashboard
- `src/app/(app)/dashboard/page.tsx` — Internal dashboard
- `src/app/client/analytics/page.tsx` — Client analytics (6 tabs)
- `src/app/(app)/menu-engineering/analytics/page.tsx` — Menu engineering analytics (ينقصه dark mode)
- `src/app/(app)/menu-engineering/report/page.tsx` — Menu engineering report (ينقصه margin_pct)
- `src/app/client/reports/cost/page.tsx` — **PLACEHOLDER** (يحتاج تنفيذ)
- `src/app/client/reports/diffs/page.tsx` — **Needs fix (month selector + wrong endpoint)**
- `src/app/client/reports/purchases/page.tsx`
- `src/app/client/reports/expenses/page.tsx`
- `src/app/client/reports/financial/page.tsx`
- `src/app/(app)/stock/page.tsx` — Current stock (ينقصه زر تصدير)
- `src/app/(app)/closing/page.tsx` — Monthly closing
- `src/app/(app)/reports/diffs/page.tsx` — Internal variance report
- `src/app/(app)/items/page.tsx` — Items & thresholds
- `src/app/(app)/vouchers/purchase/page.tsx` — Purchase (ينقصه pagination + filters)
- `src/app/(app)/vouchers/history/page.tsx` — History (عنده pagination + filters كامل)

### Backend
- `app/Models/MenuEngineering/MenuRecipe.php` — Recipe model (عنده `versions()` + `getMarginPctAttribute()`)
- `app/Models/MenuEngineering/MenuRecipeVersion.php` — Recipe version model (موجود جاهز)
- `app/Http/Controllers/ClientDashboardController.php` — Dashboard KPIs
- `app/Http/Controllers/ClientReportController.php` — Reports (purchases, menus, expenses, financial)
- `app/Http/Controllers/ClientModuleController.php` — Module definitions (19 modules)
- `database/seeders/ClientModuleSeeder.php` — 15 default modules

---

## Roadmap (منهجية التنفيذ)

### Phase 1 — Quick Wins (P0-P1)
1. Fix client diffs page (month selector + correct endpoint)
2. Implement cost analysis page (بدل placeholder)
3. Fix dark mode in menu-engineering analytics
4. Clickable KPI cards
5. Export analytics to Excel لكل tab
6. Profit Margin % في تقرير Menu Engineering

### Phase 2 — Core Enhancements (P1-P2)
7. Version Control UI للوصفات (backend جاهز)
8. Stock Movement Graph
9. Stock Report Export
10. Procurement Pagination + Filters

### Phase 3 — Advanced Features (P2-P3)
11. Budget vs Actual
12. Payroll history graph
13. Production yield dashboard
14. Auto-reorder alert list
