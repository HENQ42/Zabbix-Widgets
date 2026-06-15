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
		(new CTableInfo())->setNoDataMessage(_('Nenhum site detectado. Verifique os grupos configurados e o padrão de nomenclatura dos hosts (PREFIXO_NN...).'))
	);
}
else {
	$color_stable = $data['color_stable'] !== '' ? $data['color_stable'] : '16A34A';
	$color_critical = $data['color_critical'] !== '' ? $data['color_critical'] : 'DC2626';
	$color_warning = (isset($data['color_warning']) && $data['color_warning'] !== '') ? $data['color_warning'] : 'D97706';

	$css = <<<CSS
		.hggrid-wrap, .hggrid-drilldown {
			--hggrid-box-bg: #ffffff;
			--hggrid-box-border: #ccd5db;
			--hggrid-box-shadow: 0 1px 2px rgba(0,0,0,0.04);
			--hggrid-text: #1f2328;
			--hggrid-header-border: #e4e9ec;
			--hggrid-bar-bg: #ffffff;
			--hggrid-bar-color: #1f2328;
			--hggrid-bar-border: rgba(0,0,0,0.12);
			--hggrid-bar-btn-bg: #ffffff;
			--hggrid-bar-btn-border: #ccd5db;
			--hggrid-drilldown-bg: #f4f6f8;
			--hggrid-accent: #5343d4;
		}
		:root[color-scheme="dark"] .hggrid-wrap,
		:root[color-scheme="dark"] .hggrid-drilldown {
			--hggrid-box-bg: #ededed;
			--hggrid-box-border: #bfc6cb;
			--hggrid-box-shadow: 0 1px 2px rgba(0,0,0,0.2);
			--hggrid-text: #1f2328;
			--hggrid-header-border: #d4d9dc;
			--hggrid-bar-bg: #2b3137;
			--hggrid-bar-color: #e6e6e6;
			--hggrid-bar-border: rgba(255,255,255,0.12);
			--hggrid-bar-btn-bg: #3a4046;
			--hggrid-bar-btn-border: rgba(255,255,255,0.2);
			--hggrid-drilldown-bg: #1f2328;
			--hggrid-accent: #a094f0;
		}
		.hggrid-wrap { display: grid; gap: 12px; padding: 12px; height: 100%; overflow: auto; box-sizing: border-box; align-content: start; align-items: start; grid-auto-rows: max-content; }
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
			transition: box-shadow 0.2s ease;
		}
		.hggrid-box:hover {
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
			font-size: 14px;
			font-weight: 600;
			letter-spacing: 0.5px;
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
			align-items: center;
			gap: 8px;
			margin-bottom: 8px;
			flex-wrap: wrap;
		}
		.hggrid-typebadge {
			display: inline-flex;
			align-items: baseline;
			gap: 5px;
			font-size: 11px;
			font-weight: 700;
			line-height: 1.4;
			letter-spacing: 0.3px;
			white-space: nowrap;
		}
		.hggrid-typebadge-name { text-transform: uppercase; }
		.hggrid-typebadge-count { font-weight: 600; font-variant-numeric: tabular-nums; }
		.hggrid-typebadge-sep {
			color: var(--hggrid-text);
			opacity: 0.3;
			font-weight: 700;
			align-self: center;
		}
		.hggrid-timeline {
			display: grid;
			grid-template-columns: repeat(12, 1fr);
			gap: 3px;
			margin-bottom: 6px;
		}
		.hggrid-cell {
			aspect-ratio: 1 / 1;
			min-height: 14px;
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

	// Auto-fit columns: every card keeps a fixed minimum width (so all 12 timeline cells and the type
	// labels render in full, never clipped) and the grid packs in as many columns as the widget width
	// allows, stretching them to share the leftover space.
	$grid = (new CDiv())
		->addClass('hggrid-wrap')
		->addStyle('grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));');

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

		// Badges row: one badge per TIPO (derived from the nomenclature), formatted "<TIPO> ativo/total".
		// No border — only the text colour reflects health: all active => stable, none => critical,
		// partial => warning. Edge-Router types come first, then camera types (see buildTypeBadges()).
		$types = $site['types'] ?? [];
		if ($types) {
			$badge_items = [];
			foreach ($types as $idx => $t) {
				$t_total = (int) $t['total'];
				$t_active = (int) $t['active'];

				if ($t_active >= $t_total) {
					$t_color = $color_stable;
				}
				elseif ($t_active === 0) {
					$t_color = $color_critical;
				}
				else {
					$t_color = $color_warning;
				}

				if ($idx > 0) {
					$badge_items[] = (new CSpan('·'))->addClass('hggrid-typebadge-sep');
				}

				$badge_items[] = (new CSpan([
					(new CSpan($t['type']))->addClass('hggrid-typebadge-name'),
					(new CSpan($t_active.'/'.$t_total))->addClass('hggrid-typebadge-count')
				]))
					->addClass('hggrid-typebadge')
					->addStyle('color: #'.$t_color.';')
					->setAttribute('title',
						$t['type'].': '.$t_active.' '._('ativos').' / '.$t_total.' '._('total')
					);
			}

			$box->addItem((new CDiv($badge_items))->addClass('hggrid-badges'));
		}

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
			'total' => $site['total'] ?? 0,
			'active' => $site['active'] ?? 0,
			'types' => $site['types'] ?? [],
			'state' => $site['state'],
			'hosts' => $site['hosts'] ?? []
		];
	}

	// O conteúdo de <script> é raw text: o parser HTML NÃO decodifica entidades dentro dele.
	// Por isso embrulhamos o JSON em CJsScript (emite sem htmlspecialchars) e usamos JSON_HEX_TAG,
	// que converte < e > em </> — seguro dentro do <script> (evita quebra por </script>)
	// e revertido corretamente pelo JSON.parse no cliente. Sem isso o '>' viraria '&gt;' literal.
	$detail_script = (new CTag('script', true,
		new CJsScript(json_encode($detail_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS))
	))
		->setAttribute('type', 'application/json')
		->setAttribute('data-hggrid-detail', '1');

	$colors_script = (new CTag('script', true,
		new CJsScript(json_encode([
			'stable' => $color_stable,
			'critical' => $color_critical,
			'warning' => $color_warning
		], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS))
	))
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
