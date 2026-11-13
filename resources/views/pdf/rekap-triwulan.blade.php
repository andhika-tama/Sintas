<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Rekap Triwulanan {{ $tahun }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: {{ \App\Support\PaletGrafik::TEKS }}; }
    h1 { font-size: 15px; margin: 0 0 4px; }
    h2 { font-size: 12px; margin: 16px 0 0; page-break-after: avoid; }
    h3 { font-size: 10px; margin: 8px 0 0; }
    p { margin: 0 0 3px; color: {{ \App\Support\PaletGrafik::TEKS_SEKUNDER }}; }
    table { width: 60%; border-collapse: collapse; margin-top: 4px; }
    th { background: #F1F5F4; text-align: left; font-weight: bold; }
    th, td { border: 1px solid #D5DEDB; padding: 4px 5px; }
    table.lebar { width: 100%; }
    td.n, th.n { text-align: right; }
    tr.total td, tr.total th { font-weight: bold; border-top: 2px solid #B8C4C0; }
    tr, .blok { page-break-inside: avoid; }
    table.grafik-tabel, table.grafik-bar { width: 100%; margin-top: 0; }
    .grafik-tabel td, .grafik-bar td { border: 0; padding: 1px 3px; }
    .grafik-label { width: 28%; color: {{ \App\Support\PaletGrafik::TEKS }}; }
    .grafik-area { width: 78%; }
    .grafik-angka { color: {{ \App\Support\PaletGrafik::TEKS }}; padding-left: 4px; }
    .grafik-v { margin: 6px auto 0; padding-left: 30px; }
    .grafik-plot { position: relative; }
    .grafik-garis { position: absolute; left: 0; right: 0; height: 0; border-top: 1px solid #D5DEDB; }
    .grafik-y { position: absolute; left: -30px; width: 26px; text-align: right; font-size: 7px; line-height: 10px; color: {{ \App\Support\PaletGrafik::TEKS_SEKUNDER }}; }
    table.grafik-kolom { width: 100%; table-layout: fixed; margin: 0; }
    .grafik-kolom > tr > td, .grafik-kolom td { border: 0; padding: 0; vertical-align: bottom; text-align: center; }
    table.grafik-pasang { width: auto; margin: 0 auto; }
    .grafik-pasang td { padding: 0 2px; border: 0; text-align: center; vertical-align: bottom; }
    .grafik-nilai { font-size: 8px; line-height: 10px; height: 12px; color: {{ \App\Support\PaletGrafik::TEKS }}; }
    .grafik-batang { width: 16px; margin: 0 auto; border: 1px solid; border-bottom: 0; }
    .grafik-kat td { font-size: 8px; padding-top: 3px; vertical-align: top; color: {{ \App\Support\PaletGrafik::TEKS }}; }
    .titik { display: inline-block; width: 7px; height: 7px; border-radius: 4px; }
    .legenda { color: {{ \App\Support\PaletGrafik::TEKS_SEKUNDER }}; }
    p.catatan { margin-top: 4px; }
</style>
</head>
<body>
    <h1>Rekap Triwulanan {{ $tahun }}</h1>
    <p>{{ implode(' · ', [$tw, ...($irban ? ['Irban: '.$irban] : []), ...($saring ?? [])]) }}</p>
    <p>Dibuat {{ $dibuat }}</p>
    @unless ($pkptDisahkan)
        <p>PKPT {{ $tahun }} belum disahkan; rencana belum dapat dihitung.</p>
    @endunless
    {{-- Only the sections picked in the export modal ($pilihan); each table block stays on one page when it fits. --}}
    @foreach ($bagian as $s)
        <h2>{{ $s['judul'] }}</h2>
        @foreach ($s['tabel'] as $t)
            <div class="blok">
                @if ($t['judul'])<h3>{{ $t['judul'] }}</h3>@endif
                <table>
                    <thead><tr><th>{{ ['rr' => 'Triwulan', 'klasifikasi' => 'Klasifikasi', 'jenis' => 'Jenis Penugasan'][$s['kode']] }}</th>@foreach ($s['kolom'] as $k)<th class="n">{{ $k }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($t['baris'] as $b)
                            <tr @class(['total' => $b['total']])><th>{{ $s['kode'] === 'rr' ? $b['periode'] : $b['label'] }}</th>@foreach ($b['sel'] as $j => $n)<td class="n">{{ $n }}{{ ($b['lebih'] ?? null) === $j ? ' *' : '' }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
        {{-- Task 6 1.1.4: notes only when relevant (catatanTarget = the section code that carries it). --}}
        @if (($catatanTarget ?? null) === $s['kode'])
            <p class="catatan">{{ \App\Services\Ekspor\PembangunRekap::CATATAN_TARGET }}</p>
        @endif
        @if ($catatan = \App\Services\Ekspor\PembangunRekap::catatanRencana($rekap ?? [], $s['kode']))
            <p class="catatan">{{ $catatan }}</p>
        @endif
        @if (collect($s['tabel'])->pluck('baris')->flatten(1)->contains(fn ($b) => is_int($b['lebih'] ?? null)))
            <p class="catatan">{{ \App\Services\Ekspor\PembangunRekap::CATATAN_LEBIH }}</p>
        @endif
        @isset($grafik[$s['kode']])
            @include('pdf.partials.grafik-batang', ['kode' => $s['kode'], ...$grafik[$s['kode']]])
        @endisset
    @endforeach
    @if (in_array('detail', $pilihan, true))
        <h2>{{ $detailJudul }}</h2>
        @foreach ($detail as $g)
            <div class="blok">
                <h3>{{ $g['irban'] }}</h3>
                <table class="lebar">
                    <thead><tr>@foreach ($detailKolom as $k)<th>{{ $k }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($g['baris'] as $b)
                            <tr>@foreach ($b as $sel)<td>{{ $sel }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</body>
</html>
