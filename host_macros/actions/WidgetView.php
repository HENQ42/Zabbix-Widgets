<?php declare(strict_types = 0);

namespace Modules\HostMacros\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getDefaultName());

		$view_mode = (int) ($this->fields_values['view_mode'] ?? 0);
		$groupids = $this->fields_values['groupids'] ?? [];
		$direct_hostids = $this->fields_values['hostids'] ?? [];
		$macro_filter = trim((string) ($this->fields_values['macro_filter'] ?? ''));
		$hidden_macros_raw = trim((string) ($this->fields_values['hidden_macros'] ?? ''));
		$columns = (int) ($this->fields_values['columns'] ?? 4);
		$header_color = (string) ($this->fields_values['header_color'] ?? '1976D2');

		if ($columns < 1) {
			$columns = 1;
		}

		// Build set of macro names whose values must be masked. Accepts "{$NAME}"
		// or just "NAME"; comma-separated.
		$hidden_set = [];

		foreach (explode(',', $hidden_macros_raw) as $entry) {
			$entry = strtoupper(trim($entry));
			$entry = str_replace(['{$', '}'], '', $entry);

			if ($entry !== '') {
				$hidden_set['{$'.$entry.'}'] = true;
			}
		}

		// Build a macro entry. Returns:
		//   value      — what gets displayed (masked when hidden)
		//   real_value — original value (only when user-hidden and reveal-able)
		//   hidden     — whether the value is currently masked
		//   toggleable — whether a click can reveal the real value
		// type 1 (Zabbix Secret) cannot be revealed because the API doesn't return the real value.
		$build_entry = static function (array $m) use ($hidden_set): array {
			$type = (int) $m['type'];
			$macro_name = (string) $m['macro'];
			$value = (string) ($m['value'] ?? '');
			$is_user_hidden = isset($hidden_set[strtoupper($macro_name)]);
			$is_secret = ($type === 1);
			$is_hidden = $is_user_hidden || $is_secret;
			$toggleable = $is_user_hidden && !$is_secret;

			return [
				'macro' => $macro_name,
				'value' => $is_hidden ? '******' : $value,
				'real_value' => $toggleable ? $value : null,
				'hidden' => $is_hidden,
				'toggleable' => $toggleable,
				'type' => $type,
				'description' => $m['description'] ?? ''
			];
		};

		$empty_response = [
			'name' => $name,
			'view_mode' => $view_mode,
			'host' => null,
			'macros' => [],
			'hosts' => [],
			'macro_name' => $macro_filter,
			'columns' => $columns,
			'header_color' => $header_color,
			'error' => '',
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		// Collect host ids: direct + from groups.
		$hostids = [];

		foreach ($direct_hostids as $hid) {
			$hostids[(string) $hid] = true;
		}

		if ($groupids) {
			$group_hosts = API::Host()->get([
				'output' => ['hostid'],
				'groupids' => $groupids,
				'monitored_hosts' => true,
				'preservekeys' => true
			]);

			foreach ($group_hosts as $hid => $_) {
				$hostids[(string) $hid] = true;
			}
		}

		$hostids = array_keys($hostids);

		if (!$hostids) {
			$empty_response['error'] = _('No hosts selected.');
			$this->setResponse(new CControllerResponseData($empty_response));

			return;
		}

		// Fetch host display info.
		$hosts_info = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'hostids' => $hostids,
			'monitored_hosts' => true,
			'preservekeys' => true
		]);

		// Fetch all user macros for these hosts.
		$macros = API::UserMacro()->get([
			'output' => ['hostmacroid', 'macro', 'value', 'type', 'description', 'hostid'],
			'hostids' => $hostids
		]);

		if ($view_mode === 0) {
			// --- Single host mode: show all macros of the first host ---
			$target_hostid = (string) $hostids[0];
			$host_info = $hosts_info[$target_hostid] ?? null;

			if (!$host_info) {
				$empty_response['error'] = _('Host not found.');
				$this->setResponse(new CControllerResponseData($empty_response));

				return;
			}

			// Filter macros belonging to this host.
			$host_macros = [];

			foreach ($macros as $m) {
				if ((string) $m['hostid'] !== $target_hostid) {
					continue;
				}

				// Apply optional name filter (substring match, case-insensitive).
				if ($macro_filter !== '') {
					$search = strtoupper(str_replace(['{$', '}'], '', $macro_filter));

					if (stripos($m['macro'], $search) === false) {
						continue;
					}
				}

				$host_macros[] = $build_entry($m);
			}

			// Sort macros alphabetically.
			usort($host_macros, static function ($a, $b) {
				return strnatcasecmp($a['macro'], $b['macro']);
			});

			$this->setResponse(new CControllerResponseData([
				'name' => $name,
				'view_mode' => 0,
				'host' => [
					'hostid' => $target_hostid,
					'name' => $host_info['name'],
					'host' => $host_info['host']
				],
				'macros' => $host_macros,
				'hosts' => [],
				'macro_name' => $macro_filter,
				'columns' => $columns,
				'header_color' => $header_color,
				'error' => '',
				'user' => ['debug_mode' => $this->getDebugMode()]
			]));
		}
		else {
			// --- Group mode ---

			uasort($hosts_info, static function ($a, $b) {
				return strnatcasecmp($a['name'], $b['name']);
			});

			if ($macro_filter === '') {
				// No filter: show all macros grouped by host.
				$macros_by_host = [];

				foreach ($macros as $m) {
					$macros_by_host[(string) $m['hostid']][] = $build_entry($m);
				}

				// Sort macros alphabetically within each host.
				foreach ($macros_by_host as &$hm) {
					usort($hm, static function ($a, $b) {
						return strnatcasecmp($a['macro'], $b['macro']);
					});
				}
				unset($hm);

				$output_hosts = [];

				foreach ($hosts_info as $hostid => $host) {
					$output_hosts[] = [
						'hostid' => (string) $hostid,
						'name' => $host['name'],
						'host' => $host['host'],
						'macros' => $macros_by_host[(string) $hostid] ?? []
					];
				}

				$this->setResponse(new CControllerResponseData([
					'name' => $name,
					'view_mode' => 2,
					'host' => null,
					'macros' => [],
					'hosts' => $output_hosts,
					'macro_name' => '',
					'columns' => $columns,
					'header_color' => $header_color,
					'error' => '',
					'user' => ['debug_mode' => $this->getDebugMode()]
				]));
			}
			else {
				// With filter: one specific macro per host.
				$normalized = strtoupper(trim($macro_filter));
				$normalized = str_replace(['{$', '}'], '', $normalized);
				$normalized = '{$'.$normalized.'}';

				$macro_by_host = [];

				foreach ($macros as $m) {
					if (strtoupper($m['macro']) === $normalized) {
						$macro_by_host[(string) $m['hostid']] = $build_entry($m);
					}
				}

				$output_hosts = [];

				foreach ($hosts_info as $hostid => $host) {
					$macro_data = $macro_by_host[(string) $hostid] ?? null;

					$output_hosts[] = [
						'hostid' => (string) $hostid,
						'name' => $host['name'],
						'host' => $host['host'],
						'value' => $macro_data ? $macro_data['value'] : null,
						'real_value' => $macro_data ? $macro_data['real_value'] : null,
						'hidden' => $macro_data ? $macro_data['hidden'] : false,
						'toggleable' => $macro_data ? $macro_data['toggleable'] : false,
						'type' => $macro_data ? $macro_data['type'] : null,
						'description' => $macro_data ? $macro_data['description'] : ''
					];
				}

				$this->setResponse(new CControllerResponseData([
					'name' => $name,
					'view_mode' => 1,
					'host' => null,
					'macros' => [],
					'hosts' => $output_hosts,
					'macro_name' => $normalized,
					'columns' => $columns,
					'header_color' => $header_color,
					'error' => '',
					'user' => ['debug_mode' => $this->getDebugMode()]
				]));
			}
		}
	}
}
