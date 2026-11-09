<?php

namespace App\Services\Import;

use App\Actions\Spt\ValidatePersonel;
use App\Enums\PeranTim;
use App\Enums\StatusPkpt;
use App\Enums\SyaratSelesai;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\NomorCounter;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Services\KesesuaianPkpt;
use App\Services\Penomoran;
use App\Services\Settings;
use App\Support\Tanggal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Legacy SPT import (Sistem A/B): read() gives raw cell strings, analyze() applies every row rule with a constant number of queries. */
class SptImportParser
{
    public const HEADERS = ['nomor_spt', 'tanggal_spt', 'irban', 'klasifikasi', 'jenis_penugasan', 'uraian_untuk', 'periode_diperiksa', 'objek',
        'tanggal_mulai', 'tanggal_selesai', 'nip_supervisor', 'nip_ketua', 'nip_anggota', 'nomor_lhp', 'tanggal_lhp', 'selesai',
        'kendala_kategori', 'kendala_uraian'];

    public const TEXT_COLUMNS = ['nomor_spt', 'nip_supervisor', 'nip_ketua', 'nip_anggota', 'nomor_lhp'];

    /** Columns whose numeric cell cannot be trusted (Excel keeps ~15 significant digits): flagged `{kolom}_numerik`. */
    private const NUMERIK = ['nomor_spt', 'nip_supervisor', 'nip_ketua', 'nip_anggota'];

    private const NIP = ['nip_supervisor' => PeranTim::SupervisorDalnis, 'nip_ketua' => PeranTim::Ketua, 'nip_anggota' => PeranTim::Anggota];

    private const ANGKA = 'tersimpan sebagai angka; ubah kolom menjadi teks.';

    private const FORMAT_TANGGAL = 'Format tanggal tidak dikenali (gunakan dd/mm/yyyy).';

    public const MAX_ROWS = 2000;

    private KesesuaianPkpt $kesesuaian;

    private Penomoran $penomoran;

    public function __construct()
    {
        $this->kesesuaian = app(KesesuaianPkpt::class);
        $this->penomoran = app(Penomoran::class);
    }

    /** Raw rows only: cell strings keyed by header plus `baris`, and `{kolom}_numerik` for nomor/NIP cells typed as numbers. */
    public function read(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $grid = $reader->load($path)->getActiveSheet()->toArray(null, false, false, false);

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($grid) ?? []);
        $missing = array_diff(self::HEADERS, $header);
        if ($missing) {
            throw new InvalidArgumentException('Kolom wajib tidak ditemukan: '.implode(', ', $missing).'. Gunakan templat.');
        }

