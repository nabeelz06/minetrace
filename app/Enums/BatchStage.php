<?php

namespace App\Enums;

enum BatchStage: string
{
    case LabKimia     = 'lab_kimia';
    case Receiving    = 'receiving';
    case WetProcess   = 'wet_process';
    case DryProcess   = 'dry_process';
    case ProjectPlant = 'project_plant';
    case Warehouse    = 'warehouse';
    case Smelter      = 'smelter';
    case Exported     = 'exported';
    case Terminated   = 'terminated';

    public function label(string $lang = 'id'): string
    {
        $labels = [
            'id' => [
                self::LabKimia->value     => 'Lab Kimia',
                self::Receiving->value    => 'Receiving Area',
                self::WetProcess->value   => 'Wet Process',
                self::DryProcess->value   => 'Dry Process',
                self::ProjectPlant->value => 'Project Plant',
                self::Warehouse->value    => 'LTJ Warehouse',
                self::Smelter->value      => 'Smelter',
                self::Exported->value     => 'Diekspor',
                self::Terminated->value   => 'Terminal',
            ],
            'en' => [
                self::LabKimia->value     => 'Chemical Lab',
                self::Receiving->value    => 'Receiving Area',
                self::WetProcess->value   => 'Wet Process',
                self::DryProcess->value   => 'Dry Process',
                self::ProjectPlant->value => 'Project Plant',
                self::Warehouse->value    => 'LTJ Warehouse',
                self::Smelter->value      => 'Smelter',
                self::Exported->value     => 'Exported',
                self::Terminated->value   => 'Terminal',
            ],
        ];

        return $labels[$lang][$this->value] ?? $this->value;
    }

    public function icon(): string
    {
        return match($this) {
            self::LabKimia     => '🧪',
            self::Receiving    => '📥',
            self::WetProcess   => '💧',
            self::DryProcess   => '🔥',
            self::ProjectPlant => '⚗️',
            self::Warehouse    => '🏭',
            self::Smelter      => '⚡',
            self::Exported     => '🚢',
            self::Terminated   => '🔴',
        };
    }

    /** Stage yang bisa menjadi stage awal batch baru */
    public static function creationStages(): array
    {
        return [self::LabKimia];
    }

    /** Stage terminal — batch tidak bisa maju lagi */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Smelter, self::Exported, self::Terminated]);
    }
}