# خطة Agent AI للتحليلات الذكية — ERP CostControl

> تم إعدادها في: 8 يونيو 2026  
> التقنية الأساسية: Laravel AI SDK (v0.6.3) + Gemini API (مجاني)  
> الحالة: خطة — لم يبدأ التنفيذ بعد  

---

## 1. البنية التحتية

### 1.1 اختيار AI Provider → Gemini API (مجاني)

| الميزة | القيمة |
|--------|--------|
| المزود | Google Gemini (Gemini 2.0 Flash) |
| التكلفة | **مجاني** — 60 request/day، 1,500 request/month |
| دعم العربي | ممتاز |
| التكامل | Laravel AI SDK يدعمه natively |
| أمان البيانات | Google لا تستخدم API data للتدريب |

**بديل مستقبلي:** Ollama local (لما يتوفر جهاز بـ 8–16 GB RAM)

### 1.2 الحزم المطلوبة

```bash
composer require laravel/ai
```

### 1.3 الإعدادات (`.env`)

```env
AI_PROVIDER=gemini
GEMINI_API_KEY=AIzaSy...   # من Google AI Studio
```

### 1.4 متطلبات Laravel

- Laravel 11+
- PHP 8.3+
- Queue worker (للتحليلات الثقيلة والمجدولة)

---

## 2. هيكل الملفات

```
ERP/laravel-app/
├── app/Agents/
│   ├── OrchestratorAgent.php
│   ├── FinancialAnalystAgent.php
│   ├── InventoryIntelligenceAgent.php
│   ├── PayrollAnalystAgent.php
│   └── ExecutiveQaAgent.php
├── app/Agents/Tools/Financial/
│   ├── GetClosingReport.php
│   ├── GetDailyEntries.php
│   ├── GetExpenseBreakdown.php
│   ├── ComparePeriods.php
│   └── DetectAnomalies.php
├── app/Agents/Tools/Inventory/
│   ├── GetStockLedger.php
│   ├── GetMonthlyClosing.php
│   ├── GetItems.php
│   ├── ForecastDemand.php
│   └── GetInventoryAlerts.php
├── app/Agents/Tools/Payroll/
│   ├── GetPayrollSummary.php
│   ├── GetEmployeeCosts.php
│   ├── GetAttendancePatterns.php
│   └── CalculateLaborCostPct.php
├── app/Agents/Tools/Common/
│   ├── GetClientContext.php
│   └── GetDateRange.php
├── app/Http/Controllers/Agents/
│   └── AgentController.php
├── app/Models/
│   └── AgentInsight.php
├── app/Console/Commands/
│   └── RunScheduledAgentReports.php
├── database/migrations/
│   └── xxxx_xx_xx_create_agent_insights_table.php
└── routes/api.php                    ← إضافة routes الـ Agents

ERP/frontend/src/
├── app/(app)/analytics/agent/
│   ├── page.tsx
│   └── components/
│       ├── AgentChatBox.tsx
│       ├── StructuredResult.tsx
│       ├── InsightCard.tsx
│       └── ScheduledReportPanel.tsx
└── lib/agents/
    └── api.ts
```

---

## 3. الـ 5 Agents

### 3.1 OrchestratorAgent — الموزع الذكي

**الدور:** يستقبل أي سؤال من المستخدم ويقرر أي Agent متخصص يوجهه له.

- يستخدم `#[UseCheapestModel]` (Gemini 1.5 Flash) لأن التصنيف مهمة بسيطة
- يحلل السؤال ويحدد: Financial / Inventory / Payroll / Executive / عام
- لو السؤال تحية أو مساعدة — يرد مباشرة بدون توجيه
- يمرر الـ Context (client_id, الفترة) للـ Agent المستهدف

**مثال:**
```
المستخدم: "أيه أعلى 5 مصروفات الشهر اللي فات؟"
↓
Orchestrator يتعرف على: Financial + فترة = last month
↓
يوجه لـ FinancialAnalystAgent مع params ['month' => 5, 'year' => 2026]
```

