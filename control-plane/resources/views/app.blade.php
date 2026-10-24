@php
    // Theme (dark default · light · system) persisted in the unencrypted `appearance` cookie so the very first
    // paint already has the right palette. "system" is resolved by the inline script before CSS applies.
    $appearance = in_array(request()->cookie('appearance'), ['dark', 'light', 'system'], true) ? request()->cookie('appearance') : 'dark';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $appearance === 'light' ? 'light' : 'dark' }}" data-appearance="{{ $appearance }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="dark light">
        @if ($appearance === 'system')
            <meta name="theme-color" content="#F5F9FA" media="(prefers-color-scheme: light)">
            <meta name="theme-color" content="#0A0F12" media="(prefers-color-scheme: dark)">
        @else
            <meta name="theme-color" content="{{ $appearance === 'light' ? '#F5F9FA' : '#0A0F12' }}">
        @endif
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @if (config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key')))
            {{-- Runtime Reverb key for prebuilt images (VITE_REVERB_* are build-time); the key is public. --}}
            <meta name="falak-reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
        @endif

        @if ($appearance === 'system')
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
                (function () {
                    var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', dark);
                    document.documentElement.classList.toggle('light', !dark);
                })();
            </script>
        @endif

        <title inertia>{{ config('app.name', 'Falak') }}</title>

        @routes(null, \Illuminate\Support\Facades\Vite::cspNonce())
        @viteReactRefresh
        @vite(['resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
