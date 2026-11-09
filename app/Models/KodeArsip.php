<?php

namespace App\Models;

use App\Models\Concerns\DicatatAktivitas;
use Database\Factories\KodeArsipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Kode arsip of an SPT number (`{kode_arsip}`), picked per SPT (1.2 Task 2). Issued numbers keep their kode when a row changes. */
#[Fillable(['kode', 'nama'])]
class KodeArsip extends Model
{
    /** @use HasFactory<KodeArsipFactory> */
    use DicatatAktivitas, HasFactory;

    protected $table = 'kode_arsip';
}
