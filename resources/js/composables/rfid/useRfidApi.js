/**
 * useRfidApi — klien endpoint UI RFID (/api/v1/company/rfid/*) + pemetaan `code` → teks i18n.
 * Semua pemanggilan lewat lib/http (CSRF + penanganan 419/401).
 */
import { http } from '@/lib/http'
import { useI18n } from '@/composables/core/useI18n.js'

const BASE = '/api/v1/company/rfid'

export const rfidApi = {
    overview:          ()          => http.get(`${BASE}/overview`),
    readers:           ()          => http.get(`${BASE}/readers`),
    createReader:      body        => http.post(`${BASE}/readers`, body),
    updateReader:      (id, body)  => http.patch(`${BASE}/readers/${id}`, body),
    rotateKey:         id          => http.post(`${BASE}/readers/${id}/rotate-key`),
    searchBatches:     (params, o) => http.get(`${BASE}/batch-search`, { params, ...o }),
    startRegistration: body        => http.post(`${BASE}/registration`, body),
    registrationStatus: batchId    => http.get(`${BASE}/batches/${batchId}/registration`),
    cancelRegistration: sessionId  => http.delete(`${BASE}/registration/${sessionId}`),
    scans:             (params, o) => http.get(`${BASE}/scans`, { params, ...o }),
    scan:              body        => http.post(`${BASE}/scans`, body),
    voidScan:          (id, reason) => http.post(`${BASE}/scans/${id}/void`, { reason }),
    releaseTag:        (batchId, body) => http.post(`${BASE}/batches/${batchId}/release-tag`, body),
    authModes:         ()          => http.get(`${BASE}/authorization-modes`),
    setAuthMode:       body        => http.post(`${BASE}/authorization-modes`, body),
    saveDeviceIp:      ip          => http.post(`${BASE}/device`, { esp32_ip: ip }),
    setLegacyMode:     body        => http.post(`${BASE}/set-mode`, body),
}

/** Teks galat/hasil untuk pengguna: kamus `rfid.err.<code>` → pesan server → pesan umum. */
export function useApiText() {
    const { t } = useI18n()
    return (res) => {
        const key = `rfid.err.${res?.code}`
        const text = t(key)
        if (text !== key) return text
        return res?.message || t('rfid.err.error')
    }
}
