<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;

class BsPayrollLine extends Model
{
    public $timestamps = false;
    protected $table = 'bs_payroll_lines';
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'employee_name', 'gaji_pokok', 'tunjangan',
        'pph21', 'bpjs', 'potongan_lain', 'bruto', 'net',
    ];
    protected $casts = [
        'gaji_pokok' => 'decimal:2', 'tunjangan' => 'decimal:2', 'pph21' => 'decimal:2',
        'bpjs' => 'decimal:2', 'potongan_lain' => 'decimal:2', 'bruto' => 'decimal:2', 'net' => 'decimal:2',
    ];
}
