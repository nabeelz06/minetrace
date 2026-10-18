#!/usr/bin/env python3
"""Probe serial pasif: dengarkan port, cetak byte mentah (hex + ASCII) dengan cap waktu.
Tidak mengirim apa pun kecuali --send. Tujuan: mengenali format data HW-VX6336.

  python3 tools/uhf/serial-probe.py                       # 9600 8N1, 60 dtk
  python3 tools/uhf/serial-probe.py --baud 115200 --rts
  python3 tools/uhf/serial-probe.py --scan                # coba baud umum, masing-masing 20 dtk
  python3 tools/uhf/serial-probe.py --send "A0 04 01 89 01 D1"   # kirim hex (sadar risiko)
"""
import argparse, sys, time
import serial

p = argparse.ArgumentParser()
p.add_argument('--port', default='/dev/ttyUSB0')
p.add_argument('--baud', type=int, default=9600)
p.add_argument('--seconds', type=float, default=60)
p.add_argument('--scan', action='store_true')
p.add_argument('--rts', action='store_true', help='RTS tinggi (R2000 wajib)')
p.add_argument('--dtr', action='store_true')
p.add_argument('--send', help='hex yang dikirim sekali setelah buka port')
a = p.parse_args()

def listen(baud, secs):
    s = serial.Serial(a.port, baud, timeout=0.1)
    s.rts, s.dtr = a.rts, a.dtr
    s.reset_input_buffer()
    print(f'== {a.port} {baud} 8N1 rts={a.rts} dtr={a.dtr}, {secs:.0f} dtk. Tempelkan kartu...', flush=True)
    if a.send:
        b = bytes.fromhex(a.send.replace(' ', ''))
        s.write(b); print(f'TX {b.hex(" ").upper()}', flush=True)
    end, total, buf, last = time.time() + secs, 0, b'', 0
    while time.time() < end:
        d = s.read(256)
        if d:
            buf += d; last = time.time()
        elif buf and time.time() - last > 0.05:      # jeda 50 ms = akhir paket
            asc = ''.join(chr(c) if 32 <= c < 127 else '.' for c in buf)
            print(f'{time.strftime("%H:%M:%S")} RX {len(buf):3d}B  {buf.hex(" ").upper()}  |{asc}|', flush=True)
            total += len(buf); buf = b''
    s.close()
    print(f'-- total {total} byte', flush=True)
    return total

try:
    if a.scan:
        for baud in (9600, 19200, 38400, 57600, 115200):
            if listen(baud, 20): break
    else:
        listen(a.baud, a.seconds)
except KeyboardInterrupt:
    pass
except serial.SerialException as e:
    sys.exit(f'Gagal buka port: {e}')
