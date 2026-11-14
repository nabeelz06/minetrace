import test from 'node:test'
import assert from 'node:assert/strict'
import { activeReadersAt, laneStatus, modeMoves, readerDirections } from '../rfidFlow.js'

const reader = (over = {}) => ({ id: 1, code: 'WET-IN', stage: 'wet_process', direction: 'in', directions: ['in'], is_active: true, state: 'online', ...over })

test('modeMoves: hanya mode otomatis yang memindahkan batch, per arah', () => {
    assert.equal(modeMoves('audit_only', 'in'), false)
    assert.equal(modeMoves('audit_only', 'out'), false)
    assert.equal(modeMoves('scan_to_receive', 'in'), true)
    assert.equal(modeMoves('scan_to_receive', 'out'), false)
    assert.equal(modeMoves('scan_to_dispatch', 'in'), false)
    assert.equal(modeMoves('scan_to_dispatch', 'out'), true)
    assert.equal(modeMoves('scan_full', 'in'), true)
    assert.equal(modeMoves('scan_full', 'out'), true)
    assert.equal(modeMoves(undefined, 'in'), false)
})

test('readerDirections: memakai arah dari server; cadangan untuk API lama', () => {
    assert.deepEqual(readerDirections({ direction: 'any', directions: ['in'] }), ['in'], 'server menang (UR4 dua arah tanpa antena = Masuk saja)')
    assert.deepEqual(readerDirections({ direction: 'any' }), ['in', 'out'])
    assert.deepEqual(readerDirections({ direction: 'out' }), ['out'])
    assert.deepEqual(readerDirections({}), [])
    assert.deepEqual(readerDirections(null), [])
})

test('activeReadersAt: hanya reader aktif di tahap itu', () => {
    const list = [reader(), reader({ id: 2, is_active: false }), reader({ id: 3, stage: 'dry_process' })]
    assert.deepEqual(activeReadersAt(list, 'wet_process').map(r => r.id), [1])
    assert.deepEqual(activeReadersAt(undefined, 'wet_process'), [])
})

test('laneStatus: none → audit → offline → ready', () => {
    const here = [reader()]
    // reader "Scan Masuk saja": Scan Keluar tidak pernah terbaca, apa pun modenya
    assert.equal(laneStatus(here, 'scan_full', 'out').status, 'none')
    // mode bawaan: terbaca tetapi hanya dicatat
    assert.equal(laneStatus(here, 'audit_only', 'in').status, 'audit')
    // mode terima otomatis tetapi reader belum online
    assert.equal(laneStatus([reader({ state: 'offline' })], 'scan_to_receive', 'in').status, 'offline')
    assert.equal(laneStatus([reader({ state: 'never' })], 'scan_to_receive', 'in').status, 'offline')
    // siap
    const ready = laneStatus(here, 'scan_to_receive', 'in')
    assert.equal(ready.status, 'ready')
    assert.deepEqual(ready.readers.map(r => r.code), ['WET-IN'])
})

test('laneStatus: reader dua arah berantena memberi dua jalur siap; satu reader online cukup', () => {
    const two = reader({ code: 'WET-2WAY', direction: 'any', directions: ['in', 'out'] })
    assert.equal(laneStatus([two], 'scan_full', 'in').status, 'ready')
    assert.equal(laneStatus([two], 'scan_full', 'out').status, 'ready')
    assert.equal(laneStatus([two], 'scan_to_receive', 'out').status, 'audit')
    assert.equal(laneStatus([two, reader({ id: 9, state: 'offline' })], 'scan_to_receive', 'in').status, 'ready')
})

test('laneStatus: tanpa reader sama sekali', () => {
    assert.equal(laneStatus([], 'scan_full', 'in').status, 'none')
    assert.equal(laneStatus(undefined, 'scan_full', 'in').status, 'none')
})
