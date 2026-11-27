import test from 'node:test'
import assert from 'node:assert/strict'
import { diffTone, parseWeight, weightDiff } from '../weight.js'

test('parseWeight: koma desimal, format Indonesia, dan keypad', () => {
    assert.equal(parseWeight('1250,5'), 1250.5)
    assert.equal(parseWeight('1.250,5'), 1250.5)
    assert.equal(parseWeight('1,250.5'), 1250.5)
    assert.equal(parseWeight('1250.5'), 1250.5)
    assert.equal(parseWeight(' 980 '), 980)
    assert.equal(parseWeight('1.250.000'), 1250000)
    assert.equal(parseWeight('1,250,000'), 1250000)
})

test('parseWeight: bukan angka → null', () => {
    assert.equal(parseWeight(''), null)
    assert.equal(parseWeight(null), null)
    assert.equal(parseWeight(undefined), null)
    assert.equal(parseWeight('abc'), null)
    assert.equal(parseWeight('12kg'), null)
    assert.equal(parseWeight('-5'), null)
    assert.equal(parseWeight('1.2.3,4,5'), null)
})

test('weightDiff: selisih dan persen terhadap berat tercatat', () => {
    assert.deepEqual(weightDiff(1000, 980), { diff: -20, pct: -2 })
    assert.deepEqual(weightDiff(1000, 1000), { diff: 0, pct: 0 })
    assert.deepEqual(weightDiff(0, 50), { diff: 50, pct: 0 })
    assert.deepEqual(weightDiff('x', 5), { diff: 0, pct: 0 })
})

test('diffTone: sama / kecil / besar', () => {
    assert.equal(diffTone(0, 0), 'same')
    assert.equal(diffTone(-20, -2), 'small')
    assert.equal(diffTone(-150, -15), 'large')
    assert.equal(diffTone(150, 15), 'large')
})
