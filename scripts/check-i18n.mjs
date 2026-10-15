#!/usr/bin/env node
/**
 * check-i18n.mjs — pastikan kamus ID & EN sepadan (Node murni, tanpa dependensi).
 *  - GAGAL (exit 1) bila ada kunci yang hanya ada di satu bahasa.
 *  - GAGAL bila ada nilai kosong.
 *  - Melaporkan (tidak gagal) sisa ternary inline `lang === 'en' ? … : …` per berkas, dan kunci
 *    yang dirujuk lewat t('literal') tetapi tidak ada di kamus.
 * Pemakaian: node scripts/check-i18n.mjs [--ternary]
 */
import fs from 'node:fs'
import path from 'node:path'
import { pathToFileURL } from 'node:url'

const root = new URL('..', import.meta.url).pathname
const id = (await import(pathToFileURL(path.join(root, 'resources/js/lang/id.js')))).default
const en = (await import(pathToFileURL(path.join(root, 'resources/js/lang/en.js')))).default

function flatten(obj, prefix = '', out = {}) {
    for (const [k, v] of Object.entries(obj)) {
        const key = prefix ? `${prefix}.${k}` : k
        if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out)
        else out[key] = v
    }
    return out
}

const fid = flatten(id), fen = flatten(en)
const onlyId = Object.keys(fid).filter(k => !(k in fen))
const onlyEn = Object.keys(fen).filter(k => !(k in fid))
const empty = [...Object.entries(fid), ...Object.entries(fen)].filter(([, v]) => typeof v === 'string' && v.trim() === '').map(([k]) => k)

let failed = false
if (onlyId.length) { failed = true; console.error(`Hanya di ID (${onlyId.length}):\n  ${onlyId.join('\n  ')}`) }
if (onlyEn.length) { failed = true; console.error(`Hanya di EN (${onlyEn.length}):\n  ${onlyEn.join('\n  ')}`) }
if (empty.length) { failed = true; console.error(`Nilai kosong (${empty.length}):\n  ${[...new Set(empty)].join('\n  ')}`) }

// Pemakaian t('literal') yang tak ada di kamus + hitungan ternary inline
const walk = dir => fs.readdirSync(dir, { withFileTypes: true }).flatMap(e =>
    e.isDirectory() ? (e.name === 'ui' || e.name === 'lang' ? [] : walk(path.join(dir, e.name))) : [path.join(dir, e.name)])
const files = walk(path.join(root, 'resources/js')).filter(f => /\.(vue|js)$/.test(f))

const missing = new Set()
const ternary = {}
for (const f of files) {
    const src = fs.readFileSync(f, 'utf8')
    for (const m of src.matchAll(/\bt\(\s*(['"`])([a-z0-9_]+(?:\.[a-z0-9_]+)+)\1/gi)) {
        if (!(m[2] in fid)) missing.add(`${m[2]}  (${path.relative(root, f)})`)
    }
    const n = (src.match(/lang(?:\.value)?\s*===?\s*['"](?:en|id)['"]/g) || []).length
    if (n) ternary[path.relative(root, f)] = n
}
if (missing.size) { failed = true; console.error(`Kunci dirujuk tapi tidak ada di kamus (${missing.size}):\n  ${[...missing].join('\n  ')}`) }

const totalTernary = Object.values(ternary).reduce((a, b) => a + b, 0)
console.log(`Kunci: ID=${Object.keys(fid).length} EN=${Object.keys(fen).length} · ternary inline tersisa: ${totalTernary} di ${Object.keys(ternary).length} berkas`)
if (process.argv.includes('--ternary')) {
    for (const [f, n] of Object.entries(ternary).sort((a, b) => b[1] - a[1])) console.log(`  ${String(n).padStart(4)}  ${f}`)
}
console.log(failed ? 'GAGAL' : 'OK — kamus ID/EN sepadan')
process.exit(failed ? 1 : 0)
