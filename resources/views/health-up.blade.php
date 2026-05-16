<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $app }} Health</title>
        <style>
            :root {
                color-scheme: dark;
                --bg: #08111a;
                --panel: #101b28;
                --line: #294059;
                --text: #edf5fd;
                --soft: #9fb2c8;
                --ok: #48d597;
            }

            * {
                box-sizing: border-box;
            }

            html,
            body {
                margin: 0;
                min-height: 100%;
                font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
                background:
                    radial-gradient(circle at top left, rgba(67, 197, 255, 0.12), transparent 24rem),
                    linear-gradient(180deg, #08111a 0%, #07101a 100%);
                color: var(--text);
            }

            body {
                display: grid;
                place-items: center;
                padding: 1.5rem;
            }

            .health-card {
                width: min(100%, 34rem);
                border: 1px solid var(--line);
                border-radius: 20px;
                padding: 1.35rem 1.4rem;
                background: rgba(16, 27, 40, 0.96);
                box-shadow: 0 24px 60px rgba(0, 0, 0, 0.34);
            }

            .eyebrow {
                margin: 0 0 0.4rem;
                color: var(--soft);
                font-size: 0.75rem;
                font-weight: 700;
                letter-spacing: 0.14em;
                text-transform: uppercase;
            }

            .title {
                margin: 0;
                font-size: 2rem;
                line-height: 1;
                letter-spacing: -0.04em;
            }

            .status {
                display: inline-flex;
                align-items: center;
                gap: 0.6rem;
                margin-top: 1rem;
                font-weight: 700;
            }

            .dot {
                width: 0.7rem;
                height: 0.7rem;
                border-radius: 999px;
                background: var(--ok);
                box-shadow: 0 0 0 0.35rem rgba(72, 213, 151, 0.14);
            }

            .meta {
                margin-top: 0.9rem;
                color: var(--soft);
                line-height: 1.6;
            }

            code {
                font-family: Consolas, "Courier New", monospace;
                font-size: 0.92rem;
            }
        </style>
    </head>
    <body>
        <main class="health-card">
            <p class="eyebrow">Maestro Health</p>
            <h1 class="title">{{ $app }}</h1>
            <div class="status">
                <span class="dot"></span>
                <span>Application up</span>
            </div>
            <p class="meta">
                Status: <code>{{ $status }}</code><br>
                Timestamp: <code>{{ $timestamp }}</code>
            </p>
        </main>
    </body>
</html>
