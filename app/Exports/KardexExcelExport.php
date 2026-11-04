<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KardexExcelExport
{
    /**
     * Genera la descarga en formato Excel (.xlsx) del kardex valorizado.
     */
    public static function download(array $report): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        // ── HOJA 1: CONSOLIDADO ──
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('CONSOLIDADO');

        $sheet->setCellValue('A1', 'RWAREHOUSE MODA — KARDEX VALORIZADO');
        $sheet->setCellValue('A2', 'PERIODO: ' . mb_strtoupper($report['period_label']));

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);

        $headers = ['Categoría', 'Inv. Inicial (S/)', '(+) Compras / Ent. (S/)', '(-) Inv. Final (S/)', '(=) Costo de Ventas (S/)', 'Diferencia (S/)'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col . '4', $h);
            $sheet->getStyle($col . '4')->getFont()->setBold(true);
            $sheet->getStyle($col . '4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
            $sheet->getStyle($col . '4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $col++;
        }

        $rowNum = 5;
        foreach ($report['categories'] as $catName => $blocks) {
            $catInitial = 0.0;
            $catEntries = 0.0;
            $catFinal = 0.0;
            $catCostOfSales = 0.0;

            foreach ($blocks as $b) {
                $catInitial += $b['summary']['initial_cost'];
                $catEntries += $b['summary']['entries_cost'];
                $catFinal += $b['summary']['final_cost'];
                $catCostOfSales += $b['summary']['cost_of_sales'];
            }

            $catDiff = round(($catInitial + $catEntries - $catFinal) - $catCostOfSales, 2);

            $sheet->setCellValue('A' . $rowNum, $catName);
            $sheet->setCellValue('B' . $rowNum, $catInitial);
            $sheet->setCellValue('C' . $rowNum, $catEntries);
            $sheet->setCellValue('D' . $rowNum, $catFinal);
            $sheet->setCellValue('E' . $rowNum, $catCostOfSales);
            $sheet->setCellValue('F' . $rowNum, $catDiff);

            $sheet->getStyle("B{$rowNum}:F{$rowNum}")->getNumberFormat()->setFormatCode('#,##0.00');
            $rowNum++;
        }

        // Fila Total
        $sheet->setCellValue('A' . $rowNum, 'TOTAL CONSOLIDADO');
        $sheet->setCellValue('B' . $rowNum, $report['consolidated']['initial_cost']);
        $sheet->setCellValue('C' . $rowNum, $report['consolidated']['entries_cost']);
        $sheet->setCellValue('D' . $rowNum, $report['consolidated']['final_cost']);
        $sheet->setCellValue('E' . $rowNum, $report['consolidated']['cost_of_sales']);
        $sheet->setCellValue('F' . $rowNum, $report['consolidated']['difference']);

        $sheet->getStyle("A{$rowNum}:F{$rowNum}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowNum}:F{$rowNum}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$rowNum}:F{$rowNum}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        $sheet->getStyle("B{$rowNum}:F{$rowNum}")->getNumberFormat()->setFormatCode('#,##0.00');

        foreach (range('A', 'F') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        // ── HOJAS POR CATEGORÍA ──
        foreach ($report['categories'] as $catName => $blocks) {
            // Nombre de hoja seguro (máx 31 caracteres, sin caracteres inválidos)
            $sheetTitle = mb_substr(preg_replace('/[\/*?:\[\]]/', '', $catName), 0, 30);
            $catSheet = $spreadsheet->createSheet();
            $catSheet->setTitle($sheetTitle ?: 'Detalle');

            $curRow = 1;

            foreach ($blocks as $block) {
                // Título del bloque SKU
                $catSheet->mergeCells("A{$curRow}:N{$curRow}");
                $catSheet->setCellValue("A{$curRow}", "{$block['title']} — Almacén: {$block['warehouse_name']}");
                $catSheet->getStyle("A{$curRow}")->getFont()->setBold(true)->setSize(11);
                $catSheet->getStyle("A{$curRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD1D5DB');
                $curRow++;

                // Cabecera Fila 1
                $catSheet->setCellValue("A{$curRow}", 'ITEM');
                $catSheet->setCellValue("B{$curRow}", 'FECHA');
                $catSheet->setCellValue("C{$curRow}", 'GUÍA REMISIÓN');
                $catSheet->setCellValue("D{$curRow}", 'COMPROB. PAGO');
                $catSheet->setCellValue("E{$curRow}", 'DETALLE');

                $catSheet->mergeCells("F{$curRow}:H{$curRow}");
                $catSheet->setCellValue("F{$curRow}", 'ENTRADAS');

                $catSheet->mergeCells("I{$curRow}:K{$curRow}");
                $catSheet->setCellValue("I{$curRow}", 'SALIDAS');

                $catSheet->mergeCells("L{$curRow}:N{$curRow}");
                $catSheet->setCellValue("L{$curRow}", 'SALDOS');

                $catSheet->getStyle("A{$curRow}:N{$curRow}")->getFont()->setBold(true);
                $catSheet->getStyle("A{$curRow}:N{$curRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $catSheet->getStyle("A{$curRow}:N{$curRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
                $curRow++;

                // Cabecera Fila 2
                $subHeaders = [
                    'A' => '', 'B' => '', 'C' => '', 'D' => '', 'E' => '',
                    'F' => 'CANT.', 'G' => 'P.U.', 'H' => 'P.T.',
                    'I' => 'CANT.', 'J' => 'P.U.', 'K' => 'P.T.',
                    'L' => 'CANT.', 'M' => 'P.U.', 'N' => 'P.T.',
                ];
                foreach ($subHeaders as $c => $val) {
                    if ($val !== '') {
                        $catSheet->setCellValue($c . $curRow, $val);
                    }
                }
                $catSheet->getStyle("A{$curRow}:N{$curRow}")->getFont()->setBold(true);
                $catSheet->getStyle("A{$curRow}:N{$curRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $catSheet->getStyle("A{$curRow}:N{$curRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
                $curRow++;

                // Fila Saldo Inicial
                $catSheet->setCellValue("E{$curRow}", $block['initial']['label']);
                $catSheet->setCellValue("L{$curRow}", $block['initial']['qty']);
                $catSheet->setCellValue("M{$curRow}", $block['initial']['unit_cost']);
                $catSheet->setCellValue("N{$curRow}", $block['initial']['total_cost']);
                $catSheet->getStyle("E{$curRow}")->getFont()->setBold(true);
                $catSheet->getStyle("L{$curRow}:N{$curRow}")->getFont()->setBold(true);
                $catSheet->getStyle("M{$curRow}")->getNumberFormat()->setFormatCode('#,##0.0000');
                $catSheet->getStyle("N{$curRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $curRow++;

                // Filas de movimientos
                foreach ($block['rows'] as $r) {
                    $catSheet->setCellValue("A{$curRow}", $r['item']);
                    $catSheet->setCellValue("B{$curRow}", $r['date']);
                    $catSheet->setCellValue("C{$curRow}", $r['guide']);
                    $catSheet->setCellValue("D{$curRow}", $r['doc']);
                    $catSheet->setCellValue("E{$curRow}", $r['detail']);

                    if ($r['entry_qty'] !== null) {
                        $catSheet->setCellValue("F{$curRow}", $r['entry_qty']);
                        $catSheet->setCellValue("G{$curRow}", $r['entry_unit']);
                        $catSheet->setCellValue("H{$curRow}", $r['entry_total']);
                        if ($r['entry_qty'] < 0) {
                            $catSheet->getStyle("F{$curRow}:H{$curRow}")->getFont()->getColor()->setARGB('FFDC2626');
                        }
                    }

                    if ($r['exit_qty'] !== null) {
                        $catSheet->setCellValue("I{$curRow}", $r['exit_qty']);
                        $catSheet->setCellValue("J{$curRow}", $r['exit_unit']);
                        $catSheet->setCellValue("K{$curRow}", $r['exit_total']);
                        if ($r['exit_qty'] < 0) {
                            $catSheet->getStyle("I{$curRow}:K{$curRow}")->getFont()->getColor()->setARGB('FFDC2626');
                        }
                    }

                    $catSheet->setCellValue("L{$curRow}", $r['balance_qty']);
                    $catSheet->setCellValue("M{$curRow}", $r['balance_unit']);
                    $catSheet->setCellValue("N{$curRow}", $r['balance_total']);

                    $catSheet->getStyle("G{$curRow}")->getNumberFormat()->setFormatCode('#,##0.0000');
                    $catSheet->getStyle("H{$curRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $catSheet->getStyle("J{$curRow}")->getNumberFormat()->setFormatCode('#,##0.0000');
                    $catSheet->getStyle("K{$curRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $catSheet->getStyle("M{$curRow}")->getNumberFormat()->setFormatCode('#,##0.0000');
                    $catSheet->getStyle("N{$curRow}")->getNumberFormat()->setFormatCode('#,##0.00');

                    $curRow++;
                }

                // Fila de Totales
                $catSheet->setCellValue("E{$curRow}", 'COMPROBACIÓN: TOTALES');
                $catSheet->setCellValue("F{$curRow}", $block['totals']['entries_qty']);
                $catSheet->setCellValue("H{$curRow}", $block['totals']['entries_cost']);
                $catSheet->setCellValue("I{$curRow}", $block['totals']['exits_qty']);
                $catSheet->setCellValue("K{$curRow}", $block['totals']['exits_cost']);
                $catSheet->setCellValue("L{$curRow}", $block['totals']['balance_qty']);
                $catSheet->setCellValue("N{$curRow}", $block['totals']['balance_total']);

                $catSheet->getStyle("A{$curRow}:N{$curRow}")->getFont()->setBold(true);
                $catSheet->getStyle("A{$curRow}:N{$curRow}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
                $catSheet->getStyle("A{$curRow}:N{$curRow}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
                $catSheet->getStyle("H{$curRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $catSheet->getStyle("K{$curRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $catSheet->getStyle("N{$curRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $curRow++;

                // Cuadro de comprobación contable
                $compStart = $curRow;
                $catSheet->setCellValue("E{$curRow}", 'INV. INICIAL:');
                $catSheet->setCellValue("F{$curRow}", $block['summary']['initial_cost']);
                $curRow++;

                $catSheet->setCellValue("E{$curRow}", '(+) COMPRAS:');
                $catSheet->setCellValue("F{$curRow}", $block['summary']['entries_cost']);
                $curRow++;

                $catSheet->setCellValue("E{$curRow}", '(−) INV FINAL:');
                $catSheet->setCellValue("F{$curRow}", -1 * $block['summary']['final_cost']);
                $curRow++;

                $catSheet->setCellValue("E{$curRow}", '(=) COSTO DE VENTAS:');
                $catSheet->setCellValue("F{$curRow}", $block['summary']['cost_of_sales']);
                $catSheet->setCellValue("G{$curRow}", 'DIFERENCIA:');
                $catSheet->setCellValue("H{$curRow}", $block['summary']['difference']);

                $catSheet->getStyle("E{$compStart}:H{$curRow}")->getFont()->setBold(true);
                $catSheet->getStyle("F{$compStart}:F{$curRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $catSheet->getStyle("H{$curRow}")->getNumberFormat()->setFormatCode('#,##0.00');

                $curRow += 2; // Espacio entre bloques
            }

            foreach (range('A', 'N') as $c) {
                $catSheet->getColumnDimension($c)->setAutoSize(true);
            }
        }

        $filename = 'Kardex_Valorizado_' . str_replace('-', '_', $report['period_raw']) . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}

