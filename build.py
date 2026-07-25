#!/usr/bin/env python3

"""Build the Punga Analytics Joomla package and source archive."""

from __future__ import annotations

import hashlib
import shutil
import zipfile
from pathlib import Path

VERSION = "0.7.5"
ROOT = Path(__file__).resolve().parent
OUTPUT = ROOT.parent
BUILD = ROOT / ".build"
EXCLUDED_PARTS = {".build", ".git", "__MACOSX", "__pycache__"}


def add_tree(archive: zipfile.ZipFile, source: Path) -> None:
    """Add a directory tree with paths relative to that directory."""

    for path in sorted(source.rglob("*")):
        relative = path.relative_to(source)

        if (
            path.is_file()
            and not any(part in EXCLUDED_PARTS for part in relative.parts)
            and path.name != ".DS_Store"
            and path.suffix != ".pyc"
        ):
            archive.write(path, relative.as_posix())


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
    component_zip = BUILD / "com_pungaanalytics.zip"
    module_zip = BUILD / "mod_pungaanalytics.zip"
    plugin_zip = BUILD / "plg_system_pungaanalytics.zip"
    create_zip(ROOT / "com_pungaanalytics", component_zip)
    create_zip(ROOT / "mod_pungaanalytics", module_zip)
    create_zip(ROOT / "plg_system_pungaanalytics", plugin_zip)

    package_stage = BUILD / "package"
    shutil.copytree(ROOT / "package", package_stage)
    shutil.copy2(component_zip, package_stage / component_zip.name)
    shutil.copy2(module_zip, package_stage / module_zip.name)
    shutil.copy2(plugin_zip, package_stage / plugin_zip.name)

    package_output = OUTPUT / f"pkg_pungaanalytics-{VERSION}.zip"
    source_output = OUTPUT / f"pkg_pungaanalytics-{VERSION}-source.zip"
    create_zip(package_stage, package_output)

    with zipfile.ZipFile(source_output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(ROOT.rglob("*")):
            if (
                not path.is_file()
                or any(part in EXCLUDED_PARTS for part in path.parts)
                or path.name == ".DS_Store"
                or path.suffix == ".pyc"
            ):
                continue

            archive.write(path, (Path(f"pkg_pungaanalytics-{VERSION}") / path.relative_to(ROOT)).as_posix())

    checksum_output = OUTPUT / f"pkg_pungaanalytics-{VERSION}.sha256.txt"
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
