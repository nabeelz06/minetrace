#!/usr/bin/env python3
"""Pembangkit vektor emas UR4 — INDEPENDEN dari kode PHP (dipakai tes agar bug simetris tidak lolos).

Jalankan: python3 tools/uhf/make-golden-vectors.py > tests/Fixtures/uhf/ur4/golden.json
Label asal:  DOK = tertulis persis di dokumen protokol pihak ketiga (chainway-serial docs/protocol.md)
             LIVE = rekaman dari unit hidup yang dicatat dokumen itu
             HITUNG = dihitung dari aturan bingkai di dokumen
             SINTETIS = dibangun untuk menguji parser; BUKAN bukti perilaku perangkat
Tidak satu pun berasal dari perangkat milik proyek ini sebelum Fase 3 (tangkapan fisik).
"""
import json

def frame(cmd, payload=b'', head=b'\xA5\x5A'):
    ln = len(payload) + 8
    body = bytes([ln >> 8, ln & 255, cmd]) + payload
    x = 0
    for b in body:
        x ^= b
    return head + body + bytes([x]) + b'\r\n'

def h(b):
    return b.hex().upper()

def tag(pc, epc, tid=b'', extra=b'', rssi10=-553, ant=1):
    payload = pc + epc + tid + extra + (rssi10 & 0xFFFF).to_bytes(2, 'big') + bytes([ant])
    return frame(0x83, payload)

EPC_A = bytes.fromhex('E20000200914016323809025')
EPC_B = bytes.fromhex('523742303030303539353537')
TID_B = bytes.fromhex('E2801170200041FF40AC0B9F')

out = {'requests': [], 'replies': [], 'tags': [], 'streams': []}

def req(name, label, b, doc=None):
    if doc is not None:
        assert h(b) == doc.replace(' ', '').upper(), (name, h(b))
    out['requests'].append({'name': name, 'label': label, 'hex': h(b)})

req('versi_0x02', 'DOK', frame(0x02), 'A5 5A 00 08 02 0A 0D 0A')
req('daya_0x12', 'HITUNG', frame(0x12))
req('region_0x2E', 'DOK', frame(0x2E), 'A5 5A 00 08 2E 26 0D 0A')
req('mask_antena_0x2A', 'HITUNG', frame(0x2A))
req('sambungan_antena_0x4E', 'HITUNG', frame(0x4E))
req('mode_inventory_0x72', 'HITUNG', frame(0x72, b'\x00\x00'))
req('return_loss_0x26', 'DOK', frame(0x26), 'A5 5A 00 08 26 2E 0D 0A')
req('start_0x82_0000', 'DOK', frame(0x82, b'\x00\x00'), 'A5 5A 00 0A 82 00 00 88 0D 0A')
req('stop_0x8C', 'DOK', frame(0x8C), 'A5 5A 00 08 8C 84 0D 0A')
req('stop_header_C88C', 'HITUNG', frame(0x8C, head=b'\xC8\x8C'))
req('start_2710_klien_npm', 'DOK', frame(0x82, b'\x27\x10'), 'A5 5A 00 0A 82 27 10 BF 0D 0A')
req('tulis_daya_ant1_15dbm_save0', 'HITUNG', frame(0x10, bytes([0, 1, 0x05, 0xDC, 0x05, 0xDC])), 'A5 5A 00 0E 10 00 01 05 DC 05 DC 1F 0D 0A')
req('tulis_daya_save2_DILARANG', 'HITUNG', frame(0x10, bytes([2, 1, 0x05, 0xDC, 0x05, 0xDC])))
req('tulis_region_0x3F_save0', 'HITUNG', frame(0x2C, bytes([0, 0x3F])), 'A5 5A 00 0A 2C 00 3F 19 0D 0A')
req('tulis_mode_inventory_0_save0', 'HITUNG', frame(0x70, bytes([0, 0, 0, 0])))
req('tulis_mode_inventory_1_save0', 'HITUNG', frame(0x70, bytes([0, 1, 0, 0])))
req('tulis_mask_ant1_save0', 'HITUNG', frame(0x28, bytes([0, 0, 1])))

def rep(name, label, b, **expect):
    out['replies'].append({'name': name, 'label': label, 'hex': h(b), 'expect': expect})