        $raw = [];
        foreach ($grid as $i => $cells) {
            $row = ['baris' => $i + 2];
            $angka = false;
            foreach (self::HEADERS as $h) {
                $v = $cells[array_search($h, $header, true)] ?? null;
                if (in_array($h, self::NUMERIK, true) && (is_int($v) || is_float($v))) {
                    $row["{$h}_numerik"] = $angka = true;
                    $v = '';
                }
                // Other numeric cells stay digit strings; a date serial is recognised later by its shape.
                $row[$h] = trim(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
            if (! $angka && implode('', array_map(fn ($h) => $row[$h], self::HEADERS)) === '') {
                continue; // blank row
            }
            $raw[] = $row;
            if (count($raw) > self::MAX_ROWS) {
                throw new InvalidArgumentException(self::capMessage());
            }
        }

        return $raw;
    }

    public static function capMessage(): string
    {
        return 'Berkas terlalu besar: maksimal '.self::MAX_ROWS.' baris data per impor.';
    }

    /** @return list<array{baris:int,data:array,status:string,errors:list<array{kolom:string,pesan:string}>,warnings:list<string>}> */
    public function analyze(array $raw): array
    {
        if (count($raw) > self::MAX_ROWS) {
            throw new InvalidArgumentException(self::capMessage());
        }
        // The raw rows can come from client-tampered Livewire state: normalize every row.
        $raw = array_map(function ($r) {
            $r = is_array($r) ? $r : [];
            $n = ['baris' => isset($r['baris']) && is_scalar($r['baris']) ? (int) $r['baris'] : 0];
            foreach (self::HEADERS as $h) {
                $n[$h] = isset($r[$h]) && is_scalar($r[$h]) ? trim((string) $r[$h]) : '';
            }
            foreach (self::NUMERIK as $h) {
                $n["{$h}_numerik"] = ! empty($r["{$h}_numerik"]);
            }

            return $n;
        }, array_values($raw));

        $lookup = $this->lookup($raw);
        $seenNomor = [];
        $out = [];
        foreach ($raw as $r) {
            $out[] = $this->baris($r, $lookup, $seenNomor);
        }

        return $out;
    }

    /** Every lookup the rows need, loaded once per analyze(). */
    private function lookup(array $raw): array
    {
        $kunci = fn (?string $s) => mb_strtolower(trim((string) $s));
        $irban = [];
        $semuaIrban = Irban::all(['id', 'nama', 'kode_nomor']);
        foreach ($semuaIrban as $i) {
            $irban[$kunci($i->nama)] = $i;
        }
        foreach ($semuaIrban as $i) {
            $irban[$kunci($i->kode_nomor)] ??= $i;
        }
        $objek = [];
        foreach (Objek::all(['id', 'nama']) as $o) {
            $objek[$kunci($o->nama)][] = $o->id;
        }
        $nip = [];
        foreach ($raw as $r) {
            foreach (array_keys(self::NIP) as $h) {
                array_push($nip, ...$this->daftar($r[$h]));
            }
        }
        $tahunMulai = array_values(array_unique(array_filter(array_map(fn ($r) => (int) substr((string) Tanggal::dariSel($r['tanggal_mulai']), 0, 4), $raw))));

        return [
            'irban' => $irban,
            'klasifikasi' => Klasifikasi::all()->keyBy(fn ($k) => $kunci($k->nama)),
            'jenis' => JenisPenugasan::all(['id', 'nama'])->keyBy(fn ($j) => $kunci($j->nama)),
            'kodeArsip' => KodeArsip::pluck('id', 'kode')->mapWithKeys(fn ($id, $kode) => [$kunci($kode) => $id])->all(),
            'objek' => $objek,
            'pegawai' => Pegawai::whereIn('nip', array_unique($nip))->pluck('id', 'nip')->all(),
            // Compared lower-cased on every driver (MySQL's unique index is case-insensitive, SQLite's `=` is not).
            'nomor' => Spt::whereIn(DB::raw('lower(nomor_lengkap)'), array_values(array_unique(array_map($kunci, array_filter(array_column($raw, 'nomor_spt'))))))->pluck('nomor_lengkap')
                ->mapWithKeys(fn ($n) => [$kunci($n) => true])->all(),
            'item' => $this->itemPkpt($tahunMulai),
            'counter' => NomorCounter::all(['tahun', 'scope', 'nomor_terakhir'])->mapWithKeys(fn ($c) => ["{$c->tahun}|{$c->scope}" => $c->nomor_terakhir])->all(),
            'ppj' => IrbanTahunan::whereIn('tahun', $tahunMulai)->get(['irban_id', 'tahun', 'ppj_pegawai_id'])
                ->mapWithKeys(fn ($t) => ["{$t->irban_id}|{$t->tahun}" => $t->ppj_pegawai_id])->all(),
            'kendala' => collect((array) app(Settings::class)->get('kendala.kategori'))->keyBy(fn ($k) => $kunci($k))->all(),
            'tahunIni' => now('Asia/Jakarta')->year,
        ];
    }

    /** Active items of the PKPT disahkan in $tahun, grouped by irban|jenis|tahun: what an SPT can be linked to. Also re-read by ImporSpt at import. */
    public function itemPkpt(array $tahun, ?Collection $pkptIds = null): Collection
    {
        return PkptItem::aktif()->join('pkpt', 'pkpt.id', '=', 'pkpt_item.pkpt_id')
            ->where('pkpt.status', StatusPkpt::Disahkan)->whereIn('pkpt.tahun', $tahun)
            ->when($pkptIds, fn ($q) => $q->whereIn('pkpt_item.pkpt_id', $pkptIds))
            ->get(['pkpt_item.*', 'pkpt.tahun as pkpt_tahun'])->groupBy(fn ($i) => "{$i->irban_id}|{$i->jenis_penugasan_id}|{$i->pkpt_tahun}");
    }

    /** Items of itemPkpt() whose period overlaps the SPT dates (Y-m-d); the SPT is linked only when exactly one remains. */
    public function itemCocok(Collection $item, int $irbanId, int $jenisId, string $mulai, string $selesai): Collection
    {
        return ($item[$irbanId.'|'.$jenisId.'|'.substr($mulai, 0, 4)] ?? collect())->filter(function ($i) use ($mulai, $selesai) {
            [$m, $s] = $this->kesesuaian->rentang($i);

            return $m->toDateString() <= $selesai && $s->toDateString() >= $mulai;
        })->values();
    }

    /** @return list<string> the `;`-separated parts of a cell, trimmed, empty parts dropped */
    private function daftar(string $sel): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $sel)), fn ($s) => $s !== ''));
    }

    private function baris(array $r, array $l, array &$seenNomor): array
    {
        $errors = [];
        $galat = function (string $kolom, string $pesan) use (&$errors) {
            $errors[] = ['kolom' => $kolom, 'pesan' => $pesan];
        };

        // Nomor SPT
        $nomor = $r['nomor_spt'];
        $kunciNomor = mb_strtolower($nomor);
        if ($r['nomor_spt_numerik']) {
            $galat('nomor_spt', 'Nomor SPT '.self::ANGKA);
        } elseif ($nomor === '') {
            $galat('nomor_spt', 'Nomor SPT wajib diisi.');
        } elseif (mb_strlen($nomor) > 255) {
            $galat('nomor_spt', 'Nomor SPT maksimal 255 karakter.');
        } elseif (isset($l['nomor'][$kunciNomor])) {
            $galat('nomor_spt', "Nomor SPT {$nomor} sudah ada.");
        } elseif (isset($seenNomor[$kunciNomor])) {
            $galat('nomor_spt', "Nomor SPT {$nomor} ganda dengan baris {$seenNomor[$kunciNomor]}.");
        } else {
            $seenNomor[$kunciNomor] = $r['baris'];
        }

        // Dates
        $tgl = [];
        foreach (['tanggal_spt' => 'Tanggal SPT', 'tanggal_mulai' => 'Tanggal mulai', 'tanggal_selesai' => 'Tanggal selesai'] as $f => $label) {
            $tgl[$f] = $r[$f] === '' ? null : Tanggal::dariSel($r[$f]);
            if ($r[$f] === '') {
                $galat($f, "{$label} wajib diisi.");
            } elseif ($tgl[$f] === null) {
                $galat($f, self::FORMAT_TANGGAL);
            }
        }
        if ($tgl['tanggal_mulai'] && $tgl['tanggal_selesai'] && $tgl['tanggal_selesai'] < $tgl['tanggal_mulai']) {
            $galat('tanggal_selesai', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        // References
        $irban = $l['irban'][mb_strtolower($r['irban'])] ?? null;
        if (! $irban) {
            $galat('irban', $r['irban'] === '' ? 'Irban wajib diisi.' : "Irban \"{$r['irban']}\" tidak ditemukan.");
        }
        $klas = $l['klasifikasi'][mb_strtolower($r['klasifikasi'])] ?? null;
        if (! $klas) {
            $galat('klasifikasi', $r['klasifikasi'] === '' ? 'Klasifikasi wajib diisi.' : "Klasifikasi \"{$r['klasifikasi']}\" tidak ditemukan.");
        }
        $jenis = $r['jenis_penugasan'] === '' ? null : ($l['jenis'][mb_strtolower($r['jenis_penugasan'])] ?? null);
        if ($r['jenis_penugasan'] !== '' && ! $jenis) {
            $galat('jenis_penugasan', "Jenis penugasan \"{$r['jenis_penugasan']}\" tidak ditemukan.");
        }

        // Text
        if ($r['uraian_untuk'] === '') {
            $galat('uraian_untuk', 'Uraian wajib diisi.');
        } elseif (mb_strlen($r['uraian_untuk']) > 1000) {
            $galat('uraian_untuk', 'Uraian maksimal 1000 karakter.');
        }
        if (mb_strlen($r['periode_diperiksa']) > 255) {
            $galat('periode_diperiksa', 'Periode maksimal 255 karakter.');
        }

        // Objek
        $objekIds = [];
        foreach ($this->daftar($r['objek']) as $nama) {
            $ids = $l['objek'][mb_strtolower($nama)] ?? [];
            if (count($ids) === 1) {
                $objekIds[] = $ids[0];
            } else {
                $galat('objek', $ids ? "Objek \"{$nama}\" lebih dari satu; gunakan nama yang unik." : "Objek \"{$nama}\" tidak ditemukan.");
            }
        }

        // Personel: supervisor, ketua, anggota in cell order
        $personel = [];
        $angka = false;
        $dilihat = [];
        $susunan = []; // every given NIP with its role, for the composition rules
        foreach (self::NIP as $h => $peran) {
            if ($r["{$h}_numerik"]) {
                $galat($h, 'NIP '.self::ANGKA);
                $angka = true;
            }
            foreach ($this->daftar($r[$h]) as $nip) {
                $susunan[] = ['pegawai_id' => $nip, 'peran' => $peran->value];
                if (isset($dilihat[$nip])) {
                    $galat($h, "NIP {$nip} tercantum lebih dari sekali.");

                    continue;
                }
                $dilihat[$nip] = true;
                $id = $l['pegawai'][$nip] ?? null;
                if ($id === null) {
                    $galat($h, "NIP {$nip} tidak ditemukan.");
                } else {
                    $personel[] = ['pegawai_id' => $id, 'peran' => $peran->value];
                }
            }
        }
        // A numeric cell hides its NIPs, so the composition cannot be judged until the column is text.
        if ($susunan && ! $angka) {
            $cek = app(ValidatePersonel::class)->cek($susunan);
            if (! $cek['ketua']) {
                $galat('nip_ketua', 'Isi tepat satu NIP ketua.');
            }
            if (! $cek['supervisor']) {
                $galat('nip_supervisor', 'Isi paling banyak satu NIP supervisor.');
            }
        }

        // LHP, selesai, kendala
        $tanggalLhp = $r['tanggal_lhp'] === '' ? null : Tanggal::dariSel($r['tanggal_lhp']);
        if (($r['nomor_lhp'] === '') !== ($r['tanggal_lhp'] === '')) {
            $galat('nomor_lhp', 'Nomor dan tanggal LHP diisi bersama.');
        }
        if (mb_strlen($r['nomor_lhp']) > 255) {
            $galat('nomor_lhp', 'Nomor LHP maksimal 255 karakter.');
        }
        if ($r['tanggal_lhp'] !== '' && $tanggalLhp === null) {
            $galat('tanggal_lhp', self::FORMAT_TANGGAL);
        }
        $lhp = $r['nomor_lhp'] !== '' && $r['tanggal_lhp'] !== '';
        $kolomSelesai = mb_strtolower($r['selesai']);
        if (! in_array($kolomSelesai, ['ya', 'tidak', ''], true)) {
            $galat('selesai', 'Kolom selesai diisi ya atau tidak.');
        }
        $selesai = $kolomSelesai === 'ya' || ($kolomSelesai === '' && $lhp);
        $kategori = null;
        if (($r['kendala_kategori'] === '') !== ($r['kendala_uraian'] === '')) {
            $galat('kendala_kategori', 'Kategori dan uraian kendala diisi bersama.');
        } elseif ($r['kendala_kategori'] !== '') {
            $kategori = $l['kendala'][mb_strtolower($r['kendala_kategori'])] ?? null;
            if ($kategori === null) {
                $galat('kendala_kategori', "Kategori kendala \"{$r['kendala_kategori']}\" tidak dikenal.");
            }
        }
        if (mb_strlen($r['kendala_uraian']) > 2000) {
            $galat('kendala_uraian', 'Uraian kendala maksimal 2000 karakter.');
        }

        $nomorUrut = null;
        $pkptItemId = null;
        $warnings = [];
        if (! $errors) {
            $segmen = explode('/', $nomor)[1] ?? '';
            $nomorUrut = preg_match('/^\d{1,9}$/', $segmen) ? (int) $segmen : null;
            $tahun = (int) substr($tgl['tanggal_spt'], 0, 4);

            $kandidat = $jenis ? $this->itemCocok($l['item'], $irban->id, $jenis->id, $tgl['tanggal_mulai'], $tgl['tanggal_selesai']) : collect();
            if ($kandidat->count() === 1) {
                $pkptItemId = $kandidat->first()->id;
            } elseif ($kandidat->count() > 1) {
                $warnings[] = 'Lebih dari satu item PKPT cocok; SPT tidak dikaitkan.';
            } elseif ($klas->wajib_item_pkpt) {
                $warnings[] = 'Tidak ada item PKPT yang cocok; SPT tercatat Non-PKPT.';
            }

            if ($nomorUrut === null) {
                $warnings[] = 'Nomor urut tidak terbaca; SPT tidak tampil di Register.';
            } elseif ($tahun >= $l['tahunIni']) {
                $k = $this->penomoran->kunci($irban, $tahun);
                if ($nomorUrut > ($l['counter']["{$k['tahun']}|{$k['scope']}"] ?? 0)) {
                    $warnings[] = "Nomor urut {$nomorUrut} melebihi nomor terakhir register {$tahun}; atur nomor awal di Register sebelum menerbitkan nomor baru.";
                }
            }

            if ($selesai && $klas->syarat_selesai === SyaratSelesai::LhpLengkap && ! $lhp) {
                $warnings[] = 'SPT selesai tanpa nomor dan tanggal LHP.';
            }
        }

        $data = [
            'irban_id' => $irban?->id, 'klasifikasi_id' => $klas?->id, 'jenis_penugasan_id' => $jenis?->id, 'pkpt_item_id' => $pkptItemId,
            // The kode arsip is the first segment of the old number when it is in the master, else 700 (as the 1.2 backfill).
            'kode_arsip_id' => $l['kodeArsip'][mb_strtolower(trim(explode('/', $nomor)[0]))] ?? $l['kodeArsip']['700'] ?? null,
            'ppj_pegawai_id' => $irban && $tgl['tanggal_mulai'] ? ($l['ppj'][$irban->id.'|'.substr($tgl['tanggal_mulai'], 0, 4)] ?? null) : null,
            'nomor_lengkap' => $nomor, 'nomor_urut' => $nomorUrut, 'tahun' => $tgl['tanggal_spt'] ? (int) substr($tgl['tanggal_spt'], 0, 4) : null,
            'tanggal_spt' => $tgl['tanggal_spt'], 'tanggal_mulai' => $tgl['tanggal_mulai'], 'tanggal_selesai' => $tgl['tanggal_selesai'],
            'uraian_untuk' => $r['uraian_untuk'], 'periode_diperiksa' => $r['periode_diperiksa'] === '' ? null : $r['periode_diperiksa'],
            'objek_ids' => array_values(array_unique($objekIds)), 'personel' => $personel,
            'nomor_lhp' => $r['nomor_lhp'] === '' ? null : $r['nomor_lhp'], 'tanggal_lhp' => $tanggalLhp, 'selesai' => $selesai,
            'kendala_kategori' => $kategori, 'kendala_uraian' => $kategori === null ? null : $r['kendala_uraian'],
            // Display strings for the preview.
            'irban' => $irban?->nama ?? $r['irban'], 'klasifikasi' => $klas?->nama ?? $r['klasifikasi'], 'jenis' => $jenis?->nama ?? $r['jenis_penugasan'],
            'nomor_spt' => $nomor, 'jumlah_personel' => count($personel),
        ];

        return ['baris' => $r['baris'], 'data' => $data, 'errors' => $errors, 'warnings' => $warnings,
            'status' => $errors ? 'error' : ($warnings ? 'warning' : 'valid')];
    }
}
