#!/usr/bin/env python3
"""HW-VX6336 (protokol Chafon/RM09, 57600 8N1): poll inventory EPC G2 tiap 300 ms, cetak UID.

Bingkai: Len | Adr | Cmd | Status | Data... | CRC16 (LSB,MSB); Len = jumlah byte setelah Len (termasuk CRC).
Inventory (cmd 0x01): Data = Num, lalu Num x [EPClen, EPC...]. CRC16: poly 0x8408, awal 0xFFFF.

  python3 tools/uhf/chafon-poll.py [--port /dev/ttyUSB0] [--baud 57600] [--seconds 0] [--raw]
"""
import argparse, sys, time

POLL = bytes([0x04, 0xFF, 0x01, 0x1B, 0xB4])

def crc16(data: bytes) -> int:
    c = 0xFFFF
    for b in data:
        c ^= b
        for _ in range(8):
            c = (c >> 1) ^ 0x8408 if c & 1 else c >> 1
    return c

def parse_inventory(frame: bytes):
    """frame = seluruh bingkai (Len di indeks 0). Kembalikan (status, [epc_hex]) atau None bila CRC/format salah."""
    if len(frame) < 6 or frame[0] + 1 != len(frame):
        return None
    if crc16(frame[:-2]) != frame[-2] | (frame[-1] << 8):
        return None
    if frame[2] != 0x01:
        return None
    status, epcs = frame[3], []
    if len(frame) >= 7:
        num, i = frame[4], 5
        for _ in range(num):
            if i >= len(frame) - 2:
                return None
            n = frame[i]
            epc = frame[i + 1:i + 1 + n]
            if len(epc) != n or i + 1 + n > len(frame) - 2:
                return None
            epcs.append(epc.hex().upper())
            i += 1 + n
    return status, epcs

def read_frame(s, timeout=0.25):
    end = time.time() + timeout
    head = b''
    while not head and time.time() < end:
        head = s.read(1)
    if not head:
        return None
    rest = b''
    while len(rest) < head[0] and time.time() < end + 0.2:
        rest += s.read(head[0] - len(rest))
    return head + rest

def main():
    import serial
    a = argparse.ArgumentParser()
    a.add_argument('--port', default='/dev/ttyUSB0')
    a.add_argument('--baud', type=int, default=57600)
    a.add_argument('--seconds', type=float, default=0, help='0 = sampai Ctrl+C')
    a.add_argument('--raw', action='store_true', help='cetak juga bingkai mentah')
    a = a.parse_args()
    s = serial.Serial(a.port, a.baud, timeout=0.05)
    s.reset_input_buffer()
    print(f'== {a.port} {a.baud} 8N1, poll 300 ms. Tempel kartu... (Ctrl+C berhenti)', flush=True)
    seen, end, tx, rx = set(), (time.time() + a.seconds if a.seconds else None), 0, 0
    try:
        while end is None or time.time() < end:
            t0 = time.time()
            s.write(POLL); tx += 1
            f = read_frame(s)
            if f:
                rx += 1
                if a.raw:
                    print('RX', f.hex(' ').upper(), flush=True)
                r = parse_inventory(f)
                if r is None:
                    print('! bingkai tidak valid (CRC/format):', f.hex(' ').upper(), flush=True)
                elif r[1]:
                    print(f'{time.strftime("%H:%M:%S")} tag={len(r[1])} status=0x{r[0]:02X}  ' + ' '.join(r[1]), flush=True)
                    seen.update(r[1])
            time.sleep(max(0, 0.3 - (time.time() - t0)))
    except KeyboardInterrupt:
        pass
    print(f'-- TX {tx}, balasan {rx}, UID unik: {len(seen)}', flush=True)

if __name__ == '__main__':
    main()
