<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'DejaVu Sans',sans-serif; font-size:8.5px; color:#1a1a1a; line-height:1.5; }
  .header { background:#3E5C74; color:white; padding:16px 22px; }
  .h-title { font-size:14px; font-weight:bold; letter-spacing:1.5px; }
  .h-sub { font-size:8.5px; opacity:0.8; margin-top:2px; }
  .section { padding:10px 22px; }
  .section-title { font-size:9.5px; font-weight:bold; text-transform:uppercase; letter-spacing:1px; color:#3E5C74; border-bottom:1px solid #3E5C74; padding-bottom:3px; margin-bottom:8px; }
  .kpi-wrap { width:100%; margin-bottom:10px; border-collapse:separate; border-spacing:6px 0; }
  .kpi-cell { background:#f0f5f9; padding:8px 10px; text-align:center; border-radius:4px; }
  .kpi-val { font-size:17px; font-weight:bold; color:#3E5C74; }
  .kpi-lbl { font-size:7.5px; color:#666; margin-top:1px; }
  table.dt { width:100%; border-collapse:collapse; font-size:8px; }
  table.dt th { background:#3E5C74; color:white; padding:5px 6px; text-align:left; font-size:7.5px; text-transform:uppercase; letter-spacing:0.5px; }
  table.dt td { padding:4px 6px; border-bottom:1px solid #eee; vertical-align:middle; }
  table.dt tr:nth-child(even) td { background:#f9fafb; }
  .tr { text-align:right; }
  .tc { text-align:center; }
  .mono { font-family:'DejaVu Sans Mono',monospace; font-size:7.5px; }
  .bold { font-weight:bold; }
  .muted { color:#999; }
  .red { color:#dc2626; font-weight:bold; }
  .badge { padding:1px 5px; border-radius:2px; font-size:7px; font-weight:bold; }
  .b-ok { background:#d1fae5; color:#065f46; }
  .b-tr { background:#fef3c7; color:#92400e; }
  .b-wh { background:#dbeafe; color:#1e40af; }
  .b-ex { background:#e0e7ff; color:#3730a3; }
  .b-al { background:#fee2e2; color:#991b1b; }
  .footer { padding:10px 22px; border-top:1px solid #e5e7eb; font-size:7.5px; color:#aaa; }
</style>
</head>
<body>

<div class="header">
  <table width="100%">
    <tr>
      <td>
        <div class="h-title">LAPORAN TRACEABILITY BATCH</div>
        <div class="h-sub">{{ $company->name }} &mdash; MINETRACE Mineral Traceability System</div>
        <div class="h-sub">Periode: {{ $date_from }} &mdash; {{ $date_to }}</div>
      </td>
      <td style="text-align:right; font-size:8px; opacity:0.8; vertical-align:top;">
        <div>Dicetak: {{ $generated_at }}</div>
        <div>Kode Perusahaan: {{ $company->code }}</div>
      </td>
    </tr>
  </table>
</div>

<div class="section">
  <div class="section-title">Ringkasan Laporan</div>
  <table class="kpi-wrap">
    <tr>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $total_batches }}</div>
        <div class="kpi-lbl">Total Batch</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ number_format($total_weight / 1000, 2) }}</div>
        <div class="kpi-lbl">Total Tonase (MT)</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $batches->where('has_discrepancy', true)->count() }}</div>
        <div class="kpi-lbl">Alert Mass Balance</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $batches->whereNotNull('rfid_tag')->count() }}</div>
        <div class="kpi-lbl">Batch Ter-tag RFID</div>
      </td>
      <td class="kpi-cell">
        <div class="kpi-val">{{ $batches->where('status', 'exported')->count() }}</div>
        <div class="kpi-lbl">Telah Diekspor</div>
      </td>
    </tr>
  </table>
</div>

<div class="section">
  <div class="section-title">Detail Batch</div>
  @if($batches->isEmpty())
    <p class="muted" style="font-style:italic; padding:8px 0;">Tidak ada batch pada periode yang dipilih.</p>
  @else
  <table class="dt">
    <thead>
      <tr>
        <th width="3%" class="tc">#</th>
        <th width="15%">Kode Batch</th>
        <th width="6%">Produk</th>
        <th width="8%" class="tr">Berat Awal (kg)</th>
        <th width="8%" class="tr">Berat Kini (kg)</th>
        <th width="6%" class="tr">Susut (%)</th>
        <th width="10%">Stage</th>
        <th width="9%">Status</th>
        <th width="4%" class="tc">CP</th>
        <th width="4%" class="tc">RFID</th>
        <th width="4%" class="tc">Lab</th>
        <th>Tanggal Dibuat</th>
      </tr>
    </thead>
    <tbody>
      @foreach($batches as $i => $batch)
      @php
        $sBadge = match($batch->status) {
          'active'       => 'b-ok', 'in_process'   => 'b-ok',
          'in_transit'   => 'b-tr', 'in_warehouse' => 'b-wh',
          'exported'     => 'b-ex', default        => ''
        };
        $sLabel = match($batch->status) {
          'active' => 'Aktif', 'in_process' => 'Proses',
          'in_transit' => 'Transit', 'in_warehouse' => 'Gudang',
          'exported' => 'Ekspor', 'transformed' => 'Split',
          'terminated' => 'Selesai', default => $batch->status
        };
        $stageLabel = match($batch->process_stage) {
          'lab_kimia' => 'Lab Kimia', 'receiving' => 'Receiving',
          'wet_process' => 'Wet', 'dry_process' => 'Dry',
          'project_plant' => 'Lab LTJ', 'warehouse' => 'Warehouse',
          'exported' => 'Ekspor', default => $batch->process_stage
        };
      @endphp
      <tr>
        <td class="tc muted">{{ $i + 1 }}</td>
        <td class="mono bold">{{ $batch->batch_code }}</td>
        <td class="tc bold">{{ $batch->productCode?->code ?? '—' }}</td>
        <td class="tr">{{ number_format($batch->initial_weight, 2) }}</td>
        <td class="tr {{ $batch->has_discrepancy ? 'red' : '' }}">
          {{ number_format($batch->current_weight, 2) }}
        </td>
        <td class="tr {{ $batch->has_discrepancy ? 'red' : '' }}">
          {{ $batch->mass_loss_pct ? number_format($batch->mass_loss_pct, 2) . '%' : '—' }}
        </td>
        <td>{{ $stageLabel }}</td>
        <td><span class="badge {{ $sBadge }}">{{ $sLabel }}</span></td>
        <td class="tc">{{ $batch->checkpoints->count() }}</td>
        <td class="tc">{{ $batch->rfid_tag ? '✓' : '—' }}</td>
        <td class="tc">{{ $batch->labAnalyses->where('is_validated', true)->count() > 0 ? '✓' : '—' }}</td>
        <td class="muted">{{ $batch->created_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
</div>

<div class="footer">
  <table width="100%"><tr>
    <td>MINETRACE Mineral Traceability System &mdash; {{ $generated_at }}</td>
    <td style="text-align:right;">{{ $company->name }} &mdash; Dokumen Rahasia / Confidential</td>
  </tr></table>
</div>
</body>
</html>