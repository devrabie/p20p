<?php
session_start();
require_once 'db.php';

// 1. حماية الصفحة - يجب أن يكون مسجلاً للدخول وبرتبة admin
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// معالجة العودة للإدارة أولاً قبل التحقق من الرتبة
if (isset($_GET['action']) && $_GET['action'] === 'return_to_admin' && isset($_SESSION['admin_user_id'])) {
    $_SESSION['user_id'] = $_SESSION['admin_user_id'];
    unset($_SESSION['admin_user_id']);

    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['username'] = $stmt->fetchColumn();

    header("Location: admin.php");
    exit();
}

// جلب بيانات المستخدم الحالي للتأكد من رتبته
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'admin') {
    die("عذراً، لا تملك صلاحية الوصول لهذه الصفحة.");
}

// 2. التحقق من كلمة المرور الثانوية
$admin_secondary_pass = getenv('ADMIN_SECONDARY_PASSWORD') ?: '123456';

if (isset($_POST['secondary_password'])) {
    if ($_POST['secondary_password'] === $admin_secondary_pass) {
        $_SESSION['admin_verified'] = true;
    } else {
        $error = "كلمة المرور الثانوية غير صحيحة!";
    }
}

if (!isset($_SESSION['admin_verified']) || $_SESSION['admin_verified'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>حماية إضافية | لوحة الإدارة</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
        <style>body { font-family: 'Tajawal', sans-serif; background-color: #0a0f1c; }</style>
    </head>
    <body class="flex items-center justify-center min-h-screen p-4 text-white">
        <div class="w-full max-w-md bg-[#151b2d] p-8 rounded-2xl border border-slate-800 shadow-2xl">
            <h1 class="text-xl font-black text-yellow-500 mb-6 text-center italic">تأكيد الهوية</h1>
            <?php if(isset($error)): ?>
                <div class="bg-red-500/10 text-red-500 p-3 rounded-lg mb-4 text-sm text-center border border-red-500/20"><?php echo $error; ?></div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-2 uppercase font-bold">كلمة المرور الثانوية</label>
                    <input type="password" name="secondary_password" required class="w-full bg-[#0a0f1c] border border-slate-700 p-3 rounded-lg outline-none focus:border-yellow-500 text-center">
                </div>
                <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-black font-black py-3 rounded-lg transition-all">دخول</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// 3. معالجة الإجراءات الإدارية
if (isset($_GET['action'])) {
    $target_id = $_GET['user_id'] ?? null;

    if ($_GET['action'] === 'toggle_status' && $target_id) {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$target_id]);
        $current_status = $stmt->fetchColumn();
        $new_status = ($current_status === 'active') ? 'frozen' : 'active';

        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role != 'admin'");
        $stmt->execute([$new_status, $target_id]);
        header("Location: admin.php");
        exit();
    }

    if ($_GET['action'] === 'delete' && $target_id) {
        // حماية: لا يمكن حذف المدير
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$target_id]);
        header("Location: admin.php");
        exit();
    }

    if ($_GET['action'] === 'login_as' && $target_id) {
        // حفظ معرف المدير الحالي للعودة لاحقاً
        $_SESSION['admin_user_id'] = $_SESSION['user_id'];
        $_SESSION['user_id'] = $target_id;

        // جلب اسم المستخدم الجديد لتحديث الجلسة
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$target_id]);
        $_SESSION['username'] = $stmt->fetchColumn();

        header("Location: index.php");
        exit();
    }

}

// 4. جلب الإحصائيات العامة
$stats = [
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'total_tx' => $pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn(),
    'total_volume' => $pdo->query("SELECT SUM(crypto_amount) FROM transactions")->fetchColumn() ?: 0,
    'total_profit' => $pdo->query("SELECT SUM(fifo_profit) FROM transactions WHERE type='sell'")->fetchColumn() ?: 0
];

// 5. جلب قائمة المستخدمين مع إحصائياتهم
$users_query = "
    SELECT
        u.id, u.username, u.role, u.status, u.created_at,
        COUNT(t.id) as tx_count,
        SUM(CASE WHEN t.type = 'sell' THEN t.fifo_profit ELSE 0 END) as total_profit,
        SUM(t.crypto_amount) as total_volume
    FROM users u
    LEFT JOIN transactions t ON u.id = t.user_id
    GROUP BY u.id
    ORDER BY u.created_at DESC
