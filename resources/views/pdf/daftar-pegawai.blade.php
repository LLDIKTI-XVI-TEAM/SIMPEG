<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Daftar Nominatif Pegawai — LLDIKTI XVI</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 1.2cm 1.5cm 1.5cm 1.5cm;
        }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10px;
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

        /* Tabel Data Pegawai */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
            border: 1px solid #cbd5e1;
            margin-top: 6px;
        }

        table.data-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            text-align: left;
        }

        table.data-table th.text-center,
        table.data-table td.text-center {
            text-align: center;
        }

        table.data-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            font-size: 9px;
            vertical-align: middle;
        }

        table.data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .badge-status {
            display: inline-block;
            padding: 2px 6px;
            font-size: 8px;
            font-weight: bold;
            border-radius: 4px;
            background-color: #dcfce7;
            color: #166534;
        }

        .badge-status.cuti {
            background-color: #fef3c7;
            color: #92400e;
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
        <h3>Daftar Nominatif Pegawai</h3>
        <p>Tanggal Cetak: {{ now()->translatedFormat('d F Y') }}</p>
    </div>

    <!-- TABEL DATA PEGAWAI -->
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 30px;">No</th>
                <th style="width: 130px;">NIP</th>
                <th>Nama Pegawai</th>
                <th style="width: 70px;">Golongan</th>
                <th>Jabatan</th>
                <th>Unit Kerja</th>
                <th style="width: 90px;">Jenis Pegawai</th>
                <th class="text-center" style="width: 70px;">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pegawai as $index => $row)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $row['nip'] ?? $row->nip ?? '-' }}</td>
                    <td><strong>{{ $row['nama'] ?? $row->nama_lengkap ?? '-' }}</strong></td>
                    <td>{{ $row['golongan'] ?? $row->golongan_terakhir ?? '-' }}</td>
                    <td>{{ $row['jabatan'] ?? $row->jabatan_terakhir ?? '-' }}</td>
                    <td>{{ $row['unit'] ?? $row->positionHistories->first()?->unitKerja?->nama ?? '-' }}</td>
                    <td>{{ $row['jenis'] ?? $row->jenisPegawai?->nama ?? '-' }}</td>
                    <td class="text-center">
                        <span class="badge-status {{ ($row['status'] ?? $row->status_tampilan ?? 'Aktif') === 'Cuti' ? 'cuti' : '' }}">
                            {{ $row['status'] ?? $row->status_tampilan ?? 'Aktif' }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center" style="padding: 20px; color: #94a3b8;">
                        Tidak ada data pegawai yang tersedia.
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
