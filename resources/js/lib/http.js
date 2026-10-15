/**
 * http.js — klien HTTP tunggal untuk endpoint JSON bersesi (/api/v1/company/*).
 *
 * - Mengirim X-XSRF-TOKEN dari cookie (CSRF aktif untuk rute sesi) + Accept JSON.
 * - 419 (token CSRF kedaluwarsa) → ambil cookie baru sekali lalu coba ulang.
 * - 401 → sesi habis: arahkan ke /login (tidak membiarkan UI diam).
 * - Hasil selalu { ok, status, data, code, message, message_en, errors } — UI memetakan `code` ke kamus i18n.
 */

function xsrfToken() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
}

async function refreshCsrf() {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
}

function buildQuery(params) {
    const q = new URLSearchParams()
    Object.entries(params || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.set(k, v) })
    const s = q.toString()
    return s ? `?${s}` : ''
}

export async function request(method, url, { body, params, signal, retry = true } = {}) {
    const isRead = method === 'GET'
    const res = await fetch(url + buildQuery(params), {
        method,
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            ...(isRead ? {} : { 'X-XSRF-TOKEN': xsrfToken() }),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    }).catch(err => {
        if (err.name === 'AbortError') throw err
        return null   // jaringan putus / server tak terjangkau
    })

    if (!res) return { ok: false, status: 0, code: 'network_error', data: null, message: 'Tidak dapat terhubung ke server.', message_en: 'Cannot reach the server.' }

    if (res.status === 419 && retry && !isRead) {
        await refreshCsrf()
        return request(method, url, { body, params, signal, retry: false })
    }
    if (res.status === 401) {
        window.location.assign('/login')
        return { ok: false, status: 401, code: 'unauthenticated', data: null }
    }

    let json = null
    try { json = await res.json() } catch { /* bukan JSON */ }

    return {
        ok: res.ok && json?.success !== false,
        status: res.status,
        code: json?.code ?? (res.ok ? 'ok' : res.status === 419 ? 'csrf_expired' : res.status === 429 ? 'rate_limited' : 'error'),
        data: json?.data ?? null,
        message: json?.message ?? null,
        message_en: json?.message_en ?? null,
        errors: json?.errors ?? null,
        raw: json,
    }
}

export const http = {
    get:    (url, opts) => request('GET', url, opts),
    post:   (url, body, opts) => request('POST', url, { ...opts, body: body ?? {} }),
    patch:  (url, body, opts) => request('PATCH', url, { ...opts, body: body ?? {} }),
    delete: (url, opts) => request('DELETE', url, opts),
}
