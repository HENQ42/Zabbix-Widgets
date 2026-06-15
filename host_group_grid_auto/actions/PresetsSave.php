<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Actions;

use CController,
	CControllerResponseData,
	CWebUser,
	Modules\HostGroupGridAuto\Includes\PresetStore;

/**
 * AJAX: cria ou sobrescreve (editar) uma predefinição de tipos de site. O PresetStore::save é um UPSERT,
 * então o mesmo (usrgrpid, name) atualiza o conteúdo. A barreira de grupo (só grava em grupo do qual o
 * usuário participa; Super Admin escapa) é aplicada no PresetStore.
 *
 * CSRF desabilitado por consistência com as demais ações deste módulo (widget.host_group_grid_auto.*) e
 * porque o escopo é restrito: a operação só atinge predefinições dos próprios grupos do usuário.
 */
class PresetsSave extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'usrgrpid' => 'required|id',
			'name' => 'required|string',
			'data' => 'required|string'
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
		$usrgrpid = (int) $this->getInput('usrgrpid');
		$name = trim((string) $this->getInput('name'));
		$data_raw = (string) $this->getInput('data');

		if ($name === '') {
			$this->respondError(_('Informe o nome da predefinição'));
			return;
		}

		$data = json_decode($data_raw, true);
		if (!is_array($data)) {
			$this->respondError(_('Conteúdo da predefinição inválido'));
			return;
		}

		$userid = (int) CWebUser::$data['userid'];
		$is_super = $this->getUserType() == USER_TYPE_SUPER_ADMIN;
		$usrgrpids = array_map('intval', array_keys(PresetStore::userUsrgrps($userid)));

		$ok = PresetStore::save($usrgrpid, $name, $data, $usrgrpids, $is_super);

		if (!$ok) {
			$this->respondError(_('Você não faz parte do grupo dono desta predefinição'));
			return;
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'success' => true
		], JSON_THROW_ON_ERROR)]));
	}

	private function respondError(string $message): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'error' => ['messages' => [$message]]
		], JSON_THROW_ON_ERROR)]));
	}
}
