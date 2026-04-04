<?php 
/**
 * نظام LEDGER PRO - النسخة الإمبراطورية الكاملة
 * 12 بطاقة إحصائية - رسم بياني ذكي - أتمتة شاملة - دقة بينانس
 */

session_start();
require_once 'db.php'; 

// 1. ضبط التوقيت لليمن (GMT+3) لضمان دقة العمليات الحالية واليومية
date_default_timezone_set('Asia/Aden');
$pdo->exec("SET time_zone = '+03:00'");
$today = date('Y-m-d');

// 2. حماية الصفحة: التأكد من تسجيل الدخول وتحديد هوية المستخدم
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id']; 
$username = $_SESSION['username'] ?? 'مستخدم';

// --- البدء بجلب البيانات الخاصة بالمستخدم الحالي فقط ---

// جلب إعدادات الأسعار الافتراضية
$settings_stmt = $pdo->prepare("SELECT default_buy_price, default_sell_price FROM settings WHERE user_id = ?");
$settings_stmt->execute([$user_id]);
$settings = $settings_stmt->fetch();
$def_buy = $settings['default_buy_price'] ?? 535;
$def_sell = $settings['default_sell_price'] ?? 540;

// حساب متوسط الشراء (WAC) بدقة Float - أساس حساب الربح الحقيقي
$buy_stats_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid) as total_spent, SUM(crypto_amount) as total_bought FROM transactions WHERE user_id = ? AND type='buy'");
$buy_stats_stmt->execute([$user_id]);
$buy_stats = $buy_stats_stmt->fetch();
$avg_buy_price = ($buy_stats['total_bought'] > 0) ? ($buy_stats['total_spent'] / $buy_stats['total_bought']) : 0;

// أ. حساب الأرباح التراكمية (YER / USD) بدقة متناهية تشمل تكلفة الرسوم
$profit_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid - (? * total_crypto_deducted)) as net_profit FROM transactions WHERE user_id = ? AND type='sell'");
$profit_stmt->execute([$avg_buy_price, $user_id]);
$total_profit_yer_all = $profit_stmt->fetchColumn() ?: 0;
$total_profit_usd_all = ($def_buy > 0) ? ($total_profit_yer_all / $def_buy) : 0;

// ب. حساب حجم التداول اليومي (Volume)
$daily_buy_vol_stmt = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type='buy' AND DATE(created_at) = ?");
$daily_buy_vol_stmt->execute([$user_id, $today]);
$daily_buy_vol = $daily_buy_vol_stmt->fetchColumn() ?: 0;

$daily_sell_vol_stmt = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type='sell' AND DATE(created_at) = ?");
$daily_sell_vol_stmt->execute([$user_id, $today]);
$daily_sell_vol = $daily_sell_vol_stmt->fetchColumn() ?: 0;

// ج. حساب مبالغ السيولة النقدية لليوم (YER)
$daily_in_money_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid) FROM transactions WHERE user_id = ? AND type='buy' AND DATE(created_at) = ?");
$daily_in_money_stmt->execute([$user_id, $today]);
$daily_in_money = $daily_in_money_stmt->fetchColumn() ?: 0;

$daily_out_money_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid) FROM transactions WHERE user_id = ? AND type='sell' AND DATE(created_at) = ?");
$daily_out_money_stmt->execute([$user_id, $today]);
$daily_out_money = $daily_out_money_stmt->fetchColumn() ?: 0;

// د. حساب المخزون المتوفر (Stock)
$total_in = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type='buy'");
$total_in->execute([$user_id]);
$sum_in = $total_in->fetchColumn() ?: 0;

$total_out = $pdo->prepare("SELECT SUM(total_crypto_deducted) FROM transactions WHERE user_id = ? AND type='sell'");
$total_out->execute([$user_id]);
$sum_out = $total_out->fetchColumn() ?: 0;
$remaining_stock = $sum_in - $sum_out;

// هـ. حسابات اليوم (أرباح ورسوم اليوم فقط) بدقة
$daily_profit_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid - (? * total_crypto_deducted)) FROM transactions WHERE user_id = ? AND type='sell' AND DATE(created_at) = ?");
$daily_profit_stmt->execute([$avg_buy_price, $user_id, $today]);
$daily_profit_yer_val = $daily_profit_stmt->fetchColumn() ?: 0;
$daily_profit_usd_val = ($def_buy > 0) ? ($daily_profit_yer_val / $def_buy) : 0;

$daily_fees_stmt = $pdo->prepare("SELECT SUM(binance_fee) FROM transactions WHERE user_id = ? AND DATE(created_at) = ?");
$daily_fees_stmt->execute([$user_id, $today]);
$daily_fees_usdt_val = $daily_fees_stmt->fetchColumn() ?: 0;
$daily_fees_yer_val = $daily_fees_usdt_val * $def_buy;

// جلب النطاق الزمني المختار (الافتراضي هو 'day' لعمليات اليوم)
// و. جلب بيانات الرسم البياني (منفصلة تماماً حسب النوع)
$range = $_GET['range'] ?? 'day';
$chart_labels = [];
$chart_values = [];
$cumulative_profit = 0;

