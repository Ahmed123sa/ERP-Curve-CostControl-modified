# خطة تطوير Client Portal الاحترافي

> **الهدف:** تحويل ERP Cost Control إلى منصة SaaS بمظهر احترافي لكل عميل (Client Portal) مع Dark/Light mode، Charts تفاعلية، وتقارير قابلة للتصدير.
>
> **تاريخ الخطة:** 14 يونيو 2026

---

## 🧱 الهيكل العام

```
┌─────────────────────────────────────────────────────────────┐
│                        ERP SaaS                             │
├──────────────────────────┬──────────────────────────────────┤
│     👤 Client Portal     │      ⚙️ Admin Panel             │
│                          │                                  │
│  Dashboard + Charts      │  إدارة العملاء                   │
│  تقارير مع تصدير         │  إدارة المستخدمين                 │
│  Agent AI (لاحقاً)       │  إعدادات النظام                   │
│                          │  كل الموديولات                    │
└──────────────────────────┴──────────────────────────────────┘
```

---

## 🎯 خطة العمل (5 مراحل)

### المرحلة 1: نظام اشتراكات الموديولات + Dark/Light Mode

#### Backend

**1.1 إنشاء جدول `client_modules`**

```php
Schema::create('client_modules', function (Blueprint $table) {
    $table->id();
    $table->uuid('client_id');
    $table->string('module'); // inventory, purchases, menu_engineering, production, expenses, financial
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
    $table->unique(['client_id', 'module']);
});
```

**1.2 Model: `ClientModule.php`**

```php
class ClientModule extends Model
{
    protected $fillable = ['client_id', 'module', 'is_active'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
```

**1.3 Client Model — إضافة `primary_color`**

```php
// Migration: add_primary_color_to_clients_table
Schema::table('clients', function (Blueprint $table) {
    $table->string('primary_color', 7)->default('#2563eb')->after('logo');
});
```

**1.4 APIs جديدة**

| Method | Endpoint | الوظيفة |
|--------|----------|---------|
| `GET` | `/api/client/modules` | الموديولات المسموحة للعميل الحالي |
| `GET` | `/api/client/settings` | إعدادات العميل (logo, name, primary_color) |

**الموديولات الأساسية لكل العملاء:**
- `inventory` — المخازن
- `purchases` — المشتريات
- `menu_engineering` — Menu Engineering
- `expenses` — المصروفات

**الموديولات الإضافية:**
- `financial` — المالية (للمشتركين فقط)

**الموديولات الإدارية (مخفية عن العميل):**
- payroll, production, slaughter, processing

#### Frontend

**1.5 تثبيت الحزم الجديدة**

```bash
npm install next-themes framer-motion
```

**1.6 إنشاء Theme Provider**

```
src/
  components/
    theme-provider.tsx      ← next-themes provider
    theme-toggle.tsx        ← زر التبديل 🌙/☀️
```

**1.7 تعديل AppShell.tsx**
- جلب الموديولات من `/api/client/modules`
- إخفاء أقسام الـ sidebar بناءً على الموديولات المسموحة
- عرض لون العميل الأساسي (`primary_color`) في الـ header
- إضافة زر Theme Toggle

**1.8 تعريف الأقسام حسب الموديول:**

```typescript
const MODULE_SECTIONS: Record<string, NavSection> = {
  inventory: { section: 'المخازن', items: ['/stock', '/items', '/warehouses', '/stock-ledger'] },
  purchases: { section: 'المشتريات', items: ['/vouchers/purchase', '/vouchers/dispatch', '/vouchers/upload'] },
  menu_engineering: { section: 'قائمة الطعام', items: ['/menu-engineering'] },
  expenses: { section: 'المصروفات', items: ['/expenses'] },
  financial: { section: 'المالية', items: ['/financial'] },
};
```

---

### المرحلة 2: Client Dashboard ✅ (مكتملة)

**تم التنفيذ:**
- **المسار**: `src/app/(client)/dashboard/page.tsx` — Dashboard كامل.
- **الكروت**: 4 KpiCards (مشتريات، مخزون، فروق، مخازن).
- **الرسوم البيانية**: DiffPieChart + TopDiffItems + TrendChart (recharts).
- **الكروت الإضافية**: إنذارات المخزون (منخفض/نفد)، ملخص قائمة الطعام (الأعلى/الأقل ربحية)، آخر 5 نشاطات.

**API endpoints (8):**

