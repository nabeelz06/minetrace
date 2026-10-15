#!/usr/bin/env python3
"""ui-codemod — migrasi halaman lama ke token desain MINETRACE (idempoten; dry-run bila tanpa --apply).

Transformasi (urut):
  0. emoji data (string tunggal) → nama ikon lucide; interpolasi {{ x.icon }} → <AppIcon>; impor otomatis
  1. text-[Npx] → skala token (xs/sm/base/lg/xl/2xl)
  2. gradien inline → warna datar token; hex/rgba → hsl(var(--token))
  3. kelas palet mentah (emerald/red/amber/blue/…-NNN) → token semantik; varian `dark:` palet dibuang (token adaptif)
  4. `text-white` pada elemen ber-bg primer/solid-token → teks kontras (primary-foreground / background)
  5. outline-none dibuang (fokus terlihat global dipertahankan)
  6. emoji dekoratif tersisa dihapus
Pemakaian: python3 scripts/ui-codemod.py [--apply] [berkas…]   (default: semua halaman lama)
"""
import re, sys, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent / 'resources/js'
SKIP_DIRS = {'ui', 'app', 'rfid'}
SKIP_FILES = {  # sudah ditulis ulang pada desain baru
    'Pages/Auth/Login.vue', 'Pages/Auth/ForgotPassword.vue', 'Pages/Auth/ResetPassword.vue',
    'Pages/Public/VerifyBatch.vue', 'Pages/Company/Rfid/Index.vue',
    'Layouts/AppLayout.vue', 'Layouts/AuthLayout.vue', 'Layouts/PublicLayout.vue',
    'components/shared/StatusBadge.vue', 'components/shared/EmptyState.vue', 'components/shared/BrandLogo.vue',
}

# ── 0. emoji → ikon ──────────────────────────────────────────────────────────
EMOJI_ICON = {
    '🧪': 'flask-conical', '📥': 'package-open', '💧': 'droplets', '🔥': 'flame', '⚗': 'microscope', '🔬': 'microscope',
    '🏭': 'warehouse', '📦': 'package', '🚢': 'ship', '📄': 'file-text', '📋': 'clipboard-list', '📊': 'gauge',
    '⚡': 'zap', '⚖': 'scale', '🌍': 'globe', '⛏': 'pickaxe', '🪨': 'mountain', '💎': 'gem', '☢': 'radiation',
    '🔒': 'lock', '📷': 'camera', '📸': 'camera', '📹': 'video', '📡': 'radio', '🏢': 'building-2', '📍': 'map-pin',
    '👤': 'user', '🎮': 'sliders-horizontal', '💡': 'lightbulb', '🔩': 'wrench', '👁': 'eye', '🗂': 'layers',
    '⚙': 'settings', '🧬': 'dna', '🔗': 'link', '🚫': 'ban', '🗑': 'trash-2', '🔍': 'search', '🔄': 'refresh-cw',
    '📤': 'arrow-up-from-line', '✅': 'circle-check', '❌': 'circle-x', '⚠': 'triangle-alert', 'ℹ': 'info',
    '🔴': 'circle', '🟡': 'circle', '🔵': 'circle',
}
_EM = '|'.join(re.escape(k) for k in EMOJI_ICON)
EMOJI_STR = re.compile('([\'"`])(' + _EM + ')️?\\1')
SIZE_OF = {'text-xs': 14, 'text-sm': 16, 'text-base': 18, 'text-lg': 20, 'text-xl': 22, 'text-2xl': 28, 'text-3xl': 32}
ICON_INTERP = re.compile(
    r'<(span|div)\b([^>]*)>\s*\{\{\s*([^{}]*?(?:\.icon|\.icon_name|stageIcon\([^()]*\)|stageBadge\([^()]*\)\.icon))\s*\}\}\s*</\1>')


def icon_size(attrs):
    m = re.search(r'text-(?:xs|sm|base|lg|xl|2xl|3xl)', attrs)
    if m:
        return SIZE_OF[m.group(0)]
    f = re.search(r'font-size:\s*(\d+)px', attrs)
    return int(round(int(f.group(1)) * 1.1)) if f else 18


def repl_icon_interp(m):
    return f'<AppIcon :name="{m.group(3)} || \'circle\'" :size="{icon_size(m.group(2))}" />'


