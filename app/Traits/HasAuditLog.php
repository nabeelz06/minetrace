<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

/**
 * HasAuditLog — auto-set created_by dan updated_by.
 * Boot di model untuk logging otomatis.
 */
trait HasAuditLog
{
    public static function bootHasAuditLog(): void
    {
        static::creating(function ($model) {
            if (Auth::check() && $model->isFillable('created_by')) {
                $model->created_by = $model->created_by ?? Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check() && $model->isFillable('updated_by')) {
                $model->updated_by = Auth::id();
            }
        });
    }
}