<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pemeriksaan waktu ubah view
    |--------------------------------------------------------------------------
    |
    | Di produksi semua view sudah dikompilasi oleh deploy.sh (view:cache +
    | app:warm-up) sebelum rilis aktif, jadi tidak perlu dicek ulang di setiap
    | permintaan. Pemeriksaan ini juga bisa keliru menganggap view kedaluwarsa
    | bila dikompilasi di detik yang sama dengan file sumbernya, lalu
    | mengompilasinya ulang di tengah trafik. Biarkan aktif saat pengembangan.
    |
    */

    'check_cache_timestamps' => (bool) env('VIEW_CHECK_CACHE_TIMESTAMPS', true),

];
