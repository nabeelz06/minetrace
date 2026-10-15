<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'DejaVu Sans',sans-serif; font-size:8px; color:#1a1a1a; line-height:1.5; }
  .header { background:#3E5C74; color:white; padding:14px 20px; }
  .h-title { font-size:13px; font-weight:bold; letter-spacing:1.5px; }
  .h-sub { font-size:8px; opacity:0.8; margin-top:2px; }
  .section { padding:8px 20px; }
  .section-title { font-size:9px; font-weight:bold; text-transform:uppercase; letter-spacing:1px; color:#3E5C74; border-bottom:1px solid #3E5C74; padding-bottom:3px; margin-bottom:8px; }
  .kpi-wrap { width:100%; margin-bottom:10px; border-collapse:separate; border-spacing:5px 0; }
  .kpi-cell { background:#f0f5f9; padding:7px 8px; text-align:center; border-radius:4px; }
  .kpi-val { font-size:16px; font-weight:bold; color:#3E5C74; }
  .kpi-lbl { font-size:7px; color:#666; margin-top:1px; }
  table.dt { width:100%; border-collapse:collapse; font-size:7.5px; }
  table.dt th { background:#3E5C74; color:white; padding:5px 5px; text-align:left; font-size:7px; text-transform:uppercase; letter-spacing:0.3px; }
  table.dt td { padding:4px 5px; border-bottom:1px solid #eee; vertical-align:middle; }
  table.dt tr:nth-child(even) td { background:#f9fafb; }
  table.dt tr.total td { background:#f0f5f9; font-weight:bold; border-top:1.5px solid #3E5C74; }
  .th-ltj { background:#5a7a92 !important; }
  .th-radio { background:#7a6092 !important; }
  .tr { text-align:right; }
  .tc { text-align:center; }
  .bold { font-weight:bold; }
  .muted { color:#999; }
  .green { color:#059669; font-weight:bold; }
  .mono { font-family:'DejaVu Sans Mono',monospace; font-size:7px; }
  .badge { padding:1px 4px; border-radius:2px; font-size:6.5px; font-weight:bold; }
  .b-ok { background:#d1fae5; color:#065f46; }
  .b-pend { background:#fef3c7; color:#92400e; }
  .ltj-val { color:#3E5C74; font-weight:bold; }
  .footer { padding:8px 20px; border-top:1px solid #e5e7eb; font-size:7px; color:#aaa; }
</style>
</head>
<body>

<div class="header">
  <table width="100%">
    <tr>
      <td>
        <div class="h-title">LAPORAN ANALISIS LAB — LOGAM TANAH JARANG (LTJ)</div>
        <div class="h-sub">{{ $company->name }} &mdash; MINETRACE Mineral Traceability System</div>
        <div class="h-sub">Periode: {{ $date_from }} &mdash; {{ $date_to }}</div>
      </td>
      <td style="text-align:right; font-size:7.5px; opacity:0.8; vertical-align:top;">
        <div>Dicetak: {{ $generated_at }}</div>
        <div>{{ $company->code }}</div>
      </td>
    </tr>
  </table>
</div>

<div class="section">
  <div class="section-title">Ringkasan Analisis</div>
  <table class="kpi-wrap">
    <tr>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $stats['total'] }}</div>
        <div class="kpi-lbl">Total Analisis</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $stats['validated'] }}</div>
        <div class="kpi-lbl">Tervalidasi</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $stats['total'] - $stats['validated'] }}</div>
        <div class="kpi-lbl">Pending Validasi</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $stats['avg_reo'] }}%</div>
        <div class="kpi-lbl">Rata-rata Total REO</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $analyses->isNotEmpty() ? number_format($analyses->sum(fn($a) => ($a->la_kg ?? 0) + ($a->ce_kg ?? 0) + ($a->pr_kg ?? 0) + ($a->nd_kg ?? 0) + ($a->y_kg ?? 0)), 2) : 0 }}</div>
        <div class="kpi-lbl">Total REO Terkandung (kg)</div>
      </td>
    </tr>
  </table>
</div>

<div class="section">
  <div class="section-title">Hasil Analisis Per Batch</div>
  @if($analyses->isEmpty())
    <p class="muted" style="font-style:italic; padding:8px 0;">Tidak ada data analisis pada periode yang dipilih.</p>
  @else
  <table class="dt">
    <thead>
      <tr>
        <th width="3%" class="tc">#</th>
        <th width="11%">Batch</th>
        <th width="5%">Produk</th>
        <th width="6%" class="tr">Berat (kg)</th>
        <th class="th-ltj tr" width="5%">La (%)</th>
        <th class="th-ltj tr" width="5%">Ce (%)</th>
        <th class="th-ltj tr" width="5%">Pr (%)</th>
        <th class="th-ltj tr" width="5%">Nd (%)</th>
        <th class="th-ltj tr" width="5%">Y (%)</th>
        <th class="th-ltj tr" width="6%">REO Total (%)</th>
        <th class="th-ltj tr" width="7%">REO (kg)</th>
        <th class="th-radio tr" width="5%">ThO₂ (%)</th>
        <th class="th-radio tr" width="5%">U₃O₈ (%)</th>
        <th width="7%">Metode</th>
        <th width="9%">Tanggal</th>
        <th width="6%">Status</th>
      </tr>
    </thead>
    <tbody>
      @php
        $totalLa = 0; $totalCe = 0; $totalPr = 0; $totalNd = 0; $totalY = 0;
        $totalReoKg = 0; $totalWeight = 0; $countValid = 0;
      @endphp
      @foreach($analyses as $i => $a)
      @php
        $reoKg = round(($a->la_kg ?? 0) + ($a->ce_kg ?? 0) + ($a->pr_kg ?? 0) + ($a->nd_kg ?? 0) + ($a->y_kg ?? 0), 3);
        $batchWeight = $a->batch?->current_weight ?? 0;
        if($a->is_validated) {
          $totalLa += $a->la_pct ?? 0;
          $totalCe += $a->ce_pct ?? 0;
          $totalPr += $a->pr_pct ?? 0;
          $totalNd += $a->nd_pct ?? 0;
          $totalY  += $a->y_pct ?? 0;
          $totalReoKg += $reoKg;
          $totalWeight += $batchWeight;
          $countValid++;
        }
      @endphp
      <tr>
        <td class="tc muted">{{ $i + 1 }}</td>
        <td class="mono bold">{{ $a->batch?->batch_code ?? '—' }}</td>
        <td class="tc bold">{{ $a->batch?->productCode?->code ?? '—' }}</td>
        <td class="tr">{{ number_format($batchWeight, 2) }}</td>
        <td class="tr ltj-val">{{ number_format($a->la_pct ?? 0, 3) }}</td>
        <td class="tr ltj-val">{{ number_format($a->ce_pct ?? 0, 3) }}</td>
        <td class="tr ltj-val">{{ number_format($a->pr_pct ?? 0, 3) }}</td>
        <td class="tr ltj-val">{{ number_format($a->nd_pct ?? 0, 3) }}</td>
        <td class="tr ltj-val">{{ number_format($a->y_pct ?? 0, 3) }}</td>
        <td class="tr bold" style="color:#3E5C74;">{{ number_format($a->total_reo_pct ?? 0, 3) }}</td>
        <td class="tr bold" style="color:#3E5C74;">{{ number_format($reoKg, 3) }}</td>
        <td class="tr muted">{{ $a->tho2_pct !== null ? number_format($a->tho2_pct, 3) : '—' }}</td>
        <td class="tr muted">{{ $a->u3o8_pct !== null ? number_format($a->u3o8_pct, 3) : '—' }}</td>
        <td class="muted">{{ $a->lab_method ?? '—' }}</td>
        <td class="muted">{{ $a->analyzed_at?->timezone('Asia/Jakarta')->format('d/m/Y') ?? '—' }}</td>
        <td class="tc">
          @if($a->is_validated)
            <span class="badge b-ok">✓ Valid</span>
          @else
            <span class="badge b-pend">Pending</span>
          @endif
        </td>
      </tr>
      @endforeach
      @if($countValid > 0)
      <tr class="total">
        <td colspan="4">RATA-RATA ({{ $countValid }} tervalidasi)</td>
        <td class="tr">{{ number_format($totalLa / $countValid, 3) }}</td>
        <td class="tr">{{ number_format($totalCe / $countValid, 3) }}</td>
        <td class="tr">{{ number_format($totalPr / $countValid, 3) }}</td>
        <td class="tr">{{ number_format($totalNd / $countValid, 3) }}</td>
        <td class="tr">{{ number_format($totalY / $countValid, 3) }}</td>
        <td class="tr" style="color:#3E5C74;">{{ number_format(($totalLa+$totalCe+$totalPr+$totalNd+$totalY)/$countValid, 3) }}</td>
        <td class="tr" style="color:#3E5C74;">{{ number_format($totalReoKg, 3) }}</td>
        <td colspan="5"></td>
      </tr>
      @endif
    </tbody>
  </table>
  @endif
</div>

<div class="footer">
  <table width="100%"><tr>
    <td>MINETRACE Mineral Traceability System &mdash; {{ $generated_at }} &mdash; Satuan: % = persen berat, kg = kilogram</td>
    <td style="text-align:right;">{{ $company->name }} &mdash; Confidential</td>
  </tr></table>
</div>
</body>
</html>