---

### 3.2 FinancialAnalystAgent — المحلل المالي

**Tools:**

| Tool | مصدر البيانات | الوظيفة |
|------|---------------|---------|
| `GetClosingReport` | `FinancialClosingReport` | يجيب قائمة الدخل لشهر معين مع كل البنود |
| `GetDailyEntries` | `FinancialDailyEntry` + Details | يجيب القيود اليومية وتفاصيلها |
| `GetExpenseBreakdown` | `FinancialDailyEntryDetail` | يحلل المصروفات حسب expense_category |
| `ComparePeriods` | `FinancialMonthlySummary` | مقارنة شهرين / سنة كاملة |
| `DetectAnomalies` | Closing + Daily + Stats | اكتشاف القيم غير الطبيعية إحصائياً |

**Structured Output:**

```php
[
  'title'          => 'تحليل الأداء المالي - مايو 2026',
  'summary'        => 'ارتفعت الإيرادات 15% مقارنة بشهر إبريل...',
  'key_metrics'    => [
    'total_revenue'    => 1250000.00,
    'total_expenses'   => 875000.00,
    'net_profit'       => 375000.00,
    'profit_margin'    => 30.0,
    'expense_ratio'    => 70.0,
  ],
  'anomalies'      => [
    [
      'item'         => 'مصروفات الصيانة',
      'expected'     => 15000.00,
      'actual'       => 45000.00,
      'deviation_pct'=> 200.0,
      'severity'     => 'high',
      'possible_cause' => 'قد يكون بسبب عطل طارئ أو صيانة دورية',
    ],
  ],
  'trends'         => [
    [
      'period'     => 'آخر 3 شهور',
      'metric'     => 'صافي الربح',
      'direction'  => 'up',
      'insight'    => 'تحسن مستمر في الربحية',
    ],
  ],
  'comparisons'    => [
    [
      'period'      => 'مايو vs أبريل',
      'revenue'     => ['current' => 1250000, 'previous' => 1080000, 'change_pct' => 15.7],
      'expenses'    => ['current' => 875000,  'previous' => 800000,  'change_pct' => 9.4],
      'net_profit'  => ['current' => 375000,  'previous' => 280000,  'change_pct' => 33.9],
    ],
  ],
  'recommendations' => [
    'مراجعة مصروفات الصيانة — ارتفعت 200% عن المتوسط',
    'الإيرادات في تحسن مستمر — استمرار نفس الاستراتيجية',
  ],
  'confidence'     => 92,
  'period'         => 'May 2026',
]
```

**المهام التي يقوم بها:**
1. تحليل قائمة الدخل (P&L) لأي شهر
2. كشف الشذوذ (ارتفاع/انخفاض مفاجئ في مصروف معين)
3. مقارنة شهرين أو فترتين
4. تحليل هيكل المصروفات (التوزيع حسب التصنيف)
5. تحليل القيود اليومية (أيام الأسبوع، الإجازات)
6. توصيات لتحسين الأداء المالي

---

### 3.3 InventoryIntelligenceAgent — ذكاء المخزون

**Tools:**

| Tool | مصدر البيانات | الوظيفة |
|------|---------------|---------|
| `GetStockLedger` | `StockLedger` | حركة المخزون لفترة محددة (30/90 يوم) |
| `GetMonthlyClosing` | `MonthlyClosing` | الإقفال الشهري + الفروق (theoretical vs actual) |
| `GetItems` | `Item` + `expenseCategory` | الأصناف والفئات مع الحدود الدنيا |
| `ForecastDemand` | StockLedger (sliding window) | توقع استهلاك الـ 30 يوم القادمة |
| `GetInventoryAlerts` | `SmartAnalyticsService` (موجود) | التنبيهات الحالية (out-of-stock, critical, warning) |