| Method | Endpoint | Controller |
|--------|----------|------------|
| `GET` | `/api/client/dashboard/kpis` | `kpis()` — 4 KPIs + change % |
| `GET` | `/api/client/dashboard/stock-distribution` | `stockDistribution()` — توزيع المخزون |
| `GET` | `/api/client/dashboard/monthly-trend` | `monthlyTrend()` — اتجاه 6 شهور |
| `GET` | `/api/client/dashboard/trends` | `trends()` — alias للمذكور أعلاه |
| `GET` | `/api/client/dashboard/top-diff-items` | `topDiffItems()` — أعلى 10 فروق |
| `GET` | `/api/client/dashboard/alerts` | `alerts()` — إنذارات المخزون (نفد/منخفض/تحذير) |
| `GET` | `/api/client/dashboard/menu-snapshot` | `menuSnapshot()` — أعلى/أقل وصفات ربحية |
| `GET` | `/api/client/dashboard/recent-activity` | `recentActivity()` — آخر 5 حركات مخزنية |

**صفحات إضافية للعميل:**
- `(client)/stock/page.tsx` — رصيد حالي لكل مخزن
- `(client)/stock/movement/page.tsx` — حركة الأصناف
- `(client)/reports/financial-details/page.tsx` — تفاصيل مالية
- `(client)/reports/diffs/page.tsx` — الفروق والهدر
- `(client)/reports/cost/page.tsx` — تحليل التكاليف (placeholder)

---

### المرحلة 3: تقارير مع تصدير ✅ (مكتملة)

**تم التنفيذ:**
- **Backend**: `ClientReportController.php` — 4 endpoints تدعم json/xlsx/pdf:
  - `GET /api/client/reports/purchases?month=&format=json|xlsx|pdf` — تقرير المشتريات
  - `GET /api/client/reports/menu-engineering?format=...` — Menu Engineering
  - `GET /api/client/reports/expenses?month=&format=...` — المصروفات (يومي + ملخص)
  - `GET /api/client/reports/financial?month=&format=...` — التقرير المالي (مخازن + مبيعات/مصروفات)
- **تصدير**: PhpSpreadsheet (XLSX) + mPDF (PDF) لكل تقرير
- **Frontend**: 4 صفحات تقارير جديدة + `ExportButtons` component مشترك + `financial-details` محدث بزرين تصدير

**3.1 صفحات التقارير:**

```
src/app/(app)/client/reports/
  inventory/page.tsx
  purchases/page.tsx
  menu-engineering/page.tsx
  expenses/page.tsx
  financial/page.tsx (للمشتركين فقط)
```

**3.2 كل صفحة تقرير تحتوي:**
- فلترة (شهر/سنة/مخزن/قسم)
- جدول بيانات (باستخدام @tanstack/react-table)
- رسم بياني (باستخدام recharts)
- أزرار تصدير: PDF + Excel

**3.3 Backend للتقرير:**

```
GET /api/client/reports/{module}?month=2026-06&format=json|pdf|xlsx
```

**3.4 التصدير:**

| الصيغة | المكتبة | الحالة |
|--------|---------|--------|
| Excel (.xlsx) | `xlsx` (frontend) / `phpspreadsheet` (backend) | ✅ موجودة |
| PDF | `dompdf` (backend) | ✅ موجودة |

---

### المرحلة 4: تحسينات UI/UX

**4.1 تأثيرات وأنيميشن:**
- Framer Motion لدخول/خروج العناصر
- Skeleton loading cards
- Scroll-triggered animations

**4.2 Charts محسّنة:**
- Line charts مع نقاط تفاعلية (tooltips)
- Bar charts مع ألوان متدرجة
- Pie/Doughnut charts للنسب المئوية
- Sparkline cards للـ KPIs

**4.3 Responsive Design:**
- Mobile-first
- Sidebar يتحول لـ Drawer في الشاشات الصغيرة
- Charts تتكيف مع حجم الشاشة

**4.4 RTL محسّن:**
- كل المكونات متوافقة مع RTL
- Charts تدعم RTL (تعديل محاور recharts)

---

### المرحلة 5: Smart Analytics للعميل (جديد)

**5.1 Backend — إعادة استخدام `SmartAnalyticsController`**

نفس `SmartAnalyticsController` اللي للإدارة بيشتغل عادي للعميل لأنه بياخد `client_id` من `auth()->user()->current_client_id`.

**5.2 6 صفحات تحليلات:**

