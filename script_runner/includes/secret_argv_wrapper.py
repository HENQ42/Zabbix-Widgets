#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Runs a catalog script while keeping Zabbix secret macro values out of OS argv.

PHP starts this wrapper with placeholder arguments and sends a JSON map on stdin:
{"__ZBX_SR_SECRET_0__": "real-secret"}. The wrapper reconstructs sys.argv in
memory, then executes the target script as __main__.
"""

import json
import os
import runpy
import sys


def main():
    if len(sys.argv) < 2:
        sys.stderr.write("Entrypoint ausente.\n")
        sys.exit(2)

    entrypoint = sys.argv[1]
    argv = sys.argv[2:]

    try:
        raw = sys.stdin.read()
        replacements = json.loads(raw) if raw.strip() else {}
    except Exception:
        sys.stderr.write("Mapa de segredos invalido.\n")
        sys.exit(2)

    if not isinstance(replacements, dict):
        sys.stderr.write("Mapa de segredos invalido.\n")
        sys.exit(2)

    def restore(token):
        for placeholder, value in replacements.items():
            token = token.replace(str(placeholder), str(value))
        return token

    script_dir = os.path.dirname(os.path.abspath(entrypoint))
    if script_dir and script_dir not in sys.path:
        sys.path.insert(0, script_dir)

    sys.argv = [entrypoint] + [restore(token) for token in argv]
    runpy.run_path(entrypoint, run_name="__main__")


if __name__ == "__main__":
    main()
