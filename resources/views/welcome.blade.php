<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ $bootstrap['security']['csrfToken'] }}">
        <title>{{ $bootstrap['app']['name'] }}</title>
        @php
            $maestroShellVersion = file_exists(public_path('css/maestro.shell.css')) ? filemtime(public_path('css/maestro.shell.css')) : time();
            $maestroAppVersion = file_exists(public_path('js/maestro.app.js')) ? filemtime(public_path('js/maestro.app.js')) : time();
        @endphp
        <link rel="stylesheet" href="/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.css">
        <link rel="stylesheet" href="/css/maestro.shell.css?v={{ $maestroShellVersion }}">
    </head>
    <body data-theme="dark">
        <div id="app"></div>

        <script>
            window.__PBB_BOOTSTRAP__ = @json($bootstrap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        </script>
        <script type="module" src="/js/maestro.app.js?v={{ $maestroAppVersion }}"></script>
    </body>
</html>
