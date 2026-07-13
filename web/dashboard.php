<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/DockerClient.php';

// Verify login
check_login();

$identity = resolve_user_identity();
if (!$identity) {
    header("Location: index.php");
    exit();
}
if (($identity['role'] ?? '') === 'admin') {
    header("Location: admin.php?tab_token=" . urlencode(get_current_tab_token()));
    exit();
}

$userId = $identity['user_id'];
$username = $identity['username'];
$labType = $identity['lab_type'] ?? 'Ubuntu 22.04 LTS';

$docker = new DockerClient();

/**
 * Returns icon, color utility, and button classes based on service name.
 */
function getServiceDetails($name) {
    $details = [
        'icon' => 'fa-brands fa-ubuntu',
        'color' => 'text-orange-600 bg-orange-600/10 border-orange-500/20',
        'btn_class' => 'bg-orange-600 hover:bg-orange-500 text-white shadow-orange-600/20'
    ];
    
    $lower = strtolower($name);
    if (strpos($lower, 'kali') !== false) {
        $details['icon'] = 'fa-solid fa-shield-halved';
        $details['color'] = 'text-sky-400 bg-sky-400/10 border-sky-500/20';
        $details['btn_class'] = 'bg-sky-600 hover:bg-sky-500 text-white shadow-sky-600/20';
    } elseif (strpos($lower, 'docker') !== false) {
        $details['icon'] = 'fa-brands fa-docker';
        $details['color'] = 'text-blue-400 bg-blue-400/10 border-blue-500/20';
        $details['btn_class'] = 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-600/20';
    } elseif (strpos($lower, 'java') !== false) {
        $details['icon'] = 'fa-brands fa-java';
        $details['color'] = 'text-orange-500 bg-orange-500/10 border-orange-500/20';
        $details['btn_class'] = 'bg-orange-600 hover:bg-orange-500 text-white shadow-orange-600/20';
    } elseif (strpos($lower, 'mysql') !== false) {
        $details['icon'] = 'fa-solid fa-database';
        $details['color'] = 'text-emerald-400 bg-emerald-400/10 border-emerald-500/20';
        $details['btn_class'] = 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-emerald-600/20';
    } elseif (strpos($lower, 'nginx') !== false) {
        $details['icon'] = 'fa-solid fa-server';
        $details['color'] = 'text-green-400 bg-green-400/10 border-green-500/20';
        $details['btn_class'] = 'bg-green-600 hover:bg-green-500 text-white shadow-green-600/20';
    } elseif (strpos($lower, 'n8n') !== false) {
        $details['icon'] = 'fa-solid fa-circle-nodes';
        $details['color'] = 'text-rose-500 bg-rose-500/10 border-rose-500/20';
        $details['btn_class'] = 'bg-rose-600 hover:bg-rose-500 text-white shadow-rose-600/20';
    }
    
    return $details;
}

