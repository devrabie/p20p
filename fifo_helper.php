<?php
/**
 * FIFO Helper for Ledger Pro
 * المحرك المحاسبي لنظام FIFO
 */

function recalculateFIFO($pdo, $user_id) {
    // 1. جلب جميع العمليات مرتبة زمنياً - تحسين: جلب الأعمدة الضرورية فقط لتقليل استهلاك الذاكرة
    $stmt = $pdo->prepare("SELECT id, type, crypto_amount, total_fiat_paid, total_crypto_deducted, fifo_cost_basis, fifo_profit FROM transactions WHERE user_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$user_id]);
    $transactions = $stmt->fetchAll();

    $buy_queue = []; // مخزن طبقات الشراء المتوفرة

    foreach ($transactions as $tx) {
        if ($tx['type'] == 'buy') {
            $net_qty = round(floatval($tx['crypto_amount']), 4);
            $total_cost = floatval($tx['total_fiat_paid']);
            $unit_cost = ($net_qty > 0) ? ($total_cost / $net_qty) : 0;

            $buy_queue[] = [
                'id' => $tx['id'],
                'remaining_qty' => $net_qty,
                'unit_cost' => $unit_cost
            ];

            // في الشراء، التكلفة والربح دائماً 0 - نحدث فقط إذا لزم الأمر لتقليل ضغط الكتابة
            if (floatval($tx['fifo_cost_basis']) != 0 || floatval($tx['fifo_profit']) != 0) {
                $update = $pdo->prepare("UPDATE transactions SET fifo_cost_basis = 0, fifo_profit = 0 WHERE id = ?");
                $update->execute([$tx['id']]);
            }

        } else if ($tx['type'] == 'sell') {
            $sell_qty = round(floatval($tx['total_crypto_deducted']), 4);
            $sell_fiat = floatval($tx['total_fiat_paid']);
            $total_cost_basis = 0;
            $remaining_to_match = $sell_qty;

            // مطابقة الكمية المباعة مع طبقات الشراء (FIFO)
            reset($buy_queue);
            while ($remaining_to_match > 0.0001 && count($buy_queue) > 0) {
                $key = key($buy_queue);
                $current_buy = &$buy_queue[$key];

                if ($current_buy['remaining_qty'] <= $remaining_to_match) {
                    // استهلاك كامل الطبقة
                    $matched_qty = $current_buy['remaining_qty'];
                    $total_cost_basis += $matched_qty * $current_buy['unit_cost'];
                    $remaining_to_match -= $matched_qty;
                    array_shift($buy_queue); // إزالة الطبقة المستهلكة
                } else {
                    // استهلاك جزء من الطبقة
                    $matched_qty = $remaining_to_match;
                    $total_cost_basis += $matched_qty * $current_buy['unit_cost'];
                    $current_buy['remaining_qty'] -= $matched_qty;
                    $remaining_to_match = 0;
                }
            }

            // التعامل مع البيع المكشوف (في حال حدوثه رغم المنع)
            if ($remaining_to_match > 0.0001) {
                $settings_stmt = $pdo->prepare("SELECT default_buy_price FROM settings WHERE user_id = ?");
                $settings_stmt->execute([$user_id]);
                $def_buy = $settings_stmt->fetchColumn() ?: 535;
                $total_cost_basis += $remaining_to_match * $def_buy;
            }

            $profit = $sell_fiat - $total_cost_basis;

            $new_cost_basis = round($total_cost_basis, 2);
            $new_profit = round($profit, 2);

            // تحديث فقط إذا كانت القيم قد تغيرت فعلياً (يوفر الكثير من الوقت مع كثرة البيانات)
            if (floatval($tx['fifo_cost_basis']) != $new_cost_basis || floatval($tx['fifo_profit']) != $new_profit) {
                $update = $pdo->prepare("UPDATE transactions SET fifo_cost_basis = ?, fifo_profit = ? WHERE id = ?");
                $update->execute([$new_cost_basis, $new_profit, $tx['id']]);
            }
        }
    }
}

/**
 * حساب المخزون الحالي المتاح
 */
function getFIFOStock($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN type='buy' THEN crypto_amount ELSE 0 END) -
            SUM(CASE WHEN type='sell' THEN total_crypto_deducted ELSE 0 END) as stock
        FROM transactions
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    return round(floatval($stmt->fetchColumn() ?: 0), 4);
}

/**
 * جلب طبقات الشراء الحالية والمتوفرة (نظام FIFO)
 */
function getFIFOLayers($pdo, $user_id, $limit = 2) {
    $stmt = $pdo->prepare("SELECT id, type, crypto_amount, total_fiat_paid, total_crypto_deducted FROM transactions WHERE user_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$user_id]);
    $transactions = $stmt->fetchAll();

    $buy_queue = [];
    foreach ($transactions as $tx) {
        if ($tx['type'] == 'buy') {
            $net_qty = round(floatval($tx['crypto_amount']), 4);
            $buy_queue[] = [
                'remaining_qty' => $net_qty,
                'unit_cost' => ($net_qty > 0) ? ($tx['total_fiat_paid'] / $net_qty) : 0
            ];
        } else if ($tx['type'] == 'sell') {
            $remaining_to_match = round(floatval($tx['total_crypto_deducted']), 4);
            while ($remaining_to_match > 0.0001 && count($buy_queue) > 0) {
                if ($buy_queue[0]['remaining_qty'] <= $remaining_to_match) {
                    $remaining_to_match -= $buy_queue[0]['remaining_qty'];
                    array_shift($buy_queue);
                } else {
                    $buy_queue[0]['remaining_qty'] -= $remaining_to_match;
                    $remaining_to_match = 0;
                }
            }
        }
    }

    return array_slice($buy_queue, 0, $limit);
}

/**
 * جلب معلومات طبقة الشراء القادمة للمستشار الذكي (للتوافق)
 */
function getNextFIFOLayer($pdo, $user_id) {
    $layers = getFIFOLayers($pdo, $user_id, 1);
    return !empty($layers) ? $layers[0] : null;
}
