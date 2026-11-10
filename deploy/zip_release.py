"""Zip a built release folder so that its content unpacks at the archive root."""
import os
import sys
import zipfile


def main(source: str, target: str) -> None:
    count = 0
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
        for folder, _dirs, files in os.walk(source):
            for name in files:
                path = os.path.join(folder, name)
                archive.write(path, os.path.relpath(path, source).replace(os.sep, "/"))
                count += 1
    print(f"{count} files, {os.path.getsize(target) / 1048576:.1f} MB -> {target}")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
