<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth_check.php';

// Only admins can subscribe to server stats
if (!isset($_SESSION['admin_user_id']) || $_SESSION['admin_role'] !== 'admin') {
    echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
    ob_flush(); flush();
    exit();
}

session_write_close();

// Function to read CPU ticks
function getCpuTicks() {
    $stat = @file_get_contents('/proc/stat');
    if (!$stat) return null;
    
    $lines = explode("\n", $stat);
    foreach ($lines as $line) {
        if (strpos($line, 'cpu ') === 0) {
            $parts = preg_split('/\s+/', trim($line));
            array_shift($parts); // Remove the 'cpu' label
            $total = array_sum($parts);
            $idle = $parts[3]; // Index 3 is idle ticks
            return ['total' => $total, 'idle' => $idle];
        }
    }
    return null;
}

// Function to read RAM bytes
function getRamInfo() {
    $meminfo = @file_get_contents('/proc/meminfo');
    if (!$meminfo) return null;
    
    preg_match('/MemTotal:\s+(\d+)/', $meminfo, $matches_total);
    preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $matches_avail);
    
    $totalKB = intval($matches_total[1] ?? 0);
    $availKB = intval($matches_avail[1] ?? 0);
    
    $total = $totalKB * 1024;
    $avail = $availKB * 1024;
    $used = $total - $avail;
    
    $percent = $total > 0 ? ($used / $total) * 100 : 0;
    
    return [
        'total' => $total,
        'used' => $used,
        'percent' => $percent
    ];
}

// Main SSE Stream loop
while (true) {
    if (connection_aborted()) {
        break;
    }

    // 1. Get Host RAM
    $ram = getRamInfo() ?: ['total' => 0, 'used' => 0, 'percent' => 0];

    // 2. Get Host CPU (differential measurement)
    $ticks1 = getCpuTicks();
    usleep(200000); // Wait 200ms
    $ticks2 = getCpuTicks();

    $cpuPercent = 0.0;
    if ($ticks1 && $ticks2) {
        $totalDelta = $ticks2['total'] - $ticks1['total'];
        $idleDelta = $ticks2['idle'] - $ticks1['idle'];
        
        if ($totalDelta > 0) {
            $cpuPercent = (1.0 - ($idleDelta / $totalDelta)) * 100.0;
        }
    }

    // 3. Emit SSE Event
    echo "data: " . json_encode([
        'cpu' => round($cpuPercent, 1),
        'ram' => round($ram['percent'], 1),
        'ram_used' => $ram['used'],
        'ram_total' => $ram['total']
    ]) . "\n\n";

    ob_flush();
    flush();
    
    sleep(1); // Poll every 1 second
}
?>
