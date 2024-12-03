// WordPress Database Sync Configuration
return [
    'production' => [
        'ssh_host' => 'ip',
        'ssh_user' => 'webadmin',
        'ssh_port' => 22,
        'wp_path' => '/home/webadmin/public_html/example.com',
        'site_url' => 'http://example.com',
        'table_prefix' => 'wp_'
        // Database credentials will be auto-detected from wp-config.php on the production server
    ],
    'staging' => [
        'ssh_host' => 'ip',
        'ssh_user' => 'webadmin',
        'ssh_port' => 22,
        'wp_path' => '/home/webadmin/public_html/stage.example.com',
        'site_url' => 'http://stage.example.com',
        'table_prefix' => 'wp_'
        // Database credentials will be auto-detected from wp-config.php on the staging server
    ],
    'development' => [
        'ssh_host' => 'ip',
        'ssh_user' => 'webadmin',  // Adjust if different
        'ssh_port' => 22,
        'wp_path' => '/var/www/html/dev.example.com',
        'site_url' => 'http://dev.example.com',
        'table_prefix' => 'wp_'
        // Database credentials will be auto-detected from local wp-config.php
    ]
];
