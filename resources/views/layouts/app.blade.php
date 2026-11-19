<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root {
            --bg: #f6f7f9; --card: #fff; --text: #1d2330; --muted: #667085; --border: #e3e6eb;
            --accent: #2457d6; --accent-soft: #eaf0fd; --ok: #18794e; --ok-soft: #e7f6ee;
            --warn: #9a5b00; --warn-soft: #fdf3e2; --bad: #b42318; --bad-soft: #fdecea;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #12151b; --card: #1a1f27; --text: #e8eaee; --muted: #98a2b3; --border: #2b323d;
                --accent: #7aa2ff; --accent-soft: #1f2a44; --ok: #5fd39a; --ok-soft: #15301f;
                --warn: #f3b955; --warn-soft: #33270f; --bad: #ff8a80; --bad-soft: #3a1a18;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); }
        header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); background: var(--card); }
        header strong { font-size: 16px; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 32px 0 8px; }
        p.lead { color: var(--muted); margin: 0 0 20px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
        label { display: block; font-weight: 600; margin: 0 0 6px; }
        input, select { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); color: var(--text); font: inherit; }
        button { padding: 9px 16px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button.link { background: none; color: var(--muted); padding: 0; font-weight: 400; }
        .field { margin-bottom: 16px; }
        .error { color: var(--bad); font-size: 14px; margin-top: 6px; }
        code { font: 13px ui-monospace, SFMono-Regular, Menlo, monospace; background: var(--accent-soft); padding: 2px 6px; border-radius: 6px; word-break: break-all; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 10px 0; border-top: 1px solid var(--border); vertical-align: top; }
        td:first-child { padding-right: 12px; }
        tr:first-child td { border-top: 0; padding-top: 0; }
        tr:last-child td { padding-bottom: 0; }
    </style>
</head>
<body>
    @auth
        <header>
            <a href="{{ route('panel') }}" style="color: inherit; text-decoration: none;"><strong>{{ config('app.name') }}</strong> · Panel</a>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="link">Salir</button>
            </form>
        </header>
    @endauth
    <main>
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
