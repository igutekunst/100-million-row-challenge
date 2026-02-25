<?php

namespace App;

final class Parser
{
    public function parse(string $inputPath, string $outputPath, ?int $workers = null): void
    {
        $t0 = hrtime(true);
        
        $fileSize = filesize($inputPath);
        $memoryLimit = $this->getMemoryLimitBytes();
        
        // Determine if we're in a constrained environment
        // Only consider constrained if memory is explicitly limited between 256MB and 2GB
        // Default 128MB (-1 or low values) means unconstrained (use system memory)
        $isConstrained = $memoryLimit > 256 * 1024 * 1024 && $memoryLimit < 2 * 1024 * 1024 * 1024;
        
        // In constrained environments, use 2 workers (1 per CPU) with sub-chunking
        // In unconstrained environments, use many workers with bulk loading
        if ($workers !== null) {
            $numWorkers = $workers;
        } else {
            $numWorkers = $isConstrained ? 2 : 16;
        }
        
        // Check if igbinary is available
        $useIgbinary = function_exists('igbinary_serialize');
        
        // Calculate chunk boundaries (aligned to newlines)
        $chunkSize = (int) ceil($fileSize / $numWorkers);
        $chunks = [];
        
        $handle = fopen($inputPath, 'r');
        for ($i = 0; $i < $numWorkers; $i++) {
            $start = $i * $chunkSize;
            
            if ($i > 0) {
                fseek($handle, $start);
                fgets($handle);
                $start = ftell($handle);
            }
            
            $end = min(($i + 1) * $chunkSize, $fileSize);
            if ($i < $numWorkers - 1) {
                fseek($handle, $end);
                fgets($handle);
                $end = ftell($handle);
            }
            
            $chunks[] = ['start' => $start, 'end' => $end];
        }
        fclose($handle);
        
        $method = $useIgbinary ? 'igbinary' : 'text';
        $chunkSizeMB = round(($chunks[0]['end'] - $chunks[0]['start']) / 1024 / 1024, 1);
        
        // Calculate sub-chunk size for constrained environments
        // 200MB sub-chunks for smaller memory footprint
        $subChunkSize = $isConstrained ? 200 * 1024 * 1024 : 0;
        $subChunkInfo = $isConstrained ? ", sub-chunk: 200MB" : "";
        
        echo "Forking {$numWorkers} workers (IPC: {$method}, chunk: {$chunkSizeMB}MB{$subChunkInfo})...\n";
        
        // Fork workers
        $tempFiles = [];
        $timeFiles = [];
        $pids = [];
        
        // Use /dev/shm if available (Linux tmpfs), otherwise /tmp
        $tmpDir = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        
        for ($i = 0; $i < $numWorkers; $i++) {
            $tempFile = tempnam($tmpDir, 'parser_');
            $timeFile = $tempFile . '.time';
            $tempFiles[$i] = $tempFile;
            $timeFiles[$i] = $timeFile;
            
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                die("Failed to fork");
            } elseif ($pid === 0) {
                // === CHILD PROCESS ===
                $childStart = hrtime(true);
                
                if ($isConstrained && $subChunkSize > 0) {
                    // Hybrid approach: process in sub-chunks to manage memory
                    $result = $this->processChunkHybrid(
                        $inputPath, 
                        $chunks[$i]['start'], 
                        $chunks[$i]['end'],
                        $subChunkSize
                    );
                } else {
                    // Bulk loading approach for unconstrained environments
                    $result = $this->processChunkBulk($inputPath, $chunks[$i]['start'], $chunks[$i]['end']);
                }
                
                $childParsed = hrtime(true);
                
                if ($useIgbinary) {
                    $output = \igbinary_serialize($result);
                } else {
                    $output = '';
                    foreach ($result as $path => $dates) {
                        foreach ($dates as $date => $count) {
                            $output .= "{$path}\t{$date}\t{$count}\n";
                        }
                    }
                }
                file_put_contents($tempFile, $output);
                
                $childEnd = hrtime(true);
                
                file_put_contents($timeFile, json_encode([
                    'parse_ms' => ($childParsed - $childStart) / 1e6,
                    'write_ms' => ($childEnd - $childParsed) / 1e6,
                    'size' => strlen($output),
                ]));
                
                posix_kill(posix_getpid(), SIGKILL);
            } else {
                $pids[$i] = $pid;
            }
        }
        
        // Wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        $t1 = hrtime(true);
        
        // Show worker timings
        echo "Worker timings:\n";
        $totalSize = 0;
        foreach ($timeFiles as $i => $timeFile) {
            if (file_exists($timeFile)) {
                $t = json_decode(file_get_contents($timeFile), true);
                $sizeKb = number_format($t['size'] / 1024, 1);
                echo "  Worker {$i}: parse={$t['parse_ms']}ms, write={$t['write_ms']}ms, size={$sizeKb}KB\n";
                $totalSize += $t['size'];
                unlink($timeFile);
            }
        }
        echo "Total IPC size: " . number_format($totalSize / 1024 / 1024, 2) . " MB\n";
        echo "Wallclock: " . number_format(($t1 - $t0) / 1e9, 4) . "s\n\n";
        
        echo "Merging...\n";
        
