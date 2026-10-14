import pytest

from qb import ingest
from qb.errors import SourceUnreadable

LONG = "This page has a real text layer with plenty of characters on it."


def reader(*pages):
    """pages: (text layer,) per page; the renderer returns a marker object."""

    def read(data):
        for number, layer in enumerate(pages, start=1):
            yield number, layer, (lambda n=number: f"image-{n}")

    return read


def ocr_by_image(table):
    def run(image):
        return table[image]

    return run


def test_pasted_text_is_one_page_and_blank_text_is_refused():
    result = ingest.ingest_text("  Some notes about routing.  ")

    assert [(p.number, p.text) for p in result.pages] == [(1, "Some notes about routing.")]

    with pytest.raises(SourceUnreadable):
        ingest.ingest_text("   \n ")


def test_pages_keep_their_numbers_and_markers_round_trip():
    result = ingest.ingest_pdf(b"x", reader=reader(LONG, LONG + " Second."))

    marked = result.marked_text()

    assert marked.startswith("[[page 1]]\n") and "[[page 2]]" in marked
    assert [(p.number, p.text) for p in ingest.split_marked(marked)] == [(p.number, p.text) for p in result.pages]
    assert not result.used_ocr and result.page_count == 2


def test_text_without_markers_is_a_single_page():
    assert [p.number for p in ingest.split_marked("just words")] == [1]
    assert ingest.split_marked("   ") == []


def test_a_page_without_a_text_layer_is_read_with_ocr_and_keeps_its_confidence():
    result = ingest.ingest_pdf(
        b"x",
        reader=reader(LONG, ""),
        ocr=ocr_by_image({"image-2": ("scanned words here", 91.0)}),
    )

    scanned = result.pages[1]

    assert result.used_ocr
    assert (scanned.source, scanned.text, scanned.confidence) == ("ocr", "scanned words here", 91.0)
    assert result.warnings == []


def test_a_poor_scan_is_called_out_with_its_page():
    result = ingest.ingest_pdf(
        b"x",
        reader=reader(LONG, "", ""),
        ocr=ocr_by_image({"image-2": ("noise", 35.0), "image-3": ("fine text", 88.0)}),
    )

    assert len(result.warnings) == 1
    assert "Page 2 " in result.warnings[0] and "hard to read" in result.warnings[0]


def test_only_so_many_pages_are_read_and_the_cut_is_said():
    result = ingest.ingest_pdf(b"x", reader=reader(*[LONG] * 10), max_pages=4)

    assert result.page_count == 4 and result.truncated
    assert "first 4 pages" in result.warnings[0]


def test_ocr_is_capped_per_file_and_skipped_pages_are_counted():
    calls = []

    def ocr(image):
        calls.append(image)

        return "text", 90.0

    result = ingest.ingest_pdf(b"x", reader=reader("", "", "", ""), ocr=ocr, max_ocr_pages=2)

    assert len(calls) == 2
    assert any("2 scanned pages were skipped" in w for w in result.warnings)


def test_a_file_with_nothing_readable_is_refused():
    with pytest.raises(SourceUnreadable):
        ingest.ingest_pdf(b"x", reader=reader("", ""), ocr=lambda image: ("", 0.0))


def test_empty_and_oversized_files_are_refused_before_any_work():
    def must_not_read(data):
        raise AssertionError("should not be read")

    with pytest.raises(SourceUnreadable):
        ingest.ingest_pdf(b"", reader=must_not_read)

    with pytest.raises(SourceUnreadable):
        ingest.ingest_pdf(b"x" * (ingest.MAX_BYTES + 1), reader=must_not_read)


def test_a_reader_that_blows_up_becomes_an_unreadable_file():
    def broken(data):
        raise ValueError("corrupt xref")
        yield  # pragma: no cover

    with pytest.raises(SourceUnreadable):
        ingest.ingest_pdf(b"x", reader=broken)


def test_garbage_is_not_a_pdf_or_an_image():
    with pytest.raises(SourceUnreadable):
        ingest.ingest_pdf(b"this is not a pdf at all")

    with pytest.raises(SourceUnreadable):
        ingest.ingest_image(b"this is not an image")
