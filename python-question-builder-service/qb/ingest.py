"""Ingest: a file or pasted text becomes numbered pages.

Later stages cite pages ("from page 12"), so every page keeps its number.
Scans are read with OCR, and how sure OCR was is kept per page so a poor scan
can be flagged instead of silently producing questions about noise. Limits are
here, so a huge or hostile file is refused before anything is spent on it.

The reading itself (pdfplumber, Tesseract) sits behind two small callables so
the rules above can be tested without either installed."""

import io
import re
from dataclasses import dataclass, field
from typing import Callable, Iterable, Iterator

from .errors import SourceUnreadable

MAX_BYTES = 25 * 1024 * 1024
MAX_PAGES = 150
# OCR is slow and CPU-bound on a shared VPS: only this many pages per file.
MAX_OCR_PAGES = 30
# Fewer characters than this and a PDF page is treated as a scan.
MIN_TEXT_CHARS = 20
# Mean OCR confidence (0 to 100) under this is called out as hard to read.
LOW_CONFIDENCE = 60.0

MARKER = "[[page {n}]]"
_MARKER = re.compile(r"^\[\[page (\d+)\]\]$", re.MULTILINE)

# (page number, the page's own text layer, a function that renders it to an image)
RawPage = tuple[int, str, Callable[[], object]]
PageReader = Callable[[bytes], Iterable[RawPage]]
Ocr = Callable[[object], tuple[str, float | None]]


@dataclass
class Page:
    number: int
    text: str
    source: str = "text"  # "text" | "ocr"
    confidence: float | None = None  # OCR only, 0 to 100


@dataclass
class Ingested:
    pages: list[Page]
    warnings: list[str] = field(default_factory=list)
    truncated: bool = False

    @property
    def used_ocr(self) -> bool:
        return any(p.source == "ocr" for p in self.pages)

    @property
    def page_count(self) -> int:
        return len(self.pages)

    def marked_text(self) -> str:
        """The whole source with `[[page N]]` lines, the form later stages read."""

        return "\n\n".join(f"{MARKER.format(n=p.number)}\n{p.text}" for p in self.pages if p.text)


def split_marked(text: str) -> list[Page]:
    """Inverse of marked_text(); text with no markers is one page."""

    parts = _MARKER.split(text)

    if len(parts) == 1:
        return [Page(1, text.strip())] if text.strip() else []

    pages = []

    for number, body in zip(parts[1::2], parts[2::2]):
        pages.append(Page(int(number), body.strip()))

    return pages


def ingest_text(text: str) -> Ingested:
    cleaned = text.strip()

    if not cleaned:
        raise SourceUnreadable("There is no text to read.")

    return Ingested([Page(1, cleaned)])


def ingest_pdf(
    data: bytes,
    *,
    reader: PageReader | None = None,
    ocr: Ocr | None = None,
    max_pages: int = MAX_PAGES,
    max_ocr_pages: int = MAX_OCR_PAGES,
) -> Ingested:
    _check_size(data)
    reader = reader or _pdfplumber_pages
    ocr = ocr or _tesseract

    pages: list[Page] = []
    warnings: list[str] = []
    truncated = False
    ocr_used = 0
    ocr_skipped = 0

    try:
        for number, layer, render in reader(data):
            if number > max_pages:
                truncated = True
                break

            text = (layer or "").strip()

            if len(text) >= MIN_TEXT_CHARS:
                pages.append(Page(number, text))
                continue

            # No usable text layer: a scan or a photo.
            if ocr_used >= max_ocr_pages:
                ocr_skipped += 1
                pages.append(Page(number, "", "ocr", 0.0))
                continue

            ocr_used += 1
            ocr_text, confidence = ocr(render())
            pages.append(Page(number, ocr_text.strip(), "ocr", confidence))
    except SourceUnreadable:
        raise
    except Exception as exc:
        raise SourceUnreadable("The file could not be opened as a PDF.") from exc

    if truncated:
        warnings.append(f"Only the first {max_pages} pages were read.")

    if ocr_skipped:
        warnings.append(f"{ocr_skipped} scanned pages were skipped: only {max_ocr_pages} can be read per file.")

    warnings.extend(_confidence_warnings(pages))

    return _finish(pages, warnings, truncated)


def ingest_image(data: bytes, *, ocr: Ocr | None = None) -> Ingested:
    _check_size(data)
    ocr = ocr or _tesseract

    try:
        from PIL import Image

        image = Image.open(io.BytesIO(data))
        image.load()
    except Exception as exc:
        raise SourceUnreadable("The file could not be opened as an image.") from exc

    text, confidence = ocr(image)
    pages = [Page(1, text.strip(), "ocr", confidence)]

    return _finish(pages, _confidence_warnings(pages), False)


def _finish(pages: list[Page], warnings: list[str], truncated: bool) -> Ingested:
    if not any(p.text for p in pages):
        raise SourceUnreadable("No text could be read from this file.")

    return Ingested(pages, warnings, truncated)


def _confidence_warnings(pages: list[Page]) -> list[str]:
    weak = [p.number for p in pages if p.source == "ocr" and p.text and p.confidence is not None and p.confidence < LOW_CONFIDENCE]

    if not weak:
        return []

    listed = ", ".join(str(n) for n in weak[:5]) + (" and more" if len(weak) > 5 else "")

    return [f"Page {listed} looked hard to read, so questions from it may be unreliable."]


def _check_size(data: bytes) -> None:
    if not data:
        raise SourceUnreadable("The file is empty.")

    if len(data) > MAX_BYTES:
        raise SourceUnreadable(f"The file is larger than {MAX_BYTES // (1024 * 1024)} MB.")


# ----------------------------------------------------------------- real readers


def _pdfplumber_pages(data: bytes) -> Iterator[RawPage]:  # pragma: no cover - needs a real PDF
    import pdfplumber

    with pdfplumber.open(io.BytesIO(data)) as pdf:
        for index, page in enumerate(pdf.pages, start=1):
            yield index, page.extract_text() or "", (lambda p=page: p.to_image(resolution=200).original)


def _tesseract(image) -> tuple[str, float | None]:  # pragma: no cover - needs Tesseract
    import pytesseract

    data = pytesseract.image_to_data(image, output_type=pytesseract.Output.DICT)
    words = [w for w in data["text"] if w.strip()]
    confs = [float(c) for c, w in zip(data["conf"], data["text"]) if w.strip() and float(c) >= 0]

    return " ".join(words), (sum(confs) / len(confs) if confs else None)
