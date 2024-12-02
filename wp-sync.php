#!/usr/bin/php
<?php
/**
 * WordPress Database Sync Tool
 * 
 * This script safely synchronizes WordPress databases between environments
 * with proper error handling and logging.
 * 
 * Usage: ./wp-sync.php [direction]
 * where direction is either 'prod_to_dev' or 'dev_to_prod'
 * 
 * @author Your Name
 * @version 1.0.0
 */

class WordPressDatabaseSync {
    /** @var array Configuration for different environments */
    private $config;
    
    /** @var string Path to log file */
    private $logFile;
    
    /** @var array Tables to exclude from sync */
    private $excludedTables = [
        'wp_users',
        'wp_usermeta'
    ];

    /**
     * Constructor
     * 
     * @throws Exception if config file is missing or invalid
     */
    public function __construct() {
        $this->logFile = dirname(__FILE__) . '/sync_log.txt';
        $this->loadConfig();
        $this->validateEnvironment();
    }

    /**
     * Loads configuration from config file
     * 
     * @throws Exception if config file is missing or invalid
     */
    private function loadConfig() {
        $configFile = dirname(__FILE__) . '/config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception('Config file not found. Please create config.php');
        }

        $this->config = require $configFile;
        
        // Validate config structure
        $requiredKeys = ['production', 'development'];
        $requiredSubKeys = ['db_host', 'db_name', 'db_user', 'db_pass', 'site_url'];
        
        foreach ($requiredKeys as $key) {
            if (!isset($this->config[$key])) {
                throw new Exception("Missing required config key: {$key}");
            }
            foreach ($requiredSubKeys as $subKey) {
                if (!isset($this->config[$key][$subKey])) {
                    throw new Exception("Missing required config subkey: {$key}.{$subKey}");
                }
            }
        }
    }

    /**
     * Validates the current environment
     * 
     * @throws Exception if required commands are not available
     */
    private function validateEnvironment() {
        $requiredCommands = ['mysql', 'mysqldump'];
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
     * @param string $direction Either 'prod_to_dev' or 'dev_to_prod'
     * @throws Exception on sync failure
     */
    public function sync($direction = 'prod_to_dev') {
        $this->log("Starting sync: {$direction}");
        
        try {
            // Validate direction
            if (!in_array($direction, ['prod_to_dev', 'dev_to_prod'])) {
                throw new Exception('Invalid sync direction');
            }

            $source = ($direction === 'prod_to_dev') ? 'production' : 'development';
            $target = ($direction === 'prod_to_dev') ? 'development' : 'production';

            // Confirm if syncing to production
            if ($direction === 'dev_to_prod') {
                $this->confirmProductionSync();
            }

            // Create backup
            $this->createBackup($target);

            // Perform sync
            $this->exportDatabase($source);
            $this->importDatabase($target);
            $this->updateUrls(
                $this->config[$source]['site_url'],
                $this->config[$target]['site_url']
            );

            // Cleanup
            $this->cleanup();
            
            $this->log("Sync completed successfully");
            
        } catch (Exception $e) {
            $this->log("Error during sync: " . $e->getMessage());
            $this->cleanup();
            throw $e;
        }
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
            'mysqldump --opt --single-transaction -h %s -u %s -p%s %s %s > temp_dump.sql',
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
            'mysql -h %s -u %s -p%s %s < temp_dump.sql',
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
     * Updates URLs in the database
     * 
     * @param string $oldUrl Source URL
     * @param string $newUrl Target URL
     * @throws Exception on update failure
     */
    private function updateUrls($oldUrl, $newUrl) {
        $queries = [
            // Update WordPress core URLs
            "UPDATE wp_options SET option_value = replace(option_value, %s, %s) 
             WHERE option_name = 'home' OR option_name = 'siteurl';",
            
            // Update content URLs
            "UPDATE wp_posts SET post_content = replace(post_content, %s, %s);",
            
            // Update meta values
            "UPDATE wp_postmeta SET meta_value = replace(meta_value, %s, %s) 
             WHERE meta_value LIKE %s;",
            
            // Update serialized data
            "UPDATE wp_options SET option_value = replace(option_value, %s, %s) 
             WHERE option_value LIKE %s;"
        ];

        $mysqli = new mysqli(
            $this->config['development']['db_host'],
            $this->config['development']['db_user'],
            $this->config['development']['db_pass'],
            $this->config['development']['db_name']
        );

        if ($mysqli->connect_error) {
            throw new Exception("Database connection failed: " . $mysqli->connect_error);
        }

        foreach ($queries as $query) {
            $stmt = $mysqli->prepare($query);
            if ($stmt === false) {
                throw new Exception("Query preparation failed: " . $mysqli->error);
            }

            $oldUrlLike = '%' . $oldUrl . '%';
            $stmt->bind_param('sss', $oldUrl, $newUrl, $oldUrlLike);
            
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

// Example config.php file (should be created separately)
/*
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
*/
