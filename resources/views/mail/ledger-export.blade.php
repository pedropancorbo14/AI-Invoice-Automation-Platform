<x-mail::message>
# Facturas de {{ $company->name }}

Adjuntamos el libro de facturas de **{{ $period->label }}**: las facturas
validadas, con una línea por cada tipo de IVA ({{ $file['rows'] }} {{ $file['rows'] === 1 ? 'línea' : 'líneas' }}).

{{ config('app.name') }}
</x-mail::message>
