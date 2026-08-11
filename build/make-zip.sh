#!/usr/bin/env bash
#
# Copyright (C) 2026 Facty — GPLv3, see LICENSE.
#
# Genera el ZIP instalable con "Desplegar módulo externo" de Dolibarr.
#
# Dolibarr espera que el ZIP contenga el directorio del módulo en la raíz
# (factymx/...), NO los archivos sueltos: si se comprime el contenido en vez de
# la carpeta, el despliegue "funciona" y deja los archivos en el lugar
# equivocado, con un módulo que no aparece por ningún lado y ninguna pista de
# por qué.

set -euo pipefail

cd "$(dirname "$0")/.."

MODULE="factymx"
VERSION="$(grep -oE "version = '[^']+'" "$MODULE/core/modules/modFactyMX.class.php" | head -1 | cut -d"'" -f2)"

if [ -z "$VERSION" ]; then
  echo "No se pudo leer la versión del descriptor." >&2
  exit 1
fi

OUT="dist/${MODULE}-${VERSION}.zip"
mkdir -p dist
rm -f "$OUT"

# Antes de empaquetar, las mismas comprobaciones que hace CI. Publicar un ZIP
# con un error de sintaxis es peor que no publicarlo: el cliente lo instala, se
# le rompe Dolibarr y la confianza se pierde ahí.
echo "Comprobando sintaxis…"
find "$MODULE" -name '*.php' -print0 | xargs -0 -n1 -P4 php -l > /dev/null

echo "Comprobando que no se empaquete nada que no deba…"
if find "$MODULE" \( -name '*.log' -o -name '.env*' -o -name '*.key' -o -name '*.cer' \) -print | grep -q .; then
  echo "Hay archivos que no deben distribuirse dentro de $MODULE." >&2
  exit 1
fi

zip -r -q "$OUT" "$MODULE" \
  -x '*.DS_Store' \
  -x '*/.git/*' \
  -x '*.part'

echo "Listo: $OUT"
unzip -l "$OUT" | tail -3