        // Merge results
        $result = [];
        if ($useIgbinary) {
            foreach ($tempFiles as $tempFile) {
                $content = file_get_contents($tempFile);
                $chunkResult = \igbinary_unserialize($content);
                
                if ($chunkResult === false || !is_array($chunkResult)) {
                    echo "Warning: Worker output invalid, skipping...\n";
                    continue;
                }
                
                foreach ($chunkResult as $path => $dates) {
                    if (!isset($result[$path])) {
                        $result[$path] = $dates;
                    } else {
                        foreach ($dates as $date => $count) {
                            $result[$path][$date] = ($result[$path][$date] ?? 0) + $count;
                        }
                    }
                }
                unlink($tempFile);
            }
        } else {
            foreach ($tempFiles as $tempFile) {
                $handle = fopen($tempFile, 'r');
                while (($line = fgets($handle)) !== false) {
                    $tab1 = strpos($line, "\t");
                    $tab2 = strpos($line, "\t", $tab1 + 1);
                    
                    $path = substr($line, 0, $tab1);
                    $date = substr($line, $tab1 + 1, 10);
                    $count = (int)substr($line, $tab2 + 1);
                    
                    $result[$path][$date] = ($result[$path][$date] ?? 0) + $count;
                }
                fclose($handle);
                unlink($tempFile);
            }
        }
        
        $t2 = hrtime(true);
        
        // Sort dates within each path
        foreach ($result as &$dates) {
            ksort($dates);
        }
        
        $t3 = hrtime(true);
        
        // Write output
        file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT));
        
        $t4 = hrtime(true);
        
        echo "========================================\n";
        echo "   RESULTS (Parallel, {$numWorkers} workers, {$method})\n";
        echo "========================================\n";
        echo "Fork + Process: " . number_format(($t1 - $t0) / 1e9, 4) . "s\n";
        echo "Merge:          " . number_format(($t2 - $t1) / 1e9, 4) . "s\n";
        echo "Sort:           " . number_format(($t3 - $t2) / 1e9, 4) . "s\n";
        echo "JSON + Write:   " . number_format(($t4 - $t3) / 1e9, 4) . "s\n";
        echo "----------------------------------------\n";
        echo "TOTAL:          " . number_format(($t4 - $t0) / 1e9, 4) . "s\n";
        echo "========================================\n";
    }
    
    /**
     * Hybrid approach: Process chunk in sub-chunks to manage memory
     * Best for memory-constrained environments
     */
    private function processChunkHybrid(string $inputPath, int $start, int $end, int $subChunkSize): array
    {
        $result = [];
        $currentPos = $start;
        $handle = fopen($inputPath, 'r');
        
        while ($currentPos < $end) {
            // Calculate sub-chunk boundaries
            $subChunkEnd = min($currentPos + $subChunkSize, $end);
            
            // Align to newline if not at the end
            if ($subChunkEnd < $end) {
                fseek($handle, $subChunkEnd);
                fgets($handle); // Skip to end of line
                $subChunkEnd = ftell($handle);
            }
            
            // Read sub-chunk into memory
            $subChunkLength = $subChunkEnd - $currentPos;
            fseek($handle, $currentPos);
            $chunk = fread($handle, $subChunkLength);
            
            // Process with strtok (fast C-native tokenization)
            $line = strtok($chunk, "\n");
            
            while ($line !== false) {
                $lineLen = strlen($line);
                
                // Comma is always at strlen - 26 (date is fixed 25 chars + comma)
                $commaPos = $lineLen - 26;
                
                $path = substr($line, 19, $commaPos - 19);
                $date = substr($line, $commaPos + 1, 10);
                
                if (!isset($result[$path])) {
                    $result[$path] = [$date => 1];
                } elseif (!isset($result[$path][$date])) {
                    $result[$path][$date] = 1;
                } else {
                    ++$result[$path][$date];
                }
                
                $line = strtok("\n");
            }
            
            // Free memory before next sub-chunk
            unset($chunk);
            
            $currentPos = $subChunkEnd;
        }
        
        fclose($handle);
        return $result;
    }
    
    /**
     * Bulk loading approach: Load entire chunk into memory at once
     * Best for unconstrained environments with plenty of RAM
     */
    private function processChunkBulk(string $inputPath, int $start, int $end): array
    {
        $result = [];
        
        // Read entire chunk into memory - 1 syscall instead of millions of fgets()
        $chunk = file_get_contents($inputPath, false, null, $start, $end - $start);
        
        // Use strtok (C-native) to iterate lines without creating an array
        $line = strtok($chunk, "\n");
        
        while ($line !== false) {
            $lineLen = strlen($line);
            
            // Comma is always at strlen - 26 (date is fixed 25 chars + comma)
            $commaPos = $lineLen - 26;
            
            $path = substr($line, 19, $commaPos - 19);
            $date = substr($line, $commaPos + 1, 10);
            
            if (!isset($result[$path])) {
                $result[$path] = [$date => 1];
            } elseif (!isset($result[$path][$date])) {
                $result[$path][$date] = 1;
            } else {
                ++$result[$path][$date];
            }
            
            $line = strtok("\n");
        }
        
        // Free the chunk memory
        unset($chunk);
        
        return $result;
    }
    
    private function getMemoryLimitBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return 0; // Unlimited
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;
        
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}