# WordPress Database Sync Tool
[Uploading wp-sync-flowchart TD
    A[Start Sync Process] --> B{Check Current Directory}
    B -->|Not Found| C[Throw Exception:<br/>No WordPress Installation]
    B -->|Found| D[Detect WordPress Setup]
    
    D --> E[Find wp-config.php]
    E --> F[Parse WordPress Config]
    F --> G[Load Sync Config]
    
    G -->|Config Missing| H[Create Default Config]
    H --> I[Exit: Edit Config First]
    
    G -->|Config Found| J[Validate Environment]
    J --> K{Check Required Commands}
    K -->|Missing| L[Throw Exception:<br/>Missing Requirements]
    
    K -->|All Present| M{Parse Sync Direction}
    M -->|Invalid| N[Throw Exception:<br/>Invalid Direction]
    
    M -->|Valid| O{Target is Production?}
    O -->|Yes| P[Prompt for Confirmation]
    P -->|Denied| Q[Cancel Sync]
    P -->|Confirmed| R[Continue Sync]
    O -->|No| R
    
    R --> S[Create Target DB Backup]
    S --> T[Export Source Database]
    T --> U[Import to Target]
    U --> V[Update Site URLs]
    
    V --> W[Cleanup Temp Files]
    W --> X[Log Success]
    
    subgraph "Safety Checks"
        Y[Excluded Tables:<br/>wp_users<br/>wp_usermeta]
        Z[Backup Creation<br/>Before Any Changes]
    end
    
    subgraph "Error Handling"
        AA[All Operations<br/>in Try-Catch Block]
        BB[Detailed Error Logging]
        CC[Cleanup on Failure]
    end
flowchart.mermaid…]()

Simple but reliable PHP script to safely sync WordPress databases between environments with proper error handling, logging, and backups.

## TL;DR
```bash
Edit config.php with your database credentials
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
