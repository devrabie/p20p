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

// 4. استعلام تجميع البيانات اليومي المطور
// أضفنا جلب كميات البيع (total_sell_qty) لنتمكن من طرح التكلفة منها
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
    <title>الأرشيف اليومي | Ledger Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style> 
        body { 
            font-family: 'Tajawal', sans-serif; 
            background-color: #0a0f1c; 
            color: #f1f5f9; 
            line-height: 1.6;
        }
        .archive-card {
            background: #151b2d;
            border: 1px solid #242f48;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        .archive-card:hover {
            transform: translateY(-3px);
            border-color: #eab308;
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.5);
        }
        .profit-badge {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
    </style>
</head>
<body class="p-4 md:p-8 pb-20">
    <div class="max-w-4xl mx-auto">
        
        <!-- الرأس -->
        <div class="flex flex-col sm:flex-row justify-between items-center mb-10 gap-4 border-b border-slate-800 pb-8">
            <div>
                <h1 class="text-2xl md:text-3xl font-black text-yellow-500 flex items-center justify-center md:justify-start">
                    <i class="fas fa-history ml-3"></i> سجل الأداء التاريخي
                </h1>
                <p class="text-xs text-slate-500 mt-2 text-center md:text-right uppercase tracking-widest italic">P2P Accounting Archive</p>
            </div>
            <a href="index.php" class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-3 rounded-xl transition flex items-center gap-3 font-bold border border-slate-700">
                <i class="fas fa-arrow-right"></i> العودة للرئيسية
            </a>
        </div>

        <!-- قائمة الأرشيف -->
        <div class="grid gap-6">
            <?php foreach($reports as $day): 
                // حساب الربح اليومي: (إيراد البيع) - (كمية البيع * متوسط سعر الشراء العام)
                $daily_profit_yer = $day['total_sell_fiat'] - ($avg_buy_price * $day['total_sell_qty']);
                $daily_profit_usd = ($default_buy_price > 0) ? ($daily_profit_yer / $default_buy_price) : 0;
            ?>
            <div class="archive-card p-5 md:p-8 flex flex-col gap-6">
                
                <!-- معلومات التاريخ -->
                <div class="flex justify-between items-start border-b border-slate-700/50 pb-4">
                    <div class="flex items-center gap-4">
                        <div class="bg-yellow-500/10 p-3 rounded-xl border border-yellow-500/20">
                            <i class="far fa-calendar-check text-yellow-500 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-black text-white tabular-nums"><?php echo $day['day']; ?></h3>
                            <p class="text-[10px] text-slate-500 font-bold uppercase tracking-tighter">
                                <i class="fas fa-fingerprint ml-1"></i> <?php echo $day['transactions_count']; ?> عمليات مسجلة
                            </p>
                        </div>
                    </div>
                    
                    <!-- عرض الربح الصافي لهذا اليوم -->
                    <div class="text-left">
                        <div class="profit-badge px-4 py-2 rounded-lg text-sm font-black">
                            <span class="text-[10px] uppercase opacity-70 ml-1">صافي الربح:</span>
                            <?php echo number_format($daily_profit_yer); ?> YER
                        </div>
                        <div class="text-[10px] text-emerald-500 font-bold mt-1 text-center">
                            ( $<?php echo number_format($daily_profit_usd, 2); ?> )
                        </div>
                    </div>
                </div>
                
                <!-- تفاصيل الحجم (شراء وبيع) -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-blue-500/5 p-4 rounded-xl border border-blue-500/10">
                        <p class="text-[9px] text-blue-400 font-bold uppercase mb-1">إجمالي الوارد (YER)</p>
                        <p class="text-lg font-black text-slate-200 tabular-nums"><?php echo number_format($day['total_buy_fiat']); ?></p>
                        <i class="fas fa-arrow-down-long text-blue-500 text-[10px] mt-1"></i>
                    </div>
                    <div class="bg-green-500/5 p-4 rounded-xl border border-green-500/10">
                        <p class="text-[9px] text-green-400 font-bold uppercase mb-1">إجمالي الصادر (YER)</p>
                        <p class="text-lg font-black text-slate-200 tabular-nums"><?php echo number_format($day['total_sell_fiat']); ?></p>
                        <i class="fas fa-arrow-up-long text-green-500 text-[10px] mt-1"></i>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
        </div>

        <!-- حالة عدم وجود بيانات -->
        <?php if(empty($reports)): ?>
        <div class="text-center py-24 bg-slate-900/50 rounded-3xl border-2 border-dashed border-slate-800">
            <i class="fas fa-folder-open text-slate-700 text-6xl mb-6"></i>
            <p class="text-slate-500 font-bold text-xl tracking-widest">السجل فارغ تماماً</p>
        </div>
        <?php endif; ?>

    </div>
</body>
</html>