rep('versi_7_40_1', 'LIVE', frame(0x03, bytes([7, 40, 1])), cmd=3, payload='07 28 01'.replace(' ', ''))
rep('ack_stop', 'LIVE', frame(0x8D, b'\x01'), cmd=0x8D, payload='01')
rep('return_loss_ant1_16db', 'DOK', frame(0x27, bytes.fromhex('01100200030004 00'.replace(' ', ''))), cmd=0x27)
rep('daya_4_antena_30dbm', 'HITUNG', frame(0x13, b'\x00' + b''.join(bytes([a, 0x0B, 0xB8, 0x0B, 0xB8]) for a in range(1, 5))), cmd=0x13)
rep('region_indonesia_0x3F', 'HITUNG', frame(0x2F, bytes([1, 0x3F])), cmd=0x2F)
rep('mask_ant1', 'HITUNG', frame(0x2B, bytes([0, 1])), cmd=0x2B)
rep('sambungan_ant1', 'HITUNG', frame(0x4F, bytes([0, 1])), cmd=0x4F)
rep('mode_inventory_0_fw7401', 'HITUNG', frame(0x73, bytes([1, 0, 0, 0, 0, 0, 0, 0])), cmd=0x73)
rep('mode_inventory_1', 'HITUNG', frame(0x73, bytes([1, 1, 0, 0, 0, 0, 0, 0])), cmd=0x73)
rep('ack_set_0x11', 'HITUNG', frame(0x11, b'\x01'), cmd=0x11)

def t(name, label, b, **expect):
    out['tags'].append({'name': name, 'label': label, 'hex': h(b), 'expect': expect})

# V1..V5 (Lampiran D). rssi_dbm = nilai sebenarnya; rssi = disimpan (dibulatkan).
t('V1', 'SINTETIS', tag(b'\x30\x00', EPC_A, rssi10=-553, ant=2), result='tag', epc='E20000200914016323809025', tid=None, rssi=-55, antenna=2)
t('V2', 'HITUNG', tag(b'\x34\x00', EPC_B, rssi10=-591, ant=1), result='tag', epc='523742303030303539353537', tid=None, rssi=-59, antenna=1)
t('V3', 'HITUNG', tag(b'\x34\x00', EPC_B, tid=TID_B, rssi10=-591, ant=1), result='tag', epc='523742303030303539353537', tid='E2801170200041FF40AC0B9F', rssi=-59, antenna=1)
t('V4_fase_frekuensi', 'LIVE', tag(b'\x34\x00', EPC_B, tid=TID_B, extra=bytes.fromhex('00510D3A54'), rssi10=-591, ant=1), result='unparseable')
t('V5_epc_16_byte', 'SINTETIS', tag(b'\x40\x00', EPC_A + bytes.fromhex('AABBCCDD'), rssi10=-700, ant=1), result='tag', epc='E20000200914016323809025AABBCCDD', tid=None, rssi=-70, antenna=1)
t('epc_nol_byte', 'SINTETIS', tag(b'\x00\x00', b'', rssi10=-500, ant=1), result='unparseable')
t('rssi_nol_tidak_sah', 'SINTETIS', tag(b'\x30\x00', EPC_A, rssi10=0, ant=1), result='tag', epc='E20000200914016323809025', tid=None, rssi=None, antenna=1)
t('xpc_tidak_didukung', 'SINTETIS', tag(b'\x32\x00', EPC_A, rssi10=-500, ant=1), result='unparseable')
t('epc_terlalu_panjang_26_byte', 'SINTETIS', tag(b'\x68\x00', bytes(range(1, 27)), rssi10=-500, ant=1), result='epc_too_long')

# Aliran: gabungan, rusak, terpotong
v1, v2 = tag(b'\x30\x00', EPC_A, rssi10=-553, ant=2), tag(b'\x34\x00', EPC_B, rssi10=-591, ant=1)
bad = bytearray(v1); bad[-3] ^= 0xFF
out['streams'].append({'name': 'X1_xor_rusak', 'hex': h(bytes(bad)), 'expect': {'tags': 0, 'frames_bad': 1}})
out['streams'].append({'name': 'X2_heartbeat_00_di_antara', 'hex': h(v1 + b'\x00' + v2), 'expect': {'tags': 2, 'frames_bad': 0}})
out['streams'].append({'name': 'F1_satu_byte_per_satu', 'hex': h(v1), 'expect': {'tags': 1, 'chunk': 1}})
out['streams'].append({'name': 'sampah_di_depan', 'hex': h(b'\x01\xA5\x5A\x00\x04\xFF' + v1), 'expect': {'tags': 1}})
out['streams'].append({'name': 'payload_memuat_0D0A', 'hex': h(frame(0x83, b'\x34\x00' + bytes.fromhex('52374230303030350D0A3537') + (-591 & 0xFFFF).to_bytes(2, 'big') + b'\x01')), 'expect': {'frames': 1}})
print(json.dumps(out, indent=2))
