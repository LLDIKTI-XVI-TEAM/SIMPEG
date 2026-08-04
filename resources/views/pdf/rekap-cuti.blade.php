<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekapitulasi Laporan Cuti Pegawai — LLDIKTI XVI</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 1.2cm 1.5cm 1.5cm 1.5cm;
        }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 9px;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }

        /* Kop Surat Resmi */
        .header-kop {
            width: 100%;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
            text-align: center;
        }

        .header-kop table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-kop td {
            vertical-align: middle;
            border: none;
            padding: 0;
        }

        .logo-cell {
            width: 70px;
            text-align: left;
        }

        .logo-cell img {
            width: 60px;
            height: auto;
        }

        .kop-title {
            text-align: center;
        }

        .kop-title h1 {
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 0;
            color: #0f172a;
        }

        .kop-title h2 {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 2px 0 0 0;
            color: #1e3a8a;
        }

        .kop-title p {
            font-size: 9px;
            color: #475569;
            margin: 2px 0 0 0;
        }

        .doc-title {
            text-align: center;
            margin-bottom: 14px;
        }

        .doc-title h3 {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            text-decoration: underline;
            margin: 0;
            letter-spacing: 0.5px;
        }

        .doc-title p {
            font-size: 9px;
            color: #64748b;
            margin: 3px 0 0 0;
        }

        /* Tabel Rekap Cuti Per Pegawai */
        table.rekap-table {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
            border: 1px solid #cbd5e1;
            margin-top: 6px;
        }

        table.rekap-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: bold;
            font-size: 8.5px;
            text-transform: uppercase;
            border: 1px solid #cbd5e1;
            padding: 6px 6px;
            text-align: left;
        }

        table.rekap-table th.text-center,
        table.rekap-table td.text-center {
            text-align: center;
        }

        table.rekap-table th.text-right,
        table.rekap-table td.text-right {
            text-align: right;
        }

        table.rekap-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            font-size: 8.5px;
            vertical-align: middle;
        }

        table.rekap-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
    </style>
</head>
<body>

    <!-- KOP SURAT RESMI LLDIKTI XVI -->
    <div class="header-kop">
        <table>
            <tr>
                <td class="logo-cell">
                    <img src="{{ public_path('img/dikti16-favicon-blue-150x150.png') }}" alt="Logo LLDIKTI XVI">
                </td>
                <td class="kop-title">
                    <h1>Kementerian Pendidikan Tinggi, Sains, dan Teknologi</h1>
                    <h2>Lembaga Layanan Pendidikan Tinggi (LLDIKTI) Wilayah XVI</h2>
                    <p>Jl. Prof. Dr. Aloei Saboe, Wongkaditi, Kota Gorontalo</p>
                </td>
            </tr>
        </table>
    </div>

    <!-- JUDUL DOKUMEN -->
    <div class="doc-title">
        <h3>Rekapitulasi Penggunaan & Saldo Cuti Pegawai</h3>
        <p>Periode: {{ $periodeLabel ?? now()->year }} &middot; Tanggal Cetak: {{ now()->translatedFormat('d F Y') }}</p>
    </div>

    <!-- TABEL REKAPITULASI PER PEGAWAI -->
    <table class="rekap-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 25px;">No</th>
                <th style="width: 110px;">NIP</th>
                <th>Nama Pegawai</th>
                <th style="width: 120px;">Unit Kerja</th>
                <th class="text-center" style="width: 90px;">Jenis Cuti</th>
                <th class="text-right" style="width: 60px;">Total Hari</th>
                <th class="text-right" style="width: 70px;">Sisa Saldo</th>
            </tr>
        </thead>
        <tbody>
            @forelse($summaryRows as $index => $row)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $row['nip'] ?? $row[1] ?? '-' }}</td>
                    <td><strong>{{ $row['nama'] ?? $row[2] ?? '-' }}</strong></td>
                    <td>{{ $row['unit'] ?? '-' }}</td>
                    <td class="text-center">{{ $row['jenis'] ?? $row[3] ?? '-' }}</td>
                    <td class="text-right"><strong>{{ $row['total_hari'] ?? $row[4] ?? 0 }} hari</strong></td>
                    <td class="text-right" style="color: #1e3a8a; font-weight: bold;">{{ $row['sisa_saldo'] ?? $row[5] ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center" style="padding: 20px; color: #94a3b8;">
                        Tidak ada data rekapitulasi cuti pada periode ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- FOOTER HALAMAN DOMPDF -->
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->get_font("helvetica", "normal");
            $pdf->page_text(720, 565, "Halaman {PAGE_NUM} dari {PAGE_COUNT}", $font, 8, array(0.4, 0.4, 0.4));
        }
    </script>

</body>
</html>
