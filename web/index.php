<?php
session_start();
if (isset($_SESSION['admin_user_id']) && isset($_SESSION['student_user_id'])) {
    header("Location: admin.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudLab - Linux Lab Platform</title>
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
    </style>
</head>
<body class="h-full font-sans text-zinc-100 gradient-bg flex flex-col justify-between">

    <!-- Top header (Nav) -->
    <header class="w-full px-6 py-4 flex justify-between items-center max-w-7xl mx-auto">
        <div class="flex items-center gap-3">
            <div class="h-10 w-10 rounded-xl bg-brand/10 border border-brand flex items-center justify-center text-brand text-xl shadow-lg shadow-brand/20">
                <i class="fa-solid fa-terminal"></i>
            </div>
            <span class="text-2xl font-bold tracking-tight text-white">Cloud<span class="text-brand">Lab</span></span>
        </div>
        <div>
            <a href="register.php" class="text-sm font-medium text-zinc-400 hover:text-white transition-colors duration-200">
                Create an Account <i class="fa-solid fa-arrow-right ml-1"></i>
            </a>
        </div>
    </header>

    <!-- Main Container -->
    <main class="flex-grow flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            
            <!-- Welcome text -->
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold tracking-tight text-white mb-2">Welcome Back</h1>
                <p class="text-zinc-400">Launch your isolated personal Linux container instantly</p>
            </div>

            <!-- Login Card -->
            <div class="bg-zinc-900/60 backdrop-blur-xl border border-zinc-800 rounded-3xl p-8 shadow-2xl">
                
                <?php if (isset($_SESSION['admin_user_id']) || isset($_SESSION['student_user_id'])): ?>
                    <div class="mb-6 bg-zinc-950/40 border border-zinc-800/80 text-zinc-300 rounded-xl p-4 text-sm flex flex-col gap-3">
                        <div class="flex items-center gap-2 text-zinc-400 font-semibold">
                            <i class="fa-solid fa-circle-info text-brand"></i>
                            <span>Active Sessions Detected</span>
                        </div>
                        <div class="flex flex-col gap-2">
                            <?php if (isset($_SESSION['admin_user_id'])): ?>
                                <div class="flex justify-between items-center bg-zinc-900/60 border border-zinc-800/40 px-3 py-2.5 rounded-lg">
                                    <span class="text-zinc-400 text-xs">Logged in as Admin: <strong class="text-white font-medium"><?= htmlspecialchars($_SESSION['admin_username']) ?></strong></span>
                                    <a href="admin.php" class="text-brand hover:text-brand-400 font-semibold text-xs flex items-center gap-1 transition-colors">
                                        Admin Panel <i class="fa-solid fa-arrow-right-long text-[10px]"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($_SESSION['student_user_id'])): ?>
                                <div class="flex justify-between items-center bg-zinc-900/60 border border-zinc-800/40 px-3 py-2.5 rounded-lg">
                                    <span class="text-zinc-400 text-xs">Logged in as Student: <strong class="text-white font-medium"><?= htmlspecialchars($_SESSION['student_username']) ?></strong></span>
                                    <a href="dashboard.php" class="text-brand hover:text-brand-400 font-semibold text-xs flex items-center gap-1 transition-colors">
                                        Dashboard <i class="fa-solid fa-arrow-right-long text-[10px]"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="mb-6 bg-red-950/40 border border-red-500/30 text-red-400 rounded-xl p-4 text-sm flex gap-3 items-start">
                        <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                        <div>
                            <span class="font-semibold">Authentication Error</span>
                            <p class="mt-0.5 text-red-300/80"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="mb-6 bg-green-950/40 border border-green-500/30 text-green-400 rounded-xl p-4 text-sm flex gap-3 items-start">
                        <i class="fa-solid fa-circle-check mt-0.5"></i>
                        <div>
                            <span class="font-semibold">Success</span>
                            <p class="mt-0.5 text-green-300/80"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <form action="api/auth.php?action=login" method="POST" class="space-y-6">
                    <div>
                        <label for="username" class="block text-sm font-semibold text-zinc-300 mb-2">Username</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-zinc-500">
                                <i class="fa-solid fa-user"></i>
                            </span>
                            <input type="text" id="username" name="username" required
                                class="block w-full pl-10 pr-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white placeholder-zinc-500 focus:outline-none focus:border-brand focus:ring-1 focus:ring-brand transition duration-200"
                                placeholder="Enter your username">
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label for="password" class="block text-sm font-semibold text-zinc-300">Password</label>
                        </div>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-zinc-500">
                                <i class="fa-solid fa-lock"></i>
                            </span>
                            <input type="password" id="password" name="password" required
                                class="block w-full pl-10 pr-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white placeholder-zinc-500 focus:outline-none focus:border-brand focus:ring-1 focus:ring-brand transition duration-200"
                                placeholder="••••••••">
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full py-3 px-4 bg-brand hover:bg-brand-600 active:bg-brand-700 text-zinc-950 font-bold rounded-xl shadow-lg shadow-brand/20 hover:shadow-brand/35 transition-all duration-200 transform active:scale-[0.98]">
                        Sign In <i class="fa-solid fa-right-to-bracket ml-2"></i>
                    </button>
                </form>
            </div>

            <!-- Footer indicator -->
            <p class="text-center mt-6 text-sm text-zinc-500">
                Don't have an account? <a href="register.php" class="text-brand hover:underline font-medium">Create one now</a>
            </p>
        </div>
    </main>

    <!-- Footer -->
    <footer class="w-full py-6 text-center text-xs text-zinc-600 border-t border-zinc-900/60 max-w-7xl mx-auto">
        <p>© 2026 CloudLab. Built for final year B.E. Computer Science & Engineering Project.</p>
    </footer>

</body>
</html>
