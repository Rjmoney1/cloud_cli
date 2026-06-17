<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/DockerClient.php';

// Verify Admin Privileges
check_admin();

$username = $_SESSION['admin_username'];
$docker = new DockerClient();

// Fetch all standard users
$usersStmt = $pdo->query("SELECT id, username, email, lab_type, container_id, container_status, cpu_limit, memory_limit, gpu_limit, created_at FROM users WHERE role = 'user' ORDER BY id DESC");
$users = $usersStmt->fetchAll();

// Validate and sync statuses in real-time
foreach ($users as &$user) {
    if ($user['container_status'] === 'running' && !empty($user['container_id'])) {
        $inspect = $docker->inspectContainer($user['container_id']);
        if ($inspect === null || !isset($inspect['State']['Running']) || !$inspect['State']['Running']) {
            $user['container_status'] = 'stopped';
            $updateStmt = $pdo->prepare("UPDATE users SET container_status = 'stopped' WHERE id = ?");
            $updateStmt->execute([$user['id']]);
        }
    }
}
unset($user); // Break reference

// Fetch all uploaded ISO files
$isosStmt = $pdo->query("SELECT * FROM isos ORDER BY id DESC");
$isos = $isosStmt->fetchAll();

// Fetch all registered services
$servicesStmt = $pdo->query("SELECT * FROM services ORDER BY name ASC");
$services = $servicesStmt->fetchAll();

// Fetch all active ISO mounts
$mountsStmt = $pdo->query("SELECT m.id as mount_id, m.mount_path, m.mounted_at, u.id as user_id, u.username, i.filename FROM mounts m JOIN users u ON m.user_id = u.id JOIN isos i ON m.iso_id = i.id ORDER BY m.id DESC");
$mounts = $mountsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - CloudLab</title>
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
            background: radial-gradient(circle at top right, rgba(245, 176, 39, 0.08) 0%, transparent 60%),
                        radial-gradient(circle at bottom left, rgba(245, 176, 39, 0.03) 0%, transparent 50%);
        }
        .terminal-bg {
            background-color: #050506;
        }
    </style>
