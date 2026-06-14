<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'DejaVu Sans',sans-serif; font-size:9px; color:#1a1a1a; line-height:1.5; }
  .header { background:#3E5C74; color:white; padding:16px 22px; }
  .h-title { font-size:14px; font-weight:bold; letter-spacing:1.5px; }
  .h-sub { font-size:8.5px; opacity:0.8; margin-top:2px; }
  .section { padding:10px 22px; }
  .section-title { font-size:9.5px; font-weight:bold; text-transform:uppercase; letter-spacing:1px; color:#3E5C74; border-bottom:1px solid #3E5C74; padding-bottom:3px; margin-bottom:10px; }
  .kpi-wrap { width:100%; margin-bottom:12px; border-collapse:separate; border-spacing:6px 0; }
  .kpi-cell { background:#f0f5f9; padding:8px 10px; text-align:center; border-radius:4px; }
  .kpi-val { font-size:18px; font-weight:bold; color:#3E5C74; }
  .kpi-lbl { font-size:7.5px; color:#666; margin-top:1px; }
  table.dt { width:100%; border-collapse:collapse; font-size:9px; }
  table.dt th { background:#3E5C74; color:white; padding:6px 8px; text-align:left; font-size:8px; text-transform:uppercase; letter-spacing:0.5px; }
  table.dt td { padding:5px 8px; border-bottom:1px solid #eee; vertical-align:middle; }
  table.dt tr:nth-child(even) td { background:#f9fafb; }
  table.dt tr.total td { background:#f0f5f9; font-weight:bold; border-top:1.5px solid #3E5C74; }
  .tr { text-align:right; }
  .tc { text-align:center; }
  .bold { font-weight:bold; }
  .muted { color:#999; }
  .badge { padding:2px 6px; border-radius:2px; font-size:7.5px; font-weight:bold; }
  .b-int { background:#dbeafe; color:#1e40af; }
  .b-dom { background:#d1fae5; color:#065f46; }
  .footer { padding:10px 22px; border-top:1px solid #e5e7eb; font-size:7.5px; color:#aaa; }
</style>
</head>
<body>

<div class="header">
  <table width="100%">
    <tr>
      <td>
        <div class="h-title">LAPORAN RINGKASAN EKSPOR</div>
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

<div class="section">
  <div class="section-title">Ringkasan Ekspor</div>
  <table class="kpi-wrap">
    <tr>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $totals['count'] }}</div>
        <div class="kpi-lbl">Total Transaksi</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $totals['international'] }}</div>
        <div class="kpi-lbl">Ekspor Internasional</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $totals['domestic'] }}</div>
        <div class="kpi-lbl">Penjualan Domestik</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ number_format($totals['weight_kg'] / 1000, 3) }}</div>
        <div class="kpi-lbl">Total Volume (MT)</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ number_format($totals['value_usd'], 0) }}</div>
        <div class="kpi-lbl">Total Nilai (USD)</div>
      </td>
    </tr>
  </table>
</div>

<div class="section">
  <div class="section-title">Daftar Transaksi Ekspor</div>
  @if($exports->isEmpty())
    <p class="muted" style="font-style:italic; padding:8px 0;">Tidak ada transaksi ekspor pada periode yang dipilih.</p>
  @else
  <table class="dt">
    <thead>
      <tr>
        <th width="4%" class="tc">#</th>
        <th width="12%">Tanggal Ekspor</th>
        <th width="10%">Tipe</th>
        <th width="13%" class="tr">Volume (kg)</th>
        <th width="13%" class="tr">Volume (MT)</th>
        <th width="13%" class="tr">Nilai (USD)</th>
        <th width="11%" class="tr">Harga/MT (USD)</th>
        <th>Dibuat Oleh</th>
      </tr>
    </thead>
    <tbody>
      @php $runningWeight = 0; $runningValue = 0; @endphp
      @foreach($exports as $i => $export)
      @php
        $runningWeight += $export->total_weight_kg ?? 0;
        $runningValue  += $export->value_usd ?? 0;
        $pricePerMt = ($export->total_weight_kg ?? 0) > 0
          ? round(($export->value_usd ?? 0) / (($export->total_weight_kg ?? 0) / 1000), 2)
          : 0;
      @endphp
      <tr>
        <td class="tc muted">{{ $i + 1 }}</td>
        <td>{{ $export->exported_at?->timezone('Asia/Jakarta')->format('d/m/Y') ?? '—' }}</td>
        <td>
          @if($export->export_type === 'international')
            <span class="badge b-int">🌍 Internasional</span>
          @else
            <span class="badge b-dom">🏭 Domestik</span>
          @endif
        </td>
        <td class="tr">{{ number_format($export->total_weight_kg ?? 0, 2) }}</td>
        <td class="tr">{{ number_format(($export->total_weight_kg ?? 0) / 1000, 4) }}</td>
        <td class="tr">{{ number_format($export->value_usd ?? 0, 2) }}</td>
        <td class="tr">{{ $pricePerMt > 0 ? number_format($pricePerMt, 2) : '—' }}</td>
        <td class="muted">{{ $export->createdByUser?->name ?? '—' }}</td>
      </tr>
      @endforeach
      <tr class="total">
        <td colspan="3">TOTAL</td>
        <td class="tr">{{ number_format($totals['weight_kg'], 2) }}</td>
        <td class="tr">{{ number_format($totals['weight_kg'] / 1000, 4) }}</td>
        <td class="tr">{{ number_format($totals['value_usd'], 2) }}</td>
        <td class="tr">
          @php
            $avgPrice = $totals['weight_kg'] > 0
              ? round($totals['value_usd'] / ($totals['weight_kg'] / 1000), 2) : 0;
          @endphp
          {{ $avgPrice > 0 ? number_format($avgPrice, 2) : '—' }}
        </td>
        <td></td>
      </tr>
    </tbody>
  </table>
  @endif
</div>

<div class="footer">
  <table width="100%"><tr>
    <td>MINETRA Mineral Traceability System &mdash; {{ $generated_at }}</td>
    <td style="text-align:right;">{{ $company->name }} &mdash; Confidential</td>
  </tr></table>
</div>
</body>
</html>