<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Daftar Pegawai</title>
    <style>
        /* Margin bawah disisihkan agar footer bernomor halaman tidak menimpa baris tabel. */
        @page { margin: 28px 32px 42px; }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #111827; /* ink */
        }
        .report-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .report-title {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            text-decoration: underline;
            margin-bottom: 4px;
        }
        .report-date {
            font-size: 10px;
            color: #6b7280;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            color: #000;
        }
        .data-table th, .data-table td {
            border: 1px solid #000;
            padding: 6px 5px;
            text-align: left;
            vertical-align: top;
            color: #000;
        }
        .data-table th {
            background-color: transparent;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9px;
        }
        /* Footer dicetak ulang pada setiap halaman oleh DOMPDF melalui position: fixed.
           Penomoran memakai counter CSS karena eksekusi PHP di dalam template PDF dimatikan. */
        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            border-top: 1px solid #e5e7eb;
            padding-top: 6px;
            color: #6b7280;
            font-size: 8px;
            text-align: center;
        }
        .page-number::after { content: counter(page); }
        .total-pages::after { content: counter(pages); }
    </style>
</head>
<body>

    <x-laporan.kop-surat :forPdf="true" />

    <div class="report-header">
        <div class="report-title">Daftar Pegawai</div>
        <div class="report-date">Tanggal Cetak: {{ now()->translatedFormat('d F Y') }}</div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 4%; text-align: center;">No</th>
                <th style="width: 14%;">NIP</th>
                <th style="width: 20%;">Nama Pegawai</th>
                <th style="width: 8%;">Golongan</th>
                <th style="width: 20%;">Jabatan</th>
                <th style="width: 16%;">Unit Kerja</th>
                <th style="width: 10%;">Jenis</th>
                <th style="width: 8%; text-align: center;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $index => $row)
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td>{{ $row['nip'] ?? '-' }}</td>
                <td>{{ $row['nama'] ?? '-' }}</td>
                <td>{{ $row['golongan'] ?? '-' }}</td>
                <td>{{ $row['jabatan'] ?? '-' }}</td>
                <td>{{ $row['unit'] ?? '-' }}</td>
                <td>{{ $row['jenis'] ?? '-' }}</td>
                <td style="text-align: center;">{{ $row['status'] ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <footer>
        Halaman <span class="page-number"></span> dari <span class="total-pages"></span>
    </footer>

</body>
</html>
