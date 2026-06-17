<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/includes/DockerClient.php';

$docker = new DockerClient();

echo "=============================================\n";
echo "   Docker Socket Connection Tester           \n";
echo "=============================================\n\n";

echo "[*] Testing connection to /var/run/docker.sock...\n";

try {
    $containers = $docker->listContainers(true);
    
    if (is_array($containers)) {
        echo "[+] Connection SUCCESSFUL!\n";
        echo "[+] Docker daemon responded with " . count($containers) . " containers.\n\n";
        
        echo "---------------------------------------------\n";
        echo "Active Containers List on Host:\n";
        echo "---------------------------------------------\n";
        
        if (empty($containers)) {
            echo "No containers found.\n";
        } else {
            foreach ($containers as $c) {
                $id = substr($c['Id'] ?? '', 0, 12);
                $names = implode(', ', $c['Names'] ?? []);
                $image = $c['Image'] ?? 'unknown';
                $state = $c['State'] ?? 'unknown';
                echo " - ID: $id | Name: $names | Image: $image | State: $state\n";
            }
        }
    } else {
        echo "[-] Connection FAILED. Response was not an array. Check docker socket permissions.\n";
        echo "Response output:\n";
        print_r($containers);
    }
} catch (Exception $e) {
    echo "[-] Connection FAILED with exception:\n";
    echo "Error: " . $e->getMessage() . "\n";
}
echo "\n=============================================\n";
?>