// Fetch current user details from DB to get the latest status
$stmt = $pdo->prepare("SELECT lab_type, container_status, container_id, ssh_private_key, ssh_public_key, mfa_secret, mfa_enabled FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$labType = $user['lab_type'] ?? $_SESSION['student_lab_type'];
$containerStatus = $user['container_status'] ?? 'stopped';
$containerId = $user['container_id'] ?? '';
$mfaEnabled = intval($user['mfa_enabled'] ?? 0);
$mfaSecret = $user['mfa_secret'] ?? '';

// If the user has no MFA secret, generate it dynamically
if (empty($mfaSecret)) {
    require_once __DIR__ . '/includes/TOTP.php';
    $mfaSecret = TOTP::generateSecret();
    $updateSecret = $pdo->prepare("UPDATE users SET mfa_secret = ? WHERE id = ?");
    $updateSecret->execute([$mfaSecret, $userId]);
}

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

// Fetch all registered lab services
$servicesStmt = $pdo->query("SELECT * FROM services ORDER BY name ASC");
$services = $servicesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950">
<head>
    <script>
        // Redirect to the URL with the tab token if it's missing from the address bar
        if (!window.location.search.includes('tab_token')) {
            const savedUrl = sessionStorage.getItem('dashboard_url');
            if (savedUrl && savedUrl.includes('tab_token')) {
                window.location.replace(savedUrl);
            }
        }
    </script>
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
            
            <div class="flex items-center gap-4">
                <div class="hidden sm:flex items-center gap-2 text-sm text-zinc-400 mr-2">
                    <span class="h-2 w-2 rounded-full bg-green-500"></span>
                    Logged in as: <span class="font-semibold text-zinc-200"><?= htmlspecialchars($username) ?></span>
                </div>
                <a href="index.php?new_session=1" target="_blank" class="py-2 px-4 bg-zinc-900 hover:bg-zinc-800 text-brand border border-zinc-800 hover:border-brand/40 rounded-lg text-sm font-medium transition-all duration-200">
                    <i class="fa-solid fa-user-plus mr-1.5"></i> New Session
                </a>
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
                            <?php elseif ($labType === 'n8n Lab'): ?>
                                <i class="fa-solid fa-circle-nodes"></i>
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

                <?php if ($labType === 'n8n Lab'): ?>
                <a id="btn-launch-n8n" href="#" target="_blank" onclick="return checkLaunchActive(event)"
                    class="mt-3 w-full py-4 bg-zinc-900 border border-zinc-800 text-zinc-500 pointer-events-none rounded-2xl font-bold text-center transition-all duration-300 flex items-center justify-center gap-2 select-none">
                    <i class="fa-solid fa-circle-nodes text-xl"></i> Launch n8n Workflow UI
                </a>
                <?php endif; ?>

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

            <!-- MFA Card -->
            <div class="bg-zinc-900/40 backdrop-blur-xl border border-zinc-900 rounded-3xl p-8 flex flex-col justify-between shadow-xl gap-6">
                <div>
                    <h3 class="text-xl font-bold text-white mb-2"><i class="fa-solid fa-shield-halved text-brand mr-1"></i> 2FA Security</h3>
                    <p class="text-sm text-zinc-400 mb-4">Secure your lab access using Time-based One-Time Passwords (TOTP).</p>
                    
                    <div class="flex items-center justify-between mb-4 bg-zinc-950/20 border border-zinc-800/40 p-3.5 rounded-xl text-xs">
                        <span class="text-zinc-400 font-medium">Status:</span>
                        <?php if ($mfaEnabled): ?>
                            <span class="px-2.5 py-1 bg-green-950 border border-green-500/20 text-green-400 rounded-full font-bold">Enabled</span>
                        <?php else: ?>
                            <span class="px-2.5 py-1 bg-zinc-900 border border-zinc-850 text-zinc-400 rounded-full font-bold">Disabled</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$mfaEnabled): ?>
                        <div class="space-y-4 text-xs">
                            <p class="text-zinc-500 leading-relaxed font-semibold">1. Enter this Secret Key in Google Authenticator/Authy app:</p>
                            <div class="bg-zinc-950 border border-zinc-900 rounded-lg p-2.5 font-mono text-zinc-300 text-center select-all">
                                <span class="font-bold tracking-wider"><?= chunk_split($mfaSecret, 4, ' ') ?></span>
                            </div>
                            <p class="text-zinc-500 leading-relaxed font-semibold">2. Verify the 6-digit code to enable 2FA:</p>
                            <form id="form-mfa-enable" class="space-y-3">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="text" name="mfa_code" placeholder="Enter 6-digit code" required pattern="[0-9]{6}" maxlength="6"
                                    class="w-full text-center tracking-widest font-mono py-2.5 bg-zinc-950 border border-zinc-850 rounded-xl text-white focus:outline-none focus:border-brand">
                                <button type="button" onclick="configureMFA('enable')"
                                    class="w-full py-2.5 px-4 bg-brand hover:bg-brand-600 text-zinc-950 font-bold rounded-xl transition">
                                    Verify & Enable MFA
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4 text-xs">
                            <p class="text-zinc-500 leading-relaxed font-semibold">Enter the 6-digit code to disable 2FA security:</p>
                            <form id="form-mfa-disable" class="space-y-3">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="text" name="mfa_code" placeholder="Enter 6-digit code" required pattern="[0-9]{6}" maxlength="6"
                                    class="w-full text-center tracking-widest font-mono py-2.5 bg-zinc-950 border border-zinc-850 rounded-xl text-white focus:outline-none focus:border-brand">
                                <button type="button" onclick="configureMFA('disable')"
                                    class="w-full py-2.5 px-4 bg-red-950/20 text-red-400 hover:bg-red-950/40 border border-red-500/20 font-bold rounded-xl transition">
                                    Disable 2FA Security
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>

        <!-- Available Laboratory Environments -->
        <div class="space-y-6 border-t border-zinc-900/60 pt-10 mt-10">
            <div>
                <h2 class="text-2xl font-bold text-white tracking-tight flex items-center gap-2.5">
                    <i class="fa-solid fa-layer-group text-brand"></i> Available Laboratory Environments
                </h2>
                <p class="text-zinc-400 text-sm mt-1">Deploy any environment below. Switching environments preserves your files in the home directory.</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($services as $service): 
                    $isActive = ($labType === $service['name']);
                    $details = getServiceDetails($service['name']);
                ?>
                    <div class="relative group bg-zinc-900/20 hover:bg-zinc-900/45 border <?= $isActive ? 'border-brand/40 shadow-lg shadow-brand/5' : 'border-zinc-900 hover:border-zinc-800' ?> rounded-2xl p-6 transition-all duration-300 flex flex-col justify-between gap-6 overflow-hidden">
                        <?php if ($isActive): ?>
                            <div class="absolute top-0 right-0 bg-brand text-zinc-950 text-[10px] font-bold px-3 py-1 rounded-bl-xl uppercase tracking-wider">
                                Active
                            </div>
                        <?php endif; ?>
                        
                        <div class="space-y-4">
                            <div class="flex items-center gap-3">
                                <div class="h-12 w-12 rounded-xl border flex items-center justify-center text-xl <?= $details['color'] ?>">
                                    <i class="<?= $details['icon'] ?>"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-white text-base group-hover:text-brand transition-colors"><?= htmlspecialchars($service['name']) ?></h3>
                                    <span class="text-[10px] font-mono text-zinc-500 uppercase"><?= htmlspecialchars($service['image_name']) ?></span>
                                </div>
                            </div>
                            <p class="text-xs text-zinc-400 leading-relaxed min-h-[48px]"><?= htmlspecialchars($service['description']) ?></p>
                        </div>

                        <div class="border-t border-zinc-900/60 pt-4">
                            <?php if ($isActive): ?>
                                <button disabled class="w-full py-2.5 bg-zinc-950 border border-zinc-900 text-zinc-500 text-xs font-bold rounded-xl flex items-center justify-center gap-1.5 cursor-not-allowed select-none">
                                    <i class="fa-solid fa-circle-check text-brand"></i> Currently Active
                                </button>
                            <?php else: ?>
                                <button onclick="deployLab('<?= htmlspecialchars($service['name'], ENT_QUOTES) ?>')" 
                                    class="w-full py-2.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-850 text-zinc-300 hover:text-white text-xs font-bold rounded-xl transition-all duration-200 flex items-center justify-center gap-1.5 shadow-md shadow-black/10 group-hover:border-zinc-700">
                                    <i class="fa-solid fa-rocket text-brand group-hover:animate-pulse"></i> Deploy Environment
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="w-full py-6 text-center text-xs text-zinc-600 border-t border-zinc-900/60 max-w-7xl mx-auto">
        <p>© 2026 CloudLab. Built for final year B.E. Computer Science & Engineering Project.</p>
    </footer>

    <!-- Script handlers -->
    <script>
        // Save current dashboard URL with tab token to sessionStorage
        sessionStorage.setItem('dashboard_url', 'dashboard.php?tab_token=<?= urlencode(get_current_tab_token()) ?>');
        // Clean URL to hide the token from the browser address bar
        if (window.location.search.includes('tab_token')) {
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.history.replaceState({}, '', cleanUrl);
        }

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
            const btnLaunchN8n = document.getElementById('btn-launch-n8n');
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
                
                if (btnLaunchN8n) {
                    btnLaunchN8n.className = "mt-3 w-full py-4 bg-rose-600 hover:bg-rose-500 active:bg-rose-700 text-white rounded-2xl font-bold text-center shadow-lg shadow-rose-600/20 hover:shadow-rose-600/35 transition-all duration-300 flex items-center justify-center gap-2 transform hover:scale-[1.01]";
                    btnLaunchN8n.classList.remove('pointer-events-none');
                    btnLaunchN8n.href = "/n8n/<?= htmlspecialchars($username) ?>/";
                }

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

                if (btnLaunchN8n) {
                    btnLaunchN8n.className = "mt-3 w-full py-4 bg-zinc-900 border border-zinc-800/60 text-zinc-600 pointer-events-none rounded-2xl font-bold text-center flex items-center justify-center gap-2 select-none";
                    btnLaunchN8n.classList.add('pointer-events-none');
                    btnLaunchN8n.href = "#";
                }

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

                if (btnLaunchN8n) {
                    btnLaunchN8n.className = "mt-3 w-full py-4 bg-zinc-900 border border-zinc-800/60 text-zinc-600 pointer-events-none rounded-2xl font-bold text-center flex items-center justify-center gap-2 select-none";
                    btnLaunchN8n.classList.add('pointer-events-none');
                }

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

            fetch(`api/container.php?action=${action}&csrf_token=<?= csrf_token() ?>&tab_token=<?= urlencode(get_current_tab_token()) ?>`)
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

        function deployLab(labName) {
            let msg = `Are you sure you want to deploy ${labName}?`;
            if (currentStatus === 'running') {
                msg = `Switching to ${labName} will automatically stop and replace your current running lab. Your persistent home directory files will be saved. Do you want to proceed?`;
            }
            if (!confirm(msg)) return;

            updateUIState("starting...");
            window.scrollTo({ top: 0, behavior: 'smooth' });

            fetch(`api/container.php?action=deploy&lab_type=${encodeURIComponent(labName)}&csrf_token=<?= csrf_token() ?>&tab_token=<?= urlencode(get_current_tab_token()) ?>`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert(`Successfully deployed ${labName}! The workspace is booting up.`);
                        location.reload();
                    } else {
                        alert("Deployment failed: " + data.message);
                        location.reload();
                    }
                })
                .catch(err => {
                    console.error("Error deploying lab:", err);
                    alert("A network error occurred during deployment.");
                    location.reload();
                });
        }

        function configureMFA(action) {
            const form = document.getElementById(`form-mfa-${action}`);
            const formData = new FormData(form);
            
            fetch(`api/auth-mfa.php?action=${action}&tab_token=<?= urlencode(get_current_tab_token()) ?>`, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert("MFA configuration failed: " + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert("An error occurred during verification.");
            });
        }

        // Initialize state on page load
        document.addEventListener('DOMContentLoaded', () => {
            updateUIState(currentStatus);
        });
    </script>
</body>
</html>
