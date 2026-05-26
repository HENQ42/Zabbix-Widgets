<?php declare(strict_types = 0);

/**
 * SLA Card widget view.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

$theme = (int) ($data['theme'] ?? 0);
$theme_attr = $theme === 1 ? 'dark' : 'light';

$slo = (float) ($data['slo'] ?? 0.0);

// Fixed 95–100 scale for the bullet bar and sparkline — the range that matters
// when SLOs sit around 99%. Floor stays at 95% even if SLO is lower.
$bar_floor = 95.0;
$bar_span = 100.0 - $bar_floor;

$bar_width = static function (float $pct) use ($bar_floor, $bar_span): float {
	$w = (($pct - $bar_floor) / $bar_span) * 100.0;

	if ($w < 0.0) {
		return 0.0;
	}

	if ($w > 100.0) {
		return 100.0;
	}

	return $w;
};

$target_left = (($slo - $bar_floor) / $bar_span) * 100.0;
$target_left = max(0.0, min(100.0, $target_left));

$fmt_duration = static function (?int $seconds): string {
	if ($seconds === null) {
		return '—';
	}

	$seconds = max(0, $seconds);

	if ($seconds === 0) {
		return '0min';
	}

	$d = intdiv($seconds, 86400);
	$h = intdiv($seconds % 86400, 3600);
	$m = intdiv($seconds % 3600, 60);

	if ($d > 0) {
		return $d.'d '.$h.'h';
	}

	if ($h > 0) {
		return $h.'h '.str_pad((string) $m, 2, '0', STR_PAD_LEFT).'min';
	}

	return $m.'min';
};

$fmt_pct = static function (?float $pct): string {
	if ($pct === null) {
		return '—';
	}

	return number_format($pct, 2, ',', '.').'%';
};

$status_meta = static function (string $s): array {
	switch ($s) {
		case 'crit':
			return ['label' => 'Crítico',   'pill' => 's-crit',      'fg' => 'is-crit', 'bar' => 'var(--crit)'];
		case 'warn':
			return ['label' => 'Atenção',   'pill' => 's-warn',      'fg' => 'is-warn', 'bar' => 'var(--warn)'];
		case 'excellent':
			return ['label' => 'Excelente', 'pill' => 's-excellent', 'fg' => 'is-ok',   'bar' => 'var(--excellent)'];
		case 'nodata':
			return ['label' => 'Sem dados', 'pill' => 's-nodata',    'fg' => 'is-mute', 'bar' => 'var(--fg-subtle)'];
		case 'ok':
		default:
			return ['label' => 'Dentro do SLA', 'pill' => 's-ok', 'fg' => 'is-ok', 'bar' => 'var(--ok)'];
	}
};

$state_meta = static function (string $s): array {
	switch ($s) {
		case 'crit':
			return ['pill' => 's-crit', 'dot' => 'var(--crit)'];
		case 'warn':
			return ['pill' => 's-warn', 'dot' => 'var(--warn)'];
		case 'nodata':
			return ['pill' => 's-nodata', 'dot' => 'var(--fg-subtle)'];
		case 'ok':
		default:
			return ['pill' => 's-ok', 'dot' => 'var(--ok)'];
	}
};

$css = <<<CSS
.scard-wrap {
	--font-ui:   "DM Sans", system-ui, -apple-system, sans-serif;
	--font-mono: "JetBrains Mono", ui-monospace, "SF Mono", Menlo, monospace;

	--surface:    oklch(1 0 0);
	--surface-2:  oklch(0.975 0.003 95);
	--surface-3:  oklch(0.955 0.005 95);
	--bg-page:    oklch(0.985 0.003 95);

	--fg:         oklch(0.22 0.015 255);
	--fg-muted:   oklch(0.48 0.012 255);
	--fg-subtle:  oklch(0.65 0.01 255);

	--border:        oklch(0.91 0.005 255);
	--border-strong: oklch(0.83 0.008 255);
	--divider:       oklch(0.94 0.004 255);

	--excellent:    oklch(0.62 0.13 158);
	--excellent-bg: oklch(0.95 0.04 158);
	--excellent-fg: oklch(0.38 0.10 158);

	--ok:    oklch(0.68 0.13 150);
	--ok-bg: oklch(0.96 0.04 150);
	--ok-fg: oklch(0.42 0.10 150);

	--warn:    oklch(0.74 0.15 78);
	--warn-bg: oklch(0.965 0.05 85);
	--warn-fg: oklch(0.50 0.13 60);

	--crit:    oklch(0.62 0.19 25);
	--crit-bg: oklch(0.95 0.04 25);
	--crit-fg: oklch(0.45 0.16 25);

	--r-sm: 6px;
	--r-md: 10px;
	--r-lg: 14px;

	--shadow-sm: 0 1px 2px oklch(0.2 0.01 255 / 0.04);

	height: 100%;
	box-sizing: border-box;
	padding: 0;
	font-family: var(--font-ui);
	color: var(--fg);
	background: #fff;
	overflow: hidden;
}
.scard-wrap[data-theme="dark"] {
	--surface:    oklch(0.22 0.012 255);
	--surface-2:  oklch(0.26 0.012 255);
	--surface-3:  oklch(0.30 0.012 255);
	--bg-page:    oklch(0.18 0.012 255);

	--fg:         oklch(0.92 0.006 255);
	--fg-muted:   oklch(0.72 0.008 255);
	--fg-subtle:  oklch(0.58 0.008 255);

	--border:        oklch(0.34 0.008 255);
	--border-strong: oklch(0.44 0.008 255);
	--divider:       oklch(0.30 0.008 255);

	--excellent-bg: oklch(0.30 0.08 158);
	--excellent-fg: oklch(0.85 0.10 158);

	--ok-bg: oklch(0.30 0.08 150);
	--ok-fg: oklch(0.85 0.12 150);

	--warn-bg: oklch(0.32 0.09 85);
	--warn-fg: oklch(0.88 0.13 85);

	--crit-bg: oklch(0.32 0.10 25);
	--crit-fg: oklch(0.88 0.12 25);

	--shadow-sm: 0 1px 2px oklch(0.05 0.01 255 / 0.4);
}

.scard-error {
	display: flex; align-items: center; justify-content: center;
	height: 100%;
	color: var(--fg-muted);
	font-size: 13px; font-style: italic;
	text-align: center;
	padding: 16px;
}

.scard {
	font-family: var(--font-ui);
	color: var(--fg);
	background: #fff;
	border: 0;
	border-radius: 0;
	box-shadow: none;
	overflow: hidden;
	font-size: 14px;
	line-height: 1.45;
	height: 100%;
	display: flex;
	flex-direction: column;
	min-height: 0;
}
.scard-head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	padding: 14px 20px 10px;
	border-bottom: 1px solid var(--divider);
	flex-shrink: 0;
}
.scard-title {
	font-size: 15px; font-weight: 600; letter-spacing: -0.01em;
	margin: 0; color: var(--fg);
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.scard-title a, .scard-title a:visited { color: inherit; text-decoration: none; cursor: pointer; }
.scard-title a:hover { text-decoration: underline; }
.scard-sub { font-size: 12px; color: var(--fg-muted); margin-top: 2px; }
.scard-meta { font-size: 12px; color: var(--fg-muted); text-align: right; flex-shrink: 0; }
.scard-meta .strong { color: var(--fg); }

.scard-body {
	padding: 16px 20px 18px;
	display: flex;
	flex-direction: column;
	gap: 14px;
	flex: 1 1 auto;
	min-height: 0;
}

/* ---- Main pct row ---- */
.scard-main {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}
.scard-pct {
	font-family: var(--font-mono);
	font-variant-numeric: tabular-nums;
	font-size: 38px;
	font-weight: 500;
	letter-spacing: -0.02em;
	line-height: 1;
	color: var(--fg);
}
.scard-pct.is-crit { color: var(--crit-fg); }
.scard-pct.is-warn { color: var(--warn-fg); }
.scard-pct.is-ok   { color: var(--ok-fg); }
.scard-pct.is-mute { color: var(--fg-subtle); }

