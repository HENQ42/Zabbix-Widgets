<?php declare(strict_types = 0);

namespace Modules\ScriptRunner\Includes;

use API;

/**
 * Leitura e resolucao de macros de usuario de um host.
 *
 * Duas responsabilidades:
 *  - list($hostid): para o painel "Macros do host" do widget. Mascara macros secretas
 *    (type 1) e de Vault (type 2) como "*****".
 *  - resolveValues($hostid, $values): substitui referencias "{$MACRO}" digitadas nos campos
 *    pelo valor real, SEMPRE no servidor, no momento da execucao.
 *
 * Seguranca:
 *  - O acesso ao host e confirmado via API (respeita as permissoes do usuario) antes de
 *    qualquer leitura. O widget ja restringe o uso a Super Admin + grupo autorizado.
 *  - O valor real de macros secretas nao vem pela API; e lido direto da tabela hostmacro
 *    via DBselect (padrao conhecido deste projeto). Isso so ocorre apos a checagem de acesso.
 *  - O cliente nunca recebe o valor real de macros secretas; a resolucao acontece aqui e o
 *    valor resolvido nunca e devolvido ao cliente nem registrado na auditoria.
 */
class CHostMacros {

	/** Casa "{$NOME}" e "{$NOME:contexto}". */
	private const MACRO_REF_REGEX = '/\{\$[A-Z0-9_\.]+(?::[^}]*)?\}/';

	private const MASK = '*****';

	/**
	 * True se a string contem ao menos uma referencia de macro de usuario.
	 */
	public static function containsMacroRef(string $text): bool {
		return (bool) preg_match(self::MACRO_REF_REGEX, $text);
	}

	/**
	 * Lista as macros efetivas de um host para exibicao no painel.
	 *
	 * @throws \Exception se o host nao existir ou o usuario nao tiver acesso.
	 * @return array ['host' => <nome>, 'macros' => [ ['macro'=>..,'value'=>..,'secret'=>bool,'source'=>..], ... ]]
	 */
	public static function listForHost($hostid): array {
		$host = self::assertHostAccess($hostid);

		$map = self::buildEffectiveMacros((int) $hostid);

		$macros = [];
		foreach ($map as $name => $info) {
			$is_masked = ($info['type'] === ZBX_MACRO_TYPE_SECRET || $info['type'] === ZBX_MACRO_TYPE_VAULT);
			$macros[] = [
				'macro' => $name,
				'value' => $is_masked ? self::MASK : $info['value'],
				'secret' => $is_masked,
				'source' => $info['source']
			];
		}

		usort($macros, static function (array $a, array $b): int {
			return strcmp($a['macro'], $b['macro']);
		});

		return ['host' => $host['name'], 'macros' => $macros];
	}

	/**
	 * Resolve "{$MACRO}" nos valores de campos contra as macros do host.
	 * Aplica-se apenas a valores string; demais tipos passam inalterados.
	 *
	 * @return array [
	 *     'values' => [name => valor_resolvido],
	 *     'errors' => [name => 'mensagem pt-BR'] // macro referenciada inexistente
	 * ]
	 * @throws \Exception se o host nao existir ou o usuario nao tiver acesso.
	 */
	public static function resolveValues($hostid, array $values): array {
		self::assertHostAccess($hostid);
		$map = self::buildEffectiveMacros((int) $hostid);

		$out = [];
		$errors = [];

		foreach ($values as $name => $val) {
			if (!is_string($val) || !self::containsMacroRef($val)) {
				$out[$name] = $val;
				continue;
			}

			$missing = [];
			$resolved = preg_replace_callback(self::MACRO_REF_REGEX,
				static function (array $m) use ($map, &$missing): string {
					$token = $m[0];
					if (array_key_exists($token, $map)) {
						return (string) $map[$token]['value'];
					}
					$missing[] = $token;
					return $token;
				},
				$val
			);

			if ($missing) {
				$errors[$name] = 'Macro(s) nao encontrada(s) neste host: '.implode(', ', array_unique($missing)).'.';
			}
			else {
				$out[$name] = $resolved;
			}
		}

		return ['values' => $out, 'errors' => $errors];
	}

	/**
	 * Confirma que o host existe e e visivel para o usuario atual.
	 *
	 * @throws \Exception
	 * @return array ['hostid'=>.., 'name'=>..]
	 */
	private static function assertHostAccess($hostid): array {
		if (!is_numeric($hostid) || (int) $hostid <= 0) {
			throw new \Exception('Host invalido.');
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => [(int) $hostid]
		]);

		if (!$hosts) {
			throw new \Exception('Host nao encontrado ou sem acesso.');
		}

		return $hosts[0];
	}

	/**
	 * Monta o mapa efetivo de macros do host, com precedencia host > template (mais proximo)
	 * > global. O valor real (inclusive de macros secretas) e lido direto do banco.
	 *
	 * @return array [ '{$NOME}' => ['value'=>str, 'type'=>int, 'source'=>'host|template|global'], ... ]
	 */
	private static function buildEffectiveMacros(int $hostid): array {
		$map = [];

		// Niveis na ordem de precedencia: host primeiro, depois ancestrais de template (BFS).
		$levels = [['ids' => [$hostid], 'source' => 'host']];

		$current = [$hostid];
		$visited = [$hostid => true];
		$guard = 0;

		while ($current && $guard < 50) {
			$guard++;
			$parents = [];
			$res = DBselect(
				'SELECT templateid FROM hosts_templates WHERE '.dbConditionInt('hostid', $current)
			);
			while ($row = DBfetch($res)) {
				$tid = (int) $row['templateid'];
				if (!isset($visited[$tid])) {
					$visited[$tid] = true;
					$parents[] = $tid;
				}
			}
			if ($parents) {
				$levels[] = ['ids' => $parents, 'source' => 'template'];
			}
			$current = $parents;
		}

		// Macros de host/template (tabela hostmacro), nivel a nivel (precedencia: primeiro vence).
		foreach ($levels as $level) {
			$res = DBselect(
				'SELECT macro,value,type FROM hostmacro WHERE '.dbConditionInt('hostid', $level['ids'])
			);
			while ($row = DBfetch($res)) {
				$name = (string) $row['macro'];
				if (!array_key_exists($name, $map)) {
					$map[$name] = [
						'value' => (string) $row['value'],
						'type' => (int) $row['type'],
						'source' => $level['source']
					];
				}
			}
		}

		// Macros globais por ultimo (menor precedencia).
		$res = DBselect('SELECT macro,value,type FROM globalmacro');
		while ($row = DBfetch($res)) {
			$name = (string) $row['macro'];
			if (!array_key_exists($name, $map)) {
				$map[$name] = [
					'value' => (string) $row['value'],
					'type' => (int) $row['type'],
					'source' => 'global'
				];
			}
		}

		return $map;
	}
}
