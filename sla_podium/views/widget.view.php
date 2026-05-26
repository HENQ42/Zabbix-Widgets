<?php declare(strict_types = 0);

/**
 * SLA Podium widget view.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

$theme = (int) ($data['theme'] ?? 0);
$theme_attr = $theme === 1 ? 'dark' : 'light';

$slo = (float) ($data['slo'] ?? 0.0);

// Bullet-bar scale. Floor is the left edge of the bar; everything below the
// floor is clamped to zero width so very bad values still render visibly.
$scale_floor = max(80.0, $slo - 10.0);
$scale_span = max(0.1, 100.0 - $scale_floor);

$bar_width = static function (float $pct) use ($scale_floor, $scale_span): float {
	$w = (($pct - $scale_floor) / $scale_span) * 100.0;

	if ($w < 0.0) {
		return 0.0;
	}

	if ($w > 100.0) {
		return 100.0;
	}

	return $w;
};

$target_left = (($slo - $scale_floor) / $scale_span) * 100.0;
$target_left = max(0.0, min(100.0, $target_left));

// Format seconds as "Xh Ymin" (or "Ymin" when under an hour, "Xd Yh" past a day).
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

// Format an SLI percentage as "99,12%" (pt-BR).
$fmt_pct = static function (float $pct): string {
	return number_format($pct, 2, ',', '.').'%';
};

// Gap from the SLO in percentage points, signed.
$fmt_gap = static function (float $pct) use ($slo): string {
	$gap = $pct - $slo;
	$sign = $gap >= 0 ? '+' : '−';

	return $sign.number_format(abs($gap), 2, ',', '.').' pp';
};

// Map a 'crit'|'warn'|'ok'|'excellent' status to its label + pill class + pct class.
$status_meta = static function (string $s): array {
	switch ($s) {
		case 'crit':
			return ['label' => 'Crítico',  'pill' => 's-crit',      'pct' => 'is-crit', 'bar' => 'var(--crit)'];
		case 'warn':
			return ['label' => 'Atenção',  'pill' => 's-warn',      'pct' => 'is-warn', 'bar' => 'var(--warn)'];
		case 'excellent':
			return ['label' => 'Excelente','pill' => 's-excellent', 'pct' => 'is-ok',   'bar' => 'var(--excellent)'];
		case 'ok':
		default:
			return ['label' => 'Na meta',  'pill' => 's-ok',        'pct' => 'is-ok',   'bar' => 'var(--ok)'];
	}
};

$css = <<<CSS
.slap-wrap {
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
.slap-wrap[data-theme="dark"] {
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

.slap-error {
	display: flex; align-items: center; justify-content: center;
	height: 100%;
	color: var(--fg-muted);
	font-size: 13px; font-style: italic;
}

.widget {
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
.widget-head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	padding: 14px 20px 10px;
	border-bottom: 1px solid var(--divider);
	flex-shrink: 0;
}
.widget-title { font-size: 15px; font-weight: 600; letter-spacing: -0.01em; margin: 0; color: var(--fg); }
.widget-sub   { font-size: 12px; color: var(--fg-muted); margin-top: 2px; }
.widget-meta  { font-size: 12px; color: var(--fg-muted); display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
.widget-meta .strong { color: var(--fg); }
.widget-meta .dot { width: 4px; height: 4px; border-radius: 50%; background: var(--border-strong); display: inline-block; }
.widget-body  {
	padding: 14px 20px 18px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	flex: 1 1 auto;
	min-height: 0;
}

.mono { font-family: var(--font-mono); }

.status-pill {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 3px 8px; border-radius: 999px;
	font-size: 11px; font-weight: 500; letter-spacing: 0.01em;
}
.status-pill .pip { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.s-excellent { background: var(--excellent-bg); color: var(--excellent-fg); }
.s-ok        { background: var(--ok-bg);        color: var(--ok-fg); }
.s-warn      { background: var(--warn-bg);      color: var(--warn-fg); }
.s-crit      { background: var(--crit-bg);      color: var(--crit-fg); }

.slap-wrap .bbar {
	position: relative;
	height: 6px;
	width: 100%;
	background: var(--surface-3);
	border-radius: 999px;
	overflow: visible;
}
.slap-wrap .bbar-fill {
	position: absolute;
	top: 0;
	left: 0;
	height: 100%;
	min-width: 2px;
	border-radius: 999px;
	display: block;
	box-sizing: border-box;
}
.slap-wrap .bbar-target {
	position: absolute;
	top: -3px;
	bottom: -3px;
	width: 2px;
	background: var(--fg);
	opacity: 0.6;
	border-radius: 1px;
	display: block;
}

.podium-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 10px;
	flex-shrink: 0;
}

.podium-card {
	border: 1px solid var(--border);
	border-radius: var(--r-md);
	background: var(--surface-2);
	padding: 12px 14px;
	display: flex; flex-direction: column; gap: 8px;
	min-width: 0;
}
.podium-card.is-crit    { background: var(--crit-bg);    border-color: transparent; }
.podium-card.is-good    { background: var(--ok-bg);      border-color: transparent; }
.podium-card.is-summary { background: var(--surface); }

.podium-card-head {
	display: flex; justify-content: space-between; align-items: center;
}
.podium-badge {
	font-size: 10.5px; font-weight: 600;
	letter-spacing: 0.08em; text-transform: uppercase;
	color: var(--fg-subtle);
}
.podium-card.is-crit .podium-badge { color: var(--crit-fg); }
.podium-card.is-good .podium-badge { color: var(--ok-fg); }

.podium-name {
	font-weight: 600; font-size: 12.5px;
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
	color: var(--fg);
}
.podium-name a, .podium-row .name a,
.podium-name a:visited, .podium-row .name a:visited { color: inherit; text-decoration: none; cursor: pointer; }
.podium-name a:hover, .podium-row .name:hover { text-decoration: underline; }
.podium-row a.name { cursor: pointer; }
.podium-pct {
	font-family: var(--font-mono); font-variant-numeric: tabular-nums;
	font-size: 22px; font-weight: 500;
	letter-spacing: -0.02em; line-height: 1;
	color: var(--fg);
}
.podium-pct.is-crit { color: var(--crit-fg); }
.podium-pct.is-warn { color: var(--warn-fg); }
.podium-pct.is-ok   { color: var(--ok-fg); }

.podium-foot {
	display: flex; justify-content: space-between;
	font-size: 11px; color: var(--fg-muted);
}

.summary-note { font-size: 11px; color: var(--fg-muted); }
.summary-grid {
	display: grid; grid-template-columns: 1fr 1fr;
	gap: 6px; margin-top: 2px;
}
.summary-stat .n {
	font-family: var(--font-mono); font-variant-numeric: tabular-nums;
	font-size: 14px; font-weight: 500;
}
.summary-stat .n.is-crit { color: var(--crit-fg); }
.summary-stat .n.is-ok   { color: var(--ok-fg); }
.summary-stat .l { font-size: 10.5px; color: var(--fg-muted); }

.hr { height: 1px; background: var(--divider); border: 0; margin: 0; flex-shrink: 0; }

.podium-list-wrap {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
	padding-right: 4px;
}
.podium-list { display: flex; flex-direction: column; }
.podium-row {
	display: grid;
	grid-template-columns: 22px 1fr 80px 90px 100px;
	align-items: center; gap: 14px;
	padding: 8px 0;
	border-bottom: 1px solid var(--divider);
}
.podium-row:last-child { border-bottom: 0; }
.podium-row .rank { color: var(--fg-subtle); font-size: 12px; font-family: var(--font-mono); }
.podium-row .name { font-weight: 500; font-size: 13px; color: var(--fg);
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.podium-row .pct  { text-align: right; font-weight: 500; font-family: var(--font-mono); font-variant-numeric: tabular-nums; color: var(--fg); }
.podium-row .pct.is-crit { color: var(--crit-fg); }
.podium-row .pct.is-warn { color: var(--warn-fg); }
.podium-row .pct.is-ok   { color: var(--ok-fg); }
.podium-row .off  { font-size: 12px; color: var(--fg-muted); text-align: right; font-family: var(--font-mono); font-variant-numeric: tabular-nums; }
.podium-row .sit  { text-align: right; }

.podium-empty {
	text-align: center;
	color: var(--fg-muted);
	font-size: 12px;
	font-style: italic;
	padding: 12px 0;
}
CSS;

$wrap = (new CDiv())
	->addClass('slap-wrap')
	->setAttribute('data-theme', $theme_attr);

// Error / empty state.
if (!empty($data['error'])) {
	$wrap->addItem((new CDiv($data['error']))->addClass('slap-error'));

	$view->addItem([
		new CTag('style', true, $css),
		$wrap
	]);
	$view->show();

	return;
}

// ---- Widget shell ----
$widget = (new CDiv())->addClass('widget');

// Header.
$head_left = (new CDiv())
	->addItem((new CTag('h3', true, 'Atenção imediata'))->addClass('widget-title'))
	->addItem((new CDiv('Top 3 piores · resumo da frota'))->addClass('widget-sub'));

$meta = (new CDiv())->addClass('widget-meta');
$meta->addItem(new CSpan('Período'));
$meta->addItem((new CSpan($data['period_label']))->addClass('strong'));

if (!empty($data['period_from']) && !empty($data['period_to'])) {
	$meta->addItem((new CSpan(''))->addClass('dot'));
	$meta->addItem(new CSpan(
		zbx_date2str(DATE_FORMAT, (int) $data['period_from']).' – '.
		zbx_date2str(DATE_FORMAT, (int) $data['period_to'])
	));
}

$meta->addItem((new CSpan(''))->addClass('dot'));
$meta->addItem(new CSpan('Meta'));
$meta->addItem(
	(new CSpan('≥ '.number_format($slo, 2, ',', '.').'%'))
		->addClass('mono')
		->addClass('strong')
);

$widget->addItem(
	(new CDiv())
		->addClass('widget-head')
		->addItem([$head_left, $meta])
);

// Body.
$body = (new CDiv())->addClass('widget-body');

// ---- Podium grid (3 worst + summary) ----
$grid = (new CDiv())->addClass('podium-grid');

$worst = $data['worst'] ?? [];
$rank_labels = ['#1 pior', '#2 pior', '#3 pior'];

for ($i = 0; $i < 3; $i++) {
	$entry = $worst[$i] ?? null;
	$card = (new CDiv())->addClass('podium-card');

	if ($entry !== null) {
		$meta_s = $status_meta($entry['status']);

		// The #1 worst gets a colored highlight: red when actually below the SLO,
		// green when even the worst service is meeting the configured target.
		if ($i === 0) {
			$card->addClass(!empty($entry['meets_slo']) ? 'is-good' : 'is-crit');
		}

		$head = (new CDiv())->addClass('podium-card-head');
		$head->addItem((new CSpan($rank_labels[$i]))->addClass('podium-badge'));
		$head->addItem(
			(new CSpan([(new CSpan(''))->addClass('pip'), $meta_s['label']]))
				->addClass('status-pill')
				->addClass($meta_s['pill'])
		);

		$bar = (new CDiv())->addClass('bbar');
		$bar->addItem(
			(new CSpan(''))
				->addClass('bbar-fill')
				->addStyle(sprintf(
					'width: %s%%; background: %s;',
					number_format($bar_width((float) $entry['sli']), 2, '.', ''),
					$meta_s['bar']
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

		$foot = (new CDiv())->addClass('podium-foot');
		$foot->addItem(new CSpan($fmt_duration($entry['downtime'])));
		$foot->addItem(new CSpan($fmt_gap((float) $entry['sli'])));

		$card->addItem($head);
		$name_node = (!empty($entry['hostid']))
			? (new CLink($entry['name'], 'zabbix.php?action=host.dashboard.view&hostid='.$entry['hostid']))
				->setAttribute('title', $entry['name'])
			: new CSpan($entry['name']);
		$card->addItem((new CDiv($name_node))->addClass('podium-name'));
		$card->addItem(
			(new CDiv($fmt_pct((float) $entry['sli'])))
				->addClass('podium-pct')
				->addClass($meta_s['pct'])
		);
		$card->addItem($bar);
		$card->addItem($foot);
	}
	else {
		// Placeholder when fewer than 3 services exist.
		$card->addItem(
			(new CDiv())
				->addClass('podium-card-head')
				->addItem((new CSpan($rank_labels[$i]))->addClass('podium-badge'))
		);
		$card->addItem((new CDiv('—'))->addClass('podium-name'));
		$card->addItem((new CDiv('—'))->addClass('podium-pct'));
	}

	$grid->addItem($card);
}

// Summary card.
$summary_card = (new CDiv())->addClass('podium-card')->addClass('is-summary');
$summary = $data['summary'] ?? null;
$totals = $data['totals'] ?? ['in' => 0, 'out' => 0, 'total' => 0];

$summary_card->addItem(
	(new CDiv())
		->addClass('podium-card-head')
		->addItem((new CSpan('Resumo da frota'))->addClass('podium-badge'))
);

if ($summary !== null) {
	$sum_meta = $status_meta($summary['status']);
	$is_synthetic = !empty($summary['synthetic']);

	$summary_card->addItem(
		(new CDiv($fmt_pct((float) $summary['sli'])))
			->addClass('podium-pct')
			->addClass($sum_meta['pct'])
	);

	$total = (int) $totals['total'];
	$svc_word = $total === 1 ? 'serviço' : 'serviços';
	$note = $is_synthetic
		? 'SLA médio · '.$total.' '.$svc_word
		: $summary['name'].' · '.$total.' '.$svc_word;
	$summary_card->addItem((new CDiv($note))->addClass('summary-note'));

	$stats = (new CDiv())->addClass('summary-grid');
	$stats->addItem(
		(new CDiv())
			->addClass('summary-stat')
			->addItem((new CDiv((string) $totals['out']))->addClass('n')->addClass('is-crit'))
			->addItem((new CDiv('fora do SLA'))->addClass('l'))
	);
	$stats->addItem(
		(new CDiv())
			->addClass('summary-stat')
			->addItem((new CDiv((string) $totals['in']))->addClass('n')->addClass('is-ok'))
			->addItem((new CDiv('dentro do SLA'))->addClass('l'))
	);
	$summary_card->addItem($stats);
}
else {
	$summary_card->addItem((new CDiv('—'))->addClass('podium-pct'));
	$summary_card->addItem((new CDiv('Sem dados'))->addClass('summary-note'));
}

$grid->addItem($summary_card);
$body->addItem($grid);

// ---- Divider + scrollable list ----
$body->addItem((new CTag('hr', false))->addClass('hr'));

$list_wrap = (new CDiv())->addClass('podium-list-wrap');
$list = (new CDiv())->addClass('podium-list');

$rest = $data['rest'] ?? [];

if ($rest) {
	$rank = 4;

	foreach ($rest as $entry) {
		$meta_s = $status_meta($entry['status']);

		$row = (new CDiv())->addClass('podium-row');
		$row->addItem((new CSpan((string) $rank))->addClass('rank'));
		$name_node = (!empty($entry['hostid']))
			? (new CLink($entry['name'], 'zabbix.php?action=host.dashboard.view&hostid='.$entry['hostid']))
				->setAttribute('title', $entry['name'])
				->addClass('name')
			: (new CSpan($entry['name']))->addClass('name');
		$row->addItem($name_node);
		$row->addItem(
			(new CSpan($fmt_pct((float) $entry['sli'])))
				->addClass('pct')
				->addClass($meta_s['pct'])
		);
		$row->addItem((new CSpan($fmt_duration($entry['downtime'])))->addClass('off'));
		$row->addItem(
			(new CSpan(
				(new CSpan([(new CSpan(''))->addClass('pip'), $meta_s['label']]))
					->addClass('status-pill')
					->addClass($meta_s['pill'])
			))->addClass('sit')
		);

		$list->addItem($row);
		$rank++;
	}

	$list_wrap->addItem($list);
}
else {
	$list_wrap->addItem(
		(new CDiv('Sem mais serviços para ranquear.'))->addClass('podium-empty')
	);
}

$body->addItem($list_wrap);

$widget->addItem($body);
$wrap->addItem($widget);

$view->addItem([
	new CTag('style', true, $css),
	$wrap
]);

$view->show();
