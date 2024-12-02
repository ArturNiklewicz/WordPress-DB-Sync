// Example config.php file
return [
    'production' => [
        'db_host' => 'production-host',
        'db_name' => 'prod_wordpress',
        'db_user' => 'prod_user',
        'db_pass' => 'prod_pass',
        'site_url' => 'https://www.example.com'
    ],
    'development' => [
        'db_host' => 'localhost',
        'db_name' => 'dev_wordpress',
        'db_user' => 'dev_user',
        'db_pass' => 'dev_pass',
        'site_url' => 'http://localhost:8080'
    ]
];
