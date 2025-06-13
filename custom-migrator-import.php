#!/usr/bin/env php
<?php
/**
 * Hostinger Migration Importer with Binary Archive Support (Universal Version)
 *
 * A WordPress site migration tool that properly handles binary archives.
 * Compatible with PHP 5.6 through 8.x
 *
 * @package    HostingerMigrator
 * @version    2.1.0
 * @author     Your Name
 * @license    GPL-2.0+
 */

// Prevent direct access
if (PHP_SAPI !== 'cli') {
    die('This script can only be run from the command line.');
}

// Version compatibility check
if (version_compare(PHP_VERSION, '5.6.0', '<')) {
    die('This script requires PHP 5.6 or higher. Current version: ' . PHP_VERSION);
}

class HostingerMigrationImporter {
    /**
     * Archive file path
     */
    private $archiveFile;

    /**
     * Working directory for extraction
     */
    private $workingDir;

    /**
     * Log file path
     */
    private $logFile;

    /**
     * Verbose mode flag
     */
    private $verbose;

    /**
     * Debug mode flag
     */
    private $debugMode;

    /**
     * Skip content import flag
     */
    private $skipContent;

    /**
     * Block format for reading binary archives (optimized format)
     * 
     * @var array
     */
    private $blockFormat = [
        'pack' => 'a255VVa4112',  // filename(255), size(4), date(4), path(4112) = 4375 bytes
        'unpack' => 'a255filename/Vsize/Vdate/a4112path'  // For unpack: named fields
    ];

    /**
     * Constructor
     * 
     * @param array $options Import options
     * @throws InvalidArgumentException
     */
    public function __construct($options)
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

        // Handle logFile with proper fallback for getcwd() returning false
        $currentDir = getcwd();
        $logDir = $currentDir !== false ? $currentDir : dirname($this->archiveFile);
        $this->logFile = $logDir . '/hostinger-migrator-import-log.txt';

