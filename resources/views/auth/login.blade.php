@extends('layouts.app')

@section('title', 'Entrar')

@section('content')
    <div class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-12">
        {{-- Fondo: un degradado suave de la marca, sin imágenes. --}}
        <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(60rem_30rem_at_50%_-10%,var(--color-brand-100),transparent)] dark:bg-[radial-gradient(60rem_30rem_at_50%_-10%,var(--color-brand-950),transparent)]"></div>

        <div class="w-full max-w-sm">
            <div class="mb-8 flex flex-col items-center text-center">
                <span class="mb-4 flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg shadow-brand-600/30">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>
                    </svg>
                </span>
                <h1 class="text-2xl font-semibold tracking-tight">Entrar en {{ config('app.name') }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Procesado automático de facturas</p>
            </div>

            <div class="card p-6">
                <form method="post" action="{{ url('login') }}" class="space-y-4" data-busy="Entrando…">
                    @csrf
                    <div>
                        <label for="email" class="label">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input @error('email') !border-red-400 @enderror">
                        @error('email')<p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="password" class="label">Contraseña</label>
                        <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
                    </div>
                    <button type="submit" class="btn btn-primary w-full">Entrar</button>
                </form>
            </div>

            @if (config('invoices.demo'))
                <div class="card mt-4 border-brand-200 bg-brand-50/60 p-5 text-sm dark:border-brand-900 dark:bg-brand-950/30">
                    <p class="font-semibold">Demo</p>
                    <p class="mt-1 text-slate-600 dark:text-slate-300">Facturas ficticias; se reinicia cada noche.</p>
                    <dl class="mt-3 space-y-1 text-xs">
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">Revisor</dt><dd><code>revisor@demo.test</code></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">Admin</dt><dd><code>admin@demo.test</code></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">Contraseña</dt><dd><code>password</code></dd></div>
                    </dl>
                    <form method="post" action="{{ url('login') }}" class="mt-4" data-busy="Entrando…">
                        @csrf
                        <input type="hidden" name="email" value="revisor@demo.test">
                        <input type="hidden" name="password" value="password">
                        <button type="submit" class="btn btn-secondary w-full">Entrar como revisor</button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <script>
        // Al enviar, el botón muestra que está entrando y no se puede pulsar dos veces.
        document.querySelectorAll('form[data-busy]').forEach((form) => form.addEventListener('submit', () => {
            const button = form.querySelector('button[type=submit]');
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML = '<svg class="animate-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>' + form.dataset.busy;
        }));
    </script>
@endsection
