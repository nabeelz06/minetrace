<?php

namespace Tests\Feature\Pool;

use App\Services\Batch\QrCodeService;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;

class ContainerDisplayTest extends PoolTestCase
{
    #[Test]
    public function batch_detail_shows_container_and_sn(): void
    {
        $b = $this->makeBatch('wet_process', 'pooled', ['container_type' => 'drum_besi', 'sn_grade_pct' => 1.875]);

        $this->actingAs($this->admin)->get("/company/batches/{$b->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('batch.container_label', 'Drum besi')
                ->where('batch.sn_grade_pct', 1.875));
    }

    #[Test]
    public function public_page_shows_container_but_not_sn(): void
    {
        $b = $this->makeBatch('wet_process', 'pooled', ['container_type' => 'drum_besi', 'sn_grade_pct' => 7.777]);
        $url = app(QrCodeService::class)->buildPayload($b);

        $res = $this->get(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY))->assertOk();

        $res->assertInertia(fn (AssertableInertia $p) => $p->where('batch.container.id', 'Drum besi'));
        $this->assertStringNotContainsString('7.777', $res->getContent());
    }
}
