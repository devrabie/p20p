<?php
require_once 'db.php';
session_start();

// إذا كان المستخدم مسجل دخول بالفعل، وجهه للرئيسية فوراً
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "يرجى إدخال اسم المستخدم وكلمة المرور";
    } else {
        // البحث عن المستخدم في قاعدة البيانات
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // التحقق من صحة كلمة المرور المشفرة
        if ($user && password_verify($password, $user['password'])) {
            // تسجيل البيانات في الجلسة (Session)
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];

            // التوجه للوحة التحكم
            header("Location: index.php");
            exit();
        } else {
            $error = "خطأ في اسم المستخدم أو كلمة المرور!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول | P2P Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Tajawal', sans-serif; background-color: #0f172a; }
        .input-dark { background-color: #0f172a; border: 1px solid #334155; color: white; padding: 14px; border-radius: 12px; width: 100%; outline: none; transition: 0.3s; }
        .input-dark:focus { border-color: #eab308; box-shadow: 0 0 0 2px rgba(234, 179, 8, 0.15); }
        .btn-yellow { background-color: #eab308; color: #0f172a; font-weight: 800; border-radius: 14px; transition: all 0.2s; }
        .btn-yellow:hover { background-color: #facc15; transform: translateY(-2px); }
    </style>
</head>
<body class="text-white flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-md bg-[#1e293b] p-8 rounded-3xl border border-slate-700 shadow-2xl relative overflow-hidden">
        <!-- توهج خلفي جمالي -->
        <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-blue-500/10 rounded-full blur-3xl"></div>
        
        <div class="text-center mb-10">
            <div class="inline-flex p-4 bg-yellow-500/10 rounded-2xl mb-4 text-yellow-500">
                <i data-lucide="shield-check" class="w-8 h-8"></i>
            </div>
            <h1 class="text-2xl font-bold tracking-tight">مرحباً بعودتك</h1>
            <p class="text-slate-400 text-sm mt-2 uppercase tracking-widest italic">P2P Accounting System</p>
        </div>

        <!-- رسالة الخطأ -->
        <?php if($error): ?>
            <div class="bg-red-500/10 text-red-500 border border-red-500/20 p-4 rounded-xl mb-6 text-sm flex items-center gap-3 animate-pulse">
                <i data-lucide="info" class="w-4 h-4"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- رسالة نجاح التسجيل (تأتي من صفحة register) -->
        <?php if(isset($_GET['registered'])): ?>
            <div class="bg-green-500/10 text-green-500 border border-green-500/20 p-4 rounded-xl mb-6 text-sm flex items-center gap-3">
                <i data-lucide="check-circle" class="w-4 h-4"></i> تم إنشاء الحساب بنجاح، سجل دخولك الآن.
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-xs text-slate-400 mb-2 font-bold flex items-center gap-2">
                    <i data-lucide="user" class="w-3 h-3 text-yellow-500"></i> اسم المستخدم
                </label>
                <input type="text" name="username" required placeholder="أدخل اسم المستخدم" class="input-dark">
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-2 font-bold flex items-center gap-2">
                    <i data-lucide="lock" class="w-3 h-3 text-yellow-500"></i> كلمة المرور
                </label>
                <input type="password" name="password" required placeholder="••••••••" class="input-dark">
            </div>

            <button type="submit" class="w-full btn-yellow py-4 shadow-lg shadow-yellow-900/20 flex items-center justify-center gap-2 active:scale-95 transition">
                تسجيل الدخول <i data-lucide="log-in" class="w-4 h-4"></i>
            </button>
        </form>

        <div class="mt-8 pt-6 border-t border-slate-700 text-center">
            <p class="text-slate-500 text-sm">ليس لديك حساب بعد؟ 
                <a href="register.php" class="text-yellow-500 font-bold hover:underline">أنشئ حساباً مجانياً</a>
            </p>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>