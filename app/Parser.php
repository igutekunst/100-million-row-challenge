<?php

namespace App;

final class Parser
{
    public function parse(string $inputPath, string $outputPath): void
    {
        $t0 = hrtime(true);
        
        $fileSize = filesize($inputPath);
        $numWorkers = 2;
        
        // Find midpoint aligned to newline
        $handle = fopen($inputPath, 'r');
        $midpoint = (int) ($fileSize / 2);
        fseek($handle, $midpoint);
        fgets($handle); // skip partial line
        $midpoint = ftell($handle);
        fclose($handle);
        
        $chunks = [
            ['start' => 0, 'end' => $midpoint],
            ['start' => $midpoint, 'end' => $fileSize],
        ];
        
        echo "Forking 2 workers (200MB sub-chunks + strtok)...\n";
        echo "  Worker 0: 0 - " . number_format($midpoint) . "\n";
        echo "  Worker 1: " . number_format($midpoint) . " - " . number_format($fileSize) . "\n";
        
        // Use /dev/shm if available (Linux tmpfs)
        $tmpDir = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        $useIgbinary = function_exists('igbinary_serialize');
        
        $tempFiles = [];
        $pids = [];
        
        for ($i = 0; $i < $numWorkers; $i++) {
            $tempFile = $tmpDir . '/parser_' . getmypid() . '_' . $i;
            $tempFiles[$i] = $tempFile;
            
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                die("Failed to fork");
            } elseif ($pid === 0) {
                // === CHILD PROCESS ===
                $result = $this->processChunk($inputPath, $chunks[$i]['start'], $chunks[$i]['end']);
                
                if ($useIgbinary) {
                    file_put_contents($tempFile, igbinary_serialize($result));
                } else {
                    file_put_contents($tempFile, serialize($result));
                }
                
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
        echo "Workers done: " . number_format(($t1 - $t0) / 1e9, 4) . "s\n";
        
        // Merge results
        $result = [];
        foreach ($tempFiles as $tempFile) {
            $content = file_get_contents($tempFile);
            $chunkResult = $useIgbinary ? igbinary_unserialize($content) : unserialize($content);
            unlink($tempFile);
            
            foreach ($chunkResult as $path => $dates) {
                if (!isset($result[$path])) {
                    $result[$path] = $dates;
                } else {
                    foreach ($dates as $date => $count) {
                        $result[$path][$date] = ($result[$path][$date] ?? 0) + $count;
                    }
                }
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
        echo "   RESULTS (2 workers, 200MB sub-chunks)\n";
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
        $subChunkSize = 200 * 1024 * 1024; // 200MB
        $currentPos = $start;
        $handle = fopen($inputPath, 'r');
        
        while ($currentPos < $end) {
            // Calculate sub-chunk boundaries
            $subChunkEnd = min($currentPos + $subChunkSize, $end);
            
            // Align to newline if not at the end
            if ($subChunkEnd < $end) {
                fseek($handle, $subChunkEnd);
                fgets($handle);
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
                
                // Line format: https://stitcher.io/PATH,YYYY-MM-DDTHH:MM:SS+00:00
                // Fixed prefix: 19 chars (https://stitcher.io)
                // Fixed suffix: 25 chars (date) + 1 (comma) = 26 chars from end
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
}