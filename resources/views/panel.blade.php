<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Panel · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/panel/main.js'])
</head>
<body class="antialiased">
    <div id="panel" data-boot='@json($boot)'></div>
</body>
</html>
