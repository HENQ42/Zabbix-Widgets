<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Includes;

use API;

/**
 * Armazenamento persistente das predefinições (presets) de "tipos de site" do widget.
 *
 * As predefinições vivem em uma tabela própria (hggrid_auto_preset), FORA do widget_field — por isso
 * sobrevivem a apagar o widget ou o dashboard inteiro. Cada predefinição pertence a um grupo de usuários
 * (usrgrpid): só quem é membro desse grupo pode vê-la, carregá-la, salvá-la ou apagá-la. Super Admin
 * enxerga e edita todas (escape hatch). A barreira de permissão é centralizada aqui — nenhuma ação
 * acessa a tabela diretamente.
 *
 * Backend: PostgreSQL (CREATE TABLE IF NOT EXISTS). A chave primária composta (usrgrpid, name) dispensa
 * auto-increment/SERIAL. O save() ainda trata o NOME como único no escopo editável do usuário — salvar o
 * mesmo nome em outro grupo substitui/move a predefinição em vez de duplicá-la.
 */
class PresetStore {

	public const TABLE = 'hggrid_auto_preset';

	/**
	 * Cria a tabela sob demanda. Idempotente — seguro chamar a cada operação. CREATE TABLE IF NOT EXISTS
	 * é DDL no PostgreSQL e não falha se a tabela já existir.
	 */
	public static function ensureTable(): void {
		DBexecute(
			'CREATE TABLE IF NOT EXISTS '.self::TABLE.' ('.
				'usrgrpid bigint NOT NULL,'.
				'name varchar(255) NOT NULL,'.
				'data text NOT NULL,'.
				'PRIMARY KEY (usrgrpid, name)'.
			')'
		);
	}

	/**
	 * Lista as predefinições visíveis ao usuário: apenas as cujo grupo dono está entre os grupos do
	 * usuário. Super Admin recebe todas. Não retorna o campo data (payload pesado) — só os metadados
	 * para montar o seletor.
	 *
	 * @param array $usrgrpids  ids dos grupos do usuário atual
	 * @param bool  $is_super   se true, ignora o filtro de grupo e retorna tudo
	 *
	 * @return array<int, array{usrgrpid: string, name: string}>
	 */
	public static function listForUser(array $usrgrpids, bool $is_super): array {
		self::ensureTable();

		$where = '';
		if (!$is_super) {
			if (!$usrgrpids) {
				return [];
			}
			$where = ' WHERE '.dbConditionInt('usrgrpid', $usrgrpids);
		}

		$rows = [];
		$result = DBselect('SELECT usrgrpid,name FROM '.self::TABLE.$where.' ORDER BY name ASC');
		while ($row = DBfetch($result)) {
			$rows[] = [
				'usrgrpid' => (string) $row['usrgrpid'],
				'name' => (string) $row['name']
			];
		}

		return $rows;
	}

	/**
	 * Carrega uma predefinição. Retorna null se não existir OU se o usuário não puder acessá-la
	 * (não é membro do grupo dono e não é Super Admin) — indistinguível de propósito, não vaza
	 * existência de presets de outros grupos.
	 *
	 * @return array{usrgrpid: string, name: string, data: mixed}|null
	 */
	public static function load(int $usrgrpid, string $name, array $usrgrpids, bool $is_super): ?array {
		self::ensureTable();

		if (!$is_super && !in_array($usrgrpid, array_map('intval', $usrgrpids), true)) {
			return null;
		}

		$result = DBselect(
			'SELECT usrgrpid,name,data FROM '.self::TABLE.
			' WHERE usrgrpid='.$usrgrpid.' AND name='.zbx_dbstr($name)
		);
		$row = DBfetch($result);
		if (!$row) {
			return null;
		}

		return [
			'usrgrpid' => (string) $row['usrgrpid'],
			'name' => (string) $row['name'],
			'data' => json_decode((string) $row['data'], true)
		];
	}

	/**
	 * Cria ou sobrescreve (editar) uma predefinição. O NOME é o identificador único dentro do escopo que
	 * o usuário pode editar: antes de gravar, removemos qualquer predefinição de mesmo nome nos grupos
	 * acessíveis (todos, se Super Admin) e então inserimos no grupo dono escolhido. Assim, salvar o mesmo
	 * nome com outro grupo SUBSTITUI a predefinição (efetivamente a "move") em vez de duplicá-la. Recusa
	 * se o usuário não for membro do grupo dono escolhido (e não for Super Admin) — não deixa gravar em
	 * grupo do qual não participa.
	 *
	 * @return bool  true se gravou; false se o usuário não pode escrever nesse grupo
	 */
	public static function save(int $usrgrpid, string $name, $data, array $usrgrpids, bool $is_super): bool {
		self::ensureTable();

		if (!$is_super && !in_array($usrgrpid, array_map('intval', $usrgrpids), true)) {
			return false;
		}

		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		// Remove predefinições homônimas no escopo editável (em qualquer grupo), garantindo um único
		// registro por nome. Super Admin alcança todos os grupos; demais, apenas os seus.
		$scope = '';
		if (!$is_super) {
			$scope = ' AND '.dbConditionInt('usrgrpid', array_map('intval', $usrgrpids));
		}
		DBexecute('DELETE FROM '.self::TABLE.' WHERE name='.zbx_dbstr($name).$scope);

		DBexecute(
			'INSERT INTO '.self::TABLE.' (usrgrpid,name,data) VALUES ('.
				$usrgrpid.','.zbx_dbstr($name).','.zbx_dbstr($json).
			')'
		);

		return true;
	}

	/**
	 * Apaga uma predefinição. Mesma barreira de grupo do save. Retorna false se o usuário não pode
	 * mexer nesse grupo; true mesmo que nada tenha sido apagado (preset inexistente) — a ausência não
	 * é um erro de permissão.
	 */
	public static function delete(int $usrgrpid, string $name, array $usrgrpids, bool $is_super): bool {
		self::ensureTable();

		if (!$is_super && !in_array($usrgrpid, array_map('intval', $usrgrpids), true)) {
			return false;
		}

		DBexecute(
			'DELETE FROM '.self::TABLE.
			' WHERE usrgrpid='.$usrgrpid.' AND name='.zbx_dbstr($name)
		);

		return true;
	}

	/**
	 * Resolve os grupos de usuário (usrgrpid => name) do usuário atual. Usado para: (1) filtrar a
	 * listagem; (2) montar o seletor de "grupo dono" ao salvar (só os grupos do próprio usuário).
	 * Desacoplado das tabelas internas — consulta via API::User().
	 *
	 * @return array<string, string>  usrgrpid => nome do grupo
	 */
	public static function userUsrgrps(int $userid): array {
		$users = API::User()->get([
			'output' => ['userid'],
			'userids' => [$userid],
			'selectUsrgrps' => ['usrgrpid', 'name']
		]);

		if (!$users) {
			return [];
		}

		$out = [];
		foreach (($users[0]['usrgrps'] ?? []) as $grp) {
			$out[(string) $grp['usrgrpid']] = (string) $grp['name'];
		}

		return $out;
	}
}
