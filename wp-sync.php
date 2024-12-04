#!/usr/bin/php
<?php
/**
 * WordPress Database Sync Tool
 * 
 * This script safely synchronizes WordPress databases between environments
 * with proper error handling and logging.
 * Validates current path and detects correct database for each domain
 * 
 * Usage: ./wp-sync.php [direction]
 * where direction is either 'prod_to_dev', 'dev_to_prod', 'prod_to_stage', 'stage_to_dev', 'stage_to_prod', or 'dev_to_stage'
 * 
 * @author Artur Niklewicz
 * @version 1.0.0
 */

class WordPressDatabaseSync {
    private $config;
    private $logFile;
    private $wpConfig;
    
    private $excludedTables = [
        'wp_users',
        'wp_usermeta'
    ];

    public function __construct() {
        $this->logFile = dirname(__FILE__) . '/sync_log.txt';
        $this->detectCurrentSetup();
        $this->loadConfig();
        $this->validateEnvironment();
    }

    /**
     * Detects current WordPress setup from path
     * @throws Exception if WordPress is not detected
     */
    private function detectCurrentSetup() {
        // Get current directory path
        $currentPath = getcwd();
        
        // Find wp-config.php by traversing up
        $configPath = $this->findWPConfig($currentPath);
        if (!$configPath) {
            throw new Exception("No WordPress installation detected in current path: {$currentPath}");
        }

        // Parse wp-config.php to get database details
        $this->wpConfig = $this->parseWPConfig($configPath);
        
        $this->log("Detected WordPress installation:");
        $this->log("Database: {$this->wpConfig['DB_NAME']}");
    }

    /**
     * Finds WordPress config file
     * @param string $startPath Path to start searching from
     * @return string|false Path to wp-config.php or false if not found
     */
    private function findWPConfig($startPath) {
        $configFile = 'wp-config.php';
        $currentPath = $startPath;
        
        while ($currentPath !== '/') {
            if (file_exists($currentPath . '/' . $configFile)) {
                return $currentPath . '/' . $configFile;
            }
            $currentPath = dirname($currentPath);
        }
        
        return false;
    }

