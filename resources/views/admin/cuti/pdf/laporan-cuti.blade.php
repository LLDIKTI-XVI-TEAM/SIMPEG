<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap Cuti Pegawai</title>
    <style>
        @page { size: A4 landscape; margin: 24px 28px 38px; }
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 8.5px; }
        .title-doc { text-align: center; margin-top: 16px; margin-bottom: 16px; }
        .title-doc h3 { font-size: 12px; margin: 0 0 4px 0; text-transform: uppercase; text-decoration: underline; letter-spacing: 1px; }
        .title-doc p { font-size: 9px; margin: 0; color: #4b5563; }
        .meta { margin-bottom: 12px; font-weight: bold; }
        table.data-table { border-collapse: collapse; table-layout: fixed; width: 100%; margin-bottom: 18px; color: #000; }
        table.data-table thead { display: table-header-group; }
        table.data-table tr { page-break-inside: avoid; }
        table.data-table th, table.data-table td { border: 1px solid #000; overflow-wrap: anywhere; padding: 5px 4px; vertical-align: top; word-break: break-word; word-wrap: break-word; color: #000; }
        table.data-table th { font-size: 8px; text-align: left; font-weight: bold; text-transform: uppercase; background-color: transparent; }
        .number { text-align: right; }
        .center { text-align: center; }
        .empty { padding: 18px; text-align: center; color: #6b7280; }
        table.signatures { width: 100%; margin-top: 36px; border: none; }
        table.signatures td { text-align: center; width: 50%; vertical-align: top; border: none; }
        .signature-space { height: 54px; }
        footer { bottom: 0; color: #6b7280; font-size: 8px; left: 0; position: fixed; right: 0; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .footer-table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .footer-table td { border: none; padding: 0; width: 50%; }
        .footer-generated { text-align: left; }
        .footer-page { text-align: right; }
        .page-number::after  { content: counter(page); }
    </style>
</head>
<body>
    <x-laporan.kop-surat :forPdf="true" />

    <div class="title-doc">
        <h3>Rekap Cuti Pegawai</h3>
        <p>Tanggal Cetak: {{ now()->translatedFormat('d F Y') }}</p>
    </div>

    <p class="meta">Periode: {{ $periodLabel ?? 'Semua periode' }}</p>

    <table class="data-table">
        <thead>
            <tr>
                <th class="center" width="20">No</th>
                <th width="100">NIP</th>
                <th>Nama Pegawai</th>
                <th width="100">Jenis Cuti</th>
                <th class="center" width="60">Total Hari</th>
                <th class="center" width="60">Sisa Saldo {{ isset($summaryRows) && $summaryRows->isNotEmpty() ? $summaryRows->first()['saldo_tahun'] ?? '' : '' }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($summaryRows ?? [] as $i => $row)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $row['nip'] }}</td>
                    <td>{{ $row['nama'] }}</td>
                    <td>{{ $row['jenis'] }}</td>
                    <td class="number">{{ $row['total_hari'] }}</td>
                    <td class="number">{{ $row['sisa_saldo'] === '-' ? '-' : $row['sisa_saldo'] }}</td>
                </tr>
            @empty
                <tr><td class="empty" colspan="6">Tidak ada data rekap cuti sesuai filter.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="meta">Detail Cuti</p>
    <table class="data-table">
        <thead>
            <tr>
                <th class="center" width="20">No</th>
                <th width="82">NIP</th>
                <th>Nama Pegawai</th>
                <th width="72">Jenis Cuti</th>
                <th width="55">Tanggal Mulai</th>
                <th width="55">Tanggal Selesai</th>
                <th class="center" width="28">Hari</th>
                <th width="62">Sumber</th>
                <th width="82">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $i => $row)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $row->nip }}</td>
                    <td>{{ $row->nama }}</td>
                    <td>{{ $row->jenis }}</td>
                    <td>{{ $row->tanggalMulai->format('d-m-Y') }}</td>
                    <td>{{ $row->tanggalSelesai->format('d-m-Y') }}</td>
                    <td class="number">{{ $row->hari }}</td>
                    <td>{{ $row->sourceLabel }}</td>
                    <td>{{ $row->statusLabel }}</td>
                </tr>
            @empty
                <tr><td class="empty" colspan="9">Tidak ada detail cuti sesuai filter.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>
                <strong>Pembuat Laporan</strong>
                <div class="signature-space"></div>
                <p>(................................)</p>
            </td>
            <td>
                <strong>Mengetahui</strong>
                <div class="signature-space"></div>
                <p>(................................)</p>
            </td>
        </tr>
    </table>

    <footer>
        <table class="footer-table">
            <tr>
                <td class="footer-generated">Dokumen dibuat pada {{ $generatedAt->format('d-m-Y H:i:s') }}</td>
                <td class="footer-page">Halaman <span class="page-number"></span></td>
            </tr>
        </table>
    </footer>
</body>
</html>
