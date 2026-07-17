<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Generic export: any headings + rows (array of assoc arrays). */
class TableExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function __construct(
        protected array $headings,
        protected array $rows,
    ) {}

    public function array(): array
    {
        // Flatten assoc rows to ordered values matching the headings
        return array_map(fn ($row) => array_values($row), $this->rows);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
