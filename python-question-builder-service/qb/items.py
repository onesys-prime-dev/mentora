"""Digest several selected learning items, each on its own.

Every item is digested and cached by the hash of its own text, so ticking or
unticking one item reads only that item: the others come straight from the
cache. What a question cites is the item's title and the page in that item.

The combined concept list is interleaved across items (item 1 concept 1,
item 2 concept 1, ...), so a small question count still touches every
selected item instead of using up the first one."""

import copy
import math
from dataclasses import dataclass, field
from itertools import zip_longest

from . import ingest as ingest_mod
from .digest import PayloadDigestStore, build_digest, source_hash
from .errors import SourceUnreadable
from .pool import ModelPool

# Every selected item gets at least this many facts before the rest is shared out.
MIN_FACTS_PER_ITEM = 3


@dataclass
class ItemStat:
    id: int | None
    title: str
    facts: int = 0
    calls: int = 0
    tokens_in: int = 0
    tokens_out: int = 0
    cached: bool = False


@dataclass
class ItemsDigest:
    concepts: list[dict] = field(default_factory=list)
    facts: dict[str, dict] = field(default_factory=dict)
    digests: dict[str, dict] = field(default_factory=dict)  # new or updated only, by source hash
    warnings: list[str] = field(default_factory=list)
    stats: list[ItemStat] = field(default_factory=list)

    @property
    def calls(self) -> int:
        return sum(s.calls for s in self.stats)

    @property
    def tokens_in(self) -> int:
        return sum(s.tokens_in for s in self.stats)

    @property
    def tokens_out(self) -> int:
        return sum(s.tokens_out for s in self.stats)


def _label(item: dict, index: int) -> str:
    return str(item.get("title") or f"Item {index + 1}").strip()


def digest_items(
    items: list[dict],
    cached: dict[str, dict],
    pool: ModelPool,
    *,
    needed_facts: int,
    rotation: int = 0,
    on_progress=None,
) -> ItemsDigest:
    out = ItemsDigest()
    runs: list[dict] = []
    share = max(MIN_FACTS_PER_ITEM, math.ceil(needed_facts / max(1, len(items))))

    for index, item in enumerate(items):
        title = _label(item, index)
        stat = ItemStat(item.get("id"), title)
        out.stats.append(stat)

        if on_progress:
            on_progress(index, len(items), title)

        text = str(item.get("text") or "")

        try:
            pages = ingest_mod.split_marked(text) or ingest_mod.ingest_text(text).pages
        except SourceUnreadable:
            out.warnings.append(f"'{title}' has no text to read.")
            continue

        key = source_hash(pages)
        had_cache = isinstance(cached.get(key), dict)
        store = PayloadDigestStore(cached.get(key))
        digest = build_digest(pages, pool, store, needed_facts=share, rotation=rotation)

        stat.calls += digest.calls
        stat.tokens_in += digest.tokens_in
        stat.tokens_out += digest.tokens_out
        out.warnings.extend(f"'{title}': {w}" for w in digest.warnings)
        runs.append({"index": index, "item": item, "title": title, "pages": pages, "key": key,
                     "store": store, "digest": digest, "stat": stat, "had_cache": had_cache})

    # Top up from items that still have unread windows when the shares fell short.
    total = sum(len(r["digest"].facts()) for r in runs)

    if total < needed_facts:
        deficit = needed_facts - total

        for run in runs:
            if deficit <= 0:
                break

            data = run["digest"].data
            unread = data["window_count"] - len(data["sections"])

            if unread <= 0:
                continue

            before = len(run["digest"].facts())
            more = build_digest(
                run["pages"], pool, PayloadDigestStore(run["store"].updated or run["store"].cached),
                needed_facts=before + deficit, rotation=rotation,
            )
            run["store"] = PayloadDigestStore(more.data)
            run["store"].updated = more.data
            run["digest"] = more
            run["stat"].calls += more.calls
            run["stat"].tokens_in += more.tokens_in
            run["stat"].tokens_out += more.tokens_out
            deficit -= max(0, len(more.facts()) - before)

    per_item: list[list[dict]] = []

    for run in runs:
        digest = run["digest"]
        if run["store"].updated is not None:
            out.digests[run["key"]] = run["store"].updated

        run["stat"].cached = run["had_cache"] and run["stat"].calls == 0
        concepts = []

        for concept in copy.deepcopy(digest.concepts()):
            prefix = f"i{run['index']}."
            concept["id"] = prefix + concept["id"]
            concept["source_title"] = run["title"]
            concept["source_item_id"] = run["item"].get("id")

            for fact in concept["facts"]:
                fact["id"] = prefix + fact["id"]
                fact["source_title"] = run["title"]
                fact["source_item_id"] = run["item"].get("id")
                out.facts[fact["id"]] = fact

            concepts.append(concept)

        run["stat"].facts = sum(len(c["facts"]) for c in concepts)

        if not concepts:
            out.warnings.append(f"Nothing could be summarised from '{run['title']}'.")

        per_item.append(concepts)

    for group in zip_longest(*per_item):
        out.concepts.extend(c for c in group if c is not None)

    return out
