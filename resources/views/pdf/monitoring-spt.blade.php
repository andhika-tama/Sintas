<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Monitoring SPT {{ $tahun }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #151C1A; }
    h1 { font-size: 15px; margin: 0 0 4px; }
    p { margin: 0 0 3px; color: #4B5955; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #F1F5F4; text-align: left; font-weight: bold; }
    th, td { border: 1px solid #D5DEDB; padding: 4px 5px; vertical-align: top; }
    tr { page-break-inside: avoid; }
    thead { display: table-header-group; }
</style>
</head>
<body>
    <h1>Monitoring SPT {{ $tahun }}</h1>
    <p>Filter: {{ $filter ? implode('; ', $filter) : 'Semua data' }}</p>
    <p>Dibuat {{ $dibuat }} &middot; {{ count($baris) }} SPT</p>
    <table>
        <thead><tr>@foreach ($kolom as $k)<th>{{ $k }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($baris as $b)
                <tr>@foreach ($b as $sel)<td>@if (is_array($sel))<strong>{{ $sel[0] }}</strong><br><span style="font-size: 8px; color: #4B5955;">{{ $sel[1] }}</span>@else{{ $sel ?? '–' }}@endif</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($kolom) }}">Tidak ada SPT sesuai filter.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
