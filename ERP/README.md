# Cost Pro — نظام كوست كنترول الداخلي

نظام ERP متكامل لإدارة المخزون وحساب التكاليف لقطاع المطاعم والكافيهات.

---

## هيكل المشروع

```
ERP/
├── backend/                   ← Laravel 11 API
│   ├── app/
│   │   ├── Models/
│   │   │   └── Models.php     ← كل الـ models (Client, Item, Warehouse, ...)
│   │   ├── Services/
│   │   │   ├── MappingService.php         ← ربط أسماء الأصناف تلقائياً
│   │   │   ├── VoucherParserService.php   ← قراءة ملفات Excel
│   │   │   ├── StockLedgerService.php     ← تسجيل الحركات في الـ ledger
│   │   │   └── CostCalculationService.php ← حساب الـ weighted avg والتقفيل
│   │   └── Http/Controllers/
│   │       ├── AuthController.php
│   │       ├── VoucherController.php      ← رفع Excel + إدخال يدوي
│   │       └── ClosingController.php      ← التقفيل الشهري + Export
│   ├── database/
│   │   ├── migrations/
│   │   │   └── 2024_01_01_000001_create_erp_schema.php  ← الـ schema كامل
│   │   └── seeders/
│   │       └── DatabaseSeeder.php         ← بيانات تجريبية
│   └── routes/
│       └── api.php                        ← كل الـ API routes
│
├── frontend/                  ← Next.js 14 + React
│   └── src/
│       ├── lib/
│       │   ├── api.ts         ← Axios instance
│       │   └── store.ts       ← Zustand (auth + current client)
│       ├── components/
│       │   ├── ui/
│       │   │   └── AppShell.tsx      ← Layout + Sidebar
│       │   ├── upload/
│       │   │   └── VoucherUpload.tsx ← رفع Excel + معاينة + تأكيد
│       │   ├── grid/
│       │   │   └── VoucherGrid.tsx   ← إدخال يدوي زي Excel
│       │   └── closing/
│       │       └── MonthlyClosingTable.tsx ← تقفيل الشهر
│       └── app/(app)/
│           └── dashboard/page.tsx
│
└── setup.ps1                  ← تشغيله مرة واحدة بعد الاستخراج
```

---

## المتطلبات

| الأداة | الإصدار |
|--------|---------|
| PHP | 8.2+ |
| Composer | 2.x |
| Node.js | 18+ |
| PostgreSQL | 15+ |

---

## خطوات التشغيل

### الخطوة 1 — إنشاء قاعدة البيانات
```sql
CREATE DATABASE erp_cost_control;
```

### الخطوة 2 — Backend
```bash
cd backend
composer install
cp .env.example .env
# عدّل DB_PASSWORD في .env

php artisan key:generate
php artisan migrate
php artisan db:seed

php artisan serve
# يشتغل على http://localhost:8000
```

### الخطوة 3 — Frontend
```bash
cd frontend
npm install
npm run dev
# يشتغل على http://localhost:3000
```

### بيانات الدخول (تجريبية)
| المستخدم | الإيميل | كلمة المرور |
|---------|---------|------------|
| Admin | admin@erp.local | admin123 |
| أحمد محمود | ahmed@erp.local | 123456 |

---

## الـ API Endpoints الأساسية

### Auth
```
POST /api/auth/login          ← تسجيل دخول
POST /api/auth/logout
POST /api/auth/switch-client/{id}  ← تغيير العميل
```

### Vouchers (الأذون)
```
GET  /api/vouchers            ← قائمة الأذون
POST /api/vouchers/upload     ← رفع Excel (preview فقط)
POST /api/vouchers/confirm    ← تأكيد بعد المراجعة
POST /api/vouchers/manual     ← إدخال يدوي من الـ Grid
```

### Stock
```
GET /api/stock/current        ← الرصيد الحالي
GET /api/stock/movement       ← حركة صنف
GET /api/stock/warehouse-summary ← ملخص مخزن
```

### Closing
```
GET  /api/closing             ← بيانات التقفيل
POST /api/closing/generate    ← توليد التقفيل تلقائياً
PATCH /api/closing/{id}/actual ← تسجيل جرد فعلي
POST /api/closing/lock        ← إقفال الشهر
GET  /api/closing/export      ← تصدير Excel
```

---

## منطق الحساب — مثل الشيت بالظبط

### Weighted Average Cost
```
متوسط السعر = (قيمة أول المدة + قيمة الوارد)
              ÷
              (كمية أول المدة + كمية الوارد)
```

### الإدخال اليومي
```
الموظف يكتب: الصنف | الكمية | cost (إجمالي قيمة الفاتورة)
النظام يحسب: سعر الوحدة = cost ÷ كمية
```

### التقفيل الشهري
```
نظري = أول المدة + الوارد - المنصرف
الفرق = نظري - فعلي (جرد)
قيمة الفرق = الفرق × متوسط السعر
```

---

## الـ Multi-Tenant

كل عميل معزول بـ `client_id` على كل جدول.
PostgreSQL Row-Level Security مفعّل على الجداول الحساسة.
الموظف ممكن يشتغل على أكثر من عميل — بيختار من الـ sidebar.

---

## خطوات التطوير القادمة

- [ ] MappingReviewModal — نافذة ربط الأصناف اليدوي
- [ ] DashboardController — حساب الـ KPIs
- [ ] StockController — صفحة الرصيد الحالي
- [ ] صفحة الإنتاج اليومي
- [ ] صفحة المبيعات الخارجية
- [ ] نظام التنبيهات (food cost تعدى الحد)
- [ ] تقرير الـ Food Cost % مع رسوم بيانية
