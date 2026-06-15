<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Actions;

use API,
	CController,
	CControllerResponseData;

/**
 * AJAX helper do editor: dado o(s) grupo(s) de hosts pai, descobre os SITES existentes (PREFIX_NN) entre
 * os hosts monitorados sob a hierarquia e devolve a lista distinta. O grid de "tipos de site" usa essa
 * lista para oferecer um select por linha — em vez de digitar números à mão — desabilitando os sites já
 * atrelados a outro tipo.
 *
 * Espelha a extração de site do WidgetView (mesma SITE_REGEX); mantido autocontido para o formulário não
 * depender do controller da view.
 */
class SitesGet extends CController {

	// Mesma regra do WidgetView::SITE_REGEX: captura o prefixo (tudo antes do número) e o número de 2
	// dígitos do site. O site_id devolvido é o composto `prefix_site` (ex.: SEFAZ_AL_03).
	private const SITE_REGEX = '/^(?P<prefix>.+?)_(?P<site>\d{2})(?:_.*)?$/';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'groupids' => 'array_id'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => array_column(get_and_clear_messages(), 'message')]
			], JSON_THROW_ON_ERROR)]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$parent_groupids = $this->getInput('groupids', []);
		$sites = [];

		if ($parent_groupids) {
			$parent_groups = API::HostGroup()->get([
				'output' => ['name'],
				'groupids' => $parent_groupids
			]);

			if ($parent_groups) {
				$parent_names = array_column($parent_groups, 'name');

				$all_groups = API::HostGroup()->get([
					'output' => ['groupid', 'name']
				]);

				// Grupos filho: nome começa com "<pai>/".
				$child_groupids = [];
				foreach ($all_groups as $group) {
					$gname = (string) $group['name'];
					foreach ($parent_names as $pname) {
						$prefix = $pname.'/';
						if (strncmp($gname, $prefix, strlen($prefix)) === 0) {
							$child_groupids[] = $group['groupid'];
							break;
						}
					}
				}

				if ($child_groupids) {
					$hosts = API::Host()->get([
						'output' => ['host'],
						'groupids' => array_values(array_unique($child_groupids)),
						'monitored_hosts' => true
					]);

					// Dedupe por site_id (composto prefix_site), guardando o número para exibição/valor.
					$seen = [];
					foreach ($hosts as $host) {
						if (preg_match(self::SITE_REGEX, (string) $host['host'], $m)) {
							$site_id = $m['prefix'].'_'.$m['site'];
							$seen[$site_id] = $m['site'];
						}
					}

					foreach ($seen as $site_id => $number) {
						$sites[] = ['number' => $number, 'site_id' => $site_id];
					}

					usort($sites, static function ($a, $b) {
						return strnatcasecmp($a['number'], $b['number'])
							?: strnatcasecmp($a['site_id'], $b['site_id']);
					});
				}
			}
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'sites' => $sites
		], JSON_THROW_ON_ERROR)]));
	}
}
