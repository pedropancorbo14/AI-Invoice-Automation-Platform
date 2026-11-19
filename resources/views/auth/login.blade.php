@extends('layouts.app')

@section('title', 'Entrar')

@section('content')
    <div class="card" style="max-width: 380px; margin: 48px auto 0;">
        <h1>Entrar</h1>
        <p class="lead">Procesado automático de facturas</p>

        <form method="post" action="{{ url('login') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                @error('email')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
            </div>
            <button type="submit" style="width: 100%;">Entrar</button>
        </form>
    </div>
@endsection
