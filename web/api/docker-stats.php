<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Only admins can subscribe to all container stats
$identity = resolve_user_identity();
if (!$identity || $identity['role'] !== 'admin') {
    echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
    ob_flush(); flush();
    exit();
}

session_write_close();

$docker = new DockerClient();

while (true) {
    if (connection_aborted()) {
        break;
    }

    try {
        // Find all users who are currently running a container
        $stmt = $pdo->query("SELECT id, username, container_id, lab_type FROM users WHERE container_status = 'running' AND container_id IS NOT NULL");
        $users = $stmt->fetchAll();

        $statsData = [];

        foreach ($users as $user) {
            $cid = $user['container_id'];
            $rawStats = $docker->getContainerStats($cid);

            if ($rawStats && !isset($rawStats['error']) && is_array($rawStats)) {
                // Calculate CPU %
                $cpu_stats = $rawStats['cpu_stats'] ?? [];
                $precpu_stats = $rawStats['precpu_stats'] ?? [];
                
                $cpu_percent = 0.0;
                if (!empty($cpu_stats) && !empty($precpu_stats)) {
                    $cpu_usage = $cpu_stats['cpu_usage']['total_usage'] ?? 0;
                    $precpu_usage = $precpu_stats['cpu_usage']['total_usage'] ?? 0;
                    $system_cpu = $cpu_stats['system_cpu_usage'] ?? 0;
                    $presystem_cpu = $precpu_stats['system_cpu_usage'] ?? 0;

                    $cpu_delta = $cpu_usage - $precpu_usage;
                    $system_delta = $system_cpu - $presystem_cpu;

                    if ($system_delta > 0.0 && $cpu_delta > 0.0) {
                        // Get number of CPUs
                        $num_cpus = count($cpu_stats['cpu_usage']['percpu_usage'] ?? [1]);
                        if ($num_cpus == 0) $num_cpus = 1;
                        $cpu_percent = ($cpu_delta / $system_delta) * $num_cpus * 100.0;
                    }
                }

                // Calculate Memory %
                $mem_usage = $rawStats['memory_stats']['usage'] ?? 0;
                $mem_limit = $rawStats['memory_stats']['limit'] ?? 1;
                $mem_percent = $mem_limit > 0 ? ($mem_usage / $mem_limit) * 100.0 : 0;

                // Network RX/TX Bytes
                $networks = $rawStats['networks'] ?? [];
                $rx_bytes = 0;
                $tx_bytes = 0;
                foreach ($networks as $net) {
                    $rx_bytes += $net['rx_bytes'] ?? 0;
                    $tx_bytes += $net['tx_bytes'] ?? 0;
                }

                // Disk I/O Bytes
                $blkio = $rawStats['blkio_stats']['io_service_bytes_recursive'] ?? [];
                $disk_read = 0;
                $disk_write = 0;
                if (is_array($blkio)) {
                    foreach ($blkio as $io) {
                        if (strtolower($io['op'] ?? '') === 'read') {
                            $disk_read += $io['value'] ?? 0;
                        } else if (strtolower($io['op'] ?? '') === 'write') {
                            $disk_write += $io['value'] ?? 0;
                        }
                    }
                }

                $statsData[] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'lab_type' => $user['lab_type'],
                    'cpu' => round($cpu_percent, 1),
                    'memory' => round($mem_percent, 1),
                    'memory_raw' => $mem_usage,
                    'memory_limit' => $mem_limit,
                    'net_rx' => $rx_bytes,
                    'net_tx' => $tx_bytes,
                    'disk_read' => $disk_read,
                    'disk_write' => $disk_write
                ];
            }
        }

        echo "data: " . json_encode($statsData) . "\n\n";
        ob_flush(); flush();

    } catch (Exception $e) {
        echo "data: " . json_encode(['error' => 'Server error occurred while streaming stats.']) . "\n\n";
        ob_flush(); flush();
    }

    sleep(2); // Poll stats every 2 seconds
}
?>
