<?php declare(strict_types = 0);

namespace Modules\SlaPodium\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getDefaultName());

		$slaid_raw = $this->fields_values['slaid'] ?? [];
		$period_label = (string) ($this->fields_values['period_label'] ?? 'Último período');

		$slaid = is_array($slaid_raw) ? reset($slaid_raw) : $slaid_raw;
		$slaid = $slaid !== false ? (string) $slaid : '';

		$empty = [
			'name' => $name,
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

		if ($slaid === '') {
			$empty['error'] = 'Selecione um SLA.';
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

		// O próprio SLA já filtra os serviços de interesse (ex.: só câmeras ou
		// só mikrotiks). Pegamos tudo o que está atrelado a ele.
		$services = API::Service()->get([
			'output' => ['serviceid', 'name'],
			'slaids' => $sla['slaid'],
			'preservekeys' => true
		]);

		if (!$services) {
			$empty['error'] = 'Nenhum serviço associado a este SLA.';
			$this->setResponse(new CControllerResponseData($empty));

			return;
		}

		$serviceids = array_keys($services);

		$sli_resp = API::Sla()->getSli([
			'slaid' => $sla['slaid'],
			'serviceids' => $serviceids,
			'periods' => 1
		]);

		if (!$sli_resp || empty($sli_resp['periods'])) {
			$empty['error'] = 'Sem dados de SLA para o período selecionado.';
			$this->setResponse(new CControllerResponseData($empty));

			return;
		}

		$period_index = array_key_first($sli_resp['periods']);
		$period = $sli_resp['periods'][$period_index];
		$service_index_by_id = array_flip($sli_resp['serviceids']);

		$slo = (float) $sla['slo'];

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

			$entries[$sid] = [
				'serviceid' => (string) $sid,
				'name' => $service['name'],
				'sli' => $pct,
				'uptime' => (int) $row['uptime'],
				'downtime' => (int) $row['downtime'],
				'error_budget' => (int) $row['error_budget'],
				'status' => self::classify($pct, $slo),
				'meets_slo' => $pct >= $slo
			];
		}

		// Resolve hostid for each entry by matching host.name == service.name.
		$service_names = array_values(array_unique(array_column($entries, 'name')));
		$hostid_by_name = [];

		if ($service_names) {
			$matched_hosts = API::Host()->get([
				'output' => ['hostid', 'name'],
				'filter' => ['name' => $service_names],
				'monitored_hosts' => true
			]);

			foreach ($matched_hosts as $h) {
				if (!isset($hostid_by_name[$h['name']])) {
					$hostid_by_name[$h['name']] = (string) $h['hostid'];
				}
			}
		}

		foreach ($entries as &$entry_ref) {
			$entry_ref['hostid'] = $hostid_by_name[$entry_ref['name']] ?? '';
		}
		unset($entry_ref);

		uasort($entries, static function (array $a, array $b): int {
			return $a['sli'] <=> $b['sli'];
		});

		$entries = array_values($entries);

		$totals = ['in' => 0, 'out' => 0, 'total' => count($entries)];

		foreach ($entries as $e) {
			if ($e['meets_slo']) {
				$totals['in']++;
			}
			else {
				$totals['out']++;
			}
		}

		$summary = null;

		if ($entries) {
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

		$worst = array_slice($entries, 0, 3);
		$rest = array_slice($entries, 3);

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
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

		if ($pct >= $slo + 0.5 && $pct >= 99.5) {
			return 'excellent';
		}

		return 'ok';
	}
}