def ensure_import(src):
    if '<AppIcon' in src and 'components/app/AppIcon.vue' not in src:
        return re.sub(r'(<script setup[^>]*>\n)', r"\1import AppIcon from '@/components/app/AppIcon.vue'\n", src, count=1)
    return src


# ── 1. ukuran font ───────────────────────────────────────────────────────────
def font_token(px):
    px = float(px)
    return ('xs' if px <= 12.5 else 'sm' if px <= 14 else 'base' if px <= 16 else 'lg' if px <= 18
            else 'xl' if px <= 20 else '2xl' if px <= 24 else '3xl')


FONT = re.compile(r'text-\[([0-9.]+)px\]')

# ── 2. warna inline ──────────────────────────────────────────────────────────
HUE = {
    'success': ['#059669', '#10b981', '#34d399', '#2dd4bf', '#0d9488', '5,150,105', '16,185,129', '20,184,166'],
    'warning': ['#f59e0b', '#d97706', '#fbbf24', '#ea580c', '245,158,11', '249,115,22', '217,119,6'],
    'danger':  ['#ef4444', '#dc2626', '#f87171', '239,68,68', '220,38,38'],
    'info':    ['#3b82f6', '#2563eb', '#60a5fa', '#9333ea', '#7c3aed', '#8b5cf6', '#a78bfa', '#5b21b6', '#db2777',
                '#22d3ee', '#c084fc', '37,99,235', '59,130,246', '168,85,247', '124,58,237', '139,92,246', '6,182,212'],
    'neutral': ['#6b7280', '#9ca3af', '#4b5563', '107,114,128'],
    'primary': ['#3e5c74', '#5ea1c2', '#7cb4d4', '62,92,116'],
}
TOKEN_OF = {k.lower(): t for t, ks in HUE.items() for k in ks}


def tone_color(token, alpha=None):
    if alpha is None:
        return f'hsl(var(--{token}))'
    a = float(alpha)
    if token != 'primary' and a <= 0.2:
        return f'hsl(var(--{token}-soft))'
    return f'hsl(var(--{token})/{alpha})'


HEX = re.compile(r'#[0-9a-fA-F]{6}\b')
RGBA = re.compile(r'rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([0-9.]+)\s*)?\)')
GRAD = re.compile(r'linear-gradient\((?:[^()]|\([^()]*\))*\)')


def repl_rgba(m):
    r, g, b, a = m.groups()
    key = f'{r},{g},{b}'
    if key == '0,0,0':
        return f'hsl(var(--foreground)/{a})' if a else 'hsl(var(--foreground))'
    if key == '255,255,255':
        return m.group(0)
    tok = TOKEN_OF.get(key)
    return tone_color(tok, a) if tok else m.group(0)


def repl_grad(m):
    g = m.group(0)
    first = re.search(r'#[0-9a-fA-F]{6}|rgba?\([^)]*\)|hsl\(var\(--primary\)\)', g)
    if not first:
        return g
    c = first.group(0)
    if c.startswith('hsl(var(--primary))'):
        return 'hsl(var(--primary))'
    if c.startswith('#'):
        tok = TOKEN_OF.get(c.lower())
        return tone_color(tok) if tok else g
    mm = RGBA.match(c)
    return repl_rgba(mm) if mm else g


# ── 3. kelas palet mentah ────────────────────────────────────────────────────
FAMILY = {'emerald': 'success', 'green': 'success', 'lime': 'success', 'teal': 'success',
          'red': 'danger', 'rose': 'danger', 'amber': 'warning', 'yellow': 'warning', 'orange': 'warning',
          'blue': 'info', 'sky': 'info', 'cyan': 'info', 'indigo': 'info', 'violet': 'info', 'purple': 'info',
          'fuchsia': 'info', 'pink': 'info',
          'slate': 'neutral', 'gray': 'neutral', 'zinc': 'neutral', 'neutral': 'neutral', 'stone': 'neutral'}
FAM = '|'.join(FAMILY)
UTIL = r'(?:text|bg|border|ring|divide|outline|placeholder|fill|stroke|from|to|via)'
CLS = re.compile(rf'(?P<pre>(?:[a-z-]+:)*)(?P<util>{UTIL})-(?P<fam>{FAM})-(?P<n>\d{{2,3}})(?:/(?P<a>\d+))?(?![\w-])')


