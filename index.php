<?php 
/**
 * نظام LEDGER PRO - النسخة الإمبراطورية الكاملة
 * 12 بطاقة إحصائية - رسم بياني ذكي - أتمتة شاملة - دقة بينانس
 */

session_start();
require_once 'db.php'; 
require_once 'fifo_helper.php';

// 1. ضبط التوقيت لليمن (GMT+3) لضمان دقة العمليات الحالية واليومية
// (مضبوط الآن في db.php)
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
$settings_stmt = $pdo->prepare("SELECT * FROM settings WHERE user_id = ?");
$settings_stmt->execute([$user_id]);
$settings = $settings_stmt->fetch();
$def_buy = $settings['default_buy_price'] ?? 535;
$def_sell = $settings['default_sell_price'] ?? 540;
$api_key = $settings['binance_api_key'] ?? '';
$api_secret = $settings['binance_api_secret'] ?? '';
$fetch_limit = $settings['binance_fetch_limit'] ?? 10;

// أ. حساب الأرباح التراكمية (YER / USD) بناءً على FIFO
$profit_stmt = $pdo->prepare("SELECT SUM(fifo_profit) as net_profit FROM transactions WHERE user_id = ? AND type='sell'");
$profit_stmt->execute([$user_id]);
$total_profit_yer_all = $profit_stmt->fetchColumn() ?: 0;
$total_profit_usd_all = ($def_buy > 0) ? ($total_profit_yer_all / $def_buy) : 0;

// جلب النطاق الزمني المختار (الافتراضي هو 'day')
$range = $_GET['range'] ?? 'day';

// تحديد نطاق التاريخ للاستعلامات باستخدام نطاقات زمنية (Range) لتحسين استخدام الفهارس
$range_label = "اليوم";
$start_ts = $today . ' 00:00:00';
$end_ts = $today . ' 23:59:59';

if ($range === 'yesterday') {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $start_ts = $yesterday . ' 00:00:00';
    $end_ts = $yesterday . ' 23:59:59';
    $range_label = "الأمس";
} else if ($range === 'week') {
    $start_ts = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
    $end_ts = $today . ' 23:59:59';
    $range_label = "آخر 7 أيام";
} else if ($range === 'month') {
    $start_ts = date('Y-m-d', strtotime('-29 days')) . ' 00:00:00';
    $end_ts = $today . ' 23:59:59';
    $range_label = "آخر 30 يوم";
}

$date_condition = "created_at >= ? AND created_at <= ?";
$date_params = [$start_ts, $end_ts];

// ب. حساب حجم التداول للنطاق المختار (Volume)
$daily_buy_vol_stmt = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type = 'buy' AND $date_condition");
$daily_buy_vol_stmt->execute(array_merge([$user_id], $date_params));
$daily_buy_vol = $daily_buy_vol_stmt->fetchColumn() ?: 0;

$daily_sell_vol_stmt = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type = 'sell' AND $date_condition");
$daily_sell_vol_stmt->execute(array_merge([$user_id], $date_params));
$daily_sell_vol = $daily_sell_vol_stmt->fetchColumn() ?: 0;

// ج. حساب مبالغ السيولة النقدية للنطاق المختار (YER)
$daily_in_money_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid) FROM transactions WHERE user_id = ? AND type = 'buy' AND $date_condition");
$daily_in_money_stmt->execute(array_merge([$user_id], $date_params));
$daily_in_money = $daily_in_money_stmt->fetchColumn() ?: 0;

$daily_out_money_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid) FROM transactions WHERE user_id = ? AND type = 'sell' AND $date_condition");
$daily_out_money_stmt->execute(array_merge([$user_id], $date_params));
$daily_out_money = $daily_out_money_stmt->fetchColumn() ?: 0;

// د. حساب المخزون المتوفر (Stock)
$total_in = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type='buy'");
$total_in->execute([$user_id]);
$sum_in = $total_in->fetchColumn() ?: 0;

$total_out = $pdo->prepare("SELECT SUM(total_crypto_deducted) FROM transactions WHERE user_id = ? AND type='sell'");
$total_out->execute([$user_id]);
$sum_out = $total_out->fetchColumn() ?: 0;
$remaining_stock = $sum_in - $sum_out;

// هـ. حسابات النطاق المختار (أرباح ورسوم) بدقة بناءً على FIFO
$daily_profit_stmt = $pdo->prepare("SELECT SUM(fifo_profit) FROM transactions WHERE user_id = ? AND type = 'sell' AND $date_condition");
$daily_profit_stmt->execute(array_merge([$user_id], $date_params));
$daily_profit_yer_val = $daily_profit_stmt->fetchColumn() ?: 0;
$daily_profit_usd_val = ($def_buy > 0) ? ($daily_profit_yer_val / $def_buy) : 0;

$daily_fees_stmt = $pdo->prepare("SELECT SUM(binance_fee) FROM transactions WHERE user_id = ? AND $date_condition");
$daily_fees_stmt->execute(array_merge([$user_id], $date_params));
$daily_fees_usdt_val = $daily_fees_stmt->fetchColumn() ?: 0;
$daily_fees_yer_val = $daily_fees_usdt_val * $def_buy;

// و. جلب بيانات الرسم البياني (منفصلة تماماً حسب النوع)
$chart_labels = [];
$chart_values = [];
$cumulative_profit = 0;

