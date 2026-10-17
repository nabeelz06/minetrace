// Uji beban HTTP jalur scan RFID (staging, BUKAN produksi).
//   k6 run -e BASE=https://staging.example -e KEY=mtr_xxx tests/load/rfid-scan.k6.js
// Pakai reader uji; UID acak tidak terdaftar => respons 422 "tag tidak dikenal" tetap mengukur jalur penuh.
import http from 'k6/http'
import { check } from 'k6'

export const options = {
  scenarios: { scans: { executor: 'constant-arrival-rate', rate: 20, timeUnit: '1s', duration: '1m', preAllocatedVUs: 20 } },
  thresholds: { http_req_duration: ['p(95)<500'], 'http_req_failed{expected_response:true}': ['rate<0.01'] },
}

export default function () {
  const uid = ('0' + Math.floor(Math.random() * 0xffffffff).toString(16)).slice(-8).toUpperCase()
  const res = http.post(`${__ENV.BASE}/api/rfid/login`, JSON.stringify({ uid, event_id: `k6-${__VU}-${__ITER}-${Date.now()}` }), {
    headers: { 'Content-Type': 'application/json', 'X-Device-Key': __ENV.KEY },
  })
  check(res, { 'dijawab server (200/422)': (r) => r.status === 200 || r.status === 422 })
}
