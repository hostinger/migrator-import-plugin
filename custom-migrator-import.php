#!/usr/bin/env php
<?php
/**
 * Unified Hostinger Migration Importer
 *
 * A flexible WordPress site migration tool that supports both complex 
 * and simple migration archives.
 *
 * @package    UnifiedMigrator
 * @version    1.0.2
 * @author     Your Name
 * @license    GPL-2.0+
 */

declare(strict_types=1);

// Prevent direct access
if (PHP_SAPI !== 'cli') {
    die('This script can only be run from the command line.');
}

// Simple debug function for CLI arguments
function debug_args() {
    $args = $_SERVER['argv'];
    file_put_contents('args_debug.log', print_r($args, true));
    echo "Arguments logged to args_debug.log\n";
}

class MigrationImporter {
    /**
     * Archive file path
     */
    private string $archiveFile;

    /**
     * Working directory for extraction
     */
    private string $workingDir;

    /**
     * Log file path
     */
    private string $logFile;

    /**
     * Verbose mode flag
     */
    private bool $verbose;

    /**
     * Debug mode flag
     */
    private bool $debugMode;

    /**
     * Skip content import flag
     */
    private bool $skipContent;

    /**
     * Constructor
     *
     * @param array $options Command line options
     */
    public function __construct(array $options)
    {
        // Write options to log for debugging
        file_put_contents('options_debug.log', print_r($options, true));
        
        // Validate required options
        if (empty($options['file'])) {
            $this->displayError('Archive file name is required. Use --file=filename.hstgr');
        }

        // Set properties
        $this->archiveFile = $options['file'];
        
        // Handle destination directory - default to current directory if not set or invalid
        if (!empty($options['dest']) && is_string($options['dest'])) {
            $this->workingDir = $options['dest'];
        } else {
            $this->workingDir = getcwd();
        }
        
        $this->workingDir = rtrim($this->workingDir, '/');
        $this->verbose = isset($options['verbose']);
        $this->debugMode = isset($options['debug']);
        $this->skipContent = isset($options['skip-content']);

        // Initialize log file
        $this->logFile = getcwd() . '/unified-migrator-import-log.txt';
    }

    /**
     * Run the import process
     */
    public function run(): void
    {
        try {
            $this->log("=== Unified Migrator Import Started ===");
            $this->log("Archive File: {$this->archiveFile}");
            $this->log("Destination: {$this->workingDir}");
            $this->log("Verbose Mode: " . ($this->verbose ? 'Enabled' : 'Disabled'));
            $this->log("Debug Mode: " . ($this->debugMode ? 'Enabled' : 'Disabled'));

            // Find the full path of the archive
            $fullArchivePath = $this->findArchiveFile();

            if ($this->skipContent) {
                $this->log("=== Skipping content extraction ===");
                return;
            }

            // Attempt primary extraction method
            try {
                $this->extractArchive($fullArchivePath);
            } catch (\Exception $e) {
                $this->log("Primary extraction method failed. Attempting fallback...");
                
                // Fallback to simple extraction if primary method fails
                $this->simpleFallbackExtract($fullArchivePath);
            }

            $this->displayFinalInstructions();

        } catch (\Exception $e) {
            $this->displayError($e->getMessage());
        }
    }

