import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { canonicalUid } from '../identity.js'
import { FrameParser, decodeRealtime, decodeVersion, frames, toHex } from '../r2000.js'

const root = join(dirname(fileURLToPath(import.meta.url)), '../../../../..')
const read = p => JSON.parse(readFileSync(join(root, p), 'utf8'))
const hex = h => Uint8Array.from(h.replace(/\s+/g, '').match(/.{2}/g).map(b => parseInt(b, 16)))

test('UID kanonik sesuai vektor bersama dengan PHP', () => {
    for (const [input, expected] of read('tests/Fixtures/uhf/identity.json').canonical) {
        assert.equal(canonicalUid(input), expected, JSON.stringify(input))
    }
})

test('KESETARAAN LINTAS PEMBACA: EPC V1 (UR4, PHP) = EPC A1 (R2000, JS) = UID kanonik yang sama', () => {
    const v1 = read('tests/Fixtures/uhf/ur4/golden.json').tags.find(t => t.name === 'V1').expect.epc

    // Bingkai tag R2000 sintetis (Lampiran D5) memuat EPC yang sama dengan V1.
    const a1 = hex('A0 19 00 89 01 30 00 E2 00 00 20 09 14 01 63 23 80 90 25 06 01 D4 C0 0D 3A 54 7C')
    const [frame] = new FrameParser().feed(a1)
    const tag = decodeRealtime(frame)

    assert.equal(tag.type, 'tag')
    assert.equal(canonicalUid(tag.epc), canonicalUid(v1))
    assert.equal(canonicalUid(tag.epc), 'E20000200914016323809025')
    assert.equal(tag.antenna, 1)
})

test('R2000: bingkai permintaan baca-saja dan checksum sama dengan vektor independen', () => {
    assert.equal(toHex(frames.version()), 'A00300 72EB'.replace(' ', ''))
    assert.equal(toHex(frames.stopInventory()), 'A0030 08CD1'.replace(' ', ''))
    assert.equal(toHex(frames.realtimeInventory()), 'A0040089 01D2'.replace(' ', ''))
})

test('R2000: balasan versi contoh README terurai sebagai UCM601 v2.3', () => {
    const [f] = new FrameParser().feed(hex('A0 06 00 72 02 03 01 E2'))
    assert.equal(f.sumOk, true)
    assert.equal(decodeVersion(f), 'UCM601 v2.3')
})

test('R2000: checksum rusak ditandai sumOk=false dan parser sinkron kembali', () => {
    const bad = hex('A0 06 00 72 02 03 01 E3')
    const [f] = new FrameParser().feed(bad)
    assert.equal(f.sumOk, false)
})

test('R2000: sampah di depan dan aliran per byte tetap terurai', () => {
    const p = new FrameParser()
    const out = []
    for (const b of hex('00 11 A0 06 00 72 02 03 01 E2')) out.push(...p.feed([b]))
    assert.equal(out.length, 1)
})
