<?php declare(strict_types = 0);

namespace Modules\ScriptRunner\Actions;

use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use CWebUser;
use Modules\ScriptRunner\Includes\CHostMacros;
use Modules\ScriptRunner\Includes\CScriptCatalog;
use Modules\ScriptRunner\Includes\CScriptRunnerAccess;

/**
 * Executa uma ACAO (botao) de um script do catalogo.
 *
 * Defesas (ver tambem CScriptRunnerAccess, CScriptCatalog e CHostMacros):
 *  - Autorizacao revalidada no servidor (Super Admin + grupo autorizado).
 *  - CSRF verificado manualmente contra o token do grupo "widget".
 *  - slug validado contra a allowlist descoberta (anti path traversal).
 *  - script_action validada contra as acoes declaradas no manifest (anti acao forjada).
 *  - Referencias "{$MACRO}" resolvidas no servidor contra o host selecionado.
 *  - Parametros validados contra o schema (apenas os campos da acao) ANTES de executar.
 *  - proc_open com ARRAY de argumentos (sem shell): cada valor de campo vira UM argumento,
 *    nunca interpolado em linha de comando -> zero parsing de shell sobre dado do usuario.
 *  - Timeout por script; processo travado e terminado.
 *  - Auditoria best-effort com os valores ORIGINAIS (pre-resolucao) e secret mascarado.
 */
class Execute extends CController {

	private const AUDIT_DIR = '/var/lib/zabbix-ui/script_runner';
	private const AUDIT_FILE = '/var/lib/zabbix-ui/script_runner/audit.log';
	private const SECRET_PLACEHOLDER_PREFIX = '__ZBX_SR_SECRET_';

	protected function init(): void {
		// CSRF e verificado manualmente em checkInput() contra o grupo "widget".
		$this->disableCsrfValidation();
	}

	protected function checkPermissions(): bool {
		return CScriptRunnerAccess::isAuthorized();
	}

	protected function checkInput(): bool {
		$fields = [
			'script' => 'string|required',
			'script_action' => 'string|required',
			'hostid' => 'string',
			'params' => 'string',
			CSRF_TOKEN_NAME => 'string'
		];

		$ret = $this->validateInput($fields);

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
		$slug = (string) $this->getInput('script');
		$action_id = (string) $this->getInput('script_action');
		$hostid = trim((string) $this->getInput('hostid', ''));

		try {
			$meta = CScriptCatalog::loadMeta($slug);
		}
		catch (\Exception $e) {
			$this->respond([
				'ok' => false,
				'error' => 'Script indisponivel: '.$e->getMessage()
			]);
			return;
		}

		if (empty($meta['is_active'])) {
			$this->respond([
				'ok' => false,
				'error' => 'Este script esta desativado e nao pode ser executado.'
			]);
			return;
		}

		$action = CScriptCatalog::findAction($meta, $action_id);
		if ($action === null) {
			$this->respond([
				'ok' => false,
				'error' => 'Acao desconhecida para este script.'
			]);
			return;
		}

		$params_raw = (string) $this->getInput('params', '{}');
		$params = json_decode($params_raw, true);

		if (!is_array($params)) {
			$this->respond([
				'ok' => false,
				'error' => 'Parametros mal formatados.'
			]);
			return;
		}

		// Valores originais (com "{$X}" literal) para a auditoria.
		$original_params = $params;
		$secret_values = [];

		// Resolucao de macros "{$X}" -> valor real, sempre no servidor.
		if ($this->hasMacroRef($params)) {
			if ($hostid === '') {
				$this->respond([
					'ok' => false,
					'error' => 'Selecione um host no painel de macros para usar referencias {$...}.'
				]);
				return;
			}

			try {
				$resolution = CHostMacros::resolveValues($hostid, $params);
			}
			catch (\Exception $e) {
				$this->respond([
					'ok' => false,
					'error' => 'Nao foi possivel resolver as macros: '.$e->getMessage()
				]);
				return;
			}

			if ($resolution['errors']) {
				$this->respond([
					'ok' => false,
					'error' => 'Ha referencias de macro invalidas. Corrija e tente novamente.',
					'field_errors' => $resolution['errors']
				]);
				return;
			}

			$params = $resolution['values'];
			$secret_values = $resolution['secret_values'] ?? [];
		}

		$validation = CScriptCatalog::validateParamsForAction($meta, $action, $params);

		if ($validation['errors']) {
			$this->respond([
				'ok' => false,
				'error' => 'Ha campos invalidos. Corrija e tente novamente.',
				'field_errors' => $validation['errors']
			]);
			return;
		}

		$argv = CScriptCatalog::buildArgv($meta, $action, $validation['values']);
		$protected = $this->protectSecretArgv($argv, $secret_values);

		$run = $this->runScript($meta, $protected['argv'], $protected['secret_map']);

		$this->audit($meta, $action, $hostid, $original_params, $run);

		$this->respond($this->buildResult($meta, $run, $secret_values));
	}