    /**
     * Parses WordPress config file
     * @param string $configPath Path to wp-config.php
     * @return array WordPress configuration values
     */
    private function parseWPConfig($configPath) {
        $configContent = file_get_contents($configPath);
        
        $configs = [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => null,
            'table_prefix' => 'wp_'
        ];

        foreach ($configs as $key => &$value) {
            if (preg_match("/define\(\s*['\"]" . $key . "['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $configContent, $matches)) {
                $value = $matches[1];
            } else if ($key === 'table_prefix') {
                if (preg_match("/\\\$table_prefix\s*=\s*['\"](.*?)['\"]\s*;/", $configContent, $matches)) {
                    $value = $matches[1];
                }
            }
        }

        if (in_array(null, $configs, true)) {
            throw new Exception("Could not parse all required values from wp-config.php");
        }

        return $configs;
    }

    /**
     * Loads and validates configuration
     */
    private function loadConfig() {
        $configFile = dirname(__FILE__) . '/config.php';
        
        if (!file_exists($configFile)) {
            $this->createDefaultConfig($configFile);
            throw new Exception("Default config file created at {$configFile}. Please edit it with your settings.");
        }

        $this->config = require $configFile;
        
        // Auto-detect database credentials for production and staging
        $this->detectRemoteCredentials('production');
        $this->detectRemoteCredentials('staging');
        
        // Development environment uses local WordPress config
        $this->config['development'] = array_merge($this->config['development'] ?? [], [
            'db_host' => $this->wpConfig['DB_HOST'],
            'db_name' => $this->wpConfig['DB_NAME'],
            'db_user' => $this->wpConfig['DB_USER'],
            'db_pass' => $this->wpConfig['DB_PASSWORD'],
            'table_prefix' => $this->wpConfig['table_prefix']
        ]);

        $this->validateConfig();
    }

    /**
     * Creates default configuration file
     */
    private function createDefaultConfig($configFile) {
        $defaultConfig = <<<PHP
<?php
return [
    'production' => [
        'ssh_host' => 'your-production-server.com',
        'ssh_user' => 'production-username',
        'ssh_port' => 22,
        'db_host' => 'localhost',
        'db_name' => 'production_wordpress',
        'db_user' => 'production_db_user',
        'db_pass' => 'production_db_pass',
        'site_url' => 'https://www.example.com',
        'table_prefix' => 'wp_',
        'wp_path' => '/home/user/public_html/domain.com'
    ],
    'staging' => [
        'ssh_host' => 'your-staging-server.com',
        'ssh_user' => 'staging-username',
        'ssh_port' => 22,
        'db_host' => 'localhost',
        'db_name' => 'staging_wordpress',
        'db_user' => 'staging_db_user',
        'db_pass' => 'staging_db_pass',
        'site_url' => 'https://staging.example.com',
        'table_prefix' => 'wp_',
        'wp_path' => '/home/user/public_html/staging.domain.com'
    ],
    'development' => [
        'site_url' => 'https://dev.example.com'
    ]
];
PHP;
        file_put_contents($configFile, $defaultConfig);
    }

    /**
     * Validates configuration
     */
    private function validateConfig() {
        // Validate production config
        $requiredProdKeys = ['ssh_host', 'ssh_user', 'ssh_port', 'db_host', 'db_name', 'db_user', 'db_pass', 'site_url', 'wp_path'];
        foreach ($requiredProdKeys as $key) {
            if (!isset($this->config['production'][$key])) {
                throw new Exception("Missing required production config key: {$key}");
            }
        }

        // Validate staging config
        $requiredStageKeys = ['ssh_host', 'ssh_user', 'ssh_port', 'db_host', 'db_name', 'db_user', 'db_pass', 'site_url', 'wp_path'];
        foreach ($requiredStageKeys as $key) {
            if (!isset($this->config['staging'][$key])) {
                throw new Exception("Missing required staging config key: {$key}");
            }
        }

        // Development config is auto-detected from local WordPress
        if (!isset($this->config['development']['site_url'])) {
            throw new Exception("Missing development site URL in config");
        }
    }

    /**
     * Validates the current environment
     * 
     * @throws Exception if required commands are not available
     */
    private function validateEnvironment() {
        $requiredCommands = ['mysql', 'mysqldump', 'ssh'];
        foreach ($requiredCommands as $command) {
            exec("which {$command}", $output, $returnVar);
            if ($returnVar !== 0) {
                throw new Exception("{$command} is not available on this system");
            }
        }
    }

    /**
     * Main sync method
     * 
     * @param string $direction Format: 'source_to_target' (e.g., 'prod_to_dev', 'stage_to_dev', 'prod_to_stage')
     * @throws Exception on sync failure
     */
    public function sync($direction = 'prod_to_dev') {
        $this->log("Starting sync: {$direction}");
        try {
            // Parse direction
            $parts = explode('_to_', $direction);
            if (count($parts) !== 2) {
                throw new Exception("Invalid direction format. Use: source_to_target (e.g., prod_to_dev, stage_to_dev)");
            }
            
            $sourceEnv = $this->getEnvironmentKey($parts[0]);
            $targetEnv = $this->getEnvironmentKey($parts[1]);
            
            // Confirm if syncing to production
            if ($targetEnv === 'production') {
                $this->confirmProductionSync();
            }
            
            // Create backup of target database
            $this->createBackup($targetEnv);
            
            // Export from source and import to target
            $this->exportDatabase($sourceEnv);
            $this->importDatabase($targetEnv);
            
            // Update URLs in target database
            $this->updateRemoteUrls($targetEnv, $sourceEnv);
            
            // Cleanup temporary files
            $this->cleanup();
            
            $this->log("Sync completed successfully");
        } catch (Exception $e) {
            $this->log("Error during sync: " . $e->getMessage());
            $this->cleanup();
            throw $e;
        }
    }
    
    /**
     * Convert environment shorthand to full key
     * 
     * @param string $env Environment shorthand (prod/stage/dev)
     * @return string Full environment key
     * @throws Exception if invalid environment
     */
    private function getEnvironmentKey($env) {
        $envMap = [
            'prod' => 'production',
            'stage' => 'staging',
            'dev' => 'development'
        ];
        
        if (!isset($envMap[$env])) {
            throw new Exception("Invalid environment: {$env}. Use: prod, stage, or dev");
        }
        
        return $envMap[$env];
    }

    /**
     * Creates a backup of the target database
     * 
     * @param string $environment Environment to backup
     * @throws Exception on backup failure
     */
    private function createBackup($environment) {
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = dirname(__FILE__) . "/backup_{$environment}_{$timestamp}.sql";
        
        $cmd = sprintf(
            'mysqldump --opt -h %s -u %s -p%s %s > %s',
            escapeshellarg($this->config[$environment]['db_host']),
            escapeshellarg($this->config[$environment]['db_user']),
            escapeshellarg($this->config[$environment]['db_pass']),
            escapeshellarg($this->config[$environment]['db_name']),
            escapeshellarg($backupFile)
        );

        exec($cmd . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new Exception("Backup failed: " . implode("\n", $output));
        }
        
        $this->log("Backup created: {$backupFile}");
    }

    /**
     * Exports database from source environment
     * 
     * @param string $environment Source environment
     * @throws Exception on export failure
     */
    private function exportDatabase($environment) {
        $excludeTables = '';
        foreach ($this->excludedTables as $table) {
            $excludeTables .= " --ignore-table=" . 
                            $this->config[$environment]['db_name'] . 
                            "." . $table;
        }

        $cmd = sprintf(
            'ssh -p %d %s@%s "mysqldump --opt --single-transaction -h %s -u %s -p%s %s %s" > temp_dump.sql',
            $this->config[$environment]['ssh_port'],
            escapeshellarg($this->config[$environment]['ssh_user']),
            escapeshellarg($this->config[$environment]['ssh_host']),
            escapeshellarg($this->config[$environment]['db_host']),
            escapeshellarg($this->config[$environment]['db_user']),
            escapeshellarg($this->config[$environment]['db_pass']),
            escapeshellarg($this->config[$environment]['db_name']),
            $excludeTables
        );

        exec($cmd . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new Exception("Export failed: " . implode("\n", $output));
        }
    }

    /**
     * Imports database to target environment
     * 
     * @param string $environment Target environment
     * @throws Exception on import failure
     */
    private function importDatabase($environment) {
        $cmd = sprintf(
            'ssh -p %d %s@%s "mysql -h %s -u %s -p%s %s" < temp_dump.sql',
            $this->config[$environment]['ssh_port'],
            escapeshellarg($this->config[$environment]['ssh_user']),
            escapeshellarg($this->config[$environment]['ssh_host']),
            escapeshellarg($this->config[$environment]['db_host']),
            escapeshellarg($this->config[$environment]['db_user']),
            escapeshellarg($this->config[$environment]['db_pass']),
            escapeshellarg($this->config[$environment]['db_name'])
        );

        exec($cmd . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new Exception("Import failed: " . implode("\n", $output));
        }
    }

    /**
     * Updates URLs in the remote database
     * 
     * @param string $environment Target environment
     * @param string $sourceEnv Source environment
     * @throws Exception on update failure
     */
    private function updateRemoteUrls($environment, $sourceEnv) {
        // Use detected table prefix
        $prefix = $this->config[$environment]['table_prefix'];
        
        $queries = [
            "UPDATE {$prefix}options SET option_value = replace(option_value, '%s', '%s') WHERE option_name = 'home' OR option_name = 'siteurl';",
            "UPDATE {$prefix}posts SET post_content = replace(post_content, '%s', '%s');",
            "UPDATE {$prefix}postmeta SET meta_value = replace(meta_value, '%s', '%s') WHERE meta_value LIKE '%%%s%%';",
            "UPDATE {$prefix}options SET option_value = replace(option_value, '%s', '%s') WHERE option_value LIKE '%%%s%%';"
        ];

        $mysqli = new mysqli(
            $this->config[$environment]['db_host'],
            $this->config[$environment]['db_user'],
            $this->config[$environment]['db_pass'],
            $this->config[$environment]['db_name']
        );

        if ($mysqli->connect_error) {
            throw new Exception("Database connection failed: " . $mysqli->connect_error);
        }

        foreach ($queries as $query) {
            $stmt = $mysqli->prepare($query);
            if ($stmt === false) {
                throw new Exception("Query preparation failed: " . $mysqli->error);
            }

            $oldUrlLike = '%' . $this->config[$sourceEnv]['site_url'] . '%';
            $stmt->bind_param('sss', $this->config[$sourceEnv]['site_url'], $this->config[$environment]['site_url'], $oldUrlLike);
            
            if (!$stmt->execute()) {
                throw new Exception("Query execution failed: " . $stmt->error);
            }
            
            $stmt->close();
        }

        $mysqli->close();
    }

    /**
     * Confirms with user before syncing to production
     * 
     * @throws Exception if user does not confirm
     */
    private function confirmProductionSync() {
        echo "\nWARNING: You are about to sync to production.\n";
        echo "This will overwrite the production database.\n";
        echo "Are you sure you want to continue? (y/N): ";
        
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        if (trim(strtolower($line)) !== 'y') {
            throw new Exception('Sync to production cancelled by user');
        }
    }

    /**
     * Cleans up temporary files
     */
    private function cleanup() {
        $tmpFiles = ['temp_dump.sql'];
        foreach ($tmpFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Logs a message with timestamp
     * 
     * @param string $message Message to log
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;
    }

    /**
     * Detects database credentials from remote wp-config.php
     * @param string $environment The environment to detect credentials for
     */
    private function detectRemoteCredentials($environment) {
        $sshCmd = sprintf(
            'ssh -p %d %s@%s "php -r \\"include(\'%s/wp-config.php\'); echo json_encode([\'DB_HOST\' => DB_HOST, \'DB_NAME\' => DB_NAME, \'DB_USER\' => DB_USER, \'DB_PASSWORD\' => DB_PASSWORD]);\\"" 2>/dev/null',
            $this->config[$environment]['ssh_port'],
            escapeshellarg($this->config[$environment]['ssh_user']),
            escapeshellarg($this->config[$environment]['ssh_host']),
            escapeshellarg($this->config[$environment]['wp_path'])
        );
        
        exec($sshCmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new Exception("Failed to detect remote credentials for {$environment}. Ensure SSH key-based authentication is set up.");
        }
        
        $credentials = json_decode($output[0], true);
        if (!$credentials) {
            throw new Exception("Failed to parse remote credentials for {$environment}");
        }
        
        $this->config[$environment]['db_host'] = $credentials['DB_HOST'];
        $this->config[$environment]['db_name'] = $credentials['DB_NAME'];
        $this->config[$environment]['db_user'] = $credentials['DB_USER'];
        $this->config[$environment]['db_pass'] = $credentials['DB_PASSWORD'];
    }
}

// Script execution
try {
    $sync = new WordPressDatabaseSync();
    $direction = isset($argv[1]) ? $argv[1] : 'prod_to_dev';
    $sync->sync($direction);
    echo "Sync completed successfully\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}