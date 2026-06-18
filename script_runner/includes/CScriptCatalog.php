<?php declare(strict_types = 0);

namespace Modules\ScriptRunner\Includes;

/**
 * Descoberta e validacao do catalogo de scripts.
 *
 * Abstracao central: cada script vive em scripts/<slug>/ com exatamente dois arquivos:
 *   - script.json : metadados + alerta + schema dos campos + acoes (botoes)
 *   - <entrypoint>.py : o script em si (sempre Python)
 * Adicionar um script = criar uma pasta. Nada aqui e codificado por script.
 *
 * Modelo de execucao:
 *   - Cada script declara uma ou mais ACOES (botoes). Cada acao tem titulo, descricao,
 *     quais campos usa (uses) e a lista de argumentos (args) com que sera executada.
 *   - Em "args", cada token literal e passado como esta; cada "{campo}" e substituido
 *     pelo valor do campo como UM unico argumento (sem shell -> sem injecao).
 *   - O script responde JSON no STDOUT: {ok, message|error, details}. Sucesso = ok:true E exit 0.
 *
 * Seguranca:
 *  - O <slug> escolhido pelo usuario e validado contra a lista DESCOBERTA (allowlist
 *    exata) e contra regex; containment realpath (anti path traversal).
 *  - A acao submetida e validada contra a lista declarada no manifest (anti acao forjada).
 *  - Todo parametro e validado contra o schema declarado ANTES de executar.
 *  - Interpretador fixo (python3); nunca vem do manifest.
 */
class CScriptCatalog {

	private const SLUG_REGEX = '/^[a-z0-9_]+$/';
	private const ACTION_ID_REGEX = '/^[a-z0-9_]+$/';
	private const FIELD_NAME_REGEX = '/^[a-z][a-z0-9_]*$/';
	private const MANIFEST_FILE = 'script.json';
	private const ALLOWED_DANGER = ['low', 'medium', 'high'];
	private const ALLOWED_FIELD_TYPES = ['text', 'textarea', 'integer', 'select', 'flag'];
	private const ALLOWED_ALERT_LEVELS = ['info', 'warning', 'danger'];
	private const PLACEHOLDER_REGEX = '/\{([a-z][a-z0-9_]*)\}/';
	private const TIMEOUT_MIN = 1;
	private const TIMEOUT_MAX = 600;
	private const TIMEOUT_DEFAULT = 60;

	/** Interpretador fixo: todo script e Python. */
	public const INTERPRETER = 'python3';

	public static function getScriptsDir(): string {
		return realpath(__DIR__.'/../scripts') ?: (__DIR__.'/../scripts');
	}

	/**
	 * Lista o catalogo completo.
	 *
	 * @return array ['scripts' => [ <client_meta>, ... ], 'errors' => [ ['slug'=>..,'error'=>..], ... ] ]
	 */
	public static function listAll(): array {
		$dir = self::getScriptsDir();
		$scripts = [];
		$errors = [];

		if (!is_dir($dir)) {
			return ['scripts' => [], 'errors' => []];
		}

		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $dir.'/'.$entry;

			if (!is_dir($path)) {
				continue;
			}

			if (!preg_match(self::SLUG_REGEX, $entry)) {
				$errors[] = ['slug' => $entry, 'error' => 'Nome de pasta invalido (use apenas a-z, 0-9, _).'];
				continue;
			}

			try {
				$meta = self::loadMeta($entry);
				$scripts[] = self::toClientMeta($meta);
			}
			catch (\Exception $e) {
				$errors[] = ['slug' => $entry, 'error' => $e->getMessage()];
			}
		}

		usort($scripts, static function (array $a, array $b): int {
			return strcmp($a['name'], $b['name']);
		});

