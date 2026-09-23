<?php

return [
    // Populated once EAS builds are published. Left null until a real, verified
    // artifact exists — the download page renders an honest "not yet available"
    // state rather than a dead link.
    'version' => env('MOBILE_APP_VERSION', '1.2.0'),
    'android_url' => env('MOBILE_APP_ANDROID_URL'),
    'ios_url' => env('MOBILE_APP_IOS_URL'),
    'play_store_url' => env('MOBILE_APP_PLAY_STORE_URL'),
    'app_store_url' => env('MOBILE_APP_APP_STORE_URL'),
    'android_size' => env('MOBILE_APP_ANDROID_SIZE'),
    'min_android' => env('MOBILE_APP_MIN_ANDROID', '6.0'),
    'min_ios' => env('MOBILE_APP_MIN_IOS', '15.1'),
];
