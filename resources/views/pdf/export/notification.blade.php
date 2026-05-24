<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.6; }
  .header { padding: 24px 30px; margin-bottom: 16px; border-bottom: 3px solid #CC0000; display: flex; justify-content: space-between; align-items: center; }
  .bim-label { font-size: 9px; font-weight: bold; color: #CC0000; text-transform: uppercase; letter-spacing: 1px; }
  .doc-title { font-size: 16px; font-weight: bold; color: #1a1a1a; margin-top: 2px; }
  .section { padding: 0 30px; margin-bottom: 16px; }
  .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #CC0000; border-bottom: 1px solid #CC0000; padding-bottom: 3px; margin-bottom: 10px; }
  .info-row { margin-bottom: 4px; display: flex; gap: 8px; }
  .info-label { color: #555; width: 160px; flex-shrink: 0; }
  .info-value { font-weight: bold; }
  table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
  th { background: #CC0000; color: white; padding: 7px 8px; font-size: 9px; text-transform: uppercase; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; }
  tr:nth-child(even) td { background: #fff5f5; }
  .text-center { text-align: center; }
  .text-right { text-align: right; }
  .font-bold { font-weight: bold; }
  .total-row td { background: #fff0f0 !important; font-weight: bold; border-top: 1.5px solid #CC0000; }
  .footer { padding: 16px 30px; margin-top: 20px; border-top: 1px solid #ddd; font-size: 9px; color: #888; }
  .stamp-area { border: 1.5px dashed #CC0000; padding: 10px; text-align: center; margin-top: 20px; }
  .stamp-text { font-size: 9px; color: #CC0000; font-weight: bold; }
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="bim-label">Badan Industri Mineral (BIM) — Notifikasi Ekspor</div>
    <div class="doc-title">EXPORT NOTIFICATION</div>
    <div style="font-size:9px; color:#555; margin-top:3px;">No: EN-{{ now()->format('Y') }}-{{ str_pad($export->id, 6, '0', STR_PAD_LEFT) }}</div>
  </div>
  <div style="text-align:right;">
    <div style="font-size:9px; color:#888;">Tanggal Notifikasi</div>
    <div style="font-size:13px; font-weight:bold;">{{ $date }}</div>
  </div>
</div>

<div class="section">
  <p style="margin-bottom:12px; line-height:1.7;">
    Yang bertanda tangan di bawah ini, menyampaikan notifikasi ekspor mineral LTJ sesuai ketentuan yang berlaku kepada <strong>Badan Industri Mineral (BIM)</strong> dengan detail sebagai berikut:
  </p>
  <div class="section-title">Data Perusahaan Pengirim</div>
  <div class="info-row"><span class="info-label">Nama Perusahaan</span><span class="info-value">{{ $company->name }}</span></div>
  <div class="info-row"><span class="info-label">Jenis Industri</span><span class="info-value">{{ $company->industry ?? '-' }}</span></div>
  <div class="info-row"><span class="info-label">Alamat</span><span class="info-value">{{ $company->address ?? '-' }}, {{ $company->city ?? '-' }}, {{ $company->province ?? '-' }}</span></div>
  <div class="info-row"><span class="info-label">Kontak</span><span class="info-value">{{ $company->phone ?? '-' }} | {{ $company->email ?? '-' }}</span></div>
</div>

<div class="section">
  <div class="section-title">Detail Pengiriman</div>
  <div style="display:flex; gap:30px;">
    <div style="flex:1;">
      <div class="info-row"><span class="info-label">Tipe Ekspor</span><span class="info-value">{{ $export->export_type === 'international' ? 'Ekspor Internasional' : 'Penjualan Domestik' }}</span></div>
      <div class="info-row"><span class="info-label">Tujuan</span><span class="info-value">{{ $export->destination_company }} ({{ $export->destination_country ?? 'Indonesia' }})</span></div>
      <div class="info-row"><span class="info-label">Tanggal Ekspor</span><span class="info-value">{{ $export->exported_at?->format('d F Y') ?? $date }}</span></div>
    </div>
    <div style="flex:1;">
      <div class="info-row"><span class="info-label">Total Berat</span><span class="info-value">{{ number_format($export->total_weight_kg, 2) }} kg ({{ number_format($export->total_weight_kg/1000, 3) }} MT)</span></div>
      <div class="info-row"><span class="info-label">Nilai (USD)</span><span class="info-value">{{ $export->value_usd ? 'USD ' . number_format($export->value_usd, 2) : '-' }}</span></div>
      <div class="info-row"><span class="info-label">No. Invoice</span><span class="info-value">{{ $export->invoice_number ?? '-' }}</span></div>
    </div>
  </div>
</div>

<div class="section">
  <div class="section-title">Material yang Diekspor</div>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Kode Batch</th>
        <th>Kode Produk</th>
        <th>Nama Material</th>
        <th>Berat (kg)</th>
        <th>Simbol Kimia</th>
      </tr>
    </thead>
    <tbody>
      @foreach($batches as $i => $batch)
      <tr>
        <td class="text-center">{{ $i + 1 }}</td>
        <td class="font-bold" style="font-family:monospace; font-size:9px;">{{ $batch->batch_code }}</td>
        <td class="text-center">{{ $batch->productCode?->code }}</td>
        <td>{{ $batch->productCode?->name }}</td>
        <td class="text-right">{{ number_format($batch->current_weight, 2) }}</td>
        <td class="text-center">{{ $batch->productCode?->chemical_symbol ?? '-' }}</td>
      </tr>
      @endforeach
      <tr class="total-row">
        <td colspan="4" class="text-right">TOTAL</td>
        <td class="text-right">{{ number_format($batches->sum('current_weight'), 2) }} kg</td>
        <td></td>
      </tr>
    </tbody>
  </table>
</div>

<div style="padding: 0 30px; margin-top: 24px; display:flex; justify-content:space-between; align-items:flex-end;">
  <div style="max-width:280px; font-size:9px; color:#555; line-height:1.6;">
    Dokumen ini merupakan notifikasi resmi ekspor mineral LTJ yang dikirimkan kepada BIM sesuai ketentuan sistem traceability nasional. Untuk pertanyaan, hubungi perusahaan pengirim.
  </div>
  <div style="text-align:center;">
    <div style="border-top: 1px solid #333; width: 200px; margin: 50px auto 6px;"></div>
    <div style="font-size:9px; font-weight:bold;">Pejabat Berwenang</div>
    <div style="font-size:9px; color:#555;">{{ $company->name }}</div>
    <div style="font-size:8px; color:#888; margin-top:2px;">{{ $date }}</div>
  </div>
</div>

<div class="stamp-area" style="margin: 16px 30px 0;">
  <div class="stamp-text">⬜ UNTUK VERIFIKASI BIM — MINETRA MINERAL TRACEABILITY SYSTEM</div>
  <div style="font-size:8px; color:#888; margin-top:4px;">Dokumen ini digenerate otomatis. Scan QR untuk verifikasi digital (fitur mendatang).</div>
</div>

<div class="footer">
  <p>Generated by MINETRA Mineral Traceability System — {{ now()->format('d F Y H:i') }} WIB | {{ $company->name }}</p>
</div>

</body>
</html>