	/**
	 * True se algum valor string submetido contem referencia de macro "{$...}".
	 */
	private function hasMacroRef(array $params): bool {
		foreach ($params as $val) {
			if (is_string($val) && CHostMacros::containsMacroRef($val)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Executa o script com proc_open (sem shell), passando os argumentos ja montados.
	 *
	 * @return array exit_code, stdout, stderr, duration_ms, timed_out, spawn_error
	 */
	private function protectSecretArgv(array $argv, array $secret_values): array {
		$secret_map = [];
		$safe_argv = $argv;
		$idx = 0;
		$secret_values = $this->normalizeSecretValues($secret_values);

		foreach ($secret_values as $secret) {
			$placeholder = self::SECRET_PLACEHOLDER_PREFIX.$idx.'__';
			$used = false;

			foreach ($safe_argv as $i => $arg) {
				if (strpos((string) $arg, $secret) !== false) {
					$safe_argv[$i] = str_replace($secret, $placeholder, (string) $arg);
					$used = true;
				}
			}

			if ($used) {
				$secret_map[$placeholder] = $secret;
				$idx++;
			}
		}

		return ['argv' => $safe_argv, 'secret_map' => $secret_map];
	}

	private function runScript(array $meta, array $argv, array $secret_map = []): array {
		if ($secret_map) {
			$wrapper = realpath(__DIR__.'/../includes/secret_argv_wrapper.py');
			if ($wrapper === false || !is_file($wrapper)) {
				return [
					'spawn_error' => true,
					'exit_code' => null,
					'stdout' => '',
					'stderr' => 'Wrapper de execucao segura nao encontrado.',
					'duration_ms' => 0,
					'timed_out' => false
				];
			}

			$cmd = array_merge([CScriptCatalog::INTERPRETER, $wrapper, $meta['entrypoint']], $argv);
		}
		else {
			$cmd = array_merge([CScriptCatalog::INTERPRETER, $meta['entrypoint']], $argv);
		}

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		];

		$env = [
			'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'LANG' => 'C.UTF-8',
			'LC_ALL' => 'C.UTF-8'
		];

		$started = microtime(true);
		$proc = @proc_open($cmd, $descriptors, $pipes, $meta['dir'], $env);

		if (!is_resource($proc)) {
			return [
				'spawn_error' => true,
				'exit_code' => null,
				'stdout' => '',
				'stderr' => '',
				'duration_ms' => 0,
				'timed_out' => false
			];
		}

		if ($secret_map) {
			fwrite($pipes[0], json_encode($secret_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}
		// O script recebe os dados por argv; stdin e usado apenas pelo wrapper de segredos.
		fclose($pipes[0]);

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$exit_code = null;
		$timed_out = false;
		$deadline = $started + $meta['timeout'];

		while (true) {
			$chunk = stream_get_contents($pipes[1]);
			if ($chunk !== false && $chunk !== '') {
				$stdout .= $chunk;
			}
			$chunk = stream_get_contents($pipes[2]);
			if ($chunk !== false && $chunk !== '') {
				$stderr .= $chunk;
			}

			$status = proc_get_status($proc);
			if (!$status['running']) {
				$exit_code = $status['exitcode'];
				break;
			}

			if (microtime(true) >= $deadline) {
				$timed_out = true;
				proc_terminate($proc, 15); // SIGTERM
				usleep(300000);
				$status = proc_get_status($proc);
				if ($status['running']) {
					proc_terminate($proc, 9); // SIGKILL
				}
				break;
			}

			usleep(25000);
		}

		// Drena o que restou nos buffers.
		$stdout .= (string) stream_get_contents($pipes[1]);
		$stderr .= (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		$close_code = proc_close($proc);
		if ($exit_code === null) {
			$exit_code = $close_code;
		}

		return [
			'spawn_error' => false,
			'exit_code' => $exit_code,
			'stdout' => $stdout,
			'stderr' => $stderr,
			'duration_ms' => (int) round((microtime(true) - $started) * 1000),
			'timed_out' => $timed_out
		];
	}

	/**
	 * Traduz o resultado bruto da execucao no contrato consumido pela interface.
	 */
	private function buildResult(array $meta, array $run, array $secret_values): array {
		$details = [
			'exit_code' => $run['exit_code'],
			'duration_ms' => $run['duration_ms'],
			'timed_out' => $run['timed_out'],
			'stdout' => $this->redactText($run['stdout'], $secret_values),
			'stderr' => $this->redactText($run['stderr'], $secret_values)
		];

		if (!empty($run['spawn_error'])) {
			return [
				'ok' => false,
				'error' => 'Nao foi possivel iniciar o interpretador "'.CScriptCatalog::INTERPRETER.'".',
				'details' => $details
			];
		}

		if ($run['timed_out']) {
			return [
				'ok' => false,
				'error' => 'Tempo limite de '.$meta['timeout'].'s excedido. A execucao foi interrompida.',
				'details' => $details
			];
		}

		$decoded = json_decode(trim((string) $run['stdout']), true);

		if (!is_array($decoded) || !array_key_exists('ok', $decoded)) {
			return [
				'ok' => false,
				'error' => 'O script nao respondeu no formato esperado (JSON com a chave "ok").',
				'details' => $details
			];
		}

		$decoded = $this->redactData($decoded, $secret_values);
		$script_ok = ($decoded['ok'] === true) && ($run['exit_code'] === 0);

		return [
			'ok' => $script_ok,
			'message' => $script_ok ? (string) ($decoded['message'] ?? 'Concluido com sucesso.') : null,
			'error' => $script_ok ? null : (string) ($decoded['error'] ?? $decoded['message'] ?? 'O script reportou uma falha.'),
			'result' => $decoded,
			'details' => $details
		];
	}

	private function redactData($data, array $secret_values) {
		if (is_string($data)) {
			return $this->redactText($data, $secret_values);
		}

		if (is_array($data)) {
			foreach ($data as $key => $value) {
				$data[$key] = $this->redactData($value, $secret_values);
			}
		}

		return $data;
	}

	private function redactText(string $text, array $secret_values): string {
		foreach ($this->normalizeSecretValues($secret_values) as $secret) {
			$text = str_replace($secret, '***', $text);

			$encoded = json_encode($secret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if (is_string($encoded) && strlen($encoded) >= 2) {
				$text = str_replace(substr($encoded, 1, -1), '***', $text);
			}

			$encoded_ascii = json_encode($secret, JSON_UNESCAPED_SLASHES);
			if (is_string($encoded_ascii) && strlen($encoded_ascii) >= 2) {
				$text = str_replace(substr($encoded_ascii, 1, -1), '***', $text);
			}
		}

		return $text;
	}

	private function normalizeSecretValues(array $secret_values): array {
		$out = [];

		foreach ($secret_values as $secret) {
			$secret = (string) $secret;
			if ($secret !== '') {
				$out[$secret] = true;
			}
		}

		$out = array_keys($out);
		usort($out, static function (string $a, string $b): int {
			return strlen($b) <=> strlen($a);
		});

		return $out;
	}

	private function audit(array $meta, array $action, string $hostid, array $original_params, array $run): void {
		$line = json_encode([
			'ts' => date('c'),
			'userid' => CWebUser::$data['userid'] ?? null,
			'username' => CWebUser::$data['username'] ?? null,
			'script' => $meta['slug'],
			'action' => $action['id'],
			'hostid' => $hostid !== '' ? $hostid : null,
			'params' => CScriptCatalog::redactForAudit($meta, $original_params),
			'exit_code' => $run['exit_code'],
			'timed_out' => $run['timed_out'],
			'duration_ms' => $run['duration_ms']
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if (!is_dir(self::AUDIT_DIR)) {
			@mkdir(self::AUDIT_DIR, 0750, true);
		}

		@file_put_contents(self::AUDIT_FILE, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
	}

	private function respond(array $payload): void {
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
		]));
	}
}
