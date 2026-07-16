<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap Cuti Pegawai</title>
    <style>
        @page { margin: 28px 32px 42px; }
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        header { border-bottom: 2px solid #1e3a8a; margin-bottom: 16px; padding-bottom: 10px; text-align: center; }
        header p, header h1 { margin: 2px 0; }
        h1 { font-size: 16px; text-transform: uppercase; }
        .meta { margin-bottom: 12px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #9ca3af; padding: 5px 4px; vertical-align: top; }
        th { background: #dbeafe; font-size: 8px; text-align: left; }
        .number { text-align: right; }
        .empty { padding: 18px; text-align: center; }
        .signatures { margin-top: 36px; width: 100%; }
        .signature { display: inline-block; text-align: center; vertical-align: top; width: 48%; }
        .signature-space { height: 54px; }
        footer { bottom: -24px; color: #6b7280; font-size: 8px; left: 0; position: fixed; right: 0; text-align: center; }
    </style>
</head>
<body>
    <header>
        <p>LEMBAGA LAYANAN PENDIDIKAN TINGGI WILAYAH XVI</p>
        <h1>Rekap Cuti Pegawai</h1>
    </header>

    <p class="meta">Periode: {{ $filters['periode'] ?? 'Semua periode' }}</p>
    <table>
        <thead>
            <tr>
                <th>No</th><th>NIP</th><th>Nama</th><th>Jenis</th><th>Mulai</th><th>Selesai</th><th>Hari Kerja</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $row->employee?->nip ?? '-' }}</td>
                    <td>{{ $row->employee?->nama_lengkap ?? '-' }}</td>
                    <td>{{ $row->jenisCuti?->nama ?? '-' }}</td>
                    <td>{{ $row->tanggal_mulai?->format('Y-m-d') ?? '-' }}</td>
                    <td>{{ $row->tanggal_selesai?->format('Y-m-d') ?? '-' }}</td>
                    <td class="number">{{ $row->jumlah_hari_kerja }}</td>
                    <td>{{ $row->report_status }}</td>
                </tr>
            @empty
                <tr><td class="empty" colspan="8">Tidak ada data cuti sesuai filter.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="signatures">
        <div class="signature"><strong>Pembuat Laporan</strong><div class="signature-space"></div><p>(................................)</p></div>
        <div class="signature"><strong>Mengetahui</strong><div class="signature-space"></div><p>(................................)</p></div>
    </div>
    <footer>Dokumen dibuat pada {{ $generatedAt->format('d-m-Y H:i:s') }}</footer>
</body>
</html>
