<?php declare(strict_types = 0);

namespace Modules\SlaPodium\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getDefaultName());

		$slaid_raw = $this->fields_values['slaid'] ?? [];
		$serviceids_raw = $this->fields_values['serviceids'] ?? [];
		$parent_raw = $this->fields_values['parent_serviceid'] ?? [];
		$period_label = (string) ($this->fields_values['period_label'] ?? 'Último período');
		$theme = (int) ($this->fields_values['theme'] ?? 0);

		$slaid = is_array($slaid_raw) ? reset($slaid_raw) : $slaid_raw;
		$slaid = $slaid !== false ? (string) $slaid : '';

		$serviceids = array_values(array_filter(array_map('strval', (array) $serviceids_raw)));

		$parent_serviceid = is_array($parent_raw) ? reset($parent_raw) : $parent_raw;
		$parent_serviceid = $parent_serviceid !== false && $parent_serviceid !== null
			? (string) $parent_serviceid
			: '';

		$empty = [
			'name' => $name,
			'theme' => $theme,
			'period_label' => $period_label,
			'slo' => 0.0,
			'period_from' => 0,
			'period_to' => 0,
			'worst' => [],
			'rest' => [],
			'summary' => null,
			'totals' => ['in' => 0, 'out' => 0, 'total' => 0],
			'error' => '',
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		if ($slaid === '' || !$serviceids) {
			$empty['error'] = 'Selecione um SLA e ao menos um serviço.';
			$this->setResponse(new CControllerResponseData($empty));

			return;
		}

		$slas = API::Sla()->get([
			'output' => ['slaid', 'name', 'period', 'slo', 'timezone', 'status'],
			'slaids' => [$slaid]
		]);

		if (!$slas) {
			$empty['error'] = 'SLA não encontrado ou inacessível.';
			$this->setResponse(new CControllerResponseData($empty));

			return;
		}

		$sla = $slas[0];

		if ((int) $sla['status'] !== ZBX_SLA_STATUS_ENABLED) {
			$empty['error'] = 'SLA está desativado.';
			$this->setResponse(new CControllerResponseData($empty));

			return;
		}

		// Only keep services that actually belong to this SLA and are accessible.
		$services = API::Service()->get([
			'output' => ['serviceid', 'name'],
			'serviceids' => $serviceids,
			'slaids' => $sla['slaid'],
			'preservekeys' => true
		]);

		if (!$services) {
			$empty['error'] = 'Nenhum serviço encontrado para este SLA.';
			$this->setResponse(new CControllerResponseData($empty));

			return;
		}

		$ordered_serviceids = array_values(array_intersect($serviceids, array_keys($services)));

		$sli_resp = API::Sla()->getSli([
			'slaid' => $sla['slaid'],
			'serviceids' => $ordered_serviceids,
			'periods' => 1
		]);

		if (!$sli_resp || empty($sli_resp['periods'])) {
			$empty['error'] = 'Sem dados de SLA para o período selecionado.';
			$this->setResponse(new CControllerResponseData($empty));

			return;
		}

		// getSli returns: periods[i], serviceids[j], sli[i][j] => {sli, uptime, downtime, error_budget, ...}
		$period_index = array_key_first($sli_resp['periods']);
		$period = $sli_resp['periods'][$period_index];
		$service_index_by_id = array_flip($sli_resp['serviceids']);

		$slo = (float) $sla['slo'];

		// Build a flat list: one entry per service with its SLI.
		$entries = [];

		foreach ($services as $sid => $service) {
			if (!isset($service_index_by_id[$sid])) {
				continue;
			}

			$idx = $service_index_by_id[$sid];
			$row = $sli_resp['sli'][$period_index][$idx] ?? null;

			if ($row === null) {
				continue;
			}

			$pct = (float) $row['sli'];
			$uptime = (int) $row['uptime'];
			$downtime = (int) $row['downtime'];
			$error_budget = (int) $row['error_budget'];

			$entries[$sid] = [
				'serviceid' => (string) $sid,
				'name' => $service['name'],
				'sli' => $pct,
				'uptime' => $uptime,
				'downtime' => $downtime,
				'error_budget' => $error_budget,
				'status' => self::classify($pct, $slo),
				'meets_slo' => $pct >= $slo
			];
		}

		// Split off the parent service if configured.
		$summary = null;

		if ($parent_serviceid !== '' && isset($entries[$parent_serviceid])) {
			$summary = $entries[$parent_serviceid];
			unset($entries[$parent_serviceid]);
		}

		// Sort ascending by SLI — worst first.
		uasort($entries, static function (array $a, array $b): int {
			return $a['sli'] <=> $b['sli'];
		});

		$entries = array_values($entries);

		// Tally fleet membership (using whatever stays in the ranking — i.e. children only).
		$totals = ['in' => 0, 'out' => 0, 'total' => count($entries)];

		foreach ($entries as $e) {
			if ($e['meets_slo']) {
				$totals['in']++;
			}
			else {
				$totals['out']++;
			}
		}

		// If no parent was picked, build a synthetic summary as the arithmetic mean of children.
		if ($summary === null && $entries) {
			$avg = array_sum(array_column($entries, 'sli')) / count($entries);

			$summary = [
				'serviceid' => null,
				'name' => 'Frota',
				'sli' => $avg,
				'uptime' => null,
				'downtime' => null,
				'error_budget' => null,
				'status' => self::classify($avg, $slo),
				'meets_slo' => $avg >= $slo,
				'synthetic' => true
			];
		}
		elseif ($summary !== null) {
			$summary['synthetic'] = false;
		}

		// Split top 3 worst into "podium" cards; the rest goes into the scrollable list.
		$worst = array_slice($entries, 0, 3);
		$rest = array_slice($entries, 3);

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'theme' => $theme,
			'period_label' => $period_label,
			'sla_name' => $sla['name'],
			'slo' => $slo,
			'period_from' => (int) $period['period_from'],
			'period_to' => (int) $period['period_to'],
			'worst' => $worst,
			'rest' => $rest,
			'summary' => $summary,
			'totals' => $totals,
			'error' => '',
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	/**
	 * Classify an SLI against the configured SLO.
	 * Returns one of: 'crit', 'warn', 'ok', 'excellent'.
	 */
	private static function classify(float $pct, float $slo): string {
		if ($pct < $slo - 2.0) {
			return 'crit';
		}

		if ($pct < $slo) {
			return 'warn';
		}

		// At or above the SLO — call it "excellent" only when there's real headroom.
		if ($pct >= $slo + 0.5 && $pct >= 99.5) {
			return 'excellent';
		}

		return 'ok';
	}
}
