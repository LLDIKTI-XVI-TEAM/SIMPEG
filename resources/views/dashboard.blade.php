<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - SIMPEG</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #f8fafc;
            color: #0f172a;
            font-family: Arial, sans-serif;
        }

        header {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
        }

        .bar,
        main {
            margin: 0 auto;
            max-width: 960px;
            padding-left: 24px;
            padding-right: 24px;
        }

        .bar {
            align-items: center;
            display: flex;
            justify-content: space-between;
            padding-bottom: 16px;
            padding-top: 16px;
        }

        .eyebrow {
            color: #64748b;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .08em;
            margin: 0;
            text-transform: uppercase;
        }

        h1,
        h2 {
            margin: 4px 0 0;
        }

        button {
            background: #020617;
            border: 0;
            border-radius: 6px;
            color: #ffffff;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 16px;
        }

        main {
            padding-bottom: 32px;
            padding-top: 32px;
        }

        section {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgb(15 23 42 / 8%);
            padding: 24px;
        }

        section p {
            color: #475569;
            margin: 4px 0 0;
        }
    </style>
</head>
<body>
    <header>
        <div class="bar">
            <div>
                <p class="eyebrow">SIMPEG</p>
                <h1>Dashboard</h1>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">
                    Keluar
                </button>
            </form>
        </div>
    </header>

    <main>
        <section>
            <p>Login aktif</p>
            <h2>{{ auth()->user()->name }}</h2>
            <p>{{ auth()->user()->email }}</p>
        </section>
    </main>
</body>
</html>
