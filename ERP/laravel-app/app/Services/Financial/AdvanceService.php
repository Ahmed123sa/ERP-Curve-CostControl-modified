<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialEmployee;
use App\Models\Financial\EmployeeAdvance;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class AdvanceService
{
    public function employees(string $clientId): array
    {
        return FinancialEmployee::where('client_id', $clientId)
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function storeEmployee(string $clientId, string $name): FinancialEmployee
    {
        return FinancialEmployee::create([
            'client_id' => $clientId,
            'name' => $name,
        ]);
    }

    public function updateEmployee(string $clientId, string $id, string $name): FinancialEmployee
    {
        $employee = FinancialEmployee::where('client_id', $clientId)->findOrFail($id);
        $employee->update(['name' => $name]);
        return $employee;
    }

    public function list(string $clientId, string $month): array
    {
        [$year, $monthNum] = explode('-', $month);

        $employees = FinancialEmployee::where('client_id', $clientId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $advances = EmployeeAdvance::where('client_id', $clientId)
            ->whereYear('date', $year)
            ->whereMonth('date', $monthNum)
            ->get();

        $daysInMonth = (int) date('t', strtotime($month . '-01'));

        // Build matrix: employee_id x day -> amount
        $matrix = [];
        foreach ($employees as $emp) {
            $matrix[$emp->id] = [
                'employee' => $emp,
                'days' => array_fill(1, $daysInMonth, 0),
                'total' => 0,
            ];
        }

        foreach ($advances as $adv) {
            $day = (int) $adv->date->format('d');
            if (isset($matrix[$adv->employee_id])) {
                $matrix[$adv->employee_id]['days'][$day] = (float) $adv->amount;
                $matrix[$adv->employee_id]['total'] += (float) $adv->amount;
            }
        }

        return [
            'month' => $month,
            'days_in_month' => $daysInMonth,
            'employees' => $employees->toArray(),
            'matrix' => $matrix,
        ];
    }

    public function store(string $clientId, array $data): EmployeeAdvance
    {
        return DB::transaction(function () use ($clientId, $data) {
            // Delete existing advance for same employee+date
            EmployeeAdvance::where('client_id', $clientId)
                ->where('employee_id', $data['employee_id'])
                ->where('date', $data['date'])
                ->delete();

            if (($data['amount'] ?? 0) > 0) {
                return EmployeeAdvance::create([
                    'client_id' => $clientId,
                    'employee_id' => $data['employee_id'],
                    'date' => $data['date'],
                    'amount' => $data['amount'],
                    'notes' => $data['notes'] ?? null,
                ]);
            }

            return null;
        });
    }

    public function destroy(string $clientId, string $id): bool
    {
        $advance = EmployeeAdvance::where('client_id', $clientId)->findOrFail($id);
        return $advance->delete();
    }

    public function exportExcel(string $clientId, string $month): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $data = $this->list($clientId, $month);
        $employees = $data['employees'];
        $matrix = $data['matrix'];
        $daysInMonth = $data['days_in_month'];
        [$year, $monthNum] = explode('-', $month);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('السلف');
        $sheet->setRightToLeft(true);

        // Header
        $sheet->mergeCells('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($daysInMonth + 3) . '1');
        $sheet->setCellValue('A1', "سلف الموظفين — {$month}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Column headers
        $sheet->setCellValue('A2', 'م');
        $sheet->setCellValue('B2', 'الموظف');
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($day + 2);
            $sheet->setCellValue($col . '2', $day);
        }
        $totalCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($daysInMonth + 3);
        $sheet->setCellValue($totalCol . '2', 'الإجمالي');

        $headerRange = 'A2:' . $totalCol . '2';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8E8E8');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(20);
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($day + 2);
            $sheet->getColumnDimension($col)->setWidth(8);
        }
        $sheet->getColumnDimension($totalCol)->setWidth(12);

        $row = 3;
        $grandTotal = 0;
        foreach ($employees as $i => $emp) {
            $empData = $matrix[$emp['id']] ?? null;
            if (!$empData) continue;

            $sheet->setCellValue('A' . $row, $i + 1);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setCellValue('B' . $row, $emp['name']);
            $sheet->getStyle('B' . $row)->getFont()->setBold(true);

            $empTotal = 0;
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $amt = $empData['days'][$day] ?? 0;
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($day + 2);
                if ($amt > 0) {
                    $sheet->setCellValue($col . $row, $amt);
                    $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                    $empTotal += $amt;
                }
            }

            $sheet->setCellValue($totalCol . $row, $empTotal);
            $sheet->getStyle($totalCol . $row)->getFont()->setBold(true);
            $sheet->getStyle($totalCol . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $grandTotal += $empTotal;
            $row++;
        }

        if ($row > 3) {
            $dataEnd = $row - 1;
            $styleArray = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
            ];
            $sheet->getStyle('A2:' . $totalCol . $dataEnd)->applyFromArray($styleArray);

            $sheet->setCellValue('A' . $row, '');
            $sheet->mergeCells('A' . $row . ':B' . $row);
            $sheet->setCellValue('A' . $row, 'الإجمالي العام');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $sheet->setCellValue($totalCol . $row, $grandTotal);
            $sheet->getStyle($totalCol . $row)->getFont()->setBold(true);
            $sheet->getStyle($totalCol . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "سلف_الموظفين_{$month}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
