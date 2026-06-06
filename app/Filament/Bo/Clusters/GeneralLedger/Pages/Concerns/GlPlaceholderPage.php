<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns;

use App\Filament\Bo\Clusters\GeneralLedger;
use Filament\Pages\Page;

/**
 * Base for not-yet-implemented GL menu items. Renders a labelled placeholder so
 * the GL menu mirrors the legacy structure 1:1; the actual content & flow of
 * each page is filled in from the operator's screenshots.
 */
abstract class GlPlaceholderPage extends Page
{
    protected static ?string $cluster = GeneralLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-document';

    protected static string $view = 'filament.bo.gl.placeholder';

    /** Short description of what this menu does (shown on the placeholder). */
    protected static string $keterangan = 'Menu ini akan disesuaikan dari screenshot legacy.';

    public function getKeterangan(): string
    {
        return static::$keterangan;
    }
}