if ($range === 'day') {
    // وضع اليوم: عمليات البيع لهذا اليوم مرتبة زمنياً (بحد أقصى 50 عملية)
    $stmt = $pdo->prepare("SELECT created_at, (total_fiat_paid - (? * total_crypto_deducted)) as op_profit FROM transactions WHERE user_id = ? AND type = 'sell' AND DATE(created_at) = ? ORDER BY id ASC LIMIT 50");
    $stmt->execute([$avg_buy_price, $user_id, $today]);
    $data_rows = $stmt->fetchAll();
    
    foreach ($data_rows as $data) {
        $cumulative_profit += (float)$data['op_profit'];
        $chart_labels[] = date('h:i A', strtotime($data['created_at']));
        $chart_values[] = round($cumulative_profit, 2);
    }
} else if ($range === 'pulse') {
    // وضع النبض: آخر 30 عملية بيع فردية مرتبة زمنياً (بغض النظر عن اليوم)
    $stmt = $pdo->prepare("SELECT created_at, (total_fiat_paid - (? * total_crypto_deducted)) as op_profit FROM transactions WHERE user_id = ? AND type = 'sell' ORDER BY id DESC LIMIT 30");
    $stmt->execute([$avg_buy_price, $user_id]);
    $data_rows = array_reverse($stmt->fetchAll());

    foreach ($data_rows as $data) {
        $cumulative_profit += (float)$data['op_profit'];
        $chart_labels[] = date('h:i A', strtotime($data['created_at']));
        $chart_values[] = round($cumulative_profit, 2);
    }
} else {
    // وضع أسبوعي أو شهري
    $days_count = ($range === 'month') ? 30 : 7;
    // نبدأ الحساب من 0 لبيان النمو خلال الفترة المختارة فقط
    $cumulative_profit = 0;

    for ($i = $days_count - 1; $i >= 0; $i--) {
        $current_d = date('Y-m-d', strtotime("-$i days"));
        $chart_labels[] = date('m-d', strtotime($current_d));
        
        $day_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid - (? * total_crypto_deducted)) FROM transactions WHERE user_id = ? AND type = 'sell' AND DATE(created_at) = ?");
        $day_stmt->execute([$avg_buy_price, $user_id, $current_d]);
        $day_val = (float)($day_stmt->fetchColumn() ?: 0);
        
        $cumulative_profit += $day_val;
        $chart_values[] = round($cumulative_profit, 2);
    }
}

// إضافة نقطة البداية (صفر) في بداية المصفوفة لضمان منطقية الرسم
array_unshift($chart_labels, "البداية");
array_unshift($chart_values, 0);

