<?php declare(strict_types = 0);

namespace Modules\ScriptRunner\Actions;

use API;
use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ScriptRunner\Includes\CScriptRunnerAccess;

/**
 * Busca de hosts para o seletor do painel "Macros do host".
 *
 * Recebe um termo de busca e devolve ate 30 hosts visiveis ao usuario (a API respeita
 * as permissoes). Usado pelo autocomplete do widget para escolher o host de contexto.
 */
class Hosts extends CController {

	private const LIMIT = 30;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkPermissions(): bool {
		return CScriptRunnerAccess::isAuthorized();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'search' => 'string',
			CSRF_TOKEN_NAME => 'string'
		]);

		if ($ret && !CCsrfTokenHelper::check((string) $this->getInput(CSRF_TOKEN_NAME, ''), 'widget')) {
			$ret = false;
		}

		if (!$ret) {
			$this->respond(['ok' => false, 'error' => 'Requisicao invalida.']);
		}

		return $ret;
	}

	protected function doAction(): void {
		$search = trim((string) $this->getInput('search', ''));

		$options = [
			'output' => ['hostid', 'host', 'name'],
			'sortfield' => 'name',
			'limit' => self::LIMIT
		];

		if ($search !== '') {
			$options['search'] = ['name' => $search, 'host' => $search];
			$options['searchByAny'] = true;
		}

		$hosts = API::Host()->get($options);

		$out = [];
		foreach ($hosts as $h) {
			$out[] = [
				'hostid' => $h['hostid'],
				'name' => $h['name'],
				'host' => $h['host']
			];
		}

		$this->respond(['ok' => true, 'hosts' => $out]);
	}

	private function respond(array $payload): void {
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
		]));
	}
}
