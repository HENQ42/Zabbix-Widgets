# Como escrever um script para o widget Executor de Scripts

Cada script vive em **sua própria pasta** dentro de `scripts/`. O widget descobre as
pastas automaticamente — basta criar a pasta com os dois arquivos abaixo e o script
aparece no catálogo (não é preciso mexer no código do widget).

```
scripts/
└── <slug>/                 # slug: apenas a-z, 0-9 e _  (vira o identificador)
    ├── script.json         # metadados + alerta + campos + ações (botões)  — obrigatório
    └── run.py              # o script em si (sempre Python)                — obrigatório
```

> **Nada além desses dois arquivos é necessário.** O `script.json` descreve a interface
> e como cada botão executa; o `.py` é o programa.
>
> **O entrypoint é descoberto sozinho:** a pasta deve conter **exatamente um** arquivo
> `.py`, e é ele que será executado. Não se declara o nome dele no `script.json` — as
> ações (botões) já definem o que rodar. Ter zero ou mais de um `.py` na pasta faz o
> script ser rejeitado do catálogo. O nome (`run.py`, `main.py`, etc.) é livre.

---

## O arquivo `script.json`

| Campo | Obrigatório | Descrição |
|---|---|---|
| `name` | sim | Nome curto exibido na lista de scripts. |
| `summary` | não | Descrição breve exibida no card do catálogo e abaixo do título. |
| `alert` | não | Alerta exibido ao **selecionar** o script (explica o que ele faz). Ver abaixo. |
| `isactive` | não | `true` (default) ou `false`. Quando `false`, o script aparece no catálogo **marcado como "Desativado"**, não pode ser selecionado nem executado, e entra na contagem de desativados. Útil para tirar um script de circulação sem apagar a pasta. |
| `timeout` | não | Segundos (1 a 600, default 60). Processo travado é interrompido. |
| `fields` | não | Lista de campos do formulário (ver abaixo). |
| `actions` | **sim** | Lista de ações (botões). Pelo menos uma. Ver abaixo. |

O interpretador é sempre `python3`, e o entrypoint é o único `.py` da pasta — nada disso
se declara no `script.json`.

### `alert` (mostrado ao selecionar o script)

```json
"alert": {
    "level": "info | warning | danger",
    "title": "O que este script faz",
    "message": "Explique aqui o efeito do script e os riscos, em linguagem clara."
}
```

### `fields[]` (campos de dados)

Comuns a todos: `name` (a-z, 0-9, _), `label`, `help`, `required`, `default`, `secret`.

| `type` | Restrições adicionais |
|---|---|
| `text` | `minlength`, `maxlength` (default 1000), `pattern` (regex), `placeholder` |
| `textarea` | iguais a `text` |
| `integer` | `min`, `max` |
| `select` | `options: [{ "value": "..", "label": ".." }]` (obrigatório) |
| `flag` | booleano (checkbox). Use `switch` para definir o argumento emitido (ex.: `"--verbose"`) |

> Campos `"required": true` são exigidos apenas pelas **ações que os usam** (ver `uses`).
> Campos `"secret": true` aparecem mascarados na interface e na auditoria — ainda assim,
> prefira não pedir credenciais aqui: use o painel de **macros do host** (abaixo).

### `actions[]` (os botões)

Cada ação é um botão com seu próprio comando. Estrutura:

| Campo | Obrigatório | Descrição |
|---|---|---|
| `title` | sim | Texto do botão / título da ação. |
| `id` | não | Identificador (a-z, 0-9, _). Se omitido, é derivado do título. |
| `description` | não | O que a ação faz (exibido no card da ação). |
| `uses` | não | Lista de nomes de campos que a ação usa. Se omitido, é derivado dos `args`. |
| `args` | sim | Lista de argumentos passados ao script (ver regras abaixo). |
| `danger` | não | `low`, `medium` ou `high` (default `medium`). Colore o botão. |
| `confirm` | não | `true` exige confirmação. Sempre `true` quando `danger: high`. |

#### Regras de `args` (como o comando é montado)

Os argumentos **nunca** passam por um shell. Cada item da lista vira **um argumento
isolado** — então valores com espaços, aspas ou caracteres especiais são seguros.

