#!/usr/bin/env python3
"""Probe serial AKTIF (master): kirim perintah QUERY/baca ke port pada banyak baud dan cetak setiap balasan.
Hanya perintah baca (versi/inventory sekali); tidak ada perintah tulis-konfigurasi. Hentikan dengan Ctrl+C.

  python3 tools/uhf/serial-master-probe.py                 # semua baud x semua kandidat
  python3 tools/uhf/serial-master-probe.py --bauds 115200 --poll 30   # setelah ketemu: poll inventory 30 dtk (tempel kartu)
"""
import argparse, sys, time
import serial

def r2000(addr, cmd, data=b''):
    f = bytes([0xA0, 3 + len(data), addr, cmd]) + data
    return f + bytes([(-sum(f)) & 0xFF])

def ur4(cmd, payload=b''):
    body = (8 + len(payload)).to_bytes(2, 'big') + bytes([cmd]) + payload
    x = 0
    for b in body: x ^= b
    return b'\xA5\x5A' + body + bytes([x]) + b'\r\n'

CANDS = {
    'R2000 versi addr=FF':        r2000(0xFF, 0x72),
    'R2000 versi addr=01':        r2000(0x01, 0x72),
    'R2000 versi addr=00':        r2000(0x00, 0x72),
    'R2000 realtime inv addr=FF': r2000(0xFF, 0x89, b'\x01'),
    'R2000 realtime inv addr=01': r2000(0x01, 0x89, b'\x01'),
    'M100/YRM100 hw-versi':       bytes.fromhex('BB00030001000 47E'.replace(' ', '')),
    'M100/YRM100 single-poll':    bytes.fromhex('BB00220000227E'),
    'UR4 stop':                   ur4(0x8C),
    'ASCII CRLF':                 b'\r\n',
    'ASCII VER':                  b'VER\r\n',
}

a_ = argparse.ArgumentParser()
a_.add_argument('--port', default='/dev/ttyUSB0')
a_.add_argument('--bauds', default='9600,19200,38400,57600,115200')
a_.add_argument('--wait', type=float, default=0.5)
a_.add_argument('--poll', type=float, default=0, help='setelah itu: kirim terus kandidat pertama yang dijawab, N dtk')
a = a_.parse_args()

hits = []
try:
    for baud in map(int, a.bauds.split(',')):
        for rts in (True, False):
            s = serial.Serial(a.port, baud, timeout=0.05)
            s.rts, s.dtr = rts, False
            time.sleep(0.1); s.reset_input_buffer()
            for name, frame in CANDS.items():
                s.write(frame); s.flush()
                end, buf = time.time() + a.wait, b''
                while time.time() < end:
                    buf += s.read(256)
                tag = f'{baud} rts={int(rts)} | {name}'
                if buf:
                    asc = ''.join(chr(c) if 32 <= c < 127 else '.' for c in buf)
                    print(f'** JAWABAN {tag}\n   TX {frame.hex(" ").upper()}\n   RX {buf.hex(" ").upper()} |{asc}|', flush=True)
                    hits.append((baud, rts, name, frame))
                else:
                    print(f'   sunyi   {tag}', flush=True)
            s.close()
    print(f'\n== selesai: {len(hits)} jawaban' + ('' if hits else ' — perangkat tidak menjawab kandidat mana pun'))
    if hits and a.poll:
        baud, rts, name, frame = hits[0]
        s = serial.Serial(a.port, baud, timeout=0.05); s.rts = rts
        print(f'== poll {a.poll:.0f} dtk memakai "{name}" @ {baud}. Tempel kartu...')
        end = time.time() + a.poll
        while time.time() < end:
            s.write(frame); time.sleep(0.15)
            d = s.read(512)
            if d: print(time.strftime('%H:%M:%S'), 'RX', d.hex(' ').upper(), flush=True)
except KeyboardInterrupt:
    pass
except serial.SerialException as e:
    sys.exit(f'Gagal buka port: {e}')
