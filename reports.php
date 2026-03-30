<?php
session_start();
require_once 'db.php';

// 1. حماية الصفحة
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// 2. جلب متوسط سعر الشراء العام للمستخدم
$wac_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid) as spent, SUM(crypto_amount) as bought FROM transactions WHERE user_id = ? AND type='buy'");
$wac_stmt->execute([$user_id]);
$wac_data = $wac_stmt->fetch();
$avg_buy_price = ($wac_data['bought'] > 0) ? ($wac_data['spent'] / $wac_data['bought']) : 0;

// 3. جلب سعر الصرف الافتراضي للمستخدم
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

// 5. إحصائيات مميزة (Best Day & Highest Vol)
$best_day_stmt = $pdo->prepare("
    SELECT DATE(created_at) as day, SUM((price_per_unit - ?) * crypto_amount) as daily_profit
    FROM transactions
    WHERE user_id = ? AND type='sell'
    GROUP BY DATE(created_at)
    ORDER BY daily_profit DESC
    LIMIT 1
");
$best_day_stmt->execute([$avg_buy_price, $user_id]);
$best_day = $best_day_stmt->fetch();

$max_vol_stmt = $pdo->prepare("
    SELECT DATE(created_at) as day, SUM(crypto_amount) as daily_vol
    FROM transactions
    WHERE user_id = ?
    GROUP BY DATE(created_at)
    ORDER BY daily_vol DESC
    LIMIT 1
");
$max_vol_stmt->execute([$user_id]);
$max_vol_day = $max_vol_stmt->fetch();

// 6. الفلترة حسب التاريخ
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$where_clause = "WHERE user_id = ?";
$params = [$user_id];

if ($start_date) {
    $where_clause .= " AND DATE(created_at) >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $where_clause .= " AND DATE(created_at) <= ?";
    $params[] = $end_date;
}

// 7. استعلام تجميع البيانات اليومي
$sql = "SELECT 
            DATE(created_at) as day, 
            SUM(CASE WHEN type='buy' THEN total_fiat_paid ELSE 0 END) as total_buy_fiat,
            SUM(CASE WHEN type='sell' THEN total_fiat_paid ELSE 0 END) as total_sell_fiat,
            SUM(CASE WHEN type='sell' THEN crypto_amount ELSE 0 END) as total_sell_qty,
            COUNT(*) as transactions_count
        FROM transactions 
        $where_clause
        GROUP BY DATE(created_at) 
        ORDER BY day DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style> 
        :root { --bg-main: #0a0f1c; --bg-card: #151b2d; --accent-blue: #3b82f6; --border-color: #242f48; }
        body { font-family: 'Tajawal', sans-serif; background-color: var(--bg-main); color: #f1f5f9; line-height: 1.6; font-style: normal !important; }
        * { font-style: normal !important; }
        .glass-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; transition: all 0.3s ease; }
        .btn-primary-glass { background: linear-gradient(135deg, rgba(59, 130, 246, 0.8) 0%, rgba(29, 78, 216, 0.8) 100%); backdrop-filter: blur(10px); color: white; border: 1px solid rgba(255, 255, 255, 0.1); font-weight: 800; border-radius: 8px; transition: all 0.3s; }
        .btn-primary-glass:hover { transform: translateY(-1px); box-shadow: 0 10px 20px -10px rgba(59, 130, 246, 0.5); }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
        .tabular-nums { font-family: 'Courier New', monospace; font-weight: 700; }
        .table-row:hover { background-color: rgba(255, 255, 255, 0.02); }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }
    </style>
</head>
<body class="p-4 md:p-8 pb-20 custom-scrollbar">
    <div class="max-w-6xl mx-auto">
        
        <!-- الرأس -->
        <header class="flex flex-col md:flex-row justify-between items-center mb-10 gap-6 border-b border-slate-800 pb-8">
            <div class="text-center md:text-right">
                <h1 class="text-2xl md:text-3xl font-black text-blue-500 flex items-center gap-3 justify-center md:justify-start uppercase tracking-tighter">
                    <i data-lucide="bar-chart-3" class="w-8 h-8 md:w-10 md:h-10"></i> الأرشيف والتحليل الذكي
                </h1>
                <p class="text-[10px] md:text-xs text-slate-500 mt-2 font-bold uppercase tracking-[0.2em] opacity-60">P2P Performance & Historical Data</p>
            </div>
            <div class="flex gap-3">
                <a href="index.php" class="btn-primary-glass px-6 py-2.5 flex items-center gap-2 text-sm active:scale-95">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i> الرئيسية
                </a>
            </div>
        </header>

        <!-- قسم الإحصائيات المميزة (Highlights) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="glass-card p-4 border-r-4 border-emerald-500 bg-emerald-500/5">
                <p class="text-[10px] font-black text-emerald-500/70 uppercase mb-1 tracking-widest">إجمالي الأرباح</p>
                <h3 class="text-lg md:text-xl font-black text-white tabular-nums"><?php echo number_format($lifetime['total_profit'] ?? 0); ?> <span class="text-[10px] opacity-40">YER</span></h3>
                <p class="text-[10px] text-emerald-400 font-bold">$<?php echo number_format(($lifetime['total_profit'] ?? 0) / ($default_buy_price ?: 1), 2); ?></p>
            </div>
            <div class="glass-card p-4 border-r-4 border-blue-500 bg-blue-500/5">
                <p class="text-[10px] font-black text-blue-500/70 uppercase mb-1 tracking-widest">أفضل يوم ربح</p>
                <h3 class="text-lg md:text-xl font-black text-white tabular-nums"><?php echo number_format($best_day['daily_profit'] ?? 0); ?> <span class="text-[10px] opacity-40">YER</span></h3>
                <p class="text-[10px] text-blue-400 font-bold"><?php echo $best_day['day'] ?? '--'; ?></p>
            </div>
            <div class="glass-card p-4 border-r-4 border-purple-500 bg-purple-500/5">
                <p class="text-[10px] font-black text-purple-500/70 uppercase mb-1 tracking-widest">حجم التداول (Vol)</p>
                <h3 class="text-lg md:text-xl font-black text-white tabular-nums"><?php echo number_format($lifetime['total_volume'] ?? 0, 1); ?> <span class="text-[10px] opacity-40">USDT</span></h3>
                <p class="text-[10px] text-purple-400 font-bold"><?php echo number_format($lifetime['total_tx'] ?? 0); ?> عملية</p>
            </div>
            <div class="glass-card p-4 border-r-4 border-slate-500 bg-slate-500/5">
                <p class="text-[10px] font-black text-slate-400 uppercase mb-1 tracking-widest">متوسط الشراء (WAC)</p>
                <h3 class="text-lg md:text-xl font-black text-white tabular-nums"><?php echo number_format($avg_buy_price, 2); ?> <span class="text-[10px] opacity-40">YER</span></h3>
                <p class="text-[10px] text-slate-500 font-bold">حسب إجمالي الوارد</p>
            </div>
        </div>

        <!-- شريط الفلترة والأدوات -->
        <div class="glass-card p-4 mb-8 bg-slate-900/50 border-slate-800">
            <form method="GET" class="flex flex-col md:flex-row gap-4 items-end">
                <div class="w-full md:w-auto">
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-1 mr-1">من تاريخ</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="w-full md:w-44 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:border-blue-500 outline-none">
                </div>
                <div class="w-full md:w-auto">
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-1 mr-1">إلى تاريخ</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="w-full md:w-44 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:border-blue-500 outline-none">
                </div>
                <div class="flex gap-2 w-full md:w-auto">
                    <button type="submit" class="flex-1 md:flex-none bg-blue-600 hover:bg-blue-500 text-white px-6 py-2 rounded-lg text-sm font-bold flex items-center justify-center gap-2 transition-all">
                        <i data-lucide="search" class="w-4 h-4"></i> بحث
                    </button>
                    <a href="reports.php" class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center justify-center transition-all">
                        <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                    </a>
                </div>
                <div class="md:mr-auto flex gap-2 w-full md:w-auto">
                    <button type="button" onclick="exportToExcel()" class="flex-1 md:flex-none bg-emerald-600/10 hover:bg-emerald-600 text-emerald-500 hover:text-white border border-emerald-600/20 px-4 py-2 rounded-lg text-sm font-bold flex items-center justify-center gap-2 transition-all">
                        <i data-lucide="file-spreadsheet" class="w-4 h-4"></i> تصدير Excel
                    </button>
                </div>
            </form>
        </div>

        <!-- جدول البيانات -->
        <div class="glass-card overflow-hidden border-slate-800 shadow-2xl">
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-right border-collapse min-w-[800px]" id="reportsTable">
                    <thead>
                        <tr class="bg-slate-800/50 border-b border-slate-700">
                            <th class="p-5 text-xs font-black text-slate-400 uppercase tracking-widest">التاريخ</th>
                            <th class="p-5 text-xs font-black text-slate-400 uppercase tracking-widest text-center">العمليات</th>
                            <th class="p-5 text-xs font-black text-slate-400 uppercase tracking-widest text-center">حجم التداول (USDT)</th>
                            <th class="p-5 text-xs font-black text-slate-400 uppercase tracking-widest text-center">إجمالي الوارد (YER)</th>
                            <th class="p-5 text-xs font-black text-slate-400 uppercase tracking-widest text-center">إجمالي الصادر (YER)</th>
                            <th class="p-5 text-xs font-black text-emerald-400 uppercase tracking-widest text-left">صافي الربح</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <?php
                        $last_month = "";
                        foreach($reports as $day):
                            $month_name = date('F Y', strtotime($day['day']));
                            $months_ar = ["January"=>"يناير","February"=>"فبراير","March"=>"مارس","April"=>"أبريل","May"=>"مايو","June"=>"يونيو","July"=>"يوليو","August"=>"أغسطس","September"=>"سبتمبر","October"=>"أكتوبر","November"=>"نوفمبر","December"=>"ديسمبر"];
                            $month_display = strtr($month_name, $months_ar);

                            if ($month_display !== $last_month):
                        ?>
                            <tr class="bg-blue-500/5">
                                <td colspan="6" class="px-5 py-3 text-[11px] font-black text-blue-400 uppercase tracking-widest">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="calendar" class="w-3 h-3"></i> <?php echo $month_display; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php
                            $last_month = $month_display;
                            endif;

                            $daily_profit_yer = $day['total_sell_fiat'] - ($avg_buy_price * $day['total_sell_qty']);
                            $daily_profit_usd = ($default_buy_price > 0) ? ($daily_profit_yer / $default_buy_price) : 0;
                        ?>
                        <tr class="table-row transition-colors">
                            <td class="p-5">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center">
                                        <i data-lucide="calendar-days" class="w-4 h-4 text-slate-400"></i>
                                    </div>
                                    <span class="text-sm font-bold tabular-nums"><?php echo $day['day']; ?></span>
                                </div>
                            </td>
                            <td class="p-5 text-center text-sm font-bold text-slate-300"><?php echo $day['transactions_count']; ?></td>
                            <td class="p-5 text-center text-sm font-black tabular-nums"><?php echo number_format($day['total_sell_qty'], 1); ?></td>
                            <td class="p-5 text-center text-sm font-black tabular-nums text-blue-400"><?php echo number_format($day['total_buy_fiat']); ?></td>
                            <td class="p-5 text-center text-sm font-black tabular-nums text-emerald-400"><?php echo number_format($day['total_sell_fiat']); ?></td>
                            <td class="p-5 text-left">
                                <div class="inline-block text-right">
                                    <div class="text-sm font-black text-emerald-500 tabular-nums"><?php echo number_format($daily_profit_yer); ?> <span class="text-[9px] opacity-60">YER</span></div>
                                    <div class="text-[10px] font-bold text-slate-500 tabular-nums">$<?php echo number_format($daily_profit_usd, 2); ?></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- حالة عدم وجود بيانات -->
        <?php if(empty($reports)): ?>
        <div class="text-center py-24 bg-slate-900/40 rounded-2xl border-2 border-dashed border-slate-800/50 flex flex-col items-center justify-center mt-8">
            <div class="w-16 h-16 bg-slate-800/50 rounded-full flex items-center justify-center mb-4">
                <i data-lucide="search-x" class="text-slate-600 w-8 h-8"></i>
            </div>
            <p class="text-slate-500 font-bold text-lg uppercase tracking-widest">لا توجد سجلات تطابق البحث</p>
            <p class="text-slate-600 text-xs mt-1">جرب تغيير نطاق التاريخ المختار</p>
        </div>
        <?php endif; ?>

    </div>

    <script>
        lucide.createIcons();

        function exportToExcel() {
            const table = document.getElementById("reportsTable");
            const wb = XLSX.utils.table_to_book(table, {sheet: "Daily Reports"});
            const date = new Date().toISOString().split('T')[0];
            XLSX.writeFile(wb, `LedgerPro_Report_${date}.xlsx`);
        }
    </script>
</body>
</html>