.status-pill {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 4px 10px; border-radius: 999px;
	font-size: 11px; font-weight: 500; letter-spacing: 0.01em;
	white-space: nowrap;
}
.status-pill .pip {
	width: 6px; height: 6px; border-radius: 50%;
	background: currentColor;
}
.s-excellent { background: var(--excellent-bg); color: var(--excellent-fg); }
.s-ok        { background: var(--ok-bg);        color: var(--ok-fg); }
.s-warn      { background: var(--warn-bg);      color: var(--warn-fg); }
.s-crit      { background: var(--crit-bg);      color: var(--crit-fg); }
.s-nodata    { background: var(--surface-3);    color: var(--fg-muted); }

/* ---- Bullet bar (95–100 scale) ---- */
.scard-bar-wrap { display: flex; flex-direction: column; gap: 4px; }
.scard-wrap .bbar {
	position: relative;
	height: 8px;
	width: 100%;
	background: var(--surface-3);
	border-radius: 999px;
}
.scard-wrap .bbar-fill {
	position: absolute;
	top: 0; left: 0;
	height: 100%;
	min-width: 2px;
	border-radius: 999px;
	display: block;
}
.scard-wrap .bbar-target {
	position: absolute;
	top: -3px; bottom: -3px;
	width: 2px;
	background: var(--fg);
	opacity: 0.6;
	border-radius: 1px;
	display: block;
}
.scard-bar-axis {
	display: flex; justify-content: space-between;
	font-family: var(--font-mono); font-variant-numeric: tabular-nums;
	font-size: 11px; color: var(--fg-subtle);
}