**المهام:**
1. تحليل معدل دوران المخزون (Turnover Rate) لكل صنف وفئة
2. كشف الأصناف الراكدة (no movement في آخر 90 يوم)
3. توقع موعد نفاد المخزون (Days Until Stockout) — باستخدام AI trend
4. كشف الشذوذ في الفروق (Diff بين النظري والفعلي)
5. تحليل سرعة الاستهلاك حسب الموسم/الشهر
6. توصيات إعادة تموين ذكية

**مثال Output:**
```json
{
  "title": "تحليل المخزون - مايو 2026",
  "summary": "تم اكتشاف 12 صنف في حالة حرجة، 3 أصناف راكدة...",
  "critical_items": [
    {"name": "دجاج", "current_stock": 50, "daily_consumption": 15, "days_until_out": 3.3, "severity": "critical"}
  ],
  "slow_moving": [
    {"name": "بهارات نادرة", "last_movement": "2026-02-15", "days_since_movement": 105, "current_qty": 200}
  ],
  "forecast": [
    {"item": "دجاج", "next_month_forecast": 450, "confidence": 85}
  ],
  "anomalies": [
    {"item": "زيت طعام", "expected_consumption": 100, "actual": 250, "deviation_pct": 150, "severity": "high"}
  ],
  "recommendations": [
    "طلب دجاج عاجل — سينفد خلال 3 أيام",
    "تخفيض كمية البهارات النادرة — لم تتحرك منذ 3 شهور",
    "مراجعة استهلاك الزيت — تضاعف الشهر الماضي"
  ]
}
```

---

### 3.4 PayrollAnalystAgent — محلل الرواتب

**Tools:**

| Tool | مصدر البيانات | الوظيفة |
|------|---------------|---------|
| `GetPayrollSummary` | `PayrollMonthly` | ملخص الرواتب لشهر/فترة |
| `GetEmployeeCosts` | `PayrollMonthlyDetail` | تكلفة كل موظف (basic + overtime + bonuses + deductions) |
| `GetAttendancePatterns` | `AttendanceRecord` | أنماط الحضور، الغياب، overtime |
| `CalculateLaborCostPct` | Payroll + DailyEntry | تكلفة العمالة % من المبيعات |

**المهام:**
1. تحليل تكلفة العمالة الإجمالية و % من المبيعات
2. مقارنة تكلفة العمالة شهر/شهر
3. كشف الموظفين ذوي overtime غير طبيعي
4. تحليل أنماط الغياب (أيام محددة، موظفون محددون)
5. توزيع تكلفة الرواتب حسب القسم/الوظيفة
6. توقع تكلفة العمالة للشهر القادم (بناءً على الاتجاه)

**مثال Output:**
```json
{
  "title": "تحليل تكلفة العمالة - مايو 2026",
  "summary": "تكلفة العمالة 28% من المبيعات — ضمن المعدل الطبيعي...",
  "key_metrics": {
    "total_labor_cost": 350000.00,
    "total_sales": 1250000.00,
    "labor_cost_pct": 28.0,
    "total_employees": 45,
    "avg_salary": 7777.78,
    "total_overtime": 35000.00,
    "overtime_pct": 10.0
  },
  "overtime_anomalies": [
    {"employee": "أحمد علي", "overtime_hours": 45, "avg_hours": 10, "severity": "high"}
  ],
  "comparison": {
    "previous_month": {"labor_cost": 320000, "labor_pct": 29.5},
    "current_month": {"labor_cost": 350000, "labor_pct": 28.0},
    "change_pct": 9.4
  },
  "recommendations": [
    "مراجعة أوفر تايم أحمد علي — 45 ساعة (المعدل 10)",
    "تكلفة العمالة تحسنت من 29.5% لـ 28% — استمرار"
  ]
}
```

---

### 3.5 ExecutiveQaAgent — الأسئلة المباشرة

**Tools:** كل Tools الـ 3 Agents السابقين (مجمعة في Tool واحدة `RouteToSpecialist`).

