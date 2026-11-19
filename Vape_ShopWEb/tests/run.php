<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}\n";
}

function expect_throw(string $label, callable $fn): void
{
    try {
        $fn();
        check($label, false);
    } catch (InvalidArgumentException $e) {
        check($label, true);
    }
}

/* ---------- Pricing ---------- */

$t = order_totals(1000.00);
check('subtotal is preserved', abs($t['subtotal'] - 1000.00) < 0.001);
check('shipping is added once', abs($t['shipping'] - 150.00) < 0.001);
check('total excludes a VAT add-on', abs($t['total'] - 1150.00) < 0.001);
check('VAT included portion is 107.14', abs($t['vatIncludedAmount'] - 107.14) < 0.001);
check('free shipping applies at threshold', abs(order_totals(2000.00)['shipping']) < 0.001);
check('empty cart is zero', abs(order_totals(0)['total']) < 0.001);

/* ---------- Payments ---------- */

$p = validate_payments([
    ['method' => 'GCASH', 'amount' => 600.00],
    ['method' => 'CASH',  'amount' => 400.00],
], 1000.00);
check('split payment accepted', count($p) === 2);
check('methods are normalized', $p[0]['method'] === 'GCASH' && $p[1]['method'] === 'CASH');

expect_throw('duplicate method rejected', static fn() => validate_payments([
    ['method' => 'GCASH', 'amount' => 500.00],
    ['method' => 'GCASH', 'amount' => 500.00],
], 1000.00));

expect_throw('sum mismatch rejected', static fn() => validate_payments([
    ['method' => 'GCASH', 'amount' => 500.00],
    ['method' => 'CASH',  'amount' => 400.00],
], 1000.00));

expect_throw('card rejected', static fn() => validate_payments([
    ['method' => 'CARD', 'amount' => 1000.00],
], 1000.00));

expect_throw('zero amount rejected', static fn() => validate_payments([
    ['method' => 'GCASH', 'amount' => 1000.00],
    ['method' => 'CASH',  'amount' => 0.00],
], 1000.00));

expect_throw('three methods rejected', static fn() => validate_payments([
    ['method' => 'GCASH', 'amount' => 400.00],
    ['method' => 'MAYA',  'amount' => 300.00],
    ['method' => 'CASH',  'amount' => 300.00],
], 1000.00));

$single = validate_payments([
    ['method' => 'MAYA', 'amount' => 1000.00],
], 1000.00);
check('single payment accepted', count($single) === 1);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
