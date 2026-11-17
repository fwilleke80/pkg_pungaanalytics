#!/usr/bin/env python3
"""Build the Punga Analytics Joomla package from the expanded source tree."""

from __future__ import annotations

import shutil
import tempfile
import zipfile
from pathlib import Path

VERSION = "0.11.4"
ROOT = Path(__file__).resolve().parent
OUTPUT = ROOT / f"pkg_pungaanalytics-v{VERSION}.zip"

EXTENSIONS: dict[str, str] = {
    "com_pungaanalytics": "com_pungaanalytics.zip",
    "mod_pungaanalytics": "mod_pungaanalytics.zip",
    "plg_system_pungaanalytics": "plg_system_pungaanalytics.zip",
}


def add_tree(archive: zipfile.ZipFile, source: Path) -> None:
    """Add every file below source to archive using source-relative paths."""
    for path in sorted(source.rglob("*")):
        if path.is_file():
            archive.write(path, path.relative_to(source).as_posix())


def make_zip(source: Path, destination: Path) -> None:
    """Create a compressed ZIP containing the contents of source."""
    with zipfile.ZipFile(destination, "w", zipfile.ZIP_DEFLATED) as archive:
        add_tree(archive, source)


def build() -> Path:
    """Build and return the Joomla package installer path."""
    package_source = ROOT / "pkg_pungaanalytics"

    with tempfile.TemporaryDirectory(prefix="pungaanalytics-build-") as temp_name:
        temp = Path(temp_name)
        package_stage = temp / "package"
        shutil.copytree(package_source, package_stage)

        for source_name, archive_name in EXTENSIONS.items():
            make_zip(ROOT / source_name, package_stage / archive_name)

        make_zip(package_stage, OUTPUT)

    return OUTPUT


if __name__ == "__main__":
    print(build())
