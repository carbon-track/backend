<?php

declare(strict_types=1);

$copy = $language === 'zh' ? [
    'title' => '正在确认连接',
    'description' => 'CarbonTrack 正在确认此浏览器中的授权。',
    'readyTitle' => '授权已确认',
    'readyDescription' => '正在返回客户端以完成连接。',
    'deniedTitle' => '授权已拒绝',
    'deniedDescription' => '正在返回客户端。',
    'errorTitle' => '无法确认连接',
    'errorDescription' => '请从客户端重新发起登录。',
    'continue' => '返回客户端',
    'javascript' => '请启用 JavaScript 以完成连接。',
] : [
    'title' => 'Confirming your connection',
    'description' => 'CarbonTrack is confirming the authorization in this browser.',
    'readyTitle' => 'Authorization confirmed',
    'readyDescription' => 'Returning to your client to finish connecting.',
    'deniedTitle' => 'Authorization declined',
    'deniedDescription' => 'Returning to your client.',
    'errorTitle' => 'Connection could not be confirmed',
    'errorDescription' => 'Start a new sign-in from your client.',
    'continue' => 'Return to client',
    'javascript' => 'Enable JavaScript to finish connecting.',
];
?>
<!doctype html>
<html lang="<?= $language ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CarbonTrack · <?= $copy['title'] ?></title>
    <style nonce="<?= $nonce ?>">
        :root {
            color-scheme: light dark;
            --background: #f0f4ef;
            --surface: #fbfdf9;
            --text: #153025;
            --muted: #52685b;
            --accent: #196046;
            --border: #cbdace;
            --glow: #dceae0;
            font: 16px/1.55 system-ui, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --background: #101c16;
                --surface: #182821;
                --text: #edf7ef;
                --muted: #b0c9ba;
                --accent: #9be0b9;
                --border: #3e5447;
                --glow: #244234;
            }
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            padding: clamp(1.25rem, 5vw, 4rem);
            display: grid;
            place-items: center;
            color: var(--text);
            background: radial-gradient(ellipse at 75% 14%, var(--glow), transparent 55%), var(--background);
        }
        main {
            width: min(100%, 34rem);
            padding: clamp(1.5rem, 6vw, 3.25rem);
            background: var(--surface);
            border: 1px solid var(--border);
            border-top: 4px solid var(--accent);
            border-radius: 1.25rem;
            box-shadow: 0 1.5rem 5rem #122e2222;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: .6rem;
            margin: 0 0 1.6rem;
            color: var(--accent);
            font-weight: 700;
        }
        .brand::before {
            width: .85rem;
            height: .85rem;
            content: "";
            background: currentColor;
            border-radius: 70% 15% 70% 15%;
            transform: rotate(-20deg);
        }
        h1 {
            margin: 0;
            font-size: clamp(1.75rem, 5vw, 2.5rem);
            line-height: 1.15;
            letter-spacing: -.025em;
        }
        .description { margin: .85rem 0 0; color: var(--muted); }
        a {
            display: inline-flex;
            align-items: center;
            min-height: 2.75rem;
            margin-top: 1.25rem;
            padding: .45rem .9rem;
            border: 1px solid var(--border);
            border-radius: .6rem;
            color: var(--accent);
            font-weight: 600;
            text-decoration: none;
        }
        a:hover { border-color: currentColor; }
        a:focus-visible { outline: 2px solid currentColor; outline-offset: 3px; }
        a[hidden] { display: none; }
    </style>
</head>
<body>
    <main>
        <p class="brand">CarbonTrack</p>
        <div role="status" aria-atomic="true">
            <h1 id="title"><?= $copy['title'] ?></h1>
            <p id="status" class="description"><?= $copy['description'] ?></p>
        </div>
        <a id="continue" rel="noreferrer" hidden><?= $copy['continue'] ?></a>
        <noscript><p class="description"><?= $copy['javascript'] ?></p></noscript>
    </main>
    <script nonce="<?= $nonce ?>">
        const copy = <?= json_encode($copy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>;
        const ticket = /^#ticket=([A-Za-z0-9_-]{43})$/.exec(location.hash);
        history.replaceState(null, '', location.pathname);

        function showStatus(title, description) {
            document.title = 'CarbonTrack · ' + title;
            document.querySelector('#title').textContent = title;
            document.querySelector('#status').textContent = description;
        }

        async function confirmConnection() {
            try {
                if (!ticket) throw new Error();
                const response = await fetch(location.pathname, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new URLSearchParams({ticket: ticket[1]}),
                    redirect: 'error',
                });
                if (!response.ok) throw new Error();
                const result = await response.json();
                const callback = new URL(result.callback_url);
                if (callback.searchParams.has('error')) {
                    showStatus(copy.deniedTitle, copy.deniedDescription);
                } else {
                    showStatus(copy.readyTitle, copy.readyDescription);
                }
                const link = document.querySelector('#continue');
                const returnToClient = () => location.replace(callback.href);
                const redirectTimer = setTimeout(returnToClient, 650);
                link.href = callback.href;
                link.hidden = false;
                link.addEventListener('click', event => {
                    event.preventDefault();
                    clearTimeout(redirectTimer);
                    returnToClient();
                });
            } catch {
                showStatus(copy.errorTitle, copy.errorDescription);
            }
        }
        confirmConnection();
    </script>
</body>
</html>