/* ---- 4-stat row ---- */
.scard-stats {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 10px;
	padding-top: 4px;
	border-top: 1px solid var(--divider);
}
.scard-stat .v {
	font-family: var(--font-mono); font-variant-numeric: tabular-nums;
	font-size: 16px; font-weight: 500; color: var(--fg);
	display: flex; align-items: center; gap: 6px;
}
.scard-stat .v .pip {
	width: 8px; height: 8px; border-radius: 50%;
	flex-shrink: 0;
}
.scard-stat .l {
	font-size: 11px; color: var(--fg-muted); margin-top: 2px;
}

/* ---- Sparkline ---- */
.scard-spark {
	display: flex; flex-direction: column; gap: 4px;
	padding-top: 8px;
	border-top: 1px solid var(--divider);
}
.scard-spark-head {
	display: flex; justify-content: space-between; align-items: baseline;
	font-size: 11px; color: var(--fg-muted);
}
.scard-spark-head .label {
	letter-spacing: 0.08em; text-transform: uppercase;
}
.scard-spark-head .summary {
	font-family: var(--font-mono); font-variant-numeric: tabular-nums;
	color: var(--fg);
}
.scard-spark svg {
	display: block; width: 100%; height: 64px;
}
.scard-spark svg .area { opacity: 0.18; }
.scard-spark svg .line { fill: none; stroke-width: 1.5; }
.scard-spark svg .slo {
	stroke: var(--fg);
	stroke-width: 1;
	stroke-dasharray: 3 3;
	opacity: 0.45;
}
CSS;

$wrap = (new CDiv())
	->addClass('scard-wrap')
	->setAttribute('data-theme', $theme_attr);

if (!empty($data['error'])) {
	$wrap->addItem((new CDiv($data['error']))->addClass('scard-error'));

	$view->addItem([
		new CTag('style', true, $css),
		$wrap
	]);
	$view->show();

	return;
}

$card = (new CDiv())->addClass('scard');

// ---- Header ----
$title_text = $data['host_name'] !== ''
	? $data['host_name']
	: ($data['service_name'] ?? '—');

$title_node = (!empty($data['hostid']))
	? (new CLink($title_text, 'zabbix.php?action=host.dashboard.view&hostid='.$data['hostid']))
		->setAttribute('title', $title_text)
	: $title_text;

$head_left = (new CDiv())
	->addItem((new CTag('h3', true, $title_node))->addClass('scard-title'))
	->addItem(
		(new CDiv('Disponibilidade · '.$data['period_label']))->addClass('scard-sub')
	);

$head_right = (new CDiv())->addClass('scard-meta');
$head_right->addItem(new CDiv('Atualizado às'));
$head_right->addItem(
	(new CDiv(zbx_date2str(TIME_FORMAT, (int) $data['now'])))->addClass('strong')
);

$card->addItem(
	(new CDiv())
		->addClass('scard-head')
		->addItem([$head_left, $head_right])
);

// ---- Body ----
$body = (new CDiv())->addClass('scard-body');

$smeta = $status_meta($data['status']);
$emeta = $state_meta($data['state_color']);

// Main pct + status pill
$main_row = (new CDiv())->addClass('scard-main');
$main_row->addItem(
	(new CDiv($fmt_pct($data['sli'])))
		->addClass('scard-pct')
		->addClass($smeta['fg'])
);
$main_row->addItem(
	(new CSpan([(new CSpan(''))->addClass('pip'), $smeta['label']]))
		->addClass('status-pill')
		->addClass($smeta['pill'])
);
$body->addItem($main_row);

// Bullet bar
$bar_wrap = (new CDiv())->addClass('scard-bar-wrap');

$bar = (new CDiv())->addClass('bbar');
$fill_pct = $data['sli'] !== null ? $bar_width((float) $data['sli']) : 0.0;
$bar->addItem(
	(new CSpan(''))
		->addClass('bbar-fill')
		->addStyle(sprintf(
			'width: %s%%; background: %s;',
			number_format($fill_pct, 2, '.', ''),
			$smeta['bar']
		))
);
$bar->addItem(
	(new CSpan(''))
		->addClass('bbar-target')
		->addStyle(sprintf(
			'left: calc(%s%% - 1px);',
			number_format($target_left, 2, '.', '')
		))
);
$bar_wrap->addItem($bar);

$bar_axis = (new CDiv())->addClass('scard-bar-axis');
$bar_axis->addItem(new CSpan(number_format($bar_floor, 0, ',', '.').'%'));
$bar_axis->addItem(new CSpan('100%'));
$bar_wrap->addItem($bar_axis);

$body->addItem($bar_wrap);

// 4-stat row
$stats = (new CDiv())->addClass('scard-stats');

