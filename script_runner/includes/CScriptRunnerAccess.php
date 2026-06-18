<?php declare(strict_types = 0);

namespace Modules\ScriptRunner\Includes;

use API;
use CWebUser;

/**
 * Regra de autorizacao unica e centralizada, revalidada no servidor em TODA action.
 *
 * Esconder o widget na UI NAO basta: as actions AJAX (view/execute) sao chamaveis
 * diretamente. Por isso todo controller chama CScriptRunnerAccess::isAuthorized().
 *
 * Regra (definida com o usuario): exige Super Admin E pertencer ao grupo de usuarios
 * REQUIRED_USRGRPID (defesa em profundidade).
 */
class CScriptRunnerAccess {

	/** Grupo de usuarios autorizado a usar o widget. */
	public const REQUIRED_USRGRPID = 7;

	public static function isAuthorized(): bool {
		if (CWebUser::getType() != USER_TYPE_SUPER_ADMIN) {
			return false;
		}

		$userid = CWebUser::$data['userid'] ?? null;

		if ($userid === null) {
			return false;
		}

		$users = API::User()->get([
			'output' => [],
			'userids' => $userid,
			'selectUsrgrps' => ['usrgrpid']
		]);

		if (!$users) {
			return false;
		}

		$usrgrpids = array_column($users[0]['usrgrps'] ?? [], 'usrgrpid');

		return in_array((string) self::REQUIRED_USRGRPID, $usrgrpids, true)
			|| in_array(self::REQUIRED_USRGRPID, array_map('intval', $usrgrpids), true);
	}
}