**الفرق:** ده Agent "خفيف" بيستقبل أي سؤال بالعامية أو الفصحى ويرد مباشرة.

**أمثلة:**
```
"إيه أعلى 3 مصروفات النهارده؟"
"قارن أرباح مايو مع إبريل"
"أيه الأصناف اللي استهلاكها قل فجأة؟"
"عرض تقرير كامل للشهر"
"عاوز مرتبات الموظفين كلها"
"أيه نسبة تكلفة العمالة من المبيعات؟"
"فيني ثقة في تحليل المخزون؟"
```

**طريقة العمل:**
1. يستقبل السؤال
2. Instruction يقول له: "أنت مساعد خبير في تحليل بيانات ERP... حدد أي بيانات محتاج"
3. يستخدم Tools المناسبة (أو يستدعي الـ Orchestrator)
4. يرد بالعربية — ممكن structured أو نص عادي حسب السؤال

---

## 4. API Routes

```php
// routes/api.php
Route::middleware('auth:sanctum')->prefix('agent')->group(function () {

    // Chat — سؤال وجواب مباشر (Streaming)
    Route::post('/chat', [AgentController::class, 'chat']);

    // Analyze — تحليل فترة محددة (غير متزامن — Queue)
    Route::post('/analyze', [AgentController::class, 'analyze']);

    // تحليل مالي
    Route::post('/analyze/financial', [AgentController::class, 'analyzeFinancial']);
    // تحليل مخزون
    Route::post('/analyze/inventory', [AgentController::class, 'analyzeInventory']);
    // تحليل رواتب
    Route::post('/analyze/payroll', [AgentController::class, 'analyzePayroll']);

    // التوصيات المخزنة
    Route::get('/insights', [AgentController::class, 'insights']);
    Route::patch('/insights/{id}/read', [AgentController::class, 'markRead']);

    // التقارير المجدولة
    Route::get('/schedules', [AgentController::class, 'schedules']);
    Route::post('/schedules', [AgentController::class, 'setSchedule']);
    Route::delete('/schedules/{id}', [AgentController::class, 'deleteSchedule']);
});
```

---

## 5. واجهة المستخدم (Frontend)

### 5.1 صفحة Chat

**الموقع:** `analytics/agent/page.tsx`

**المكونات:**
- `AgentChatBox.tsx` — شات كامل (مشابه ChatGPT):
  - Input text + زر إرسال
  - تاريخ المحادثة (Conversation History)
  - Loading state (الـ Agent بيفكر)
  - Streaming response (حرف بحرف)
- `StructuredResult.tsx` — عرض Structured Output:
  - Key metrics cards (إيرادات، مصروفات، صافي)
  - Anomalies list (مع severity badge)
  - Recommendations cards
  - Charts (إن أمكن)
- `InsightCard.tsx` — عرض التوصيات المخزنة
- `ScheduledReportPanel.tsx` — إعداد التقارير المجدولة

### 5.2 Dashboard Widgets

**الموقع:** `dashboard/page.tsx` — إضافة كروت جديدة

- **Agent Insights Widget:** يعرض آخر التوصيات + عدد الـ Anomalies
- **Quick Analyze Buttons:** أزرار سريعة (حلل الشهر, كشف الشذوذ, قارن)

### 5.3 تكامل مع التحليلات الحالية

- إضافة تبويب جديد في `menu-engineering/analytics/page.tsx`
- التبويب اسمه: 🤖 Agent Ai
- المحتوى: تضمين صفحة الـ Agent Chat

---

## 6. AgentInsight Model — التوصيات المخزنة

```php
class AgentInsight extends Model
{
    protected $fillable = [
        'client_id',
        'agent_type',      // financial / inventory / payroll
        'insight_type',    // anomaly / trend / recommendation / alert
        'title',
        'summary',
        'data_json',       // التوصية كاملة (JSON)
        'severity',        // info / warning / critical
        'period',          // 'May 2026'
        'is_read',
    ];

    protected $casts = [
        'data_json' => 'array',
        'is_read' => 'boolean',
    ];
}
```

