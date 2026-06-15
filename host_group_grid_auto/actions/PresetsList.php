<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Actions;

use CController,
	CControllerResponseData,
	CWebUser,
	Modules\HostGroupGridAuto\Includes\PresetStore;

/**
 * AJAX helper do editor do widget: devolve as predefinições (presets) de "tipos de site" que o usuário
 * atual pode ver — apenas as cujo grupo dono está entre os grupos do usuário (Super Admin vê todas) —
 * junto da lista dos grupos do próprio usuário (para o seletor de "grupo dono" ao salvar).
 *
 * Toda a barreira de permissão fica no PresetStore; aqui só resolvemos o usuário corrente e repassamos.
 */
class PresetsList extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$userid = (int) CWebUser::$data['userid'];
		$is_super = $this->getUserType() == USER_TYPE_SUPER_ADMIN;

		$user_groups = PresetStore::userUsrgrps($userid);
		$usrgrpids = array_map('intval', array_keys($user_groups));

		$presets = PresetStore::listForUser($usrgrpids, $is_super);

		// Nome do grupo para exibir junto de cada preset (Super Admin pode ver presets de grupos que
		// não são dele, então o nome nem sempre está em $user_groups).
		$group_names = $user_groups;
		foreach ($presets as $p) {
			if (!isset($group_names[$p['usrgrpid']])) {
				$group_names[$p['usrgrpid']] = '#'.$p['usrgrpid'];
			}
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'presets' => array_map(static function ($p) use ($group_names) {
				return [
					'usrgrpid' => $p['usrgrpid'],
					'name' => $p['name'],
					'usrgrp_name' => $group_names[$p['usrgrpid']] ?? ('#'.$p['usrgrpid'])
				];
			}, $presets),
			// Grupos do próprio usuário — opções válidas de "grupo dono" ao salvar.
			'own_groups' => array_map(static function ($id, $name) {
				return ['usrgrpid' => (string) $id, 'name' => $name];
			}, array_keys($user_groups), array_values($user_groups))
		], JSON_THROW_ON_ERROR)]));
	}
}
