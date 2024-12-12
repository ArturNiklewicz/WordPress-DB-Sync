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
    private $config;
    private $logFile;
    private $lastSyncFile;
    private $minSyncInterval = 300; // 5 minutes between syncs
    
    private $excludedTables = [
        'wp_users',
        'wp_usermeta'
    ];

    public function __construct() {
        $this->logFile = dirname(__FILE__) . '/sync_log.txt';
        $this->lastSyncFile = dirname(__FILE__) . '/.last_sync';
        $this->loadConfig();
        $this->validateEnvironment();
        $this->validateFilePermissions();
        $this->checkRateLimit();
    }

    /**
     * Loads and validates configuration
     */
    private function loadConfig() {
        $configFile = dirname(__FILE__) . '/config.php';

        $this->config = require $configFile;
        $this->validateConfig();
    }

    /**
     * Validates configuration
     */
    private function validateConfig() {
        // Validate production config
        $requiredProdKeys = ['ssh_host', 'ssh_user', 'ssh_port', 'db_host', 'db_name', 'db_user', 'db_pass', 'site_url', 'wp_path'];
        $stages = ['production','staging', 'development'];
        foreach ($requiredProdKeys as $key) {
            foreach ($stages as $stage) {   
                if (!isset($this->config[$stage][$key])) {
                    throw new Exception("Missing required {$stage} config key: {$key}");
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
        $requiredCommands = ['mysql', 'mysqldump', 'ssh'];
        foreach ($requiredCommands as $command) {
            exec("which {$command}", $output, $returnVar);
            if ($returnVar !== 0) {
                throw new Exception("{$command} is not available on this system");
            }
        }
    }

    /**
     * Validates file permissions for sensitive files
     * 
     * @throws Exception if file permissions are too loose
     */
    private function validateFilePermissions() {
        // Check config file permissions
        $configFile = dirname(__FILE__) . '/config.php';
        $configPerms = fileperms($configFile) & 0777;
        if ($configPerms > 0600) {
            throw new Exception("Config file permissions too loose: {$configPerms}. Should be 600 or less.");
        }

        // Check log file permissions
        if (file_exists($this->logFile)) {
            $logPerms = fileperms($this->logFile) & 0777;
            if ($logPerms > 0644) {
                throw new Exception("Log file permissions too loose: {$logPerms}. Should be 644 or less.");
            }
        }

        // Ensure script itself has correct permissions
        $scriptPerms = fileperms(__FILE__) & 0777;
        if ($scriptPerms > 0755) {
            throw new Exception("Script file permissions too loose: {$scriptPerms}. Should be 755 or less.");
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
     * Executes a command via SSH on a remote server
     * 
     * @param string $environment Environment to connect to
     * @param string $command Command to execute
     * @return array Output of the command
     * @throws Exception on SSH command failure
     */
    private function executeSSHCommand($environment, $command) {
        $sshCmd = sprintf(
            'ssh -o PasswordAuthentication=no -o BatchMode=yes -o StrictHostKeyChecking=accept-new -p %d %s@%s %s',
            $this->config[$environment]['ssh_port'],
            escapeshellarg($this->config[$environment]['ssh_user']),
            escapeshellarg($this->config[$environment]['ssh_host']),
            escapeshellarg($command)
        );

        exec($sshCmd . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("SSH command failed: " . implode("\n", $output));
        }

        return $output;
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
            'mysqldump --opt -h %s -u %s -p%s %s',
            escapeshellarg($this->config[$environment]['db_host']),
            escapeshellarg($this->config[$environment]['db_user']),
            escapeshellarg($this->config[$environment]['db_pass']),
            escapeshellarg($this->config[$environment]['db_name'])
        );

        if ($environment === 'production' || $environment === 'staging') {
            // Use SSH to execute the command on remote environments
            $cmd .= " > {$backupFile}";
            $this->executeSSHCommand($environment, $cmd);
        } else {
            // Execute locally for development
            $cmd .= " > " . escapeshellarg($backupFile);
            exec($cmd . ' 2>&1', $output, $returnVar);
            
            if ($returnVar !== 0) {
                throw new Exception("Backup failed: " . implode("\n", $output));
            }
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

        if ($environment === 'production') {
            // For production, use SSH to run mysqldump
            $sshCmd = sprintf(
                'ssh -p %d %s@%s mysqldump --opt --single-transaction -h %s -u %s -p%s %s %s',
                $this->config['production']['ssh_port'],
                escapeshellarg($this->config['production']['ssh_user']),
                escapeshellarg($this->config['production']['ssh_host']),
                escapeshellarg($this->config['production']['db_host']),
                escapeshellarg($this->config['production']['db_user']),
                escapeshellarg($this->config['production']['db_pass']),
                escapeshellarg($this->config['production']['db_name']),
                $excludeTables
            );
            
            $cmd = $sshCmd . ' > temp_dump.sql';
        } elseif ($environment === 'staging') {
            // For staging, use SSH to run mysqldump
            $sshCmd = sprintf(
                'ssh -p %d %s@%s mysqldump --opt --single-transaction -h %s -u %s -p%s %s %s',
                $this->config['staging']['ssh_port'],
                escapeshellarg($this->config['staging']['ssh_user']),
                escapeshellarg($this->config['staging']['ssh_host']),
                escapeshellarg($this->config['staging']['db_host']),
                escapeshellarg($this->config['staging']['db_user']),
                escapeshellarg($this->config['staging']['db_pass']),
                escapeshellarg($this->config['staging']['db_name']),
                $excludeTables
            );
            
            $cmd = $sshCmd . ' > temp_dump.sql';
        } else {
            // For development, use local mysqldump
            $cmd = sprintf(
                'mysqldump --opt --single-transaction -h %s -u %s -p%s %s %s > temp_dump.sql',
                escapeshellarg($this->config[$environment]['db_host']),
                escapeshellarg($this->config[$environment]['db_user']),
                escapeshellarg($this->config[$environment]['db_pass']),
                escapeshellarg($this->config[$environment]['db_name']),
                $excludeTables
            );
        }

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