- **Texto literal** (sem `{}`) → passado como está. Ex.: `"--dry-run"`.
- **`{campo}` de valor** (text/textarea/integer/select) → o valor como **um** argumento.
  Se o campo for **opcional e estiver vazio**, o token inteiro é **descartado**.
- **`{campo}` de flag** → emite o `switch` do campo quando marcada; **nada** quando desmarcada.

Exemplos:

```json
"fields": [
    { "name": "alvo",    "label": "Alvo",      "type": "text", "required": true },
    { "name": "limite",  "label": "Limite",    "type": "integer" },
    { "name": "verbose", "label": "Detalhado", "type": "flag", "switch": "--verbose" }
],
"actions": [
    {
        "title": "Roda teste seco",
        "description": "Roda como se fosse real, mas sem alterar nada.",
        "args": ["--alvo={alvo}", "--limite={limite}", "{verbose}", "--dry-run"],
        "danger": "low"
    },
    {
        "title": "Executa de verdade",
        "description": "Aplica as alterações nas máquinas.",
        "args": ["--alvo={alvo}", "--limite={limite}", "{verbose}"],
        "danger": "high"
    }
]
```

- Com `alvo="srv-01"`, `limite` vazio e `verbose` marcado, a 1ª ação roda:
  `run.py --alvo=srv-01 --verbose --dry-run` (o `--limite=` some por estar vazio).
- Dica: use a forma `--opcao={campo}` (um único token) para que a opção **toda**
  desapareça quando o campo opcional estiver vazio.

---

## Macros do host (`{$MACRO}`)

O widget tem um painel **Macros do host**, independente dos scripts. Você seleciona um
host, vê as macros dele (secretas aparecem como `*****`) e pode inserir `{$NOME}` em
qualquer campo de texto. Na execução, o widget substitui `{$NOME}` pelo **valor real**
da macro **no servidor** (segredos são lidos com segurança e nunca trafegam ao navegador
nem são gravados resolvidos na auditoria).

Para usar: selecione o host no painel, clique em **inserir** na macro desejada (ela vai
para o último campo de texto focado) e execute. Se um campo contém `{$...}` e nenhum host
está selecionado, a execução é bloqueada com um aviso.

---

## Contrato de execução

O script recebe os dados como **argumentos de linha de comando** (use `argparse`).

O script **deve** responder no **STDOUT** um **JSON**:

```json
// sucesso
{ "ok": true,  "message": "Texto para o usuario", "details": { "qualquer": "coisa" } }
// falha
{ "ok": false, "error": "O que deu errado", "details": { "qualquer": "coisa" } }
```

E usar o **exit code**: `0` = sucesso, diferente de `0` = falha. Mensagens de
diagnóstico podem ir para o **STDERR** (capturado e exibido em "Detalhes técnicos").

**Sucesso só é considerado quando `ok: true` E exit code `0`.** Qualquer saída fora desse
formato é tratada como erro de contrato.

Esqueleto mínimo:

```python
#!/usr/bin/env python3
import sys, json, argparse

def emit(payload, code):
    sys.stdout.write(json.dumps(payload, ensure_ascii=False))
    sys.exit(code)

p = argparse.ArgumentParser(add_help=False)
p.add_argument("--alvo", required=True)
p.add_argument("--dry-run", action="store_true")
args = p.parse_args()

emit({"ok": True, "message": "Feito.", "details": {"alvo": args.alvo}}, 0)
```

---

## Segurança (resumo)

- Acesso restrito: Super Admin **e** membro do grupo de usuários autorizado, revalidado
  no servidor em toda requisição.
- Execução **sem shell**: `proc_open` com array de argumentos; cada valor vira um argumento
  discreto — nunca interpolado em linha de comando.
- `slug` e `id` da ação validados contra o que foi descoberto/declarado (anti path
  traversal e anti ação forjada). Manifests com referências inválidas são ignorados.
- Parâmetros validados contra o schema **antes** de executar; timeout por script.
- Toda execução é auditada em `/var/lib/zabbix-ui/script_runner/audit.log` (valores
  originais, com `{$X}` literal e campos secretos mascarados).

Veja `echo_diagnostico/` como exemplo completo e inofensivo.
