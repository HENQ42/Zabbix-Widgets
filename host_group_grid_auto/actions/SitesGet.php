<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Actions;

use API,
	CController,
	CControllerResponseData;

/**
 * AJAX helper do editor: dado o(s) grupo(s) de hosts pai, descobre os SITES existentes (PREFIX_<SITE>)
 * entre os hosts monitorados sob a hierarquia e devolve a lista distinta. O grid de "tipos de site" usa
 * essa lista para oferecer um select por linha — em vez de digitar à mão — desabilitando os sites já
 * atrelados a outro tipo.
 *
 * Espelha a extração de site do WidgetView (mesma regra numérica + texto via prefixo aprendido); mantido
 * autocontido para o formulário não depender do controller da view.
 */
class SitesGet extends CController {

	// Mesma regra do WidgetView::SITE_REGEX_NUM: captura o prefixo (tudo antes do número) e o numerador
	// <NN> do site. O numerador é uma âncora inequívoca; sites em TEXTO (ex.: SEFAZ_AL_CENTRO) não têm
	// âncora e são resolvidos pelo prefixo aprendido dos sites numéricos. O site_id devolvido é o
	// composto `prefix_site` (ex.: SEFAZ_AL_03, SEFAZ_AL_CENTRO).
	private const SITE_REGEX_NUM = '/^(?P<prefix>.+?)_(?P<site>\d{2})(?:_.*)?$/';

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

					// Aprende os prefixos a partir dos sites numéricos (âncora inequívoca) para depois
					// localizar os sites em texto. Mais longos primeiro, para casar o mais específico.
					$known_prefixes = [];
					foreach ($hosts as $host) {
						if (preg_match(self::SITE_REGEX_NUM, (string) $host['host'], $m)) {
							$known_prefixes[$m['prefix']] = true;
						}
					}
					$known_prefixes = array_keys($known_prefixes);
					usort($known_prefixes, static fn($a, $b) => strlen($b) <=> strlen($a));

					// Dedupe por site_id (composto prefix_site), guardando o identificador para exibição/valor.
					$seen = [];
					foreach ($hosts as $host) {
						$parsed = $this->extractSite((string) $host['host'], $known_prefixes);
						if ($parsed !== null) {
							[$site_id, $token] = $parsed;
							$seen[$site_id] = $token;
						}
					}

					foreach ($seen as $site_id => $token) {
						$sites[] = ['number' => $token, 'site_id' => $site_id];
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

	/**
	 * Extrai o site de um nome técnico, devolvendo [site_id, token] ou null. Espelha
	 * WidgetView::extractSite(): site numérico via âncora <NN>; site em texto via prefixo conhecido.
	 *
	 * @param string[] $known_prefixes prefixos do mais longo para o mais curto
	 * @return array{0: string, 1: string}|null
	 */
	private function extractSite(string $host_technical_name, array $known_prefixes): ?array {
		if (preg_match(self::SITE_REGEX_NUM, $host_technical_name, $m)) {
			return [$m['prefix'].'_'.$m['site'], $m['site']];
		}

		foreach ($known_prefixes as $prefix) {
			$needle = $prefix.'_';
			if (strncmp($host_technical_name, $needle, strlen($needle)) !== 0) {
				continue;
			}
			$rest = substr($host_technical_name, strlen($needle));
			$token = explode('_', $rest, 2)[0];
			if ($token !== '') {
				return [$prefix.'_'.$token, $token];
			}
		}

		return null;
	}
}