**مigrations:**

```php
Schema::create('agent_insights', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('client_id')->constrained();
    $table->string('agent_type');           // financial / inventory / payroll
    $table->string('insight_type');         // anomaly / trend / recommendation / alert
    $table->string('title');
    $table->text('summary')->nullable();
    $table->json('data_json')->nullable();
    $table->string('severity')->default('info');  // info / warning / critical
    $table->string('period')->nullable();
    $table->boolean('is_read')->default(false);
    $table->timestamps();
});
```

---

## 7. Scheduled Reports — التقارير المجدولة

### الـ Command

```php
// app/Console/Commands/RunScheduledAgentReports.php
php artisan agent:run-scheduled
```

**إيه اللي بيحصل:**
1. يجيب الـ Client IDs النشطة
2. لكل Client:
   - Financial Agent يحلل P&L الشهر الماضي
   - Inventory Agent يحلل المخزون
   - Payroll Agent يحلل الرواتب
3. كل تحليل يتخزن كـ `AgentInsight`
4. الـ Dashboard يشوف أحدث الـ Insights

### الـ Schedule

```php
// في bootstrap/app.php أو Kernel (حسب إصدار Laravel)
$schedule->command('agent:run-scheduled')
    ->lastDayOfMonth()
    ->at('23:00')
    ->onQueue('analytics');
```

### واجهة إعداد الـ Schedule

- المستخدم يختار: أي Agent يجري تقرير (كلهم / واحد)
- التوقيت: شهري / أسبوعي / يومي
- مستوى التفصيل: مختصر / مفصل

---

## 8. خطة التنفيذ — 6 أيام

### اليوم 1: الأساسيات

| المهمة | الوصف |
|--------|-------|
| تسجيل Gemini API Key | من Google AI Studio |
| تثبيت Laravel AI SDK | `composer require laravel/ai` |
| نشر config | `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"` |
| إعداد `.env` | `AI_PROVIDER=gemini`, `GEMINI_API_KEY=...` |
| إنشاء `app/Agents/` | هيكل الفولدرات |
| أول Tool: `GetClosingReport` | يجيب P&L من FinancialClosingReport |
| أول Agent: `FinancialAnalystAgent` | مع `HasStructuredOutput` |
| أول Test | `FinancialAnalystAgent::prompt("حلل شهر 5")` يعمل |

### اليوم 2: الـ Tools المالية + Inventory Agent

| المهمة | الوصف |
|--------|-------|
| `GetDailyEntries` Tool | يجيب القيود اليومية |
| `GetExpenseBreakdown` Tool | تحليل المصروفات حسب التصنيف |
| `ComparePeriods` Tool | مقارنة فترتين |
| `DetectAnomalies` Tool | انحراف معياري، Z-score |
| `InventoryIntelligenceAgent` | مع Structured Output |
| Tools المخزون | `GetStockLedger`, `GetMonthlyClosing`, `GetItems`, `ForecastDemand`, `GetInventoryAlerts` |
| Test | Inventory Agent يحلل مخزون شهر |

### اليوم 3: Payroll + Executive + Orchestrator

| المهمة | الوصف |
|-------|--------|
| `PayrollAnalystAgent` | مع Tools + Structured Output |
| Tools الرواتب | `GetPayrollSummary`, `GetEmployeeCosts`, `GetAttendancePatterns`, `CalculateLaborCostPct` |
| `ExecutiveQaAgent` | يجمع كل Tools + إرشادات بالعامية |
| `OrchestratorAgent` | يحلل السؤال ويوجهه |
| Test | كل Agents تشتغل منفردة ومجمعة |

### اليوم 4: Backend API + Model

