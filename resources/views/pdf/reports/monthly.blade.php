<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'DejaVu Sans',sans-serif; font-size:9px; color:#1a1a1a; line-height:1.5; }
  .header { background:#3E5C74; color:white; padding:18px 24px; }
  .h-title { font-size:15px; font-weight:bold; letter-spacing:1.5px; }
  .h-sub { font-size:8.5px; opacity:0.8; margin-top:2px; }
  .section { padding:10px 24px; }
  .section-title { font-size:9.5px; font-weight:bold; text-transform:uppercase; letter-spacing:1px; color:#3E5C74; border-bottom:1px solid #3E5C74; padding-bottom:3px; margin-bottom:10px; }
  .kpi-wrap { width:100%; margin-bottom:12px; }
  .kpi-table { width:100%; border-collapse:separate; border-spacing:6px 0; }
  .kpi-cell { background:#f0f5f9; padding:9px 10px; text-align:center; border-radius:5px; }
  .kpi-val { font-size:20px; font-weight:bold; color:#3E5C74; }
  .kpi-lbl { font-size:7.5px; color:#666; margin-top:2px; }
  table.dt { width:100%; border-collapse:collapse; font-size:9px; }
  table.dt th { background:#3E5C74; color:white; padding:5px 7px; text-align:left; font-size:8px; text-transform:uppercase; }
  table.dt td { padding:5px 7px; border-bottom:1px solid #eee; vertical-align:middle; }
  table.dt tr:nth-child(even) td { background:#f9fafb; }
  table.dt tr.total td { background:#f0f5f9; font-weight:bold; border-top:1.5px solid #3E5C74; }
  .tr { text-align:right; }
  .tc { text-align:center; }
  .bold { font-weight:bold; }
  .muted { color:#999; font-style:italic; }
  .badge { padding:2px 6px; border-radius:3px; font-size:7.5px; font-weight:bold; }
  .b-ok    { background:#d1fae5; color:#065f46; }
  .b-warn  { background:#fef3c7; color:#92400e; }
  .b-alert { background:#fee2e2; color:#991b1b; }
  .b-mon   { background:#fee2e2; color:#991b1b; }
  .b-zir   { background:#dbeafe; color:#1e40af; }
  .b-ilm   { background:#fef3c7; color:#92400e; }
  .mini-bar-wrap { width:100%; background:#e5e7eb; border-radius:3px; height:6px; margin-top:3px; }
  .mini-bar { height:6px; border-radius:3px; }
  .footer { padding:10px 24px; border-top:1px solid #e5e7eb; font-size:7.5px; color:#aaa; }
  .two-col { display:table; width:100%; }
  .two-col-cell { display:table-cell; width:50%; vertical-align:top; padding-right:8px; }
  .two-col-cell:last-child { padding-right:0; padding-left:8px; }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
  <table width="100%">
    <tr>
      <td>
        <div class="h-title">LAPORAN PRODUKSI BULANAN</div>
        <div class="h-sub">{{ $company->name }} &mdash; MINETRA Mineral Traceability System</div>
        <div class="h-sub">Periode: {{ $date_from }} &mdash; {{ $date_to }}</div>
      </td>
      <td style="text-align:right; font-size:8px; opacity:0.8; vertical-align:top;">
        <div>Dicetak: {{ $generated_at }}</div>
        <div>{{ $company->code }}</div>
      </td>
    </tr>
  </table>
</div>

<!-- KPI Summary -->
<div class="section">
  <div class="section-title">Ringkasan Kinerja</div>
  <table class="kpi-table">
    <tr>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $batch_summary['total_created'] }}</div>
        <div class="kpi-lbl">Batch Dibuat</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ number_format($batch_summary['total_weight_kg'] / 1000, 2) }}</div>
        <div class="kpi-lbl">Total Tonase (MT)</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $export_summary['total'] }}</div>
        <div class="kpi-lbl">Total Ekspor</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $lab_summary['total'] }}</div>
        <div class="kpi-lbl">Analisis Lab</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val" style="{{ $lab_summary['avg_reo_pct'] > 0 ? 'color:#059669' : 'color:#3E5C74' }}">{{ $lab_summary['avg_reo_pct'] }}%</div>
        <div class="kpi-lbl">Rata-rata REO</div>
      </td>
    </tr>
  </table>
</div>

<!-- Produksi per Material (by_product) -->
<div class="section">
  <div class="section-title">Produksi per Material</div>
  @if(count($batch_summary['by_product']) > 0)
  @php
    $maxWeight = max(array_column($batch_summary['by_product'], 'weight'));
    $productColors = ['MON' => '#ef4444', 'ZIR' => '#3b82f6', 'ILM' => '#f59e0b'];
  @endphp
  <table class="dt">
    <thead>
      <tr>
        <th width="12%">Kode</th>
        <th>Nama Material</th>
        <th width="10%" class="tr">Batch</th>
        <th width="15%" class="tr">Berat (kg)</th>
        <th width="12%" class="tr">Tonase (MT)</th>
        <th width="30%">Proporsi</th>
      </tr>
    </thead>
    <tbody>
      @php $totalW = $batch_summary['total_weight_kg']; @endphp
      @foreach($batch_summary['by_product'] as $p)
      @php
        $pct   = $totalW > 0 ? round(($p['weight'] / $totalW) * 100, 1) : 0;
        $color = $productColors[$p['code']] ?? '#6b7280';
        $barW  = $maxWeight > 0 ? round(($p['weight'] / $maxWeight) * 100, 0) : 0;
      @endphp
      <tr>
        <td class="bold">{{ $p['code'] }}</td>
        <td class="muted">{{ $p['name'] }}</td>
        <td class="tr bold">{{ $p['count'] }}</td>
        <td class="tr">{{ number_format($p['weight'], 2) }}</td>
        <td class="tr">{{ number_format($p['weight'] / 1000, 4) }}</td>
        <td>
          <div style="display:flex; align-items:center; gap:6px;">
            <div class="mini-bar-wrap" style="flex:1">
              <div class="mini-bar" style="width:{{ $barW }}%; background:{{ $color }}"></div>
            </div>
            <span style="font-size:8px; font-weight:bold; color:{{ $color }}; white-space:nowrap;">{{ $pct }}%</span>
          </div>
        </td>
      </tr>
      @endforeach
      <tr class="total">
        <td colspan="2">TOTAL</td>
        <td class="tr">{{ $batch_summary['total_created'] }}</td>
        <td class="tr">{{ number_format($batch_summary['total_weight_kg'], 2) }}</td>
        <td class="tr">{{ number_format($batch_summary['total_weight_kg'] / 1000, 4) }}</td>
        <td></td>
      </tr>
    </tbody>
  </table>
  @else
  <p class="muted" style="padding:8px 0;">Tidak ada batch dibuat pada periode ini.</p>
  @endif
</div>

<!-- Distribusi per Stage + Ekspor (dua kolom) -->
<div class="section">
  <div class="two-col">
    <!-- Kolom 1: Per Stage -->
    <div class="two-col-cell">
      <div class="section-title" style="font-size:9px;">Distribusi per Tahap Proses</div>
      @if(count($batch_summary['by_stage']) > 0)
      <table class="dt">
        <thead>
          <tr>
            <th>Stage</th>
            <th class="tr" width="20%">Batch</th>
            <th class="tr" width="30%">Berat (kg)</th>
          </tr>
        </thead>
        <tbody>
          @foreach($batch_summary['by_stage'] as $s)
          <tr>
            <td>{{ $s['stage_label'] }}</td>
            <td class="tr bold">{{ $s['count'] }}</td>
            <td class="tr">{{ number_format($s['weight'], 2) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
      @else
      <p class="muted" style="padding:6px 0; font-size:8px;">Tidak ada data.</p>
      @endif
    </div>

    <!-- Kolom 2: Ringkasan Ekspor -->
    <div class="two-col-cell">
      <div class="section-title" style="font-size:9px;">Ringkasan Ekspor</div>
      <table class="dt">
        <thead>
          <tr>
            <th>Tipe</th>
            <th class="tr" width="25%">Jumlah</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>🌍 Internasional</td>
            <td class="tr bold">{{ $export_summary['international'] }}</td>
          </tr>
          <tr>
            <td>🏭 Domestik</td>
            <td class="tr bold">{{ $export_summary['domestic'] }}</td>
          </tr>
          <tr class="total">
            <td>Total</td>
            <td class="tr">{{ $export_summary['total'] }}</td>
          </tr>
        </tbody>
      </table>
      @if($export_summary['total_weight'] > 0 || $export_summary['total_value'] > 0)
      <table style="width:100%; margin-top:8px; font-size:9px;">
        <tr>
          <td style="color:#666;">Total Volume:</td>
          <td style="text-align:right; font-weight:bold;">{{ number_format($export_summary['total_weight'], 2) }} kg</td>
        </tr>
        <tr>
          <td style="color:#666;">Total Nilai:</td>
          <td style="text-align:right; font-weight:bold;">USD {{ number_format($export_summary['total_value'], 2) }}</td>
        </tr>
      </table>
      @endif
    </div>
  </div>
</div>

<!-- Analisis Lab -->
<div class="section">
  <div class="section-title">Ringkasan Analisis Lab LTJ</div>
  <table class="dt">
    <thead>
      <tr>
        <th>Metrik</th>
        <th class="tr" width="25%">Nilai</th>
        <th width="40%">Status</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Total Analisis Dilakukan</td>
        <td class="tr bold">{{ $lab_summary['total'] }}</td>
        <td></td>
      </tr>
      <tr>
        <td>Analisis Tervalidasi</td>
        <td class="tr bold">{{ $lab_summary['validated'] }}</td>
        <td>
          @if($lab_summary['total'] > 0)
            @php $valPct = round($lab_summary['validated'] / $lab_summary['total'] * 100, 1); @endphp
            <div class="mini-bar-wrap">
              <div class="mini-bar" style="width:{{ $valPct }}%; background:#059669;"></div>
            </div>
          @endif
        </td>
      </tr>
      <tr>
        <td>Pending Validasi</td>
        <td class="tr bold">{{ $lab_summary['total'] - $lab_summary['validated'] }}</td>
        <td>
          @if($lab_summary['total'] - $lab_summary['validated'] > 0)
            <span class="badge b-warn">Perlu Ditindaklanjuti</span>
          @else
            <span class="badge b-ok">Semua Tervalidasi</span>
          @endif
        </td>
      </tr>
      <tr>
        <td class="bold">Rata-rata Total REO (%)</td>
        <td class="tr bold" style="color:#3E5C74; font-size:11px;">{{ $lab_summary['avg_reo_pct'] }}%</td>
        <td>
          @if($lab_summary['avg_reo_pct'] >= 5)
            <span class="badge b-ok">Kualitas Baik (≥5%)</span>
          @elseif($lab_summary['avg_reo_pct'] > 0)
            <span class="badge b-warn">Kualitas Rendah</span>
          @else
            <span class="muted">Tidak ada data</span>
          @endif
        </td>
      </tr>
    </tbody>
  </table>
</div>

<!-- Mass Balance Alerts -->
<div class="section">
  <div class="section-title">Mass Balance Alert</div>
  @if(count($alerts) > 0)
  <table class="dt">
    <thead>
      <tr>
        <th>Tahap Proses</th>
        <th class="tr" width="20%">Jumlah Batch</th>
        <th class="tr" width="25%">Rata-rata Susut</th>
        <th width="25%" class="tc">Status</th>
      </tr>
    </thead>
    <tbody>
      @foreach($alerts as $alert)
      <tr>
        <td class="bold">{{ ucwords(str_replace('_', ' ', $alert['process_stage'])) }}</td>
        <td class="tr">{{ $alert['cnt'] }}</td>
        <td class="tr">{{ number_format($alert['avg_loss'], 2) }}%</td>
        <td class="tc"><span class="badge b-alert">⚠ Perlu Verifikasi</span></td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @else
  <div style="text-align:center; padding:12px; background:#f0fdf4; border-radius:6px;">
    <span class="badge b-ok">✓ Tidak ada mass balance alert pada periode ini</span>
  </div>
  @endif
</div>

<div class="footer">
  <table width="100%">
    <tr>
      <td>Laporan digenerate otomatis oleh MINETRA Mineral Traceability System pada {{ $generated_at }}</td>
      <td style="text-align:right;">{{ $company->name }} &mdash; Confidential</td>
    </tr>
  </table>
</div>

</body>
</html>