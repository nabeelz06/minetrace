#!/usr/bin/env node
/**
 * contrast.mjs — ukur rasio kontras pasangan token di resources/css/app.css (terang & gelap).
 * Node murni, tanpa dependensi. Gagal (exit 1) bila ada pasangan di bawah ambang WCAG 2.2 AA:
 *   teks ≥ 4.5:1 · komponen UI/border kontrol ≥ 3:1.
 * Pemakaian: node scripts/contrast.mjs [--md]   (--md = keluaran tabel Markdown)
 */
import fs from 'node:fs'

const css = fs.readFileSync(new URL('../resources/css/app.css', import.meta.url), 'utf8')

function block(selector) {
    const start = css.indexOf(selector)
    if (start < 0) throw new Error(`blok ${selector} tidak ditemukan`)
    const open = css.indexOf('{', start)
    const close = css.indexOf('\n}', open)
    return css.slice(open + 1, close)
}

function vars(body) {
    const out = {}
    for (const m of body.matchAll(/--([\w-]+):\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%/g)) {
        out[m[1]] = [parseFloat(m[2]), parseFloat(m[3]), parseFloat(m[4])]
    }
    return out
}

function hslToRgb([h, s, l]) {
    s /= 100; l /= 100
    const k = n => (n + h / 30) % 12
    const a = s * Math.min(l, 1 - l)
    const f = n => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)))
    return [f(0), f(8), f(4)].map(v => v * 255)
}
const lum = hsl => {
    const [r, g, b] = hslToRgb(hsl).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4 })
    return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05) }

const light = vars(block(':root {'))
const dark = { ...light, ...vars(block('html.dark {')) }

// [nama, fg, bg, ambang]
const PAIRS = [
    ['teks utama / latar',            'foreground', 'background', 4.5],
    ['teks utama / kartu',            'card-foreground', 'card', 4.5],
    ['teks redup / latar',            'muted-foreground', 'background', 4.5],
    ['teks redup / kartu',            'muted-foreground', 'card', 4.5],
    ['teks redup / muted',            'muted-foreground', 'muted', 4.5],
    ['teks primer / tombol primer',   'primary-foreground', 'primary', 4.5],
    ['primer (tautan) / latar',       'primary', 'background', 4.5],
    ['primer (tautan) / kartu',       'primary', 'card', 4.5],
    ['teks sekunder / secondary',     'secondary-foreground', 'secondary', 4.5],
    ['teks aksen / accent',           'accent-foreground', 'accent', 4.5],
    ['status sukses / lembut',        'success', 'success-soft', 4.5],
    ['status peringatan / lembut',    'warning', 'warning-soft', 4.5],
    ['status bahaya / lembut',        'danger', 'danger-soft', 4.5],
    ['status info / lembut',          'info', 'info-soft', 4.5],
    ['status netral / lembut',        'neutral', 'neutral-soft', 4.5],
    ['sukses / kartu',                'success', 'card', 4.5],
    ['peringatan / kartu',            'warning', 'card', 4.5],
    ['bahaya / kartu',                'danger', 'card', 4.5],
    ['teks sidebar / sidebar',        'sidebar-foreground', 'sidebar-background', 4.5],
    ['primer sidebar / sidebar',      'sidebar-primary', 'sidebar-background', 3],
    ['batas kontrol (input) / kartu', 'input', 'card', 3],
    ['batas kontrol (input) / latar', 'input', 'background', 3],
    ['cincin fokus / kartu',          'ring', 'card', 3],
    ['cincin fokus / latar',          'ring', 'background', 3],
]

let fail = 0
const rows = []
for (const [name, fg, bg, min] of PAIRS) {
    const r = {}
    for (const [theme, set] of [['terang', light], ['gelap', dark]]) {
        if (!set[fg] || !set[bg]) { r[theme] = null; fail++; console.error(`token hilang: ${fg}/${bg} (${theme})`); continue }
        const v = ratio(set[fg], set[bg])
        r[theme] = v
        if (v < min) { fail++; console.error(`GAGAL ${theme}: ${name} = ${v.toFixed(2)} < ${min}`) }
    }
    rows.push({ name, fg, bg, min, ...r })
}

if (process.argv.includes('--md')) {
    console.log('| Pasangan | Token | Ambang | Terang | Gelap |\n|---|---|---|---|---|')
    for (const r of rows) console.log(`| ${r.name} | \`${r.fg}\` / \`${r.bg}\` | ${r.min}:1 | ${r.terang?.toFixed(2)} | ${r.gelap?.toFixed(2)} |`)
} else {
    for (const r of rows) console.log(`${r.name.padEnd(32)} ${String(r.min).padStart(3)}:1  terang ${r.terang?.toFixed(2).padStart(5)}  gelap ${r.gelap?.toFixed(2).padStart(5)}`)
}
console.log(fail ? `\n${fail} pasangan GAGAL` : '\nSemua pasangan lulus WCAG 2.2 AA')
process.exit(fail ? 1 : 0)
