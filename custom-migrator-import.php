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
     * Sleep delay in microseconds between file writes
     */
    private int $sleepDelay;

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
            throw new \InvalidArgumentException('Archive file name is required. Use --file=filename.hstgr');
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
        
        // Allow custom sleep delay (in microseconds) for I/O throttling
        // Default: 5000 microseconds (5ms) - optimal for Hostinger shared hosting
        $this->sleepDelay = isset($options['sleep']) ? (int)$options['sleep'] : 5000;

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
                $this->analyzeArchiveFormat($fullArchivePath);
                $this->fallbackTextExtract($fullArchivePath);
            }

            $this->displayFinalInstructions();
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
            $this->log("Binary detection failed: Archive too small for binary format: " . strlen($block) . " bytes (need $blockSize)");
            return false;
        }

        // Try to unpack as binary format
        $data = @unpack($this->blockFormat['unpack'], $block);
        
        if (!$data || !isset($data['filename'], $data['size'], $data['date'], $data['path'])) {
            $this->log("Binary detection failed: Failed to unpack first block as binary format");
            if ($this->debugMode) {
                $hexData = bin2hex(substr($block, 0, 64));
                $this->log("First 64 bytes hex: $hexData", true);
            }
            return false;
        }

        $fileName = trim($data['filename'], "\0");
        $fileSize = $data['size'];
        $fileDate = $data['date'];
        $filePath = trim($data['path'], "\0");

        // Enhanced debug logging
        $this->log("Binary detection analysis:");
        $this->log("- Raw filename: '" . addslashes($data['filename']) . "' (length: " . strlen($data['filename']) . ")");
        $this->log("- Trimmed filename: '$fileName' (length: " . strlen($fileName) . ")");
        $this->log("- File size: $fileSize");
        $this->log("- File date: $fileDate (" . date('Y-m-d H:i:s', $fileDate) . ")");
        $this->log("- Raw path: '" . addslashes($data['path']) . "' (length: " . strlen($data['path']) . ")");
        $this->log("- Trimmed path: '$filePath' (length: " . strlen($filePath) . ")");

        // Basic sanity checks for binary format
        $checks = [];
        $checks['filename_not_empty'] = !empty($fileName);
        $checks['filename_length_ok'] = strlen($fileName) <= 255 && strlen($fileName) > 0;
        $checks['filesize_valid'] = $fileSize >= 0 && $fileSize < 1024 * 1024 * 1024;
        // More permissive date validation - allow wider range
        $checks['date_valid'] = $fileDate > 0 && $fileDate < time() + (86400 * 30); // Allow up to 30 days in future
        $checks['path_length_ok'] = strlen($filePath) <= 4112;
        $checks['filename_no_nulls'] = strpos($fileName, "\0") === false;
        $checks['path_no_nulls'] = strpos($filePath, "\0") === false;
        // More permissive filename pattern - allow more characters and Unicode
        $checks['filename_pattern_ok'] = (
            // Standard ASCII filename
            preg_match('/^[a-zA-Z0-9._\-\s]+(\.[a-zA-Z0-9]+)?$/', $fileName) ||
            // Allow more special characters common in filenames
            preg_match('/^[a-zA-Z0-9._\-\s\(\)\[\]&@%+]+(\.[a-zA-Z0-9]+)?$/u', $fileName) ||
            // Fallback: just check if it's not empty and reasonable length
            (!empty($fileName) && strlen($fileName) <= 255 && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $fileName))
        );

        $this->log("Binary format validation checks:");
        foreach ($checks as $check => $result) {
            $this->log("- $check: " . ($result ? 'PASS' : 'FAIL'));
        }

        $isValid = array_product($checks); // All checks must pass

        $this->log("Binary format validation result: " . ($isValid ? 'PASSED' : 'FAILED'));
        
        if (!$isValid) {
            $this->log("Archive will be processed as legacy text format due to failed validation");
        }

        return (bool)$isValid;
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
        $stoppedDueToCorruption = false; // Track if we stopped due to invalid data
        
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
                $stoppedDueToCorruption = true;
                break;
            }

            $fileName = trim($data['filename'], "\0");
            $fileSize = $data['size'];
            $fileDate = $data['date'];
            $filePath = trim($data['path'], "\0");

            // Apply same safe character handling as export using centralized methods
            // The export uses encode_for_binary() before packing, so we need decode_from_binary() after unpacking
            $fileName = $this->decode_from_binary($fileName);
            $filePath = $this->decode_from_binary($filePath);

            // Additional null byte sanitization as final safety measure
            $fileName = str_replace("\0", '', $fileName);
            $filePath = str_replace("\0", '', $filePath);

            // Basic validation
            if (empty($fileName) || $fileSize < 0 || $fileSize > 1024 * 1024 * 1024) {
                $this->log("Invalid file data detected, stopping extraction");
                if ($this->debugMode) {
                    $this->log("File: '$fileName', Size: $fileSize, Path: '$filePath'", true);
                }
                $stoppedDueToCorruption = true;
                break;
            }

            // Additional validation for null bytes in paths (causes mkdir() failures)
            if (strpos($fileName, "\0") !== false || strpos($filePath, "\0") !== false) {
                throw new \Exception("Null bytes detected in file path data at file #$fileCount. Archive is corrupted.");
            }

            // Construct full paths using custom migrator logic (same as helper class)
            if (empty($filePath) || $filePath === '.') {
                $relativePath = $fileName;
            } else {
                $relativePath = $filePath . DIRECTORY_SEPARATOR . $fileName;
            }
            $relativePath = ltrim($relativePath, '/');
            
            // CRITICAL FIX: The path is already URL-decoded by decode_from_binary()
            // So it should already be in the correct format (wp-content/...)
            // Just use it directly
            $fullPath = $this->workingDir . '/' . $relativePath;

            if ($this->debugMode) {
                $this->log("Processing: $relativePath (Size: $fileSize bytes) -> $fullPath", true);
            }

            // Create directory structure
            $dir = dirname($fullPath);
            if (!file_exists($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $this->log("Failed to create directory: $dir (permissions issue or invalid path)");
                    // Skip this file by reading its content
                    if ($fileSize > 0) {
                        $seekResult = fseek($fp, $fileSize, SEEK_CUR);
                        if ($seekResult !== 0) {
                            throw new \Exception("Failed to seek past file content in archive. Archive may be corrupted.");
                        }
                    }
                    continue;
                }
                // Clear stat cache after directory creation to reduce memory
                clearstatcache(true, $dir);
            }

            // Extract file content
            if ($fileSize > 0) {
                $targetFp = @fopen($fullPath, 'wb');
                if (!$targetFp) {
                    $error = error_get_last();
                    $error_msg = $error ? $error['message'] : 'unknown error';
                    
                    // Clear cache before checking
                    $dirPath = dirname($fullPath);
                    clearstatcache(true, $dirPath);
                    clearstatcache(true, $fullPath);
                    
                    // If directory exists but fopen fails with "No such file", try alternative method
                    $dirExists = file_exists($dirPath);
                    if ($dirExists && strpos($error_msg, 'No such file') !== false) {
                        // Force garbage collection first
                        gc_collect_cycles();
                        
                        // Read file content from archive
                        $fileContent = fread($fp, $fileSize);
                        if ($fileContent === false || strlen($fileContent) != $fileSize) {
                            $this->log("ERROR: Failed to read file content from archive for: {$relativePath}");
                            continue;
                        }
                        
                        // Try to write with file_put_contents (doesn't use file handles)
                        $result = @file_put_contents($fullPath, $fileContent);
                        if ($result === false) {
                            $this->log("Cannot create file: {$fullPath} - alternative write method also failed");
                        } else {
                            if ($fileDate > 0) {
                                touch($fullPath, $fileDate);
                            }
                            $fileCount++;
                        }
                        continue;
                    }
                    
                    // Log simple failure message
                    $this->log("Cannot create file: {$relativePath} (server I/O limit)");
                    
                    // Skip file content
                    $seekResult = fseek($fp, $fileSize, SEEK_CUR);
                    if ($seekResult !== 0) {
                        throw new \Exception("Failed to seek past file content in archive. Archive may be corrupted.");
                    }
                    continue;
                }

                // Copy file content in chunks
                $bytesRemaining = $fileSize;
                $chunkSize = 512000; // 512KB chunks for optimal performance

                while ($bytesRemaining > 0) {
                    $readSize = min($chunkSize, $bytesRemaining);
                    $content = fread($fp, $readSize);

                    if ($content === false) {
                        fclose($targetFp);
                        throw new \Exception("Error reading file content for: {$relativePath}. Archive may be corrupted.");
                    }

                    if (strlen($content) == 0) {
                        if ($bytesRemaining > 0) {
                            fclose($targetFp);
                            throw new \Exception("Unexpected end of archive while reading: {$relativePath}. Archive is incomplete or corrupted.");
                        }
                        break;
                    }

                    $written = fwrite($targetFp, $content);
                    if ($written === false) {
                        fclose($targetFp);
                        throw new \Exception("Error writing file content for: {$relativePath}. Disk may be full or permissions issue.");
                    }

                    $bytesRemaining -= strlen($content);
                }

                fclose($targetFp);
                unset($targetFp); // Explicitly unset to free reference

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

            // Small delay to prevent I/O throttling on shared hosting
            // Default: 5000 microseconds (5ms) per file, adds ~45 seconds for 9000 files
            // Optimal for Hostinger shared hosting environment
            if ($this->sleepDelay > 0) {
                usleep($this->sleepDelay);
            }

            $fileCount++;

            // Log progress and free resources every 50 files (more aggressive)
            if ($fileCount % 50 === 0) {
                // Force garbage collection and clear stat cache to free memory and file handles
                gc_collect_cycles();
                clearstatcache();
                
                if ($fileCount % 100 === 0) {
                    $this->log("Extracted {$fileCount} files...");
                }
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

        // Check if extraction was successful
        if ($fileCount === 0) {
            throw new \Exception("No files were extracted from the binary archive. The archive may be corrupt or in an unsupported format.");
        }

        // CRITICAL: Fail if extraction stopped due to corruption
        if ($stoppedDueToCorruption) {
            throw new \Exception("Archive extraction FAILED: Extraction stopped due to invalid file data or corruption after extracting $fileCount files. The archive appears to be damaged and the import is incomplete.");
        }
    }

    /**
     * Analyze archive format for better error reporting
     */
    private function analyzeArchiveFormat(string $filepath): void
    {
        $fp = fopen($filepath, "rb");
        if (!$fp) {
            return;
        }

        $fileSize = filesize($filepath);
        $this->log("Archive file size: " . number_format($fileSize) . " bytes");

        // Read first 1KB to analyze format
        $firstBytes = fread($fp, 1024);
        $this->log("First " . strlen($firstBytes) . " bytes read for analysis");

        // Check for common archive markers
        $hasFileMarkers = substr_count($firstBytes, '__file__:');
        $hasSizeMarkers = substr_count($firstBytes, '__size__:');
        $hasEndMarkers = substr_count($firstBytes, '__endfile__');
        
        $this->log("Text format markers found - File: $hasFileMarkers, Size: $hasSizeMarkers, End: $hasEndMarkers");

        // Check if file is mostly binary
        $binaryCharCount = 0;
        for ($i = 0; $i < strlen($firstBytes); $i++) {
            $ord = ord($firstBytes[$i]);
            if ($ord < 32 && $ord !== 10 && $ord !== 13 && $ord !== 9) {
                $binaryCharCount++;
            }
        }
        $binaryPercentage = ($binaryCharCount / strlen($firstBytes)) * 100;
        $this->log(sprintf("Binary content analysis: %.2f%% non-printable characters", $binaryPercentage));

        fclose($fp);
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
                
                // Path should already be in correct format from export
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
                    // Path should already be in correct format from export
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
                
                // Force garbage collection every 100 files
                if ($fileCount % 100 === 0) {
                    gc_collect_cycles();
                    $this->log("Extracted {$fileCount} files (text format)...");
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

        // Check if extraction was successful
        if ($fileCount === 0) {
            throw new \Exception("No files were extracted from the archive. The archive may be corrupt or in an unsupported format.");
        }
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

    /**
     * Encode a string for safe binary storage (handles international characters)
     * This matches the plugin's encoding method
     *
     * @param  string $value The string to encode
     * @return string URL-encoded string safe for binary storage
     */
    private function encode_for_binary($value) {
        return urlencode($value);
    }

    /**
     * Decode a string from binary storage (handles international characters)
     * This matches the plugin's decoding method
     *
     * @param  string $value The URL-encoded string from binary storage
     * @return string Decoded original string
     */
    private function decode_from_binary($value) {
        return urldecode(trim($value, "\0"));
    }
}

// Main execution
try {
    // Handle both formats: --file=value and --file value
    $longopts = [
        "file:",
        "dest:",           // Changed from :: to : to require value
        "sleep:",          // Sleep delay in microseconds for I/O throttling testing
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
            } elseif ($arg === '--sleep' && isset($argv[$i + 1])) {
                $options['sleep'] = $argv[$i + 1];
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
            } elseif (strpos($arg, '--sleep=') === 0) {
                $options['sleep'] = substr($arg, 8);
            }
        }
    }
    
    // Debug: log what options were parsed
    if (isset($options['debug'])) {
        echo "Parsed options: " . print_r($options, true) . "\n";
        echo "Command line args: " . print_r($argv, true) . "\n";
    }
    
    if (empty($options) || isset($options['help'])) {
        throw new \InvalidArgumentException('No valid options provided. Use --file=filename.hstgr');
    }
    
    $importer = new HostingerMigrationImporter($options);
    $importer->run();
    
} catch (\Throwable $e) {
    // Log error details for debugging
    $logFile = (getcwd() !== false ? getcwd() : '/tmp') . '/hostinger-migrator-import-log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $errorLog = "\n[{$timestamp}] FATAL ERROR: " . $e->getMessage() . "\n";
    $errorLog .= "[{$timestamp}] Stack trace: " . $e->getTraceAsString() . "\n";
    file_put_contents($logFile, $errorLog, FILE_APPEND);
    
    // Display error message with usage instructions
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\nUsage: php " . basename(__FILE__) . " --file=filename.hstgr [options]\n";
    echo "\nOptions:\n";
    echo "  --file=FILENAME       Required. The .hstgr archive file name\n";
    echo "  --dest=PATH           Optional. Destination directory (default: current directory)\n";
    echo "  --sleep=MICROSECONDS  Optional. Delay between file writes (default: 5000 = 5ms, optimized for shared hosting)\n";
    echo "  --skip-content        Skip extracting content files\n";
    echo "  --verbose             Show detailed output during extraction\n";
    echo "  --debug               Enable debug mode with detailed file logging\n";
    echo "\nExamples:\n";
    echo "  php " . basename(__FILE__) . " --file=site_export.hstgr --verbose\n";
    echo "  php " . basename(__FILE__) . " --file=site_export.hstgr --sleep=0  # No delay (fastest, may hit I/O limits)\n";
    
    // Re-throw the exception to be caught by the calling application
    throw $e;
}