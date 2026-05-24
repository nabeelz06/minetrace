<?php

namespace App\Http\Controllers\Api\V1\Checkpoint;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * ApiCheckpointController — input checkpoint dari mobile/hardware.
 * Endpoint ini akan digunakan oleh RFID reader di Sprint 8.
 */
class ApiCheckpointController extends Controller
{
    /**
     * POST /api/v1/company/checkpoints/{batch}
     * Record checkpoint baru untuk batch.
     */
    public function store(Batch $batch, Request $request): JsonResponse
    {
        abort_unless($batch->company_id === Auth::user()->company_id, 403);

        $validated = $request->validate([
            'checkpoint_stage' => 'required|string',
            'weight_recorded'  => 'nullable|numeric|min:0',
            'location_id'      => 'nullable|exists:locations,id',
            'notes'            => 'nullable|string|max:1000',
            'checkpoint_data'  => 'nullable|array',
            'rfid_scan_code'   => 'nullable|string|max:100',
            'qr_scan_code'     => 'nullable|string|max:100',
        ]);

        return DB::transaction(function () use ($batch, $validated) {
            $previousWeight = $batch->current_weight;
            $newWeight      = $validated['weight_recorded'] ?? $previousWeight;
            $loss           = $previousWeight - $newWeight;
            $lossPct        = $previousWeight > 0
                ? round(($loss / $previousWeight) * 100, 2)
                : 0;

            $checkpoint = Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $validated['location_id'] ?? $batch->current_location_id,
                'checkpoint_stage' => $validated['checkpoint_stage'],
                'weight_recorded'  => $newWeight,
                'weight_previous'  => $previousWeight,
                'weight_loss'      => $loss,
                'weight_loss_pct'  => $lossPct,
                'notes'            => $validated['notes'] ?? null,
                'checkpoint_data'  => $validated['checkpoint_data'] ?? [],
                'rfid_scan_code'   => $validated['rfid_scan_code'] ?? null,
                'qr_scan_code'     => $validated['qr_scan_code'] ?? null,
                'scan_timestamp'   => $validated['rfid_scan_code'] || $validated['qr_scan_code'] ? now() : null,
                'recorded_by'      => Auth::id(),
                'recorded_at'      => now(),
            ]);

            // Update batch weight
            $batch->update([
                'current_weight' => $newWeight,
                'mass_loss_pct'  => $lossPct,
                'updated_by'     => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'data'    => [
                    'checkpoint_id'  => $checkpoint->id,
                    'batch_code'     => $batch->batch_code,
                    'new_weight'     => $newWeight,
                    'weight_loss_pct'=> $lossPct,
                    'recorded_at'    => $checkpoint->recorded_at->toIso8601String(),
                ],
                'message' => 'Checkpoint berhasil dicatat.',
            ], 201);
        });
    }

    /**
     * GET /api/v1/company/checkpoints/{batch}
     * Riwayat checkpoint untuk batch.
     */
    public function index(Batch $batch): JsonResponse
    {
        abort_unless($batch->company_id === Auth::user()->company_id, 403);

        $checkpoints = $batch->checkpoints()
            ->with(['location', 'recordedByUser'])
            ->get()
            ->map(fn($cp) => [
                'id'               => $cp->id,
                'stage'            => $cp->checkpoint_stage,
                'weight_recorded'  => $cp->weight_recorded,
                'weight_loss_pct'  => $cp->weight_loss_pct,
                'has_discrepancy'  => $cp->has_discrepancy,
                'location'         => $cp->location?->name,
                'recorded_by'      => $cp->recordedByUser?->name,
                'notes'            => $cp->notes,
                'recorded_at'      => $cp->recorded_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data'    => $checkpoints,
            'message' => 'OK',
        ]);
    }
}