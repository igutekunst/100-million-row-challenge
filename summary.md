# 100 Million Row Challenge - Summary for Next Thread

## Challenge Overview
- Parse 100M rows of CSV (page visits) into JSON
- Benchmark server: **2 vCPUs, 1.5GB RAM**
- PHP 8.5, no FFI allowed, JIT disabled
- Current leaderboard: **21.9s, 23.0s, 28.3s**
- Our best: **~26s**

## Current Implementation

### Architecture (Dual-Path)
```
Memory > 2GB? → 16 workers, bulk loading (file_get_contents + strtok)
Memory < 2GB? → 2 workers, hybrid sub-chunking (300MB chunks)
```

### Key Files
- `app/Parser.php` - Main implementation with dual-path logic
- `app/Commands/DataParseCommand.php` - CLI command
- `Dockerfile` + `docker-compose.yml` - Constrained environment testing

### Data Format
```
https://stitcher.io/PATH,YYYY-MM-DDTHH:MM:SS+00:00
```
- Fixed prefix: `https://stitcher.io` (19 chars)
- Variable path length (lines vary 55-99 chars total)
- Fixed date: 25 chars
- We extract: `path = substr(line, 19, commaPos - 19)`, `date = substr(line, commaPos + 1, 10)`

## Performance Journey

| Environment | Approach | Time |
|-------------|----------|------|
| Mac M4 (unconstrained) | 16 workers + bulk strtok | **5.4s** |
| Docker (2 vCPU, 1.5GB) | 2 workers + 300MB sub-chunks | **~26s** |
| Leaderboard best | Unknown | **21.9s** |

**Gap to close: ~4-5 seconds (26s → 21.9s)**

## What We've Tried

### Successful Optimizations
1. `strpos`/`substr` instead of `parse_url()`
2. `pcntl_fork()` for parallelization
3. `igbinary` for IPC serialization
4. `file_get_contents()` + `strtok()` (C-native, fast)
5. Calculated comma position instead of `strpos()`
6. `++$result` with `isset` checks
7. `/dev/shm` for temp files (RAM-based tmpfs)
8. Hybrid sub-chunking for constrained memory

### Failed Experiments
- `preg_match_all()` - massive memory (1.25GB for matches)
- `explode()` - 6.25M element array overhead
- Flat key `"$path|$date"` - more memory than nested arrays
- Larger sub-chunks (400-500MB) - caused memory pressure

## Ideas NOT Yet Tried

### 1. **Pure `fgets()` Streaming** (HIGH PRIORITY)
Our 300MB sub-chunks cause memory pressure. Pure streaming might be faster:
- Constant ~100 byte memory per line
- No large buffer allocation/deallocation
- OS manages file caching efficiently
- Hypothesis: Leaders might use this!

### 2. **Shared Memory (`shmop`)** 
Available on benchmark server! Could eliminate file I/O for IPC:
```php
$shm = shmop_open($key, "c", 0644, $size);
shmop_write($shm, igbinary_serialize($result), 0);
```

### 3. **Different Worker Counts**
- Try 3-4 workers instead of 2
- Better CPU/IO overlap with I/O-bound workload

### 4. **Merge Optimization**
- Current merge is ~0.04s with `/dev/shm`
- Could merge in parallel or use different data structure

## Docker Testing Commands

```bash
# Build and run in constrained environment
docker compose build
docker compose run --rm -T parser

# Run multiple benchmarks
for i in 1 2 3; do docker compose run --rm -T parser 2>&1 | grep "TOTAL:"; done
```

## Key Code Locations

### Parser.php - Constrained Detection
```php
$isConstrained = $memoryLimit > 256 * 1024 * 1024 && $memoryLimit < 2 * 1024 * 1024 * 1024;
$numWorkers = $isConstrained ? 2 : 16;
```

### Parser.php - Sub-chunk Processing
```php
private function processChunkHybrid(string $inputPath, int $start, int $end, int $subChunkSize): array
```

### Parser.php - Bulk Processing  
```php
private function processChunkBulk(string $inputPath, int $start, int $end): array
```

## Available Extensions on Benchmark Server
```
igbinary, pcntl, shmop, sysvsem, sysvshm, sysvmsg, msgpack, memcached, redis
```

## Next Steps (Priority Order)

1. **Try pure `fgets()` streaming** - Might be faster due to less memory pressure
2. **Implement `shmop` for IPC** - Eliminate temp file I/O
3. **Test 3-4 workers** - Better parallelism with I/O overlap
4. **Profile memory usage** - Understand where pressure comes from

## Quick Benchmark Reference

```bash
# Native Mac (unconstrained)
/opt/homebrew/Cellar/php/8.5.3/bin/php -d memory_limit=-1 tempest data:parse

# Docker (constrained)
docker compose run --rm -T parser
```
