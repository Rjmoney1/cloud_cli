<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth_check.php';

// Verify Admin Privileges
check_admin();

$username = $_SESSION['admin_username'];
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Monitoring Dashboard - CloudLab</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    </style>
</head>
<body class="min-h-full font-sans text-zinc-100 gradient-bg flex flex-col justify-between">

    <!-- Top Navigation -->
    <nav class="border-b border-zinc-900 bg-zinc-950/40 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-9 w-9 rounded-lg bg-brand/10 border border-brand/40 flex items-center justify-center text-brand text-lg">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <span class="text-xl font-bold text-white">CloudLab <span class="text-xs uppercase px-2 py-0.5 rounded bg-brand/10 text-brand border border-brand/20 ml-2">Monitor</span></span>
            </div>
            
            <div class="flex items-center gap-6">
                <a href="admin.php" class="text-zinc-400 hover:text-white text-sm font-semibold transition-colors duration-200">
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Admin Control Panel
                </a>
                <div class="hidden md:block h-4 w-[1px] bg-zinc-800"></div>
                <a href="api/auth.php?action=logout&role=admin" class="py-2 px-4 bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-800 rounded-lg text-sm font-medium transition-all duration-200">
                    <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Dashboard container -->
    <main class="flex-grow max-w-7xl w-full mx-auto px-6 py-8 flex flex-col gap-8">
        
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 border-b border-zinc-900 pb-6">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-white mb-1">Live Resource Monitor</h1>
                <p class="text-zinc-400">Real-time statistics of the host machine and individual student containers via SSE streaming.</p>
            </div>
            <div>
                <a href="admin.php" 
                    class="py-3 px-5 bg-zinc-900 hover:bg-zinc-800 text-zinc-300 border border-zinc-800 font-bold rounded-xl transition-all duration-200 flex items-center gap-2">
                    <i class="fa-solid fa-screwdriver-wrench"></i> Back to Control Panel
                </a>
            </div>
        </div>

        <!-- Total Server Host Health Statistics -->
        <section class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-zinc-900/10 border border-zinc-900 rounded-3xl p-6 shadow-xl">
            
            <!-- Host CPU Card -->
            <div class="bg-zinc-950/40 border border-zinc-900 rounded-2xl p-5 flex items-center justify-between gap-6">
                <div class="flex items-center gap-4">
                    <div class="h-12 w-12 rounded-xl bg-brand/10 border border-brand/20 flex items-center justify-center text-brand text-xl">
                        <i class="fa-solid fa-microchip"></i>
                    </div>
                    <div>
                        <span class="text-xs uppercase tracking-wider text-zinc-500 font-bold">Total Server Host</span>
                        <h3 class="text-lg font-bold text-white">Host CPU Load</h3>
                    </div>
                </div>
                
                <div class="text-right">
                    <span id="host-cpu-text" class="text-4xl font-extrabold text-brand font-mono">0.0%</span>
                    <div class="w-24 bg-zinc-900 rounded-full h-1.5 mt-2">
                        <div id="host-cpu-bar" class="bg-brand h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                    </div>
                </div>
            </div>

            <!-- Host RAM Card -->
            <div class="bg-zinc-950/40 border border-zinc-900 rounded-2xl p-5 flex items-center justify-between gap-6">
                <div class="flex items-center gap-4">
                    <div class="h-12 w-12 rounded-xl bg-sky-500/10 border border-sky-500/20 flex items-center justify-center text-sky-400 text-xl">
                        <i class="fa-solid fa-memory"></i>
                    </div>
                    <div>
                        <span class="text-xs uppercase tracking-wider text-zinc-500 font-bold">Total Server Host</span>
                        <h3 class="text-lg font-bold text-white">Host Memory Usage</h3>
                    </div>
                </div>
                
                <div class="text-right">
                    <span id="host-ram-text" class="text-4xl font-extrabold text-sky-400 font-mono">0.0%</span>
                    <span id="host-ram-raw" class="block text-[10px] text-zinc-500 mt-0.5">0.0 GB / 0.0 GB</span>
                    <div class="w-24 bg-zinc-900 rounded-full h-1.5 mt-1">
                        <div id="host-ram-bar" class="bg-sky-400 h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                    </div>
                </div>
            </div>

        </section>

        <!-- Live Container Resource stats Title -->
        <div>
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
                <span class="flex h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse"></span>
                Active User Containers Stats (<span id="running-count-text">0</span> Running)
            </h2>
            <p class="text-sm text-zinc-500 mt-1">Containers will automatically appear below when started by students and disappear when stopped.</p>
        </div>

        <!-- Dynamic Container Cards Container Grid -->
        <section id="containers-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <!-- Dynamic Cards are injected here -->
        </section>

    </main>

    <!-- Footer -->
    <footer class="w-full py-6 text-center text-xs text-zinc-600 border-t border-zinc-900/60 max-w-7xl mx-auto">
        <p>© 2026 CloudLab. Built for final year B.E. Computer Science & Engineering Project.</p>
    </footer>

    <!-- Stats JS Stream logic -->
    <script>
        let hostEventSource = null;
        let dockerEventSource = null;
        
        // Track active container chart instances and data
        const containerCharts = {};

        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        // Initialize Host Stats SSE
        function initHostStats() {
            hostEventSource = new EventSource('api/server-stats.php');
            hostEventSource.onmessage = function(e) {
                const data = JSON.parse(e.data);
                
                // Update Host CPU
                document.getElementById('host-cpu-text').innerText = data.cpu + '%';
                document.getElementById('host-cpu-bar').style.width = data.cpu + '%';
                
                // Update Host RAM
                document.getElementById('host-ram-text').innerText = data.ram + '%';
                document.getElementById('host-ram-bar').style.width = data.ram + '%';
                
                const used = formatBytes(data.ram_used);
                const total = formatBytes(data.ram_total);
                document.getElementById('host-ram-raw').innerText = `${used} / ${total}`;
            };

            hostEventSource.onerror = function() {
                console.error("Host stats SSE error.");
            };
        }

        // Initialize Docker Containers Stats SSE
        function initDockerStats() {
            dockerEventSource = new EventSource('api/docker-stats.php');
            dockerEventSource.onmessage = function(e) {
                const data = JSON.parse(e.data);
                
                if (data.error) {
                    console.error("Docker stats error:", data.error);
                    return;
                }

                // Update running count
                document.getElementById('running-count-text').innerText = data.length;

                const grid = document.getElementById('containers-grid');
                const activeIds = data.map(item => item.id.toString());

                // 1. Remove stopped container cards from DOM and charts map
                Object.keys(containerCharts).forEach(userId => {
                    if (!activeIds.includes(userId)) {
                        // Destroy chart instance
                        if (containerCharts[userId].chart) {
                            containerCharts[userId].chart.destroy();
                        }
                        // Remove card node
                        const cardNode = document.getElementById(`container-card-${userId}`);
                        if (cardNode) {
                            grid.removeChild(cardNode);
                        }
                        delete containerCharts[userId];
                    }
                });

                // 2. Add or update container cards
                data.forEach(c => {
                    const userId = c.id.toString();
                    
                    if (!containerCharts[userId]) {
                        // Create and inject container DOM card
                        createContainerCard(grid, c);
                    } else {
                        // Update values & graphs
                        updateContainerCard(c);
                    }
                });
            };

            dockerEventSource.onerror = function() {
                console.error("Docker stats SSE error.");
            };
        }

        function createContainerCard(grid, c) {
            const userId = c.id.toString();
            const card = document.createElement('div');
            card.id = `container-card-${userId}`;
            card.className = "bg-zinc-900/40 border border-zinc-900 rounded-3xl p-6 shadow-lg flex flex-col justify-between gap-5 transition";

            card.innerHTML = `
                <div class="flex justify-between items-start border-b border-zinc-900 pb-3">
                    <div>
                        <h4 class="font-bold text-white text-lg flex items-center gap-2">
                            <i class="fa-solid fa-desktop text-brand text-sm"></i> lab-${c.username}
                        </h4>
                        <span class="text-[10px] uppercase font-mono tracking-wide text-zinc-500">${c.lab_type}</span>
                    </div>
                    <span class="px-2 py-0.5 bg-green-950 border border-green-500/20 text-green-400 rounded-full text-[10px] font-semibold flex items-center gap-1">
                        <span class="h-1 w-1 bg-green-400 rounded-full animate-ping"></span> Active
                    </span>
                </div>

                <!-- IO Info -->
                <div class="grid grid-cols-2 gap-4 text-xs">
                    <div class="bg-zinc-950/30 border border-zinc-900 rounded-xl p-2.5">
                        <span class="text-zinc-500 font-medium block mb-0.5">Network Load</span>
                        <div class="flex flex-col font-mono text-[10px]">
                            <span class="text-zinc-300"><i class="fa-solid fa-arrow-down text-green-500 mr-1"></i> In: <span id="net-rx-${userId}">0 B</span></span>
                            <span class="text-zinc-300"><i class="fa-solid fa-arrow-up text-orange-500 mr-1"></i> Out: <span id="net-tx-${userId}">0 B</span></span>
                        </div>
                    </div>
                    <div class="bg-zinc-950/30 border border-zinc-900 rounded-xl p-2.5">
                        <span class="text-zinc-500 font-medium block mb-0.5">Disk I/O</span>
                        <div class="flex flex-col font-mono text-[10px]">
                            <span class="text-zinc-300"><i class="fa-solid fa-circle-down text-sky-400 mr-1"></i> Read: <span id="disk-r-${userId}">0 B</span></span>
                            <span class="text-zinc-300"><i class="fa-solid fa-circle-up text-amber-500 mr-1"></i> Write: <span id="disk-w-${userId}">0 B</span></span>
                        </div>
                    </div>
                </div>

                <!-- Live Chart canvas -->
                <div class="h-44 w-full relative">
                    <canvas id="chart-canvas-${userId}"></canvas>
                </div>

                <!-- Footer Summary info -->
                <div class="flex justify-between items-center text-xs border-t border-zinc-900 pt-3">
                    <span class="text-zinc-500">Live stats feedback loop</span>
                    <span class="text-zinc-400 font-mono text-[10px] bg-zinc-900/60 border border-zinc-800 rounded px-1.5 py-0.5">
                        RAM: <span id="ram-raw-${userId}">0 MB</span>
                    </span>
                </div>
            `;

            grid.appendChild(card);

            // Setup Chart.js context
            const ctx = document.getElementById(`chart-canvas-${userId}`).getContext('2d');
            
            // Build the chart configuration
            const chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: Array(10).fill(''), // 10 empty ticks
                    datasets: [
                        {
                            label: 'CPU (%)',
                            borderColor: '#F5B027', // Theme primary
                            borderWidth: 2,
                            pointRadius: 0,
                            fill: true,
                            backgroundColor: 'rgba(245, 176, 39, 0.05)',
                            data: Array(10).fill(0),
                            tension: 0.3
                        },
                        {
                            label: 'RAM (%)',
                            borderColor: '#38bdf8', // Sky blue
                            borderWidth: 2,
                            pointRadius: 0,
                            fill: true,
                            backgroundColor: 'rgba(56, 189, 248, 0.05)',
                            data: Array(10).fill(0),
                            tension: 0.3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            labels: {
                                color: '#a1a1aa',
                                boxWidth: 12,
                                font: { size: 9 }
                            }
                        }
                    },
                    scales: {
                        y: {
                            min: 0,
                            max: 100,
                            grid: { color: '#18181b' },
                            ticks: { color: '#71717a', font: { size: 8 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { display: false }
                        }
                    },
                    animation: { duration: 300 }
                }
            });

            // Store references in map
            containerCharts[userId] = {
                chart: chart,
                cpuData: Array(10).fill(0),
                ramData: Array(10).fill(0)
            };

            // Call initial update to populate raw fields
            updateContainerCard(c);
        }

        function updateContainerCard(c) {
            const userId = c.id.toString();
            const record = containerCharts[userId];
            if (!record) return;

            // 1. Update text nodes
            document.getElementById(`net-rx-${userId}`).innerText = formatBytes(c.net_rx);
            document.getElementById(`net-tx-${userId}`).innerText = formatBytes(c.net_tx);
            document.getElementById(`disk-r-${userId}`).innerText = formatBytes(c.disk_read);
            document.getElementById(`disk-w-${userId}`).innerText = formatBytes(c.disk_write);
            
            const rawMem = formatBytes(c.memory_raw);
            const limitMem = formatBytes(c.memory_limit);
            document.getElementById(`ram-raw-${userId}`).innerText = `${rawMem} / ${limitMem}`;

            // 2. Push new values to datasets array, popping the oldest
            record.cpuData.shift();
            record.cpuData.push(c.cpu);
            record.ramData.shift();
            record.ramData.push(c.memory);

            // 3. Update Chart datasets data
            record.chart.data.datasets[0].data = record.cpuData;
            record.chart.data.datasets[1].data = record.ramData;
            
            // 4. Redraw chart
            record.chart.update();
        }

        document.addEventListener('DOMContentLoaded', () => {
            initHostStats();
            initDockerStats();
        });

        // Cleanup EventSources on unload
        window.addEventListener('beforeunload', () => {
            if (hostEventSource) hostEventSource.close();
            if (dockerEventSource) dockerEventSource.close();
        });
    </script>
</body>
</html>
