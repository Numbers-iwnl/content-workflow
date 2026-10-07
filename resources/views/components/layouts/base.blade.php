@props(['title' => null, 'theme' => '#0E100F', 'bodyClass' => ''])
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $theme }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Conteúdos">
    <title>{{ $title ? $title.' · ' : '' }}Estúdio de Conteúdo</title>
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://use.typekit.net" crossorigin>
    <link rel="stylesheet" href="https://use.typekit.net/wbc3blt.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh antialiased {{ $bodyClass }}">
    {{ $slot }}

    @if (session('status'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 4500)" x-show="show" x-transition.opacity.duration.400ms
             class="pointer-events-none fixed inset-x-0 bottom-24 z-[60] flex justify-center px-4 lg:bottom-6">
            <p class="rise pointer-events-auto flex items-center gap-2 rounded-full bg-carbon px-5 py-3 text-sm text-white shadow-2xl shadow-carbon/30 ring-1 ring-white/10">
                <svg class="size-4 shrink-0 text-verde-lt" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                {{ session('status') }}
            </p>
        </div>
    @endif
</body>
</html>
