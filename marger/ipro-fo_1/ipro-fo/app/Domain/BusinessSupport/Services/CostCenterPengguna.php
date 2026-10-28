<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsEmployee;

/**
 * Cost center (unit kerja) milik seorang pengguna — SATU definisi untuk seluruh sistem.
 *
 * Dipakai mengisi otomatis cost center pada Registrasi Invoice Hutang & Bon Sementara, dan
 * mencetak "Departemen" pada Bukti Permohonan Pembayaran serta SIP. Sebelum kelas ini,
 * logikanya ditulis dua kali di ApInvoiceController dan SipController; dua salinan yang suatu
 * saat berbeda berarti dokumen pembayaran dan layar input menyebut unit yang BERBEDA untuk
 * orang yang sama — dan biaya menempel ke departemen yang salah tanpa satu pun galat.
 *
 * ── JALUR PENCOCOKANNYA ──
 *
 * 1. `users.employee_nip` = `bs_employees.nik` — jalur resmi, diisi otomatis oleh
 *    IproMasterSync saat akun dibuat di J-ERP.
 * 2. Cadangan: kesamaan NAMA. Dipertahankan karena akun lama belum tentu ber-NIP, dan tanpa
 *    cadangan ini mereka kehilangan cost center-nya diam-diam.
 *
 * NAMA KEMBAR: yang pertama menang, dan itu memang tebakan. Karena itu nama hanya dipakai bila
 * NIP kosong — menebak di antara dua karyawan senama berarti membebankan biaya ke divisi yang
 * salah.
 *
 * ── TIDAK ADA MASTER DEPARTEMEN ──
 *
 * Dulu ada `bs_hr_departments`, tetapi tidak pernah diisi (0 baris, department_id NULL di
 * seluruh karyawan) sehingga setiap dokumen tercetak "Tanpa Departemen". Master itu sudah
 * dicabut; cost center-lah yang isinya memang nama divisi (Marketing, Finance & Accounting,
 * Operasional, Proyek/Konstruksi, HRD & GA). Sebutan "Departemen" tetap dipakai di dokumen.
 */
class CostCenterPengguna
{
    /** Cost center pengguna, atau null bila akunnya belum tertaut ke karyawan mana pun. */
    public function untuk(?object $user): ?BsCostCenter
    {
        $emp = $this->karyawan($user);

        if ($emp?->cost_center_id === null) {
            return null;
        }

        return BsCostCenter::find($emp->cost_center_id);
    }

    /** Id-nya saja — untuk mengisi isian di layar. */
    public function idUntuk(?object $user): ?int
    {
        $emp = $this->karyawan($user);

        return $emp?->cost_center_id !== null ? (int) $emp->cost_center_id : null;
    }

    /**
     * SEMUA departemen pengguna (Utama lebih dulu, lalu tambahan). Dipakai pilihan departemen
     * Pengajuan Bon Sementara dan hak melihat bon tim — tempat lain tetap memakai Utama.
     *
     * @return list<int>
     */
    public function idsUntuk(?object $user): array
    {
        return $this->karyawan($user)?->costCenterIds() ?? [];
    }

    /**
     * Nama unit untuk DICETAK.
     *
     * Jatuh ke Jabatan bila cost center-nya belum diisi: pada dokumen pembayaran, "Staff
     * Accounting" masih lebih berguna daripada tanda hubung — walaupun ia menyebut peran
     * orangnya, bukan unit yang menanggung biayanya.
     */
    public function namaUnit(?object $user, string $kosong = '-'): string
    {
        $emp = $this->karyawan($user);

        if ($emp === null) {
            return $kosong;
        }

        $nama = trim((string) ($emp->costCenter?->name ?? ''));

        return $nama !== '' ? $nama : (trim((string) $emp->position) ?: $kosong);
    }

    /**
     * Id KARYAWAN yang tertaut ke akun ini — dipakai mengisi otomatis "Nama Karyawan" pada
     * Pengajuan Bon Sementara, yang dari situ unit kerjanya diturunkan.
     */
    public function idKaryawan(?object $user): ?int
    {
        return $this->karyawan($user)?->id;
    }

    /**
     * NAMA karyawan yang tertaut ke akun ini — dipakai mencocokkan kolom `holder` pada bon.
     *
     * `holder` menyimpan nama tampilan ("Nama — Jabatan"), bukan id, sehingga kepemilikan bon
     * lama yang `created_by`-nya kosong hanya bisa dikenali lewat nama ini. Dikembalikan apa
     * adanya — pemanggilnya yang menyusun pencocokan, supaya tidak ada yang memakai LIKE
     * berawalan dan ikut menyeret nama lain yang berawalan sama.
     */
    public function namaKaryawan(?object $user): ?string
    {
        $nama = trim((string) ($this->karyawan($user)?->name ?? ''));

        return $nama !== '' ? $nama : null;
    }

    /**
     * Nama MANAGER cost center pengguna ini — penanda tangan "Dari" / "Hormat Kami" pada
     * Memo Internal. Isinya kolom Manager di master Cost Center (SPV ke atas per departemen).
     * null bila penggunanya tak tertaut ke karyawan, atau kolom Manager-nya masih kosong.
     */
    public function namaManager(?object $user): ?string
    {
        return self::managerDari($this->untuk($user));
    }

    /** Nama Manager sebuah cost center, atau null bila kosong. */
    public static function managerDari(?BsCostCenter $cc): ?string
    {
        $nama = trim((string) ($cc?->manager ?? ''));

        return $nama !== '' ? $nama : null;
    }

    /** Karyawan yang tertaut ke akun ini — lewat NIP dulu, baru nama. */
    private function karyawan(?object $user): ?BsEmployee
    {
        if ($user === null) {
            return null;
        }

        $nip = trim((string) ($user->employee_nip ?? ''));
        if ($nip !== '') {
            $emp = BsEmployee::with('costCenter:id,code,name')->where('nik', $nip)->first();
            if ($emp !== null) {
                return $emp;
            }
        }

        $nama = trim((string) ($user->name ?? ''));
        if ($nama === '') {
            return null;
        }

        return BsEmployee::with('costCenter:id,code,name')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nama)])
            ->first();
    }
}
