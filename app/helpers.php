<?php

use App\Support\ArabicShaper;

if (!function_exists('ar')) {
    /**
     * Shape a static Arabic string written directly in a Blade PDF template
     * (e.g. `<th>{{ ar('الموظف') }}</th>`) — anything NOT already passed
     * through ArabicShaper::shape()/shapeDeep() from a controller. Without
     * this, dompdf renders it as disconnected, wrong-order letterforms.
     * Only use inside PDF views; regular HTML pages render Arabic correctly
     * on their own and must never be shaped (it would corrupt the text).
     */
    function ar(?string $text): ?string
    {
        return ArabicShaper::shape($text);
    }
}