def repl_class(m):
    pre, util, fam, n, a = m.group('pre'), m.group('util'), m.group('fam'), int(m.group('n')), m.group('a')
    if 'dark:' in pre:
        return ''                         # token sudah adaptif terhadap tema gelap
    tone = FAMILY[fam]
    if util in ('from', 'to', 'via'):
        return ''                         # gradien Tailwind dibuang
    if util in ('text', 'fill', 'stroke', 'placeholder'):
        return f'{pre}{util}-' + ('muted-foreground' if tone == 'neutral' else tone)
    if util == 'bg':
        soft = n <= 200 or (a is not None and int(a) <= 25)
        if tone == 'neutral':
            return f'{pre}bg-muted' if soft else f'{pre}bg-muted-foreground'
        return f'{pre}bg-{tone}-soft' if soft else f'{pre}bg-{tone}'
    if util in ('border', 'divide', 'outline'):
        return f'{pre}{util}-border' if tone == 'neutral' else f'{pre}{util}-{tone}/30'
    if util == 'ring':
        return f'{pre}ring-{tone}/30' if tone != 'neutral' else f'{pre}ring-border'
    return m.group(0)


EMOJI = re.compile('[\U0001F300-\U0001FAFF☀-➿⭐⬆✅❌ℹ⚠️]\\s?')
GRADCLS = re.compile(r'\bbg-gradient-to-[a-z]+\b')


def transform(src):
    src = EMOJI_STR.sub(lambda m: "'" + EMOJI_ICON[m.group(2)] + "'", src)
    src = ICON_INTERP.sub(repl_icon_interp, src)
    src = re.sub(r"\$\{stageIcon\([^()]*\)\}\s?", '', src)
    src = src.replace(':icon="stage_config?.icon ?? \'\'"', ':icon="stage_config?.icon_name ?? \'package\'"')
    s = FONT.sub(lambda m: f'text-{font_token(m.group(1))}', src)
    s = GRAD.sub(repl_grad, s)
    s = RGBA.sub(repl_rgba, s)
    s = HEX.sub(lambda m: tone_color(TOKEN_OF[m.group(0).lower()]) if m.group(0).lower() in TOKEN_OF else m.group(0), s)
    s = CLS.sub(repl_class, s)
    s = GRADCLS.sub('bg-primary', s)
    out = []
    for line in s.split('\n'):
        if 'text-white' in line and ('bg-primary' in line or 'hsl(var(--primary))' in line):
            line = line.replace('text-white', 'text-primary-foreground')
        elif 'text-white' in line and re.search(r'bg-(?:success|warning|danger|info)(?![\w-])', line):
            line = line.replace('text-white', 'text-background')
        out.append(line)
    s = '\n'.join(out)
    s = re.sub(r'(?<![\w-])(?:focus:|focus-visible:)?outline-none(?![\w-])', '', s)
    s = EMOJI.sub('', s)
    s = ensure_import(s)
    s = re.sub(r'class="([^"]*)"', lambda m: 'class="' + re.sub(r'\s{2,}', ' ', m.group(1)).strip() + '"', s)
    return s


def files(args):
    if args:
        return [pathlib.Path(a) for a in args]
    out = []
    for p in ROOT.rglob('*.vue'):
        rel = p.relative_to(ROOT).as_posix()
        if rel in SKIP_FILES or any(part in SKIP_DIRS for part in p.relative_to(ROOT).parts[:-1]):
            continue
        out.append(p)
    return sorted(out)


if __name__ == '__main__':
    apply = '--apply' in sys.argv
    paths = files([a for a in sys.argv[1:] if not a.startswith('--')])
    changed = 0
    for p in paths:
        src = p.read_text(encoding='utf-8')
        new = transform(src)
        if new != src:
            changed += 1
            if apply:
                p.write_text(new, encoding='utf-8')
            print(('ubah ' if apply else 'akan ubah ') + p.relative_to(ROOT).as_posix())
    print(f'-- {changed} berkas {"diubah" if apply else "akan diubah"} dari {len(paths)}')
