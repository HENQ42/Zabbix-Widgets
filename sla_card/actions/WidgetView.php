<?php declare(strict_types = 0);

namespace Modules\SlaCard\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getDefaultName());

		$slaid_raw = $this->fields_values['slaid'] ?? [];
		$serviceid_raw = $this->fields_values['serviceid'] ?? [];
		$period_label = (string) ($this->fields_values['period_label'] ?? 'Mês atual');
		$theme = (int) ($this->fields_values['theme'] ?? 0);

		$override_hostids = $this->fields_values['override_hostid'] ?? [];

		$slaid = is_array($slaid_raw) ? reset($slaid_raw) : $slaid_raw;
		$slaid = $slaid !== false && $slaid !== null ? (string) $slaid : '';

		$serviceid_manual = is_array($serviceid_raw) ? reset($serviceid_raw) : $serviceid_raw;
		$serviceid_manual = $serviceid_manual !== false && $serviceid_manual !== null
			? (string) $serviceid_manual
			: '';

		$override_hostid = is_array($override_hostids) ? reset($override_hostids) : $override_hostids;
		$override_hostid = $override_hostid !== false && $override_hostid !== null
			? (string) $override_hostid
			: '';

		$now = time();

		$empty = [
			'name' => $name,
			'theme' => $theme,
			'period_label' => $period_label,
			'host_name' => '',
			'service_name' => '',
			'sla_name' => '',
			'slo' => 0.0,
			'sli' => null,
			'status' => 'nodata',
			'state_label' => 'Sem dados',
			'state_color' => 'nodata',
			'downtime' => 0,
			'incidents_24h' => 0,
			'uptime_24h_pct' => null,
			'sparkline' => array_fill(0, 24, 100.0),
			'period_from' => 0,
			'period_to' => 0,
			'now' => $now,
			'error' => '',
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		if ($slaid === '') {
			$empty['error'] = 'Selecione um SLA.';
			$this->setResponse(new CControllerResponseData($empty));

			return;
		}

		// ---- SLA ----
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

		$slo = (float) $sla['slo'];
		$empty['slo'] = $slo;
		$empty['sla_name'] = $sla['name'];

		// ---- Host context (from template-dashboard host or any other override) ----
		$host = null;

		if ($override_hostid !== '') {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'host', 'name'],
				'hostids' => [$override_hostid]
			]);
			$host = $hosts ? $hosts[0] : null;
		}

		$host_name = $host ? $host['name'] : '';
		$empty['host_name'] = $host_name;

		// ---- Resolve the SLA service ----
		// Priority:
		//   1. Explicit manual override (serviceid field).
		//   2. Service whose name == host.name.
		//   3. Service whose name == host.host (technical name).
		//   4. Service whose name contains host.name (partial search).
		$service = null;

		if ($serviceid_manual !== '') {
			$services = API::Service()->get([
				'output' => ['serviceid', 'name'],
				'serviceids' => [$serviceid_manual],
				'slaids' => $sla['slaid']
			]);
			$service = $services ? $services[0] : null;
		}

		if ($service === null && $host !== null) {
			foreach ([['name' => $host['name']], ['name' => $host['host']]] as $filter) {
				if ($filter['name'] === '') {
					continue;
				}

				$services = API::Service()->get([
					'output' => ['serviceid', 'name'],
					'slaids' => $sla['slaid'],
					'filter' => $filter
				]);

				if ($services) {
					$service = $services[0];
					break;
				}
			}

			if ($service === null) {
				$services = API::Service()->get([
					'output' => ['serviceid', 'name'],
					'slaids' => $sla['slaid'],
					'search' => ['name' => $host['name']],
					'limit' => 1
				]);

				if ($services) {
					$service = $services[0];
				}
			}
		}

		if ($service === null) {
			$empty['error'] = $host === null
				? 'Selecione um serviço manualmente ou abra este widget em um dashboard de host.'
				: 'Nenhum serviço SLA encontrado para o host "'.$host['name'].'".';
			$this->setResponse(new CControllerResponseData($empty));

			return;
		}

		// ---- Latest period SLI ----
		$sli_pct = null;
		$downtime = 0;
		$period_from = 0;
		$period_to = 0;

		$sli_resp = API::Sla()->getSli([
			'slaid' => $sla['slaid'],
			'serviceids' => [$service['serviceid']],
			'periods' => 1
		]);

		if ($sli_resp && !empty($sli_resp['periods'])) {
			$pi = array_key_first($sli_resp['periods']);
			$period = $sli_resp['periods'][$pi];
			$period_from = (int) $period['period_from'];
			$period_to = (int) $period['period_to'];

			$service_idx = array_search($service['serviceid'], $sli_resp['serviceids'], false);

			if ($service_idx !== false) {
				$row = $sli_resp['sli'][$pi][$service_idx] ?? null;

				if ($row !== null) {
					$sli_pct = (float) $row['sli'];
					$downtime = (int) $row['downtime'];
				}
			}
		}

		$status = self::classify($sli_pct, $slo);

		// ---- 24h sparkline + current state via host trigger events ----
		$sparkline = array_fill(0, 24, 100.0);
		$uptime_24h_pct = ($host !== null) ? 100.0 : null;
		$incidents_24h = 0;
		$state_label = 'Online';
		$state_color = 'ok';

		if ($host === null) {
			$state_label = 'Sem contexto';
			$state_color = 'nodata';
		}
		else {
			$since = $now - 86400;

			$triggers = API::Trigger()->get([
				'output' => ['triggerid'],
				'hostids' => [$host['hostid']],
				'monitored' => true,
				'skipDependent' => true
			]);

			$triggerids = array_column($triggers, 'triggerid');

			if (!$triggerids) {
				$state_label = 'Sem triggers';
				$state_color = 'nodata';
			}
			else {
				$open_problems = API::Problem()->get([
					'output' => ['eventid'],
					'objectids' => $triggerids,
					'object' => EVENT_OBJECT_TRIGGER,
					'source' => EVENT_SOURCE_TRIGGERS,
					'limit' => 1
				]);

				if ($open_problems) {
					$state_label = 'Indisponível';
					$state_color = 'crit';
				}

				$events = API::Event()->get([
					'output' => ['eventid', 'objectid', 'clock', 'r_eventid', 'value'],
					'objectids' => $triggerids,
					'object' => EVENT_OBJECT_TRIGGER,
					'source' => EVENT_SOURCE_TRIGGERS,
					'time_from' => $since,
					'time_till' => $now,
					'value' => TRIGGER_VALUE_TRUE,
					'sortfield' => 'clock'
				]);

				$incidents_24h = count($events);

				$r_eventids = [];

				foreach ($events as $e) {
					if ((int) $e['r_eventid'] !== 0) {
						$r_eventids[] = $e['r_eventid'];
					}
				}

				$recoveries = $r_eventids
					? API::Event()->get([
						'output' => ['eventid', 'clock'],
						'eventids' => $r_eventids,
						'preservekeys' => true
					])
					: [];

				$buckets = array_fill(0, 24, 0);

				foreach ($events as $e) {
					$start = (int) $e['clock'];
					$end = ((int) $e['r_eventid'] !== 0 && isset($recoveries[$e['r_eventid']]))
						? (int) $recoveries[$e['r_eventid']]['clock']
						: $now;

					$start = max($start, $since);
					$end = min($end, $now);

					if ($end <= $start) {
						continue;
					}

					$t = $start;

					while ($t < $end) {
						$idx = (int) floor(($t - $since) / 3600);

						if ($idx < 0 || $idx >= 24) {
							break;
						}

						$bucket_end_t = $since + ($idx + 1) * 3600;
						$slice_end = min($end, $bucket_end_t);
						$buckets[$idx] += $slice_end - $t;
						$t = $slice_end;
					}
				}

				$total_down_24h = array_sum($buckets);
				$uptime_24h_pct = max(0.0, 100.0 - ($total_down_24h / 86400.0 * 100.0));

				$sparkline = [];

				foreach ($buckets as $down_sec) {
					$sparkline[] = max(0.0, 100.0 - ($down_sec / 3600.0 * 100.0));
				}
			}
		}

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'theme' => $theme,
			'period_label' => $period_label,
			'sla_name' => $sla['name'],
			'host_name' => $host_name,
			'service_name' => $service['name'],
			'slo' => $slo,
			'sli' => $sli_pct,
			'status' => $status,
			'state_label' => $state_label,
			'state_color' => $state_color,
			'downtime' => $downtime,
			'incidents_24h' => $incidents_24h,
			'uptime_24h_pct' => $uptime_24h_pct,
			'sparkline' => $sparkline,
			'period_from' => $period_from,
			'period_to' => $period_to,
			'now' => $now,
			'error' => '',
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	private static function classify(?float $pct, float $slo): string {
		if ($pct === null) {
			return 'nodata';
		}

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