// ز. جلب السجل التاريخي (آخر 500 عملية)
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 500");
$stmt->execute([$user_id]);
$transactions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ledger Pro | المحاسب الذكي</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
<style>
    :root { --bg-main: #0a0f1c; --bg-card: #151b2d; --accent-gold: #eab308; --border-color: #242f48; --radius: 4px; }
    body { font-family: 'Tajawal', sans-serif; background-color: var(--bg-main); color: #e2e8f0; line-height: 1.6; scroll-behavior: smooth; font-style: normal !important; }
    * { font-style: normal !important; }
    .glass-card { background: var(--bg-card); border: 1px solid var(--border-color); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3); border-radius: var(--radius) !important; }
    .input-dark { background-color: #0d1220; border: 1px solid var(--border-color); color: white; padding: 12px; border-radius: var(--radius) !important; width: 100%; font-size: 15px; transition: all 0.3s ease; }
    .input-dark:focus { border-color: var(--accent-gold); outline: none; box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.1); }
    .btn-primary-glass { background: linear-gradient(135deg, rgba(79, 70, 229, 0.8) 0%, rgba(30, 58, 138, 0.8) 100%); backdrop-filter: blur(10px); color: white; border: 1px solid rgba(255, 255, 255, 0.1); font-weight: 800; border-radius: 12px !important; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 4px 15px -3px rgba(0, 0, 0, 0.4); }
    .btn-primary-glass:hover { transform: translateY(-2px); background: linear-gradient(135deg, rgba(99, 102, 241, 0.9) 0%, rgba(37, 99, 235, 0.9) 100%); box-shadow: 0 10px 25px -5px rgba(67, 56, 202, 0.4); border-color: rgba(255, 255, 255, 0.2); }
    .btn-primary-glass:active { transform: scale(0.97); }
    #toast { transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55); transform: translate(-50%, 100px); visibility: hidden; opacity: 0; z-index: 9999; }
    #toast.show { visibility: visible; opacity: 1; transform: translate(-50%, 0); }
    nav { border-radius: 0 0 var(--radius) var(--radius) !important; border-bottom: 2px solid var(--accent-gold) !important; }
    .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
    .tabular-nums { font-family: 'Courier New', monospace; font-weight: 700; }
    .custom-scrollbar::-webkit-scrollbar {
    height: 4px; /* جعل الشريط نحيفاً جداً للجمالية */
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #eab308; /* لون ذهبي مطابق للهوية */
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: #1e293b;
}
</style>
</head>
<body class="p-3 md:p-6 pb-20">

    <div id="toast" class="fixed bottom-10 left-1/2 -translate-x-1/2 bg-emerald-600 text-white px-8 py-4 rounded shadow-2xl font-black flex items-center gap-3 border border-emerald-400/30">
        <i data-lucide="check-circle"></i> <span id="toast-msg">تم الحفظ بنجاح!</span>
    </div>

    <nav class="max-w-6xl mx-auto bg-[#1e293b]/50 border-b border-slate-800 py-3 px-3 md:px-8 flex justify-between items-center mb-6 md:mb-8 glass-card">
        <div class="flex items-center gap-2 md:gap-3 group">
            <div class="w-8 h-8 md:w-10 md:h-10 bg-yellow-500/10 rounded-full flex items-center justify-center border border-yellow-500/20 group-hover:scale-110 transition"><i data-lucide="user-lock" class="w-4 h-4 md:w-6 md:h-6 text-yellow-500"></i></div>
            <div class="flex flex-col text-right"><span class="text-[9px] md:text-[10px] text-slate-500 font-bold uppercase italic">حساب التاجر</span><span class="text-xs md:text-sm font-black text-white"><?php echo htmlspecialchars($username); ?></span></div>
        </div>
        <a href="logout.php" onclick="return confirm('خروج؟')" class="flex items-center gap-2 bg-rose-500/10 hover:bg-rose-500 text-rose-500 hover:text-white px-3 md:px-5 py-2 rounded text-[10px] md:text-xs font-black border border-rose-500/20 transition-all"><span>خروج آمن</span> <i data-lucide="log-out" class="w-3.5 h-3.5 md:w-4 md:h-4"></i></a>
    </nav>

    <div class="max-w-6xl mx-auto">
        <header class="flex flex-col md:flex-row justify-between items-center gap-6 mb-8 md:mb-10 text-right">
            <div class="text-center md:text-right">
                <h1 class="text-2xl md:text-3xl font-black text-yellow-500 flex items-center justify-center md:justify-start gap-3 italic"><i data-lucide="shield-check"></i> LEDGER PRO</h1>
                <div class="flex justify-center md:justify-start gap-4 mt-3">
                    <div class="text-[10px] md:text-xs text-blue-400 font-bold border-l border-slate-700 pl-4 uppercase tracking-tighter">شراء: <span class="text-white"><?php echo number_format($def_buy, 2); ?></span></div>
                    <div class="text-[10px] md:text-xs text-green-400 font-bold border-l border-slate-700 pl-4 uppercase tracking-tighter">بيع: <span class="text-white"><?php echo number_format($def_sell, 2); ?></span></div>
                    <button onclick="document.getElementById('settingsModal').classList.remove('hidden')" class="text-yellow-500 hover:scale-125 transition"><i data-lucide="sliders" class="w-4 h-4 md:w-5 md:h-5"></i></button>
                </div>
            </div>

            <div class="flex flex-wrap justify-center md:justify-end items-center gap-2 md:gap-3">
                <a href="reports.php" class="glass-card bg-white/5 hover:bg-white/10 text-white px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 transition-all border border-white/10 active:scale-95">
                    <i data-lucide="calendar-days" class="w-3.5 h-3.5 text-blue-400"></i> الأرشيف والتحليل
                </a>
                <button onclick="openReportsModal()" class="glass-card bg-white/5 hover:bg-white/10 text-white px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 transition-all border border-white/10 active:scale-95">
                    <i data-lucide="layout-dashboard" class="w-3.5 h-3.5 text-emerald-400"></i> عرض التقارير
                </button>
            </div>
        </header>

        <!-- الصف الرئيسي: المخزون وربح اليوم -->
        <div class="grid grid-cols-2 gap-4 mb-10">
            <div class="glass-card p-5 border-r-4 border-yellow-500 shadow-xl"><span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase italic tracking-tighter">المخزون المتوفر (Stock)</span><h3 class="text-lg md:text-xl font-black text-yellow-500 tabular-nums"><?php echo number_format($remaining_stock, 2); ?></h3></div>
            <div class="glass-card p-4 bg-blue-500/10 border border-blue-500/20"><span class="text-[9px] text-blue-500 font-black uppercase mb-1 block">ربح اليوم ($)</span><h3 class="text-lg font-black text-blue-400 tabular-nums">$<?php echo number_format($daily_profit_usd_val, 2); ?></h3></div>
        </div>

    <!-- نافذة التقارير المنبثقة -->
    <div id="reportsModal" class="hidden fixed inset-0 bg-black/95 flex items-start md:items-center justify-center p-2 md:p-4 z-[500] overflow-y-auto">
        <div class="glass-card w-full max-w-5xl p-4 md:p-10 border-2 border-emerald-500/30 shadow-2xl my-4 md:my-auto">
            <div class="flex justify-between items-center mb-6 md:mb-8 pb-4 border-b border-slate-800">
                <h2 class="text-lg md:text-xl font-black text-emerald-500 flex items-center gap-3 italic uppercase tracking-widest"><i data-lucide="bar-chart-horizontal"></i> الإحصائيات التفصيلية</h2>
                <button onclick="closeReportsModal()" class="bg-slate-800 p-2 rounded-lg text-white hover:bg-rose-500 transition"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>

            <!-- شبكة البطاقات داخل النافذة -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 md:gap-4 mb-8">
                <div class="glass-card p-4 border-r-4 border-blue-500 bg-slate-900/40"><span class="text-slate-400 text-[9px] font-bold block mb-1 uppercase">صافي الربح ($)</span><h3 class="text-sm font-black text-blue-400 tabular-nums">$<?php echo number_format($total_profit_usd_all, 2); ?></h3></div>
                <div class="glass-card p-4 border-r-4 border-emerald-500 bg-slate-900/40"><span class="text-slate-400 text-[9px] font-bold block mb-1 uppercase">صافي الربح (YER)</span><h3 class="text-sm font-black text-emerald-400 tabular-nums"><?php echo number_format($total_profit_yer_all, 2); ?></h3></div>
                <div class="glass-card p-4 border-r-4 border-purple-500 bg-slate-900/40"><span class="text-slate-400 text-[9px] font-bold block mb-1 uppercase italic">متوسط الشراء (WAC)</span><h3 class="text-sm font-black text-purple-400 tabular-nums"><?php echo number_format($avg_buy_price, 2); ?></h3></div>
                <div class="glass-card p-4 border-l-4 border-blue-500 bg-slate-900/40"><span class="text-[9px] text-blue-400 font-bold block italic uppercase">شراء اليوم (Vol)</span><h3 class="text-sm font-black tabular-nums"><?php echo number_format($daily_buy_vol, 2); ?></h3></div>
                <div class="glass-card p-4 border-l-4 border-green-500 bg-slate-900/40"><span class="text-[9px] text-green-400 font-bold block italic uppercase">بيع اليوم (Vol)</span><h3 class="text-sm font-black tabular-nums"><?php echo number_format($daily_sell_vol, 2); ?></h3></div>

                <div class="glass-card p-4 border-l-4 border-slate-600 bg-slate-900/40"><span class="text-[9px] text-slate-400 font-bold block italic uppercase">وارد اليوم (YER)</span><h3 class="text-sm font-black tabular-nums"><?php echo number_format($daily_in_money, 2); ?></h3></div>
                <div class="glass-card p-4 border-l-4 border-slate-600 bg-slate-900/40"><span class="text-[9px] text-slate-400 font-bold block italic uppercase">صادر اليوم (YER)</span><h3 class="text-sm font-black tabular-nums"><?php echo number_format($daily_out_money, 2); ?></h3></div>
                <div class="glass-card p-4 bg-emerald-500/10 border border-emerald-500/20"><span class="text-[9px] text-emerald-500 font-black uppercase mb-1 block">ربح اليوم (YER)</span><h3 class="text-sm font-black text-emerald-400 tabular-nums"><?php echo number_format($daily_profit_yer_val, 2); ?></h3></div>
                <div class="glass-card p-4 bg-rose-500/10 border border-rose-500/20"><span class="text-[9px] text-rose-500 font-black uppercase mb-1 block">رسوم اليوم (USDT)</span><h3 class="text-sm font-black text-rose-400 tabular-nums"><?php echo number_format($daily_fees_usdt_val, 2); ?></h3></div>
                <div class="glass-card p-4 bg-purple-500/10 border border-purple-500/20"><span class="text-[9px] text-purple-500 font-black uppercase mb-1 block">رسوم اليوم (YER)</span><h3 class="text-sm font-black text-purple-400 tabular-nums"><?php echo number_format($daily_fees_yer_val, 2); ?></h3></div>
            </div>

            <!-- قسم الرسم البياني داخل النافذة -->
            <div id="chart-section" class="bg-slate-900/60 p-6 rounded-xl border border-slate-800">
                <div class="flex flex-col sm:flex-row justify-between items-center mb-6 gap-4">
                    <h2 class="text-sm font-black text-slate-300 uppercase tracking-widest italic flex items-center gap-2">
                        <i data-lucide="bar-chart-3" class="text-emerald-500"></i> تحليل الأداء
                        <span class="text-[10px] text-slate-500 font-normal">(<?php echo strtoupper($range); ?>)</span>
                    </h2>

                    <div class="flex bg-slate-900/80 p-1 rounded border border-slate-700">
                        <a href="?range=day#reportsModal" class="px-3 py-1 text-[10px] font-bold rounded <?php echo $range=='day'?'bg-yellow-500 text-black':'text-slate-400 hover:text-white'; ?>">اليوم</a>
                        <a href="?range=week#reportsModal" class="px-3 py-1 text-[10px] font-bold rounded <?php echo $range=='week'?'bg-yellow-500 text-black':'text-slate-400 hover:text-white'; ?>">أسبوعي</a>
                        <a href="?range=month#reportsModal" class="px-3 py-1 text-[10px] font-bold rounded <?php echo $range=='month'?'bg-yellow-500 text-black':'text-slate-400 hover:text-white'; ?>">شهري</a>
                    </div>
                </div>

                <div class="overflow-x-auto custom-scrollbar pb-4">
                    <div id="chart-scroll-container" style="height: 300px; min-width: 100%;">
                        <canvas id="profitChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="mt-8 text-center"><button onclick="closeReportsModal()" class="bg-slate-800 text-white px-10 py-3 rounded-lg font-bold text-xs uppercase italic tracking-widest hover:bg-slate-700 transition">إغلاق النافذة</button></div>
        </div>
    </div>

        <div id="form-section" class="flex flex-col gap-8">
            <!-- قسم تسجيل عملية جديدة - يظهر أولاً -->
            <div class="w-full text-right">
                <div class="glass-card p-6 md:p-8 border-b-4 border-b-yellow-500 shadow-2xl max-w-4xl mx-auto">
                    <h2 class="text-lg font-bold mb-8 text-yellow-500 italic flex items-center gap-3"><i data-lucide="zap"></i> تسجيل عملية جديدة</h2>
                    <form id="ajax-form" class="space-y-5">
                        <div class="flex items-center gap-2 mb-4 p-3 bg-blue-500/5 border border-blue-500/20"><input type="checkbox" id="enable_backdate" class="w-4 h-4 accent-yellow-500 cursor-pointer" onchange="toggleDateInput()"><label for="enable_backdate" class="text-xs text-blue-400 font-bold cursor-pointer italic select-none">تأريخ يدوي؟</label></div>
                        <div id="date_container" style="display: none;" class="mb-4 animate-pulse"><input type="datetime-local" name="transaction_date" id="manual_date" class="input-dark text-yellow-500 border-yellow-500/30 font-bold"></div>
                        <div id="live_clock_display" class="bg-slate-900/40 p-3 border border-slate-700 flex justify-between items-center mb-4"><span class="text-[10px] text-slate-500 font-bold italic uppercase">توقيت اليمن</span><span id="clock" class="text-sm font-black text-yellow-500 tabular-nums">--:--:--</span></div>
                        <select name="type" id="typeSelect" onchange="handleTypeChange()" class="input-dark font-bold text-yellow-500 text-center cursor-pointer uppercase"><option value="buy">شراء (تستلم)</option><option value="sell">بيع (ترسل)</option></select>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-xs text-slate-400 mb-2 font-bold italic tracking-tighter uppercase">الكمية Net</label><input type="number" step="any" name="amount" id="crypto_amount_input" required class="input-dark text-xl font-bold tabular-nums text-center" placeholder="0.00"></div>
                            <div><label class="block text-xs text-slate-400 mb-2 font-bold italic tracking-tighter uppercase">السعر YER</label><input type="number" step="any" name="price" id="priceInput" value="<?php echo $def_buy; ?>" required class="input-dark text-xl font-black tabular-nums text-center"></div>
                        </div>
                        <div id="calc-preview" class="p-4 bg-slate-900/80 border border-slate-700 text-[11px] space-y-1 hidden">
                            <div class="flex justify-between"><span>الإجمالي قبل الخصم:</span> <span id="prev-gross" class="font-bold tabular-nums">0.00</span></div>
                            <div class="flex justify-between text-yellow-500 font-bold border-t border-slate-800 pt-1"><span>الدفع النهائي (YER):</span> <span id="prev-total-yer" class="tabular-nums font-black text-sm">0.00</span></div>
                        </div>
                        <div class="p-3 bg-yellow-500/5 border border-yellow-500/20 rounded"><label class="block text-[10px] text-yellow-500 font-bold uppercase italic mb-1 flex justify-between"><span>رسوم بينانس (USDT)</span> <span class="text-[8px] text-slate-500">تلقائي 0.1%</span></label><input type="number" step="any" name="binance_fee" id="binance_fee_input" class="input-dark text-sm font-bold text-yellow-500 tabular-nums text-center"></div>
                        <div id="manualFeeContainer" class="bg-slate-900/50 p-4 border border-dashed border-slate-700"><label class="block text-[10px] text-blue-400 mb-2 font-bold uppercase italic">رسوم صراف إضافية (YER)</label><input type="number" step="any" name="manual_fee" id="manual_fiat_fee" class="input-dark tabular-nums text-center" placeholder="0.00"></div>
                        <button type="submit" id="submit-btn" class="w-full btn-primary-glass py-4 flex justify-center items-center gap-2 font-black uppercase tracking-widest italic"><i data-lucide="save"></i> حفظ وتحديث</button>
                    </form>
                </div>
            </div>

            <!-- السجل المتسلسل - يظهر ثانياً في الأسفل -->
            <div class="w-full">
                <div class="glass-card flex flex-col h-[600px] md:h-[750px] shadow-2xl overflow-hidden max-w-4xl mx-auto">
                    <!-- رأس السجل المطور -->
                    <div class="p-4 border-b border-slate-700 bg-slate-800/40">
                        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 mb-4">
                            <h2 class="text-sm font-black text-slate-300 uppercase tracking-widest italic flex items-center gap-2"><i data-lucide="activity" class="text-blue-500"></i> السجل المتسلسل</h2>
                            <div class="flex bg-slate-900/60 p-1 rounded-lg border border-slate-700/50">
                                <button onclick="filterType('all')" id="btn-all" class="px-4 py-1 text-[10px] font-black rounded-md transition-all bg-blue-600 text-white shadow-lg">الكل</button>
                                <button onclick="filterType('buy')" id="btn-buy" class="px-4 py-1 text-[10px] font-bold rounded-md transition-all text-slate-400 hover:text-white">شراء</button>
                                <button onclick="filterType('sell')" id="btn-sell" class="px-4 py-1 text-[10px] font-bold rounded-md transition-all text-slate-400 hover:text-white">بيع</button>
                            </div>
                        </div>
                        <div class="relative group">
                            <i data-lucide="search" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 group-focus-within:text-blue-400 transition-colors"></i>
                            <input type="text" id="recordSearch" placeholder="بحث بالمبلغ، السعر، أو التاريخ..." class="w-full bg-slate-900/80 border border-slate-700 rounded-xl py-2.5 pr-10 pl-4 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500/50 focus:ring-4 focus:ring-blue-500/5 transition-all">
                        </div>
                    </div>

                    <!-- قائمة العمليات المفرزة -->
                    <div class="overflow-y-auto flex-grow custom-scrollbar p-4 space-y-4" id="transactions-container">
                        <!-- سيتم تعبئة البيانات بواسطة JS لضمان البحث الفوري والفلترة -->
                    </div>

                    <!-- زر عرض المزيد -->
                    <div id="loadMoreContainer" class="p-4 text-center border-t border-slate-800 bg-slate-800/20">
                        <button onclick="loadMore()" class="text-[10px] font-black text-blue-400 hover:text-blue-300 uppercase tracking-widest flex items-center gap-2 mx-auto transition-all active:scale-95">
                            <i data-lucide="chevron-down" class="w-4 h-4"></i> عرض المزيد من العمليات
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة الإعدادات -->
    <div id="settingsModal" class="hidden fixed inset-0 bg-black/95 flex items-center justify-center p-4 z-[999]">
        <div class="glass-card w-full max-w-sm p-8 border-2 border-yellow-500/30 shadow-2xl text-center text-right">
            <h2 class="text-xl font-black mb-8 text-yellow-500 flex items-center justify-center gap-3 italic uppercase tracking-widest underline decoration-yellow-500/20"><i data-lucide="cog"></i> الإعدادات</h2>
            <form action="update_settings.php" method="POST" class="space-y-6">
                <div><label class="block text-xs text-blue-400 mb-2 font-black uppercase italic tracking-widest">سعر الشراء الافتراضي</label><input type="number" step="any" name="default_buy_price" value="<?php echo $def_buy; ?>" class="input-dark text-2xl font-black text-center tabular-nums"></div>
                <div><label class="block text-xs text-green-400 mb-2 font-black uppercase italic tracking-widest">سعر البيع الافتراضي</label><input type="number" step="any" name="default_sell_price" value="<?php echo $def_sell; ?>" class="input-dark text-2xl font-black text-center tabular-nums"></div>
                <div class="flex gap-4 pt-4"><button type="submit" class="flex-1 btn-primary-glass py-4 font-black uppercase italic">حفظ</button><button type="button" onclick="document.getElementById('settingsModal').classList.add('hidden')" class="flex-1 bg-slate-800 py-4 text-xs font-black text-white uppercase italic">إغلاق</button></div>
            </form>
        </div>
    </div>

    <!-- نافذة التعديل -->
    <div id="editModal" class="hidden fixed inset-0 bg-black/95 flex items-center justify-center p-4 z-[999]">
        <div class="glass-card w-full max-w-md p-8 border-2 border-blue-500/30 shadow-2xl text-right">
            <h2 class="text-xl font-black mb-8 text-blue-400 flex items-center gap-3 italic uppercase underline tracking-widest"><i data-lucide="edit"></i> تعديل بيانات</h2>
            <form id="edit-ajax-form" class="space-y-6">
                <input type="hidden" name="id" id="edit_id">
                <div><label class="block text-xs text-slate-400 mb-2 font-black italic tracking-widest uppercase">تعديل التاريخ</label><input type="datetime-local" name="transaction_date" id="edit_date" required class="input-dark font-black text-yellow-500 border-yellow-500/20 tabular-nums text-center"></div>

                <div>
                    <label class="block text-xs text-slate-400 mb-2 font-black italic tracking-widest uppercase text-right">نوع العملية</label>
                    <select name="type" id="edit_type" class="input-dark font-bold text-yellow-500 text-center cursor-pointer uppercase">
                        <option value="buy">شراء (تستلم)</option>
                        <option value="sell">بيع (ترسل)</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-xs text-slate-400 mb-1 font-black">الكمية Net</label><input type="number" step="any" name="amount" id="edit_amount" required class="input-dark font-black tabular-nums text-center"></div>
                    <div><label class="block text-xs text-slate-400 mb-1 font-black">السعر YER</label><input type="number" step="any" name="price" id="edit_price" required class="input-dark font-black tabular-nums text-center"></div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-xs text-yellow-500 mb-2 font-black uppercase italic text-center">رسوم (USDT)</label><input type="number" step="any" name="binance_fee" id="edit_binance_fee" class="input-dark text-yellow-500 font-black tabular-nums text-center"></div>
                    <div id="editManualFeeContainer"><label class="block text-xs text-blue-400 mb-2 font-black uppercase italic text-center">رسوم صراف (YER)</label><input type="number" step="any" name="manual_fee" id="edit_manual_fee" class="input-dark text-blue-400 font-black tabular-nums text-center"></div>
                </div>

                <div class="flex gap-4 pt-4"><button type="submit" id="edit-submit-btn" class="flex-1 btn-primary-glass py-4 font-black text-white uppercase italic">تحديث</button><button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="flex-1 bg-slate-800 py-4 text-xs font-black text-white uppercase italic">تراجع</button></div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();
        const BUY_PRICE_DEF = <?php echo $def_buy; ?>;
        const SELL_PRICE_DEF = <?php echo $def_sell; ?>;

        function updateClock() { const clock = document.getElementById('clock'); if (clock) { clock.textContent = new Date().toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' }); } }
        setInterval(updateClock, 1000); updateClock();

        function showToast(msg) { const toast = document.getElementById('toast'); document.getElementById('toast-msg').textContent = msg; toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 4000); }

        function openReportsModal() { document.getElementById('reportsModal').classList.remove('hidden'); window.location.hash = "reportsModal"; }
        function closeReportsModal() { document.getElementById('reportsModal').classList.add('hidden'); history.pushState("", document.title, window.location.pathname + window.location.search); }

        function saveMainFormState() {
            localStorage.setItem('enable_backdate', document.getElementById('enable_backdate').checked);
            localStorage.setItem('manual_date', document.getElementById('manual_date').value);
            localStorage.setItem('type', typeSelect.value);
            localStorage.setItem('price', priceInput.value);
            localStorage.setItem('manual_fee', manualFiatInput.value);
        }

        function loadMainFormState() {
            const backdate = localStorage.getItem('enable_backdate') === 'true';
            const manualDate = localStorage.getItem('manual_date');
            const type = localStorage.getItem('type');
            const price = localStorage.getItem('price');
            const manualFee = localStorage.getItem('manual_fee');

            if (backdate) {
                document.getElementById('enable_backdate').checked = true;
                toggleDateInput();
            }
            if (manualDate) document.getElementById('manual_date').value = manualDate;
            if (type) {
                typeSelect.value = type;
                handleTypeChange();
            }
            if (price) priceInput.value = price;
            if (manualFee) manualFiatInput.value = manualFee;
            updateCalculations();
        }

        window.onload = function() {
            renderProfitChart();
            renderTransactions();
            loadMainFormState();
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('status') === 'success') { showToast("تم الحفظ بنجاح وتحديث ميزان الأرباح!"); window.history.replaceState({}, document.title, "index.php#form-section"); }
            if (urlParams.get('updated') === '1') { showToast("تم تحديث العملية بنجاح!"); window.history.replaceState({}, document.title, "index.php#form-section"); }
            if (urlParams.get('deleted') === '1') { showToast("تم حذف العملية بنجاح!"); window.history.replaceState({}, document.title, "index.php#form-section"); }
            if (window.location.hash === "#form-section") { document.getElementById('form-section').scrollIntoView({ behavior: 'smooth' }); }
            if (window.location.hash === "#reportsModal") { document.getElementById('reportsModal').classList.remove('hidden'); }
        }

        const ajaxForm = document.getElementById('ajax-form');
        ajaxForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('submit-btn'); btn.disabled = true; btn.innerHTML = '<i data-lucide="loader" class="animate-spin w-4 h-4"></i>'; lucide.createIcons();
            fetch('process.php', { method: 'POST', body: new FormData(this) })
            .then(res => res.json())
            .then(data => { if (data.status === 'success') { window.location.href="index.php?status=success#form-section"; window.location.reload(); } else { alert(data.message); btn.disabled = false; btn.innerText = "حفظ"; } });
        });

        const editAjaxForm = document.getElementById('edit-ajax-form');
        editAjaxForm.addEventListener('submit', function(e) {
            e.preventDefault();
            saveEditModalState();
            const btn = document.getElementById('edit-submit-btn'); btn.disabled = true; btn.innerHTML = '<i data-lucide="loader" class="animate-spin w-4 h-4"></i>'; lucide.createIcons();
            fetch('update.php', { method: 'POST', body: new FormData(this) })
            .then(res => { if (res.ok) { window.location.href="index.php?updated=1"; window.location.reload(); } else { alert("خطأ في التحديث"); btn.disabled = false; btn.innerText = "تحديث"; } });
        });

        const amountInput = document.getElementById('crypto_amount_input');
        const priceInput = document.getElementById('priceInput');
        const feeInput = document.getElementById('binance_fee_input');
        const manualFiatInput = document.getElementById('manual_fiat_fee');
        const typeSelect = document.getElementById('typeSelect');
        const calcPreview = document.getElementById('calc-preview');

        function updateCalculations() {
            const amount = parseFloat(amountInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            const type = typeSelect.value;
            const manualFiat = parseFloat(manualFiatInput.value) || 0;
            if (amount > 0) {
                calcPreview.classList.remove('hidden');
                if (type === 'buy') {
                    const gross = amount / 0.999;
                    const fee = gross - amount;
                    feeInput.value = fee.toFixed(2);
                    document.getElementById('prev-gross').textContent = gross.toFixed(4);
                    document.getElementById('prev-total-yer').textContent = ((gross * price) + manualFiat).toLocaleString();
                } else {
                    const fee = amount * 0.001;
                    feeInput.value = fee.toFixed(2);
                    document.getElementById('prev-gross').textContent = (amount + fee).toFixed(4);
                    document.getElementById('prev-total-yer').textContent = (amount * price).toLocaleString();
                }
            } else { calcPreview.classList.add('hidden'); feeInput.value = "0.00"; }
        }

        amountInput.addEventListener('input', updateCalculations);
        priceInput.addEventListener('input', () => { updateCalculations(); saveMainFormState(); });
        manualFiatInput.addEventListener('input', () => { updateCalculations(); saveMainFormState(); });
        typeSelect.addEventListener('change', () => { handleTypeChange(); updateCalculations(); saveMainFormState(); });
        document.getElementById('enable_backdate').addEventListener('change', saveMainFormState);
        document.getElementById('manual_date').addEventListener('input', saveMainFormState);

        function handleTypeChange() {
            typeSelect.value === 'sell' ? priceInput.value = SELL_PRICE_DEF : priceInput.value = BUY_PRICE_DEF;
            document.getElementById('manualFeeContainer').style.display = typeSelect.value === 'sell' ? 'none' : 'block';
        }

        function toggleDateInput() {
            const isChecked = document.getElementById('enable_backdate').checked;
            document.getElementById('date_container').style.display = isChecked ? 'block' : 'none';
            document.getElementById('live_clock_display').style.display = isChecked ? 'none' : 'block';
        }

        function saveEditModalState() {
            localStorage.setItem('edit_date', document.getElementById('edit_date').value);
            localStorage.setItem('edit_type', document.getElementById('edit_type').value);
            localStorage.setItem('edit_price', document.getElementById('edit_price').value);
            localStorage.setItem('edit_manual_fee', document.getElementById('edit_manual_fee').value);
        }

        function openEditModal(data) {
            document.getElementById('edit_id').value = data.id;

            const savedDate = localStorage.getItem('edit_date');
            const savedType = localStorage.getItem('edit_type');
            const savedPrice = localStorage.getItem('edit_price');
            const savedManualFee = localStorage.getItem('edit_manual_fee');

            document.getElementById('edit_type').value = savedType || data.type;
            document.getElementById('edit_amount').value = data.crypto_amount;
            document.getElementById('edit_price').value = savedPrice || data.price_per_unit;
            document.getElementById('edit_binance_fee').value = data.binance_fee;
            document.getElementById('edit_manual_fee').value = savedManualFee || data.manual_fee || 0;
            document.getElementById('editManualFeeContainer').style.display = document.getElementById('edit_type').value === 'sell' ? 'none' : 'block';

            if (savedDate) {
                document.getElementById('edit_date').value = savedDate;
            } else {
                // Formatting for datetime-local input (YYYY-MM-DDTHH:MM)
                // Created_at is "YYYY-MM-DD HH:MM:SS" in Yemen time
                let dt = data.created_at.replace(" ", "T").substring(0, 16);
                document.getElementById('edit_date').value = dt;
            }

            document.getElementById('editModal').classList.remove('hidden');
            updateEditCalculations();
        }

        function updateEditCalculations() {
            const amount = parseFloat(document.getElementById('edit_amount').value) || 0;
            const type = document.getElementById('edit_type').value;
            const feeInput = document.getElementById('edit_binance_fee');

            if (amount > 0) {
                if (type === 'buy') {
                    const gross = amount / 0.999;
                    feeInput.value = (gross - amount).toFixed(2);
                } else {
                    feeInput.value = (amount * 0.001).toFixed(2);
                }
            }
        }

        document.getElementById('edit_type').addEventListener('change', function() {
            document.getElementById('editManualFeeContainer').style.display = this.value === 'sell' ? 'none' : 'block';
            updateEditCalculations();
            saveEditModalState();
        });
        document.getElementById('edit_date').addEventListener('input', saveEditModalState);
        document.getElementById('edit_price').addEventListener('input', saveEditModalState);
        document.getElementById('edit_manual_fee').addEventListener('input', saveEditModalState);
        document.getElementById('edit_amount').addEventListener('input', updateEditCalculations);

        // --- نظام السجل المتطور ---
        const rawTransactions = <?php echo json_encode($transactions); ?>;
        let filteredTransactions = [...rawTransactions];
        let currentFilter = 'all';
        let itemsToShow = 20;

        function renderTransactions() {
            const container = document.getElementById('transactions-container');
            container.innerHTML = '';

            let lastDate = "";
            const searchTerm = document.getElementById('recordSearch').value.toLowerCase();

            const results = filteredTransactions.filter(t => {
                const matchesSearch = t.crypto_amount.toString().includes(searchTerm) ||
                                    t.price_per_unit.toString().includes(searchTerm) ||
                                    t.created_at.includes(searchTerm);
                const matchesType = currentFilter === 'all' || t.type === currentFilter;
                return matchesSearch && matchesType;
            });

            if (results.length === 0) {
                container.innerHTML = '<div class="text-center py-10 text-slate-500 font-bold italic">لا توجد نتائج مطابقة...</div>';
                document.getElementById('loadMoreContainer').style.display = 'none';
                return;
            }

            const visibleResults = results.slice(0, itemsToShow);

            visibleResults.forEach(t => {
                // استخدام وقت اليمن الصريح لضمان تطابق الفرز والواجهة مع الخادم
                const txDate = new Date(t.created_at + ' GMT+0300');
                const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'Asia/Aden' };
                const timeOptions = { hour: '2-digit', minute: '2-digit', hour12: true, timeZone: 'Asia/Aden' };

                const dateOnly = txDate.toLocaleDateString('ar-YE', dateOptions);

                if (dateOnly !== lastDate) {
                    container.innerHTML += `<div class="sticky top-0 z-10 bg-slate-900/90 backdrop-blur px-4 py-1.5 rounded-lg border border-slate-800 text-[10px] font-black text-blue-400 mt-6 mb-2 flex items-center gap-2"><i data-lucide="calendar" class="w-3 h-3"></i> ${dateOnly}</div>`;
                    lastDate = dateOnly;
                }

                const card = `
                    <div class="glass-card p-4 hover:bg-slate-800/40 transition-all border-r-4 ${t.type === 'buy' ? 'border-r-emerald-500' : 'border-r-rose-500'} group">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <div class="p-2 rounded-lg ${t.type === 'buy' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400'}">
                                    <i data-lucide="${t.type === 'buy' ? 'arrow-down-left' : 'arrow-up-right'}" class="w-4 h-4"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-black tabular-nums">${parseFloat(t.crypto_amount).toLocaleString()} <span class="text-[10px] opacity-50">USDT</span></p>
                                    <p class="text-[9px] text-slate-500 font-bold">${txDate.toLocaleTimeString('ar-YE', timeOptions)}</p>
                                </div>
                            </div>
                            <div class="text-left">
                                <p class="text-sm font-black text-white tabular-nums">${parseFloat(t.total_fiat_paid).toLocaleString()} <span class="text-[10px] text-slate-500">YER</span></p>
                                <p class="text-[9px] text-slate-500 italic">سعر الصرف: ${t.price_per_unit}</p>
                            </div>
                            <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onclick='openEditModal(${JSON.stringify(t)})' class="p-2 text-blue-400 hover:bg-blue-500/10 rounded-lg"><i data-lucide="edit-3" class="w-3.5 h-3.5"></i></button>
                                <a href="delete.php?id=${t.id}" onclick="return confirm('حذف؟')" class="p-2 text-rose-500 hover:bg-rose-500/10 rounded-lg"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></a>
                            </div>
                        </div>
                    </div>
                `;
                container.innerHTML += card;
            });

            lucide.createIcons();
            document.getElementById('loadMoreContainer').style.display = (itemsToShow >= results.length) ? 'none' : 'block';
        }

        function filterType(type) {
            currentFilter = type;
            itemsToShow = 20;
            ['all', 'buy', 'sell'].forEach(t => {
                const btn = document.getElementById('btn-' + t);
                btn.classList.remove('bg-blue-600', 'bg-emerald-600', 'bg-rose-600', 'text-white', 'shadow-lg');
                btn.classList.add('text-slate-400');
            });
            const activeBtn = document.getElementById('btn-' + type);
            let activeClass = 'bg-blue-600';
            if (type === 'buy') activeClass = 'bg-emerald-600';
            if (type === 'sell') activeClass = 'bg-rose-600';

            activeBtn.classList.add(activeClass, 'text-white', 'shadow-lg');
            activeBtn.classList.remove('text-slate-400');
            renderTransactions();
        }

        function loadMore() {
            itemsToShow += 20;
            renderTransactions();
        }

        document.getElementById('recordSearch').addEventListener('input', () => {
            itemsToShow = 20;
            renderTransactions();
        });