if ($range === 'day') {
    // وضع اليوم: عمليات البيع لهذا اليوم مرتبة زمنياً (بحد أقصى 50 عملية)
    $stmt = $pdo->prepare("SELECT created_at, fifo_profit as op_profit FROM transactions WHERE user_id = ? AND type = 'sell' AND created_at >= ? AND created_at <= ? ORDER BY id ASC LIMIT 50");
    $stmt->execute([$user_id, $today . ' 00:00:00', $today . ' 23:59:59']);
    $data_rows = $stmt->fetchAll();
    
    foreach ($data_rows as $data) {
        $cumulative_profit += (float)$data['op_profit'];
        $chart_labels[] = date('h:i A', strtotime($data['created_at']));
        $chart_values[] = round($cumulative_profit, 2);
    }
} else if ($range === 'pulse') {
    // وضع النبض: آخر 30 عملية بيع فردية مرتبة زمنياً (بغض النظر عن اليوم)
    $stmt = $pdo->prepare("SELECT created_at, fifo_profit as op_profit FROM transactions WHERE user_id = ? AND type = 'sell' ORDER BY id DESC LIMIT 30");
    $stmt->execute([$user_id]);
    $data_rows = array_reverse($stmt->fetchAll());

    foreach ($data_rows as $data) {
        $cumulative_profit += (float)$data['op_profit'];
        $chart_labels[] = date('h:i A', strtotime($data['created_at']));
        $chart_values[] = round($cumulative_profit, 2);
    }
} else if ($range === 'yesterday') {
    // وضع الأمس: عمليات البيع للأمس مرتبة زمنياً
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare("SELECT created_at, fifo_profit as op_profit FROM transactions WHERE user_id = ? AND type = 'sell' AND created_at >= ? AND created_at <= ? ORDER BY id ASC LIMIT 50");
    $stmt->execute([$user_id, $yesterday . ' 00:00:00', $yesterday . ' 23:59:59']);
    $data_rows = $stmt->fetchAll();

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
        
        $day_stmt = $pdo->prepare("SELECT SUM(fifo_profit) FROM transactions WHERE user_id = ? AND type = 'sell' AND created_at >= ? AND created_at <= ?");
        $day_stmt->execute([$user_id, $current_d . ' 00:00:00', $current_d . ' 23:59:59']);
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
        <div class="flex items-center gap-3">
            <?php if(isset($_SESSION['admin_user_id'])): ?>
                <a href="admin.php?action=return_to_admin" class="flex items-center gap-2 bg-blue-500/10 hover:bg-blue-500 text-blue-500 hover:text-white px-3 md:px-5 py-2 rounded text-[10px] md:text-xs font-black border border-blue-500/20 transition-all">
                    <i data-lucide="undo-2" class="w-3.5 h-3.5"></i> العودة للإدارة
                </a>
            <?php endif; ?>
            <a href="logout.php" onclick="return confirm('خروج؟')" class="flex items-center gap-2 bg-rose-500/10 hover:bg-rose-500 text-rose-500 hover:text-white px-3 md:px-5 py-2 rounded text-[10px] md:text-xs font-black border border-rose-500/20 transition-all"><span>خروج آمن</span> <i data-lucide="log-out" class="w-3.5 h-3.5 md:w-4 md:h-4"></i></a>
        </div>
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
                <button onclick="window.location.reload()" class="glass-card bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-500 px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 transition-all border border-emerald-500/20 active:scale-95" title="تحديث البيانات">
                    <i data-lucide="refresh-ccw" class="w-3.5 h-3.5"></i> تحديث
                </button>
                <?php if ($api_key && $api_secret): ?>
                <button onclick="openBinanceModal()" class="glass-card bg-yellow-500/10 hover:bg-yellow-500/20 text-yellow-500 px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 transition-all border border-yellow-500/20 active:scale-95">
                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> جلب من بينانس
                </button>
                <?php endif; ?>
                <a href="reports.php" class="glass-card bg-white/5 hover:bg-white/10 text-white px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 transition-all border border-white/10 active:scale-95">
                    <i data-lucide="calendar-days" class="w-3.5 h-3.5 text-blue-400"></i> الأرشيف والتحليل
                </a>
                <button onclick="openReportsModal()" class="glass-card bg-white/5 hover:bg-white/10 text-white px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 transition-all border border-white/10 active:scale-95">
                    <i data-lucide="layout-dashboard" class="w-3.5 h-3.5 text-emerald-400"></i> عرض التقارير
                </button>
            </div>
        </header>

        <!-- الشريط الذكي (Smart Banner) -->
        <?php
        $next_layer = getNextFIFOLayer($pdo, $user_id);
        if($next_layer):
            $layer_cost = $next_layer['unit_cost'];
            $break_even_price = $layer_cost * 1.001;
            $recommended_price = $layer_cost * 1.007; // ربح متوسط 0.7%
            $best_price = $layer_cost * 1.012; // ربح ممتاز 1.2%
        ?>
        <div class="glass-card p-4 mb-6 border-l-4 border-blue-500 bg-blue-500/5">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-500/10 flex items-center justify-center border border-blue-500/20">
                        <i data-lucide="zap" class="text-blue-500 w-5 h-5 animate-pulse"></i>
                    </div>
                    <div>
                        <p class="text-[10px] text-blue-400 font-bold uppercase tracking-widest mb-1">مستشار التداول الذكي</p>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            <p class="text-xs font-black text-white italic tabular-nums">سعر التعادل: <span class="text-slate-400"><?php echo number_format($break_even_price, 2); ?></span></p>
                            <p class="text-xs font-black text-emerald-400 italic tabular-nums">سعر التوصية: <span class="text-white"><?php echo number_format($recommended_price, 2); ?></span></p>
                            <p class="text-xs font-black text-yellow-500 italic tabular-nums">أفضل سعر بيع: <span class="text-white"><?php echo number_format($best_price, 2); ?></span></p>
                        </div>
                    </div>
                </div>
                <button onclick="openProfitCalculator()" class="bg-blue-600/20 hover:bg-blue-600 text-blue-400 hover:text-white p-2 rounded-lg transition-all" title="محاكي الأرباح">
                    <i data-lucide="help-circle" class="w-5 h-5"></i>
                </button>
            </div>
        </div>

        <!-- نافذة محاكي الأرباح المنبثقة -->
        <div id="profitCalcModal" class="hidden fixed inset-0 bg-black/95 flex items-center justify-center p-4 z-[600]">
            <div class="glass-card w-full max-w-md p-8 border-2 border-blue-500/30 shadow-2xl text-right">
                <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-800">
                    <h2 class="text-lg font-black text-blue-400 flex items-center gap-3 italic uppercase tracking-widest"><i data-lucide="calculator"></i> محاكي الأرباح المتوقعة</h2>
                    <button onclick="closeProfitCalculator()" class="bg-slate-800 p-2 rounded-lg text-white hover:bg-rose-500 transition"><i data-lucide="x" class="w-4 h-4"></i></button>
                </div>

                <p class="text-xs text-slate-500 mb-6 font-bold italic leading-relaxed">
                    بناءً على مخزونك الحالي (<span class="text-yellow-500"><?php echo number_format($remaining_stock, 2); ?> USDT</span>) ومتوسط شراء (<span class="text-purple-400"><?php echo number_format($avg_buy_price, 2); ?> YER</span>)، إليك الأرباح الصافية المتوقعة عند البيع بأسعار مختلفة:
                </p>

                <div class="space-y-4">
                    <div class="bg-slate-900/60 p-4 rounded-xl border border-slate-800">
                        <p class="text-[10px] text-slate-500 font-bold uppercase mb-1">بالسعر الافتراضي للإعدادات (<?php echo number_format($def_sell, 2); ?>)</p>
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-black <?php echo ($def_sell > $break_even_price) ? 'text-emerald-500' : 'text-rose-500'; ?> tabular-nums"><?php echo number_format(($remaining_stock * $def_sell) - ($layer_cost * ($remaining_stock * 1.001))); ?> YER</span>
                            <span class="text-[10px] text-slate-500">صافي الربح</span>
                        </div>
                    </div>
                    <div class="bg-emerald-500/5 p-4 rounded-xl border border-emerald-500/20">
                        <p class="text-[10px] text-emerald-500 font-bold uppercase mb-1 tracking-widest">بسعر التوصية (<?php echo number_format($recommended_price, 2); ?>)</p>
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-black text-emerald-400 tabular-nums"><?php echo number_format(($remaining_stock * $recommended_price) - ($layer_cost * ($remaining_stock * 1.001))); ?> YER</span>
                            <span class="text-[10px] text-emerald-600 font-bold">ربح متوسط (0.7%)</span>
                        </div>
                    </div>
                    <div class="bg-yellow-500/5 p-4 rounded-xl border border-yellow-500/20">
                        <p class="text-[10px] text-yellow-500 font-bold uppercase mb-1 tracking-widest">بأفضل سعر بيع (<?php echo number_format($best_price, 2); ?>)</p>
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-black text-yellow-400 tabular-nums"><?php echo number_format(($remaining_stock * $best_price) - ($layer_cost * ($remaining_stock * 1.001))); ?> YER</span>
                            <span class="text-[10px] text-yellow-600 font-bold">ربح ممتاز (1.2%)</span>
                        </div>
                    </div>
                </div>

                <div class="mt-8 text-center"><button onclick="closeProfitCalculator()" class="w-full bg-slate-800 text-white py-4 rounded-xl font-bold text-xs uppercase italic tracking-widest hover:bg-slate-700 transition">فهمت ذلك</button></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- الصف الرئيسي: المخزون وربح اليوم -->
        <div class="grid grid-cols-2 gap-4 mb-10">
            <div class="glass-card p-5 border-r-4 border-yellow-500 shadow-xl">
                <span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase italic tracking-tighter">المخزون المتوفر (Stock)</span>
                <h3 class="text-lg md:text-xl font-black text-yellow-500 tabular-nums"><?php echo number_format($remaining_stock, 2); ?></h3>
                <p class="text-[9px] text-slate-500 font-bold mt-1">تكلفة الطبقة الحالية: <?php echo number_format($layer_cost ?? 0, 1); ?></p>
            </div>
            <div class="glass-card p-5 bg-blue-500/10 border border-blue-500/20">
                <span class="text-[9px] text-blue-500 font-black uppercase mb-1 block">ربح اليوم</span>
                <h3 class="text-lg font-black text-blue-400 tabular-nums">$<?php echo number_format($daily_profit_usd_val, 2); ?></h3>
                <p class="text-[9px] text-blue-500/70 font-bold mt-1"><?php echo number_format($daily_profit_yer_val); ?> YER</p>
            </div>
        </div>

    <!-- نافذة التقارير المنبثقة -->
    <div id="reportsModal" class="hidden fixed inset-0 bg-black/95 flex items-start md:items-center justify-center p-2 md:p-4 z-[500] overflow-y-auto">
        <div class="glass-card w-full max-w-5xl p-4 md:p-10 border-2 border-emerald-500/30 shadow-2xl my-4 md:my-auto">
            <div class="flex justify-between items-center mb-6 md:mb-8 pb-4 border-b border-slate-800">
                <h2 class="text-lg md:text-xl font-black text-emerald-500 flex items-center gap-3 italic uppercase tracking-widest"><i data-lucide="bar-chart-horizontal"></i> الإحصائيات التفصيلية</h2>
                <button onclick="closeReportsModal()" class="bg-slate-800 p-2 rounded-lg text-white hover:bg-rose-500 transition"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>

            <!-- شبكة البطاقات داخل النافذة -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-8">
                <!-- بطاقة الربح العام (ثابتة) -->
                <div class="glass-card p-4 border-r-4 border-blue-500 bg-slate-900/40">
                    <span class="text-slate-400 text-[9px] font-bold block mb-1 uppercase">صافي الربح العام</span>
                    <h3 class="text-sm font-black text-blue-400 tabular-nums">$<?php echo number_format($total_profit_usd_all, 2); ?></h3>
                    <p class="text-[10px] text-slate-500"><?php echo number_format($total_profit_yer_all); ?> YER</p>
                </div>

                <!-- بطاقة متوسط الشراء (ثابتة) -->
                <div class="glass-card p-4 border-r-4 border-purple-500 bg-slate-900/40">
                    <span class="text-slate-400 text-[9px] font-bold block mb-1 uppercase italic">تكلفة الطبقة الحالية</span>
                    <h3 class="text-sm font-black text-purple-400 tabular-nums"><?php echo number_format($layer_cost ?? 0, 2); ?></h3>
                    <p class="text-[10px] text-slate-500 italic">بناءً على نظام FIFO</p>
                </div>

                <!-- بطاقة ربح الفترة (متغيرة) -->
                <div class="glass-card p-4 border-r-4 border-emerald-500 bg-emerald-500/5">
                    <span class="text-emerald-500 text-[9px] font-black block mb-1 uppercase">ربح فترة (<?php echo $range_label; ?>)</span>
                    <h3 class="text-sm font-black text-emerald-400 tabular-nums"><?php echo number_format($daily_profit_yer_val); ?> <span class="text-[9px]">YER</span></h3>
                    <p class="text-[10px] text-emerald-500/70 font-bold">$<?php echo number_format($daily_profit_usd_val, 2); ?></p>
                </div>

                <!-- بطاقة رسوم الفترة (متغيرة) -->
                <div class="glass-card p-4 border-r-4 border-rose-500 bg-rose-500/5">
                    <span class="text-rose-500 text-[9px] font-black block mb-1 uppercase">رسوم فترة (<?php echo $range_label; ?>)</span>
                    <h3 class="text-sm font-black text-rose-400 tabular-nums"><?php echo number_format($daily_fees_usdt_val, 2); ?> <span class="text-[9px]">USDT</span></h3>
                    <p class="text-[10px] text-rose-500/70 font-bold"><?php echo number_format($daily_fees_yer_val); ?> YER</p>
                </div>

                <!-- بطاقة رصيد بينانس -->
                <div id="binance_balance_card" class="glass-card p-4 border-r-4 border-yellow-500 bg-yellow-500/5 col-span-2 md:col-span-2 hidden">
                    <div class="flex justify-between items-start mb-1">
                        <span class="text-yellow-500 text-[9px] font-black block uppercase">رصيد بينانس المتاح</span>
                        <div class="animate-pulse bg-yellow-500/20 h-2 w-2 rounded-full" id="balance_loader"></div>
                    </div>
                    <div class="flex justify-between items-end">
                        <div>
                            <h3 class="text-xl font-black text-yellow-400 tabular-nums" id="binance_usdt_val">0.00</h3>
                            <p class="text-[10px] text-slate-500">USDT (Spot + Funding)</p>
                        </div>
                        <div class="text-left">
                            <div class="flex gap-2">
                                <button onclick="showBalanceJson()" class="text-slate-600 hover:text-yellow-500 transition-colors" title="Show JSON">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                                    </svg>
                                </button>
                                <button onclick="fetchBinanceBalance()" class="text-slate-500 hover:text-yellow-500 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- بطاقة شراء الفترة (مدمجة) -->
                <div class="glass-card p-4 border-l-4 border-blue-500 bg-blue-500/5 col-span-2 md:col-span-2">
                    <span class="text-blue-400 text-[9px] font-black block mb-1 uppercase italic">إجمالي الشراء (<?php echo $range_label; ?>)</span>
                    <div class="flex justify-between items-end">
                        <div>
                            <p class="text-[10px] text-slate-500 mb-0.5">الكمية المستلمة:</p>
                            <h3 class="text-base font-black text-white tabular-nums"><?php echo number_format($daily_buy_vol, 2); ?> <span class="text-xs opacity-50">USDT</span></h3>
                        </div>
                        <div class="text-left">
                            <p class="text-[10px] text-slate-500 mb-0.5">وارد (المبلغ المدفوع):</p>
                            <h3 class="text-base font-black text-blue-400 tabular-nums"><?php echo number_format($daily_in_money); ?> <span class="text-xs opacity-50">YER</span></h3>
                        </div>
                    </div>
                </div>

                <!-- بطاقة بيع الفترة (مدمجة) -->
                <div class="glass-card p-4 border-l-4 border-green-500 bg-green-500/5 col-span-2 md:col-span-2">
                    <span class="text-green-400 text-[9px] font-black block mb-1 uppercase italic">إجمالي البيع (<?php echo $range_label; ?>)</span>
                    <div class="flex justify-between items-end">
                        <div>
                            <p class="text-[10px] text-slate-500 mb-0.5">الكمية المرسلة:</p>
                            <h3 class="text-base font-black text-white tabular-nums"><?php echo number_format($daily_sell_vol, 2); ?> <span class="text-xs opacity-50">USDT</span></h3>
                        </div>
                        <div class="text-left">
                            <p class="text-[10px] text-slate-500 mb-0.5">صادر (المبلغ المستلم):</p>
                            <h3 class="text-base font-black text-green-400 tabular-nums"><?php echo number_format($daily_out_money); ?> <span class="text-xs opacity-50">YER</span></h3>
                        </div>
                    </div>
                </div>
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
                        <a href="?range=yesterday#reportsModal" class="px-3 py-1 text-[10px] font-bold rounded <?php echo $range=='yesterday'?'bg-yellow-500 text-black':'text-slate-400 hover:text-white'; ?>">الأمس</a>
                        <a href="?range=week#reportsModal" class="px-3 py-1 text-[10px] font-bold rounded <?php echo $range=='week'?'bg-yellow-500 text-black':'text-slate-400 hover:text-white'; ?>">أسبوعي</a>
                        <a href="?range=month#reportsModal" class="px-3 py-1 text-[10px] font-bold rounded <?php echo $range=='month'?'bg-yellow-500 text-black':'text-slate-400 hover:text-white'; ?>">شهري</a>
                    </div>
                </div>

                <div class="overflow-x-auto custom-scrollbar pb-4">
                    <div id="chart-scroll-container" style="height: 300px; min-width: 100%;">
                        <canvas id="profitChart"></canvas>
                    </div>
                </div>
                <p class="text-center text-[10px] text-slate-500 mt-2 italic font-bold">ملاحظة: الرسم البياني يوضح تراكم الأرباح خلال النطاق الزمني المختار</p>
            </div>

            <!-- قسم شفافية الأرباح -->
            <div class="mt-8 pt-8 border-t border-slate-800">
                <h3 class="text-sm font-black text-blue-400 mb-4 flex items-center gap-2 italic uppercase"><i data-lucide="help-circle"></i> كيف يتم احتساب الأرباح؟</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-900/40 p-6 rounded-xl border border-slate-800/50">
                    <div>
                        <p class="text-xs text-slate-400 font-bold mb-2">المعادلة المستخدمة:</p>
                        <div class="bg-black/30 p-4 rounded-lg font-mono text-[11px] text-emerald-500 text-left dir-ltr">
                            Profit = SellFiat - matched_FIFO_BuyCost
                        </div>
                    </div>
                    <ul class="text-[11px] text-slate-500 space-y-2">
                        <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-emerald-500 mt-0.5"></i> يتم حساب الربح بمطابقة كمية البيع مع أقدم كميات شراء متوفرة (First-In, First-Out).</li>
                        <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-emerald-500 mt-0.5"></i> التكلفة تشمل سعر الشراء + رسوم بينانس + أي رسوم صراف إضافية.</li>
                        <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-emerald-500 mt-0.5"></i> هذا النظام يضمن دقة الأرباح حتى عند تقلب الأسعار بشكل كبير.</li>
                    </ul>
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
                        <input type="hidden" name="binance_order_id" id="form_binance_order_id">
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
        <div class="glass-card w-full max-w-md p-8 border-2 border-yellow-500/30 shadow-2xl text-right overflow-y-auto max-h-[90vh]">
            <h2 class="text-xl font-black mb-8 text-yellow-500 flex items-center justify-center gap-3 italic uppercase tracking-widest underline decoration-yellow-500/20"><i data-lucide="cog"></i> الإعدادات</h2>
            <form action="update_settings.php" method="POST" class="space-y-6">
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-[10px] text-blue-400 mb-2 font-black uppercase italic tracking-widest text-center">سعر الشراء الافتراضي</label><input type="number" step="any" name="default_buy_price" value="<?php echo $def_buy; ?>" class="input-dark text-lg font-black text-center tabular-nums"></div>
                    <div><label class="block text-[10px] text-green-400 mb-2 font-black uppercase italic tracking-widest text-center">سعر البيع الافتراضي</label><input type="number" step="any" name="default_sell_price" value="<?php echo $def_sell; ?>" class="input-dark text-lg font-black text-center tabular-nums"></div>
                </div>

                <div class="pt-4 border-t border-slate-800">
                    <h3 class="text-xs font-black text-yellow-500 mb-4 uppercase italic">إعدادات Binance API</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-[10px] text-slate-400 mb-2 font-black uppercase italic">API Key</label>
                            <input type="text" name="binance_api_key" value="<?php echo htmlspecialchars($api_key); ?>" class="input-dark text-xs tabular-nums" placeholder="أدخل API Key">
                        </div>
                        <div>
                            <label class="block text-[10px] text-slate-400 mb-2 font-black uppercase italic">API Secret</label>
                            <input type="password" name="binance_api_secret" value="<?php echo $api_secret ? '********' : ''; ?>" class="input-dark text-xs tabular-nums" placeholder="أدخل API Secret">
                        </div>
                        <div>
                            <label class="block text-[10px] text-slate-400 mb-2 font-black uppercase italic">عدد العمليات للجلب</label>
                            <input type="number" name="binance_fetch_limit" value="<?php echo $fetch_limit; ?>" class="input-dark text-sm text-center tabular-nums">
                        </div>
                    </div>
                </div>

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
                <input type="hidden" name="binance_order_id" id="edit_binance_order_id">
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

    <!-- نافذة تأكيد الإدراج السريع -->
    <div id="importConfirmModal" class="hidden fixed inset-0 bg-black/98 flex items-center justify-center p-4 z-[1010] animate-in fade-in zoom-in duration-200">
        <div class="glass-card w-full max-w-md p-6 border-2 border-yellow-500/30 shadow-2xl text-right">
            <div class="flex justify-between items-center mb-6 pb-2 border-b border-slate-800">
                <h3 class="text-base font-black text-yellow-500 uppercase tracking-widest italic flex items-center gap-2">
                    <i data-lucide="check-square" class="w-5 h-5"></i> تأكيد إدراج العملية
                </h3>
                <button onclick="document.getElementById('importConfirmModal').classList.add('hidden')" class="text-slate-500 hover:text-white transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form id="import-confirm-form" class="space-y-5">
                <input type="hidden" name="binance_order_id" id="confirm_order_id">
                <input type="hidden" name="type" id="confirm_type">
                <input type="hidden" name="transaction_date" id="confirm_date">

                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-slate-900/60 p-3 rounded-lg border border-slate-800">
                        <label class="block text-[8px] text-slate-500 uppercase font-black mb-1">النوع</label>
                        <div id="display_type" class="text-sm font-black uppercase">---</div>
                    </div>
                    <div class="bg-slate-900/60 p-3 rounded-lg border border-slate-800">
                        <label class="block text-[8px] text-slate-500 uppercase font-black mb-1">الكمية Net</label>
                        <div id="display_amount" class="text-sm font-black tabular-nums">0.00</div>
                        <input type="hidden" name="amount" id="confirm_amount">
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-2 font-black italic tracking-widest uppercase">السعر YER</label>
                    <input type="number" step="any" name="price" id="confirm_price" required class="input-dark text-xl font-black text-yellow-500 border-yellow-500/20 tabular-nums text-center focus:ring-4 ring-yellow-500/10">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] text-yellow-500 mb-1.5 font-black uppercase italic">رسوم (USDT)</label>
                        <input type="number" step="any" name="binance_fee" id="confirm_binance_fee" class="input-dark text-sm font-bold text-yellow-500/80 tabular-nums text-center">
                    </div>
                    <div id="confirmManualFeeContainer">
                        <label class="block text-[10px] text-blue-400 mb-1.5 font-black uppercase italic">رسوم صراف (YER)</label>
                        <input type="number" step="any" name="manual_fee" id="confirm_manual_fee" class="input-dark text-sm font-bold text-blue-400/80 tabular-nums text-center">
                    </div>
                </div>

                <div class="pt-4 flex gap-3">
                    <button type="submit" id="confirm-submit-btn" class="flex-1 btn-primary-glass py-4 font-black uppercase italic shadow-lg shadow-indigo-500/20">
                        <i data-lucide="plus-circle" class="w-4 h-4 inline ml-1"></i> إدراج وحفظ
                    </button>
                    <button type="button" onclick="document.getElementById('importConfirmModal').classList.add('hidden')" class="flex-1 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white py-4 text-xs font-black uppercase italic rounded-xl transition-all">
                        تراجع
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- نافذة عرض JSON الخام -->
    <div id="jsonModal" class="hidden fixed inset-0 bg-black/98 flex items-center justify-center p-4 z-[1001]">
        <div class="glass-card w-full max-w-lg p-6 border-2 border-blue-500/30 shadow-2xl text-left flex flex-col max-h-[80vh]">
            <div class="flex justify-between items-center mb-4 pb-2 border-b border-slate-800">
                <h3 class="text-xs font-black text-blue-400 uppercase tracking-widest italic">Binance Raw Data (JSON)</h3>
                <button onclick="document.getElementById('jsonModal').classList.add('hidden')" class="text-slate-500 hover:text-white"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <pre id="json-display" class="bg-black/50 p-4 rounded text-[10px] font-mono text-emerald-400 overflow-auto custom-scrollbar flex-grow dir-ltr text-left"></pre>
            <button onclick="document.getElementById('jsonModal').classList.add('hidden')" class="mt-4 w-full bg-slate-800 py-2 text-[10px] font-bold text-white rounded">إغلاق</button>
        </div>
    </div>

    <!-- نافذة جلب عمليات بينانس -->
    <div id="binanceModal" class="hidden fixed inset-0 bg-black/95 flex items-center justify-center p-2 md:p-4 z-[999]">
        <div class="glass-card w-full max-w-2xl p-4 md:p-5 border-2 border-yellow-500/30 shadow-2xl text-right flex flex-col max-h-[85vh]">
            <div class="flex justify-between items-center mb-3 pb-2 border-b border-slate-800">
                <h2 class="text-sm font-black text-yellow-500 flex items-center gap-2 italic uppercase tracking-widest"><i data-lucide="refresh-cw" class="w-4 h-4"></i> جلب عمليات بينانس</h2>
                <button onclick="closeBinanceModal()" class="bg-slate-800 p-1.5 rounded-lg text-white hover:bg-rose-500 transition"><i data-lucide="x" class="w-3.5 h-3.5"></i></button>
            </div>

            <div class="flex flex-col gap-2 mb-3">
                <!-- الملاح الزمني -->
                <div class="flex items-center justify-between bg-slate-900/60 border border-slate-800 rounded-lg p-1 overflow-hidden">
                    <button onclick="navigateBinanceDate(-1)" class="flex items-center gap-1.5 px-3 py-2 text-slate-400 hover:text-yellow-500 transition-all active:scale-90 group">
                         <span class="text-[9px] font-black">السابق</span> <i data-lucide="chevron-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                    </button>

                    <div class="flex flex-col items-center flex-grow px-2">
                        <div id="binance_date_label" class="text-[10px] font-black text-yellow-500 tabular-nums">اليوم</div>
                        <div id="binance_date_val" class="text-[9px] text-slate-500 font-bold tabular-nums">----/--/--</div>
                    </div>

                    <button onclick="navigateBinanceDate(1)" class="flex items-center gap-1.5 px-3 py-2 text-slate-400 hover:text-yellow-500 transition-all active:scale-90 group">
                        <i data-lucide="chevron-left" class="w-4 h-4 group-hover:-translate-x-1 transition-transform"></i> <span class="text-[9px] font-black">التالي</span>
                    </button>
                </div>

                <div class="flex gap-2">
                    <button onclick="setBinanceToday()" class="flex-1 bg-yellow-500/10 hover:bg-yellow-500/20 text-yellow-500 py-2 rounded-lg text-[9px] font-black border border-yellow-500/20 transition-all flex items-center justify-center gap-2">
                        <i data-lucide="calendar-check" class="w-3.5 h-3.5"></i> عرض عمليات اليوم
                    </button>
                    <button onclick="toggleBinanceManualDate()" id="btn-manual-date" class="px-3 bg-slate-800 text-slate-500 hover:text-white rounded-lg text-[9px] font-black border border-slate-700 transition-colors" title="تحديد نطاق مخصص">
                        <i data-lucide="calendar-range" class="w-4 h-4"></i>
                    </button>
                </div>

                <!-- النطاق المخصص (مخفي افتراضياً) -->
                <div id="binance_manual_range" class="hidden flex flex-col gap-2 mt-1 p-3 bg-slate-900/40 border border-slate-800 rounded-xl shadow-inner">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <label class="block text-[8px] font-black text-slate-500 uppercase tracking-widest">من تاريخ</label>
                            <input type="date" id="binance_start_date" class="w-full bg-slate-800 border-none text-white text-[10px] px-2.5 py-1.5 rounded-lg focus:ring-1 ring-yellow-500/50" onchange="syncNavigatorWithInputs(); fetchBinanceOrders();">
                            <input type="time" id="binance_start_time" value="00:00" class="w-full bg-slate-800 border-none text-slate-400 text-[10px] px-2.5 py-1.5 rounded-lg focus:ring-1 ring-yellow-500/50" onchange="fetchBinanceOrders();">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[8px] font-black text-slate-500 uppercase tracking-widest">إلى تاريخ</label>
                            <input type="date" id="binance_end_date" class="w-full bg-slate-800 border-none text-white text-[10px] px-2.5 py-1.5 rounded-lg focus:ring-1 ring-yellow-500/50" onchange="syncNavigatorWithInputs(); fetchBinanceOrders();">
                            <input type="time" id="binance_end_time" value="23:59" class="w-full bg-slate-800 border-none text-slate-400 text-[10px] px-2.5 py-1.5 rounded-lg focus:ring-1 ring-yellow-500/50" onchange="fetchBinanceOrders();">
                        </div>
                    </div>
                    <p class="text-[7px] text-slate-600 italic text-center mt-1">تلميح: يمكنك تحديد الوقت بدقة لجلب العمليات المفقودة في أوقات الذروة</p>
                </div>
            </div>

            <div id="binance-orders-container" class="overflow-y-auto flex-grow custom-scrollbar space-y-2 mb-3 px-1 relative">
                <!-- العمليات ستظهر هنا -->
                <div class="text-center py-10 text-slate-500 font-bold italic">جاري تحميل العمليات...</div>
            </div>

            <div class="flex gap-3 pt-3 border-t border-slate-800">
                <button onclick="fetchBinanceOrders()" class="flex-1 bg-yellow-500/10 hover:bg-yellow-500 text-yellow-500 hover:text-black py-2 rounded-lg font-black text-[10px] uppercase italic transition-all border border-yellow-500/20 shadow-lg shadow-yellow-500/5">تحديث القائمة</button>
                <button onclick="matchBalance()" class="flex-1 bg-blue-500/10 hover:bg-blue-500 text-blue-500 hover:text-white py-2 rounded-lg font-black text-[10px] uppercase italic transition-all border border-blue-500/20 flex items-center justify-center gap-2">
                    <i data-lucide="scale" class="w-3.5 h-3.5"></i> مطابقة الرصيد
                </button>
                <button onclick="closeBinanceModal()" class="flex-1 bg-slate-800 py-2 text-[10px] font-black text-white uppercase italic rounded-lg">إغلاق</button>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        const BUY_PRICE_DEF = <?php echo $def_buy; ?>;
        const SELL_PRICE_DEF = <?php echo $def_sell; ?>;
        const LEDGER_STOCK = <?php echo (float)$remaining_stock; ?>;
        let currentBinanceBalance = 0;
        let hasPendingBuyOrders = false;

        function updateClock() { const clock = document.getElementById('clock'); if (clock) { clock.textContent = new Date().toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' }); } }
        setInterval(updateClock, 1000); updateClock();

        function showToast(msg) { const toast = document.getElementById('toast'); document.getElementById('toast-msg').textContent = msg; toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 4000); }

        function openReportsModal() { document.getElementById('reportsModal').classList.remove('hidden'); window.location.hash = "reportsModal"; }
        function closeReportsModal() { document.getElementById('reportsModal').classList.add('hidden'); history.pushState("", document.title, window.location.pathname + window.location.search); }

        function openBinanceModal() {
            document.getElementById('binanceModal').classList.remove('hidden');
            // تعيين تاريخ اليوم كافتراضي للفلتر إذا كان فارغاً
            const startDateInput = document.getElementById('binance_start_date');
            const endDateInput = document.getElementById('binance_end_date');
            if(!startDateInput.value || !endDateInput.value) {
                const today = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD
                startDateInput.value = today;
                endDateInput.value = today;
            }
            syncNavigatorWithInputs();
            fetchBinanceOrders();
        }

        function toggleBinanceManualDate() {
            const range = document.getElementById('binance_manual_range');
            const btn = document.getElementById('btn-manual-date');
            if (range.classList.contains('hidden')) {
                range.classList.remove('hidden');
                btn.classList.add('bg-yellow-500', 'text-black');
                btn.classList.remove('bg-slate-800', 'text-slate-500');
            } else {
                range.classList.add('hidden');
                btn.classList.remove('bg-yellow-500', 'text-black');
                btn.classList.add('bg-slate-800', 'text-slate-500');
            }
        }

        function syncNavigatorWithInputs() {
            const start = document.getElementById('binance_start_date').value;
            const end = document.getElementById('binance_end_date').value;
            const label = document.getElementById('binance_date_label');
            const val = document.getElementById('binance_date_val');

            const today = new Date().toLocaleDateString('en-CA');
            const yesterday = new Date(Date.now() - 86400000).toLocaleDateString('en-CA');

            if (start === end) {
                if (start === today) label.innerText = "اليوم";
                else if (start === yesterday) label.innerText = "الأمس";
                else label.innerText = "تاريخ محدد";
                val.innerText = start;
            } else {
                label.innerText = "نطاق مخصص";
                val.innerText = `${start} ↔ ${end}`;
            }
        }

        function setBinanceToday() {
            const today = new Date().toLocaleDateString('en-CA');
            document.getElementById('binance_start_date').value = today;
            document.getElementById('binance_end_date').value = today;
            syncNavigatorWithInputs();
            fetchBinanceOrders();
        }

        function navigateBinanceDate(offset) {
            const startDateInput = document.getElementById('binance_start_date');
            const endDateInput = document.getElementById('binance_end_date');

            // نعتمد دائماً على تاريخ البداية للانتقال ليوم واحد
            let current = new Date(startDateInput.value);
            if (isNaN(current.getTime())) current = new Date();

            current.setDate(current.getDate() + offset);
            const newDate = current.toLocaleDateString('en-CA');

            startDateInput.value = newDate;
            endDateInput.value = newDate;

            syncNavigatorWithInputs();
            fetchBinanceOrders();
        }
        function closeBinanceModal() {
            document.getElementById('binanceModal').classList.add('hidden');
        }

        function showRawJson(data) {
            document.getElementById('json-display').textContent = JSON.stringify(data, null, 4);
            document.getElementById('jsonModal').classList.remove('hidden');
            lucide.createIcons();
        }

        let lastBalanceData = null;
        function fetchBinanceBalance() {
            const card = document.getElementById('binance_balance_card');
            const loader = document.getElementById('balance_loader');
            const valDisplay = document.getElementById('binance_usdt_val');

            loader.classList.add('animate-spin');

            fetch('fetch_binance_balance.php')
            .then(res => res.json())
            .then(data => {
                loader.classList.remove('animate-spin');
                if (data.status === 'success') {
                    card.classList.remove('hidden');
                    lastBalanceData = data.raw;
                    currentBinanceBalance = parseFloat(data.balance);
                    valDisplay.innerText = currentBinanceBalance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                } else {
                    console.error('Binance Balance Error:', data.message);
                }
            })
            .catch(err => {
                loader.classList.remove('animate-spin');
                console.error('Fetch error:', err);
            });
        }

        function showBalanceJson() {
            if (!lastBalanceData) return;
            document.getElementById('json_order_id').innerText = "Binance Wallet Balance";
            document.getElementById('json_content').textContent = JSON.stringify(lastBalanceData, null, 4);
            document.getElementById('jsonModal').classList.remove('hidden');
        }

        function matchBalance() {
            if (hasPendingBuyOrders) {
                alert("يوجد عمليات شراء في القائمة لم يتم إضافتها بعد. يرجى إضافتها أولاً لضمان دقة مطابقة الرصيد.");
                return;
            }

            if (currentBinanceBalance > LEDGER_STOCK) {
                if (confirm("لديك رصيد لم يسجل بالكمية هل تريد اضافتة")) {
                    closeReportsModal();
                    closeBinanceModal();

                    typeSelect.value = 'buy';
                    handleTypeChange();

                    const diff = currentBinanceBalance - LEDGER_STOCK;
                    amountInput.value = diff.toFixed(4);

                    // تصفير الرسوم وحمايتها من التحديث التلقائي
                    feeInput.value = "0.00";
                    manualFiatInput.value = 0;
                    feeInput.dataset.manualModified = 'true';
                    manualFiatInput.dataset.manualModified = 'true';

                    updateCalculations();

                    document.getElementById('form-section').scrollIntoView({ behavior: 'smooth' });
                    showToast("تم إدراج الفرق في الكمية، يرجى مراجعة السعر والحفظ");
                }
            } else {
                alert("الرصيد في النظام مطابق أو أكبر من رصيد بينانس");
            }
        }

        function fetchBinanceOrders() {
            const container = document.getElementById('binance-orders-container');
            const startDate = document.getElementById('binance_start_date').value;
            const endDate = document.getElementById('binance_end_date').value;
            const startTime = document.getElementById('binance_start_time').value;
            const endTime = document.getElementById('binance_end_time').value;

            if (startDate && endDate) {
                const diff = (new Date(endDate) - new Date(startDate)) / (1000 * 60 * 60 * 24);
                if (diff > 30) { alert("تنبيه: الحد الأقصى للنطاق الزمني هو 30 يوماً حسب قوانين بينانس."); return; }
            }

            container.innerHTML = '<div class="text-center py-10 text-slate-500 font-bold italic animate-pulse">جاري الاتصال بـ Binance API...</div>';

            fetch(`fetch_binance_orders.php?start_date=${startDate}&end_date=${endDate}&start_time=${startTime}&end_time=${endTime}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    renderBinanceOrders(data.orders);
                } else {
                    container.innerHTML = `<div class="text-center py-10 text-rose-500 font-bold italic">${data.message}</div>`;
                }
            })
            .catch(err => {
                container.innerHTML = `<div class="text-center py-10 text-rose-500 font-bold italic">حدث خطأ في الاتصال بالسيرفر</div>`;
            });
        }

        function renderBinanceOrders(orders) {
            const container = document.getElementById('binance-orders-container');
            container.innerHTML = '';
            hasPendingBuyOrders = false;

            if (orders.length === 0) {
                container.innerHTML = '<div class="text-center py-10 text-slate-500 font-bold italic">لا توجد عمليات حديثة لهذا التاريخ</div>';
                return;
            }

            // إضافة عداد إجمالي للعمليات المعروضة
            const counterHtml = `
                <div class="sticky top-0 z-20 flex justify-center mb-2 pointer-events-none">
                    <span class="bg-slate-900/80 text-blue-400 px-4 py-1 rounded-full text-[9px] font-black border border-blue-500/20 backdrop-blur-md shadow-lg">
                        عدد العمليات المعروضة: ${orders.length}
                    </span>
                </div>
            `;
            container.innerHTML = counterHtml;

            orders.forEach((order, index) => {
                const isBuy = order.side === 'BUY';
                const isCompleted = order.status === 'COMPLETED';
                const isCancelled = order.status === 'CANCELLED' || order.status === 'FAILED' || order.status === 'CANCELLED_BY_SYSTEM' || order.status === 'SYSTEM_CANCELLED';

                const typeLabel = isBuy ? 'شراء' : 'بيع';
                const typeBg = isBuy ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400';
                const borderClass = isBuy ? 'border-r-emerald-500' : 'border-r-rose-500';

                let statusBadge = '';
                if(isCompleted) statusBadge = '<span class="bg-emerald-500/10 text-emerald-500 px-1.5 py-0.5 rounded text-[8px] font-black">مكتملة</span>';
                else if(isCancelled) statusBadge = '<span class="bg-rose-500/10 text-rose-500 px-1.5 py-0.5 rounded text-[8px] font-black">ملغية</span>';
                else statusBadge = '<span class="bg-yellow-500/10 text-yellow-500 px-1.5 py-0.5 rounded text-[8px] font-black">قيد الانتظار</span>';

                const amount = parseFloat(order.amount).toFixed(2);
                const isP2P = order.source === 'P2P';
                const isImported = order.is_imported === true;

                if (isBuy && isCompleted && !isImported) {
                    hasPendingBuyOrders = true;
                }

                let sourceBadge = '';
                if(order.source === 'PAY') sourceBadge = '<span class="bg-blue-500/20 text-blue-400 px-1.5 py-0.5 rounded text-[8px] font-black tracking-widest">PAY</span>';
                if(order.source === 'WITHDRAW') sourceBadge = '<span class="bg-purple-500/20 text-purple-400 px-1.5 py-0.5 rounded text-[8px] font-black tracking-widest">WITHDRAW</span>';
                if(order.source === 'DEPOSIT') sourceBadge = '<span class="bg-emerald-500/20 text-emerald-400 px-1.5 py-0.5 rounded text-[8px] font-black tracking-widest">DEPOSIT</span>';

                const card = `
                    <div class="relative glass-card p-4 hover:bg-slate-800/40 transition-all border-r-4 ${borderClass} group ${isImported || isCancelled ? 'opacity-60' : ''} overflow-hidden">

                        <!-- ترقيم العملية وزر JSON -->
                        <div class="absolute top-0 left-0 flex items-start">
                            <button onclick='showRawJson(${JSON.stringify(order.raw)})' class="p-2 text-slate-700 hover:text-blue-400 hover:bg-blue-500/10 transition-all rounded-br-lg" title="بيانات JSON">
                                <i data-lucide="code" class="w-3 h-3"></i>
                            </button>
                        </div>
                        <div class="absolute top-0 right-0">
                            <span class="bg-slate-800 text-slate-500 px-2 py-1 rounded-bl-lg text-[9px] font-black tabular-nums border-b border-l border-slate-700 shadow-sm">${index + 1}</span>
                        </div>

                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mt-2">

                            <!-- القسم الأيمن: دمج البيانات في كتلة واحدة -->
                            <div class="flex flex-col gap-2 flex-grow w-full">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="px-2 py-0.5 rounded text-[8px] font-black uppercase ${typeBg} tracking-widest">${typeLabel}</span>
                                    ${sourceBadge} ${statusBadge}
                                    <span class="text-[8px] font-mono text-slate-500 bg-black/30 px-2 py-0.5 rounded border border-slate-800 tracking-tighter">#${order.orderNumber.substring(0,10)}...</span>
                                </div>

                                <div class="bg-black/20 p-3 rounded-lg border border-white/5 space-y-1 w-full">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-1.5 text-white font-black">
                                            <span class="text-sm tabular-nums">${amount}</span>
                                            <span class="text-[8px] opacity-50 uppercase tracking-widest">USDT</span>
                                        </div>
                                        <div class="flex items-center gap-1.5 text-yellow-500 font-black">
                                            <span class="text-sm tabular-nums">${isP2P ? parseFloat(order.totalPrice).toLocaleString() : '--'}</span>
                                            <span class="text-[8px] opacity-50 font-bold">﷼ ${order.fiat}</span>
                                        </div>
                                    </div>

                                    <div class="text-[8px] text-slate-500 font-bold flex flex-wrap gap-x-4 gap-y-1 mt-1 border-t border-white/5 pt-1">
                                        <span>سعر الصرف: <span class="text-slate-300 tabular-nums">${isP2P ? parseFloat(order.unitPrice).toFixed(2) : '--'}</span> ﷼</span>
                                        <span>رسوم: <span class="text-rose-400 tabular-nums">${parseFloat(order.binance_fee).toFixed(4)}</span></span>
                                    </div>

                                    <div class="text-[8px] text-slate-600 font-bold pt-1 flex items-center gap-1">
                                        <i data-lucide="clock" class="w-2.5 h-2.5"></i> ${order.createTime}
                                    </div>
                                </div>
                            </div>

                            <!-- القسم الأيسر: الأزرار -->
                            <div class="flex items-center gap-2 w-full md:w-auto justify-end">
                                ${isImported ?
                                    '<span class="bg-emerald-500/10 text-emerald-500 px-3 py-1.5 rounded-lg text-[9px] font-black flex items-center gap-1.5 border border-emerald-500/20"><i data-lucide="check-circle" class="w-3 h-3"></i> تم الإضافة</span>' :
                                    (!isCompleted ? '' : (isP2P ?
                                        `<button id="btn-import-${order.orderNumber}" onclick='quickImportOrder(${JSON.stringify(order)})' class="flex-grow md:flex-none bg-yellow-500/10 hover:bg-yellow-500 text-yellow-500 hover:text-black px-4 py-2 rounded-lg text-[9px] font-black transition-all border border-yellow-500/20 flex items-center justify-center gap-1.5 active:scale-95">
                                            <i data-lucide="zap" class="w-3 h-3"></i> إضافة سريعة
                                        </button>` :
                                        `<button onclick='manualImportToForm(${JSON.stringify(order)})' class="flex-grow md:flex-none bg-blue-500/10 hover:bg-blue-500 text-blue-500 hover:text-white px-4 py-2 rounded-lg text-[9px] font-black transition-all border border-blue-500/20 flex items-center justify-center gap-1.5 active:scale-95">
                                            <i data-lucide="edit-3" class="w-3 h-3"></i> إدراج للنموذج
                                        </button>`
                                    ))
                                }
                            </div>
                        </div>
                    </div>
                `;
                container.innerHTML += card;
            });
            lucide.createIcons();
        }

        function manualImportToForm(order) {
            const modal = document.getElementById('importConfirmModal');
            const type = order.side === 'BUY' ? 'buy' : 'sell';
            const fee = parseFloat(order.binance_fee || 0);
            let amount = parseFloat(order.amount);

            // تصحيح الكمية
            if (type === 'buy') amount = amount - fee;

            // تعبئة بيانات النافذة
            document.getElementById('confirm_order_id').value = order.orderNumber;
            document.getElementById('confirm_type').value = type;
            document.getElementById('confirm_date').value = order.createTime;
            document.getElementById('confirm_amount').value = amount.toFixed(8);

            document.getElementById('display_type').innerText = (type === 'buy' ? 'شراء' : 'بيع');
            document.getElementById('display_type').className = `text-sm font-black uppercase ${type === 'buy' ? 'text-emerald-500' : 'text-rose-500'}`;
            document.getElementById('display_amount').innerText = amount.toFixed(4) + " USDT";

            document.getElementById('confirm_price').value = (type === 'buy' ? BUY_PRICE_DEF : SELL_PRICE_DEF);
            document.getElementById('confirm_binance_fee').value = fee;

            // حساب رسوم صراف افتراضية للشراء
            let manualFee = 0;
            if (type === 'buy') {
                const grossYER = amount * BUY_PRICE_DEF;
                if (grossYER > 300000) manualFee = 200;
                else if (grossYER > 90000) manualFee = 50;
            }
            document.getElementById('confirm_manual_fee').value = manualFee;
            document.getElementById('confirmManualFeeContainer').style.display = (type === 'sell' ? 'none' : 'block');

            modal.classList.remove('hidden');
            lucide.createIcons();
        }

        function quickImportOrder(order) {
            const btn = document.getElementById(`btn-import-${order.orderNumber}`);
            if(btn.disabled) return;

            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader" class="animate-spin w-3 h-3"></i>';
            lucide.createIcons();

            const formData = new FormData();
            const type = order.side === 'BUY' ? 'buy' : 'sell';
            const fee = parseFloat(order.binance_fee || 0);
            let amount = parseFloat(order.amount);

            // تصحيح الكمية: في الشراء فقط نخصم الرسوم لنسجل الصافي الذي دخل المحفظة
            if (type === 'buy') {
                amount = amount - fee;
            }

            formData.append('amount', amount.toFixed(8));
            formData.append('price', order.unitPrice);
            formData.append('type', type);
            formData.append('transaction_date', order.createTime);
            formData.append('binance_order_id', order.orderNumber);

            // استخدام الرسوم الحقيقية من بينانس إذا توفرت
            if (order.binance_fee !== undefined && order.binance_fee !== null) {
                formData.append('binance_fee', fee);
            }

            // إضافة رسوم الصراف آلياً لعمليات الشراء
            if (type === 'buy') {
                const amount = parseFloat(order.amount);
                const price = parseFloat(order.unitPrice);
                const grossYER = (amount) * price;
                let manualFee = 0;
                if (grossYER > 300000) manualFee = 200;
                else if (grossYER > 90000) manualFee = 50;
                formData.append('manual_fee', manualFee);
            }

            fetch('process.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    btn.className = "mt-2 bg-emerald-500/20 text-emerald-500 px-4 py-2 rounded text-[10px] font-black border border-emerald-500/20";
                    btn.innerHTML = '<i data-lucide="check" class="w-3 h-3 inline"></i> تمت الإضافة';
                    lucide.createIcons();
                    showToast("تم إضافة العملية بنجاح!");
                } else {
                    alert(data.message);
                    btn.disabled = false;
                    btn.innerText = "إضافة سريعة";
                    lucide.createIcons();
                }
            })
            .catch(err => {
                alert("حدث خطأ تقني");
                btn.disabled = false;
                btn.innerText = "إضافة سريعة";
                lucide.createIcons();
            });
        }

        function openProfitCalculator() { document.getElementById('profitCalcModal').classList.remove('hidden'); }
        function closeProfitCalculator() { document.getElementById('profitCalcModal').classList.add('hidden'); }

        function saveMainFormState() {
            localStorage.setItem('enable_backdate', document.getElementById('enable_backdate').checked);
            localStorage.setItem('manual_date', document.getElementById('manual_date').value);
            localStorage.setItem('type', typeSelect.value);
            localStorage.setItem('price', priceInput.value);
        }

        function loadMainFormState() {
            const backdate = localStorage.getItem('enable_backdate') === 'true';
            const manualDate = localStorage.getItem('manual_date');
            const type = localStorage.getItem('type');
            const price = localStorage.getItem('price');

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
            updateCalculations();
        }

        window.onload = function() {
            renderProfitChart();
            renderTransactions();
            loadMainFormState();
            fetchBinanceBalance();
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('status') === 'success') { showToast("تم الحفظ بنجاح وتحديث ميزان الأرباح!"); window.history.replaceState({}, document.title, "index.php#form-section"); }
            if (urlParams.get('updated') === '1') { showToast("تم تحديث العملية بنجاح!"); window.history.replaceState({}, document.title, "index.php#form-section"); }
            if (urlParams.get('deleted') === '1') { showToast("تم حذف العملية بنجاح!"); window.history.replaceState({}, document.title, "index.php#form-section"); }
            if (window.location.hash === "#form-section") { document.getElementById('form-section').scrollIntoView({ behavior: 'smooth' }); }
            if (window.location.hash === "#reportsModal") { document.getElementById('reportsModal').classList.remove('hidden'); }
        }

        const importConfirmForm = document.getElementById('import-confirm-form');
        importConfirmForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('confirm-submit-btn');
            const orderId = document.getElementById('confirm_order_id').value;

            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader" class="animate-spin w-4 h-4"></i>';
            lucide.createIcons();

            fetch('process.php', { method: 'POST', body: new FormData(this) })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast("تم إدراج العملية بنجاح!");
                    document.getElementById('importConfirmModal').classList.add('hidden');

                    // تحديث زر العملية في قائمة بينانس إذا كانت مفتوحة
                    const importBtn = document.getElementById(`btn-import-${orderId}`) ||
                                     document.querySelector(`button[onclick*='${orderId}']`);

                    if (importBtn) {
                        const parent = importBtn.parentElement;
                        parent.innerHTML = '<span class="bg-emerald-500/10 text-emerald-500 px-3 py-1.5 rounded-lg text-[9px] font-black flex items-center gap-1.5 border border-emerald-500/20"><i data-lucide="check-circle" class="w-3 h-3"></i> تم الإضافة</span>';
                        lucide.createIcons();
                    }
                } else {
                    alert(data.message);
                    btn.disabled = false;
                    btn.innerHTML = '<i data-lucide="plus-circle" class="w-4 h-4 inline ml-1"></i> إدراج وحفظ';
                    lucide.createIcons();
                }
            })
            .catch(err => {
                alert("حدث خطأ تقني");
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="plus-circle" class="w-4 h-4 inline ml-1"></i> إدراج وحفظ';
                lucide.createIcons();
            });
        });

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
            const btn = document.getElementById('edit-submit-btn');
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader" class="animate-spin w-4 h-4"></i>';
            lucide.createIcons();

            fetch('update.php', { method: 'POST', body: new FormData(this) })
            .then(res => {
                if (res.ok) {
                    window.location.href="index.php?updated=1";
                    // إذا لم يتم التوجيه لسبب ما
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    alert("خطأ في التحديث: تأكد من صحة البيانات");
                    btn.disabled = false;
                    btn.innerText = "تحديث";
                    lucide.createIcons();
                }
            })
            .catch(err => {
                alert("حدث خطأ تقني أثناء التحديث");
                btn.disabled = false;
                btn.innerText = "تحديث";
                lucide.createIcons();
            });
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

            if (amount > 0) {
                calcPreview.classList.remove('hidden');
                if (type === 'buy') {
                    const gross = amount;
                    const fee = 0;
                    if (feeInput.dataset.manualModified !== "true") feeInput.value = fee.toFixed(2);
                    document.getElementById('prev-gross').textContent = gross.toFixed(4);

                    const grossYER = gross * price;

                    // أتمتة رسوم الصراف بناءً على إجمالي المبلغ - فقط إذا لم يقم المستخدم بتعديله يدوياً
                    let finalFee = 0;
                    if (manualFiatInput.dataset.manualModified === "true") {
                        finalFee = parseFloat(manualFiatInput.value) || 0;
                    } else {
                        if (grossYER > 300000) finalFee = 200;
                        else if (grossYER > 90000) finalFee = 50;
                        manualFiatInput.value = finalFee;
                    }

                    document.getElementById('prev-total-yer').textContent = (grossYER + finalFee).toLocaleString();
                } else {
                    const fee = 0;
                    if (feeInput.dataset.manualModified !== "true") feeInput.value = fee.toFixed(2);
                    document.getElementById('prev-gross').textContent = (amount + fee).toFixed(4);
                    document.getElementById('prev-total-yer').textContent = (amount * price).toLocaleString();
                    manualFiatInput.value = 0;
                }
            } else {
                calcPreview.classList.add('hidden');
                feeInput.value = "0.00";
                manualFiatInput.value = 0;
                manualFiatInput.dataset.manualModified = "false";
            feeInput.dataset.manualModified = "false";
            }
        }

        amountInput.addEventListener('input', () => {
            updateCalculations();
        });
        priceInput.addEventListener('input', () => { updateCalculations(); saveMainFormState(); });
        feeInput.addEventListener('input', () => {
            feeInput.dataset.manualModified = 'true';
            updateCalculations();
            saveMainFormState();
        });
        manualFiatInput.addEventListener('input', () => {
            manualFiatInput.dataset.manualModified = "true";
            updateCalculations();
            saveMainFormState();
        });
        typeSelect.addEventListener('change', () => {
            manualFiatInput.dataset.manualModified = "false";
            feeInput.dataset.manualModified = "false";
            handleTypeChange();
            updateCalculations();
            saveMainFormState();
        });
        document.getElementById('enable_backdate').addEventListener('change', saveMainFormState);
        document.getElementById('manual_date').addEventListener('input', saveMainFormState);

        function handleTypeChange() {
            typeSelect.value === 'sell' ? priceInput.value = SELL_PRICE_DEF : priceInput.value = BUY_PRICE_DEF;
            document.getElementById('manualFeeContainer').style.display = typeSelect.value === 'sell' ? 'none' : 'block';
        }

        function toggleDateInput() {
            const isChecked = document.getElementById('enable_backdate').checked;
            const dateInput = document.getElementById('manual_date');
            document.getElementById('date_container').style.display = isChecked ? 'block' : 'none';
            document.getElementById('live_clock_display').style.display = isChecked ? 'none' : 'block';

            if (!isChecked) {
                dateInput.value = ""; // تصفير التاريخ عند التعطيل لضمان استخدام وقت السيرفر الحالي
            }
        }

        function openEditModal(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_binance_order_id').value = data.binance_order_id || "";

            document.getElementById('edit_type').value = data.type;
            document.getElementById('edit_amount').value = data.crypto_amount;
            document.getElementById('edit_price').value = data.price_per_unit;
            document.getElementById('edit_binance_fee').value = data.binance_fee;

            const editManualFee = document.getElementById('edit_manual_fee');
            editManualFee.value = data.manual_fee || 0;
            editManualFee.dataset.manualModified = "false";
            document.getElementById('edit_binance_fee').dataset.manualModified = "true";

            document.getElementById('editManualFeeContainer').style.display = data.type === 'sell' ? 'none' : 'block';

            // Formatting for datetime-local input (YYYY-MM-DDTHH:MM)
            // Created_at is "YYYY-MM-DD HH:MM:SS" in Yemen time
            let dt = data.created_at.replace(" ", "T").substring(0, 16);
            document.getElementById('edit_date').value = dt;

            document.getElementById('editModal').classList.remove('hidden');
            updateEditCalculations();
        }

        function updateEditCalculations() {
            const amount = parseFloat(document.getElementById('edit_amount').value) || 0;
            const price = parseFloat(document.getElementById('edit_price').value) || 0;
            const type = document.getElementById('edit_type').value;
            const binanceFeeInput = document.getElementById('edit_binance_fee');
            const manualFeeInput = document.getElementById('edit_manual_fee');

            if (amount > 0) {
                if (type === 'buy') {
                    const gross = amount;
                    const grossYER = gross * price;

                    if (binanceFeeInput.dataset.manualModified !== "true") binanceFeeInput.value = "0.00";

                    // أتمتة رسوم الصراف أيضاً عند التعديل - مع مراعاة التعديل اليدوي
                    let finalFee = 0;
                    if (manualFeeInput.dataset.manualModified === "true") {
                        finalFee = parseFloat(manualFeeInput.value) || 0;
                    } else {
                        if (grossYER > 300000) finalFee = 200;
                        else if (grossYER > 90000) finalFee = 50;
                        manualFeeInput.value = finalFee;
                    }
                } else {
                    if (binanceFeeInput.dataset.manualModified !== "true") binanceFeeInput.value = "0.00";
                    manualFeeInput.value = 0;
                }
            }
        }

        document.getElementById('edit_type').addEventListener('change', function() {
            document.getElementById('edit_manual_fee').dataset.manualModified = "false";
            document.getElementById('editManualFeeContainer').style.display = this.value === 'sell' ? 'none' : 'block';
            updateEditCalculations();
        });
        document.getElementById('edit_price').addEventListener('input', updateEditCalculations);
        document.getElementById('edit_amount').addEventListener('input', updateEditCalculations);
        document.getElementById('edit_binance_fee').addEventListener('input', function() {
            this.dataset.manualModified = 'true';
            updateEditCalculations();
        });
        document.getElementById('edit_manual_fee').addEventListener('input', function() {
            this.dataset.manualModified = "true";
            updateEditCalculations();
        });

        // --- نظام السجل المتطور ---
        let rawTransactions = <?php echo json_encode($transactions); ?>;

        // حساب المخزون قبل وبعد لكل عملية (تراكمي عكسي لأن البيانات مرتبة من الأحدث للأقدم)
        let runningStock = <?php echo $remaining_stock; ?>;
        rawTransactions.forEach((t, i) => {
            t.stock_after = runningStock;
            const impact = (t.type === 'buy') ? parseFloat(t.crypto_amount) : -parseFloat(t.total_crypto_deducted);
            t.stock_before = runningStock - impact;
            runningStock = t.stock_before; // تحديث المخزون للعملية التي قبلها (أقدم منها)
        });

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
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <p class="text-[9px] text-slate-500 font-bold">${txDate.toLocaleTimeString('ar-YE', timeOptions)}</p>
                                        <div class="flex items-center gap-1.5 border-r border-slate-700 pr-2 mr-0.5">
                                            <span class="text-[8px] text-slate-500 font-bold">قبل: <span class="text-slate-400 tabular-nums">${t.stock_before.toFixed(2)}</span></span>
                                            <span class="text-[8px] text-slate-500 font-bold">بعد: <span class="text-white tabular-nums">${t.stock_after.toFixed(2)}</span></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-left">
                                <p class="text-sm font-black text-white tabular-nums">${parseFloat(t.total_fiat_paid).toLocaleString()} <span class="text-[10px] text-slate-500">YER</span></p>
                                <div class="flex flex-col items-end">
                                    <p class="text-[9px] text-slate-500 italic">سعر الصرف: ${t.price_per_unit}</p>
                                    ${t.type === 'sell' ? `<p class="text-[10px] font-black text-emerald-500 tabular-nums mt-0.5"><i data-lucide="trending-up" class="w-2.5 h-2.5 inline ml-0.5"></i>+${parseFloat(t.fifo_profit).toLocaleString()} <span class="text-[8px] opacity-60">ربح</span></p>` : ''}
                                </div>
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