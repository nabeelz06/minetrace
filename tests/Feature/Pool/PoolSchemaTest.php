<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;

class PoolSchemaTest extends PoolTestCase
{
    #[Test]
    public function rfid_tag_unique_is_enforced(): void
    {
        $this->makeBatch('receiving', 'in_process', ['rfid_tag' => 'E200AAAA0001']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeBatch('receiving', 'in_process', ['rfid_tag' => 'E200AAAA0001']);
    }

    #[Test]
    public function two_null_tags_allowed(): void
    {
        $this->makeBatch('receiving', 'in_process');
        $this->makeBatch('receiving', 'in_process');

        $this->assertSame(2, Batch::whereNull('rfid_tag')->where('company_id', $this->company->id)->where('batch_code', 'like', 'T-%')->count());
    }

    #[Test]
    public function status_accepts_pooled_and_awaiting_weight(): void
    {
        $a = $this->makeBatch('wet_process', 'pooled');
        $b = $this->makeBatch('wet_process', 'awaiting_weight');

        $this->assertSame('pooled', $a->fresh()->status);
        $this->assertSame('awaiting_weight', $b->fresh()->status);
    }
}
