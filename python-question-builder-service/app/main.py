from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from . import config, extraction, generation
from .schemas import ExtractTextResponse, GenerateRequest, GenerateResponse

app = FastAPI(
    title="Mentora Question Builder Service",
    description=(
        "Local AI question generation for Mentora's quiz builder. "
        "Uses a self-hosted Ollama model — never sends course content to a "
        "third-party API."
    ),
)

ALLOWED_PDF_TYPES = {"application/pdf"}
ALLOWED_IMAGE_TYPES = {"image/png", "image/jpeg", "image/jpg", "image/webp"}


@app.get("/health")
def health():
    try:
        import ollama

        ollama.list()
        ollama_ok = True
    except Exception:
        ollama_ok = False

    return {
        "status": "ok" if ollama_ok else "degraded",
        "ollama_reachable": ollama_ok,
        "model": config.MODEL_NAME,
    }


@app.post("/generate", response_model=GenerateResponse)
def generate(request: GenerateRequest):
    # A caller asking for more than one call's worth gets this call's worth,
    # not an error — the chunk loop then asks again for the remainder.
    # (Rejecting instead used to fail any batch larger than the per-call cap
    # outright, which broke the whole 20/50/100-question use case.)
    try:
        questions, model_used = generation.generate_questions(request)
    except generation.GenerationBusyError as error:
        raise HTTPException(status_code=409, detail=str(error))
    except generation.GenerationFailedError as error:
        raise HTTPException(status_code=502, detail=str(error))
    except Exception as error:
        raise HTTPException(status_code=502, detail=f"Generation failed: {error}")

    return GenerateResponse(questions=questions, model_used=model_used)


@app.post("/extract-text", response_model=ExtractTextResponse)
async def extract_text(file: UploadFile = File(...)):
    contents = await file.read()

    if file.content_type == "application/pdf":
        text, used_ocr, pages = extraction.extract_from_pdf(contents)

    elif file.content_type in ALLOWED_IMAGE_TYPES:
        text, used_ocr, pages = extraction.extract_from_image(contents)

    else:
        raise HTTPException(
            status_code=400,
            detail=f"Unsupported file type: {file.content_type}. "
            "Expected a PDF or an image (png/jpeg/webp).",
        )

    if not text.strip():
        raise HTTPException(
            status_code=422,
            detail="No text could be extracted from this file.",
        )

    return ExtractTextResponse(text=text, used_ocr=used_ocr, pages=pages)
