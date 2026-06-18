<?php declare(strict_types = 0);

namespace Modules\ScriptRunner\Actions;

use CControllerDashboardWidgetView;
use CControllerResponseData;
use Modules\ScriptRunner\Includes\CScriptCatalog;
use Modules\ScriptRunner\Includes\CScriptRunnerAccess;

class WidgetView extends CControllerDashboardWidgetView {

	/**
	 * Revalida o acesso no servidor: a action e chamavel diretamente, esconder o
	 * widget na UI nao basta. Exige Super Admin + grupo autorizado.
	 */
	protected function checkPermissions(): bool {
		return parent::checkPermissions() && CScriptRunnerAccess::isAuthorized();
	}

	protected function doAction(): void {
		$catalog = CScriptCatalog::listAll();

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'catalog' => $catalog,
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		]));
	}
}
