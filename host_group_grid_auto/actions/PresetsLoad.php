<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Actions;

use CController,
	CControllerResponseData,
	CWebUser,
	Modules\HostGroupGridAuto\Includes\PresetStore;

/**
 * AJAX: devolve o conteúdo (data) de uma predefinição para preencher o grid de tipos de site. Retorna
 * erro se a predefinição não existir ou se o usuário não puder acessá-la (não é membro do grupo dono e
 * não é Super Admin) — a checagem fica no PresetStore.
 */
class PresetsLoad extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'usrgrpid' => 'required|id',
			'name' => 'required|string'
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
		$name = (string) $this->getInput('name');

		$userid = (int) CWebUser::$data['userid'];
		$is_super = $this->getUserType() == USER_TYPE_SUPER_ADMIN;
		$usrgrpids = array_map('intval', array_keys(PresetStore::userUsrgrps($userid)));

		$preset = PresetStore::load($usrgrpid, $name, $usrgrpids, $is_super);

		if ($preset === null) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [_('Predefinição não encontrada')]]
			], JSON_THROW_ON_ERROR)]));
			return;
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'data' => $preset['data']
		], JSON_THROW_ON_ERROR)]));
	}
}
