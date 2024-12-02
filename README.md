# WordPress Database Sync Tool

Simple but reliable PHP script to safely sync WordPress databases between environments with proper error handling, logging, and backups.

## TL;DR
```bash
# Setup
cp config.example.php config.php
# Edit config.php with your database credentials
chmod +x wp-sync.php

# Usage
./wp-sync.php prod_to_dev  # Sync production to development
./wp-sync.php dev_to_prod  # Sync development to production
```

## Features
- Automatic database backups
- Safe URL replacement
- Production sync confirmation
- Error logging
- Excluded sensitive tables (users, usermeta)
- Transaction-safe dumps
- Shell argument escaping

## Requirements
- PHP 7.0+
- MySQL/MariaDB
- `mysqldump` and `mysql` commands available
- Write permissions in script directory

## Config Example
```php
// config.php
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
```

## Security
- Never commit `config.php`
- Always use restricted database users
- Review excluded tables in `$excludedTables`

## License
MIT
