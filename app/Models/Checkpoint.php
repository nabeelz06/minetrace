<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Checkpoint — pencatatan perpindahan/verifikasi batch.
 * Dibuat setiap kali batch berpindah lokasi atau status berubah.
 */
class Checkpoint extends Model
{
    protected $fillable = [
        'batch_id', 'location_id', 'checkpoint_stage',
        'weight_recorded', 'weight_previous', 'weight_loss', 'weight_loss_pct',
        'has_discrepancy', 'is_verified', 'verified_at', 'verified_by',
        'next_stage', 'checkpoint_data', 'photos', 'snapshot_status',
        'rfid_origin', 'capture_attempted', 'capture_succeeded',
        'notes', 'rfid_scan_code', 'qr_scan_code', 'scan_timestamp',
        'recorded_by', 'recorded_at',
    ];

    protected $casts = [
        'weight_recorded'  => 'float',
        'weight_previous'  => 'float',
        'weight_loss'      => 'float',
        'weight_loss_pct'  => 'float',
        'has_discrepancy'  => 'boolean',
        'is_verified'      => 'boolean',
        'checkpoint_data'  => 'array',
        'photos'           => 'array',
        'verified_at'      => 'datetime',
        'scan_timestamp'   => 'datetime',
        'recorded_at'      => 'datetime',
        'snapshot_status'   => 'string',
        'capture_attempted' => 'boolean',
        'capture_succeeded' => 'boolean',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // ─── Accessors ────────────────────────────────────────────────────────

    public function getStageLabelAttribute(): string
    {
        return match($this->checkpoint_stage) {
            'lab_kimia_create'     => 'Pembuatan Batch (Lab Kimia)',
            'lab_sample_taken'     => 'Sampel Lab Diambil',
            'in_transit'           => 'Dalam Perjalanan',
            'receiving_verify'     => 'Verifikasi Receiving',
            'wet_process_input'    => 'Masuk Wet Process',
            'wet_process_output'   => 'Keluar Wet Process',
            'dry_process_input'    => 'Masuk Dry Process',
            'dry_process_output'   => 'Keluar Dry Process',
            'project_plant_input'  => 'Masuk Project Plant',
            'project_plant_output' => 'Keluar Project Plant',
            'warehouse_inbound'    => 'Masuk Warehouse',
            'warehouse_outbound'   => 'Keluar Warehouse',
            'warehouse_transfer'   => 'Pengiriman ke Lab LTJ',
            'smelter_input'        => 'Masuk Smelter (Terminal)',
            'export_release'       => 'Rilis Ekspor',
            'reprocess_return'     => 'Dikembalikan untuk Reproses',
            default                => str_replace('_', ' ', $this->checkpoint_stage ?? ''),
        };
    }
}