    /**
     * Find the archive file in various potential locations
     *
     * @return string Full path to the archive file
     * @throws \Exception If file cannot be found
     */
    private function findArchiveFile(): string
    {
        // Potential search paths
        $searchPaths = [
            $this->archiveFile, // As-is path
            getcwd() . '/' . $this->archiveFile,
            getcwd() . '/wp-content/hostinger-migration-archives/' . $this->archiveFile,
            $this->workingDir . '/wp-content/hostinger-migration-archives/' . $this->archiveFile
        ];

        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                $this->log("Found archive at: {$path}");
                return $path;
            }
        }

        throw new \Exception("Archive file not found. Searched in multiple locations: " . implode(", ", $searchPaths));
    }

    /**
     * Robust archive extraction method
     *
     * @param string $filepath Path to the archive file
     * @throws \Exception If extraction fails
     */
    private function extractArchive(string $filepath): void
    {
        $fp = fopen($filepath, "rb");
        if (!$fp) {
            throw new \Exception("Cannot open archive file.");
        }

        $fileCount = 0;
        $currentFile = null;
        $currentFp = null;
        $startTime = microtime(true);

        // Skip header lines
        while (!feof($fp) && ($line = fgets($fp))) {
            if (substr(trim($line), 0, 1) !== "#") {
                break;
            }
            $this->log("Header: " . trim($line), true);
        }

        // Process the file
        while (!feof($fp)) {
            $line = fgets($fp);
            if (empty($line)) continue;
            $trimmedLine = trim($line);
            
            // Check for file header
            if (strpos($trimmedLine, "__file__:") === 0) {
                // Close previous file if open
                if ($currentFp !== null) {
                    fclose($currentFp);
                    $currentFp = null;
                }
                
                // Get filename
                $currentFile = trim(substr($trimmedLine, 9));
                $fullPath = "{$this->workingDir}/{$currentFile}";
                
                // Create directory if it does not exist
                $dir = dirname($fullPath);
                if (!file_exists($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                continue;
            }
            
            // Get MD5 hash if present (after which we can open the file)
            if (strpos($trimmedLine, "__md5__:") === 0) {
                $fullPath = "{$this->workingDir}/{$currentFile}";
                $currentFp = fopen($fullPath, "wb");
                if (!$currentFp) {
                    $this->log("Cannot create file {$fullPath}");
                    continue;
                }
                $fileCount++;
                
                // Log progress periodically
                if ($fileCount % 100 === 0) {
                    $this->log("Extracted {$fileCount} files...");
                }
                
                continue;
            }
            
            // Check for file footer
            if ($trimmedLine === "__endfile__") {
                if ($currentFp !== null) {
                    fclose($currentFp);
                    $currentFp = null;
                }
                continue;
            }
            
            // Check for archive end
            if ($trimmedLine === "__done__") {
                break;
            }
            
            // Write file content if we have an open file
            if ($currentFp !== null) {
                fwrite($currentFp, $line);
            }
        }

        // Close any open files
        if ($currentFp !== null) {
            fclose($currentFp);
        }
        fclose($fp);

        $totalTime = microtime(true) - $startTime;
        $this->log(sprintf(
            "Extracted %d files in %.2f seconds", 
            $fileCount,
            $totalTime
        ));
    }

    /**
     * Simplified fallback extraction method
     *
     * @param string $filepath Path to the archive file
     * @throws \Exception If extraction fails
     */
    private function simpleFallbackExtract(string $filepath): void
    {
        $this->log("Attempting simplified fallback extraction...");

        $fp = fopen($filepath, "rb");
        if (!$fp) {
            throw new \Exception("Cannot open archive file for fallback extraction.");
        }

        $fileCount = 0;
        $currentFile = null;
        $currentFp = null;
        $startTime = microtime(true);

        while (!feof($fp)) {
            $line = fgets($fp);
            if (empty($line)) continue;
            $trimmedLine = trim($line);
            
            if (strpos($trimmedLine, "__file__:") === 0) {
                // Close previous file if open
                if ($currentFp !== null) {
                    fclose($currentFp);
                }
                
                $currentFile = trim(substr($trimmedLine, 9));
                $fullPath = "{$this->workingDir}/{$currentFile}";
                
                // Create directory if it does not exist
                $dir = dirname($fullPath);
                if (!file_exists($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                continue;
            }
            
            if (strpos($trimmedLine, "__md5__:") === 0) {
                $fullPath = "{$this->workingDir}/{$currentFile}";
                $currentFp = fopen($fullPath, "wb");
                if (!$currentFp) {
                    $this->log("Cannot create file {$fullPath}");
                    continue;
                }
                $fileCount++;
                
                // Log progress periodically
                if ($fileCount % 100 === 0) {
                    $this->log("Fallback extraction: {$fileCount} files processed...");
                }
                
                continue;
            }
            
            if ($trimmedLine === "__endfile__") {
                if ($currentFp !== null) {
                    fclose($currentFp);
                    $currentFp = null;
                }
                continue;
            }
            
            if ($trimmedLine === "__done__") {
                break;
            }
            
            if ($currentFp !== null) {
                fwrite($currentFp, $line);
            }
        }
        
        // Close any open files
        if ($currentFp !== null) {
            fclose($currentFp);
        }
        fclose($fp);

        $totalTime = microtime(true) - $startTime;
        $this->log(sprintf(
            "Fallback extraction complete. Extracted %d files in %.2f seconds", 
            $fileCount,
            $totalTime
        ));
    }

    /**
     * Display final instructions
     */
    private function displayFinalInstructions(): void
    {
        $this->log("\n=== Import Complete ===");
        $this->log("WordPress content files have been extracted successfully!");
        $this->log("\nNext steps:");
        $this->log("1. Update wp-config.php with correct database credentials");
        $this->log("2. Log in to WordPress admin and go to Settings > Permalinks");
        $this->log("3. Verify site functionality");
        $this->log("\n✅ File extraction completed successfully!");
        $this->log("Log file saved to: {$this->logFile}");
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param bool $debugOnly Log only in debug mode
     */
    private function log(string $message, bool $debugOnly = false): void
    {
        // Determine if message should be displayed
        $shouldDisplay = !$debugOnly || ($debugOnly && $this->debugMode);
        
        if ($shouldDisplay) {
            echo $message . PHP_EOL;
        }

        // Always append to log file
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Display error and exit
     *
     * @param string $message Error message
     */
    private function displayError(string $message): void
    {
        // Log error
        $this->log("ERROR: {$message}");
        
        // Display help information
        echo "\nUsage: php " . basename(__FILE__) . " --file=filename.hstgr [options]\n";
        echo "\nOptions:\n";
        echo "  --file=FILENAME       Required. The .hstgr archive file name\n";
        echo "  --dest=PATH           Optional. Destination directory (default: current directory)\n";
        echo "  --skip-content        Skip extracting content files\n";
        echo "  --verbose             Show more detailed output\n";
        echo "  --debug               Enable debug mode with detailed logging\n";
        echo "\nExample:\n";
        echo "  php " . basename(__FILE__) . " --file=site_export.hstgr\n";
        
        exit(1);
    }
}

// Main execution
try {
    // Log the raw arguments for debugging
    debug_args();
    
    // New custom argument parser
    $options = [];
    $args = $_SERVER['argv'];
    
    // Skip the first argument (script name)
    array_shift($args);
    
    // Handle paired arguments (--option value format)
    for ($i = 0; $i < count($args); $i++) {
        $arg = trim($args[$i], "'\"");
        
        // If this is a parameter (starts with --)
        if (substr($arg, 0, 2) === '--') {
            $key = substr($arg, 2); // Remove the --
            
            // Check if the next argument exists and doesn't start with --
            if (isset($args[$i+1]) && substr(trim($args[$i+1], "'\""), 0, 2) !== '--') {
                $options[$key] = trim($args[$i+1], "'\"");
                $i++; // Skip the next argument as we've processed it
            } else {
                $options[$key] = true; // Flag without value
            }
        }
    }
    
    // Debug the parsed options
    file_put_contents('parsed_options.log', print_r($options, true));
    
    // Display help if no arguments or explicitly requested
    if (empty($options) || isset($options['help'])) {
        $importer = new MigrationImporter(['file' => 'dummy']);
        exit(0);
    }
    
    // Run the importer
    $importer = new MigrationImporter($options);
    $importer->run();
    
} catch (\Throwable $e) {
    echo "Unexpected error: " . $e->getMessage() . PHP_EOL;
    // Log the full error details for debugging
    file_put_contents('error_log.txt', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo "Full error details logged to error_log.txt\n";
    exit(1);
}