| المهمة | الوصف |
|-------|--------|
| `AgentController` | chat, analyze, insights, schedules |
| `AgentInsight` Model + Migration | تخزين التوصيات |
| Routes | إضافة routes الـ Agents في api.php |
| Queue Integration | التحليلات الثقيلة على Queue |
| Validation + Error Handling | Fallback لو الـ API مش متاح |

### اليوم 5: Frontend

| المهمة | الوصف |
|-------|--------|
| `lib/agents/api.ts` | API calls |
| `AgentChatBox.tsx` | شات عربي كامل |
| `StructuredResult.tsx` | عرض structured output |
| `InsightCard.tsx` | كروت التوصيات |
| `ScheduledReportPanel.tsx` | إعداد الجدولة |
| Integration مع التحليلات الحالية | تبويب جديد في صفحة التحليلات |
| Dashboard Widgets | كروت التوصيات في الداشبورد |

### اليوم 6: الإنتاج والاختبار

| المهمة | الوصف |
|-------|--------|
| `RunScheduledAgentReports` Command | أمر artisan للتشغيل المجدول |
| Kernel Schedule | `$schedule->command('agent:run-scheduled')->monthly()` |
| اختبار جميع السيناريوهات | Financial + Inventory + Payroll + Q&A |
| تحسين الـ Prompts | ضبط التعليمات لكل Agent |
| Performance | Cache, Queue, Timeouts |
| Error handling | API key expired, Rate limit, Timeout |

---

## 9. ملاحظات مهمة

### الأداء
- الـ Chat (Streaming) سريع — المستمع يشوف الحرف أول بأول
- التحليلات الثقيلة تشتغل على Queue — ما توقفش الـ HTTP request
- الـ Scheduled reports تشتغل آخر الشهر — بدون تدخل المستخدم

### الأمان
- كل Agent يستخدم `$request->user()->current_client_id` — Multi-tenant
- الـ API key في `.env` — مش في الكود
- الـ Insights خاصة بكل Client
- Rate limiting على endpoints

### اللغة
- كل Prompts الـ Agents بالعربية
- Structured Output بالعربية
- الـ Assistant Name: "المحلل الذكي"
- الاتجاه: RTL

### التوسع المستقبلي
- تحويل لـ Ollama: تغيير `AI_PROVIDER=ollama` فقط + تشغيل Ollama
- إضافة Agents جديدة (e.g., Customer Agent, Supplier Agent)
- إضافة Voice Input
- إضافة Predictive ML models (إذا توفرت بيانات كافية)

---

## 10. ملحق: نموذج كود أول Agent

### FinancialAnalystAgent.php

