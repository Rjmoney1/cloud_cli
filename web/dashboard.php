<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/DockerClient.php';

// Verify login
check_login();

$userId = $_SESSION['student_user_id'];
$username = $_SESSION['student_username'];
$labType = $_SESSION['student_lab_type'];

$docker = new DockerClient();

// Fetch current user details from DB to get the latest status
$stmt = $pdo->prepare("SELECT container_status, container_id, ssh_private_key, ssh_public_key FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$containerStatus = $user['container_status'] ?? 'stopped';
$containerId = $user['container_id'] ?? '';

// Check and generate SSH keys dynamically if missing
if (empty($user['ssh_private_key'])) {
    $tempFile = tempnam(sys_get_temp_dir(), 'ssh');
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    exec("ssh-keygen -t rsa -b 2048 -f " . escapeshellarg($tempFile) . " -N '' -q");
    if (file_exists($tempFile)) {
        $privateKey = file_get_contents($tempFile);
        $publicKey = trim(file_get_contents($tempFile . '.pub'));
        
        unlink($tempFile);
        unlink($tempFile . '.pub');

        $updateKeys = $pdo->prepare("UPDATE users SET ssh_private_key = ?, ssh_public_key = ? WHERE id = ?");
        $updateKeys->execute([$privateKey, $publicKey, $userId]);

        $user['ssh_private_key'] = $privateKey;
        $user['ssh_public_key'] = $publicKey;
    }
}

// Dynamically verify if container is actually running in Docker
if ($containerStatus === 'running' && !empty($containerId)) {
    $inspect = $docker->inspectContainer($containerId);
    if ($inspect === null || !isset($inspect['State']['Running']) || !$inspect['State']['Running']) {
        // Container is not actually running! Sync database state
        $containerStatus = 'stopped';
        $updateStmt = $pdo->prepare("UPDATE users SET container_status = 'stopped' WHERE id = ?");
        $updateStmt->execute([$userId]);
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - CloudLab</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
          theme: {
            extend: {
              colors: {
                brand: {
                  DEFAULT: '#F5B027',
                  50: '#fef7e9',
                  100: '#fdefc9',
                  200: '#fbdf92',
                  300: '#f9c852',
                  400: '#f7b426',
                  500: '#F5B027',
                  600: '#d59114',
                  700: '#ae7011',
                  800: '#8c5613',
                  900: '#734613',
                  950: '#432407',
                }
              },
              fontFamily: {
                sans: ['Outfit', 'Inter', 'sans-serif'],
              }
            }
          }
        }
    </script>
    <style>
        .gradient-bg {
            background: radial-gradient(circle at top right, rgba(245, 176, 39, 0.1) 0%, transparent 60%),
                        radial-gradient(circle at bottom left, rgba(245, 176, 39, 0.05) 0%, transparent 50%);
        }
        .shimmer {
            background: linear-gradient(90deg, #18181b 25%, #27272a 50%, #18181b 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    </style>
</head>
<body class="min-h-full font-sans text-zinc-100 gradient-bg flex flex-col justify-between">

    <!-- Top Navigation -->
    <nav class="border-b border-zinc-900 bg-zinc-950/40 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-9 w-9 rounded-lg bg-brand/10 border border-brand/40 flex items-center justify-center text-brand text-lg">
                    <i class="fa-solid fa-terminal"></i>
                </div>
                <span class="text-xl font-bold text-white">Cloud<span class="text-brand">Lab</span></span>
            </div>
            
            <div class="flex items-center gap-6">
                <div class="hidden sm:flex items-center gap-2 text-sm text-zinc-400">
                    <span class="h-2 w-2 rounded-full bg-green-500"></span>
                    Logged in as: <span class="font-semibold text-zinc-200"><?= htmlspecialchars($username) ?></span>
                </div>
                <a href="api/auth.php?action=logout&role=student" class="py-2 px-4 bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-800 rounded-lg text-sm font-medium transition-all duration-200">
                    <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Dashboard Body -->
    <main class="flex-grow max-w-7xl w-full mx-auto px-6 py-12 flex flex-col gap-8">
        
        <!-- Welcome banner -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 border-b border-zinc-900 pb-6">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-white mb-1">Hello, <?= htmlspecialchars($username) ?>!</h1>
                <p class="text-zinc-400">Manage and connect to your dedicated Linux laboratory workspace.</p>
            </div>
            <div class="px-4 py-2 bg-zinc-900/80 border border-zinc-800 rounded-xl text-xs flex items-center gap-2">
                <span class="text-zinc-500 font-medium">Lab Name:</span>
                <span class="font-mono text-zinc-300 font-semibold"><?= !empty($containerId) ? 'lab-' . htmlspecialchars($username) : 'Not Initialized' ?></span>
            </div>
        </div>

        <!-- Dashboard Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Lab Container Status Card -->
            <div class="lg:col-span-2 bg-zinc-900/40 backdrop-blur-xl border border-zinc-900 rounded-3xl p-8 flex flex-col justify-between shadow-xl gap-8">
                <div class="flex justify-between items-start">
                    <div class="flex gap-4 items-center">
                        <div class="h-14 w-14 rounded-2xl bg-brand/10 border border-brand/20 flex items-center justify-center text-brand text-2xl shadow-inner">
                            <?php if ($labType === 'Kali Linux'): ?>
                                <i class="fa-solid fa-shield-halved"></i>
                            <?php elseif ($labType === 'Ubuntu with Docker'): ?>
                                <i class="fa-brands fa-docker"></i>
                            <?php elseif ($labType === 'Ubuntu with Java Dev Server'): ?>
                                <i class="fa-brands fa-java"></i>
                            <?php elseif ($labType === 'Ubuntu with MySQL'): ?>
                                <i class="fa-solid fa-database"></i>
                            <?php elseif ($labType === 'Ubuntu with Nginx'): ?>
                                <i class="fa-solid fa-server"></i>
                            <?php else: ?>
                                <i class="fa-brands fa-ubuntu"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span class="text-xs uppercase tracking-wider text-brand font-semibold">Workspace</span>
                            <h2 class="text-2xl font-bold text-white"><?= htmlspecialchars($labType) ?></h2>
                        </div>
                    </div>

                    <!-- Live status indicator -->
                    <div id="status-badge" class="px-3 py-1.5 rounded-full text-xs font-semibold flex items-center gap-1.5 transition-all duration-300">
                        <span id="status-dot" class="h-2 w-2 rounded-full"></span>
                        <span id="status-text">Checking...</span>
                    </div>
                </div>

                <!-- Info description block -->
                <div class="bg-zinc-950/50 border border-zinc-900 rounded-2xl p-6 text-sm text-zinc-400 space-y-3">
                    <div class="flex gap-3">
                        <i class="fa-solid fa-circle-info text-brand mt-0.5"></i>
                        <p>This lab environment runs as a fully isolated, privileged Docker container just for you. You have full root privileges and a persistent home directory (<code class="text-zinc-200 font-semibold font-mono">/home/developer</code>) which remains saved even when the container is stopped.</p>
                    </div>
                </div>

                <!-- Action Button Controls -->
                <div class="flex flex-col sm:flex-row gap-4 items-center border-t border-zinc-900 pt-6">
                    <button id="btn-start" onclick="controlContainer('start')" 
                        class="w-full sm:w-auto px-6 py-3 bg-brand hover:bg-brand-600 disabled:opacity-40 disabled:hover:bg-brand text-zinc-950 font-bold rounded-xl shadow-lg shadow-brand/10 transition-all duration-200 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-play"></i> Start Lab
                    </button>
                    
                    <button id="btn-stop" onclick="controlContainer('stop')" 
                        class="w-full sm:w-auto px-6 py-3 bg-zinc-900 hover:bg-zinc-800 disabled:opacity-40 disabled:hover:bg-zinc-900 border border-zinc-800 text-zinc-300 hover:text-white font-bold rounded-xl transition-all duration-200 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-stop"></i> Stop Lab
                    </button>

                    <button id="btn-restart" onclick="controlContainer('restart')" 
                        class="w-full sm:w-auto px-6 py-3 bg-zinc-900 hover:bg-zinc-800 disabled:opacity-40 disabled:hover:bg-zinc-900 border border-zinc-800 text-zinc-300 hover:text-white font-bold rounded-xl transition-all duration-200 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-rotate-left"></i> Restart Lab
                    </button>
                </div>
            </div>

            <!-- Launch workspace trigger card -->
            <div class="bg-zinc-900/40 backdrop-blur-xl border border-zinc-900 rounded-3xl p-8 flex flex-col justify-between shadow-xl gap-6">
                <div>
                    <h3 class="text-xl font-bold text-white mb-2">Connect to IDE</h3>
                    <p class="text-sm text-zinc-400 mb-6">Launch the browser-based VS Code environment (code-server) with full integrated terminal access.</p>
                    
                    <div class="border border-zinc-800/40 rounded-xl p-4 bg-zinc-950/20 text-xs text-zinc-500 space-y-2">
                        <div class="flex justify-between">
                            <span>HTTP Port:</span>
                            <span class="font-mono text-zinc-400">80 (Proxied)</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Shell IDE:</span>
                            <span class="font-mono text-zinc-400">code-server</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Default User:</span>
                            <span class="font-mono text-zinc-400">developer</span>
                        </div>
                    </div>
                </div>

                <a id="btn-launch" href="#" target="_blank" onclick="return checkLaunchActive(event)"
                    class="w-full py-4 bg-zinc-900 border border-zinc-800 text-zinc-500 pointer-events-none rounded-2xl font-bold text-center transition-all duration-300 flex items-center justify-center gap-2 select-none">
                    <i class="fa-solid fa-code text-xl"></i> Launch VS Code IDE
                </a>

                <div id="ssh-info-box" class="hidden border border-zinc-800/40 rounded-xl p-4 bg-zinc-950/20 text-xs text-zinc-400 space-y-3">
                    <span class="font-bold text-zinc-300"><i class="fa-solid fa-terminal text-brand mr-1"></i> SSH Terminal Connection</span>
                    
                    <a href="api/download-key.php" class="flex items-center justify-center gap-2 w-full py-2.5 px-4 bg-zinc-900 hover:bg-zinc-800 border border-zinc-850 text-zinc-300 hover:text-white rounded-xl text-xs font-semibold transition-all duration-200 shadow-md">
                        <i class="fa-solid fa-key text-brand"></i> Download SSH Private Key (.pem)
                    </a>
                    
                    <div class="space-y-2">
                        <p class="text-zinc-500 text-[11px] leading-relaxed">Connect to this workspace using any standard SSH client with the downloaded private key:</p>
                        <div class="bg-zinc-950 border border-zinc-900 rounded-lg p-2.5 font-mono text-zinc-300 select-all relative group flex items-center justify-between">
                            <span id="ssh-command-text">ssh -i cloudlab-<?= htmlspecialchars($username) ?>.pem <?= htmlspecialchars($username) ?>@localhost -p <?= 20000 + intval($userId) ?></span>
                            <button onclick="navigator.clipboard.writeText(document.getElementById('ssh-command-text').innerText); alert('Copied to clipboard!');" class="text-zinc-500 hover:text-white transition" title="Copy to clipboard">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <span class="text-[10px] text-zinc-500 block leading-normal">Note: You may need to run <code class="font-mono text-zinc-400 bg-zinc-950 px-1 py-0.5 rounded border border-zinc-900">chmod 400 cloudlab-<?= htmlspecialchars($username) ?>.pem</code> before connecting on Linux/macOS.</span>
                    </div>
                </div>
            </div>
            
        </div>
    </main>

    <!-- Footer -->
    <footer class="w-full py-6 text-center text-xs text-zinc-600 border-t border-zinc-900/60 max-w-7xl mx-auto">
        <p>© 2026 CloudLab. Built for final year B.E. Computer Science & Engineering Project.</p>
    </footer>

    <!-- Script handlers -->
    <script>
        let currentStatus = "<?= $containerStatus ?>";
         function updateUIState(status) {
            currentStatus = status;

            const badge = document.getElementById('status-badge');
            const dot = document.getElementById('status-dot');
            const text = document.getElementById('status-text');
            
            const btnStart = document.getElementById('btn-start');
            const btnStop = document.getElementById('btn-stop');
            const btnRestart = document.getElementById('btn-restart');
            const btnLaunch = document.getElementById('btn-launch');
            const sshBox = document.getElementById('ssh-info-box');

            if (status === 'running') {
                // Badge
                badge.className = "px-3 py-1.5 rounded-full text-xs font-semibold flex items-center gap-1.5 bg-green-950/40 border border-green-500/30 text-green-400 shadow-lg shadow-green-950/20";
                dot.className = "h-2 w-2 rounded-full bg-green-500 animate-ping";
                text.innerText = "Running";

                // Buttons
                btnStart.disabled = true;
                btnStop.disabled = false;
                btnRestart.disabled = false;

                // Launch Button Active Style
                btnLaunch.className = "w-full py-4 bg-brand hover:bg-brand-600 active:bg-brand-700 text-zinc-950 rounded-2xl font-bold text-center shadow-lg shadow-brand/20 hover:shadow-brand/35 transition-all duration-300 flex items-center justify-center gap-2 transform hover:scale-[1.01]";
                btnLaunch.classList.remove('pointer-events-none');
                btnLaunch.href = "/workspace/<?= htmlspecialchars($username) ?>/";
                
                // Show SSH Box
                sshBox.classList.remove('hidden');
            } else if (status === 'stopped') {
                // Badge
                badge.className = "px-3 py-1.5 rounded-full text-xs font-semibold flex items-center gap-1.5 bg-zinc-900 border border-zinc-800 text-zinc-400";
                dot.className = "h-2 w-2 rounded-full bg-zinc-600";
                text.innerText = "Stopped";

                // Buttons
                btnStart.disabled = false;
                btnStop.disabled = true;
                btnRestart.disabled = true;

                // Launch Button Disabled Style
                btnLaunch.className = "w-full py-4 bg-zinc-900 border border-zinc-800/60 text-zinc-600 pointer-events-none rounded-2xl font-bold text-center flex items-center justify-center gap-2 select-none";
                btnLaunch.classList.add('pointer-events-none');
                btnLaunch.href = "#";

                // Hide SSH Box
                sshBox.classList.add('hidden');
            } else {
                // Pending/transition state
                badge.className = "px-3 py-1.5 rounded-full text-xs font-semibold flex items-center gap-1.5 bg-amber-950/30 border border-brand/30 text-brand shadow-lg shadow-brand/10";
                dot.className = "h-2 w-2 rounded-full bg-brand animate-pulse";
                text.innerText = status.charAt(0).toUpperCase() + status.slice(1);

                // Disable all action buttons
                btnStart.disabled = true;
                btnStop.disabled = true;
                btnRestart.disabled = true;
                btnLaunch.className = "w-full py-4 bg-zinc-900 border border-zinc-800/60 text-zinc-600 pointer-events-none rounded-2xl font-bold text-center flex items-center justify-center gap-2 select-none";
                btnLaunch.classList.add('pointer-events-none');

                // Hide SSH Box
                sshBox.classList.add('hidden');
            }
        }

        function checkLaunchActive(e) {
            if (currentStatus !== 'running') {
                e.preventDefault();
                return false;
            }
            return true;
        }

        function controlContainer(action) {
            let transitionState = "starting...";
            if (action === 'stop') transitionState = "stopping...";
            if (action === 'restart') transitionState = "restarting...";

            updateUIState(transitionState);

            fetch(`api/container.php?action=${action}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        updateUIState(data.status);
                    } else {
                        alert("Action failed: " + data.message);
                        // Re-fetch correct status
                        location.reload();
                    }
                })
                .catch(err => {
                    console.error("Error managing container:", err);
                    alert("A network error occurred. Please try again.");
                    location.reload();
                });
        }



        // Initialize state on page load
        document.addEventListener('DOMContentLoaded', () => {
            updateUIState(currentStatus);
        });
    </script>
</body>
</html>
