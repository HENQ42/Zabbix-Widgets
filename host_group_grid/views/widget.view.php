<?php declare(strict_types = 0);

/**
 * Host Group Grid widget view.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if (empty($data['sites'])) {
	$view->addItem(
		(new CTableInfo())->setNoDataMessage(_('No sites detected. Check the configured groups and the host naming pattern (SEFAZ_AL_NN...).'))
	);
}
else {
	$columns = (int) ($data['columns'] ?? 3);
	if ($columns < 1) {
		$columns = 1;
	}

	$color_stable = $data['color_stable'] !== '' ? $data['color_stable'] : '16A34A';
	$color_critical = $data['color_critical'] !== '' ? $data['color_critical'] : 'DC2626';
	$color_warning = (isset($data['color_warning']) && $data['color_warning'] !== '') ? $data['color_warning'] : 'D97706';

	$css = <<<CSS
		.hggrid-wrap {
			--hggrid-box-bg: #ffffff;
			--hggrid-box-border: #ccd5db;
			--hggrid-box-shadow: 0 1px 2px rgba(0,0,0,0.04);
			--hggrid-text: #1f2328;
			--hggrid-header-border: #e4e9ec;
		}
		:root[color-scheme="dark"] .hggrid-wrap {
			--hggrid-box-bg: #ededed;
			--hggrid-box-border: #bfc6cb;
			--hggrid-box-shadow: 0 1px 2px rgba(0,0,0,0.2);
			--hggrid-text: #1f2328;
			--hggrid-header-border: #d4d9dc;
		}
		.hggrid-wrap { display: grid; gap: 12px; padding: 12px; height: 100%; overflow-y: auto; box-sizing: border-box; align-content: start; }
		.hggrid-box {
			border: 1px solid var(--hggrid-box-border);
			border-radius: 8px;
			padding: 10px 14px;
			background: var(--hggrid-box-bg);
			box-shadow: var(--hggrid-box-shadow);
			color: var(--hggrid-text);
			display: flex;
			flex-direction: column;
			min-width: 0;
			overflow: hidden;
			transform-origin: center;
			transition: transform 0.2s ease, box-shadow 0.2s ease;
			will-change: transform;
		}
		.hggrid-box:hover {
			transform: scale(1.03);
			box-shadow: 0 4px 12px rgba(0,0,0,0.12);
			z-index: 1;
		}
		.hggrid-header {
			display: flex;
			align-items: center;
			gap: 8px;
			padding-bottom: 6px;
			margin-bottom: 6px;
			border-bottom: 1px solid var(--hggrid-header-border);
		}
		.hggrid-site-num {
			flex: 1 1 auto; min-width: 0;
			white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
			color: inherit;
			font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
			font-size: 20px;
			font-weight: 400;
			letter-spacing: 1px;
			line-height: 1;
		}
		.hggrid-status {
			flex-shrink: 0;
			display: inline-flex;
			align-items: center;
			gap: 5px;
			padding: 2px 8px 2px 7px;
			border-radius: 999px;
			font-size: 9px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			line-height: 1.4;
		}
		.hggrid-status-dot {
			width: 6px;
			height: 6px;
			border-radius: 50%;
			background: currentColor;
		}
		.hggrid-status.critical .hggrid-status-dot {
			animation: pulse-dot 1.4s ease-out infinite;
		}
		@keyframes pulse-dot {
			0% { box-shadow: 0 0 0 0 currentColor; opacity: 1; }
			70% { box-shadow: 0 0 0 6px transparent; opacity: 0.6; }
			100% { box-shadow: 0 0 0 0 transparent; opacity: 1; }
		}
		.hggrid-badges {
			display: flex;
			gap: 6px;
			margin-bottom: 8px;
			flex-wrap: wrap;
		}
		.hggrid-badge {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			padding: 2px 8px;
			border-radius: 12px;
			font-size: 11px;
			font-weight: 600;
			border: 1.5px solid;
			background: transparent;
			line-height: 1.4;
		}
		.hggrid-badge .hggrid-badge-icon { font-size: 12px; line-height: 1; }
		.hggrid-badge.ok { border-color: #4CAF50; color: #2e7d32; }
		.hggrid-badge.bad { border-color: #E53935; color: #c62828; }
		.hggrid-badge.empty { border-color: #9e9e9e; color: #616161; }
		:root[color-scheme="dark"] .hggrid-badge.ok { color: #1b5e20; }
		:root[color-scheme="dark"] .hggrid-badge.bad { color: #b71c1c; }
		:root[color-scheme="dark"] .hggrid-badge.empty { color: #424242; }
		.hggrid-timeline {
			display: grid;
			grid-template-columns: repeat(12, 1fr);
			gap: 3px;
			margin-bottom: 6px;
		}
		.hggrid-cell {
			aspect-ratio: 1 / 1;
			border-radius: 3px;
			box-shadow: inset 0 0 0 1px rgba(0,0,0,0.08);
			transition: transform 0.15s ease;
		}
		.hggrid-cell:hover {
			transform: scale(1.15);
			box-shadow: inset 0 0 0 1px rgba(0,0,0,0.25), 0 2px 4px rgba(0,0,0,0.2);
		}
		.hggrid-cell.has-problems { cursor: pointer; }
		.hggrid-cell.future {
			background-color: transparent !important;
			box-shadow: inset 0 0 0 1px rgba(128,128,128,0.25);
		}
		.hggrid-online {
			display: inline-block;
			width: 7px; height: 7px;
			border-radius: 50%;
			margin-right: 6px;
			vertical-align: middle;
		}
		.hggrid-online.ok { background: #16A34A; box-shadow: 0 0 0 2px rgba(22,163,74,0.18); }
		.hggrid-online.bad { background: #9e9e9e; box-shadow: 0 0 0 2px rgba(158,158,158,0.18); }
	CSS;

	$grid = (new CDiv())
		->addClass('hggrid-wrap')
		->addStyle('grid-template-columns: repeat('.$columns.', minmax(0, 1fr));');

	foreach ($data['sites'] as $site) {
		$state = (string) ($site['state'] ?? 'stable');

		if ($state === 'critical') {
			$stripe_color = $color_critical;
			$status_label = _('Crítico');
			$status_bg = 'fee2e2';
		}
		elseif ($state === 'unstable') {
			$stripe_color = $color_warning;
			$status_label = _('Instável');
			$status_bg = 'fef3c7';
		}
		else {
			$stripe_color = $color_stable;
			$status_label = _('Estável');
			$status_bg = 'dcfce7';
		}

		$box = (new CDiv())
			->addClass('hggrid-box')
			->setAttribute('data-site-id', (string) $site['site_id'])
			->addStyle('border-left: 5px solid #'.$stripe_color.'; cursor: pointer;');

		$site_num = (new CSpan($site['site_id']))
			->addClass('hggrid-site-num')
			->setAttribute('title', $site['site_label']);

		$status_badge = (new CSpan([
			(new CSpan(''))->addClass('hggrid-status-dot'),
			$status_label
		]))
			->addClass('hggrid-status')
			->addClass($state)
			->addStyle('background-color: #'.$status_bg.'; color: #'.$stripe_color.';');

		$box->addItem((new CDiv([$site_num, $status_badge]))->addClass('hggrid-header'));

		// Badges row.
		$sw_total = (int) $site['switch_total'];
		$cam_total = (int) $site['camera_total'];
		$sw_class = $sw_total === 0 ? 'empty' : (((int) $site['switch_active'] === $sw_total) ? 'ok' : 'bad');
		$cam_class = $cam_total === 0 ? 'empty' : (((int) $site['camera_active'] === $cam_total) ? 'ok' : 'bad');

		$switch_badge = (new CSpan([
			(new CSpan('⇄'))->addClass('hggrid-badge-icon'),
			' Switch ',
			$site['switch_active'].'/'.$site['switch_total']
		]))
			->addClass('hggrid-badge')
			->addClass($sw_class)
			->setAttribute('title',
				_('Switches').': '.$site['switch_active'].' '._('ativos').' / '.$site['switch_total'].' '._('total')
			);

		$camera_badge = (new CSpan([
			(new CSpan('◉'))->addClass('hggrid-badge-icon'),
			' Câmera ',
			$site['camera_active'].'/'.$site['camera_total']
		]))
			->addClass('hggrid-badge')
			->addClass($cam_class)
			->setAttribute('title',
				_('Câmeras').': '.$site['camera_active'].' '._('ativas').' / '.$site['camera_total'].' '._('total')
			);

		$box->addItem(
			(new CDiv([$switch_badge, $camera_badge]))->addClass('hggrid-badges')
		);

		// Timeline 24h (two rows of 12 cells).
		if (!empty($site['timeline'])) {
			$timeline_rows = [
				array_slice($site['timeline'], 0, 12),
				array_slice($site['timeline'], 12, 12)
			];

			foreach ($timeline_rows as $row_cells) {
				$timeline_div = (new CDiv())->addClass('hggrid-timeline');

				foreach ($row_cells as $cell) {
					$cell_color = $color_stable;
					$state_label = _('Estável');
					$extra_class = '';

					if ($cell['state'] === 'future') {
						$state_label = _('Futuro');
						$extra_class = ' future';
					}
					elseif ($cell['state'] === 'critical') {
						$cell_color = $color_critical;
						$state_label = _('Crítico');
					}
					elseif ($cell['state'] === 'warning') {
						$cell_color = $color_warning;
						$state_label = _('Atenção');
					}

					$problems = $cell['problems'] ?? [];
					$count = count($problems);

					$tooltip = $cell['hour_label'].' — '.$state_label;
					if ($count > 0) {
						$tooltip .= ' ('.$count.' '.($count === 1 ? _('problema') : _('problemas')).')';
					}

					$cell_div = (new CDiv())
						->addClass('hggrid-cell'.$extra_class)
						->setAttribute('title', $tooltip);

					if ($cell['state'] !== 'future') {
						$cell_div->addStyle('background-color: #'.$cell_color.';');
					}

					if ($count > 0) {
						$cell_div->addClass('has-problems');
						$cell_div->setAttribute('data-problems', json_encode($problems, JSON_UNESCAPED_UNICODE));
						$cell_div->setAttribute('data-hour', $cell['hour_label']);
					}

					$timeline_div->addItem($cell_div);
				}

				$box->addItem($timeline_div);
			}
		}

		$grid->addItem($box);
	}

	// Per-site detail (consumed by class.widget.js for the drill-down screen).
	$detail_payload = [];
	foreach ($data['sites'] as $site) {
		$detail_payload[] = [
			'site_id' => $site['site_id'],
			'site_label' => $site['site_label'],
			'switch_total' => $site['switch_total'],
			'switch_active' => $site['switch_active'],
			'camera_total' => $site['camera_total'],
			'camera_active' => $site['camera_active'],
			'state' => $site['state'],
			'hosts' => $site['hosts'] ?? []
		];
	}

	$detail_script = (new CTag('script', true, json_encode($detail_payload, JSON_UNESCAPED_UNICODE)))
		->setAttribute('type', 'application/json')
		->setAttribute('data-hggrid-detail', '1');

	$colors_script = (new CTag('script', true, json_encode([
		'stable' => $color_stable,
		'critical' => $color_critical,
		'warning' => $color_warning
	])))
		->setAttribute('type', 'application/json')
		->setAttribute('data-hggrid-colors', '1');

	$view->addItem([
		new CTag('style', true, $css),
		$detail_script,
		$colors_script,
		$grid
	]);
}

$view->show();