| الصفحة | الرابط | الوظيفة |
|--------|--------|---------|
| إنذارات المخزون | `/client/analytics/inventory-alerts` | حالة الأصناف (نفد/منخفض/تحذير/متوفر) + chart أيام حتى النفاد |
| أعلى المشتريات | `/client/analytics/top-purchases` | ترتيب المشتريات بأعلى قيمة + نسب مساهمة |
| تغيرات الأسعار | `/client/analytics/price-changes` | فرق التكلفة القديم/الجديد + أثر مالي |
| أثر التكلفة | `/client/analytics/cost-impact` | تغير Food Cost % لكل وصفة + تفاصيل المكونات |
| تحليل ABC/Pareto | `/client/analytics/cost-contribution` | تصنيف ABC بالإسهام التراكمي |
| قيمة المخزون | `/client/analytics/stock-value` | قيمة المخزون الإجمالية بالمخازن |

**5.3 Dashboard — Smart Widgets:**

إضافة 4 كروت ذكية في الـ Dashboard تحت الـ KPI cards (زي الإدارة):
- إنذارات المخزون (عدد الأصناف الناقصة)
- تغيرات الأسعار
- قيمة المخزون
- المشتريات الشهرية

**API endpoints:**
```
GET /api/client/analytics/inventory-alerts  ← يعيد استخدام SmartAnalyticsController@inventoryAlerts
GET /api/client/analytics/top-purchases     ← يعيد استخدام SmartAnalyticsController@topPurchases
GET /api/client/analytics/price-changes     ← يعيد استخدام SmartAnalyticsController@priceChanges
GET /api/client/analytics/cost-impact       ← يعيد استخدام SmartAnalyticsController@costImpact
GET /api/client/analytics/cost-contribution ← يعيد استخدام SmartAnalyticsController@costContribution
GET /api/client/analytics/stock-value       ← يعيد استخدام SmartAnalyticsController@stockValue
GET /api/client/dashboard/smart-summary     ← يعيد استخدام DashboardController@smartSummary
```

**5.4 ClientShell — إضافة روابط التحليلات:**
- قسم جديد "التحليلات" في الـ Sidebar بـ 6 روابط
- HREF_MODULE: `analytics.inventory-alerts`, `analytics.top-purchases` إلخ
- موديول `analytics` يضاف لـ client_modules

---

### المرحلة 6: Menu Engineering لكل Menu (جديد)

**6.1 ينقل تقرير المنيو إنجينيرنج للإدارة للعميل:**

- `GET /api/client/menu-engineering/report/summary?branch_id=&menu_id=` ← يعيد استخدام `MenuReportController@summary`
- النسخة بتدعم `?format=json|xlsx|pdf`

**6.2 صفحة `/client/reports/menu-engineering` محدثة:**

الإضافة:
- Dropdown لاختيار الفرع (مخازن نوع `branch`)
- Dropdown لاختيار المنيو (ديناميكي حسب الفرع)
- تقرير مفصل: ملخص إجمالي + breakdown لكل Category + جدول الوصفات
- ألوان: أحمر لو Food Cost % > 35%، أخضر لو أقل

**6.3 API endpoints:**
```
GET /api/client/menu-engineering/branches    ← المخازن نوع branch
GET /api/client/menu-engineering/menus?branch_id=  ← المنيوهات بتاع الفرع
GET /api/client/menu-engineering/report/summary?branch_id=&menu_id=&format=json|xlsx|pdf
```

---

### المرحلة 7: Dashboard — الداي اند نايت الجميل اللي يشد (جديد)

**7.1 KPI Cards مطورة:**
- خلفية بتدرج لوني (gradient background) حسب الـ primary_color
- Glassmorphism (bg-white/10 backdrop-blur)
- Hover effect (scale 1.02 + shadow)
- Sparkline في البطاقة (موجود حالياً — يتطور)
- أيقونة في دايرة مع glow

**7.2 خلفية الصفحة:**
- Animated gradient background في الوضع النهاري
- Dark mode: subtle grid pattern مع glow spots
- Soft shadows على البطاقات

**7.3 Charts محسّنة:**
- Gradient fills في الـ area/bar charts
- Custom tooltip بتصميم glassmorphism
- Animation على ظهور الشارت
- Empty state تصميم مش فاضي (illustration + رسالة)

**7.4 تناسق الألوان:**
- كل العميل ياخد `primary_color` ويطبق على:
  - الـ header bar
  - أزرار الأكشن
  - Hover effects
  - Active tab في الـ sidebar
  - Gradients في الـ KPI cards

