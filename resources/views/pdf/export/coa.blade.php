<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.5; }

  .header { background: #3E5C74; color: white; padding: 20px 30px; margin-bottom: 20px; }
  .header-title { font-size: 18px; font-weight: bold; letter-spacing: 2px; }
  .header-sub { font-size: 11px; opacity: 0.8; margin-top: 2px; }
  .header-doc { text-align: right; }
  .header-doc .doc-num { font-size: 14px; font-weight: bold; }

  .section { padding: 0 30px; margin-bottom: 16px; }
  .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #3E5C74; border-bottom: 1.5px solid #3E5C74; padding-bottom: 4px; margin-bottom: 10px; }

  .info-grid { display: flex; gap: 20px; }
  .info-col { flex: 1; }
  .info-row { margin-bottom: 5px; display: flex; gap: 8px; }
  .info-label { color: #666; width: 140px; flex-shrink: 0; }
  .info-value { font-weight: bold; }

  table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
  th { background: #3E5C74; color: white; padding: 7px 8px; text-align: center; font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
  td { padding: 6px 8px; border-bottom: 1px solid #e8e8e8; }
  tr:nth-child(even) td { background: #f8f9fa; }
  .text-center { text-align: center; }
  .text-right { text-align: right; }
  .font-bold { font-weight: bold; }

  .total-row td { background: #EEF4F8 !important; font-weight: bold; border-top: 1.5px solid #3E5C74; }

  .footer { padding: 20px 30px; margin-top: 30px; border-top: 1px solid #ddd; display: flex; justify-content: space-between; align-items: flex-end; }
  .signature-box { text-align: center; }
  .signature-line { border-top: 1px solid #333; width: 180px; margin: 40px auto 6px; }
  .watermark { color: #28a745; font-weight: bold; font-size: 11px; }

  .badge-radio { background: #f3e8ff; color: #6b21a8; padding: 2px 6px; border-radius: 3px; font-size: 8px; font-weight: bold; }
</style>
</head>
<body>

  <!-- Header -->
  <div class="header">
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
      <div>
        <div class="header-title">CERTIFICATE OF ANALYSIS</div>
        <div class="header-sub">{{ $company->name }} — Mineral Traceability System (MINETRACE)</div>
        <div class="header-sub" style="margin-top:4px;">{{ $company->address ?? '' }}, {{ $company->city ?? '' }}</div>
      </div>
      <div class="header-doc">
        <div style="font-size:9px; opacity:0.7; margin-bottom:2px;">NOMOR DOKUMEN</div>
        <div class="doc-num">{{ $coa_number }}</div>
        <div style="font-size:9px; opacity:0.7; margin-top:4px;">TANGGAL</div>
        <div style="font-weight:bold;">{{ $date }}</div>
      </div>
    </div>
  </div>

  <!-- Export Info -->
  <div class="section">
    <div class="section-title">Informasi Ekspor</div>
    <div class="info-grid">
      <div class="info-col">
        <div class="info-row">
          <span class="info-label">Tipe Ekspor</span>
          <span class="info-value">{{ $export->export_type === 'international' ? 'Ekspor Internasional' : 'Penjualan Domestik' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">Perusahaan Tujuan</span>
          <span class="info-value">{{ $export->destination_company }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">Negara Tujuan</span>
          <span class="info-value">{{ $export->destination_country ?? 'Indonesia' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">Total Berat</span>
          <span class="info-value">{{ number_format($export->total_weight_kg, 2) }} kg</span>
        </div>
      </div>
      <div class="info-col">
        <div class="info-row">
          <span class="info-label">No. Invoice</span>
          <span class="info-value">{{ $export->invoice_number ?? '-' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">No. Manifest</span>
          <span class="info-value">{{ $export->manifest_number ?? '-' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">Pelabuhan Muat</span>
          <span class="info-value">{{ $export->port_of_loading ?? '-' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">Tanggal Ekspor</span>
          <span class="info-value">{{ $export->exported_at ? $export->exported_at->format('d F Y') : $date }}</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Lab Analysis Results -->
  <div class="section">
    <div class="section-title">Hasil Analisis Kimia — Parameter LTJ</div>
    @if(count($lab_data) > 0)
    <table>
      <thead>
        <tr>
          <th>Kode Batch</th>
          <th>Produk</th>
          <th>Berat (kg)</th>
          <th>La (%)</th>
          <th>Ce (%)</th>
          <th>Pr (%)</th>
          <th>Nd (%)</th>
          <th>Y (%)</th>
          <th>Total REO (%)</th>
          <th>ThO₂ (%)</th>
          <th>Metode</th>
        </tr>
      </thead>
      <tbody>
        @foreach($lab_data as $row)
        <tr>
          <td class="font-bold" style="font-family: monospace;">{{ $row['batch_code'] }}</td>
          <td class="text-center">{{ $row['product_code'] }}</td>
          <td class="text-right">{{ number_format($row['weight_kg'], 2) }}</td>
          <td class="text-center">{{ $row['la_pct'] ?? '-' }}</td>
          <td class="text-center">{{ $row['ce_pct'] ?? '-' }}</td>
          <td class="text-center">{{ $row['pr_pct'] ?? '-' }}</td>
          <td class="text-center">{{ $row['nd_pct'] ?? '-' }}</td>
          <td class="text-center">{{ $row['y_pct'] ?? '-' }}</td>
          <td class="text-center font-bold">{{ $row['total_reo'] ?? '-' }}</td>
          <td class="text-center">
            @if($row['tho2_pct'])
              <span class="badge-radio">{{ $row['tho2_pct'] }}</span>
            @else — @endif
          </td>
          <td class="text-center">{{ $row['method'] ?? '-' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
    @else
    <p style="color:#999; font-style:italic; padding: 10px 0;">Hasil analisis lab tidak tersedia untuk dokumen ini.</p>
    @endif
  </div>

  <!-- Batch List -->
  <div class="section">
    <div class="section-title">Daftar Batch dalam Ekspor Ini</div>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Kode Batch</th>
          <th>Kode Produk</th>
          <th>Nama Material</th>
          <th>Berat (kg)</th>
          <th>Stage</th>
        </tr>
      </thead>
      <tbody>
        @foreach($batches as $i => $batch)
        <tr>
          <td class="text-center">{{ $i + 1 }}</td>
          <td class="font-bold" style="font-family: monospace;">{{ $batch->batch_code }}</td>
          <td class="text-center">{{ $batch->productCode?->code }}</td>
          <td>{{ $batch->productCode?->name }}</td>
          <td class="text-right">{{ number_format($batch->current_weight, 2) }}</td>
          <td class="text-center">{{ ucfirst(str_replace('_', ' ', $batch->process_stage)) }}</td>
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

  <!-- Footer Signature -->
  <div class="footer">
    <div>
      <p style="font-size:9px; color:#666;">Dokumen ini digenerate secara otomatis oleh sistem MINETRACE.</p>
      <p style="font-size:9px; color:#666;">Untuk verifikasi, hubungi: {{ $company->email ?? config('mail.from.address') }}</p>
    </div>
    <div style="display:flex; gap:40px;">
      <div class="signature-box">
        <div class="signature-line"></div>
        <div style="font-size:9px;">Kepala Divisi Produksi</div>
        <div style="font-size:9px; color:#666;">{{ $company->name }}</div>
      </div>
      <div class="signature-box">
        <div class="signature-line"></div>
        <div style="font-size:9px;">Kepala QC / Lab</div>
        <div style="font-size:9px; color:#666;">{{ $company->name }}</div>
      </div>
    </div>
  </div>

</body>
</html>