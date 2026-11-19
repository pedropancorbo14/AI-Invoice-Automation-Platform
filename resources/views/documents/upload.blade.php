@extends('layouts.app')

@section('title', 'Subir facturas')

@section('content')
    <h1>Subir facturas</h1>
    <p class="lead">Arrastra aquí los PDF o las fotos. Lo normal es que lleguen solas por email; esto es para lo que no.</p>

    <div class="card">
        <div class="field">
            <label for="company">Cliente</label>
            <select id="company">
                <option value="">Sin asignar (lo decides después)</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}">{{ $company->name }} · {{ $company->tax_id }}</option>
                @endforeach
            </select>
        </div>

        <label for="files" id="dropzone" class="dropzone">
            <input id="files" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.heic,application/pdf,image/*" hidden>
            <span class="dz-title">Suelta los ficheros aquí</span>
            <span class="dz-hint">o haz clic para elegirlos · PDF, JPG, PNG o HEIC · hasta 20 a la vez</span>
        </label>

        <ul id="results" class="results" aria-live="polite"></ul>
    </div>

    <h2>Direcciones de reenvío</h2>
    <p class="lead">Cada cliente reenvía sus facturas a su dirección y entran solas.</p>
    <div class="card">
        <table>
            @foreach ($companies as $company)
                <tr><td>{{ $company->name }}</td><td><code>{{ $company->inboxAddress() }}</code></td></tr>
            @endforeach
            <tr><td>Sin cliente concreto <br><small style="color: var(--muted)">van a "sin asignar"</small></td><td><code>{{ $account->inboxAddress() }}</code></td></tr>
        </table>
    </div>

    <style>
        .dropzone { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; min-height: 180px; padding: 24px; border: 2px dashed var(--border); border-radius: 12px; text-align: center; cursor: pointer; font-weight: 400; transition: border-color .15s, background .15s; }
        .dropzone:hover, .dropzone.over { border-color: var(--accent); background: var(--accent-soft); }
        .dropzone.busy { opacity: .6; pointer-events: none; }
        .dz-title { font-size: 17px; font-weight: 600; }
        .dz-hint { color: var(--muted); font-size: 14px; }
        .results { list-style: none; margin: 16px 0 0; padding: 0; display: grid; gap: 8px; }
        .results li { display: flex; justify-content: space-between; gap: 12px; padding: 10px 12px; border-radius: 8px; font-size: 14px; }
        .results .ok { background: var(--ok-soft); color: var(--ok); }
        .results .warn { background: var(--warn-soft); color: var(--warn); }
        .results .bad { background: var(--bad-soft); color: var(--bad); }
        .results span:first-child { overflow-wrap: anywhere; color: var(--text); }
    </style>
@endsection

@push('scripts')
<script>
(() => {
    const zone = document.getElementById('dropzone');
    const input = document.getElementById('files');
    const company = document.getElementById('company');
    const results = document.getElementById('results');
    const token = document.querySelector('meta[name="csrf-token"]').content;

    const row = (name, text, kind) => {
        const li = document.createElement('li');
        li.className = kind;
        const a = document.createElement('span');
        a.textContent = name;
        const b = document.createElement('span');
        b.textContent = text;
        li.append(a, b);
        results.prepend(li);
    };

    async function upload(files) {
        if (!files.length) return;

        const body = new FormData();
        [...files].forEach(f => body.append('files[]', f));
        if (company.value) body.append('company_id', company.value);

        zone.classList.add('busy');
        try {
            const res = await fetch(@json(route('documents.upload.web')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body,
            });
            const json = await res.json();

            if (!res.ok) {
                const errors = Object.values(json.errors ?? {}).flat();
                row('Error', errors[0] ?? json.message ?? 'No se pudo subir', 'bad');
                return;
            }

            json.data.created.forEach(d => row(d.original_filename, 'Recibido', 'ok'));
            json.data.duplicates.forEach(d => row(d.filename, 'Ya estaba subido', 'warn'));
            json.data.rejected.forEach(d => row(d.filename, d.reason, 'bad'));
        } catch (e) {
            row('Error', 'Sin conexión. Inténtalo de nuevo.', 'bad');
        } finally {
            zone.classList.remove('busy');
            input.value = '';
        }
    }

    input.addEventListener('change', () => upload(input.files));
    ['dragenter', 'dragover'].forEach(e => zone.addEventListener(e, ev => { ev.preventDefault(); zone.classList.add('over'); }));
    ['dragleave', 'drop'].forEach(e => zone.addEventListener(e, ev => { ev.preventDefault(); zone.classList.remove('over'); }));
    zone.addEventListener('drop', ev => upload(ev.dataTransfer.files));
})();
</script>
@endpush