        // Log PHP version and memory info
        $this->log("PHP Version: " . PHP_VERSION);
        $this->log("Memory Limit: " . ini_get('memory_limit'));
        $this->log("Max Execution Time: " . ini_get('max_execution_time') . " seconds");
    }

    /**
     * Run the import process
     */
    public function run()
    {
        try {
            $this->log("=== Hostinger Migration Import Started ===");
            $this->log("Archive File: {$this->archiveFile}");
            $this->log("Destination: {$this->workingDir}");
            $this->log("Verbose Mode: " . ($this->verbose ? 'Enabled' : 'Disabled'));

            // Increase limits if possible
            $this->adjustPhpLimits();

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

        } catch (\Exception $e) {
            $this->displayError($e->getMessage());
            if ($this->debugMode) {
                $this->log("Stack trace: " . $e->getTraceAsString());
            }
        }
    }

    /**
     * Adjust PHP limits if possible
     */
    private function adjustPhpLimits()
    {
        // Only attempt if we have permission
        if (function_exists('ini_set')) {
            // Try to increase memory limit
            $currentLimit = ini_get('memory_limit');
            $numericLimit = (int)$currentLimit;
            if ($numericLimit < 512) {
                @ini_set('memory_limit', '512M');
            }
            
            // Try to increase max execution time
            @ini_set('max_execution_time', '300');
            
            // Disable time limit if possible
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            
            // Log new limits
            $this->log("Adjusted memory limit to: " . ini_get('memory_limit'));
            $this->log("Adjusted max execution time to: " . ini_get('max_execution_time'));
        }
    }

    /**
     * Find the archive file with enhanced debugging
     */
    private function findArchiveFile()
    {
        // Get current directory with fallback
        $currentDir = getcwd();
        $currentDirPath = $currentDir !== false ? $currentDir : dirname($this->archiveFile);
        
        $this->log("Looking for archive file: {$this->archiveFile}");
        $this->log("Current working directory: " . ($currentDir !== false ? $currentDir : 'unknown'));
        $this->log("Working directory: {$this->workingDir}");
        
        $searchPaths = [
            $this->archiveFile,
            $currentDirPath . '/' . $this->archiveFile,
            $currentDirPath . '/wp-content/hostinger-migration-archives/' . $this->archiveFile,
            $this->workingDir . '/wp-content/hostinger-migration-archives/' . $this->archiveFile
        ];

        $this->log("Searching in the following paths:");
        foreach ($searchPaths as $index => $path) {
            $exists = file_exists($path) ? 'EXISTS' : 'NOT FOUND';
            $readable = file_exists($path) && is_readable($path) ? 'READABLE' : 'NOT READABLE';
            $this->log("  " . ($index + 1) . ". $path - $exists - $readable");
            if (file_exists($path) && is_readable($path)) {
                $this->log("Found archive at: {$path}");
                return $path;
            }
        }

        // Additional debug: check archive directory contents
        $archiveDir = $currentDirPath . '/wp-content/hostinger-migration-archives/';
        $this->log("Checking contents of: $archiveDir");
        if (is_dir($archiveDir)) {
            $files = scandir($archiveDir);
            $this->log("Files found in archive directory:");
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $fileInfo = [
                        'name' => $file,
                        'size' => file_exists($archiveDir . $file) ? filesize($archiveDir . $file) : 'unknown',
                        'readable' => is_readable($archiveDir . $file) ? 'yes' : 'no'
                    ];
                    $this->log("  - {$fileInfo['name']} (Size: {$fileInfo['size']} bytes, Readable: {$fileInfo['readable']})");
                }
            }
        } else {
            $this->log("Archive directory does not exist: $archiveDir");
        }

        throw new \Exception("Archive file not found or not readable. Searched in multiple locations.");
    }

    /**
     * Check if archive uses binary format by examining file structure
     */
    private function isBinaryArchive($filepath)
    {
        $fp = fopen($filepath, "rb");
        if (!$fp) {
            $this->log("Failed to open file for binary detection: $filepath");
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
        $checks = [
            'filename_length' => strlen($fileName) > 0 && strlen($fileName) <= 255,
            'filesize_valid' => $fileSize >= 0,
            'date_valid' => $fileDate > 0 && $fileDate <= time(),
            'path_length' => strlen($filePath) > 0 && strlen($filePath) <= 4112
        ];

        $validFormat = !in_array(false, $checks, true);
        
        if ($this->debugMode) {
            $this->log("Binary format validation checks:");
            foreach ($checks as $check => $result) {
                $this->log("- $check: " . ($result ? 'PASS' : 'FAIL'));
            }
        }

        return $validFormat;
    }

    /**
     * Extract files from binary archive with memory-efficient streaming
     */
    private function extractBinaryArchive($filepath)
    {
        $fp = fopen($filepath, "rb");
        if (!$fp) {
            throw new \Exception("Cannot open archive for reading: $filepath");
        }

        $totalFiles = 0;
        $totalBytes = 0;
        $startTime = microtime(true);
        $lastProgressUpdate = 0;
        $progressInterval = 1; // Update progress every second

        while (!feof($fp)) {
            // Read header block
            $headerBlock = fread($fp, 4375);
            if (strlen($headerBlock) < 4375) {
                break; // End of archive
            }

            $header = @unpack($this->blockFormat['unpack'], $headerBlock);
            if (!$header) {
                $this->log("Warning: Invalid header block found, skipping...");
                continue;
            }

            $fileName = trim($header['filename'], "\0");
            $fileSize = $header['size'];
            $fileDate = $header['date'];
            $filePath = trim($header['path'], "\0");

            if (empty($fileName) || empty($filePath)) {
                continue;
            }

            // Create full path for extraction
            $fullPath = $this->workingDir . '/' . $filePath;
            $dirPath = dirname($fullPath);

            // Create directory if it doesn't exist
            if (!is_dir($dirPath)) {
                if (!mkdir($dirPath, 0755, true)) {
                    throw new \Exception("Failed to create directory: $dirPath");
                }
            }

            // Extract file with streaming for large files
            $outFp = fopen($fullPath, 'wb');
            if (!$outFp) {
                throw new \Exception("Cannot create output file: $fullPath");
            }

            $remainingBytes = $fileSize;
            $chunkSize = 8192; // 8KB chunks for memory efficiency

            while ($remainingBytes > 0) {
                $chunk = fread($fp, min($chunkSize, $remainingBytes));
                if ($chunk === false) {
                    break;
                }
                fwrite($outFp, $chunk);
                $remainingBytes -= strlen($chunk);
            }

            fclose($outFp);
            touch($fullPath, $fileDate);

            $totalFiles++;
            $totalBytes += $fileSize;

            // Progress reporting with rate limiting
            $currentTime = microtime(true);
            if (($currentTime - $lastProgressUpdate) >= $progressInterval) {
                $elapsed = $currentTime - $startTime;
                $rate = $elapsed > 0 ? ($totalBytes / 1048576) / $elapsed : 0;
                $this->log(sprintf(
                    "Extracted %d files (%.2f MB) in %.1f seconds (%.2f MB/s)",
                    $totalFiles,
                    $totalBytes / 1048576,
                    $elapsed,
                    $rate
                ));
                $lastProgressUpdate = $currentTime;
            }
        }

        fclose($fp);

        $totalTime = microtime(true) - $startTime;
        $averageRate = $totalTime > 0 ? ($totalBytes / 1048576) / $totalTime : 0;

        $this->log(sprintf(
            "Extraction complete: %d files (%.2f MB) in %.1f seconds (average %.2f MB/s)",
            $totalFiles,
            $totalBytes / 1048576,
            $totalTime,
            $averageRate
        ));
    }

    /**
     * Analyze archive format for legacy support
     */
    private function analyzeArchiveFormat($filepath)
    {
        $this->log("Analyzing archive format...");
        
        $fp = fopen($filepath, "rb");
        if (!$fp) {
            throw new \Exception("Cannot open archive for analysis: $filepath");
        }

        $sample = fread($fp, 8192); // Read first 8KB for analysis
        fclose($fp);

        // Check for various archive signatures
        $formats = [
            'HSTGR' => 'Hostinger binary format',
            '\x1f\x8b' => 'GZIP compressed',
            'PK\x03\x04' => 'ZIP archive',
            'BZh' => 'BZIP2 compressed',
            '7z' => '7-Zip archive'
        ];

        foreach ($formats as $signature => $formatName) {
            if (strpos($sample, $signature) === 0) {
                $this->log("Detected format: $formatName");
                return $formatName;
            }
        }

        $this->log("No known archive format detected, assuming text-based archive");
        return 'text';
    }

    /**
     * Extract files using fallback text-based method
     */
    private function fallbackTextExtract($filepath)
    {
        $this->log("Using fallback text-based extraction method");
        
        $fp = fopen($filepath, "rb");
        if (!$fp) {
            throw new \Exception("Cannot open archive for extraction: $filepath");
        }

        $inHeader = true;
        $currentFile = null;
        $currentPath = null;
        $fileBuffer = '';
        $totalFiles = 0;
        $totalBytes = 0;

        while (($line = fgets($fp)) !== false) {
            $line = rtrim($line);

            if (preg_match('/^FILE: (.+)$/', $line, $matches)) {
                // Save previous file if exists
                if ($currentFile && $currentPath) {
                    $this->saveFile($currentPath, $fileBuffer);
                    $totalFiles++;
                    $totalBytes += strlen($fileBuffer);
                }

                // Start new file
                $currentFile = $matches[1];
                $currentPath = $this->workingDir . '/' . $currentFile;
                $fileBuffer = '';
                $inHeader = true;
                continue;
            }

            if ($line === "CONTENT:") {
                $inHeader = false;
                continue;
            }

            if (!$inHeader && $currentFile) {
                $fileBuffer .= $line . PHP_EOL;
            }
        }

        // Save last file
        if ($currentFile && $currentPath) {
            $this->saveFile($currentPath, $fileBuffer);
            $totalFiles++;
            $totalBytes += strlen($fileBuffer);
        }

        fclose($fp);

        $this->log(sprintf(
            "Fallback extraction complete: %d files (%.2f MB)",
            $totalFiles,
            $totalBytes / 1048576
        ));
    }

    /**
     * Save file with directory creation
     */
    private function saveFile($path, $content)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \Exception("Failed to create directory: $dir");
            }
        }

        if (file_put_contents($path, $content) === false) {
            throw new \Exception("Failed to write file: $path");
        }
    }

    /**
     * Display final instructions
     */
    private function displayFinalInstructions()
    {
        $this->log("\n=== Import Complete ===");
        $this->log("Next steps:");
        $this->log("1. Verify file permissions in {$this->workingDir}");
        $this->log("2. Update WordPress configuration if needed");
        $this->log("3. Clear any caches");
        $this->log("\nFor detailed import log, check: {$this->logFile}");
    }

    /**
     * Log a message
     */
    private function log($message, $debugOnly = false)
    {
        if ($debugOnly && !$this->debugMode) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";

        if ($this->verbose) {
            echo $logMessage;
        }

        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    /**
     * Display error message
     */
    private function displayError($message)
    {
        $this->log("ERROR: $message");
        fwrite(STDERR, "\nERROR: $message\n");
    }
}

// Parse command line arguments
$options = getopt('', ['file:', 'dest:', 'verbose', 'debug', 'skip-content']);

try {
    $importer = new HostingerMigrationImporter($options);
    $importer->run();
} catch (\Exception $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);
} 