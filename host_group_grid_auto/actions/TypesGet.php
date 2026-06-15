<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Actions;

use API,
	CController,
	CControllerResponseData;

/**
 * AJAX helper for the widget config form: given the selected parent host group(s), discover the child
 * groups (EMPRESA/CONTRATO/TIPO/MODELO) and return the distinct host TYPEs (the first path segment after
 * the parent prefix). The "Host type" drill-down field is populated from this list, so it always offers
 * exactly the types that exist under the chosen parent — no free typing required.
 *
 * Mirrors WidgetView::discoverChildGroupTypes(); kept self-contained so the form has no dependency on the
 * view controller.
 */
class TypesGet extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'groupids' => 'array_id'
		]);

		if (!$ret) {
			$this->setResponse(
				new CControllerResponseData(['main_block' => json_encode([
					'error' => [
						'messages' => array_column(get_and_clear_messages(), 'message')
					]
				], JSON_THROW_ON_ERROR)])
			);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$parent_groupids = $this->getInput('groupids', []);
		$types = [];

		if ($parent_groupids) {
			$parent_groups = API::HostGroup()->get([
				'output' => ['name'],
				'groupids' => $parent_groupids
			]);

			if ($parent_groups) {
				$parent_names = array_column($parent_groups, 'name');

				$all_groups = API::HostGroup()->get([
					'output' => ['name']
				]);

				$seen = [];
				foreach ($all_groups as $group) {
					$gname = (string) $group['name'];
					foreach ($parent_names as $pname) {
						$prefix = $pname.'/';
						if (strncmp($gname, $prefix, strlen($prefix)) !== 0) {
							continue;
						}
						$rest = substr($gname, strlen($prefix));
						$segments = explode('/', $rest);
						$tipo = strtoupper(trim($segments[0] ?? ''));
						if ($tipo !== '') {
							$seen[$tipo] = true;
						}
						break;
					}
				}

				$types = array_keys($seen);
				usort($types, 'strnatcasecmp');
			}
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'types' => $types
		], JSON_THROW_ON_ERROR)]));
	}
}