```php
<?php

namespace App\Agents;

use Laravel\Ai\Agent\Agent;
use Laravel\Ai\Agent\Concerns\Promptable;
use Laravel\Ai\Agent\Contracts\Agent as AgentContract;
use Laravel\Ai\Agent\Contracts\HasTools;
use Laravel\Ai\Agent\Contracts\HasStructuredOutput;
use Laravel\Ai\Agent\Contracts\Conversational;
use Laravel\Ai\Agent\Data\JsonSchema;
use Laravel\Ai\Agent\Configuration\UsesModel;

#[UsesModel('gemini-2.0-flash')]
class FinancialAnalystAgent implements AgentContract, HasTools, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public string $clientId,
        public ?int $month = null,
        public ?int $year = null,
    ) {}

    public function instructions(): string
    {
        return 'أنت محلل مالي خبير في تحليل قوائم الدخل والمصروفات والميزانيات.
                تحلل البيانات المالية وتقدم رؤى وتوصيات قابلة للتنفيذ باللغة العربية.
                تستخدم الأدوات المتاحة لجلب وتحليل البيانات.
                ركز على اكتشاف الشذوذ والاتجاهات والمقارنات.
                كن دقيقاً في الأرقام وقدم نسبة الثقة في تحليلك.';
    }

    public function tools(): array
    {
        return [
            new \App\Agents\Tools\Financial\GetClosingReport($this->clientId),
            new \App\Agents\Tools\Financial\GetDailyEntries($this->clientId),
            new \App\Agents\Tools\Financial\GetExpenseBreakdown($this->clientId),
            new \App\Agents\Tools\Financial\ComparePeriods($this->clientId),
            new \App\Agents\Tools\Financial\DetectAnomalies($this->clientId),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string('عنوان التحليل'),
            'summary' => $schema->string('ملخص التحليل المالي بالعربية'),
            'key_metrics' => $schema->object('المؤشرات الرئيسية', [
                'total_revenue' => $schema->number('إجمالي الإيرادات'),
                'total_expenses' => $schema->number('إجمالي المصروفات'),
                'net_profit' => $schema->number('صافي الربح'),
                'profit_margin' => $schema->number('هامش الربح %'),
                'expense_ratio' => $schema->number('نسبة المصروفات %'),
            ]),
            'anomalies' => $schema->array('الشذوذات المالية',
                $schema->object([
                    'item' => $schema->string('البند'),
                    'expected' => $schema->number('القيمة المتوقعة'),
                    'actual' => $schema->number('القيمة الفعلية'),
                    'deviation_pct' => $schema->number('نسبة الانحراف %'),
                    'severity' => $schema->enum(['low', 'medium', 'high'], 'الخطورة'),
                    'possible_cause' => $schema->string('السبب المحتمل'),
                ]),
            ),
            'trends' => $schema->array('الاتجاهات',
                $schema->object([
                    'period' => $schema->string('الفترة'),
                    'metric' => $schema->string('المؤشر'),
                    'direction' => $schema->enum(['up', 'down', 'stable'], 'الاتجاه'),
                    'insight' => $schema->string('التحليل'),
                ]),
            ),
            'comparisons' => $schema->array('المقارنات',
                $schema->object([
                    'period' => $schema->string('الفترة المقارنة'),
                    'revenue' => $schema->object('', [
                        'current' => $schema->number(),
                        'previous' => $schema->number(),
                        'change_pct' => $schema->number(),
                    ]),
                    'expenses' => $schema->object('', [
                        'current' => $schema->number(),
                        'previous' => $schema->number(),
                        'change_pct' => $schema->number(),
                    ]),
                    'net_profit' => $schema->object('', [
                        'current' => $schema->number(),
                        'previous' => $schema->number(),
                        'change_pct' => $schema->number(),
                    ]),
                ]),
            ),
            'recommendations' => $schema->array('التوصيات',
                $schema->string()
            ),
            'confidence' => $schema->number('نسبة الثقة في التحليل 0-100'),
            'period' => $schema->string('الفترة التي تم تحليلها'),
        ];
    }
}
```

### GetClosingReport.php (نموذج Tool)

```php
<?php

namespace App\Agents\Tools\Financial;

use App\Models\Financial\FinancialClosingReport;
use Laravel\Ai\Agent\Tool;

class GetClosingReport extends Tool
{
    public function __construct(private string $clientId) {}

    public function name(): string
    {
        return 'get_closing_report';
    }

    public function description(): string
    {
        return 'يجيب تقرير الإقفال الشهري (قائمة الدخل P&L) لشهر وسنة محددين';
    }

    public function parameters(): array
    {
        return [
            'month' => ['type' => 'integer', 'description' => 'الشهر (1-12)'],
            'year' => ['type' => 'integer', 'description' => 'السنة'],
        ];
    }

    public function handle(int $month, int $year): mixed
    {
        $report = FinancialClosingReport::where('client_id', $this->clientId)
            ->where('month', $month)
            ->where('year', $year)
            ->with('details.items')
            ->first();

        if (!$report) {
            return ['error' => "لا يوجد تقرير إقفال لـ {$month}/{$year}"];
        }

        return $report->toArray();
    }
}
```

---

> **انتهت الخطة**  
> تاريخ البدء: لم يبدأ بعد  
>在当前 حالة: انتظار موافقة المستخدم على البدء
