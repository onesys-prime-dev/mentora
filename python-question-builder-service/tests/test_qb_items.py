import json

from qb.digest import source_hash
from qb.governor import RateGovernor
from qb.ingest import split_marked
from qb.items import digest_items
from qb.pipeline import make_generate_handler
from qb.pool import ModelPool
from qb.providers.base import Capabilities
from qb.providers.fake import FakeProvider

BIG = Capabilities(tokens_per_minute=10**9, tokens_per_day=10**12)


def make_pool(script):
    provider = FakeProvider(script)
    pool = ModelPool(provider, ["m"], RateGovernor({"m": BIG}), clock=lambda: 0.0, sleep=lambda s: None)

    return provider, pool


def digest_reply(*concepts):
    return json.dumps({"concepts": [
        {"name": name, "facts": [{"text": text, "page": page} for text, page in facts]} for name, facts in concepts
    ]})


READING = {"id": 11, "title": "Networking basics", "type": "reading",
           "text": "Routers forward packets using the destination address in a routing table."}
PDF = {"id": 12, "title": "Transport slides", "type": "pdf",
       "text": "[[page 4]]\nTCP gives reliable ordered delivery with acknowledgements.\n\n[[page 5]]\nUDP is faster but unreliable."}

READING_REPLY = digest_reply(("Routing", [("Routers forward packets using the destination address", 1)]))
PDF_REPLY = digest_reply(
    ("TCP", [("TCP gives reliable ordered delivery with acknowledgements", 4)]),
    ("UDP", [("UDP is faster but unreliable", 5)]),
)


def test_each_item_is_digested_on_its_own_and_cited_by_title_and_page():
    provider, pool = make_pool([READING_REPLY, PDF_REPLY])

    result = digest_items([READING, PDF], {}, pool, needed_facts=3)

    assert len(provider.calls) == 2
    titles = {f["source_title"] for f in result.facts.values()}
    assert titles == {"Networking basics", "Transport slides"}
    tcp = next(f for f in result.facts.values() if "TCP" in f["text"])
    assert tcp["page"] == 4 and tcp["source_item_id"] == 12
    assert len(result.digests) == 2


def test_fact_and_concept_ids_cannot_collide_between_items():
    _, pool = make_pool([READING_REPLY, READING_REPLY])
    twin = dict(READING, id=13, title="Copy")

    result = digest_items([READING, twin], {}, pool, needed_facts=2)

    ids = [c["id"] for c in result.concepts]
    assert len(ids) == len(set(ids)) == 2
    assert all(i.startswith(("i0.", "i1.")) for i in ids)


def test_cached_items_cost_nothing_and_return_no_new_digests():
    _, first = make_pool([READING_REPLY, PDF_REPLY])
    seeded = digest_items([READING, PDF], {}, first, needed_facts=3)

    provider, pool = make_pool([])
    again = digest_items([READING, PDF], seeded.digests, pool, needed_facts=3)

    assert provider.calls == []
    assert again.digests == {}
    assert len(again.facts) == len(seeded.facts)
    assert all(s.cached for s in again.stats)


def test_unticking_an_item_reads_nothing_new_and_leaves_it_out():
    _, first = make_pool([READING_REPLY, PDF_REPLY])
    seeded = digest_items([READING, PDF], {}, first, needed_facts=3)

    provider, pool = make_pool([])
    only_pdf = digest_items([PDF], seeded.digests, pool, needed_facts=3)

    assert provider.calls == []
    assert {f["source_title"] for f in only_pdf.facts.values()} == {"Transport slides"}


def test_adding_an_item_reads_only_the_new_one():
    _, first = make_pool([READING_REPLY])
    seeded = digest_items([READING], {}, first, needed_facts=2)

    provider, pool = make_pool([PDF_REPLY])
    both = digest_items([READING, PDF], seeded.digests, pool, needed_facts=4)

    assert len(provider.calls) == 1
    assert list(both.digests) == [source_hash(split_marked(PDF["text"]))]


