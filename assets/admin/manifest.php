<?php
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'id' => '/public/admin/',
    'name' => 'Hercule License Admin',
    'short_name' => 'Hercule Admin',
    'description' => 'Secure mobile administration for Hercule POS licenses.',
    'start_url' => '/public/admin/index.php?source=pwa',
    'scope' => '/public/admin/',
    'display' => 'standalone',
    'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
    'orientation' => 'any',
    'background_color' => '#0d1117',
    'theme_color' => '#151b23',
    'lang' => 'en',
    'dir' => 'ltr',
    'categories' => ['business', 'productivity', 'security'],
    'icons' => [
        [
            'src' => '/public/admin/assets/icons/app-icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => '/public/admin/assets/icons/app-icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
    'shortcuts' => [
        ['name' => 'Dashboard', 'short_name' => 'Dashboard', 'url' => '/public/admin/index.php', 'icons' => [['src' => '/public/admin/assets/icons/app-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']]],
        ['name' => 'Customers', 'short_name' => 'Customers', 'url' => '/public/admin/customers.php'],
        ['name' => 'Licenses', 'short_name' => 'Licenses', 'url' => '/public/admin/licenses.php'],
        ['name' => 'Recovery', 'short_name' => 'Recovery', 'url' => '/public/admin/recovery_requests.php'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
