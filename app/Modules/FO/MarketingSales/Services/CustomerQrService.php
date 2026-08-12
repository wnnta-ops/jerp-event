<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\MarketingSales\Models\Customer;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\HtmlString;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * QR per customer untuk alur Pilih Unit hari-H launching.
 *
 * Isi QR = KODE customer (mis. CUST-000123) — bukan data pribadi mentah — supaya
 * aman bila QR tersebar & selalu sinkron: saat di-scan, Pilih Unit menarik
 * nama/alamat/No.NUP terbaru dari database via kode ini.
 */
class CustomerQrService
{
    public function __construct(
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    /** Pastikan customer punya kode (terbitkan via numerator bila masih kosong). */
    public function ensureCode(Customer $customer): string
    {
        if (empty($customer->code)) {
            $customer->update(['code' => $this->numerator->next('CUST')]);
        }

        return (string) $customer->code;
    }

    /** Awalan penanda payload QR customer. */
    public const PREFIX = 'CUST:';

    /**
     * Payload yang ditanam di QR: "CUST:<kode>".
     *
     * WAJIB berawalan. Kode customer hasil migrasi berupa ANGKA (1, 2, 3, …) dan
     * 304 di antaranya sama persis dengan No. NUP; tanpa awalan, pemindaian yang
     * mencocokkan No. NUP lebih dulu bisa mengenali QR sebagai CUSTOMER LAIN.
     */
    public function payload(Customer $customer): string
    {
        return self::PREFIX.$this->ensureCode($customer);
    }

    /** Render QR sebagai SVG inline (tanpa butuh imagick). */
    public function svg(string $payload, int $size = 220): HtmlString
    {
        return new HtmlString(
            (string) QrCode::format('svg')->size($size)->margin(1)->errorCorrection('M')->generate($payload)
        );
    }
}
