#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Echo de Diagnostico — script de teste do widget Executor de Scripts.

Contrato (ver scripts/README.md):
  - Recebe os dados como ARGUMENTOS de linha de comando (argv). Cada "{campo}" do
    script.json e entregue como UM argumento isolado (sem shell).
  - Responde no STDOUT um JSON:
        sucesso: {"ok": true,  "message": "...", "details": {...}}
        falha:   {"ok": false, "error": "...",   "details": {...}}
  - Exit code: 0 = sucesso; diferente de 0 = falha.

Este script NAO acessa nenhuma maquina; apenas ecoa os parametros recebidos.
"""

import sys
import json
import time
import argparse


def emit(payload, code):
    sys.stdout.write(json.dumps(payload, ensure_ascii=False))
    sys.stdout.flush()
    sys.exit(code)


def main():
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--mensagem", default="")
    parser.add_argument("--nivel", default="info")
    parser.add_argument("--repeticoes", type=int, default=1)
    parser.add_argument("--falhar", action="store_true")
    parser.add_argument("--travar", action="store_true")

    try:
        args = parser.parse_args()
    except SystemExit:
        # argparse aborta com exit 2 em erro de parsing; traduz para o contrato.
        emit({"ok": False, "error": "Argumentos invalidos recebidos pelo script."}, 1)

    if args.travar:
        # Trava de proposito; o widget deve interromper por timeout.
        time.sleep(600)

    if args.falhar:
        # Demonstra o caminho de erro: stderr + exit 1.
        sys.stderr.write("Falha simulada a pedido do usuario (--falhar).\n")
        emit({
            "ok": False,
            "error": "Falha simulada com sucesso. Este e um erro proposital para teste.",
            "details": {
                "nivel": args.nivel,
                "mensagem_recebida": args.mensagem
            }
        }, 1)

    repeticoes = max(1, args.repeticoes)
    eco = " | ".join([args.mensagem] * repeticoes)

    emit({
        "ok": True,
        "message": "Script executado com sucesso. A mensagem foi ecoada %d vez(es)." % repeticoes,
        "details": {
            "nivel": args.nivel,
            "repeticoes": repeticoes,
            "eco": eco,
            "tamanho_mensagem": len(args.mensagem)
        }
    }, 0)


if __name__ == "__main__":
    main()
