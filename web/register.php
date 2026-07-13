<?php
session_start();
if (isset($_SESSION['student_user_id']) && !isset($_GET['new_session'])) {
    $tabToken = '';
    if (isset($_SESSION['tabs'])) {
        $keys = array_keys($_SESSION['tabs']);
        if (!empty($keys)) {
            $tabToken = $keys[0];
        }
    }
    $query = $tabToken ? "?tab_token=" . urlencode($tabToken) : "";
    header("Location: dashboard.php" . $query);
    exit();
}
require_once __DIR__ . '/includes/db.php';
$servicesStmt = $pdo->query("SELECT * FROM services ORDER BY name ASC");
$services = $servicesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - CloudLab</title>
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
<body class="min-h-full font-sans text-zinc-100 gradient-bg flex flex-col justify-between">

    <!-- Top header (Nav) -->
    <header class="w-full px-6 py-4 flex justify-between items-center max-w-7xl mx-auto">
        <div class="flex items-center gap-3">
            <div class="h-10 w-10 rounded-xl bg-brand/10 border border-brand flex items-center justify-center text-brand text-xl shadow-lg shadow-brand/20">
                <i class="fa-solid fa-terminal"></i>
            </div>
            <a href="index.php" class="text-2xl font-bold tracking-tight text-white">Cloud<span class="text-brand">Lab</span></a>
        </div>
        <div>
            <a href="index.php" class="text-sm font-medium text-zinc-400 hover:text-white transition-colors duration-200">
                <i class="fa-solid fa-arrow-left mr-1"></i> Back to Login
            </a>
        </div>
    </header>

    <!-- Main Container -->
    <main class="flex-grow flex items-center justify-center px-4 py-8">
        <div class="w-full max-w-lg">
            
            <div class="text-center mb-6">
                <h1 class="text-4xl font-bold tracking-tight text-white mb-2">Create Account</h1>
                <p class="text-zinc-400">Select your preferred lab environment during registration</p>
            </div>

            <!-- Register Card -->
            <div class="bg-zinc-900/60 backdrop-blur-xl border border-zinc-800 rounded-3xl p-8 shadow-2xl">
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="mb-6 bg-red-950/40 border border-red-500/30 text-red-400 rounded-xl p-4 text-sm flex gap-3 items-start">
                        <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                        <div>
                            <span class="font-semibold">Registration Error</span>
                            <p class="mt-0.5 text-red-300/80"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <form action="api/auth.php?action=register" method="POST" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="username" class="block text-sm font-semibold text-zinc-300 mb-2">Username</label>
                            <input type="text" id="username" name="username" required
                                class="block w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white placeholder-zinc-600 focus:outline-none focus:border-brand focus:ring-1 focus:ring-brand transition duration-200"
                                placeholder="john_doe">
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-semibold text-zinc-300 mb-2">Email Address</label>
                            <input type="email" id="email" name="email" required
                                class="block w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white placeholder-zinc-600 focus:outline-none focus:border-brand focus:ring-1 focus:ring-brand transition duration-200"
                                placeholder="john@example.com">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="password" class="block text-sm font-semibold text-zinc-300 mb-2">Password</label>
                            <input type="password" id="password" name="password" required
                                class="block w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white placeholder-zinc-600 focus:outline-none focus:border-brand focus:ring-1 focus:ring-brand transition duration-200"
                                placeholder="••••••••">
                        </div>

                        <div>
                            <label for="confirm_password" class="block text-sm font-semibold text-zinc-300 mb-2">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required
                                class="block w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-white placeholder-zinc-600 focus:outline-none focus:border-brand focus:ring-1 focus:ring-brand transition duration-200"
                                placeholder="••••••••">
                        </div>
                    </div>

                    <div>
                        <label for="lab_type" class="block text-sm font-semibold text-zinc-300 mb-2">Linux Lab Environment</label>
                        <div class="relative">
                            <select id="lab_type" name="lab_type" required
                                class="block w-full px-4 py-3 bg-zinc-950 border border-zinc-800 rounded-xl text-zinc-300 focus:outline-none focus:border-brand focus:ring-1 focus:ring-brand transition duration-200">
                                <?php foreach ($services as $service): ?>
                                    <option value="<?= htmlspecialchars($service['name']) ?>"><?= htmlspecialchars($service['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="mt-2 text-xs text-zinc-500">Your environment will be initialized and mapped to your personal workspace volume.</p>
                    </div>

                    <button type="submit"
                        class="w-full mt-2 py-3 px-4 bg-brand hover:bg-brand-600 active:bg-brand-700 text-zinc-950 font-bold rounded-xl shadow-lg shadow-brand/20 hover:shadow-brand/35 transition-all duration-200 transform active:scale-[0.98]">
                        Register & Initialize Lab <i class="fa-solid fa-user-plus ml-2"></i>
                    </button>
                </form>
            </div>

            <!-- Footer indicator -->
            <p class="text-center mt-6 text-sm text-zinc-500">
                Already have an account? <a href="index.php" class="text-brand hover:underline font-medium">Sign In</a>
            </p>
        </div>
    </main>

    <!-- Footer -->
    <footer class="w-full py-6 text-center text-xs text-zinc-600 border-t border-zinc-900/60 max-w-7xl mx-auto">
        <p>© 2026 CloudLab. Built for final year B.E. Computer Science & Engineering Project.</p>
    </footer>

</body>
</html>
