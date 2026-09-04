<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Formulir Cuti</title>
    <style>
        @page { margin: 8mm 12mm; }
        * { box-sizing: border-box; }
        body { color: #000; font-family: Times, serif; font-size: 7.5pt; line-height: 1.1; }
        h1, h2, p { margin: 0; }
        .document-header { margin-bottom: 5pt; min-height: 50pt; position: relative; }
        .document-header h1, .document-header h2 { padding-right: 53%; text-align: center; }
        .document-header h1 { font-size: 11pt; line-height: 1.25; text-transform: uppercase; }
        .document-header h2 { font-size: 10pt; line-height: 1.25; text-transform: uppercase; }
        .header-details { font-size: 7.5pt; line-height: 1.05; margin: 0; position: absolute; right: 0; top: 0; width: 53%; }
        .header-details .spacer { width: 47%; }
        .header-details .addressee { text-align: left; width: 53%; }
        .header-details p { margin: 0; }
        .section { margin-top: 3pt; }
        .section-title { border: 0.7pt solid #000; font-size: 8pt; font-weight: bold; padding: 2pt 3pt; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        td, th { overflow-wrap: break-word; vertical-align: top; word-break: break-word; }
        .form-table td, .form-table th, .approval-table td, .approval-table th { border: 0.7pt solid #000; padding: 2pt 3pt; }
        .label { width: 25%; }
        .colon { width: 3%; text-align: center; }
        .check-cell { width: 33.333%; }
        .balance-label { width: 70%; }
        .approval-table th { font-weight: bold; text-align: center; }
        .approval-table .order { width: 5%; text-align: center; }
        .approval-table .position { width: 19%; }
        .approval-table .role { width: 13%; }
        .approval-table .decision { width: 12%; }
        .approval-table .note { width: 20%; }
        .approval-table .time { width: 14%; }
        .form-table tr, .approval-table tr { page-break-inside: avoid; }
        .official-block { border: 0.7pt solid #000; margin-top: 3pt; page-break-inside: avoid; padding: 3pt; }
        .official-block td { vertical-align: middle; }
        .official-details { width: 72%; }
        .qr-cell { text-align: center; width: 28%; }
        .qr-cell img { height: 25mm; width: 25mm; }
        .balance-notes { font-size: 7pt; line-height: 1.1; margin-top: 2pt; }
        .verification-url { font-size: 6.5pt; overflow-wrap: break-word; word-break: break-word; }
        .statement { font-size: 7pt; margin-top: 4pt; }
    </style>
</head>
<body>
    <header class="document-header">
        <h1>{{ $institution }}</h1>
        <h2>FORMULIR PERMINTAAN DAN PEMBERIAN CUTI</h2>
        <table class="header-details">
            <tr>
                <td class="spacer"></td>
                <td class="addressee">
                    <p>{{ $issuePlace }}, {{ $issueDateLabel }}</p>
                    <p>{{ 'Kepada :' }}</p>
                    <p>{{ 'Yth. Kepala Lembaga Layanan Pendidikan Tinggi Wilayah XVI' }}</p>
                    <p>{{ 'di-' }}</p>
                    <p>{{ 'Tempat' }}</p>
                </td>
            </tr>
        </table>
    </header>

    <section class="section">
        <h2 class="section-title">I. DATA PEGAWAI</h2>
        <table class="form-table">
            <tr><td class="label">Nama</td><td class="colon">:</td><td>{{ $employeeName }}</td></tr>
            <tr><td class="label">NIP</td><td class="colon">:</td><td>{{ $employeeNip }}</td></tr>
            <tr><td class="label">Jabatan</td><td class="colon">:</td><td>{{ $employeePosition }}</td></tr>
            <tr><td class="label">Masa Kerja</td><td class="colon">:</td><td>{{ $employeeServiceLength }}</td></tr>
            <tr><td class="label">Unit Kerja</td><td class="colon">:</td><td>{{ $employeeUnit }}</td></tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">II. JENIS CUTI YANG DIAMBIL</h2>
        <table class="form-table">
            <tr>
                <td class="check-cell">@if ($leaveTypeCode === 'tahunan')[x]@else[ ]@endif Cuti Tahunan</td>
                <td class="check-cell">@if ($leaveTypeCode === 'besar')[x]@else[ ]@endif Cuti Besar</td>
                <td class="check-cell">@if ($leaveTypeCode === 'sakit')[x]@else[ ]@endif Cuti Sakit</td>
            </tr>
            <tr>
                <td class="check-cell">@if ($leaveTypeCode === 'melahirkan')[x]@else[ ]@endif Cuti Melahirkan</td>
                <td class="check-cell">@if ($leaveTypeCode === 'alasan_penting')[x]@else[ ]@endif Cuti Karena Alasan Penting</td>
                <td class="check-cell">@if ($leaveTypeCode === 'cltn')[x]@else[ ]@endif Cuti di Luar Tanggungan Negara</td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">III. ALASAN CUTI</h2>
        <table class="form-table"><tr><td>{{ $reason }}</td></tr></table>
    </section>

    <section class="section">
        <h2 class="section-title">IV. LAMANYA CUTI</h2>
        <table class="form-table">
            <tr><td class="label">Lama Cuti</td><td class="colon">:</td><td>{{ $workdayCount }} hari kerja</td></tr>
            <tr><td class="label">Tanggal Mulai</td><td class="colon">:</td><td>{{ $startDateLabel }}</td></tr>
            <tr><td class="label">Tanggal Selesai</td><td class="colon">:</td><td>{{ $endDateLabel }}</td></tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">V. CATATAN CUTI</h2>
        <table class="form-table">
            <tr><td class="balance-label">Sisa Cuti Tahunan N-2</td><td>{{ $balanceN2 }}</td></tr>
            <tr><td class="balance-label">Sisa Cuti Tahunan N-1</td><td>{{ $balanceN1 }}</td></tr>
            <tr><td class="balance-label">Sisa Cuti Tahunan N</td><td>{{ $balanceN }}</td></tr>
        </table>
        <p class="balance-notes">{{ 'N = Cuti tahun berjalan' }}; {{ 'N-1 = Sisa cuti 1 tahun sebelumnya' }}; {{ 'N-2 = Sisa cuti 2 tahun sebelumnya' }}</p>
    </section>

    <section class="section">
        <h2 class="section-title">VI. ALAMAT SELAMA MENJALANKAN CUTI</h2>
        <table class="form-table">
            <tr><td class="label">Alamat</td><td class="colon">:</td><td>{{ $addressDuringLeave }}</td></tr>
            <tr><td class="label">Nomor Telepon</td><td class="colon">:</td><td>{{ $phoneDuringLeave }}</td></tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">VII. PERTIMBANGAN ATASAN LANGSUNG DAN KEPUTUSAN PEJABAT BERWENANG</h2>
        <table class="approval-table">
            <thead>
                <tr><th class="order">No.</th><th>Nama</th><th class="position">Jabatan</th><th class="role">Peran</th><th class="decision">Keputusan</th><th class="note">Catatan</th><th class="time">Waktu</th></tr>
            </thead>
            <tbody>
                @foreach ($steps as $step)
                    <tr>
                        <td class="order">{{ $step['order'] }}</td>
                        <td>{{ $step['approver'] }}</td>
                        <td>{{ $step['position'] }}</td>
                        <td>{{ $step['role'] }}</td>
                        <td>{{ $step['statusLabel'] }}</td>
                        <td>{{ $step['note'] }}</td>
                        <td>{{ $step['actedAtLabel'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="official-block">
            <table>
                <tr>
                    <td class="official-details">
                        <p><strong>Keputusan Pejabat Berwenang:</strong> {{ $finalDecisionLabel }}</p>
                        <p>Nama: {{ $finalApproverName }}</p>
                        <p>Jabatan: {{ $finalApproverPosition }}</p>
                        <p>Peran: {{ $finalApproverRole }}</p>
                        <p>Waktu keputusan: {{ $finalActedAtLabel }}</p>
                        <p>Dokumen diterbitkan: {{ $issueDateTimeLabel }}</p>
                        <p class="verification-url">URL verifikasi: {{ $verificationUrl }}</p>
                    </td>
                    <td class="qr-cell"><img src="{{ $qrDataUri }}"></td>
                </tr>
            </table>
            <p class="statement">QR memverifikasi alur persetujuan elektronik lengkap dan data dokumen pada URL kanonis. QR bukan tanda tangan elektronik tersertifikasi.</p>
        </div>
    </section>
</body>
</html>