		return ['scripts' => $scripts, 'errors' => $errors];
	}

	/**
	 * Carrega e valida o meta completo de um script (uso interno do servidor).
	 * Lanca \Exception com mensagem em pt-BR se o slug for invalido ou o manifest estiver quebrado.
	 */
	public static function loadMeta(string $slug): array {
		if (!preg_match(self::SLUG_REGEX, $slug)) {
			throw new \Exception('Identificador de script invalido.');
		}

		$base = self::getScriptsDir();
		$dir = realpath($base.'/'.$slug);

		// Anti path traversal: o diretorio resolvido precisa estar dentro de scripts/.
		if ($dir === false || strpos($dir.'/', rtrim($base, '/').'/') !== 0 || !is_dir($dir)) {
			throw new \Exception('Script nao encontrado.');
		}

		$manifest_path = $dir.'/'.self::MANIFEST_FILE;

		if (!is_file($manifest_path)) {
			throw new \Exception('Arquivo '.self::MANIFEST_FILE.' ausente.');
		}

		$raw = file_get_contents($manifest_path);
		$json = json_decode($raw, true);

		if (!is_array($json)) {
			throw new \Exception(self::MANIFEST_FILE.' nao e um JSON valido.');
		}

		return self::normalizeMeta($slug, $dir, $json);
	}

	private static function normalizeMeta(string $slug, string $dir, array $json): array {
		$name = trim((string) ($json['name'] ?? ''));
		if ($name === '') {
			throw new \Exception('Campo "name" obrigatorio no manifest.');
		}

		// Entrypoint descoberto automaticamente: o unico arquivo .py na pasta do script.
		// Nao se declara no manifest -- as acoes ja definem o que executar.
		$entrypoint_path = self::discoverEntrypoint($dir);

		$is_active = array_key_exists('isactive', $json)
			? self::coerceBool($json['isactive'], true)
			: self::coerceBool($json['is_active'] ?? true, true);

		$timeout = (int) ($json['timeout'] ?? self::TIMEOUT_DEFAULT);
		if ($timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX) {
			$timeout = self::TIMEOUT_DEFAULT;
		}

		$fields = self::normalizeFields($json['fields'] ?? []);
		$fields_by_name = [];
		foreach ($fields as $f) {
			$fields_by_name[$f['name']] = $f;
		}

		$actions = self::normalizeActions($json['actions'] ?? [], $fields_by_name);

		return [
			'slug' => $slug,
			'dir' => $dir,
			'name' => $name,
			'summary' => (string) ($json['summary'] ?? $json['description'] ?? ''),
			'alert' => self::normalizeAlert($json['alert'] ?? null),
			'is_active' => $is_active,
			'entrypoint' => $entrypoint_path,
			'entrypoint_name' => basename($entrypoint_path),
			'timeout' => $timeout,
			'fields' => $fields,
			'actions' => $actions
		];
	}

	/**
	 * Descobre o entrypoint Python da pasta do script: deve existir exatamente um arquivo .py.
	 * Falha fechada se houver zero ou mais de um (ambiguo) -- a pasta deve ter so script.json + o .py.
	 */
	private static function discoverEntrypoint(string $dir): string {
		$found = [];
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (substr($entry, -3) === '.py' && is_file($dir.'/'.$entry)) {
				$found[] = $entry;
			}
		}

		if (!$found) {
			throw new \Exception('Nenhum arquivo .py encontrado na pasta do script.');
		}
		if (count($found) > 1) {
			sort($found);
			throw new \Exception('A pasta do script deve ter exatamente um arquivo .py (encontrados: '
				.implode(', ', $found).').');
		}

		$path = realpath($dir.'/'.$found[0]);
		if ($path === false || dirname($path) !== $dir || !is_file($path)) {
			throw new \Exception('Nao foi possivel resolver o entrypoint do script.');
		}

		return $path;
	}

	private static function normalizeAlert($alert): ?array {
		if (!is_array($alert)) {
			return null;
		}

		$level = (string) ($alert['level'] ?? 'info');
		if (!in_array($level, self::ALLOWED_ALERT_LEVELS, true)) {
			$level = 'info';
		}

		$title = trim((string) ($alert['title'] ?? ''));
		$message = trim((string) ($alert['message'] ?? ''));

		if ($title === '' && $message === '') {
			return null;
		}

		return ['level' => $level, 'title' => $title, 'message' => $message];
	}

	private static function normalizeFields($fields): array {
		if (!is_array($fields)) {
			throw new \Exception('Campo "fields" deve ser uma lista.');
		}

		$out = [];
		$seen = [];

		foreach ($fields as $f) {
			if (!is_array($f)) {
				throw new \Exception('Cada item de "fields" deve ser um objeto.');
			}

			$name = (string) ($f['name'] ?? '');
			if (!preg_match(self::FIELD_NAME_REGEX, $name)) {
				throw new \Exception('Nome de campo invalido: "'.$name.'".');
			}
			if (isset($seen[$name])) {
				throw new \Exception('Campo duplicado: "'.$name.'".');
			}
			$seen[$name] = true;

			$type = (string) ($f['type'] ?? 'text');
			if (!in_array($type, self::ALLOWED_FIELD_TYPES, true)) {
				throw new \Exception('Tipo de campo invalido: "'.$type.'".');
			}

			$field = [
				'name' => $name,
				'label' => (string) ($f['label'] ?? $name),
				'type' => $type,
				'help' => (string) ($f['help'] ?? ''),
				'required' => (bool) ($f['required'] ?? false),
				'secret' => (bool) ($f['secret'] ?? false),
				'default' => $f['default'] ?? null,
				'placeholder' => (string) ($f['placeholder'] ?? '')
			];

			if ($type === 'text' || $type === 'textarea') {
				$field['minlength'] = isset($f['minlength']) ? (int) $f['minlength'] : null;
				$field['maxlength'] = isset($f['maxlength']) ? (int) $f['maxlength'] : 1000;
				$field['pattern'] = isset($f['pattern']) ? (string) $f['pattern'] : null;
			}
			elseif ($type === 'integer') {
				$field['min'] = isset($f['min']) ? (int) $f['min'] : null;
				$field['max'] = isset($f['max']) ? (int) $f['max'] : null;
			}
			elseif ($type === 'select') {
				$options = [];
				foreach ((array) ($f['options'] ?? []) as $opt) {
					if (!is_array($opt) || !isset($opt['value'])) {
						throw new \Exception('Opcao invalida no campo "'.$name.'".');
					}
					$options[] = [
						'value' => (string) $opt['value'],
						'label' => (string) ($opt['label'] ?? $opt['value'])
					];
				}
				if (!$options) {
					throw new \Exception('Campo select "'.$name.'" sem opcoes.');
				}
				$field['options'] = $options;
			}
			elseif ($type === 'flag') {
				$switch = isset($f['switch']) ? trim((string) $f['switch']) : '';
				if ($switch !== '' && $switch[0] !== '-') {
					throw new \Exception('O "switch" do campo flag "'.$name.'" deve comecar com "-".');
				}
				$field['switch'] = $switch !== '' ? $switch : null;
			}

			$out[] = $field;
		}

		return $out;
	}

	/**
	 * Normaliza a lista de acoes (botoes). Cada acao referencia campos declarados; toda
	 * referencia invalida faz o script inteiro falhar fechado (nao entra no catalogo).
	 */
	private static function normalizeActions($actions, array $fields_by_name): array {
		if (!is_array($actions) || !$actions) {
			throw new \Exception('O script precisa declarar ao menos uma acao em "actions".');
		}

		$out = [];
		$seen = [];

		foreach ($actions as $i => $a) {
			if (!is_array($a)) {
				throw new \Exception('Cada item de "actions" deve ser um objeto.');
			}

			$title = trim((string) ($a['title'] ?? ''));
			if ($title === '') {
				throw new \Exception('Acao #'.($i + 1).' sem "title".');
			}

			$id = trim((string) ($a['id'] ?? ''));
			if ($id === '') {
				$id = self::slugify($title);
			}
			if (!preg_match(self::ACTION_ID_REGEX, $id)) {
				throw new \Exception('Id de acao invalido: "'.$id.'" (use apenas a-z, 0-9, _).');
			}
			if (isset($seen[$id])) {
				throw new \Exception('Id de acao duplicado: "'.$id.'".');
			}
			$seen[$id] = true;

			$args = $a['args'] ?? [];
			if (!is_array($args)) {
				throw new \Exception('Acao "'.$id.'": "args" deve ser uma lista de strings.');
			}

			// Quais campos sao referenciados em args.
			$referenced = [];
			foreach ($args as $token) {
				if (!is_string($token)) {
					throw new \Exception('Acao "'.$id.'": cada item de "args" deve ser uma string.');
				}
				if (preg_match_all(self::PLACEHOLDER_REGEX, $token, $m)) {
					foreach ($m[1] as $fname) {
						if (!isset($fields_by_name[$fname])) {
							throw new \Exception('Acao "'.$id.'" referencia campo inexistente: "{'.$fname.'}".');
						}
						// Flag referenciada precisa ter "switch" para virar argumento.
						if ($fields_by_name[$fname]['type'] === 'flag' && empty($fields_by_name[$fname]['switch'])) {
							throw new \Exception('Acao "'.$id.'" usa a flag "{'.$fname.'}", mas o campo "'
								.$fname.'" nao define "switch".');
						}
						$referenced[$fname] = true;
					}
				}
			}

			// uses: declarado OU derivado das referencias em args.
			if (array_key_exists('uses', $a)) {
				if (!is_array($a['uses'])) {
					throw new \Exception('Acao "'.$id.'": "uses" deve ser uma lista de nomes de campo.');
				}
				$uses = [];
				foreach ($a['uses'] as $fname) {
					$fname = (string) $fname;
					if (!isset($fields_by_name[$fname])) {
						throw new \Exception('Acao "'.$id.'": "uses" referencia campo inexistente: "'.$fname.'".');
					}
					$uses[$fname] = true;
				}
				// Todo campo referenciado em args precisa estar em uses.
				foreach ($referenced as $fname => $_) {
					if (!isset($uses[$fname])) {
						throw new \Exception('Acao "'.$id.'": campo "{'.$fname.'}" usado em args mas ausente de "uses".');
					}
				}
				$uses = array_keys($uses);
			}
			else {
				$uses = array_keys($referenced);
			}

			$danger = (string) ($a['danger'] ?? 'medium');
			if (!in_array($danger, self::ALLOWED_DANGER, true)) {
				$danger = 'medium';
			}

			$confirm = (bool) ($a['confirm'] ?? false) || $danger === 'high';

			$out[] = [
				'id' => $id,
				'title' => $title,
				'description' => (string) ($a['description'] ?? ''),
				'uses' => $uses,
				'args' => array_values($args),
				'danger' => $danger,
				'confirm' => $confirm
			];
		}

		return $out;
	}

	private static function slugify(string $text): string {
		$text = strtolower($text);
		$text = preg_replace('/[^a-z0-9]+/', '_', $text);
		$text = trim((string) $text, '_');
		return $text !== '' ? $text : 'acao';
	}

	/**
	 * Versao do meta segura para enviar ao cliente (sem caminhos absolutos do servidor).
	 */
	private static function toClientMeta(array $meta): array {
		return array_intersect_key($meta, array_flip([
			'slug', 'name', 'summary', 'alert', 'is_active', 'entrypoint_name', 'timeout', 'fields', 'actions'
		]));
	}

	/**
	 * Localiza uma acao pelo id dentro de um meta carregado.
	 */
	public static function findAction(array $meta, string $action_id): ?array {
		foreach ($meta['actions'] as $action) {
			if ($action['id'] === $action_id) {
				return $action;
			}
		}
		return null;
	}

	/**
	 * Valida os parametros submetidos APENAS para os campos usados por uma acao.
	 *
	 * @return array [ 'values' => [name => valor_coagido], 'errors' => [name => mensagem_pt_BR] ]
	 *               Apenas campos em $action['uses'] sao considerados (whitelist por acao).
	 */
	public static function validateParamsForAction(array $meta, array $action, array $input): array {
		$fields_by_name = [];
		foreach ($meta['fields'] as $field) {
			$fields_by_name[$field['name']] = $field;
		}

		$values = [];
		$errors = [];

		foreach ($action['uses'] as $name) {
			if (!isset($fields_by_name[$name])) {
				continue;
			}
			$field = $fields_by_name[$name];
			$present = array_key_exists($name, $input);
			$raw = $present ? $input[$name] : null;

			if ($field['type'] === 'flag') {
				$values[$name] = self::coerceBool($raw, (bool) ($field['default'] ?? false));
				continue;
			}

			// Vazio / ausente.
			if (!$present || $raw === null || (is_string($raw) && trim($raw) === '')) {
				if ($field['required']) {
					$errors[$name] = 'Campo obrigatorio.';
				}
				else {
					$values[$name] = $field['default'] ?? ($field['type'] === 'integer' ? null : '');
				}
				continue;
			}

			switch ($field['type']) {
				case 'text':
				case 'textarea':
					$v = (string) $raw;
					$len = mb_strlen($v);
					if ($field['minlength'] !== null && $len < $field['minlength']) {
						$errors[$name] = 'Minimo de '.$field['minlength'].' caracteres.';
						break;
					}
					if ($field['maxlength'] !== null && $len > $field['maxlength']) {
						$errors[$name] = 'Maximo de '.$field['maxlength'].' caracteres.';
						break;
					}
					if ($field['pattern'] !== null && @preg_match('/'.str_replace('/', '\/', $field['pattern']).'/u', $v) !== 1) {
						$errors[$name] = 'Formato invalido.';
						break;
					}
					$values[$name] = $v;
					break;

				case 'integer':
					if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw) {
						$errors[$name] = 'Deve ser um numero inteiro.';
						break;
					}
					$v = (int) $raw;
					if ($field['min'] !== null && $v < $field['min']) {
						$errors[$name] = 'Valor minimo: '.$field['min'].'.';
						break;
					}
					if ($field['max'] !== null && $v > $field['max']) {
						$errors[$name] = 'Valor maximo: '.$field['max'].'.';
						break;
					}
					$values[$name] = $v;
					break;

				case 'select':
					$allowed = array_column($field['options'], 'value');
					if (!in_array((string) $raw, $allowed, true)) {
						$errors[$name] = 'Opcao invalida.';
						break;
					}
					$values[$name] = (string) $raw;
					break;
			}
		}

		return ['values' => $values, 'errors' => $errors];
	}

	/**
	 * Monta a lista de argumentos final de uma acao a partir dos valores ja validados.
	 *
	 * Regras (espelhadas no preview do cliente):
	 *  - token literal (sem "{campo}") -> passa como esta (1 argumento).
	 *  - "{campo}" de valor -> valor como 1 argumento; vazio/ausente => token inteiro descartado.
	 *  - "{campo}" de flag -> o "switch" quando marcada; nada (descarta o token) quando desmarcada.
	 *  - Se qualquer "{campo}" no token resolver para vazio, o token inteiro e omitido.
	 */
	public static function buildArgv(array $meta, array $action, array $values): array {
		$fields_by_name = [];
		foreach ($meta['fields'] as $field) {
			$fields_by_name[$field['name']] = $field;
		}

		$argv = [];

		foreach ($action['args'] as $token) {
			if (!preg_match(self::PLACEHOLDER_REGEX, $token)) {
				$argv[] = $token;
				continue;
			}

			$drop = false;
			$rendered = preg_replace_callback(self::PLACEHOLDER_REGEX,
				static function (array $m) use ($fields_by_name, $values, &$drop): string {
					$name = $m[1];
					$field = $fields_by_name[$name] ?? null;
					if ($field === null) {
						$drop = true;
						return '';
					}

					if ($field['type'] === 'flag') {
						$on = !empty($values[$name]);
						if (!$on) {
							$drop = true;
							return '';
						}
						return (string) $field['switch'];
					}

					$val = $values[$name] ?? null;
					if ($val === null || $val === '') {
						$drop = true;
						return '';
					}
					return (string) $val;
				},
				$token
			);

			if (!$drop) {
				$argv[] = $rendered;
			}
		}

		return $argv;
	}

	private static function coerceBool($raw, bool $default): bool {
		if ($raw === null) {
			return $default;
		}
		if (is_bool($raw)) {
			return $raw;
		}
		return in_array((string) $raw, ['1', 'true', 'on', 'yes'], true);
	}

	/**
	 * Mascara campos secretos para auditoria/log.
	 */
	public static function redactForAudit(array $meta, array $values): array {
		$secret = [];
		foreach ($meta['fields'] as $field) {
			if (!empty($field['secret'])) {
				$secret[$field['name']] = true;
			}
		}
		$out = [];
		foreach ($values as $k => $v) {
			$out[$k] = isset($secret[$k]) ? '***' : $v;
		}
		return $out;
	}
}
