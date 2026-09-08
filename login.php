<?php
session_start();
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
require_once __DIR__ . '/database/db.php';
$error = '';
$success = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Please enter a valid email and password.';
    } elseif (!$conn) {
        $error = 'The account service is temporarily unavailable.';
    } else {
        $stmt = $conn->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $account = $result ? $result->fetch_assoc() : null;
            $storedPassword = $account['password_hash'] ?? $account['password'] ?? '';
            $validPassword = $account && (password_verify($password, $storedPassword) || hash_equals((string) $storedPassword, $password));
            if ($validPassword) {
                            $role = strtolower($account['role'] ?? $account['user_role'] ?? 'customer');
                unset($_SESSION['guest_mode']);
                $_SESSION['user_id'] = (int) ($account['id'] ?? $account['user_id']);
                $_SESSION['user_role'] = $role;
                $_SESSION['user_name'] = $account['fullname'] ?? $account['name'] ?? $account['full_name'] ?? $email;
                header('Location: ' . (in_array($role, ['admin', 'staff'], true) ? 'admin/template01.php' : 'index.php#home-section'));
                exit;
            }
            $error = 'We could not match those login details.';
            $stmt->close();
        } else {
            $error = 'Login is not configured for the current database.';
        }
    }
}
if ($success) {
    echo '<div style="position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:50;padding:14px 20px;border:1px solid #a7f3d0;border-radius:12px;background:#ecfdf5;color:#047857;font:600 14px Inter,system-ui,sans-serif;box-shadow:0 8px 20px rgba(16,185,129,.12)">' . htmlspecialchars($success) . '</div>';
}
?><style>
    section.flex.items-center.justify-center .mb-8 > p:first-child {
        font-size: 1.125rem !important;
        font-weight: 800 !important;
    }
        .guest-login-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 180px;
            padding: 10px 18px;
            border: 1px solid #c4b5fd;
            border-radius: 999px;
            color: #7c3aed;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        .guest-login-link:hover {
            background: #f3e8ff;
            color: #6d28d9;
        }
        .login-submit.is-signing-in {
            cursor: wait;
            opacity: 0.8;
        }
        .login-submit.is-signing-in::before {
            content: '';
            display: inline-block;
            width: 13px;
            height: 13px;
            margin-right: 8px;
            border: 2px solid rgba(255, 255, 255, 0.45);
            border-top-color: #ffffff;
            border-radius: 28px;
            vertical-align: -2px;
            animation: loginSpinner 650ms linear infinite;
        }
        @keyframes loginSpinner { to { transform: rotate(360deg); } }
        @property --queuezy-loader-angle {
            syntax: '<angle>';
            initial-value: 0deg;
            inherits: false;
        }
        .queuezy-logo-loader {
            position: fixed;
            top: calc(50% - 28px);
            left: 50%;
            width: 132px;
            height: 132px;
            z-index: 10001;
            border: 3px solid transparent;
            border-radius: 25px;
            background: conic-gradient(from var(--queuezy-loader-angle), #7c3aed 0deg 250deg, #f5d0fe 285deg, #ffffff 315deg, #7c3aed 350deg 360deg) border-box;
            box-shadow: 0 0 18px rgba(233, 213, 255, 0.75);
            transform: translate(-50%, -50%);
            animation: queuezyLoaderRotation 2.2s linear infinite;
            transition: opacity 450ms ease, visibility 450ms ease;
        }
        .queuezy-logo-loader span {
            position: absolute;
            inset: 3px;
            border-radius: 22px;
            background-color: #7c3aed;
            background-image: var(--queuezy-logo-image, none);
            background-repeat: no-repeat;
            background-size: 124px 124px;
            background-position: center;
        }
        @keyframes queuezyLoaderRotation {
            to { --queuezy-loader-angle: 360deg; }
        }
        html:not(.queue-loading) .queuezy-logo-loader {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        html.queue-loading body > main {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        html:not(.queue-loading) body > main {
            opacity: 1;
            visibility: visible;
            transition: opacity 350ms ease;
        }
        .queuezy-splash {
            position: fixed;
            inset: 0;
            z-index: 9998;
            display: grid;
            place-items: center;
            overflow: hidden;
            background: #ffffff;
            opacity: 1;
            transition: opacity 450ms ease, visibility 450ms ease;
        }
        .queuezy-splash::before {
            display: none;
        }
        .queuezy-splash-title {
            position: absolute;
            top: calc(50% + 60px);
            width: 100%;
            color: #24104f;
            font-size: 24px;
            font-weight: 900;
            letter-spacing: 0.12em;
            text-align: center;
        }
        .queuezy-splash-subtitle {
            position: absolute;
            top: calc(50% + 96px);
            width: 100%;
            color: rgba(36, 16, 79, 0.62);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.16em;
            text-align: center;
            text-transform: uppercase;
        }
        html:not(.queue-loading) .queuezy-splash {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        html.queue-loading::before,
        html.queue-loading::after {
            display: none;
        }
        html.queue-loading::before {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: #ffffff;
            background-image: none;
            background-repeat: no-repeat;
            background-size: 82px 82px, cover;
            background-position: center calc(50% - 28px), center;
            border-radius: 0;
            transition: opacity 450ms ease, visibility 450ms ease;
        }
        html.queue-loading::after {
            content: 'QUEUEZY';
            position: fixed;
            inset: 50% 0 auto;
            z-index: 10000;
            color: #6d28d9;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-align: center;
            text-shadow: 0 1px 8px rgba(255, 255, 255, 0.9);
            transform: translateY(28px);
            transition: opacity 450ms ease, visibility 450ms ease;
        }
        html:not(.queue-loading)::before,
        html:not(.queue-loading)::after {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
</style>
<script>
    document.documentElement.classList.add('queue-loading');
    const queuezySplash = document.createElement('div');
    queuezySplash.className = 'queuezy-splash';
    queuezySplash.innerHTML = '<strong class="queuezy-splash-title">QUEUEZY</strong><span class="queuezy-splash-subtitle">Smart queue management</span>';
    document.documentElement.appendChild(queuezySplash);
    const queuezyLogoLoader = document.createElement('div');
    queuezyLogoLoader.className = 'queuezy-logo-loader';
    queuezyLogoLoader.innerHTML = '<span aria-hidden="true"></span>';
    queuezySplash.appendChild(queuezyLogoLoader);
    const queuezySplashLogo = new Image();
    queuezySplashLogo.onload = function () {
        const canvas = document.createElement('canvas');
        canvas.width = queuezySplashLogo.naturalWidth;
        canvas.height = queuezySplashLogo.naturalHeight;
        const context = canvas.getContext('2d');
        context.drawImage(queuezySplashLogo, 0, 0);
        const image = context.getImageData(0, 0, canvas.width, canvas.height);
        for (let index = 0; index < image.data.length; index += 4) {
            const red = image.data[index];
            const green = image.data[index + 1];
            const blue = image.data[index + 2];
            const brightness = (red + green + blue) / 3;
            const saturation = Math.max(red, green, blue) - Math.min(red, green, blue);
            if (saturation < 45 && brightness < 180) image.data[index + 3] = 0;
        }
        context.putImageData(image, 0, 0);
        document.documentElement.style.setProperty('--queuezy-logo-image', `url(${canvas.toDataURL('image/png')})`);
    };
    queuezySplashLogo.src = 'assets/images/logo.jpg';
    document.addEventListener('DOMContentLoaded', function () {
        window.setTimeout(function () {
            document.documentElement.classList.remove('queue-loading');
            window.setTimeout(function () {
                queuezySplash.remove();
            }, 500);
        }, 3000);
        const socialButtons = document.querySelector('[aria-label="Google login"]')?.parentElement;
        if (socialButtons) {
            socialButtons.innerHTML = '<a href="index.php?guest=1" class="guest-login-link">Sign in as guest</a>';
        }
        const loginForm = document.querySelector('form[method="post"]');
        loginForm?.addEventListener('submit', function () {
            const submitButton = this.querySelector('button[type="submit"]');
            if (!submitButton) return;
            submitButton.classList.add('login-submit', 'is-signing-in');
            submitButton.disabled = true;
            submitButton.textContent = 'Signing in...';
        });
    });
</script>
<script src="assets/js/password-toggle.js"></script>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QUEUEZY</title><script src="https://cdn.tailwindcss.com"></script><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><script>tailwind.config={theme:{extend:{colors:{queue:'#7C3AED',mist:'#F8F7FC',lavender:'#F3E8FF'}}}}</script></head>
<body class="min-h-screen bg-mist font-[Inter,sans-serif] text-slate-800"><main class="min-h-screen grid lg:grid-cols-2"><section class="hidden lg:flex relative overflow-hidden bg-gradient-to-br from-[#7C3AED] to-[#A78BFA] p-12 text-white"><a href="login.php" class="relative z-10 flex items-center gap-3 text-2xl font-extrabold"><span class="grid h-11 w-11 place-items-center rounded-full bg-white text-queue">Q</span>Queuezy</a><div class="relative z-10 self-center max-w-lg"><p class="mb-4 text-sm font-bold uppercase tracking-[.22em] text-purple-100">Smart queue & appointments</p><h1 class="text-5xl font-extrabold leading-tight">Your time matters.<br>We keep your place.</h1><p class="mt-6 max-w-md text-lg leading-8 text-purple-100">Join a queue remotely, follow your progress, and arrive when it is almost your turn.</p><div class="mt-12 flex items-end gap-3" aria-hidden="true"><span class="h-24 w-20 rounded-t-2xl bg-white/20"></span><span class="h-40 w-20 rounded-t-2xl bg-white/30"></span><span class="h-32 w-20 rounded-t-2xl bg-white/20"></span><span class="h-52 w-20 rounded-t-2xl bg-white/40"></span></div></div><i class="fa-solid fa-ticket absolute bottom-12 right-20 rotate-12 text-[180px] text-white/10"></i></section><section class="flex items-center justify-center p-6 sm:p-12"><div class="w-full max-w-md"><div class="mb-10 lg:hidden flex items-center gap-3 text-2xl font-extrabold"><span class="grid h-11 w-11 place-items-center rounded-full bg-queue text-white">Q</span>Queue<span class="text-queue">zy</span></div><div class="mb-8"><p class="text-sm font-bold uppercase tracking-widest text-queue">Welcome to Queuezy</p><h2 class="mt-3 text-3xl font-extrabold"></h2><p class="mt-2 text-slate-500"></p></div><?php if ($error): ?><div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700"><?= htmlspecialchars($error) ?></div><?php endif; ?><form method="post" class="space-y-5"><label class="block text-sm font-semibold">Email address<div class="relative mt-2"><i class="fa-solid fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i><input name="email" type="email" required autocomplete="email" placeholder="you@example.com" class="w-full rounded-xl border border-slate-200 bg-white py-3.5 pl-11 pr-4 outline-none transition focus:border-queue focus:ring-4 focus:ring-purple-100"></div></label><label class="block text-sm font-semibold">Password<div class="relative mt-2"><i class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i><input name="password" type="password" required autocomplete="current-password" placeholder="Enter your password" class="w-full rounded-xl border border-slate-200 bg-white py-3.5 pl-11 pr-4 outline-none transition focus:border-queue focus:ring-4 focus:ring-purple-100"></div></label><div class="flex items-center justify-between text-sm"><label class="flex items-center gap-2 text-slate-500"><input type="checkbox" name="remember" class="h-4 w-4 accent-queue"> Remember me</label><a href="#" class="font-semibold text-queue">Forgot password?</a></div><button type="submit" class="w-full rounded-xl bg-queue py-3.5 font-bold text-white shadow-lg shadow-purple-200 transition hover:bg-purple-800">Log in</button></form><div class="my-7 flex items-center gap-3 text-xs text-slate-400"><span class="h-px flex-1 bg-slate-200"></span>or continue with<span class="h-px flex-1 bg-slate-200"></span></div><div class="flex justify-center gap-3"><button type="button" aria-label="Google login" class="grid h-11 w-11 place-items-center rounded-full border border-slate-200 text-red-500 hover:bg-red-50"><i class="fa-brands fa-google"></i></button><button type="button" aria-label="Facebook login" class="grid h-11 w-11 place-items-center rounded-full border border-slate-200 text-blue-600 hover:bg-blue-50"><i class="fa-brands fa-facebook-f"></i></button><button type="button" aria-label="Twitter login" class="grid h-11 w-11 place-items-center rounded-full border border-slate-200 text-sky-500 hover:bg-sky-50"><i class="fa-brands fa-x-twitter"></i></button></div><p class="mt-8 text-center text-sm text-slate-500">Need an account? <a href="register.php" class="font-bold text-queue">Create one</a></p></div></section></main></body></html>
