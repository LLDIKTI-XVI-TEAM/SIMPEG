<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akun Belum Terdaftar - SIMPEG</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #f8fafc;
            color: #0f172a;
            font-family: Arial, sans-serif;
        }

        main {
            align-items: center;
            display: flex;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
            text-align: center;
        }

        section {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgb(15 23 42 / 8%);
            max-width: 520px;
            padding: 32px;
            width: 100%;
        }

        .eyebrow {
            color: #64748b;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .08em;
            margin: 0;
            text-transform: uppercase;
        }

        h1 {
            font-size: 26px;
            margin: 12px 0 0;
        }

        p.message {
            color: #475569;
            font-size: 15px;
            line-height: 1.6;
            margin: 18px 0 0;
        }

        a {
            background: #020617;
            border-radius: 6px;
            color: #ffffff;
            display: inline-flex;
            font-size: 14px;
            font-weight: 600;
            margin-top: 24px;
            padding: 10px 16px;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main>
        <section>
            <p class="eyebrow">SIMPEG</p>
            <h1>Akun belum terdaftar</h1>
            <p class="message">
                {{ $message ?? 'Akun Anda belum terdaftar di SIMPEG. Silakan hubungi Admin Kepegawaian.' }}
            </p>
            <a href="{{ route('login') }}">
                Login ulang
            </a>
        </section>
    </main>
</body>
</html>