**7.5 Animations شاملة:**
- Page transition (Framer Motion — موجود)
- Card entrance staggered
- Chart entrance (Recharts animation)
- Number counter animation للـ KPIs
- Skeleton shimmer محسّن

**7.6 Responsive:**
- الـ KPI cards 2×2 (بدل 4×1) في التابلت
- الـ charts تصفف vertically في الموبايل
- الـ sidebar drawer (موجود — يتحسن)

---

### المرحلة 8: Agent AI (لاحقاً — غير مجدولة)

- PHP Native NLP Engine
- Local LLM fallback (llama.cpp)
- شات مدمج في Dashboard العميل
- نظام تعلم تدريجي

---

## 📁 هيكل الملفات الجديدة

```
FRONTEND:
  src/
    components/
      theme-provider.tsx
      theme-toggle.tsx
      kpi-card-enhanced.tsx
      report-chart.tsx
      export-buttons.tsx
    app/(app)/
      client/
        dashboard/
          page.tsx
          components/
            KpiCards.tsx
            TrendChart.tsx
            InventoryAlerts.tsx
            MenuSnapshot.tsx
            RecentActivity.tsx
        reports/
          inventory/page.tsx
          purchases/page.tsx
          menu-engineering/page.tsx
          expenses/page.tsx
          financial/page.tsx
          components/
            ReportFilters.tsx
            ReportTable.tsx
            ReportChart.tsx
    lib/
      client-modules.ts

BACKEND:
  app/
    Models/
      ClientModule.php
    Http/Controllers/
      ClientModuleController.php
      ClientDashboardController.php
      ClientReportController.php
    Http/Resources/
      ClientDashboardResource.php
  database/migrations/
    xxxx_create_client_modules_table.php
    xxxx_add_primary_color_to_clients_table.php
  routes/
    api.php ← إضافة routes
```

---

## 📦 الحزم المطلوبة

### تثبيت جديد:
```bash
cd ERP/frontend
npm install next-themes framer-motion
```

### موجودة مسبقاً:
- `recharts` — الرسوم البيانية
- `shadcn/ui` — مكونات UI
- `lucide-react` — الأيقونات
- `@tanstack/react-query` — إدارة API calls
- `@tanstack/react-table` — الجداول
- `xlsx` — تصدير Excel
- `dompdf` + `phpspreadsheet` — تصدير من Laravel

---

## 📊 خرائط الموديولات والـ Sidebar

| Module | أيقونة | أقسام الـ Sidebar |
|--------|--------|-------------------|
| `inventory` | 📦 | المخازن، الأصناف، كشف المخزون |
| `purchases` | 🚚 | فواتير الشراء، فواتير الصرف، رفع الفواتير |
| `menu_engineering` | 📋 | تحليل القائمة، الوصفات، التسوية |
| `expenses` | 💰 | المصروفات اليومية، تقارير المصروفات |
| `financial` (إضافي) | 🏦 | اليومية، الشهري، الإغلاق، السلف |

---

## 🚀 ترتيب التنفيذ

| الأسبوع | المرحلة | المحتوى | الحالة |
|---------|---------|---------|--------|
| **الأسبوع 1** | 1 | Client Module System + Dark/Light Mode | ✅ |
| **الأسبوع 2** | 2 | Client Dashboard مع Charts | ✅ |
| **الأسبوع 3** | 3 | صفحات التقارير + PDF/Excel Export | ✅ |
| **الأسبوع 4** | 4 | تحسينات UI/UX + أنيميشن | ✅ |
| **—** | 5 | Smart Analytics للعميل (6 صفحات) | ⏳ |
| **—** | 6 | Menu Engineering لكل Menu | ⏳ |
| **—** | 7 | Dashboard الداي اند نايت الجميل | ⏳ |
| **لاحقاً** | 8 | Agent AI | ❌

---

## ملاحظات

1. الـ Dashboard الحالي (`/dashboard` + `/analytics`) يبقى للإدارة فقط
2. الـ Client Portal الجديد في مسار `/client/dashboard` (مجلد `app/client/` وليس route group)
3. الموديولات الأساسية للكل: مخازن + مشتريات + Menu Engineering + مصروفات
4. الموديول الإضافي: مالية + analytics
5. كل عميل له لون أساسي خاص (`primary_color`) يظهر في الواجهة
6. **الصفحات الناقصة حالياً**: Smart Analytics (6 صفحات)، Menu Engineering لكل Menu، تحسين شكل الـ Dashboard
