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
  table.dt td { padding:6px 8px; border-bottom:1px solid #eee; vertical-align:middle; }
  table.dt tr:nth-child(even) td { background:#f9fafb; }
  table.dt tr.total td { background:#f0f5f9; font-weight:bold; border-top:1.5px solid #3E5C74; }
  .tr { text-align:right; }
  .tc { text-align:center; }
  .bold { font-weight:bold; }
  .muted { color:#999; }
  .red { color:#dc2626; font-weight:bold; }
  .green { color:#059669; }
  .badge { padding:2px 6px; border-radius:2px; font-size:7.5px; font-weight:bold; }
  .b-alert { background:#fee2e2; color:#991b1b; }
  .b-ok { background:#d1fae5; color:#065f46; }
  .note { background:#fffbeb; border:1px solid #fcd34d; border-radius:4px; padding:8px 10px; font-size:8.5px; margin-bottom:10px; }
  .footer { padding:10px 22px; border-top:1px solid #e5e7eb; font-size:7.5px; color:#aaa; }
</style>
</head>
<body>

<div class="header">
  <table width="100%">
    <tr>
      <td>
        <div class="h-title">LAPORAN MASS BALANCE</div>
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
  <div class="section-title">Ringkasan Massa Material</div>
  @php
    $totalInput   = $data->sum('input_weight');
    $totalCurrent = $data->sum('current_weight');
    $totalLoss    = $totalInput - $totalCurrent;
    $totalLossPct = $totalInput > 0 ? round($totalLoss / $totalInput * 100, 2) : 0;
    $totalAlerts  = $data->sum('alert_count');
  @endphp
  <table class="kpi-wrap">
    <tr>
      <td class="kpi-cell">
        <div class="kpi-val">{{ number_format($totalInput / 1000, 3) }}</div>
        <div class="kpi-lbl">Total Input (MT)</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ number_format($totalCurrent / 1000, 3) }}</div>
        <div class="kpi-lbl">Total Output (MT)</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val" style="{{ $totalLossPct > 5 ? 'color:#dc2626' : 'color:#3E5C74' }}">
          {{ $totalLossPct }}%
        </div>
        <div class="kpi-lbl">Susut Total (%)</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ number_format($totalLoss, 2) }}</div>
        <div class="kpi-lbl">Total Susut (kg)</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val" style="{{ $totalAlerts > 0 ? 'color:#dc2626' : 'color:#059669' }}">
          {{ $totalAlerts }}
        </div>
        <div class="kpi-lbl">Total Alert</div>
      </td>
    </tr>
  </table>
</div>

<div class="section">
  <div class="section-title">Rekap Per Tahap Proses</div>
  <table class="dt">
    <thead>
      <tr>
        <th width="18%">Tahap Proses</th>
        <th width="8%" class="tr">Jumlah Batch</th>
        <th width="12%" class="tr">Total Input (kg)</th>
        <th width="12%" class="tr">Total Output (kg)</th>
        <th width="10%" class="tr">Susut (kg)</th>
        <th width="10%" class="tr">Susut Rata (%)</th>
        <th width="10%" class="tc">Alert</th>
        <th class="tc">Evaluasi</th>
      </tr>
    </thead>
    <tbody>
      @foreach($data as $row)
      @php
        $loss = $row['input_weight'] - $row['current_weight'];
        $isAlert = $row['alert_count'] > 0 || $row['avg_loss_pct'] > 5;
      @endphp
      <tr>
        <td class="bold">{{ $row['stage_label'] }}</td>
        <td class="tr">{{ $row['total_batches'] }}</td>
        <td class="tr">{{ number_format($row['input_weight'], 2) }}</td>
        <td class="tr">{{ number_format($row['current_weight'], 2) }}</td>
        <td class="tr {{ $loss > 0 && $isAlert ? 'red' : '' }}">
          {{ number_format($loss, 2) }}
        </td>
        <td class="tr {{ $isAlert ? 'red' : 'green' }}">
          {{ number_format($row['avg_loss_pct'], 2) }}%
        </td>
        <td class="tc">
          @if($row['alert_count'] > 0)
            <span class="badge b-alert">⚠ {{ $row['alert_count'] }}</span>
          @else
            <span class="muted">—</span>
          @endif
        </td>
        <td class="tc">
          @if(!$isAlert)
            <span class="badge b-ok">Normal</span>
          @else
            <span class="badge b-alert">Perlu Cek</span>
          @endif
        </td>
      </tr>
      @endforeach
      <tr class="total">
        <td>TOTAL KESELURUHAN</td>
        <td class="tr">{{ $data->sum('total_batches') }}</td>
        <td class="tr">{{ number_format($totalInput, 2) }}</td>
        <td class="tr">{{ number_format($totalCurrent, 2) }}</td>
        <td class="tr {{ $totalLossPct > 5 ? 'red' : '' }}">{{ number_format($totalLoss, 2) }}</td>
        <td class="tr {{ $totalLossPct > 5 ? 'red' : '' }}">{{ $totalLossPct }}%</td>
        <td class="tr">{{ $totalAlerts }}</td>
        <td class="tc">
          @if($totalAlerts == 0)
            <span class="badge b-ok">Normal</span>
          @else
            <span class="badge b-alert">Ada Alert</span>
          @endif
        </td>
      </tr>
    </tbody>
  </table>
</div>

@if($data->where('alert_count', '>', 0)->isNotEmpty())
<div class="section">
  <div class="section-title">Catatan Tahap dengan Alert</div>
  <div class="note">
    ⚠ Tahap berikut memiliki batch dengan mass balance discrepancy:
    @foreach($data->where('alert_count', '>', 0) as $row)
      <strong>{{ $row['stage_label'] }}</strong> ({{ $row['alert_count'] }} batch, susut rata-rata {{ $row['avg_loss_pct'] }}%)
      @if(!$loop->last), @endif
    @endforeach
    . Lakukan verifikasi manual pada batch-batch tersebut.
  </div>
</div>
@endif

<div class="footer">
  <table width="100%"><tr>
    <td>MINETRA Mineral Traceability System &mdash; {{ $generated_at }}</td>
    <td style="text-align:right;">{{ $company->name }} &mdash; Confidential</td>
  </tr></table>
</div>
</body>
</html>