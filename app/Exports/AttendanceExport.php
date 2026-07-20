<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(protected Collection $logs) {}

    public function collection(): Collection
    {
        return $this->logs;
    }

    public function headings(): array
    {
        return ['Employee', 'Code', 'Office', 'Type', 'Status', 'Time', 'Date', 'Source', 'Location', 'IP Address'];
    }

    public function map($log): array
    {
        return [
            $log->employee->full_name ?? '—',
            $log->employee->employee_code ?? '—',
            $log->office->name ?? '—',
            strtoupper($log->type),
            ucfirst(str_replace('_', ' ', $log->status)),
            $log->scanned_at->format('h:i A'),
            $log->work_date->format('m/d/Y'),
            $log->source,
            ($log->latitude && $log->longitude) ? $log->latitude . ', ' . $log->longitude : '—',
            $log->ip_address ?? '—',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
