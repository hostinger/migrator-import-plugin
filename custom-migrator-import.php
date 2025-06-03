#!/usr/bin/env php
<?php
/**
 * Hostinger Migration Importer with Binary Archive Support
 *
 * A WordPress site migration tool that properly handles binary archives.
 *
 * @package    HostingerMigrator
 * @version    2.0.0
 * @author     Your Name
 * @license    GPL-2.0+
 */

declare(strict_types=1);

// Prevent direct access
if (PHP_SAPI !== 'cli') {
    die('This script can only be run from the command line.');
}

class HostingerMigrationImporter {
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
     * Block format for reading binary archives (optimized format)
     * 
     * @var array
     */
    private array $blockFormat = [
        'pack' => 'a255VVa4112',  // filename(255), size(4), date(4), path(4112) = 4375 bytes
        'unpack' => 'a255filename/Vsize/Vdate/a4112path'  // For unpack: named fields
    ];

    /**
     * Constructor
     */
    public function __construct(array $options)
    {
        if (!isset($options['file'])) {
            $this->displayError('Archive file name is required. Use --file=filename.hstgr');
        }

        $this->archiveFile = $options['file'];
        
        // Handle workingDir with proper fallback for getcwd() returning false
        if (isset($options['dest'])) {
            $this->workingDir = $options['dest'];
        } else {
            $currentDir = getcwd();
            $this->workingDir = $currentDir !== false ? $currentDir : '/tmp';
        }
        $this->workingDir = rtrim($this->workingDir, '/');
        
        $this->verbose = isset($options['verbose']);
        $this->debugMode = isset($options['debug']);
        $this->skipContent = isset($options['skip-content']);

        // Handle logFile with proper fallback for getcwd() returning false
        $currentDir = getcwd();
        $logDir = $currentDir !== false ? $currentDir : dirname($this->archiveFile);
        $this->logFile = $logDir . '/hostinger-migrator-import-log.txt';
    }

    /**
     * Run the import process
     */
    public function run(): void
    {
        try {
            $this->log("=== Hostinger Migration Import Started ===");
            $this->log("Archive File: {$this->archiveFile}");
            $this->log("Destination: {$this->workingDir}");
            $this->log("Verbose Mode: " . ($this->verbose ? 'Enabled' : 'Disabled'));

            $fullArchivePath = $this->findArchiveFile();

            if ($this->skipContent) {
                $this->log("=== Skipping content extraction ===");
                return;
            }

            // Check if this is a binary archive or legacy text archive
            if ($this->isBinaryArchive($fullArchivePath)) {
                $this->log("Detected binary archive format (optimized 4375-byte headers)");
                $this->extractBinaryArchive($fullArchivePath);
            } else {
                $this->log("Detected legacy text archive format, using fallback method");
                $this->fallbackTextExtract($fullArchivePath);
            }

            $this->displayFinalInstructions();

        } catch (\Exception $e) {
            $this->displayError($e->getMessage());
        }
    }

