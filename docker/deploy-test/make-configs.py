"""Generate per-port tienda wp-config files for the deploy simulation (output is git-ignored: contains prod salts)."""
from pathlib import Path

root = Path(__file__).resolve().parents[2]
src = (root / "docker" / "wp-config.tienda.php").read_text(encoding="utf-8")
for port in (8090, 8091):
    out = root / "docker" / "deploy-test" / f"wp-config.tienda.{port}.php"
    out.write_text(src.replace("localhost:8080", f"localhost:{port}"), encoding="utf-8")
    print("wrote", out.name)
