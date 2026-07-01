<?php

namespace App\Contracts;

use App\Models\Batch;

/**
 * ScanAuthorizationStrategy — Strategy interface for Progressive Authorization Model.
 *
 * 3 concrete implementations:
 *   AuditOnlyStrategy      → scan = checkpoint audit trail only (current default)
 *   ScanToReceiveStrategy  → scan IN also triggers receiveBatch() chain
 *   ScanToDispatchStrategy → scan OUT also triggers forwardBatch() chain
 *
 * ARCHITECTURE CONSTRAINT: All mode-changing implementations MUST reuse the same
 * Chain-of-Responsibility guards already in ProcessController before any transition.
 */
interface ScanAuthorizationStrategy
{
    /**
     * Handle a Scan IN event for the given batch at the given stage.
     * Must return the same shape as RfidService::checkpointIn().
     */
    public function handleScanIn(Batch $batch, string $stage, array $context): array;

    /**
     * Handle a Scan OUT event for the given batch at the given stage.
     * Must return the same shape as RfidService::checkpointOut().
     */
    public function handleScanOut(Batch $batch, string $stage, array $context): array;

    /** Machine-readable mode identifier: audit_only | scan_to_receive | scan_to_dispatch */
    public function getMode(): string;

    /** Human-readable description (Bahasa Indonesia) */
    public function getDescription(): string;
}