function renderProfitChart() {
    const canvas = document.getElementById('profitChart');
    if(!canvas) return;

    const labels = <?php echo json_encode($chart_labels); ?>;
    const dataValues = <?php echo json_encode($chart_values); ?>;
    const scrollContainer = document.getElementById('chart-scroll-container');

    // --- حساب العرض الديناميكي لمنع الزحام ---
    // سنعطي كل نقطة مساحة 50 بكسل على الأقل
    const minPointWidth = 50; 
    const calculatedWidth = labels.length * minPointWidth;
    const parentWidth = canvas.parentElement.parentElement.offsetWidth;
    
    // إذا كان العرض المحسوب أكبر من عرض الشاشة، نقوم بتوسيع الحاوية
    const finalWidth = Math.max(parentWidth, calculatedWidth);
    scrollContainer.style.width = finalWidth + 'px';

    if (window.myProfitChart) { window.myProfitChart.destroy(); }

    window.myProfitChart = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'نمو الأرباح',
                data: dataValues,
                segment: {
                    borderColor: ctx => ctx.p0.parsed.y > ctx.p1.parsed.y ? '#f43f5e' : '#10b981',
                },
                backgroundColor: 'rgba(16, 185, 129, 0.05)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointRadius: 4,
                pointBackgroundColor: '#eab308',
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { legend: { display: false } },
            scales: { 
                y: { 
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: '#64748b', font: { size: 10 } }
                },
                x: { 
                    grid: { display: false }, 
                    ticks: { 
                        color: '#64748b', 
                        font: { size: 9 },
                        maxRotation: 0, // منع دوران النصوص لسهولة القراءة
                        autoSkip: false // إظهار كل النقاط طالما يوجد تمرير
                    }
                }
            }
        }
    });

    // تحريك التمرير لليسار (آخر العمليات) تلقائياً عند التحميل
    setTimeout(() => {
        canvas.parentElement.parentElement.scrollLeft = 0; 
    }, 100);
}
        handleTypeChange();
    </script>
</body>
</html>