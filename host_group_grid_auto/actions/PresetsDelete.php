<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Actions;

use CController,
	CControllerResponseData,
	CWebUser,
	Modules\HostGroupGridAuto\Includes\PresetStore;

/**
 * AJAX: apaga uma predefinição. Mesma barreira de grupo do save (só apaga em grupo do qual o usuário
 * participa; Super Admin escapa), aplicada no PresetStore.
 *
 * CSRF desabilitado por consistência com as demais ações do módulo e por escopo restrito (predefinições
 * dos próprios grupos do usuário).
 */
class PresetsDelete extends CController {

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

		$ok = PresetStore::delete($usrgrpid, $name, $usrgrpids, $is_super);

		if (!$ok) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [_('Você não faz parte do grupo dono desta predefinição')]]
			], JSON_THROW_ON_ERROR)]));
			return;
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'success' => true
		], JSON_THROW_ON_ERROR)]));
	}
}
