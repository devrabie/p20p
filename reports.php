<?php
session_start();
require_once 'db.php';

// 1. حماية الصفحة
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// 2. جلب متوسط سعر الشراء العام للمستخدم (لحساب التكلفة في الأرشيف)
$wac_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid) as spent, SUM(crypto_amount) as bought FROM transactions WHERE user_id = ? AND type='buy'");
$wac_stmt->execute([$user_id]);
$wac_data = $wac_stmt->fetch();
$avg_buy_price = ($wac_data['bought'] > 0) ? ($wac_data['spent'] / $wac_data['bought']) : 0;

// 3. جلب سعر الصرف الافتراضي للمستخدم (لتحويل الأرباح للدولار)
$settings_stmt = $pdo->prepare("SELECT default_buy_price FROM settings WHERE user_id = ?");
$settings_stmt->execute([$user_id]);
$default_buy_price = $settings_stmt->fetchColumn() ?: 535;

// 4. جلب إحصائيات العمر (Lifetime Analytics)
$lifetime_stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN type='sell' THEN total_fiat_paid - (? * crypto_amount) ELSE 0 END) as total_profit,
        SUM(crypto_amount) as total_volume,
        COUNT(*) as total_tx
    FROM transactions
    WHERE user_id = ?
");
$lifetime_stmt->execute([$avg_buy_price, $user_id]);
$lifetime = $lifetime_stmt->fetch();

