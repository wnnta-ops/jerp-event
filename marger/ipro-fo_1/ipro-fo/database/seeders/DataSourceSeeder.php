<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Tenant;
use App\Domain\Master\Customer\Models\DataSource;
use Illuminate\Database\Seeder;

class DataSourceSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        $sources = [
            ['code' => 'PAMERAN',       'name' => 'Pameran',              'description' => 'Pameran / event property', 'display_order' => 10],
            ['code' => 'ONLINE',        'name' => 'Online / Website',     'description' => 'Lead dari website / social media ads', 'display_order' => 20],
            ['code' => 'REFERRAL',      'name' => 'Referral',             'description' => 'Referral dari customer existing', 'display_order' => 30],
            ['code' => 'WALK_IN',       'name' => 'Walk-In',              'description' => 'Customer datang langsung ke gallery / sales office', 'display_order' => 40],
            ['code' => 'BROCHURE',      'name' => 'Brochure / Flyer',     'description' => 'Lead dari brochure / flyer offline', 'display_order' => 50],
            ['code' => 'SOCIAL_MEDIA',  'name' => 'Social Media (Umum)',  'description' => 'Lead organik dari social media (tidak spesifik)', 'display_order' => 60],
            ['code' => 'SM_INSTAGRAM',  'name' => 'Social Media — Instagram', 'description' => 'Lead dari Instagram', 'display_order' => 61],
            ['code' => 'SM_FACEBOOK',   'name' => 'Social Media — Facebook',  'description' => 'Lead dari Facebook', 'display_order' => 62],
            ['code' => 'SM_TIKTOK',     'name' => 'Social Media — TikTok',    'description' => 'Lead dari TikTok', 'display_order' => 63],
            ['code' => 'SM_WHATSAPP',   'name' => 'Social Media — WhatsApp',  'description' => 'Lead dari WhatsApp', 'display_order' => 64],
            ['code' => 'SM_YOUTUBE',    'name' => 'Social Media — YouTube',   'description' => 'Lead dari YouTube', 'display_order' => 65],
            ['code' => 'SM_GOOGLE',     'name' => 'Social Media — Google/SEM','description' => 'Lead dari Google Ads / pencarian online', 'display_order' => 66],
            ['code' => 'AGENT',         'name' => 'Property Agent',       'description' => 'Lead dari agent property eksternal', 'display_order' => 70],
            ['code' => 'COLD_CALL',     'name' => 'Cold Call',            'description' => 'Hasil canvassing telepon', 'display_order' => 80],
            ['code' => 'EVENT_MUP',     'name' => 'Event MUP',            'description' => 'Lead dari event internal MUP', 'display_order' => 90],
            ['code' => 'OTHER',         'name' => 'Lainnya',              'description' => 'Sumber lain yang belum terkategori', 'display_order' => 999],
        ];

        foreach ($sources as $src) {
            DataSource::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $src['code']],
                array_merge($src, ['is_active' => true, 'tenant_id' => $tenant->id]),
            );
        }
    }
}
