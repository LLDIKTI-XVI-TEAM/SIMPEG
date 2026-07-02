<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Notifikasi SIMPEG' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#172033;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #dbe3ef;">
                    <tr>
                        <td style="background:#122E92;padding:20px 24px;color:#ffffff;">
                            <h1 style="margin:0;font-size:20px;line-height:1.35;font-weight:700;">SIMPEG LLDIKTI XVI</h1>
                            <p style="margin:4px 0 0;font-size:13px;line-height:1.5;opacity:.9;">Sistem Informasi Kepegawaian</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;background:#f8fafc;border-top:1px solid #e5edf6;color:#64748b;font-size:12px;line-height:1.5;">
                            Email ini dikirim otomatis oleh SIMPEG LLDIKTI Wilayah XVI. Jangan membalas email ini.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
