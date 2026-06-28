<?php

use Illuminate\Support\Facades\Schema;

foreach (['refunds', 'cancellation_requests'] as $t) {
    if (! Schema::hasTable($t)) {
        echo "MISSING: {$t}\n";
        continue;
    }
    echo "== {$t} ==\n  ".implode(', ', Schema::getColumnListing($t))."\n";
}