</head>
<body class="min-h-full font-sans text-zinc-100 gradient-bg flex flex-col justify-between">

    <!-- Top Navigation -->
    <nav class="border-b border-zinc-900 bg-zinc-950/40 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-9 w-9 rounded-lg bg-brand/10 border border-brand/40 flex items-center justify-center text-brand text-lg">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                </div>
                <span class="text-xl font-bold text-white">CloudLab <span class="text-xs uppercase px-2 py-0.5 rounded bg-brand/10 text-brand border border-brand/20 ml-2">Admin</span></span>
            </div>
            
            <div class="flex items-center gap-6">
                <a href="admin-stats.php" class="text-zinc-400 hover:text-white text-sm font-semibold transition-colors duration-200">
                    <i class="fa-solid fa-chart-line mr-1.5"></i> Live Monitoring
                </a>
                <div class="hidden md:block h-4 w-[1px] bg-zinc-800"></div>
                <div class="hidden sm:flex items-center gap-2 text-sm text-zinc-400">
                    Logged in as: <span class="font-semibold text-zinc-200"><?= htmlspecialchars($username) ?></span>
                </div>
                <a href="api/auth.php?action=logout&role=admin" class="py-2 px-4 bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-800 rounded-lg text-sm font-medium transition-all duration-200">
                    <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Admin Container -->
    <main class="flex-grow max-w-7xl w-full mx-auto px-6 py-8 flex flex-col gap-10">
        
        <!-- Welcome banner -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 border-b border-zinc-900 pb-6">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-white mb-1">Administrative Control Center</h1>
                <p class="text-zinc-400">Manage user lab containers, run installations, mount media, and monitor execution logs.</p>
            </div>
            <div>
                <a href="admin-stats.php" 
                    class="py-3 px-5 bg-brand hover:bg-brand-600 text-zinc-950 font-bold rounded-xl shadow-lg shadow-brand/10 hover:shadow-brand/25 transition-all duration-200 flex items-center gap-2">
                    <i class="fa-solid fa-gauge-high"></i> View Live Stats Dashboard
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-950/40 border border-red-500/30 text-red-400 rounded-xl p-4 text-sm flex gap-3">
                <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                <p><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-950/40 border border-green-500/30 text-green-400 rounded-xl p-4 text-sm flex gap-3">
                <i class="fa-solid fa-circle-check mt-0.5"></i>
                <p><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></p>
            </div>
        <?php endif; ?>

        <!-- Active Users & Container Management Table -->
        <section class="bg-zinc-900/20 border border-zinc-900 rounded-3xl p-6 shadow-xl">
            <h2 class="text-xl font-bold text-white mb-4"><i class="fa-solid fa-users text-brand mr-2"></i> Registered Students & Labs</h2>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-400 border-collapse">
                    <thead>
                        <tr class="border-b border-zinc-800 text-zinc-300 font-semibold bg-zinc-950/20">
                            <th class="py-3 px-4">Student</th>
                            <th class="py-3 px-4">Lab Environment</th>
                            <th class="py-3 px-4">Resource Limits</th>
                            <th class="py-3 px-4">Container Name</th>
                            <th class="py-3 px-4">Docker Status</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="py-8 text-center text-zinc-500">No registered students found in the database.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr class="border-b border-zinc-900 hover:bg-zinc-900/10 transition-colors">
                                    <td class="py-4 px-4 font-semibold text-white">
                                        <?= htmlspecialchars($user['username']) ?>
                                        <span class="block text-xs text-zinc-500 font-normal"><?= htmlspecialchars($user['email']) ?></span>
                                    </td>
                                    <td class="py-4 px-4">
                                        <span class="font-semibold text-zinc-300"><?= htmlspecialchars($user['lab_type']) ?></span>
                                    </td>
                                    <td class="py-4 px-4">
                                        <span class="block text-xs text-zinc-300">CPU: <strong class="text-white"><?= floatval($user['cpu_limit'] ?? 1.0) ?> Cores</strong></span>
                                        <span class="block text-xs text-zinc-300">RAM: <strong class="text-white"><?= intval($user['memory_limit'] ?? 1024) ?> MB</strong></span>
                                        <span class="block text-xs text-zinc-300">GPU: <strong class="text-white"><?= intval($user['gpu_limit'] ?? 0) === -1 ? 'All' : intval($user['gpu_limit'] ?? 0) ?></strong></span>
                                    </td>
                                    <td class="py-4 px-4 font-mono text-xs text-zinc-500">
                                        <?= !empty($user['container_id']) ? 'lab-' . htmlspecialchars($user['username']) : '-' ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <div id="status-badge-<?= $user['id'] ?>" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border <?= $user['container_status'] === 'running' ? 'bg-green-950/30 border-green-500/20 text-green-400' : 'bg-zinc-900 border-zinc-800 text-zinc-500' ?>">
                                            <span class="h-1.5 w-1.5 rounded-full <?= $user['container_status'] === 'running' ? 'bg-green-500 animate-pulse' : 'bg-zinc-600' ?>"></span>
                                            <?= htmlspecialchars($user['container_status']) ?>
                                        </div>
                                    </td>
                                    <td class="py-4 px-4 text-right">
                                        <div class="flex justify-end gap-2">
                                            <button onclick="openLimitsModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>', <?= floatval($user['cpu_limit'] ?? 1.0) ?>, <?= intval($user['memory_limit'] ?? 1024) ?>, <?= intval($user['gpu_limit'] ?? 0) ?>, '<?= htmlspecialchars($user['lab_type']) ?>')"
                                                class="px-2.5 py-1.5 bg-zinc-905 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white text-xs font-semibold rounded-lg transition" title="Set Resource Limits">
                                                <i class="fa-solid fa-sliders mr-1 text-brand"></i> Limits & Env
                                            </button>
                                            <button onclick="controlUserContainer(<?= $user['id'] ?>, 'start')"
                                                class="px-3 py-1.5 bg-green-950 hover:bg-green-900 border border-green-500/20 text-green-400 text-xs font-semibold rounded-lg transition">
                                                Start
                                            </button>
                                            <button onclick="controlUserContainer(<?= $user['id'] ?>, 'stop')"
                                                class="px-3 py-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 text-xs font-semibold rounded-lg transition">
                                                Stop
                                            </button>
                                            <button onclick="controlUserContainer(<?= $user['id'] ?>, 'restart')"
                                                class="px-3 py-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 text-xs font-semibold rounded-lg transition">
                                                Restart
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Command Execution & Software Installation Console -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            
            <!-- Software Installer Card -->
            <section class="bg-zinc-900/20 border border-zinc-900 rounded-3xl p-6 shadow-xl flex flex-col justify-between gap-6">
                <div>
                    <h2 class="text-xl font-bold text-white mb-2"><i class="fa-solid fa-cube text-brand mr-2"></i> Remote Software Installer</h2>
                    <p class="text-sm text-zinc-400 mb-6">Install packages remotely (using <code class="font-mono text-zinc-300">apt-get install</code>) into running user containers.</p>
                    
                    <form id="form-install" onsubmit="executeAdminAction(event, 'install_software')" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <div>
                            <label for="install_user" class="block text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">Target Student Container</label>
                            <select id="install_user" name="user_id" required class="w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white focus:outline-none focus:border-brand transition">
                                <option value="" disabled selected>-- Select Student --</option>
                                <?php foreach ($users as $user): ?>
                                    <?php if ($user['container_status'] === 'running'): ?>
                                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['lab_type']) ?>)</option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="install_package" class="block text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">Apt Package Name</label>
                            <input type="text" id="install_package" name="package" required placeholder="e.g. htop python3-pip zip"
                                class="w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white focus:outline-none focus:border-brand transition">
                        </div>
                        
                        <button type="submit" id="btn-install"
                            class="w-full py-3 px-4 bg-brand hover:bg-brand-600 text-zinc-950 font-bold rounded-xl transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-download"></i> Remote Install Package
                        </button>
                    </form>
                </div>
            </section>

            <!-- Script Runner Card -->
            <section class="bg-zinc-900/20 border border-zinc-900 rounded-3xl p-6 shadow-xl flex flex-col justify-between gap-6">
                <div>
                    <h2 class="text-xl font-bold text-white mb-2"><i class="fa-solid fa-code text-brand mr-2"></i> Exec Script Console</h2>
                    <p class="text-sm text-zinc-400 mb-6">Run custom Bash/Shell scripts directly inside a student's active container.</p>
                    
                    <form id="form-script" onsubmit="executeAdminAction(event, 'run_script')" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <div>
                            <label for="script_user" class="block text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">Target Student Container</label>
                            <select id="script_user" name="user_id" required class="w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white focus:outline-none focus:border-brand transition">
                                <option value="" disabled selected>-- Select Student --</option>
                                <?php foreach ($users as $user): ?>
                                    <?php if ($user['container_status'] === 'running'): ?>
                                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="script_content" class="block text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">Bash Script</label>
                            <textarea id="script_content" name="script" rows="4" required placeholder="#!/bin/bash&#10;echo 'Current user:' $(whoami)&#10;df -h"
                                class="w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white font-mono text-xs focus:outline-none focus:border-brand transition resize-none"></textarea>
                        </div>
                        
                        <button type="submit" id="btn-script"
                            class="w-full py-3 px-4 bg-brand hover:bg-brand-600 text-zinc-950 font-bold rounded-xl transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-terminal"></i> Execute Shell Script
                        </button>
                    </form>
                </div>
            </section>
        </div>

        <!-- Terminal Output Window (used for scripts/installations output) -->
        <section id="section-terminal-out" class="hidden bg-zinc-950 border border-zinc-900 rounded-3xl p-6 shadow-xl">
            <div class="flex justify-between items-center mb-4">
                <span class="text-sm font-semibold text-zinc-300"><i class="fa-solid fa-desktop text-brand mr-2"></i> Execution Output Console</span>
                <button onclick="document.getElementById('section-terminal-out').classList.add('hidden')" class="text-zinc-500 hover:text-white transition">
                    <i class="fa-solid fa-xmark"></i> Close Console
                </button>
            </div>
            <pre id="terminal-pre" class="terminal-bg border border-zinc-900 rounded-2xl p-5 font-mono text-xs text-green-400 overflow-y-auto max-h-80 select-all"></pre>
        </section>

        <!-- ISO uploads & Mounting Panel -->
        <section class="bg-zinc-900/20 border border-zinc-900 rounded-3xl p-6 shadow-xl">
            <h2 class="text-xl font-bold text-white mb-4"><i class="fa-solid fa-compact-disc text-brand mr-2"></i> ISO Storage & Media Mounting</h2>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- ISO Upload Card -->
                <div class="lg:col-span-1 bg-zinc-950/30 border border-zinc-900 rounded-2xl p-5 space-y-4">
                    <h3 class="font-bold text-white text-sm uppercase tracking-wider text-brand">Upload ISO Image</h3>
                    
                    <form action="api/admin-action.php?action=upload_iso" method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <div class="border border-dashed border-zinc-800 hover:border-brand/40 rounded-xl p-4 text-center cursor-pointer transition flex flex-col items-center justify-center bg-zinc-950/20">
                            <i class="fa-solid fa-cloud-arrow-up text-zinc-600 text-3xl mb-2"></i>
                            <input type="file" name="iso_file" required accept=".iso" class="text-xs text-zinc-400 w-full">
                        </div>
                        <button type="submit" class="w-full py-2.5 px-4 bg-zinc-900 hover:bg-zinc-800 text-zinc-200 border border-zinc-800 font-semibold rounded-lg text-sm transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-upload"></i> Upload to Server
                        </button>
                    </form>
                </div>

                <!-- ISO Mounter Card -->
                <div class="lg:col-span-1 bg-zinc-950/30 border border-zinc-900 rounded-2xl p-5 space-y-4">
                    <h3 class="font-bold text-white text-sm uppercase tracking-wider text-brand">Mount ISO to Container</h3>
                    
                    <form id="form-mount" onsubmit="executeAdminAction(event, 'mount_iso')" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <div>
                            <label class="block text-xs text-zinc-400 font-semibold mb-1">Target Student</label>
                            <select name="user_id" required class="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-sm text-white focus:outline-none focus:border-brand transition">
                                <option value="" disabled selected>-- Select --</option>
                                <?php foreach ($users as $user): ?>
                                    <?php if ($user['container_status'] === 'running'): ?>
                                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-zinc-400 font-semibold mb-1">Select ISO File</label>
                                <select name="iso_id" required class="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-sm text-white focus:outline-none focus:border-brand transition">
                                    <option value="" disabled selected>-- Select --</option>
                                    <?php foreach ($isos as $iso): ?>
                                        <option value="<?= $iso['id'] ?>"><?= htmlspecialchars($iso['filename']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 font-semibold mb-1">Mount Path</label>
                                <input type="text" name="mount_path" value="/mnt/iso" required class="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-sm text-white focus:outline-none focus:border-brand transition">
                            </div>
                        </div>
                        <button type="submit" class="w-full py-2.5 px-4 bg-brand hover:bg-brand-600 text-zinc-950 font-bold rounded-lg text-sm transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-link"></i> Mount Loop Device
                        </button>
                    </form>
                </div>

                <!-- Active Mounts List -->
                <div class="lg:col-span-1 bg-zinc-950/30 border border-zinc-900 rounded-2xl p-5 space-y-4">
                    <h3 class="font-bold text-white text-sm uppercase tracking-wider text-brand">Active ISO Mounts</h3>
                    
                    <div class="overflow-y-auto max-h-[220px] pr-2 space-y-3">
                        <?php if (empty($mounts)): ?>
                            <p class="text-xs text-zinc-500 text-center py-8">No active media mounts registered.</p>
                        <?php else: ?>
                            <?php foreach ($mounts as $mount): ?>
                                <div class="bg-zinc-900/60 border border-zinc-800/60 rounded-xl p-3 text-xs flex justify-between items-center gap-3">
                                    <div class="space-y-1">
                                        <span class="font-semibold text-zinc-200"><?= htmlspecialchars($mount['filename']) ?></span>
                                        <p class="text-zinc-500 font-mono">Mounted on <span class="text-zinc-400"><?= htmlspecialchars($mount['username']) ?>:<?= htmlspecialchars($mount['mount_path']) ?></span></p>
                                    </div>
                                    <button onclick="unmountIso(<?= $mount['mount_id'] ?>)" class="p-2 bg-red-950/20 text-red-400 hover:bg-red-950/50 border border-red-500/20 rounded-lg transition" title="Unmount">
                                        <i class="fa-solid fa-unlink"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </section>

        <!-- Custom Services Management Panel -->
        <section class="bg-zinc-900/20 border border-zinc-900 rounded-3xl p-6 shadow-xl">
            <h2 class="text-xl font-bold text-white mb-4"><i class="fa-solid fa-server text-brand mr-2"></i> Custom Services Management</h2>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Add Custom Service Card -->
                <div class="lg:col-span-1 bg-zinc-950/30 border border-zinc-900 rounded-2xl p-5 space-y-4">
                    <h3 class="font-bold text-white text-sm uppercase tracking-wider text-brand">Register New Service</h3>
                    
                    <form id="form-add-service" onsubmit="executeAdminAction(event, 'add_service')" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <div>
                            <label class="block text-xs text-zinc-400 font-semibold mb-1">Service Display Name</label>
                            <input type="text" name="name" required placeholder="e.g. Python 3.10 Web Server" 
                                class="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-sm text-white focus:outline-none focus:border-brand transition">
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-400 font-semibold mb-1">Docker Image Name</label>
                            <input type="text" name="image_name" required placeholder="e.g. python:3.10-slim" 
                                class="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-sm text-white focus:outline-none focus:border-brand transition">
                            <span class="text-[10px] text-zinc-500 mt-1 block">Image will be pulled automatically from Docker Hub.</span>
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-400 font-semibold mb-1">Description</label>
                            <textarea name="description" rows="2" placeholder="e.g. Isolated Python 3.10 development lab" 
                                class="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-sm text-white focus:outline-none focus:border-brand transition resize-none"></textarea>
                        </div>
                        <button type="submit" class="w-full py-2.5 px-4 bg-brand hover:bg-brand-600 text-zinc-950 font-bold rounded-lg text-sm transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-plus-circle"></i> Register Service
                        </button>
                    </form>
                </div>

                <!-- Registered Services List (takes 2 columns) -->
                <div class="lg:col-span-2 bg-zinc-950/30 border border-zinc-900 rounded-2xl p-5 space-y-4">
                    <h3 class="font-bold text-white text-sm uppercase tracking-wider text-brand">Registered Lab Services</h3>
                    
                    <div class="overflow-y-auto max-h-[350px] pr-2 space-y-3">
                        <?php if (empty($services)): ?>
                            <p class="text-xs text-zinc-500 text-center py-8">No custom services registered.</p>
                        <?php else: ?>
                            <?php foreach ($services as $service): ?>
                                <div class="bg-zinc-900/40 border border-zinc-800/80 rounded-xl p-4 flex justify-between items-start gap-4 hover:border-zinc-700/60 transition">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-zinc-100 text-sm"><?= htmlspecialchars($service['name']) ?></span>
                                            <code class="text-[10px] px-1.5 py-0.5 rounded bg-brand/10 border border-brand/20 text-brand font-mono"><?= htmlspecialchars($service['image_name']) ?></code>
                                        </div>
                                        <p class="text-xs text-zinc-400 leading-normal"><?= htmlspecialchars($service['description'] ?: 'No description provided.') ?></p>
                                    </div>
                                    <button onclick="deleteService(<?= $service['id'] ?>)" class="p-2 bg-red-950/20 text-red-400 hover:bg-red-950/50 border border-red-500/20 rounded-lg transition" title="Delete Service">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Real-time Container Log Streaming terminal -->
        <section class="bg-zinc-900/20 border border-zinc-900 rounded-3xl p-6 shadow-xl">
            <h2 class="text-xl font-bold text-white mb-2"><i class="fa-solid fa-file-invoice text-brand mr-2"></i> Real-Time Terminal Log Viewer</h2>
            <p class="text-sm text-zinc-400 mb-6">Select an active container to stream its standard logs in real-time via Server-Sent Events (SSE).</p>
            
            <div class="flex flex-wrap items-center gap-4 mb-4">
                <select id="log-select-user" class="px-4 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-sm text-white focus:outline-none focus:border-brand transition">
                    <option value="" disabled selected>-- Select Running Lab --</option>
                    <?php foreach ($users as $user): ?>
                        <?php if ($user['container_status'] === 'running'): ?>
                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['lab_type']) ?>)</option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                
                <button onclick="toggleLogStream()" id="btn-log-stream" class="py-2 px-5 bg-brand hover:bg-brand-600 text-zinc-950 font-bold rounded-lg text-sm transition">
                    <i class="fa-solid fa-satellite-dish mr-1"></i> Stream Logs
                </button>
                <button onclick="clearTerminalLogs()" class="py-2 px-4 bg-zinc-900 hover:bg-zinc-800 text-zinc-300 border border-zinc-800 rounded-lg text-sm transition">
                    Clear Terminal
                </button>
            </div>

            <!-- Log Terminal Emulator -->
            <div class="terminal-bg border border-zinc-900 rounded-2xl p-5 font-mono text-xs text-zinc-400 h-80 overflow-y-auto flex flex-col gap-1" id="log-terminal-output">
                <span class="text-zinc-600 font-semibold select-none">[SYSTEM] Select a container and click "Stream Logs" to watch real-time output.</span>
            </div>
        </section>

    </main>

    <!-- Footer -->
    <footer class="w-full py-6 text-center text-xs text-zinc-600 border-t border-zinc-900/60 max-w-7xl mx-auto">
        <p>© 2026 CloudLab. Built for final year B.E. Computer Science & Engineering Project.</p>
    </footer>

    <!-- Admin JS controllers -->
    <script>
        let logEventSource = null;
        let activeLogUserId = null;

        function controlUserContainer(userId, action) {
            const badge = document.getElementById(`status-badge-${userId}`);
            badge.className = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border bg-amber-950/20 border-brand/20 text-brand";
            badge.innerHTML = `<span class="h-1.5 w-1.5 rounded-full bg-brand animate-ping"></span> ${action === 'start' ? 'Starting...' : action === 'stop' ? 'Stopping...' : 'Restarting...'}`;

            fetch(`api/container.php?action=${action}&user_id=${userId}&csrf_token=<?= csrf_token() ?>`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert("Control action failed: " + data.message);
                        location.reload();
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("A communication error occurred.");
                    location.reload();
                });
        }

        function executeAdminAction(event, action) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            
            const btn = form.querySelector('button[type="submit"]');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner animate-spin"></i> Processing...`;

            fetch(`api/admin-action.php?action=${action}`, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                if (data.success) {
                    if (action === 'mount_iso') {
                        alert(data.message);
                        location.reload();
                    } else {
                        // Display execution logs output
                        const terminalOut = document.getElementById('section-terminal-out');
                        const terminalPre = document.getElementById('terminal-pre');
                        terminalOut.classList.remove('hidden');
                        terminalPre.textContent = data.output || "Command completed successfully with no output.";
                        terminalOut.scrollIntoView({ behavior: 'smooth' });
                    }
                } else {
                    alert("Action failed: " + data.message);
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                console.error(err);
                alert("A server error occurred while executing the command.");
            });
        }

        function unmountIso(mountId) {
            if (!confirm("Are you sure you want to unmount this ISO file?")) return;

            fetch(`api/admin-action.php?action=unmount_iso`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `mount_id=${mountId}&csrf_token=<?= csrf_token() ?>`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert("ISO unmounted successfully.");
                    location.reload();
                } else {
                    alert("Unmount failed: " + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert("Network error occurred during unmounting.");
            });
        }

        function toggleLogStream() {
            const select = document.getElementById('log-select-user');
            const btn = document.getElementById('btn-log-stream');
            const terminal = document.getElementById('log-terminal-output');

            if (logEventSource) {
                // Stop active streaming
                logEventSource.close();
                logEventSource = null;
                activeLogUserId = null;
                btn.className = "py-2 px-5 bg-brand hover:bg-brand-600 text-zinc-950 font-bold rounded-lg text-sm transition";
                btn.innerHTML = `<i class="fa-solid fa-satellite-dish mr-1"></i> Stream Logs`;
                
                const sysSpan = document.createElement('span');
                sysSpan.className = "text-zinc-500 font-semibold select-none mt-2";
                sysSpan.innerText = "[SYSTEM] Log streaming stopped.";
                terminal.appendChild(sysSpan);
                terminal.scrollTop = terminal.scrollHeight;
                return;
            }

            const userId = select.value;
            if (!userId) {
                alert("Please select a running container first.");
                return;
            }

            terminal.innerHTML = `<span class="text-zinc-500 font-semibold select-none">[SYSTEM] Connecting to container log stream for user ID: ${userId}...</span>`;
            
            btn.className = "py-2 px-5 bg-red-950 border border-red-500/30 text-red-400 hover:bg-red-900 font-bold rounded-lg text-sm transition animate-pulse";
            btn.innerHTML = `<i class="fa-solid fa-circle-stop mr-1"></i> Stop Streaming`;

            activeLogUserId = userId;
            logEventSource = new EventSource(`api/docker-logs.php?user_id=${userId}`);

            logEventSource.onmessage = function(event) {
                const data = JSON.parse(event.data);
                if (data.error) {
                    const errorSpan = document.createElement('span');
                    errorSpan.className = "text-red-400 font-semibold";
                    errorSpan.innerText = `[DOCKER ERROR] ${data.error}`;
                    terminal.appendChild(errorSpan);
                    toggleLogStream(); // Stop the loop
                } else if (data.log) {
                    const logSpan = document.createElement('span');
                    // Break lines in chunk and append
                    logSpan.innerHTML = data.log.replace(/\n/g, '<br>');
                    terminal.appendChild(logSpan);
                }
                terminal.scrollTop = terminal.scrollHeight;
            };

            logEventSource.onerror = function(err) {
                console.error("EventSource failed:", err);
                const errorSpan = document.createElement('span');
                errorSpan.className = "text-zinc-600 font-semibold mt-2";
                errorSpan.innerText = "[SYSTEM] Connection lost. Reconnecting...";
                terminal.appendChild(errorSpan);
                terminal.scrollTop = terminal.scrollHeight;
            };
        }

        function clearTerminalLogs() {
            document.getElementById('log-terminal-output').innerHTML = '<span class="text-zinc-600 font-semibold select-none">[SYSTEM] Console cleared.</span>';
        }

        function openLimitsModal(userId, username, cpu, memory, gpu, labType) {
            document.getElementById('limits-user-id').value = userId;
            document.getElementById('limits-username').value = username;
            document.getElementById('limits-cpu').value = cpu;
            document.getElementById('limits-memory').value = memory;
            document.getElementById('limits-gpu').value = gpu;
            document.getElementById('limits-lab-type').value = labType;
            
            const modal = document.getElementById('limits-modal');
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeLimitsModal() {
            const modal = document.getElementById('limits-modal');
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        function submitLimitsForm(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            
            const btn = document.getElementById('btn-save-limits');
            const originalText = btn.innerText;
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner animate-spin"></i> Saving...`;

            fetch('api/admin-action.php?action=update_limits', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerText = originalText;
                closeLimitsModal();
                
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert("Failed to update limits: " + data.message);
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerText = originalText;
                console.error(err);
                alert("A server error occurred while updating resource limits.");
            });
        }

        function deleteService(serviceId) {
            if (!confirm("Are you sure you want to delete this service? Students currently assigned to this environment will fail to launch containers until they are assigned a valid environment.")) return;

            fetch('api/admin-action.php?action=delete_service', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `service_id=${serviceId}&csrf_token=<?= csrf_token() ?>`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert("Delete failed: " + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert("Network error occurred.");
            });
        }
    </script>

    <!-- Resource Limits Modal -->
    <div id="limits-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-zinc-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-6">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-4">
                <h3 class="text-lg font-bold text-white"><i class="fa-solid fa-sliders text-brand mr-2"></i> Configure Resource Limits</h3>
                <button onclick="closeLimitsModal()" class="text-zinc-400 hover:text-white transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <form id="form-limits" onsubmit="submitLimitsForm(event)" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" id="limits-user-id" name="user_id">
                
                <div>
                    <label class="block text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">Student</label>
                    <input type="text" id="limits-username" readonly class="w-full px-4 py-3 bg-zinc-950 border border-zinc-950 rounded-xl text-zinc-500 font-medium select-none focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="limits-cpu" class="block text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">CPU Cores</label>
                        <input type="number" id="limits-cpu" name="cpu_limit" step="0.1" min="0.1" required class="w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white focus:outline-none focus:border-brand transition">
                        <span class="text-[10px] text-zinc-500 mt-1 block">e.g. 1.0, 2.5</span>
                    </div>
                    <div>
                        <label for="limits-memory" class="block text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">RAM (MB)</label>
                        <input type="number" id="limits-memory" name="memory_limit" min="128" required class="w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white focus:outline-none focus:border-brand transition">
                        <span class="text-[10px] text-zinc-500 mt-1 block">e.g. 512, 1024, 2048</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="limits-gpu" class="block text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">GPU Allocation</label>
                        <select id="limits-gpu" name="gpu_limit" class="w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white focus:outline-none focus:border-brand transition">
                            <option value="0">No GPUs (Default)</option>
                            <option value="1">1 GPU</option>
                            <option value="2">2 GPUs</option>
                            <option value="-1">All GPUs</option>
                        </select>
                        <span class="text-[10px] text-zinc-500 mt-1 block">Requires Host GPU Toolkit support.</span>
                    </div>

                    <div>
                        <label for="limits-lab-type" class="block text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">Lab Environment</label>
                        <select id="limits-lab-type" name="lab_type" class="w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white focus:outline-none focus:border-brand transition">
                            <?php foreach ($services as $service): ?>
                                <option value="<?= htmlspecialchars($service['name']) ?>"><?= htmlspecialchars($service['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-[10px] text-zinc-500 mt-1 block">Select lab workspace.</span>
                    </div>
                </div>

                <div class="bg-amber-950/20 border border-brand/20 rounded-xl p-3.5 text-xs text-brand flex gap-2.5 items-start">
                    <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                    <p>Changing resource limits or environment will automatically stop and recreate the student's container (data inside the home directory is preserved).</p>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeLimitsModal()" class="flex-1 py-3 px-4 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 font-bold rounded-xl transition">
                        Cancel
                    </button>
                    <button type="submit" id="btn-save-limits" class="flex-1 py-3 px-4 bg-brand hover:bg-brand-600 text-zinc-950 font-bold rounded-xl transition">
                        Save Limits
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
