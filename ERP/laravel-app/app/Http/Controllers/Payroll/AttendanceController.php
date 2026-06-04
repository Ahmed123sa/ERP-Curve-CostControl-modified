<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Financial\EmployeeAdvance;
use App\Models\Payroll\PayrollEmployee;
use App\Services\Payroll\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);
        $employeeId = $request->query('employee_id');

        $records = $this->service->list($clientId, $month, $year, $employeeId);
        return response()->json(['records' => $records]);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'employee_id' => 'required|uuid',
            'date' => 'required|date',
            'shift_start' => 'required|date_format:H:i',
            'shift_end' => 'required|date_format:H:i',
            'notes' => 'nullable|string|max:500',
        ]);

        $record = $this->service->store($clientId, $data);
        return response()->json(['record' => $record, 'message' => 'تم تسجيل الحضور']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $this->service->destroy($clientId, $id);
        return response()->json(['message' => 'تم حذف تسجيل الحضور']);
    }

    public function employeeAdvances(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $employeeId = $request->query('employee_id');
        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);

        $payrollEmp = PayrollEmployee::where('client_id', $clientId)->findOrFail($employeeId);

        $total = 0;
        $daily = [];
        if ($payrollEmp->financial_employee_id) {
            $advances = EmployeeAdvance::where('client_id', $clientId)
                ->where('employee_id', $payrollEmp->financial_employee_id)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get();
            $total = (float) $advances->sum('amount');
            foreach ($advances as $adv) {
                $daily[(int) $adv->date->format('d')] = (float) $adv->amount;
            }
        }

        return response()->json(['total' => $total, 'daily' => $daily]);
    }

    public function exportExcel(Request $request)
    {
        $clientId = $request->user()->current_client_id;
        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);
        $employeeId = $request->query('employee_id');

        $employee = PayrollEmployee::where('client_id', $clientId)->findOrFail($employeeId);
        $records = $this->service->list($clientId, $month, $year, $employeeId);
        $daysInMonth = (int) date('t', strtotime("{$year}-{$month}-01"));
        $shiftHours = (float) $employee->shift_hours;
        $lastCol = 'H';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance');
        $sheet->setRightToLeft(true);

        $headerBg = 'FF2C3E50';
        $headerFg = 'FFFFFFFF';

        // ── Title ──
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', "سجل الحضور والانصراف — {$employee->name}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF2C3E50');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', "الشهر: {$month}/{$year}  |  ساعات الشيفت: {$shiftHours}");
        $sheet->getStyle('A2')->getFont()->setSize(11)->getColor()->setARGB('FF7F8C8D');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // ── Headers row 4 ──
        $headers = ['م', 'التاريخ', 'اليوم', 'البداية', 'النهاية', 'إجمالي', 'النوع', 'أوفر تايم'];
        $headerRange = 'A4:' . $lastCol . '4';
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '4', $h);
        }
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB($headerFg);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($headerBg);
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension('4')->setRowHeight(22);

        // ── Daily data ──
        $dayNames = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $recordMap = [];
        foreach ($records as $r) {
            $recordMap[(int) date('j', strtotime($r['date']))] = $r;
        }

        $totalHours = 0;
        $overtimeAccum = 0;
        $presentDays = 0;
        $doubleShiftDays = 0;
        $overtimeDays = 0;
        $shortageDays = 0;

        $row = 5;
        $firstDataRow = $row;
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $timestamp = strtotime("{$year}-{$month}-{$day}");
            $dayOfWeek = (int) date('w', $timestamp);
            $isFriday = $dayOfWeek === 5;

            $sheet->setCellValue('A' . $row, $day);
            $sheet->setCellValue('B' . $row, date('Y-m-d', $timestamp));
            $sheet->setCellValue('C' . $row, $dayNames[$dayOfWeek]);

            $record = $recordMap[$day] ?? null;
            if ($record) {
                $netDiffH = $record['overtime_minutes'] / 60;
                $isDoubleShift = $record['is_double_shift'];

                $sheet->setCellValue('D' . $row, $record['shift_start'] ?? '');
                $sheet->setCellValue('E' . $row, $record['shift_end'] ?? '');
                $sheet->setCellValueExplicit('F' . $row, round($record['total_hours'], 2), DataType::TYPE_NUMERIC);

                if ($isDoubleShift) {
                    $sheet->setCellValue('G' . $row, 'تطبيق');
                    $sheet->getStyle('G' . $row)->getFont()->getColor()->setARGB('FF5B2C6F');
                    $sheet->setCellValue('H' . $row, '—');
                    $doubleShiftDays++;
                } elseif ($netDiffH > 0) {
                    $sheet->setCellValue('G' . $row, 'إضافي');
                    $sheet->getStyle('G' . $row)->getFont()->getColor()->setARGB('FFD4A017');
                    $sheet->setCellValueExplicit('H' . $row, round($netDiffH, 2), DataType::TYPE_NUMERIC);
                    $overtimeDays++;
                } elseif ($netDiffH < 0) {
                    $sheet->setCellValue('G' . $row, 'عجز');
                    $sheet->getStyle('G' . $row)->getFont()->getColor()->setARGB('FFE74C3C');
                    $sheet->setCellValueExplicit('H' . $row, round($netDiffH, 2), DataType::TYPE_NUMERIC);
                    $shortageDays++;
                } else {
                    $sheet->setCellValue('G' . $row, 'عادي');
                    $sheet->getStyle('G' . $row)->getFont()->getColor()->setARGB('FF27AE60');
                    $sheet->setCellValue('H' . $row, '—');
                }

                $totalHours += $record['total_hours'];
                $presentDays++;
                $overtimeAccum += $netDiffH;
            } else {
                $sheet->setCellValue('F' . $row, '—');
                $sheet->setCellValue('G' . $row, '—');
                $sheet->setCellValue('H' . $row, '—');
            }

            if ($isFriday) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF5F5F5');
            }

            $row++;
        }
        $lastDataRow = $row - 1;

        // ── Summary section ──
        $summaryStart = $row + 1;
        $sheet->mergeCells("A{$summaryStart}:D{$summaryStart}");
        $sheet->setCellValue("A{$summaryStart}", 'عدد ساعات الشيفت');
        $sheet->getStyle("A{$summaryStart}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$summaryStart}", $shiftHours);

        $r2 = $summaryStart + 1;
        $sheet->mergeCells("A{$r2}:D{$r2}");
        $sheet->setCellValue("A{$r2}", 'عدد أيام الشهر');
        $sheet->getStyle("A{$r2}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$r2}", $daysInMonth);

        $r3 = $summaryStart + 2;
        $sheet->mergeCells("A{$r3}:D{$r3}");
        $sheet->setCellValue("A{$r3}", 'إجمالي أيام العمل');
        $sheet->getStyle("A{$r3}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$r3}", $presentDays);

        $r4 = $summaryStart + 3;
        $sheet->mergeCells("A{$r4}:D{$r4}");
        $sheet->setCellValue("A{$r4}", 'صافي الفرق (ساعات)');
        $sheet->getStyle("A{$r4}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$r4}", round($overtimeAccum, 2));

        $r5 = $summaryStart + 4;
        $restDayOT = max(4 - ($daysInMonth - $presentDays), 0);
        $sheet->mergeCells("A{$r5}:D{$r5}");
        $sheet->setCellValue("A{$r5}", 'أيام إضافي راحات');
        $sheet->getStyle("A{$r5}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$r5}", $restDayOT);

        $r6 = $summaryStart + 5;
        $sheet->mergeCells("A{$r6}:D{$r6}");
        $sheet->setCellValue("A{$r6}", 'أيام تطبيق');
        $sheet->getStyle("A{$r6}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$r6}", $doubleShiftDays);

        // Style summary labels
        $summaryLabelsRange = "A{$summaryStart}:D{$r6}";
        $sheet->getStyle($summaryLabelsRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFECF0F1');
        $sheet->getStyle($summaryLabelsRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("A{$summaryStart}:F{$r6}")->getFont()->setSize(11);

        // ── Borders ──
        $sheet->getStyle("A4:{$lastCol}{$lastDataRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBDC3C7']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(10);
        $sheet->getColumnDimension('G')->setWidth(10);
        $sheet->getColumnDimension('H')->setWidth(10);

        $writer = new Xlsx($spreadsheet);
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\p{Arabic}]/u', '_', $employee->name);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "حضور_وانصراف_{$safeName}_{$month}_{$year}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