def test_concepts_are_interleaved_so_a_small_count_touches_every_item():
    rich_a = digest_reply(("A1", [("Alpha one fact", 1)]), ("A2", [("Alpha two fact", 1)]), ("A3", [("Alpha three fact", 1)]))
    rich_b = digest_reply(("B1", [("Beta one fact", 1)]), ("B2", [("Beta two fact", 1)]))
    item_a = {"id": 1, "title": "A", "type": "reading", "text": "Alpha one fact. Alpha two fact. Alpha three fact."}
    item_b = {"id": 2, "title": "B", "type": "reading", "text": "Beta one fact. Beta two fact."}
    _, pool = make_pool([rich_a, rich_b])

    result = digest_items([item_a, item_b], {}, pool, needed_facts=5)

    assert [c["name"] for c in result.concepts] == ["A1", "B1", "A2", "B2", "A3"]


def test_an_item_with_no_text_is_reported_and_the_rest_still_work():
    _, pool = make_pool([READING_REPLY])
    empty = {"id": 99, "title": "Empty reading", "type": "reading", "text": "   "}

    result = digest_items([empty, READING], {}, pool, needed_facts=2)

    assert any("Empty reading" in w for w in result.warnings)
    assert len(result.facts) == 1


def test_a_short_share_is_topped_up_from_an_item_that_still_has_unread_windows():
    long_a = {"id": 1, "title": "Long", "type": "reading", "text": "Alpha " * 2600}
    short_b = {"id": 2, "title": "Short", "type": "reading", "text": "Beta gamma delta epsilon."}
    # The first window already meets the item's share (3), so it is not read further at first.
    first = digest_reply(("A1", [("Alpha alpha", 1), ("Alpha alpha alpha", 1), ("Alpha alpha alpha alpha", 1)]))
    second = digest_reply(("A2", [("Alpha alpha alpha alpha alpha", 1)]), ("A3", [("Alpha alpha alpha alpha alpha alpha", 1)]))
    b_reply = digest_reply(("B1", [("Beta gamma delta", 1)]))
    provider, pool = make_pool([first, b_reply, second])

    result = digest_items([long_a, short_b], {}, pool, needed_facts=6)

    assert len(provider.calls) == 3
    assert len([f for f in result.facts.values() if f["source_title"] == "Long"]) == 5


# ----------------------------------------------------------------- the whole job


class Ctx:
    def __init__(self):
        self.notes = []

    def progress(self, **fields):
        self.notes.append((fields.get("stage"), fields.get("note")))


def test_the_job_with_items_cites_titles_pages_and_returns_per_item_digests():
    # Concepts interleave across items, so the first question is the reading's.
    write = json.dumps({"questions": [
        {"concept_id": "i0.0.0", "type": "Short Answer", "text": "What do routers use to forward packets?",
         "fact_id": "i0.0.0.0", "explanation": "Routing table.", "accepted_answers": ["destination address"]}]})
    verify = json.dumps({"answers": [{"n": 1, "answer": "destination address"}]})
    _, pool = make_pool([READING_REPLY, PDF_REPLY, write, verify])
    ctx = Ctx()

    result = make_generate_handler(pool)(
        {"payload": {"items": [READING, PDF], "count": 1, "type_mix": {"short_answer": 1}}}, ctx
    )

    question = result["questions"][0]
    assert question["source_title"] == "Networking basics" and question["page"] == 1
    assert question["source_item_id"] == 11
    assert "digest" not in result and len(result["digests"]) == 2
    assert [s["title"] for s in result["diagnostics"]["per_item"]] == ["Networking basics", "Transport slides"]
    assert any("Transport slides" in (note or "") for stage, note in ctx.notes if stage == "digest")


def test_a_pdf_question_carries_its_page_from_the_pdfs_own_numbering():
    write = json.dumps({"questions": [
        {"concept_id": "i0.0.0", "type": "Short Answer", "text": "Which protocol gives reliable ordered delivery?",
         "fact_id": "i0.0.0.0", "explanation": "TCP.", "accepted_answers": ["TCP"]}]})
    verify = json.dumps({"answers": [{"n": 1, "answer": "TCP"}]})
    _, pool = make_pool([PDF_REPLY, write, verify])

    result = make_generate_handler(pool)(
        {"payload": {"items": [PDF], "count": 1, "type_mix": {"short_answer": 1}}}, Ctx()
    )

    question = result["questions"][0]
    assert question["source_title"] == "Transport slides" and question["page"] == 4