// 5. استعلام تجميع البيانات اليومي المطور
$sql = "SELECT 
            DATE(created_at) as day, 
            SUM(CASE WHEN type='buy' THEN total_fiat_paid ELSE 0 END) as total_buy_fiat,
            SUM(CASE WHEN type='sell' THEN total_fiat_paid ELSE 0 END) as total_sell_fiat,
            SUM(CASE WHEN type='sell' THEN crypto_amount ELSE 0 END) as total_sell_qty,
            COUNT(*) as transactions_count
        FROM transactions 
        WHERE user_id = ? 
        GROUP BY DATE(created_at) 
        ORDER BY day DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $reports = $stmt->fetchAll();
} catch (Exception $e) {
    die("خطأ في جلب التقارير: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الأرشيف والتحليل | Ledger Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style> 
        :root { --bg-main: #0a0f1c; --bg-card: #151b2d; --accent-gold: #eab308; --border-color: #242f48; }
        body { font-family: 'Tajawal', sans-serif; background-color: var(--bg-main); color: #f1f5f9; line-height: 1.6; }
        .glass-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; transition: all 0.3s ease; }
        .glass-card:hover { border-color: #3b82f6; transform: translateY(-2px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3); }
        .profit-badge { background: rgba(16, 185, 129, 0.05); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.1); }
        .btn-primary-glass { background: linear-gradient(135deg, rgba(79, 70, 229, 0.8) 0%, rgba(30, 58, 138, 0.8) 100%); backdrop-filter: blur(10px); color: white; border: 1px solid rgba(255, 255, 255, 0.1); font-weight: 800; border-radius: 12px; transition: all 0.3s; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
    </style>
</head>
<body class="p-4 md:p-8 pb-20 custom-scrollbar">
    <div class="max-w-5xl mx-auto">
        
        <!-- الرأس -->
        <div class="flex flex-col md:flex-row justify-between items-center mb-10 gap-6 border-b border-slate-800 pb-8 text-right">
            <div>
                <h1 class="text-2xl md:text-4xl font-black text-yellow-500 flex items-center gap-3 justify-center md:justify-start italic uppercase tracking-tighter">
                    <i data-lucide="line-chart" class="w-8 h-8 md:w-10 md:h-10"></i> الأرشيف والتحليل
                </h1>
                <p class="text-[10px] md:text-xs text-slate-500 mt-2 font-bold uppercase tracking-[0.2em] italic opacity-60">P2P Performance Intelligence</p>
            </div>
            <a href="index.php" class="btn-primary-glass px-8 py-3 flex items-center gap-3 active:scale-95">
                <i data-lucide="home" class="w-5 h-5"></i> العودة للرئيسية
            </a>
        </div>

        <!-- قسم Lifetime Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
            <div class="glass-card p-6 border-r-4 border-r-emerald-500 relative overflow-hidden group">
                <div class="absolute -right-4 -top-4 w-20 h-20 bg-emerald-500/5 rounded-full blur-2xl group-hover:bg-emerald-500/10 transition-all"></div>
                <p class="text-[10px] font-black text-slate-500 uppercase mb-2 tracking-widest">إجمالي أرباح المنصة</p>
                <h3 class="text-2xl font-black text-emerald-400 tabular-nums"><?php echo number_format($lifetime['total_profit'] ?? 0, 2); ?> <span class="text-xs opacity-50">YER</span></h3>
                <p class="text-[10px] text-emerald-500/60 mt-1 font-bold italic">$<?php echo number_format(($lifetime['total_profit'] ?? 0) / $default_buy_price, 2); ?></p>
            </div>
            <div class="glass-card p-6 border-r-4 border-r-blue-500 relative overflow-hidden group">
                <div class="absolute -right-4 -top-4 w-20 h-20 bg-blue-500/5 rounded-full blur-2xl group-hover:bg-blue-500/10 transition-all"></div>
                <p class="text-[10px] font-black text-slate-500 uppercase mb-2 tracking-widest">إجمالي حجم التداول</p>
                <h3 class="text-2xl font-black text-blue-400 tabular-nums"><?php echo number_format($lifetime['total_volume'] ?? 0, 2); ?> <span class="text-xs opacity-50">USDT</span></h3>
                <p class="text-[10px] text-blue-500/60 mt-1 font-bold italic"><?php echo number_format($lifetime['total_tx'] ?? 0); ?> عملية مسجلة</p>
            </div>
            <div class="glass-card p-6 border-r-4 border-r-purple-500 relative overflow-hidden group text-right">
                <div class="absolute -right-4 -top-4 w-20 h-20 bg-purple-500/5 rounded-full blur-2xl group-hover:bg-purple-500/10 transition-all"></div>
                <p class="text-[10px] font-black text-slate-500 uppercase mb-2 tracking-widest">متوسط الشراء (WAC)</p>
                <h3 class="text-2xl font-black text-purple-400 tabular-nums"><?php echo number_format($avg_buy_price, 2); ?> <span class="text-xs opacity-50">YER</span></h3>
                <p class="text-[10px] text-purple-500/60 mt-1 font-bold italic">أساس حساب الأرباح</p>
            </div>
        </div>

        <!-- قائمة الأرشيف مجمعة -->
        <div class="space-y-12">
            <?php
            $last_month = "";
            foreach($reports as $day):
                $month_name = date('F Y', strtotime($day['day']));
                // ترجمة الشهور للعربية
                $months_ar = ["January"=>"يناير","February"=>"فبراير","March"=>"مارس","April"=>"أبريل","May"=>"مايو","June"=>"يونيو","July"=>"يوليو","August"=>"أغسطس","September"=>"سبتمبر","October"=>"أكتوبر","November"=>"نوفمبر","December"=>"ديسمبر"];
                $month_display = strtr($month_name, $months_ar);

                if ($month_display !== $last_month):
            ?>
                <div class="flex items-center gap-4 mb-8">
                    <div class="h-px flex-grow bg-slate-800"></div>
                    <div class="bg-slate-900 border border-slate-700 px-6 py-2 rounded-full text-xs font-black text-blue-400 italic uppercase tracking-widest">
                        <i data-lucide="calendar-days" class="inline w-3.5 h-3.5 ml-2"></i> <?php echo $month_display; ?>
                    </div>
                    <div class="h-px flex-grow bg-slate-800"></div>
                </div>
            <?php
                $last_month = $month_display;
                endif;

                // حساب الربح اليومي
                $daily_profit_yer = $day['total_sell_fiat'] - ($avg_buy_price * $day['total_sell_qty']);
                $daily_profit_usd = ($default_buy_price > 0) ? ($daily_profit_yer / $default_buy_price) : 0;
            ?>
            <div class="glass-card p-6 md:p-8 hover:border-emerald-500/30 transition-all">
                <div class="flex flex-col md:flex-row justify-between items-center gap-6">

                    <div class="flex items-center gap-5 w-full md:w-auto">
                        <div class="w-14 h-14 bg-gradient-to-br from-yellow-500/20 to-yellow-600/5 rounded-2xl flex items-center justify-center border border-yellow-500/20 shadow-inner">
                            <i data-lucide="calendar-check-2" class="text-yellow-500 w-7 h-7"></i>
                        </div>
                        <div class="text-right">
                            <h3 class="text-xl font-black text-white tabular-nums tracking-tight"><?php echo $day['day']; ?></h3>
                            <div class="flex gap-3 mt-1">
                                <span class="text-[10px] text-slate-500 font-bold uppercase flex items-center gap-1"><i data-lucide="activity" class="w-3 h-3"></i> <?php echo $day['transactions_count']; ?> عمليات</span>
                                <span class="text-[10px] text-blue-400 font-bold uppercase flex items-center gap-1"><i data-lucide="zap" class="w-3 h-3 text-blue-500"></i> فوليوم: <?php echo number_format($day['total_sell_qty'], 1); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex gap-4 items-center w-full md:w-auto justify-between md:justify-end">
                        <div class="grid grid-cols-2 gap-3 text-right">
                            <div class="bg-blue-500/5 border border-blue-500/10 px-4 py-2 rounded-xl">
                                <p class="text-[8px] text-blue-400 font-black uppercase mb-0.5 tracking-tighter">إجمالي الوارد</p>
                                <p class="text-xs font-black text-white tabular-nums"><?php echo number_format($day['total_buy_fiat']); ?> <span class="opacity-30 text-[8px]">YER</span></p>
                            </div>
                            <div class="bg-green-500/5 border border-green-500/10 px-4 py-2 rounded-xl">
                                <p class="text-[8px] text-green-400 font-black uppercase mb-0.5 tracking-tighter">إجمالي الصادر</p>
                                <p class="text-xs font-black text-white tabular-nums"><?php echo number_format($day['total_sell_fiat']); ?> <span class="opacity-30 text-[8px]">YER</span></p>
                            </div>
                        </div>

                        <div class="profit-badge px-6 py-3 rounded-2xl text-center border-emerald-500/20 shadow-lg shadow-emerald-900/10">
                            <span class="text-[9px] font-black uppercase opacity-60 block mb-1">صافي الربح</span>
                            <div class="text-lg font-black tabular-nums"><?php echo number_format($daily_profit_yer); ?> <span class="text-[10px]">YER</span></div>
                            <div class="text-[10px] font-bold mt-0.5 opacity-80">$<?php echo number_format($daily_profit_usd, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- حالة عدم وجود بيانات -->
        <?php if(empty($reports)): ?>
        <div class="text-center py-32 bg-slate-900/40 rounded-[2rem] border-2 border-dashed border-slate-800/50 flex flex-col items-center justify-center">
            <div class="w-20 h-20 bg-slate-800/50 rounded-full flex items-center justify-center mb-6">
                <i data-lucide="folder-open" class="text-slate-600 w-10 h-10"></i>
            </div>
            <p class="text-slate-500 font-black text-xl tracking-widest uppercase italic">السجل فارغ تماماً</p>
            <p class="text-slate-600 text-sm mt-2 font-bold">ابدأ بتسجيل عملياتك لتظهر هنا</p>
        </div>
        <?php endif; ?>

    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>