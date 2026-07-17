#!/usr/bin/env python3

"""Build the Simple Stats Joomla package and source archive."""

from __future__ import annotations

import hashlib
import shutil
import zipfile
from pathlib import Path

VERSION = "0.2.1"
ROOT = Path(__file__).resolve().parent
OUTPUT = ROOT.parent
BUILD = ROOT / ".build"


def add_tree(archive: zipfile.ZipFile, source: Path) -> None:
    """Add a directory tree with paths relative to that directory."""

    for path in sorted(source.rglob("*")):
        if path.is_file():
            archive.write(path, path.relative_to(source).as_posix())


def create_zip(source: Path, destination: Path) -> None:
    """Create a deterministic-enough ZIP from a source directory."""

    with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        add_tree(archive, source)


def sha256(path: Path) -> str:
    """Return the SHA-256 digest for a file."""

    digest = hashlib.sha256()

    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)

    return digest.hexdigest()


def main() -> None:
    """Build nested extension archives, installable package, source archive, and checksums."""

    if BUILD.exists():
        shutil.rmtree(BUILD)

    BUILD.mkdir()
    component_zip = BUILD / "com_simplestats.zip"
    plugin_zip = BUILD / "plg_system_simplestats.zip"
    create_zip(ROOT / "com_simplestats", component_zip)
    create_zip(ROOT / "plg_system_simplestats", plugin_zip)

    package_stage = BUILD / "package"
    shutil.copytree(ROOT / "package", package_stage)
    shutil.copy2(component_zip, package_stage / component_zip.name)
    shutil.copy2(plugin_zip, package_stage / plugin_zip.name)

    package_output = OUTPUT / f"pkg_simplestats-{VERSION}.zip"
    source_output = OUTPUT / f"pkg_simplestats-{VERSION}-source.zip"
    create_zip(package_stage, package_output)

    with zipfile.ZipFile(source_output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(ROOT.rglob("*")):
            if not path.is_file() or ".build" in path.parts:
                continue

            archive.write(path, (Path(f"pkg_simplestats-{VERSION}") / path.relative_to(ROOT)).as_posix())

    checksum_output = OUTPUT / f"pkg_simplestats-{VERSION}.sha256.txt"
    checksum_output.write_text(
        f"{sha256(package_output)}  {package_output.name}\n"
        f"{sha256(source_output)}  {source_output.name}\n",
        encoding="utf-8",
    )

    print(package_output)
    print(source_output)
    print(checksum_output)


if __name__ == "__main__":
    main()