    /**
     * Find the archive file
     */
    private function findArchiveFile(): string
    {
        // Get current directory with fallback
        $currentDir = getcwd();
        $currentDirPath = $currentDir !== false ? $currentDir : dirname($this->archiveFile);
        
        $searchPaths = [
            $this->archiveFile,
            $currentDirPath . '/' . $this->archiveFile,
            $currentDirPath . '/wp-content/hostinger-migration-archives/' . $this->archiveFile,
            $this->workingDir . '/wp-content/hostinger-migration-archives/' . $this->archiveFile
        ];

        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                $this->log("Found archive at: {$path}");
                return $path;
            }
        }

        throw new \Exception("Archive file not found. Searched in multiple locations.");
    }

    /**
     * Check if archive uses binary format by examining file structure
     */
    private function isBinaryArchive(string $filepath): bool
    {
        $fp = fopen($filepath, "rb");
        if (!$fp) {
            return false;
        }

        // Check for new optimized format (4375 bytes)
        $blockSize = 4375;
        $block = fread($fp, $blockSize);
        fclose($fp);
        
        if (strlen($block) < $blockSize) {
            if ($this->debugMode) {
                $this->log("Archive too small for binary format: " . strlen($block) . " bytes (need $blockSize)", true);
            }
            return false;
        }

        // Try to unpack as binary format
        $data = @unpack($this->blockFormat['unpack'], $block);
        
        if (!$data || !isset($data['filename'], $data['size'], $data['date'], $data['path'])) {
            if ($this->debugMode) {
                $this->log("Failed to unpack first block as binary format", true);
            }
            return false;
        }

        $fileName = trim($data['filename'], "\0");
        $fileSize = $data['size'];
        $fileDate = $data['date'];
        $filePath = trim($data['path'], "\0");

        // Debug the first block data
        if ($this->debugMode) {
            $this->log("Binary detection - File: '$fileName', Size: $fileSize, Date: $fileDate, Path: '$filePath'", true);
            $this->log("Block size: " . strlen($block) . " bytes", true);
        }

        // Basic sanity checks for binary format
        $isValid = (
            !empty($fileName) && 
            strlen($fileName) <= 255 && 
            strlen($fileName) > 0 &&
            $fileSize >= 0 && 
            $fileSize < 1024 * 1024 * 1024 && // Less than 1GB per file
            $fileDate > 946684800 && // After year 2000
            $fileDate < time() + 86400 && // Not future dated beyond 1 day
            strlen($filePath) <= 4112 && 
            strpos($fileName, "\0") === false && // No embedded nulls after trim
            strpos($filePath, "\0") === false && // No embedded nulls after trim
            // More permissive filename pattern to allow various file types
            (preg_match('/^[a-zA-Z0-9._\-\s]+\.[a-zA-Z0-9]+$/', $fileName) || 
             preg_match('/^[a-zA-Z0-9._\-\s]+$/', $fileName))
        );

        if ($this->debugMode) {
            $this->log("Binary format validation result: " . ($isValid ? 'PASSED' : 'FAILED'), true);
            if (!$isValid) {
                $this->log("Validation details:", true);
                $this->log("- Filename not empty: " . (!empty($fileName) ? 'YES' : 'NO'), true);
                $this->log("- Filename length OK: " . ((strlen($fileName) <= 255 && strlen($fileName) > 0) ? 'YES' : 'NO'), true);
                $this->log("- File size valid: " . (($fileSize >= 0 && $fileSize < 1024 * 1024 * 1024) ? 'YES' : 'NO'), true);
                $this->log("- Date valid: " . (($fileDate > 946684800 && $fileDate < time() + 86400) ? 'YES' : 'NO'), true);
                $this->log("- Path length OK: " . ((strlen($filePath) <= 4112) ? 'YES' : 'NO'), true);
                $this->log("- Filename pattern OK: " . ((preg_match('/^[a-zA-Z0-9._\-\s]+\.[a-zA-Z0-9]+$/', $fileName) || preg_match('/^[a-zA-Z0-9._\-\s]+$/', $fileName)) ? 'YES' : 'NO'), true);
            }
        }

        return $isValid;
    }

    /**
     * Extract binary structured archive
     */
    private function extractBinaryArchive(string $filepath): void
    {
        $fp = fopen($filepath, "rb");
        if (!$fp) {
            throw new \Exception("Cannot open binary archive file.");
        }

        $fileCount = 0;
        $startTime = microtime(true);
        $blockSize = 4375; // filename(255), size(4), date(4), path(4112) = 4375 bytes
        
        $this->log("Starting binary archive extraction...");

        while (!feof($fp)) {
            // Read file header block
            $block = fread($fp, $blockSize);
            
            if (strlen($block) < $blockSize) {
                if (strlen($block) > 0) {
                    $this->log("Incomplete block at end of file, stopping extraction");
                }
                break;
            }

            // Unpack the binary block
            $data = @unpack($this->blockFormat['unpack'], $block);
            if (!$data || !isset($data['filename'], $data['size'], $data['date'], $data['path'])) {
                $this->log("Failed to unpack binary block, stopping extraction");
                if ($this->debugMode) {
                    $hexData = bin2hex(substr($block, 0, 32));
                    $this->log("Block hex data: $hexData", true);
                }
                break;
            }

            $fileName = trim($data['filename'], "\0");
            $fileSize = $data['size'];
            $fileDate = $data['date'];
            $filePath = trim($data['path'], "\0");

            // Basic validation
            if (empty($fileName) || $fileSize < 0 || $fileSize > 1024 * 1024 * 1024) {
                $this->log("Invalid file data detected, stopping extraction");
                if ($this->debugMode) {
                    $this->log("File: '$fileName', Size: $fileSize, Path: '$filePath'", true);
                }
                break;
            }

            // Construct full paths
            $relativePath = $filePath . '/' . $fileName;
            $relativePath = ltrim($relativePath, '/');
            $fullPath = $this->workingDir . '/' . $relativePath;

            if ($this->debugMode) {
                $this->log("Processing: $relativePath (Size: $fileSize bytes)", true);
            }

            // Create directory structure
            $dir = dirname($fullPath);
            if (!file_exists($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $this->log("Failed to create directory: $dir");
                    // Skip this file by reading its content
                    if ($fileSize > 0) {
                        fseek($fp, $fileSize, SEEK_CUR);
                    }
                    continue;
                }
            }

            // Extract file content
            if ($fileSize > 0) {
                $targetFp = fopen($fullPath, 'wb');
                if (!$targetFp) {
                    $this->log("Cannot create file: {$fullPath}");
                    // Skip file content
                    fseek($fp, $fileSize, SEEK_CUR);
                    continue;
                }

                // Copy file content in chunks
                $bytesRemaining = $fileSize;
                $chunkSize = 512000; // 512KB chunks for optimal performance

                while ($bytesRemaining > 0) {
                    $readSize = min($chunkSize, $bytesRemaining);
                    $content = fread($fp, $readSize);

                    if ($content === false) {
                        $this->log("Error reading file content for: {$relativePath}");
                        break;
                    }

                    if (strlen($content) == 0) {
                        if ($bytesRemaining > 0) {
                            $this->log("Unexpected end of archive while reading: {$relativePath}");
                        }
                        break;
                    }

                    $written = fwrite($targetFp, $content);
                    if ($written === false) {
                        $this->log("Error writing file content for: {$relativePath}");
                        break;
                    }

                    $bytesRemaining -= strlen($content);
                }

                fclose($targetFp);

                // Set file modification time
                if ($fileDate > 0) {
                    touch($fullPath, $fileDate);
                }
            } else {
                // Create empty file
                touch($fullPath);
                if ($fileDate > 0) {
                    touch($fullPath, $fileDate);
                }
            }

            $fileCount++;

            // Log progress
            if ($fileCount % 100 === 0) {
                $this->log("Extracted {$fileCount} files...");
            }

            if ($this->verbose && $fileCount % 50 === 0) {
                $elapsed = microtime(true) - $startTime;
                $rate = $fileCount / max($elapsed, 1);
                $this->log(sprintf("Progress: %d files extracted (%.2f files/sec)", $fileCount, $rate));
            }
        }

        fclose($fp);

        $totalTime = microtime(true) - $startTime;
        $this->log(sprintf(
            "Binary extraction complete. Extracted %d files in %.2f seconds", 
            $fileCount, 
            $totalTime
        ));
    }

    /**
     * Fallback extraction for legacy text-based archives
     */
    private function fallbackTextExtract(string $filepath): void
    {
        $this->log("Using fallback text extraction method...");

        $fp = fopen($filepath, "rb");
        if (!$fp) {
            throw new \Exception("Cannot open archive file for fallback extraction.");
        }

        $fileCount = 0;
        $currentFile = null;
        $currentFp = null;
        $startTime = microtime(true);

        // Skip header lines
        while (!feof($fp) && ($line = fgets($fp))) {
            if (substr(trim($line), 0, 1) !== "#") {
                fseek($fp, -strlen($line), SEEK_CUR);
                break;
            }
            if ($this->debugMode) {
                $this->log("Skipping header: " . trim($line), true);
            }
        }

        while (!feof($fp)) {
            $line = fgets($fp);
            if (empty($line)) continue;
            $trimmedLine = trim($line);
            
            if (strpos($trimmedLine, "__file__:") === 0) {
                if ($currentFp !== null) {
                    fclose($currentFp);
                }
                
                $currentFile = trim(substr($trimmedLine, 9));
                $fullPath = "{$this->workingDir}/{$currentFile}";
                
                $dir = dirname($fullPath);
                if (!file_exists($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                continue;
            }
            
            if (strpos($trimmedLine, "__size__:") === 0 || 
                strpos($trimmedLine, "__md5__:") === 0) {
                if ($currentFile && !$currentFp) {
                    $fullPath = "{$this->workingDir}/{$currentFile}";
                    $currentFp = fopen($fullPath, "wb");
                    if (!$currentFp) {
                        $this->log("Cannot create file {$fullPath}");
                        continue;
                    }
                    $fileCount++;
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
        $this->log("2. Import the database from the .sql or .sql.gz file");
        $this->log("3. Log in to WordPress admin and go to Settings > Permalinks");
        $this->log("4. Verify site functionality, especially fonts and media files");
        $this->log("\n✅ File extraction completed successfully!");
        $this->log("Log file available at: {$this->logFile}");
    }

    /**
     * Log a message
     */
    private function log(string $message, bool $debugOnly = false): void
    {
        $shouldDisplay = !$debugOnly || ($debugOnly && $this->debugMode);
        
        if ($shouldDisplay) {
            echo $message . PHP_EOL;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Display error and exit
     */
    private function displayError(string $message): void
    {
        $this->log("ERROR: {$message}");
        
        echo "\nUsage: php " . basename(__FILE__) . " --file=filename.hstgr [options]\n";
        echo "\nOptions:\n";
        echo "  --file=FILENAME       Required. The .hstgr archive file name\n";
        echo "  --dest=PATH           Optional. Destination directory (default: current directory)\n";
        echo "  --skip-content        Skip extracting content files\n";
        echo "  --verbose             Show detailed output during extraction\n";
        echo "  --debug               Enable debug mode with detailed file logging\n";
        echo "\nExample:\n";
        echo "  php " . basename(__FILE__) . " --file=site_export.hstgr --verbose\n";
        
        exit(1);
    }
}

// Main execution
try {
    // Handle both formats: --file=value and --file value
    $longopts = [
        "file:",
        "dest:",           // Changed from :: to : to require value
        "skip-content",    // Removed :: since it's a flag
        "verbose",         // Removed :: since it's a flag  
        "debug",           // Removed :: since it's a flag
        "help"             // Removed :: since it's a flag
    ];
    
    $options = getopt("", $longopts);
    
    // Fallback: Manual parsing for space-separated arguments
    if (empty($options['file']) && count($argv) > 1) {
        $options = [];
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            
            if ($arg === '--file' && isset($argv[$i + 1])) {
                $options['file'] = $argv[$i + 1];
                $i++; // Skip next argument
            } elseif ($arg === '--dest' && isset($argv[$i + 1])) {
                $options['dest'] = $argv[$i + 1];
                $i++; // Skip next argument
            } elseif ($arg === '--debug') {
                $options['debug'] = true;
            } elseif ($arg === '--verbose') {
                $options['verbose'] = true;
            } elseif ($arg === '--skip-content') {
                $options['skip-content'] = true;
            } elseif ($arg === '--help') {
                $options['help'] = true;
            } elseif (strpos($arg, '--file=') === 0) {
                $options['file'] = substr($arg, 7);
            } elseif (strpos($arg, '--dest=') === 0) {
                $options['dest'] = substr($arg, 7);
            }
        }
    }
    
    // Debug: log what options were parsed
    if (isset($options['debug'])) {
        echo "Parsed options: " . print_r($options, true) . "\n";
        echo "Command line args: " . print_r($argv, true) . "\n";
    }
    
    if (empty($options) || isset($options['help'])) {
        $importer = new HostingerMigrationImporter([]);
        exit(0);
    }
    
    $importer = new HostingerMigrationImporter($options);
    $importer->run();
    
} catch (\Throwable $e) {
    echo "Unexpected error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}