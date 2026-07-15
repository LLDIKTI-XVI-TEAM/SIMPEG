<!doctype html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Dokumen Verifikasi Cuti SIMPEG</title>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
            h1 { font-size: 16px; margin: 0 0 4px; }
            h2 { font-size: 12px; margin: 0 0 16px; font-weight: normal; }
            table { width: 100%; border-collapse: collapse; margin-top: 16px; }
            th, td { border: 1px solid #111827; padding: 8px; text-align: left; vertical-align: top; }
            th { width: 35%; background: #f3f4f6; }
            .footer { margin-top: 24px; font-size: 9px; color: #4b5563; }
            .qr { margin-top: 24px; text-align: center; }
            .qr img { width: 150px; height: 150px; }
        </style>
    </head>
    <body>
        <h1>LLDIKTI Wilayah XVI</h1>
        <h2>Dokumen Verifikasi Cuti SIMPEG</h2>

        <table>
            <tr><th>Nama Pegawai</th><td>{{ $leave->employee?->nama_lengkap ?? '-' }}</td></tr>
            <tr><th>Jenis Cuti</th><td>{{ $leave->jenisCuti?->nama ?? '-' }}</td></tr>
            <tr><th>Tanggal Cuti</th><td>{{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }} s.d. {{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</td></tr>
            <tr><th>Jumlah Hari Kerja</th><td>{{ $leave->jumlah_hari_kerja }} hari kerja</td></tr>
            <tr><th>Status Keputusan</th><td>Disetujui</td></tr>
            <tr><th>Tanggal Keputusan</th><td>{{ $decisionAt?->translatedFormat('d M Y H:i') ?? $proof->generated_at?->translatedFormat('d M Y H:i') ?? '-' }}</td></tr>
            <tr><th>Pejabat Final</th><td>{{ $finalApprover?->nama_lengkap ?? '-' }}</td></tr>
        </table>

        <div class="qr">
            <img src="{{ $qrDataUri }}" alt="Kode QR verifikasi dokumen cuti">
            <p>Scan kode QR atau buka {{ $verificationUrl }}</p>
        </div>

        <p class="footer">Dokumen ini adalah dokumen verifikasi cuti SIMPEG. Template formulir resmi LLDIKTI belum diterapkan pada dokumen ini.</p>
    </body>
</html>
