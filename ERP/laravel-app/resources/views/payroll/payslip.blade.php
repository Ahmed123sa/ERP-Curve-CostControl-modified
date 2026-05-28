<!DOCTYPE html>
<html dir="rtl">
<head>
<meta charset="utf-8">
<title>كشف مرتب</title>
<style>
body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; }
.header { text-align: center; margin-bottom: 20px; }
.header h1 { font-size: 18px; margin: 0 0 5px; }
.header h2 { font-size: 14px; margin: 0; color: #555; }
table { width: 100%; border-collapse: collapse; margin-top: 15px; }
th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: center; }
th { background: #e8e8e8; font-weight: bold; }
.footer { margin-top: 30px; text-align: left; }
.signature { margin-top: 50px; }
.signature span { border-top: 1px solid #000; padding-top: 3px; }
.net-salary { font-size: 16px; font-weight: bold; color: #1a56db; }
</style>
</head>
<body>
<div class="header">
    <h1>كشف مرتب</h1>
    <h2>{{ $detail->employee->name }}</h2>
    <p>{{ $detail->employee->job_title ?? '' }} — {{ $payroll->month }}/{{ $payroll->year }}</p>
</div>

<table>
    <tr>
        <th>المرتب الأساسي</th>
        <td>{{ number_format($detail->base_salary_snapshot, 2) }}</td>
        <th>أجر اليوم</th>
        <td>{{ number_format($detail->daily_wage_snapshot, 2) }}</td>
    </tr>
    <tr>
        <th>أيام العمل</th>
        <td>{{ $detail->work_days }}</td>
        <th>أيام الغياب</th>
        <td>{{ $detail->absence_days }}</td>
    </tr>
    <tr>
        <th>خصم غياب</th>
        <td>{{ number_format($detail->absence_amount, 2) }}</td>
        <th>إضافي راحات</th>
        <td>{{ number_format($detail->rest_day_ot_amount, 2) }}</td>
    </tr>
    <tr>
        <th>أوفر تايم (ساعات)</th>
        <td>{{ number_format($detail->overtime_hours, 2) }}</td>
        <th>قيمة أوفر تايم</th>
        <td>{{ number_format($detail->overtime_amount, 2) }}</td>
    </tr>
    <tr>
        <th>المكافآت</th>
        <td>{{ number_format($detail->bonus_total, 2) }}</td>
        <th>السلفة</th>
        <td>{{ number_format($detail->advance_amount, 2) }}</td>
    </tr>
    <tr>
        <th>إجمالي الخصم</th>
        <td>{{ number_format($detail->total_deductions, 2) }}</td>
        <th class="net-salary">صافي المرتب</th>
        <td class="net-salary">{{ number_format($detail->net_salary, 2) }}</td>
    </tr>
</table>

@if($detail->bonusItems->count() > 0)
<h3 style="margin-top:20px;">تفاصيل المكافآت</h3>
<table>
    <tr><th>البيان</th><th>القيمة</th></tr>
    @foreach($detail->bonusItems as $item)
    <tr><td>{{ $item->name }}</td><td>{{ number_format($item->amount, 2) }}</td></tr>
    @endforeach
</table>
@endif

<div class="footer">
    <div class="signature">
        <span>التوقيع</span>
    </div>
</div>
</body>
</html>
