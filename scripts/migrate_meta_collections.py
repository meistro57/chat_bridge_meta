# filename: migrate_meta_collections.py
"""
Copy meta-bridge Qdrant collections into Chat Bridge's own bundled Qdrant.

Chat Bridge's app talks to its own `qdrant` container (docker-compose service,
host port 16333) — not the standalone kae-qdrant instance where mb_claims,
mb_chunks, mb_sources, meta_reflections, and misfit_reports actually live.
The app's .env already has MB_QDRANT_COLLECTION_* pointing at these names on
its own instance; this script is what actually gets the data there so those
settings mean something.

Uses qdrant_client's official `migrate()` helper: copies collection config
(vector size/distance/named vectors) + all points in batches, then asserts
point counts match on both ends.

Usage:
    python migrate_meta_collections.py                    # migrate all 5, skip if already done
    python migrate_meta_collections.py --recreate          # wipe + redo all 5 on destination
    python migrate_meta_collections.py --collections mb_claims --recreate
    python migrate_meta_collections.py --batch-size 500

Requires:
    pip install -U qdrant-client
"""

from __future__ import annotations

import argparse
import os
import sys

from qdrant_client import QdrantClient
from qdrant_client.migrate import migrate

SOURCE_URL = os.environ.get("SOURCE_QDRANT_URL", "http://localhost:6333")
DEST_URL = os.environ.get("DEST_QDRANT_URL", "http://localhost:16333")

DEFAULT_COLLECTIONS = [
    "meta_reflections",
    "mb_claims",
    "mb_chunks",
    "mb_sources",
    "misfit_reports",
]


def collection_exists(client: QdrantClient, name: str) -> bool:
    try:
        client.get_collection(name)
        return True
    except Exception:
        return False


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--collections",
        nargs="*",
        default=DEFAULT_COLLECTIONS,
        help=f"Collection names to migrate (default: {DEFAULT_COLLECTIONS})",
    )
    parser.add_argument(
        "--recreate",
        action="store_true",
        help="Drop and fully re-copy any destination collection that already exists "
             "(only affects the destination copy — source is never touched).",
    )
    parser.add_argument("--batch-size", type=int, default=256)
    args = parser.parse_args()

    print(f"Source:      {SOURCE_URL}")
    print(f"Destination: {DEST_URL}")
    print(f"Collections: {args.collections}")
    print()

    source = QdrantClient(url=SOURCE_URL)
    dest = QdrantClient(url=DEST_URL)

    results = []

    for name in args.collections:
        try:
            src_count = source.count(name).count
        except Exception as e:
            print(f"[{name}] SKIP — not found on source ({SOURCE_URL}): {e}")
            results.append((name, "skipped-missing-source", None))
            continue

        exists_on_dest = collection_exists(dest, name)

        if exists_on_dest and not args.recreate:
            dest_count = dest.count(name).count
            if dest_count == src_count:
                print(f"[{name}] already fully migrated ({dest_count} points) — skipping")
                results.append((name, "already-done", dest_count))
                continue
            else:
                print(
                    f"[{name}] EXISTS on destination with {dest_count}/{src_count} points "
                    f"and --recreate was not passed — skipping. Re-run with "
                    f"--collections {name} --recreate to wipe and redo just this one."
                )
                results.append((name, "skipped-partial", dest_count))
                continue

        print(f"[{name}] migrating {src_count} points (batch size {args.batch_size})...")
        try:
            migrate(
                source,
                dest,
                collection_names=[name],
                recreate_on_collision=True,
                batch_size=args.batch_size,
            )
        except Exception as e:
            print(f"[{name}] FAILED: {e}")
            results.append((name, "failed", None))
            continue

        new_count = dest.count(name).count
        print(f"[{name}] done — {new_count} points on destination")
        results.append((name, "migrated", new_count))
        print()

    print("=" * 60)
    print("Summary:")
    for name, status, count in results:
        count_str = f"{count} points" if count is not None else ""
        print(f"  {name:24s} {status:20s} {count_str}")

    return 0 if all(r[1] in ("already-done", "migrated") for r in results) else 1


if __name__ == "__main__":
    sys.exit(main())
