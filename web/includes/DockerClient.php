<?php
class DockerClient {
    private $socketPath = '/var/run/docker.sock';

    private function request($path, $method = 'GET', $data = null, $stream = false, $onData = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->socketPath);
        curl_setopt($ch, CURLOPT_URL, 'http://localhost' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, !$stream);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($data !== null) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonData)
            ]);
        }

        if ($stream && $onData) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $str) use ($onData) {
                $onData($str);
                return strlen($str);
            });
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($stream) {
            return $httpCode;
        }

        return [
            'code' => $httpCode,
            'body' => json_decode($response, true) ?: $response
        ];
    }

    public function listContainers($all = true) {
        $res = $this->request('/containers/json?all=' . ($all ? 'true' : 'false'));
        return $res['body'] ?? [];
    }

    public function inspectContainer($id) {
        $res = $this->request("/containers/$id/json");
        return $res['code'] === 200 ? $res['body'] : null;
    }

    public function createVolume($name) {
        $data = [
            'Name' => $name
        ];
        $res = $this->request('/volumes/create', 'POST', $data);
        return $res['code'] === 201;
    }

    public function createContainer($name, $image, $basePath, $volumeName, $username = 'developer', $passwordHash = '', $cpuLimit = 1.0, $memoryLimit = 1024, $gpuLimit = 0, $sshPort = 0, $sshPublicKey = '') {
        // Convert PHP $2y$ blowfish prefix to Linux PAM compatible $2a$ prefix
        $linuxHash = str_replace('$2y$', '$2a$', $passwordHash);

        $hostConfig = [
            'Binds' => [
                "$volumeName:/home/$username",
                "/var/lib/linux-lab/isos:/media/isos:ro"
            ],
            'Privileged' => true,
            'NetworkMode' => 'linux-lab-net'
        ];

        // Apply resource limits
        if ($cpuLimit > 0) {
            $hostConfig['NanoCpus'] = intval($cpuLimit * 1000000000);
        }
        if ($memoryLimit > 0) {
            $hostConfig['Memory'] = intval($memoryLimit * 1024 * 1024);
            $hostConfig['MemorySwap'] = intval($memoryLimit * 1024 * 1024);
        }
        if ($gpuLimit > 0 || $gpuLimit === -1) {
            $hostConfig['DeviceRequests'] = [
                [
                    'Driver' => 'nvidia',
                    'Count' => $gpuLimit,
                    'Capabilities' => [['gpu']]
                ]
            ];
        }

        // Apply SSH port mapping
        if ($sshPort > 0) {
            $hostConfig['PortBindings'] = [
                '22/tcp' => [
                    [
                        'HostPort' => (string)$sshPort
                    ]
                ]
            ];
        }

        $data = [
            'Hostname' => $name,
            'Image' => $image,
            'Env' => [
                "BASE_PATH=$basePath",
                "USER_NAME=$username",
                "USER_PASSWORD_HASH=$linuxHash",
                "USER_SSH_PUBLIC_KEY=$sshPublicKey"
            ],
            'ExposedPorts' => [
                '8080/tcp' => new stdClass(),
                '22/tcp' => new stdClass()
            ],
            'HostConfig' => $hostConfig
        ];

        $res = $this->request("/containers/create?name=$name", 'POST', $data);
        return $res['code'] === 201 ? ($res['body']['Id'] ?? null) : null;
    }

    public function startContainer($id) {
        $res = $this->request("/containers/$id/start", 'POST');
        return ($res['code'] === 204 || $res['code'] === 304);
    }

    public function stopContainer($id) {
        $res = $this->request("/containers/$id/stop", 'POST');
        return ($res['code'] === 204 || $res['code'] === 304);
    }

    public function restartContainer($id) {
        $res = $this->request("/containers/$id/restart", 'POST');
        return ($res['code'] === 204);
    }

    public function removeContainer($id) {
        $res = $this->request("/containers/$id?v=true&force=true", 'DELETE');
        return ($res['code'] === 204);
    }

    public function getContainerStats($id) {
        $res = $this->request("/containers/$id/stats?stream=false");
        return $res['code'] === 200 ? $res['body'] : null;
    }

    public function streamContainerStats($id, $onData) {
        return $this->request("/containers/$id/stats?stream=true", 'GET', null, true, $onData);
    }

    public function getContainerLogs($id, $tail = 100) {
        // Get logs statically
        $res = $this->request("/containers/$id/logs?stdout=true&stderr=true&tail=$tail");
        return $this->cleanDockerOutput($res['body']);
    }

    public function streamContainerLogs($id, $onData, $tail = 100) {
        return $this->request("/containers/$id/logs?stdout=true&stderr=true&tail=$tail&follow=true", 'GET', null, true, function($data) use ($onData) {
            $cleaned = $this->cleanDockerOutput($data);
            if (!empty($cleaned)) {
                $onData($cleaned);
            }
        });
    }

    public function execCommand($containerId, $cmdArray) {
        // Create exec
        $data = [
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Cmd' => $cmdArray
        ];
        $res = $this->request("/containers/$containerId/exec", 'POST', $data);
        $execId = $res['body']['Id'] ?? null;
        if (!$execId) return "Failed to create exec session.";

        // Start exec
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->socketPath);
        curl_setopt($ch, CURLOPT_URL, "http://localhost/exec/$execId/start");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $startPayload = json_encode([
            "Detach" => false,
            "Tty" => false
        ]);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $startPayload);
        $output = curl_exec($ch);
        curl_close($ch);
        
        return $this->cleanDockerOutput($output);
    }

    public function pullImage($image) {
        $res = $this->request("/images/create?fromImage=" . urlencode($image), 'POST');
        return $res['code'] === 200;
    }

    private function cleanDockerOutput($output) {
        if (empty($output) || !is_string($output)) return $output;
        
        $cleaned = "";
        $len = strlen($output);
        $i = 0;
        
        while ($i < $len) {
            // Docker multiplexed header is exactly 8 bytes
            if ($i + 8 > $len) {
                $cleaned .= substr($output, $i);
                break;
            }
            
            $header = substr($output, $i, 8);
            
            // Check if it matches a docker stream header: stream type is 0, 1, or 2
            $streamType = ord($header[0]);
            if ($streamType > 2 || ord($header[1]) !== 0 || ord($header[2]) !== 0 || ord($header[3]) !== 0) {
                // If it doesn't look like a standard docker stream header, treat as raw string
                $cleaned .= substr($output, $i);
                break;
            }
            
            $size = unpack('N', substr($header, 4, 4))[1];
            $payload = substr($output, $i + 8, $size);
            $cleaned .= $payload;
            $i += 8 + $size;
        }
        return $cleaned;
    }
}
?>
