<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Isi TRUSTED_PROXIES hanya bila aplikasi berada di belakang proxy/load
    | balancer (mis. Cloudflare, Nginx terpisah): daftar IP dipisah koma, atau
    | "*" bila server hanya bisa diakses lewat proxy itu. Dibiarkan kosong, IP
    | pengunjung tidak bisa dipalsukan lewat header X-Forwarded-For.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
