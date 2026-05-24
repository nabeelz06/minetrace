<?php

namespace App\Enums;

/**
 * BatchStatus — semua status batch yang valid.
 * Gunakan ini daripada hardcoded string 'active', 'in_process', dll.
 */
enum BatchStatus: string
{
    case Draft       = 'draft';
    case Active      = 'active';
    case InTransit   = 'in_transit';
    case InProcess   = 'in_process';
    case InWarehouse = 'in_warehouse';
    case Completed   = 'completed';
    case Split       = 'split';
    case Transformed = 'transformed';
    case Exported    = 'exported';
    case Terminated  = 'terminated';

    public function label(string $lang = 'id'): string
    {
        $labels = [
            'id' => [
                self::Draft->value       => 'Draft',
                self::Active->value      => 'Aktif',
                self::InTransit->value   => 'Dalam Perjalanan',
                self::InProcess->value   => 'Sedang Diproses',
                self::InWarehouse->value => 'Di Gudang',
                self::Completed->value   => 'Selesai',
                self::Split->value       => 'Di-split',
                self::Transformed->value => 'Ditransformasi',
                self::Exported->value    => 'Diekspor',
                self::Terminated->value  => 'Terminal',
            ],
            'en' => [
                self::Draft->value       => 'Draft',
                self::Active->value      => 'Active',
                self::InTransit->value   => 'In Transit',
                self::InProcess->value   => 'Processing',
                self::InWarehouse->value => 'In Warehouse',
                self::Completed->value   => 'Completed',
                self::Split->value       => 'Split',
                self::Transformed->value => 'Transformed',
                self::Exported->value    => 'Exported',
                self::Terminated->value  => 'Terminal',
            ],
        ];

        return $labels[$lang][$this->value] ?? $this->value;
    }

    /** Status yang dianggap "aktif" (sedang dalam proses) */
    public static function activeStatuses(): array
    {
        return [self::Active, self::InTransit, self::InProcess];
    }

    /** Status yang tidak bisa dimodifikasi lagi */
    public static function terminalStatuses(): array
    {
        return [self::Exported, self::Terminated, self::Transformed];
    }
}