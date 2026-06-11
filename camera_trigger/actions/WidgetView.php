<?php declare(strict_types = 0);

namespace Modules\CameraTrigger\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getName());

		// Dashboard host broadcaster / Template Dashboard takes precedence.
		$override_hostid = $this->fields_values['override_hostid'] ?? [];
		$hostids = $override_hostid
			? (is_array($override_hostid) ? $override_hostid : [$override_hostid])
			: ($this->fields_values['hostid'] ?? []);

		$macro_names = [
			'base' => self::normalizeMacro($this->fields_values['macro_url'] ?? '', '{$CAM_URL}'),
			'user' => self::normalizeMacro($this->fields_values['macro_user'] ?? '', '{$CAM_USER}'),
			'pass' => self::normalizeMacro($this->fields_values['macro_pass'] ?? '', '{$CAM_PASS}')
		];

		$config = ['base' => '', 'user' => '', 'pass' => '',
			'hostid' => $hostids ? (string) $hostids[0] : '',
			'timeout' => (int) ($this->fields_values['trigger_timeout'] ?? 15),
			'refresh' => (int) ($this->fields_values['refresh_sec'] ?? 0)
		];
		$error = '';

		if (!$hostids) {
			$error = _('No host selected.');
		}
		else {
			$macros = self::resolveHostMacros((string) $hostids[0], array_values($macro_names));

			foreach ($macro_names as $key => $macro) {
				$config[$key] = $macros[$macro] ?? '';
			}

			if ($config['base'] === '') {
				$error = sprintf(_('Macro %s not found on the host (or its templates).'), $macro_names['base']);
			}
		}

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'config' => $config,
			'error' => $error,
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	/**
	 * Accepts "CAM_URL" or "{$CAM_URL}"; empty input falls back to default.
	 */
	private static function normalizeMacro(string $value, string $default): string {
		$value = strtoupper(trim($value));
		$value = str_replace(['{$', '}'], '', $value);

		return $value === '' ? $default : '{$'.$value.'}';
	}

	/**
	 * Resolve user macro values for a host, walking up the template chain.
	 * Host-level value wins; otherwise the nearest template level wins.
	 * Secret macros (type 1) have no value in the API response and resolve to ''.
	 *
	 * @return array  macro name ({$NAME}) => value
	 */
	private static function resolveHostMacros(string $hostid, array $wanted): array {
		$resolved = [];
		$level_ids = [$hostid];
		$seen = [$hostid => true];
		$is_host_level = true;

		while ($level_ids && count($resolved) < count($wanted)) {
			$macros = API::UserMacro()->get([
				'output' => ['macro', 'value'],
				'hostids' => $level_ids
			]);

			foreach ($macros as $m) {
				$macro = strtoupper($m['macro']);

				if (in_array($macro, $wanted, true) && !array_key_exists($macro, $resolved)) {
					$resolved[$macro] = (string) ($m['value'] ?? '');
				}
			}

			// Climb to the next template level.
			$parents = $is_host_level
				? API::Host()->get([
					'output' => ['hostid'],
					'hostids' => $level_ids,
					'templated_hosts' => true, // hostid pode ser um template (preview no template dashboard)
					'selectParentTemplates' => ['templateid']
				])
				: API::Template()->get([
					'output' => ['templateid'],
					'templateids' => $level_ids,
					'selectParentTemplates' => ['templateid']
				]);
			$is_host_level = false;

			$next = [];

			foreach ($parents as $entity) {
				foreach ($entity['parentTemplates'] ?? [] as $tpl) {
					$tplid = (string) $tpl['templateid'];

					if (!isset($seen[$tplid])) {
						$seen[$tplid] = true;
						$next[] = $tplid;
					}
				}
			}

			$level_ids = $next;
		}

		return $resolved;
	}
}
