#!/usr/bin/env bash
# Raise host nginx upload limit for VAS Partners (fixes HTTP 413).
# Usage: sudo ./fix-host-nginx-body-size.sh [SIZE]
# Default SIZE=50M
set -euo pipefail
CONF=/etc/nginx/conf.d/vaspartners.conf
SIZE="${1:-50M}"

if [[ ! -f "$CONF" ]]; then
  echo "Missing $CONF" >&2
  echo "Install from: vaspartners/docker/nginx/host-vaspartners.conf.example" >&2
  exit 1
fi

python3 - "$CONF" "$SIZE" <<'PY'
import re, sys
path, size = sys.argv[1], sys.argv[2]
text = open(path).read()
if "client_max_body_size" in text:
    text = re.sub(r"client_max_body_size\s+\S+;", f"client_max_body_size {size};", text)
else:
    needle = '    add_header X-Content-Type-Options "nosniff";\n'
    if needle not in text:
        raise SystemExit("Could not find insertion point in nginx conf")
    text = text.replace(needle, needle + f"    client_max_body_size {size};\n", 1)
open(path, "w").write(text)
print(f"Set client_max_body_size {size} in {path}")
PY

nginx -t
systemctl reload nginx
echo "Host nginx reloaded with client_max_body_size ${SIZE}"
