<?php

namespace App\Services\Payroll;

use App\Models\Payroll\PayrollMonthly;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PayslipExportService
{
    public function exportExcel(string $clientId, string $payrollId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $payroll = PayrollMonthly::where('client_id', $clientId)
            ->with('details.employee', 'details.bonusItems')
            ->findOrFail($payrollId);

        $month = $payroll->month;
        $year = $payroll->year;
        $details = $payroll->details;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('الرواتب');
        $sheet->setRightToLeft(true);

        // Title
        $sheet->mergeCells('A1:N1');
        $sheet->setCellValue('A1', "كشف الرواتب — {$month}/{$year}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Headers row 2
        $headers = ['م', 'الاسم', 'الوظيفة', 'المرتب الأساسي', 'أيام العمل', 'أجر اليوم',
            'خصم غياب', 'إضافي راحات', 'أوفر تايم', 'المكافآت', 'السلفة',
            'إجمالي الخصم', 'صافي المرتب'];

        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '2', $h);
        }

        $headerRange = 'A2:' . Coordinate::stringFromColumnIndex(count($headers)) . '2';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8E8E8');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $colWidths = [5, 20, 15, 12, 10, 10, 12, 12, 10, 12, 10, 12, 12];
        foreach ($colWidths as $i => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        }

        $row = 3;
        $totals = array_fill(0, count($headers), 0);
        foreach ($details as $i => $d) {
            $col = 1;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $i + 1);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->employee->name);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->employee->job_title ?? '');
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->base_salary_snapshot);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->work_days);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->daily_wage_snapshot);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->absence_amount);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->rest_day_ot_amount);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->overtime_amount);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->bonus_total);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->advance_amount);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->total_deductions);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $d->net_salary);

            $totals[3] += $d->base_salary_snapshot;
            $totals[4] += $d->work_days;
            $totals[6] += $d->absence_amount;
            $totals[7] += $d->rest_day_ot_amount;
            $totals[8] += $d->overtime_amount;
            $totals[9] += $d->bonus_total;
            $totals[10] += $d->advance_amount;
            $totals[11] += $d->total_deductions;
            $totals[12] += $d->net_salary;

            $row++;
        }

        // Totals row
        if ($row > 3) {
            $sheet->setCellValue('A' . $row, '');
            $sheet->mergeCells('A' . $row . ':B' . $row);
            $sheet->setCellValue('A' . $row, 'الإجمالي');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);

            for ($c = 4; $c <= 13; $c++) {
                if ($totals[$c - 1] > 0) {
                    $cell = Coordinate::stringFromColumnIndex($c) . $row;
                    $sheet->setCellValue($cell, $totals[$c - 1]);
                    $sheet->getStyle($cell)->getFont()->setBold(true);
                }
            }

            $dataEnd = $row;
            $styleArray = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
            ];
            $sheet->getStyle('A2:' . Coordinate::stringFromColumnIndex(count($headers)) . $dataEnd)->applyFromArray($styleArray);
        }

        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "كشف_الرواتب_{$month}_{$year}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPayslipPdf(string $clientId, string $payrollId, string $employeeId): \Symfony\Component\HttpFoundation\Response
    {
        $payroll = PayrollMonthly::where('client_id', $clientId)
            ->with('details.employee', 'details.bonusItems')
            ->findOrFail($payrollId);

        $detail = $payroll->details->where('employee_id', $employeeId)->firstOrFail();

        $html = view('payroll.payslip', [
            'payroll' => $payroll,
            'detail' => $detail,
        ])->render();

        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML($html);
        $pdf->setPaper('A4', 'portrait');

        return $pdf->stream("payslip_{$detail->employee->name}_{$payroll->month}_{$payroll->year}.pdf");
    }
}
