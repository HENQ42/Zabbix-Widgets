<?php declare(strict_types = 0);

namespace Modules\CameraTrigger\Actions;

use API;
use CController;
use CControllerResponseData;

/**
 * Persiste/serve a ÚLTIMA captura de cada host (uma única imagem por host,
 * sempre sobrescrita). Os metadados (NDataComp) já vêm embutidos no próprio
 * JPEG, então não há arquivo de metadados separado.
 *
 *   GET  zabbix.php?action=widget.camera_trigger.image&hostid=N  → image/jpeg | 404
 *   POST zabbix.php?action=widget.camera_trigger.image&hostid=N  → salva corpo (JPEG cru), responde JSON
 */
class ImageStore extends CController {

	private const STORAGE_DIR = '/var/lib/zabbix-ui/camera_trigger';
	private const MAX_BYTES = 8 * 1024 * 1024;

	protected function init(): void {
		// A imagem é enviada como corpo binário cru pelo viewer (iframe same-origin,
		// autenticado por cookie de sessão) — sem form, logo sem token CSRF.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'id|required'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		if ($this->getUserType() < USER_TYPE_ZABBIX_USER) {
			return false;
		}

		// O host precisa ser visível para o usuário logado.
		return (bool) API::Host()->get([
			'output' => [],
			'hostids' => $this->getInput('hostid'),
			'templated_hosts' => true
		]);
	}

	protected function doAction(): void {
		$path = self::STORAGE_DIR.'/'.$this->getInput('hostid').'.jpg';

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$this->saveImage($path);
			return;
		}

		$this->serveImage($path);
	}

	private function serveImage(string $path): void {
		if (!is_file($path)) {
			http_response_code(404);
			header('Content-Type: text/plain');
			echo 'No saved image for this host.';
			exit;
		}

		header('Content-Type: image/jpeg');
		header('Content-Length: '.filesize($path));
		header('Cache-Control: no-store');
		readfile($path);
		exit;
	}

	private function saveImage(string $path): void {
		$body = file_get_contents('php://input', false, null, 0, self::MAX_BYTES + 1);
		$output = ['success' => false];

		if ($body === false || strlen($body) < 4) {
			$output['error'] = 'Empty body.';
		}
		elseif (strlen($body) > self::MAX_BYTES) {
			$output['error'] = 'Image too large.';
		}
		// Magic bytes JPEG (FF D8 FF) — recusa qualquer coisa que não seja imagem.
		elseif (substr($body, 0, 3) !== "\xFF\xD8\xFF") {
			$output['error'] = 'Body is not a JPEG.';
		}
		else {
			// Escrita atômica: tmp no mesmo diretório + rename.
			$tmp = $path.'.tmp.'.getmypid();

			if (file_put_contents($tmp, $body) === strlen($body) && rename($tmp, $path)) {
				$output = ['success' => true, 'bytes' => strlen($body)];
			}
			else {
				@unlink($tmp);
				$output['error'] = 'Failed to write file (check directory permissions).';
			}
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($output)]));
	}
}
