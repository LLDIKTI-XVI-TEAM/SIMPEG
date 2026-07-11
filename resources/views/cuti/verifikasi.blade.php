<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifikasi Bukti Cuti | SIMPEG</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            margin: 0;
            padding: 24px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .container {
            width: 100%;
            max-width: 680px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 32px;
            box-sizing: border-box;
        }
        header {
            text-align: center;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .inst-title {
            font-size: 14px;
            font-weight: 700;
            color: #122e92;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin: 0 0 4px 0;
        }
        .main-title {
            font-size: 20px;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }
        .status-badge {
            display: inline-block;
            background-color: #dcfce7;
            color: #15803d;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 9999px;
            margin-top: 12px;
        }
        .qr-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin: 24px 0;
            padding: 16px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px dashed #cbd5e1;
        }
        .qr-code {
            max-width: 180px;
            margin-bottom: 12px;
        }
        .qr-code svg {
            width: 100%;
            height: auto;
        }
        .verify-url {
            font-size: 12px;
            color: #122e92;
            text-decoration: none;
            word-break: break-all;
            text-align: center;
            font-weight: 500;
        }
        .verify-url:hover {
            text-decoration: underline;
        }
        h3 {
            font-size: 14px;
            font-weight: 600;
            color: #475569;
            margin: 24px 0 12px 0;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        dl {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin: 0 0 24px 0;
        }
        @media(min-width: 480px) {
            dl {
                grid-template-columns: 200px 1fr;
            }
        }
        dt {
            font-size: 14px;
            font-weight: 500;
            color: #64748b;
        }
        dd {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }
        .timeline {
            margin: 0;
            padding: 0 0 0 20px;
        }
        .timeline-item {
            position: relative;
            padding-bottom: 16px;
            list-style: none;
        }
        .timeline-item::before {
            content: "";
            position: absolute;
            left: -16px;
            top: 6px;
            width: 8px;
            height: 8px;
            background: #cbd5e1;
            border-radius: 50%;
        }
        .timeline-item.approved::before {
            background: #10b981;
        }
        .timeline-item:not(:last-child)::after {
            content: "";
            position: absolute;
            left: -13px;
            top: 14px;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-role {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            margin: 0;
        }
        .timeline-name {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            margin: 2px 0 0 0;
        }
        .timeline-meta {
            font-size: 11px;
            color: #64748b;
            margin: 2px 0 0 0;
        }
        .print-btn-container {
            display: flex;
            justify-content: center;
            margin-top: 32px;
        }
        .print-btn {
            background-color: #122e92;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }
        .print-btn:hover {
            background-color: #0c1f65;
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        @media print {
            body {
                background-color: #ffffff;
                padding: 0;
                color: #000000;
            }
            .container {
                border: none;
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }
            .print-btn-container {
                display: none;
            }
        }
    </style>
</head>
<body>
    <main class="container">
        <header>
            <p class="inst-title">{{ $verification['institution'] ?? 'LLDIKTI Wilayah XVI' }}</p>
            <h1 class="main-title">Verifikasi Bukti Cuti Kepegawaian</h1>
            <span class="status-badge">{{ $verification['status_label'] ?? 'Disetujui' }}</span>
        </header>

        <section aria-labelledby="detail-pegawai-heading">
            <h2 id="detail-pegawai-heading" class="sr-only">Detail Pegawai</h2>
            <dl>
                <dt>Nama Pegawai</dt>
                <dd>{{ $verification['employee_name'] ?? '-' }}</dd>

                <dt>Jenis Cuti</dt>
                <dd>{{ $verification['leave_type'] ?? '-' }}</dd>

                <dt>Mulai Cuti</dt>
                <dd>{{ $verification['start_date_label'] ?? '-' }}</dd>

                <dt>Selesai Cuti</dt>
                <dd>{{ $verification['end_date_label'] ?? '-' }}</dd>

                <dt>Jumlah Hari Kerja</dt>
                <dd>{{ $verification['workday_count'] ?? 0 }} Hari</dd>

                <dt>Persetujuan Akhir Oleh</dt>
                <dd>
                    @if(!empty($verification['final_approver']['name']) && $verification['final_approver']['name'] !== '-')
                        {{ $verification['final_approver']['name'] }} ({{ $verification['final_approver']['role'] ?? '-' }})
                    @else
                        -
                    @endif
                </dd>

                <dt>Waktu Diterbitkan</dt>
                <dd>{{ $verification['generated_at_label'] ?? '-' }}</dd>
            </dl>
        </section>

        @if(!empty($verification['approval_timeline']))
            <section aria-labelledby="timeline-heading">
                <h3 id="timeline-heading">Linimasa Persetujuan</h3>
                <ol class="timeline">
                    @foreach($verification['approval_timeline'] as $step)
                        <li class="timeline-item {{ ($step['status'] ?? '') === 'approved' ? 'approved' : '' }}">
                            <p class="timeline-role">{{ $step['role'] ?? '-' }}</p>
                            <p class="timeline-name">{{ $step['approver_name'] ?? '-' }}</p>
                            <p class="timeline-meta">
                                Status: {{ $step['status_label'] ?? '-' }}
                                @if(!empty($step['acted_at_label']) && $step['acted_at_label'] !== '-')
                                    • {{ $step['acted_at_label'] }}
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        <div class="qr-section">
            <div class="qr-code" role="img" aria-label="QR Code Verifikasi Bukti Cuti Publik">
                {!! $qrSvg !!}
            </div>
            <a href="{{ $verificationUrl }}" class="verify-url" target="_blank" rel="noopener noreferrer">
                {{ $verificationUrl }}
            </a>
        </div>

        <div class="print-btn-container">
            <button onclick="window.print()" class="print-btn" type="button">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true" focusable="false">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.89l-2.07 1.24A2.25 2.25 0 003 17.06v2.83c0 .85.63 1.57 1.48 1.69a48.57 48.57 0 0015.04 0c.85-.12 1.48-.84 1.48-1.69v-2.83a2.25 2.25 0 00-1.65-2.18l-2.07-1.24M15 3.75H9m6 0a2.25 2.25 0 00-2.25-2.25h-1.5A2.25 2.25 0 009 3.75m6 0V14.25a2.25 2.25 0 01-2.25 2.25h-3.5A2.25 2.25 0 017 14.25V3.75m3.75 6.75h2.5" />
                </svg>
                Cetak Dokumen
            </button>
        </div>
    </main>
</body>
</html>
