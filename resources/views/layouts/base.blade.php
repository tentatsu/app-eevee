<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name')) — {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/eevee.css') }}">
</head>
<body>
@yield('body')
@stack('scripts')
</body>
</html>
