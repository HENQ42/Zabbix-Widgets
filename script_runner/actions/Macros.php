<?php declare(strict_types = 0);

namespace Modules\ScriptRunner\Actions;

use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ScriptRunner\Includes\CHostMacros;
use Modules\ScriptRunner\Includes\CScriptRunnerAccess;

/**
 * Lista as macros de um host para o painel "Macros do host" do widget.
 *
 * Independente do sistema de scripts: serve apenas para o usuario consultar as macros
 * de um host e inserir referencias "{$NOME}" nos campos. Macros secretas voltam mascaradas.
 *
 * Defesas:
 *  - Autorizacao revalidada (Super Admin + grupo autorizado).
 *  - CSRF verificado manualmente contra o token do grupo "widget".
 *  - Acesso ao host respeita as permissoes do usuario (via API em CHostMacros).
 */
class Macros extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkPermissions(): bool {
		return CScriptRunnerAccess::isAuthorized();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'hostid' => 'string|required',
			CSRF_TOKEN_NAME => 'string'
		]);

		if ($ret && !CCsrfTokenHelper::check((string) $this->getInput(CSRF_TOKEN_NAME, ''), 'widget')) {
			$ret = false;
		}

		if (!$ret) {
			$this->respond([
				'ok' => false,
				'error' => 'Requisicao invalida (parametros ou token de seguranca).'
			]);
		}

		return $ret;
	}

	protected function doAction(): void {
		$hostid = (string) $this->getInput('hostid');

		try {
			$data = CHostMacros::listForHost($hostid);
		}
		catch (\Exception $e) {
			$this->respond([
				'ok' => false,
				'error' => $e->getMessage()
			]);
			return;
		}

		$this->respond([
			'ok' => true,
			'host' => $data['host'],
			'macros' => $data['macros']
		]);
	}

	private function respond(array $payload): void {
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
		]));
	}
}
