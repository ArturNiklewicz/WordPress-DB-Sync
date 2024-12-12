#!/opt/homebrew/opt/php@7.4/bin/php
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
    private const YELLOW = "\033[33m";  // Add yellow color for messages
    private const BLUE = "\033[34m";
    private const RED = "\033[31m";
    private const RESET = "\033[0m";
    
    private $config;
    private $logFile;
    private $lastSyncFile;
    private $minSyncInterval = 60; // 1 minutes between syncs
    private $validatedEnvironments = [];  // Track which environments have been validated
    
    private $excludedTables = [
        'wp_users',
        'wp_usermeta'
    ];

    public function __construct() {
        $this->logFile = dirname(__FILE__) . '/sync.log';
        $this->lastSyncFile = dirname(__FILE__) . '/.last_sync';
        $this->loadConfig();
        $this->checkRateLimit();
    }

    /**
     * Loads and validates configuration
     */
    private function loadConfig() {
        $configFile = dirname(__FILE__) . '/config.php';

        // Debug: Check if file exists
        if (!file_exists($configFile)) {
            throw new Exception("Config file not found: {$configFile}");
        }

        $config = require $configFile;
        
        // Debug: Verify config is an array and not being printed
        if (!is_array($config)) {
            throw new Exception("Config file must return an array");
        }
        
        $this->config = $config;
        $this->validateConfig();
    }

    /**
     * Validates configuration
     */
    private function validateConfig() {
        // Validate production config
        $requiredProdKeys = ['ssh_host', 'ssh_user', 'ssh_port', 'db_host', 'db_name', 'db_user', 'db_pass', 'site_url', 'wp_path'];
        $stages = ['production', 'staging', 'development'];
        
        foreach ($stages as $stage) {
            foreach ($requiredProdKeys as $key) {   
                if (!isset($this->config[$stage][$key])) {
                    throw new Exception("Missing required {$stage} config key: {$key}");
                }
            }
        }
    }

    /**
     * Implements rate limiting for sync operations
     * 
     * @throws Exception if minimum time between syncs hasn't elapsed
     */
    private function checkRateLimit() {
        if (file_exists($this->lastSyncFile)) {
            $lastSync = (int)file_get_contents($this->lastSyncFile);
            $timeSinceLastSync = time() - $lastSync;
            
            if ($timeSinceLastSync < $this->minSyncInterval) {
                $waitTime = $this->minSyncInterval - $timeSinceLastSync;
                throw new Exception(
                    sprintf(
                        "Rate limit exceeded. Please wait %d minutes and %d seconds before next sync.",
                        floor($waitTime / 60),
                        $waitTime % 60
                    )
                );
            }
        }
    }

    /**
     * Updates the last sync timestamp
     */
    private function updateLastSyncTime() {
        file_put_contents($this->lastSyncFile, time());
        chmod($this->lastSyncFile, 0600); // Secure the timestamp file
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
            // Update last sync time at the start
            $this->updateLastSyncTime();
            
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
            $backupFile = $this->createBackup($targetEnv);
            
            // Export from source and import to target
            $dumpFile = $this->exportDatabase($sourceEnv);
            $this->importDatabase($targetEnv, $dumpFile);
            
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
     * Executes a command via SSH on a remote server
     * 
     * @param string $environment Environment to connect to
     * @param string $command Command to execute
     * @return array Output of the command
     * @throws Exception on SSH command failure
     */
    private function executeSSHCommand($environment, $command) {
        $this->validateEnvironment($environment);
        
        $sshCmd = sprintf(
            'ssh -p %s %s@%s %s',
            escapeshellarg($this->config[$environment]['ssh_port']),
            escapeshellarg($this->config[$environment]['ssh_user']),
            escapeshellarg($this->config[$environment]['ssh_host']),
            escapeshellarg($command)
        );

        exec($sshCmd . ' 2>&1', $output, $returnVar);
        
        // Log command with its output
        $this->log(null, "SSH {$environment}: {$command}", !empty($output) ? implode("\n", $output) : null);

        if ($returnVar !== 0) {
            throw new Exception("SSH command failed: " . implode("\n", $output));
        }

        return $output;
    }

    /**
     * Validates the current environment if not already validated in this session
     * 
     * @param string $environment The environment to validate
     * @throws Exception if required commands are not available
     */
    private function validateEnvironment($environment) {
        // Skip validation if already done for this environment
        if (isset($this->validatedEnvironments[$environment])) {
            return;
        }
        
        $this->log("Validating environment on {$environment} server...");
        
        // Build validation command
        $validationCmd = 'which mysql mysqldump php sed grep';
        
        $sshConfig = $this->config[$environment];
        $sshCmd = sprintf(
            'ssh -p %s %s@%s %s',
            escapeshellarg($sshConfig['ssh_port']),
            escapeshellarg($sshConfig['ssh_user']),
            escapeshellarg($sshConfig['ssh_host']),
            escapeshellarg($validationCmd)
        );
        
        exec($sshCmd . ' 2>&1', $output, $returnVar);
        if ($returnVar !== 0) {
            throw new Exception("Connection failed or required commands not available on {$environment} server");
        }
        
        // Mark this environment as validated
        $this->validatedEnvironments[$environment] = true;
        $this->log("Environment validation successful on {$environment} server");
    }

    /**
     * Creates a backup of the target database
     * 
     * @param string $environment Environment to backup
     * @throws Exception on backup failure
     */
    private function createBackup($environment) {
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = $this->config[$environment]['wp_path'];
        $backupFile = "backup_{$environment}_{$timestamp}.sql";
        
        // Log initial parameters
        $this->log("Starting backup for environment: {$environment}");
        $this->log("Backup directory: {$backupDir}");
        $this->log("Backup filename: {$backupFile}");

        // Check if backup directory exists
        $checkDirCmd = sprintf("[ -d %s ] && echo 'Directory exists' || echo 'Directory does not exist'", 
            escapeshellarg($backupDir)
        );
        $this->log("Checking directory existence...");
        $this->executeSSHCommand($environment, $checkDirCmd);

        // Get current working directory before operations
        $this->log("Checking current working directory...");
        $this->executeSSHCommand($environment, 'pwd');
        
        // Create dump command and log it
        $dumpCmd = sprintf(
            'mysqldump --opt -h %s -u %s -p%s %s',
            escapeshellarg($this->config[$environment]['db_host']),
            escapeshellarg($this->config[$environment]['db_user']),
            escapeshellarg($this->config[$environment]['db_pass']),
            escapeshellarg($this->config[$environment]['db_name'])
        );
        
        $cdCmd = sprintf(
            'cd %s && pwd',
            escapeshellarg($this->config[$environment]['wp_path'])
        );

        // Combine commands for the final execution
        $fullCmd = sprintf('%s && %s > %s', 
            $cdCmd,
            $dumpCmd, 
            escapeshellarg($backupFile)
        );
        $this->executeSSHCommand($environment, $fullCmd);
        
        $this->executeSSHCommand($environment, sprintf('ls -l %s', escapeshellarg("{$backupDir}/{$backupFile}")));
        
        $this->log("Backup created: {$backupDir}/{$backupFile}");
        return "{$backupDir}/{$backupFile}";
    }

    /**
     * Exports database from source environment
     * 
     * @param string $environment Source environment
     * @throws Exception on export failure
     * @return string Path to the exported dump file
     */
    private function exportDatabase($environment) {
        // Generate exclude tables argument
        $excludeTables = array_map(function($table) use ($environment) {
            return sprintf("--ignore-table=%s.%s", 
                $this->config[$environment]['db_name'], 
                $table
            );
        }, $this->excludedTables);
        $excludeTablesStr = implode(' ', $excludeTables);

        // Prepare mysqldump command with all necessary options
        $dumpCmd = sprintf(
            'mysqldump --opt --single-transaction -h %s -u %s -p%s %s %s',
            escapeshellarg($this->config[$environment]['db_host']),
            escapeshellarg($this->config[$environment]['db_user']),
            escapeshellarg($this->config[$environment]['db_pass']),
            escapeshellarg($this->config[$environment]['db_name']),
            $excludeTablesStr
        );

        // Determine output filename
        $timestamp = date('Y-m-d_H-i-s');
        $dumpFile = "temp_export_{$environment}_{$timestamp}.sql";

        // Prepare full command with output redirection
        $fullCmd = "{$dumpCmd} > {$dumpFile}";

        // If the environment has SSH configuration, use SSH to execute
        if (isset($this->config[$environment]['ssh_host'])) {
            // For remote environments, verify file existence via SSH
            $fullCmd = "{$dumpCmd} > {$dumpFile} && ls -l {$dumpFile}";
            $output = $this->executeSSHCommand($environment, $fullCmd);
            
            // If no exception was thrown, the file was created
            $this->log("Database export successful on remote server: {$dumpFile}");
        } else {
            // Local execution
            exec($fullCmd . ' 2>&1', $output, $returnVar);
            
            if ($returnVar !== 0) {
                throw new Exception("Export failed: " . implode("\n", $output));
            }

            // Verify local file exists
            if (!file_exists($dumpFile)) {
                throw new Exception("Export file {$dumpFile} was not created");
            }

            $this->log("Database export successful locally: {$dumpFile}");
        }

        return $dumpFile;
    }

    /**
     * Imports database to target environment
     * 
     * @param string $environment Target environment
     * @param string $dumpFile Path to the dump file
     * @throws Exception on import failure
     */
    private function importDatabase($environment, $dumpFile) {
        $cmd = sprintf(
            'mysql -h %s -u %s -p%s %s < %s',
            escapeshellarg($this->config[$environment]['db_host']),
            escapeshellarg($this->config[$environment]['db_user']),
            escapeshellarg($this->config[$environment]['db_pass']),
            escapeshellarg($this->config[$environment]['db_name']),
            escapeshellarg($dumpFile)
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
        $tmpFiles = ['temp_dump.sql', 'temp_export_production.sql', 'temp_export_staging.sql', 'temp_export_development.sql'];
        foreach ($tmpFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Logs a message with timestamp and optional command output
     * 
     * @param string $message Message to log
     * @param string|null $command Optional command that was executed
     * @param string|null $output Optional output from command
     */
    private function log($message, $command = null, $output = null) {
        if ($command !== null) {
            $command = preg_replace("/-p['\"](.*?)['\"]/", "-p'****'", $command);
        }
        $timestamp = date('Y-m-d H:i:s');
        
        // Format for file logging (without colors)
        $logMessage = "[{$timestamp}] ";
        if ($command !== null) {
            $logMessage .= "Command: {$command}" . ($output ? " | Output: {$output}" : "") . "\n";
        } else {
            $logMessage .= "{$message}\n";
        }
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        
        // Format for terminal output (with colors)
        if ($command !== null) {
            // Command mode: Blue command + Red output
            echo "[{$timestamp}] " . self::BLUE . $command . self::RESET;
        
                echo " | " . self::RED . $output . self::RESET;
            }
            if ($output) {
                echo "\n"; 
            } else if ($message !== null) {
                // Message mode: Yellow text
                echo "[{$timestamp}] " . self::YELLOW . $message . self::RESET . "\n";
            }
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
