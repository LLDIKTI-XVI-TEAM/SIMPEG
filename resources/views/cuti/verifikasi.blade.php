<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifikasi Bukti Cuti | SIMPEG</title>
    <link rel="icon" href="{{ asset('img/dikti16-favicon-blue-150x150.png') }}">
    <style>
        :root {
            color-scheme: light;
            --navy: #142d87;
            --ink: #17243b;
            --muted: #607087;
            --line: #e1e6ed;
            --green: #126448;
            --amber: #875012;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef1f6;
            color: var(--ink);
            font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        h1, h2, p, dl, dd { margin: 0; }
        button, a { -webkit-tap-highlight-color: transparent; }
        button { font: inherit; cursor: pointer; }
        button:focus-visible, a:focus-visible { outline: 3px solid #7199ed; outline-offset: 4px; }
        .stage { padding: 30px 24px 48px; }
        .document {
            max-width: 872px;
            margin: auto;
            background: #fff;
            border: 1px solid #d9e0ea;
            box-shadow: 0 8px 32px #1f335108;
            border-radius: 12px;
            overflow: hidden;
        }
        .brandbar { padding: 28px 40px; border-top: 4px solid var(--navy); border-bottom: 1px solid var(--line); }
        .brand { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .brand img { display: block; width: 52px; height: 52px; border-radius: 10px; flex-shrink: 0; }
        .brand-name { font-size: 17px; font-weight: 750; letter-spacing: -.02em; color: var(--navy); }
        .brand-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .title-area { padding: 30px 40px 22px; }
        .eyebrow { font-size: 10px; letter-spacing: .15em; font-weight: 750; color: var(--muted); margin-bottom: 8px; }
        h1 { font-size: 28px; letter-spacing: -.035em; line-height: 1.28; font-weight: 700; }
        .intro { font-size: 14px; color: var(--muted); margin-top: 10px; max-width: 620px; }
        .status-panel {
            margin: 0 40px 28px;
            padding: 18px 0;
            border-block: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            gap: 24px;
            align-items: flex-start;
        }
        .status-copy { flex: 1; min-width: 0; }
        .status-title { font-size: 18px; font-weight: 650; line-height: 1.4; color: var(--green); }
        .status-description { font-size: 13px; color: var(--muted); margin-top: 5px; max-width: 490px; }
        .status-date { font-size: 12px; min-width: 138px; color: var(--muted); padding-top: 2px; }
        .status-date dt { font-size: 11px; margin-bottom: 2px; }
        .status-date dd { font-size: 12px; font-weight: 650; }
        .postponed .status-title { color: var(--amber); }
        .invalid .status-title { color: var(--ink); }
        .content-layout { padding: 0 40px; }
        .section-heading { font-size: 12px; font-weight: 750; letter-spacing: .055em; color: #42556e; text-transform: uppercase; }
        .employee-name { font-size: 23px; letter-spacing: -.025em; line-height: 1.35; font-weight: 650; margin: 10px 0 22px; }
        .details { display: grid; grid-template-columns: 1fr 1.5fr .7fr; gap: 24px; padding-bottom: 25px; border-bottom: 1px solid var(--line); }
        dt { font-size: 12px; color: var(--muted); margin-bottom: 5px; }
        dd { font-size: 14px; font-weight: 600; line-height: 1.55; }
        .duration-number { font-size: 20px; font-weight: 700; letter-spacing: -.03em; }
        .duration-number span { font-size: 13px; letter-spacing: 0; font-weight: 500; }
        .history { padding-top: 26px; }
        .history-intro { font-size: 12px; color: var(--muted); margin-top: 5px; }
        .timeline { list-style: none; padding: 0; margin: 18px 0 0; }
        .timeline li { position: relative; display: grid; grid-template-columns: 28px minmax(0,1fr) auto; gap: 12px; padding: 0 0 22px; }
        .timeline li:last-child { padding-bottom: 0; }
        .timeline li:not(:last-child)::before { content: ""; position: absolute; left: 13px; top: 27px; bottom: 1px; width: 1px; background: #dbe3ef; }
        .step-number { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; background: #f2f5fa; border: 1px solid #e3e9f1; font-size: 10px; font-weight: 700; color: #5e7089; }
        .step-role { font-size: 11px; color: var(--muted); line-height: 1.3; }
        .step-name { font-size: 14px; font-weight: 600; margin-top: 3px; }
        .step-meta { text-align: right; font-size: 12px; color: var(--muted); }
        .step-meta strong { display: block; font-size: 11px; font-weight: 600; margin-bottom: 2px; }
        .approved .step-meta strong { color: var(--green); }
        .verification-panel { border-top: 1px solid var(--line); margin-top: 28px; padding: 25px 0 27px; display: grid; grid-template-columns: 108px minmax(0,1fr) auto; gap: 24px; align-items: center; }
        .qr-code { width: 108px; height: 108px; background: #fff; padding: 5px; border: 1px solid var(--line); border-radius: 6px; }
        .qr-code svg { display: block; width: 100%; height: 100%; }
        .verification-copy h2 { font-size: 13px; font-weight: 650; margin-bottom: 4px; }
        .verification-copy p { font-size: 12px; color: var(--muted); max-width: 330px; }
        .issued { font-size: 11px; color: var(--muted); margin-top: 11px; }
        .issued dd { color: #43546d; font-size: 12px; font-weight: 550; margin-top: 2px; }
        .issued dt { font-size: 11px; margin: 11px 0 0; }
        .actions { display: flex; flex-direction: column; gap: 8px; }
        .primary-btn, .secondary-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 17px; border-radius: 8px; text-decoration: none; font-size: 12px; font-weight: 650; line-height: 1.35; border: 1px solid transparent; }
        .primary-btn { background: var(--navy); color: #fff; }
        .primary-btn:hover { background: #0d226b; }
        .secondary-btn { background: #fff; color: var(--navy); border-color: #dce3ec; }
        .secondary-btn:hover { background: #f1f5fb; }
        .document-footer { border-top: 1px solid var(--line); padding: 16px 40px; background: #fafbfd; display: flex; gap: 16px; justify-content: space-between; font-size: 11px; color: var(--muted); }
        .document-footer strong { font-weight: 600; color: #4a5c74; }
        .brand-name, .employee-name, dd, .step-name, .step-role, .status-title { overflow-wrap: anywhere; }
        @media (max-width: 850px) {
            .verification-panel { grid-template-columns: 108px minmax(0,1fr); }
            .actions { grid-column: 1/-1; flex-direction: row; }
            .status-panel { flex-direction: column; gap: 12px; }
        }
        @media (max-width: 680px) {
            .stage { padding: 20px 12px 28px; }
            .brandbar { padding: 22px; }
            .brand { gap: 10px; }
            .brand img { width: 44px; height: 44px; border-radius: 8px; }
            .brand-name { font-size: 14px; }
            .brand-sub { font-size: 10px; line-height: 1.5; }
            .title-area { padding: 24px 22px 20px; }
            h1 { font-size: 26px; }
            .intro { font-size: 13px; }
            .status-panel { margin: 0 22px 24px; padding: 16px 0; }
            .status-description { margin-top: 6px; }
            .content-layout { padding: 0 22px; }
            .employee-name { font-size: 21px; }
            .details { grid-template-columns: 1fr 1fr; gap: 18px; }
            .details > div:nth-child(2) { grid-column: 1/-1; grid-row: 2; }
            .details > div:last-child { grid-column: 2; grid-row: 1; }
            .history { padding-top: 22px; }
            .timeline li { grid-template-columns: 28px minmax(0,1fr); gap: 9px 12px; padding-bottom: 20px; }
            .step-meta { text-align: left; grid-column: 2; }
            .step-meta strong { display: inline; margin-right: 8px; }
            .verification-panel { gap: 18px; grid-template-columns: 96px minmax(0,1fr); margin-top: 22px; padding-top: 22px; }
            .qr-code { width: 96px; height: 96px; }
            .verification-copy p, .issued dd { font-size: 11px; }
            .actions { flex-wrap: wrap; }
            .primary-btn, .secondary-btn { flex: 1; padding-inline: 10px; }
            .document-footer { padding: 16px 22px; display: block; font-size: 10px; }
            .document-footer span { display: block; margin-top: 3px; }
        }
        @media print {
            body { background: #fff; font-size: 12px; }
            .stage { padding: 0; }
            .document { max-width: none; border: 0; box-shadow: none; overflow: visible; }
            .brandbar { padding: 16px 0; }
            .title-area { padding: 22px 0; }
            .status-panel { margin: 0 0 24px; break-inside: avoid; }
            .content-layout { padding: 0; }
            .verification-panel { grid-template-columns: 100px minmax(0,1fr); padding: 20px 0; margin-top: 22px; border-radius: 0; }
            .qr-code { width: 96px; height: 96px; }
            .actions { display: none; }
            .document-footer { padding: 14px 0; }
            .timeline li, .details, .verification-panel { break-inside: avoid; }
        }
    </style>
</head>
<body class="{{ !isset($verification) ? 'invalid' : (!empty($verification['is_administratively_postponed']) ? 'postponed' : '') }}">
    <div class="stage">
        <main class="document">
            <header class="brandbar">
                <div class="brand">
                    <img src="{{ asset('img/dikti16-favicon-blue-150x150.png') }}" width="52" height="52" alt="Logo LLDIKTI Wilayah XVI">
                    <div>
                        <p class="brand-name">{{ $verification['institution'] ?? 'LLDIKTI Wilayah XVI' }}</p>
                        <p class="brand-sub">Sistem Informasi Manajemen Kepegawaian</p>
                    </div>
                </div>
            </header>
            <div class="title-area">
                <p class="eyebrow">BUKTI CUTI KEPEGAWAIAN</p>
                <h1>Verifikasi bukti cuti</h1>
                <p class="intro">Cocokkan informasi di halaman ini dengan dokumen cuti yang Anda terima.</p>
            </div>
            <section class="status-panel" aria-labelledby="status-title">
                <div class="status-copy">
                    <h2 class="status-title" id="status-title">{{ isset($verification) ? ($verification['status_label'] ?? 'Disetujui') : 'Bukti tidak ditemukan' }}</h2>
                    <p class="status-description">
                        @if(!isset($verification))
                            Kode verifikasi tidak valid atau dokumen tidak tersedia. Periksa kembali tautan atau pindai ulang QR pada dokumen yang Anda terima.
                        @elseif(!empty($verification['is_administratively_postponed']))
                            Cuti ini tidak lagi berlaku. Persetujuan terdahulu tetap tersimpan sebagai riwayat.
                        @else
                            Pengajuan cuti telah mendapatkan persetujuan akhir.
                        @endif
                    </p>
                </div>
                @if(!empty($verification['is_administratively_postponed']))
                    <dl class="status-date">
                        <dt>Waktu penangguhan</dt>
                        <dd>{{ $verification['administratively_postponed_at_label'] ?? '-' }}</dd>
                    </dl>
                @endif
            </section>
            @isset($verification)
                <div class="content-layout">
                    <section aria-labelledby="summary-title">
                        <h2 class="section-heading" id="summary-title">Ringkasan cuti</h2>
                        <p class="employee-name">{{ $verification['employee_name'] ?? '-' }}</p>
                        <dl class="details">
                            <div><dt>Jenis cuti</dt><dd>{{ $verification['leave_type'] ?? '-' }}</dd></div>
                            <div><dt>Periode cuti</dt><dd>{{ $verification['start_date_label'] ?? '-' }} – {{ $verification['end_date_label'] ?? '-' }}</dd></div>
                            <div><dt>Durasi</dt><dd class="duration-number">{{ $verification['workday_count'] ?? 0 }} <span>hari kerja</span></dd></div>
                        </dl>
                    </section>
                    @if(!empty($verification['approval_timeline']))
                        <section class="history" aria-labelledby="history-title">
                            <h2 class="section-heading" id="history-title">Riwayat persetujuan</h2>
                            <p class="history-intro">{{ !empty($verification['is_administratively_postponed']) ? 'Persetujuan berikut merupakan histori sebelum penangguhan administratif.' : 'Tahapan persetujuan yang tercatat pada bukti cuti.' }}</p>
                            <ol class="timeline">
                                @foreach($verification['approval_timeline'] as $step)
                                    <li class="{{ ($step['status'] ?? '') === 'approved' ? 'approved' : '' }}">
                                        <span class="step-number" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                        <div><p class="step-role">{{ $step['role'] ?? '-' }}</p><p class="step-name">{{ $step['approver_name'] ?? '-' }}</p></div>
                                        <p class="step-meta">
                                            <strong>{{ $step['status_label'] ?? '-' }}</strong>
                                            @if(!empty($step['acted_at_label']) && $step['acted_at_label'] !== '-')
                                                {{ $step['acted_at_label'] }}
                                            @endif
                                        </p>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endif
                    <section class="verification-panel" aria-labelledby="qr-title">
                        <div class="qr-code" role="img" aria-label="QR Code Verifikasi Bukti Cuti Publik">
                            {!! $qrSvg !!}
                        </div>
                        <div class="verification-copy">
                            <h2 id="qr-title">Verifikasi dokumen</h2>
                            <p>Gunakan tautan verifikasi untuk melihat status cuti yang tercatat di SIMPEG.</p>
                            <dl class="issued">
                                <dt>Bukti diterbitkan</dt><dd>{{ $verification['generated_at_label'] ?? '-' }}</dd>
                                <dt>Persetujuan akhir oleh</dt>
                                <dd>
                                    @if(!empty($verification['final_approver']['name']) && $verification['final_approver']['name'] !== '-')
                                        {{ $verification['final_approver']['name'] }} ({{ $verification['final_approver']['role'] ?? '-' }})
                                    @else
                                        -
                                    @endif
                                </dd>
                            </dl>
                        </div>
                        <div class="actions">
                            <button class="primary-btn" type="button" onclick="window.print()">Cetak halaman verifikasi</button>
                            <a class="secondary-btn" href="{{ $verificationUrl }}" target="_blank" rel="noopener noreferrer">Buka tautan verifikasi</a>
                        </div>
                    </section>
                </div>
            @endisset
            <footer class="document-footer">
                <strong>SIMPEG · LLDIKTI Wilayah XVI</strong>
                <span>Informasi persetujuan ditampilkan sesuai bukti yang diterbitkan.</span>
            </footer>
        </main>
    </div>
</body>
</html>
