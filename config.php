// Example config.php file
return [
    'production' => [
        'ssh_host' => 'your-production-server.com',  // Your production server hostname
        'ssh_user' => 'your-ssh-username',           // Your SSH username
        'ssh_port' => 22,                           // SSH port (usually 22)
        'db_host' => 'production-host',             // Production MySQL host
        'db_name' => 'prod_wordpress',              // Production database name
        'db_user' => 'prod_user',                   // Production database user
        'db_pass' => 'prod_pass',                   // Production database password
        'site_url' => 'https://www.example.com',    // Production site URL
        'wp_path' => '/path/to/wordpress',          // Path to WordPress on production server
        'table_prefix' => 'wp_'                     // Production table prefix
    ],
    'development' => [
        'db_host' => 'localhost',
        'db_name' => 'dev_wordpress',
        'db_user' => 'dev_user',
        'db_pass' => 'dev_pass',
        'site_url' => 'http://localhost:8080'       // Your local development URL
        // Other development settings will be auto-detected from your local wp-config.php
    ]
];
