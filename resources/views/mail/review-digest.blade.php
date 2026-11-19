<x-mail::message>
# Resumen {{ $period }}

Hola, este es el resumen de **{{ $account->name }}**.

- Facturas recibidas: **{{ $processed }}**
- Validadas sin intervención: **{{ $automatic }}**
- Pendientes de revisar: **{{ $pending }}**

<x-mail::button :url="$url">
Revisar ahora
</x-mail::button>

Cada pendiente indica el motivo, así que suele bastar con unos segundos por factura.

{{ config('app.name') }}
</x-mail::message>
