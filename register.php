<?php
require_once 'db.php';

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "يرجى تعبئة جميع الحقول";
    } else {
        // تشفير كلمة المرور للحماية
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        try {
            // 1. محاولة إدخال المستخدم الجديد
            $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->execute([$username, $hashed_password]);
            
            // جلب الـ ID الخاص بالمستخدم الذي تم إنشاؤه للتو
            $new_user_id = $pdo->lastInsertId();

            // 2. إنشاء إعدادات افتراضية لهذا المستخدم (ضروري لعمل صفحة index)
            $stmt_settings = $pdo->prepare("INSERT INTO settings (user_id, default_buy_price, default_sell_price) VALUES (?, 535, 540)");
            $stmt_settings->execute([$new_user_id]);

            $success = "تم إنشاء الحساب بنجاح! يمكنك الآن تسجيل الدخول.";
            // توجيه تلقائي بعد 2 ثانية لصفحة اللوجن
            header("refresh:2;url=login.php");

        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // كود الخطأ لاسم مستخدم مكرر
                $error = "عذراً، اسم المستخدم هذا محجوز مسبقاً.";
            } else {
                $error = "حدث خطأ غير متوقع: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء حساب جديد | P2P Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Tajawal', sans-serif; background-color: #0f172a; }
        .input-dark { background-color: #0f172a; border: 1px solid #334155; color: white; padding: 14px; border-radius: 12px; width: 100%; outline: none; transition: 0.3s; }
        .input-dark:focus { border-color: #eab308; box-shadow: 0 0 0 2px rgba(234, 179, 8, 0.1); }
    </style>
</head>
<body class="text-white flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-md bg-[#1e293b] p-8 rounded-3xl border border-slate-700 shadow-2xl relative overflow-hidden">
        <!-- لمسة جمالية (توهج خلفي) -->
        <div class="absolute -top-24 -left-24 w-48 h-48 bg-yellow-500/10 rounded-full blur-3xl"></div>
        
        <div class="text-center mb-10">
            <div class="inline-flex p-4 bg-yellow-500/10 rounded-2xl mb-4 text-yellow-500">
                <i data-lucide="user-plus" class="w-8 h-8"></i>
            </div>
            <h1 class="text-2xl font-bold">إنشاء حساب جديد</h1>
            <p class="text-slate-400 text-sm mt-2">انضم إلينا لإدارة تداولاتك باحترافية</p>
        </div>

        <!-- رسائل الخطأ والنجاح -->
        <?php if($error): ?>
            <div class="bg-red-500/10 text-red-500 border border-red-500/20 p-4 rounded-xl mb-6 text-sm flex items-center gap-2">
                <i data-lucide="alert-circle" class="w-4 h-4"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="bg-green-500/10 text-green-500 border border-green-500/20 p-4 rounded-xl mb-6 text-sm flex items-center gap-2">
                <i data-lucide="check-circle" class="w-4 h-4"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-xs text-slate-400 mb-2 font-bold flex items-center gap-2">
                    <i data-lucide="user" class="w-3 h-3 text-yellow-500"></i> اسم المستخدم (بالإنجليزي)
                </label>
                <input type="text" name="username" required placeholder="مثلاً: ahmed_p2p" class="input-dark">
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-2 font-bold flex items-center gap-2">
                    <i data-lucide="lock" class="w-3 h-3 text-yellow-500"></i> كلمة المرور
                </label>
                <input type="password" name="password" required placeholder="••••••••" class="input-dark">
            </div>

            <button type="submit" class="w-full bg-yellow-600 hover:bg-yellow-500 text-black font-bold py-4 rounded-xl transition-all shadow-lg shadow-yellow-900/20 active:scale-95 flex items-center justify-center gap-2">
                إنشاء الحساب الآن <i data-lucide="arrow-left" class="w-4 h-4"></i>
            </button>
        </form>

        <div class="mt-8 pt-6 border-t border-slate-700 text-center">
            <p class="text-slate-500 text-sm">لديك حساب بالفعل؟ 
                <a href="login.php" class="text-yellow-500 font-bold hover:underline">سجل دخولك هنا</a>
            </p>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>