";
$users_list = $pdo->query($users_query)->fetchAll();

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الإدارة | Ledger Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style>
        :root { --bg-main: #0a0f1c; --bg-card: #151b2d; --accent-yellow: #eab308; --border-color: #242f48; }
        body { font-family: 'Tajawal', sans-serif; background-color: var(--bg-main); color: #f1f5f9; }
        .glass-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
    </style>
</head>
<body class="p-4 md:p-8 custom-scrollbar">

    <div class="max-w-6xl mx-auto">

        <header class="flex justify-between items-center mb-10 pb-6 border-b border-slate-800">
            <div>
                <h1 class="text-2xl font-black text-yellow-500 flex items-center gap-3 italic uppercase">
                    <i data-lucide="shield-check" class="w-8 h-8"></i> لوحة تحكم الإدارة
                </h1>
                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-1 italic">Ledger Pro Admin System</p>
            </div>
            <div class="flex gap-3">
                <a href="index.php" class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition-all flex items-center gap-2">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i> الرئيسية
                </a>
            </div>
        </header>

        <!-- الإحصائيات العامة -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">
            <div class="glass-card p-5 border-r-4 border-blue-500 bg-blue-500/5">
                <p class="text-[10px] font-black text-blue-400 uppercase mb-1 tracking-widest">إجمالي المستخدمين</p>
                <h3 class="text-xl font-black tabular-nums"><?php echo number_format($stats['total_users']); ?></h3>
            </div>
            <div class="glass-card p-5 border-r-4 border-purple-500 bg-purple-500/5">
                <p class="text-[10px] font-black text-purple-400 uppercase mb-1 tracking-widest">إجمالي العمليات</p>
                <h3 class="text-xl font-black tabular-nums"><?php echo number_format($stats['total_tx']); ?></h3>
            </div>
            <div class="glass-card p-5 border-r-4 border-yellow-500 bg-yellow-500/5">
                <p class="text-[10px] font-black text-yellow-400 uppercase mb-1 tracking-widest">حجم التداول الكلي</p>
                <h3 class="text-xl font-black tabular-nums"><?php echo number_format($stats['total_volume'], 1); ?> <span class="text-xs opacity-50">USDT</span></h3>
            </div>
            <div class="glass-card p-5 border-r-4 border-emerald-500 bg-emerald-500/5">
                <p class="text-[10px] font-black text-emerald-400 uppercase mb-1 tracking-widest">إجمالي الأرباح</p>
                <h3 class="text-xl font-black tabular-nums"><?php echo number_format($stats['total_profit']); ?> <span class="text-xs opacity-50">YER</span></h3>
            </div>
        </div>

        <!-- قائمة المستخدمين -->
        <div class="glass-card overflow-hidden shadow-2xl">
            <div class="p-6 border-b border-slate-800 bg-slate-800/30 flex justify-between items-center">
                <h2 class="text-sm font-black text-slate-300 uppercase tracking-widest flex items-center gap-2 italic">
                    <i data-lucide="users" class="text-yellow-500"></i> إدارة الحسابات
                </h2>
                <span class="text-[10px] bg-yellow-500/10 text-yellow-500 px-3 py-1 rounded-full font-black"><?php echo count($users_list); ?> مستخدم</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-900/50 text-[10px] font-black text-slate-500 uppercase tracking-widest">
                            <th class="p-4">المستخدم</th>
                            <th class="p-4 text-center">العمليات</th>
                            <th class="p-4 text-center">حجم التداول</th>
                            <th class="p-4 text-center">الأرباح</th>
                            <th class="p-4 text-center">الحالة</th>
                            <th class="p-4 text-left">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <?php foreach($users_list as $u): ?>
                        <tr class="hover:bg-white/5 transition-colors group">
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-slate-800 flex items-center justify-center border border-slate-700 text-xs font-black text-yellow-500 uppercase">
                                        <?php echo substr($u['username'], 0, 1); ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-black text-white"><?php echo htmlspecialchars($u['username']); ?> <?php if($u['role'] === 'admin') echo '<span class="text-[8px] bg-yellow-500 text-black px-1 rounded">ADMIN</span>'; ?></p>
                                        <p class="text-[9px] text-slate-500 font-bold italic"><?php echo date('Y/m/d', strtotime($u['created_at'])); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 text-center tabular-nums font-bold text-slate-300"><?php echo number_format($u['tx_count']); ?></td>
                            <td class="p-4 text-center tabular-nums font-black text-white"><?php echo number_format($u['total_volume'], 1); ?></td>
                            <td class="p-4 text-center tabular-nums font-black text-emerald-500"><?php echo number_format($u['total_profit']); ?></td>
                            <td class="p-4 text-center">
                                <?php if($u['status'] === 'active'): ?>
                                    <span class="bg-emerald-500/10 text-emerald-500 px-2 py-0.5 rounded text-[9px] font-black border border-emerald-500/20 italic">نشط</span>
                                <?php else: ?>
                                    <span class="bg-rose-500/10 text-rose-500 px-2 py-0.5 rounded text-[9px] font-black border border-rose-500/20 italic">مجمد</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4">
                                <div class="flex items-center justify-end gap-2">
                                    <?php if($u['role'] !== 'admin'): ?>
                                        <a href="admin.php?action=login_as&user_id=<?php echo $u['id']; ?>" class="bg-blue-500/10 hover:bg-blue-500 text-blue-500 hover:text-white p-2 rounded-lg transition-all" title="دخول كمسؤول للمستخدم">
                                            <i data-lucide="eye" class="w-4 h-4"></i>
                                        </a>
                                        <a href="admin.php?action=toggle_status&user_id=<?php echo $u['id']; ?>" class="bg-yellow-500/10 hover:bg-yellow-500 text-yellow-500 hover:text-black p-2 rounded-lg transition-all" title="<?php echo $u['status'] === 'active' ? 'تجميد' : 'إلغاء تجميد'; ?>">
                                            <i data-lucide="<?php echo $u['status'] === 'active' ? 'user-x' : 'user-check'; ?>" class="w-4 h-4"></i>
                                        </a>
                                        <a href="admin.php?action=delete&user_id=<?php echo $u['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذا الحساب نهائياً؟ لن تتمكن من التراجع!')" class="bg-rose-500/10 hover:bg-rose-500 text-rose-500 hover:text-white p-2 rounded-lg transition-all" title="حذف نهائي">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-[10px] text-slate-600 font-bold italic">لا توجد إجراءات</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
