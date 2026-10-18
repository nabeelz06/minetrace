<?php

namespace Tests\Unit\Services;

use App\Services\Hardware\Uhf\UhfTagIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Definisi UID kanonik: HARUS sama dengan padanan JS (resources/js/lib/uhf/identity.js; vektor sama). */
class UhfTagIdentityTest extends TestCase
{
    public static function vectorProvider(): array
    {
        $v = json_decode(file_get_contents(__DIR__.'/../../Fixtures/uhf/identity.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn ($row) => [$row[0], $row[1]], $v['canonical']);
    }

    #[Test]
    #[DataProvider('vectorProvider')]
    public function canonical_sesuai_vektor_bersama(string $input, ?string $expected): void
    {
        $this->assertSame($expected, UhfTagIdentity::canonical($input));
    }

    #[Test]
    public function dari_byte_epc(): void
    {
        $this->assertSame('E20000200914016323809025', UhfTagIdentity::fromBytes(hex2bin('E20000200914016323809025')));
        $this->assertNull(UhfTagIdentity::fromBytes(''));
        $this->assertNull(UhfTagIdentity::fromBytes(str_repeat("\x01", 26)), '26 byte = 52 karakter > 50');
        $this->assertSame(50, strlen(UhfTagIdentity::fromBytes(str_repeat("\x01", 25))));
    }
}
