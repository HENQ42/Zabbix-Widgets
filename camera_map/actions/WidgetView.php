<?php declare(strict_types = 0);

namespace Modules\CameraMap\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	// Highest severity → color (Zabbix severity 0..5)
	private const SEVERITY_COLORS = [
		0 => '#97AAB3', // not classified
		1 => '#7499FF', // information
		2 => '#FFC859', // warning
		3 => '#FFA059', // average
		4 => '#E97659', // high
		5 => '#E45959'  // disaster
	];
	private const SEVERITY_NAMES = [
		0 => 'Not classified',
		1 => 'Information',
		2 => 'Warning',
		3 => 'Average',
		4 => 'High',
		5 => 'Disaster'
	];
	private const OK_COLOR = '#59DB8F';

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getName());

		$override_hostid = $this->fields_values['override_hostid'] ?? [];
		if ($override_hostid) {
			$hostids = is_array($override_hostid) ? $override_hostid : [$override_hostid];
		}
		else {
			$hostids = $this->fields_values['hostids'] ?? [];
		}

		$lat_picked = (array) ($this->fields_values['lat_itemid'] ?? []);
		$lon_picked = (array) ($this->fields_values['lon_itemid'] ?? []);

		$lat_key = self::keyFromPicked($lat_picked);
		$lon_key = self::keyFromPicked($lon_picked);

		$default_zoom = max(1, min(18, (int) ($this->fields_values['default_zoom'] ?? 13)));
		$show_labels = (int) ($this->fields_values['show_labels'] ?? 1) === 1;

		$center_lat = trim((string) ($this->fields_values['center_lat'] ?? ''));
		$center_lon = trim((string) ($this->fields_values['center_lon'] ?? ''));
		$center = null;
		if (is_numeric($center_lat) && is_numeric($center_lon)) {
			$center = [(float) $center_lat, (float) $center_lon];
		}

		$markers = [];
		$error = null;

		if (!$hostids) {
			$error = _('Select at least one host (or use the dashboard host).');
		}
		elseif ($lat_key === '' || $lon_key === '') {
			$error = _('Select both latitude and longitude items.');
		}
		else {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name', 'host'],
				'hostids' => $hostids,
				'filter' => ['status' => HOST_STATUS_MONITORED],
				'preservekeys' => true
			]);
			$active_hostids = array_keys($hosts);

			if (!$active_hostids) {
				$error = _('No monitored hosts selected.');
			}
			else {
				$lat_items = API::Item()->get([
					'output' => ['itemid', 'hostid', 'lastvalue'],
					'hostids' => $active_hostids,
					'filter' => ['key_' => $lat_key],
					'webitems' => true
				]) ?: [];
				$lon_items = API::Item()->get([
					'output' => ['itemid', 'hostid', 'lastvalue'],
					'hostids' => $active_hostids,
					'filter' => ['key_' => $lon_key],
					'webitems' => true
				]) ?: [];

				$lons_by_host = [];
				foreach ($lon_items as $it) {
					$lons_by_host[$it['hostid']] = $it['lastvalue'];
				}

				// Active problems → highest severity per host.
				$problems = API::Problem()->get([
					'output' => ['eventid', 'objectid', 'severity', 'name'],
					'hostids' => $active_hostids,
					'recent' => false
				]) ?: [];

				$triggerids = array_unique(array_column($problems, 'objectid'));
				$trigger_to_hosts = [];
				if ($triggerids) {
					$triggers = API::Trigger()->get([
						'output' => ['triggerid'],
						'triggerids' => $triggerids,
						'selectHosts' => ['hostid'],
						'preservekeys' => true
					]) ?: [];
					foreach ($triggers as $tr) {
						$tids = [];
						foreach ($tr['hosts'] as $h) {
							$tids[] = $h['hostid'];
						}
						$trigger_to_hosts[$tr['triggerid']] = $tids;
					}
				}

				$host_severity = [];        // hostid → max severity
				$host_problem_count = [];   // hostid → count
				$host_problem_names = [];   // hostid → top N problem names
				foreach ($problems as $p) {
					$tid = $p['objectid'];
					$sev = (int) $p['severity'];
					$pname = $p['name'];
					$affected = $trigger_to_hosts[$tid] ?? [];
					foreach ($affected as $hid) {
						if (!isset($hosts[$hid])) {
							continue;
						}
						if (!isset($host_severity[$hid]) || $sev > $host_severity[$hid]) {
							$host_severity[$hid] = $sev;
						}
						$host_problem_count[$hid] = ($host_problem_count[$hid] ?? 0) + 1;
						if (!isset($host_problem_names[$hid])) {
							$host_problem_names[$hid] = [];
						}
						if (count($host_problem_names[$hid]) < 3) {
							$host_problem_names[$hid][] = ['sev' => $sev, 'name' => $pname];
						}
					}
				}

				foreach ($lat_items as $lat_it) {
					$hostid = $lat_it['hostid'];
					if (!isset($hosts[$hostid]) || !isset($lons_by_host[$hostid])) {
						continue;
					}
					$lat = $lat_it['lastvalue'];
					$lon = $lons_by_host[$hostid];
					if (!is_numeric($lat) || !is_numeric($lon)) {
						continue;
					}
					$lat = (float) $lat;
					$lon = (float) $lon;
					if ($lat === 0.0 && $lon === 0.0) {
						continue; // skip phantom markers
					}
					if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
						continue;
					}

					$sev = $host_severity[$hostid] ?? null;
					$color = $sev !== null ? self::SEVERITY_COLORS[$sev] : self::OK_COLOR;
					$sev_name = $sev !== null ? self::SEVERITY_NAMES[$sev] : 'OK';

					$host_name = $hosts[$hostid]['name'];
					$label = preg_match('/\d+/', $host_name, $mm) ? $mm[0] : '';

					$markers[] = [
						'hostid' => $hostid,
						'name' => $host_name,
						'label' => $label,
						'lat' => $lat,
						'lon' => $lon,
						'color' => $color,
						'severity' => $sev !== null ? $sev : -1,
						'severity_name' => $sev_name,
						'problem_count' => $host_problem_count[$hostid] ?? 0,
						'problems' => $host_problem_names[$hostid] ?? [],
						'url' => '../../zabbix.php?action=host.dashboard.view&hostid='.$hostid
					];
				}

				if (!$markers) {
					$error = _('No host returned valid latitude/longitude values.');
				}
			}
		}

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'map' => [
				'markers' => $markers,
				'zoom' => $default_zoom,
				'center' => $center,
				'show_labels' => $show_labels,
				'error' => $error
			],
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	private static function keyFromPicked(array $itemids): string {
		if (!$itemids) {
			return '';
		}
		$picked = API::Item()->get([
			'output' => ['key_'],
			'itemids' => $itemids,
			'webitems' => true
		]) ?: [];
		return $picked ? $picked[0]['key_'] : '';
	}
}
