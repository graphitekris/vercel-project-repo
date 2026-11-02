<?php
declare(strict_types=1);

function render_line_chart(array $series, array $labels, array $opts = []): string
{
    $width    = $opts['width']  ?? 600;
    $height   = $opts['height'] ?? 160;
    $padTop   = 20;
    $padBot   = 40;
    $padLeft  = 34;
    $padRight = 34;

    $chartW = $width - $padLeft - $padRight;
    $chartH = $height - $padTop - $padBot;

    $max = 0;
    foreach ($series as $s) {
        foreach ($s['values'] as $v) if ($v > $max) $max = (float)$v;
    }
    if ($max <= 0) $max = 1;

    $count = max(count($labels), 1);
    $stepX = $chartW / max($count - 1, 1);

    $grid = '';
    for ($i = 0; $i <= 4; $i++) {
        $y = $padTop + ($chartH * $i / 4);
        $grid .= sprintf(
            '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="rgba(255,255,255,0.05)" stroke-dasharray="3 3"/>',
            $padLeft, $y, $width - $padRight, $y
        );
    }

    $lines = '';
    foreach ($series as $s) {
        $points = [];
        foreach ($s['values'] as $i => $v) {
            $x = $padLeft + $i * $stepX;
            $y = $padTop + $chartH - (($v / $max) * $chartH);
            $points[] = sprintf('%s,%s', round($x, 1), round($y, 1));
        }
        if (count($points) >= 2) {
            $lines .= sprintf(
                '<polyline points="%s" fill="none" stroke="%s" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>',
                implode(' ', $points),
                htmlspecialchars($s['color'])
            );
        }
    }

    $xLabels = '';
    $totalPoints = count($labels);
    if ($totalPoints > 0) {
        $minGapPx   = 60;
        $maxLabels  = max(2, (int)floor($chartW / $minGapPx));
        $labelEvery = max(1, (int)ceil($totalPoints / $maxLabels));

        $candidates = [];
        for ($i = 0; $i < $totalPoints; $i++) {
            if ($i === 0 || $i === $totalPoints - 1 || $i % $labelEvery === 0) {
                $candidates[] = $i;
            }
        }

        $kept = [];
        $lastX = -PHP_FLOAT_MAX;
        foreach ($candidates as $idx) {
            $x = $padLeft + $idx * $stepX;
            if ($idx === 0) { $kept[] = $idx; $lastX = $x; continue; }
            if (($x - $lastX) < $minGapPx) continue;
            $kept[] = $idx;
            $lastX = $x;
        }

        foreach ($kept as $idx) {
            $x = $padLeft + $idx * $stepX;
            $anchor = 'middle';
            if ($idx === 0) $anchor = 'start';
            if ($idx === $totalPoints - 1) $anchor = 'end';

            $xLabels .= sprintf(
                '<text x="%s" y="%s" fill="#8b949e" font-size="10" text-anchor="%s">%s</text>',
                round($x, 1),
                $height - 8,
                $anchor,
                htmlspecialchars((string)$labels[$idx])
            );
        }
    }

    return <<<SVG
<svg viewBox="0 0 {$width} {$height}" preserveAspectRatio="none" style="width:100%;height:100%;">
    {$grid}
    {$lines}
    {$xLabels}
</svg>
SVG;
}

function render_donut_chart(array $slices, array $opts = []): string
{
    $size    = $opts['size']   ?? 140;
    $strokeW = $opts['stroke'] ?? 18;
    $r       = ($size - $strokeW) / 2;
    $circum  = 2 * M_PI * $r;

    $total = 0;
    foreach ($slices as $s) $total += (float)$s['value'];
    if ($total <= 0) $total = 1;

    $cx = $size / 2;
    $cy = $size / 2;
    $offset = 0;

    $circles = '';
    foreach ($slices as $s) {
        $fraction = (float)$s['value'] / $total;
        $len      = $fraction * $circum;
        $circles .= sprintf(
            '<circle cx="%1$s" cy="%2$s" r="%3$s" fill="none" stroke="%4$s" stroke-width="%5$s" stroke-dasharray="%6$s %7$s" stroke-dashoffset="%8$s"/>',
            $cx, $cy, $r,
            htmlspecialchars($s['color']),
            $strokeW,
            round($len, 2),
            round($circum - $len, 2),
            round(-$offset, 2)
        );
        $offset += $len;
    }

    return <<<SVG
<svg viewBox="0 0 {$size} {$size}" style="width:{$size}px;height:{$size}px;transform:rotate(-90deg);">
    {$circles}
</svg>
SVG;
}

function render_bar_chart(array $bars, array $opts = []): string
{
    $width  = $opts['width']  ?? 600;
    $height = $opts['height'] ?? 140;
    $padTop = 10;
    $padBot = 24;
    $padLeft  = 20;
    $padRight = 20;

    $chartW = $width - $padLeft - $padRight;
    $chartH = $height - $padTop - $padBot;

    $max = 0;
    foreach ($bars as $b) if ($b['value'] > $max) $max = (float)$b['value'];
    if ($max <= 0) $max = 1;

    $count = max(count($bars), 1);
    $slotW = $chartW / $count;
    $barW  = $slotW * 0.55;

    $shapes = '';
    foreach ($bars as $i => $b) {
        $h = ($b['value'] / $max) * $chartH;
        $x = $padLeft + $i * $slotW + ($slotW - $barW) / 2;
        $y = $padTop + $chartH - $h;

        $shapes .= sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" rx="3" fill="%s"/>',
            round($x, 1), round($y, 1), round($barW, 1), round($h, 1),
            htmlspecialchars($b['color'] ?? '#007acc')
        );
        $shapes .= sprintf(
            '<text x="%s" y="%s" fill="#8b949e" font-size="10" text-anchor="middle">%s</text>',
            round($x + $barW / 2, 1),
            $height - 6,
            htmlspecialchars((string)$b['label'])
        );
    }

    return <<<SVG
<svg viewBox="0 0 {$width} {$height}" preserveAspectRatio="none" style="width:100%;height:100%;">
    {$shapes}
</svg>
SVG;
}

function book_value_at(float $cost, float $salvage, int $lifeMonths, int $monthsElapsed): float
{
    if ($lifeMonths <= 0) return $cost;
    if ($monthsElapsed >= $lifeMonths) return $salvage;
    if ($monthsElapsed <= 0) return $cost;
    $depreciated = ($cost - $salvage) * ($monthsElapsed / $lifeMonths);
    return max($salvage, $cost - $depreciated);
}