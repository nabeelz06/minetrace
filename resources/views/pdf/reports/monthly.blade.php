<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'DejaVu Sans',sans-serif; font-size:10px; color:#1a1a1a; line-height:1.5; }
  .header { background:#3E5C74; color:white; padding:24px 30px; }
  .header-title { font-size:18px; font-weight:bold; letter-spacing:2px; }
  .header-sub { font-size:10px; opacity:0.8; margin-top:2px; }
  .section { padding:16px 30px; }
  .section-title { font-size:11px; font-weight:bold; text-transform:uppercase; letter-spacing:1px; color:#3E5C74; border-bottom:1.5px solid #3E5C74; padding-bottom:4px; margin-bottom:12px; }
  .kpi-grid { display:flex; gap:12px; margin-bottom:16px; }
  .kpi-card { flex:1; background:#f0f5f9; border-radius:8px; padding:12px; text-align:center; }
  .kpi-value { font-size:22px; font-weight:bold; color:#3E5C74; }
  .kpi-label { font-size:9px; color:#666; margin-top:2px; }
  table { width:100%; border-collapse:collapse; font-size:9.5px; }
  th { background:#3E5C74; color:white; padding:6px 8px; text-align:left; font-size:9px; text-transform:uppercase; }
  td { padding:5px 8px; border-bottom:1px solid #eee; }
  tr:nth-child(even) td { background:#f8f9fa; }
  .text-right { text-align:right; }
  .text-center { text-align:center; }
  .font-bold { font-weight:bold; }
  .badge-warning { background:#fef3c7; color:#92400e; padding:2px 6px; border-radius:3px; font-size:8px; font-weight:bold; }
  .badge-ok { background:#d1fae5; color:#065f46; padding:2px 6px; border-radius:3px; font-size:8px; font-weight:bold; }
  .footer { padding:16px 30px; border-top:1px solid #ddd; font-size:9px; color:#888; margin-top:20px; }
  .page-break { page-break-before:always; }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
  <div style="display:flex; justify-content:space-between; align-items:flex-start;">
    <div>
      <div class="header-title">LAPORAN PRODUKSI BULANAN</div>
      <div class="header-sub">{{ $company->name }} — MINETRA Mineral Traceability System</div>
      <div class="header-sub">Periode: {{ $month_label }}</div>
    </div>
    <div style="text-align:right; font-size:9px; opacity:0.8;">
      <div>Dibuat: {{ $generated_at }}</div>
      <div>{{ $company->code }} — {{ $company->industry }}</div>
    </div>
  </div>
</div>

<!-- KPI Summary -->
<div class="section">
  <div class="section-title">Ringkasan Kinerja Bulan Ini</div>
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-value">{{ $batch_summary['total_created'] }}</div>
      <div class="kpi-label">Batch Dibuat</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-value">{{ number_format($batch_summary['total_weight_kg']/1000, 1) }}</div>
      <div class="kpi-label">Total Tonase (MT)</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-value">{{ $export_summary['total'] }}</div>
      <div class="kpi-label">Total Ekspor</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-value">{{ $lab_summary['total'] }}</div>
      <div class="kpi-label">Analisis Lab</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-value">{{ $lab_summary['avg_reo_pct'] }}%</div>
      <div class="kpi-label">Rata-rata REO</div>
    </div>
  </div>
</div>

<!-- Batch by Product -->
<div class="section">
  <div class="section-title">Produksi per Material</div>
  @if(count($batch_summary['by_product']) > 0)
  <table>
    <thead><tr><th>Kode Produk</th><th class="text-right">Jumlah Batch</th><th class="text-right">Berat Total (kg)</th><th class="text-right">Berat (MT)</th></tr></thead>
    <tbody>
      @foreach($batch_summary['by_product'] as $p)
      <tr>
        <td class="font-bold">{{ $p['code'] ?? '—' }}</td>
        <td class="text-right">{{ $p['count'] }}</td>
        <td class="text-right">{{ number_format($p['weight'], 2) }}</td>
        <td class="text-right">{{ number_format($p['weight']/1000, 3) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @else
  <p style="color:#999; font-style:italic;">Tidak ada batch dibuat bulan ini.</p>
  @endif
</div>

<!-- Export Summary -->
<div class="section">
  <div class="section-title">Ringkasan Ekspor</div>
  <table>
    <thead><tr><th>Tipe</th><th class="text-right">Jumlah</th><th class="text-right">Total Berat (kg)</th><th class="text-right">Nilai (USD)</th></tr></thead>
    <tbody>
      <tr>
        <td>🌍 Ekspor Internasional</td>
        <td class="text-right">{{ $export_summary['international'] }}</td>
        <td class="text-right">—</td>
        <td class="text-right">—</td>
      </tr>
      <tr>
        <td>🏭 Penjualan Domestik</td>
        <td class="text-right">{{ $export_summary['domestic'] }}</td>
        <td class="text-right">—</td>
        <td class="text-right">—</td>
      </tr>
      <tr style="background:#f0f5f9; font-weight:bold;">
        <td>TOTAL</td>
        <td class="text-right">{{ $export_summary['total'] }}</td>
        <td class="text-right">{{ number_format($export_summary['total_weight'], 2) }} kg</td>
        <td class="text-right">USD {{ number_format($export_summary['total_value'], 2) }}</td>
      </tr>
    </tbody>
  </table>
</div>

<!-- Mass Balance Alerts -->
<div class="section">
  <div class="section-title">Laporan Mass Balance Alert</div>
  @if(count($alerts) > 0)
  <table>
    <thead><tr><th>Stage</th><th class="text-right">Jumlah Batch</th><th class="text-right">Rata-rata Susut</th><th class="text-center">Status</th></tr></thead>
    <tbody>
      @foreach($alerts as $alert)
      <tr>
        <td>{{ ucwords(str_replace('_', ' ', $alert['process_stage'])) }}</td>
        <td class="text-right">{{ $alert['cnt'] }}</td>
        <td class="text-right">{{ number_format($alert['avg_loss'], 2) }}%</td>
        <td class="text-center"><span class="badge-warning">⚠ Perlu Perhatian</span></td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @else
  <div style="text-align:center; padding:12px;">
    <span class="badge-ok">✓ Tidak ada mass balance alert bulan ini</span>
  </div>
  @endif
</div>

<!-- Lab Analysis -->
<div class="section">
  <div class="section-title">Ringkasan Analisis Lab</div>
  <table>
    <thead><tr><th>Metrik</th><th class="text-right">Nilai</th></tr></thead>
    <tbody>
      <tr><td>Total Analisis Lab</td><td class="text-right">{{ $lab_summary['total'] }}</td></tr>
      <tr><td>Analisis Tervalidasi</td><td class="text-right">{{ $lab_summary['validated'] }}</td></tr>
      <tr><td>Pending Validasi</td><td class="text-right">{{ $lab_summary['total'] - $lab_summary['validated'] }}</td></tr>
      <tr><td>Rata-rata Total REO</td><td class="text-right font-bold">{{ $lab_summary['avg_reo_pct'] }}%</td></tr>
    </tbody>
  </table>
</div>

<div class="footer">
  <p>Laporan ini digenerate secara otomatis oleh MINETRA Mineral Traceability System pada {{ $generated_at }}.</p>
  <p>{{ $company->name }} | {{ $company->email ?? '' }} | Sistem MINETRA v1.0</p>
  <p style="margin-top:4px; color:#bbb;">Dokumen ini bersifat confidential dan hanya untuk penggunaan internal.</p>
</div>

</body>
</html>