<?php

namespace App;

final class Parser
{
    public function parse(string $inputPath, string $outputPath): void
    {
        $t0 = hrtime(true);
        
        $fileSize = filesize($inputPath);
        $numWorkers = 16;
        
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
        echo "Forking {$numWorkers} workers (IPC: {$method})...\n";
        
        // Fork workers
        $tempFiles = [];
        $timeFiles = [];
        $pids = [];
        
        for ($i = 0; $i < $numWorkers; $i++) {
            $tempFile = tempnam(sys_get_temp_dir(), 'parser_');
            $timeFile = $tempFile . '.time';
            $tempFiles[$i] = $tempFile;
            $timeFiles[$i] = $timeFile;
            
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                die("Failed to fork");
            } elseif ($pid === 0) {
                // === CHILD PROCESS ===
                $childStart = hrtime(true);
                
                $result = $this->processChunk($inputPath, $chunks[$i]['start'], $chunks[$i]['end']);
                
                $childParsed = hrtime(true);
                
                if ($useIgbinary) {
                    // Use igbinary for fast, compact serialization
                    $output = \igbinary_serialize($result);
                } else {
                    // Fall back to text format
                    $output = '';
                    foreach ($result as $path => $dates) {
                        foreach ($dates as $date => $count) {
                            $output .= "{$path}\t{$date}\t{$count}\n";
                        }
                    }
                }
                file_put_contents($tempFile, $output);
                
                $childEnd = hrtime(true);
                
                // Write timing info
                file_put_contents($timeFile, json_encode([
                    'parse_ms' => ($childParsed - $childStart) / 1e6,
                    'write_ms' => ($childEnd - $childParsed) / 1e6,
                    'size' => strlen($output),
                ]));
                
                // Exit without triggering framework handlers
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
            // Fast igbinary deserialization
            foreach ($tempFiles as $tempFile) {
                $content = file_get_contents($tempFile);
                $chunkResult = \igbinary_unserialize($content);
                
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
            // Text parsing fallback
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
    
    private function processChunk(string $inputPath, int $start, int $end): array
    {
        $result = [];
        
        // Read entire chunk into memory - 1 syscall instead of millions of fgets()
        $chunk = file_get_contents($inputPath, false, null, $start, $end - $start);
        
        // Use strtok (C-native) to iterate lines without creating an array
        // strtok tokenizes in place, memory efficient
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
}