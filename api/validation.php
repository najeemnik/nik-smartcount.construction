<?php
/** Exact nonnegative two-decimal amounts; no silent rounding or scientific notation. */
function moneyMinor($value): int {
    if (!is_string($value) && !is_int($value) && !is_float($value)) jsonResponse(false,null,'مبلغ معتبر نیست.',400);
    $text = (string)$value;
    if (!preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D', $text, $m)) jsonResponse(false,null,'مبلغ باید غیرمنفی و حداکثر دو رقم اعشار داشته باشد.',400);
    return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
}
