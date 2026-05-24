<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.5; }
  .header { background: #2a4052; color: white; padding: 20px 30px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
  .header-title { font-size: 18px; font-weight: bold; letter-spacing: 2px; }
  .header-sub { font-size: 10px; opacity: 0.75; margin-top: 2px; }
  .section { padding: 0 30px; margin-bottom: 16px; }
  .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #2a4052; border-bottom: 1.5px solid #2a4052; padding-bottom: 4px; margin-bottom: 10px; }
  .info-row { margin-bottom: 4px; display: flex; gap: 8px; }
  .info-label { color: #555; width: 150px; flex-shrink: 0; }
  .info-value { font-weight: bold; }
  table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
  th { background: #2a4052; color: white; padding: 7px 8px; text-align: center; font-weight: bold; font-size: 9px; text-transform: uppercase; }
  td { padding: 6px 8px; border-bottom: 1px solid #e8e8e8; }
  tr:nth-child(even) td { background: #f5f7f9; }
  .text-right { text-align: right; }
  .text-center { text-align: center; }
  .font-bold { font-weight: bold; }
  .total-row td { background: #EEF4F8 !important; font-weight: bold; border-top: 1.5px solid #2a4052; }
  .footer { padding: 20px 30px; margin-top: 20px; border-top: 1px solid #ddd; font-size: 9px; color: #888; }
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="header-title">PACKING LIST</div>
    <div class="header-sub">{{ $company->name }} — MINETRA Mineral Traceability</div>
  </div>
  <div style="text-align:right;">
    <div style="font-size:9px; opacity:0.7;">TANGGAL</div>
    <div style="font-size:14px; font-weight:bold;">{{ $date }}</div>
    <div style="font-size:9px; opacity:0.7; margin-top:4px;">REF</div>
    <div style="font-size:11px; font-weight:bold;">PL-{{ str_pad($export->id, 6, '0', STR_PAD_LEFT) }}</div>
  </div>
</div>

<div class="section">
  <div class="section-title">Informasi Pengiriman</div>
  <div style="display:flex; gap:30px;">
    <div style="flex:1;">
      <div class="info-row"><span class="info-label">Pengirim</span><span class="info-value">{{ $company->name }}</span></div>
      <div class="info-row"><span class="info-label">Alamat</span><span class="info-value">{{ $company->address ?? '-' }}, {{ $company->city ?? '-' }}</span></div>
      <div class="info-row"><span class="info-label">Telepon</span><span class="info-value">{{ $company->phone ?? '-' }}</span></div>
    </div>
    <div style="flex:1;">
      <div class="info-row"><span class="info-label">Penerima</span><span class="info-value">{{ $export->destination_company }}</span></div>
      <div class="info-row"><span class="info-label">Negara</span><span class="info-value">{{ $export->destination_country ?? 'Indonesia' }}</span></div>
      <div class="info-row"><span class="info-label">No. Container</span><span class="info-value">{{ $export->container_number ?? '-' }}</span></div>
      <div class="info-row"><span class="info-label">Nama Kapal</span><span class="info-value">{{ $export->vessel_name ?? '-' }}</span></div>
    </div>
  </div>
</div>

<div class="section">
  <div class="section-title">Daftar Material</div>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Kode Batch</th>
        <th>Kode Produk</th>
        <th>Deskripsi Material</th>
        <th>Berat Bersih (kg)</th>
        <th>Berat Bersih (MT)</th>
        <th>Keterangan</th>
      </tr>
    </thead>
    <tbody>
      @foreach($batches as $i => $batch)
      <tr>
        <td class="text-center">{{ $i + 1 }}</td>
        <td class="font-bold" style="font-family:monospace; font-size:9px;">{{ $batch->batch_code }}</td>
        <td class="text-center">{{ $batch->productCode?->code }}</td>
        <td>{{ $batch->productCode?->name ?? '-' }}</td>
        <td class="text-right">{{ number_format($batch->current_weight, 3) }}</td>
        <td class="text-right">{{ number_format($batch->current_weight / 1000, 4) }}</td>
        <td class="text-center">{{ $batch->productCode?->chemical_symbol ?? '-' }}</td>
      </tr>
      @endforeach
      <tr class="total-row">
        <td colspan="4" class="text-right">TOTAL BERAT</td>
        <td class="text-right">{{ number_format($batches->sum('current_weight'), 3) }} kg</td>
        <td class="text-right">{{ number_format($batches->sum('current_weight') / 1000, 4) }} MT</td>
        <td></td>
      </tr>
    </tbody>
  </table>
</div>

@if($export->notes)
<div class="section">
  <div class="section-title">Catatan</div>
  <p>{{ $export->notes }}</p>
</div>
@endif

<div class="footer">
  <p>Dokumen ini digenerate otomatis oleh MINETRA Mineral Traceability System pada {{ now()->format('d F Y, H:i') }} WIB.</p>
  <p>{{ $company->name }} | {{ $company->address ?? '' }} | {{ $company->email ?? '' }}</p>
</div>

</body>
</html>