import io
import os
import shutil

import pdfplumber
from PIL import Image
import pytesseract

# Minimum characters per page before we trust the PDF's own text layer over
# OCR. A digitally-authored PDF has real text; a scanned page usually
# extracts to empty or near-empty.
MIN_CHARS_PER_PAGE = 20

# Don't rely on PATH being current for this process — a service started
# before Tesseract was installed (or before a PATH refresh) won't see it
# even though `tesseract` works fine in a fresh shell. Check PATH first,
# then fall back to the standard Windows install location.
_TESSERACT_FALLBACK = r"C:\Program Files\Tesseract-OCR\tesseract.exe"

if shutil.which("tesseract") is None and os.path.exists(_TESSERACT_FALLBACK):
    pytesseract.pytesseract.tesseract_cmd = _TESSERACT_FALLBACK


def extract_from_pdf(file_bytes: bytes) -> tuple[str, bool, int]:
    """
    Returns (text, used_ocr, page_count). Uses each page's real text layer
    when present; falls back to OCR-ing the page image when it isn't
    (i.e. a scanned page).
    """

    texts: list[str] = []
    used_ocr = False

    with pdfplumber.open(io.BytesIO(file_bytes)) as pdf:
        page_count = len(pdf.pages)

        for page in pdf.pages:
            page_text = (page.extract_text() or "").strip()

            if len(page_text) >= MIN_CHARS_PER_PAGE:
                texts.append(page_text)
                continue

            # No usable text layer — this page is likely a scan/photo.
            used_ocr = True

            image = page.to_image(resolution=200).original

            ocr_text = pytesseract.image_to_string(image)

            texts.append(ocr_text.strip())

    return "\n\n".join(texts), used_ocr, page_count


def extract_from_image(file_bytes: bytes) -> tuple[str, bool, int]:
    image = Image.open(io.BytesIO(file_bytes))

    text = pytesseract.image_to_string(image)

    return text.strip(), True, 1