// Estado atual
$state_v = (new CDiv())->addClass('v');
$state_v->addItem(
	(new CSpan(''))
		->addClass('pip')
		->addStyle('background: '.$emeta['dot'].';')
);
$state_v->addItem(new CSpan($data['state_label']));
$stats->addItem(
	(new CDiv())
		->addClass('scard-stat')
		->addItem($state_v)
		->addItem((new CDiv('Estado atual'))->addClass('l'))
);

// Meta
$stats->addItem(
	(new CDiv())
		->addClass('scard-stat')
		->addItem((new CDiv($fmt_pct($slo)))->addClass('v'))
		->addItem((new CDiv('Meta'))->addClass('l'))
);

// Indisponível
$stats->addItem(
	(new CDiv())
		->addClass('scard-stat')
		->addItem((new CDiv($fmt_duration((int) $data['downtime'])))->addClass('v'))
		->addItem((new CDiv('Indisponível'))->addClass('l'))
);

// Ocorrências (24h)
$stats->addItem(
	(new CDiv())
		->addClass('scard-stat')
		->addItem((new CDiv((string) (int) $data['incidents_24h']))->addClass('v'))
		->addItem((new CDiv('Ocorrências (24h)'))->addClass('l'))
);

$body->addItem($stats);

// ---- Sparkline ----
$spark_section = (new CDiv())->addClass('scard-spark');

$summary_text = $data['uptime_24h_pct'] !== null
	? $fmt_pct((float) $data['uptime_24h_pct']).
		' · '.((int) $data['incidents_24h'] === 0
			? 'sem quedas'
			: (int) $data['incidents_24h'].((int) $data['incidents_24h'] === 1 ? ' queda' : ' quedas'))
	: '—';

$spark_head = (new CDiv())->addClass('scard-spark-head');
$spark_head->addItem((new CSpan('Últimas 24h'))->addClass('label'));
$spark_head->addItem((new CSpan($summary_text))->addClass('summary'));
$spark_section->addItem($spark_head);

// Build SVG sparkline.
$sparkline = $data['sparkline'] ?? array_fill(0, 24, 100.0);
$svg_w = 240;
$svg_h = 64;
$count = max(1, count($sparkline));
$step = $svg_w / max(1, $count - 1);

$y_for = static function (float $pct) use ($svg_h, $bar_floor, $bar_span): float {
	$normalized = ($pct - $bar_floor) / $bar_span;

	if ($normalized < 0.0) {
		$normalized = 0.0;
	}
	elseif ($normalized > 1.0) {
		$normalized = 1.0;
	}

	return $svg_h - $normalized * $svg_h;
};

$points = [];

foreach ($sparkline as $i => $pct) {
	$x = $i * $step;
	$y = $y_for((float) $pct);
	$points[] = [$x, $y];
}

$line_d = '';
$area_d = '';

if ($points) {
	$line_parts = [];

	foreach ($points as $idx => $p) {
		$line_parts[] = ($idx === 0 ? 'M ' : 'L ').
			number_format($p[0], 2, '.', '').','.number_format($p[1], 2, '.', '');
	}

	$line_d = implode(' ', $line_parts);

	$first = $points[0];
	$last = $points[count($points) - 1];

	$area_d = 'M '.number_format($first[0], 2, '.', '').','.number_format($svg_h, 2, '.', '').' '.
		$line_d.' '.
		'L '.number_format($last[0], 2, '.', '').','.number_format($svg_h, 2, '.', '').' Z';
}

$slo_y = $y_for($slo);

$svg = (new CTag('svg', true))
	->setAttribute('viewBox', '0 0 '.$svg_w.' '.$svg_h)
	->setAttribute('preserveAspectRatio', 'none')
	->setAttribute('xmlns', 'http://www.w3.org/2000/svg');

if ($area_d !== '') {
	$svg->addItem(
		(new CTag('path', true))
			->setAttribute('class', 'area')
			->setAttribute('d', $area_d)
			->setAttribute('fill', $smeta['bar'])
	);
}

if ($line_d !== '') {
	$svg->addItem(
		(new CTag('path', true))
			->setAttribute('class', 'line')
			->setAttribute('d', $line_d)
			->setAttribute('stroke', $smeta['bar'])
	);
}

$svg->addItem(
	(new CTag('line', true))
		->setAttribute('class', 'slo')
		->setAttribute('x1', '0')
		->setAttribute('y1', number_format($slo_y, 2, '.', ''))
		->setAttribute('x2', (string) $svg_w)
		->setAttribute('y2', number_format($slo_y, 2, '.', ''))
);

$spark_section->addItem($svg);
$body->addItem($spark_section);

$card->addItem($body);
$wrap->addItem($card);

$view->addItem([
	new CTag('style', true, $css),
	$wrap
]);

$view->show();
