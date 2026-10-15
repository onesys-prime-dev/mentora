# Mentora Question Builder Service

AI question generation for Mentora's quiz builder. It has two parts:

- **The worker** (`qb/`, run with `python -m qb.worker` or `.\run-worker.ps1`)
  is the current design. It pulls queued jobs from Mentora, reads the selected
  learning-unit material, and writes and checks questions with a hosted model
  (Groq's free tier by default). The text of the selected material **is sent to
  the provider**.
- **The original HTTP service** (`app/`, `uvicorn app.main:app`) is the older
  design that runs a model locally through Ollama. It is kept for now (it also
  reads scanned files with OCR) and is removed once nothing calls it.

## Run the worker on this machine with Groq

Use this to test the real pipeline locally, against your local site.

1. Get a Groq API key at <https://console.groq.com> (API Keys). Use a key of
   your own for local work, separate from staging's, so tests here cannot use
   up the staging allowance.
2. In this folder, create the settings file from the template and fill in the
   two secrets (the file is git-ignored; never commit it):
   ```
   copy deploy\qb-worker.local.env.example .env
   ```
   - `GROQ_API_KEY`: the key from step 1.
   - `QB_WORKER_SECRET`: any long random string, **the same value** as
     `questionBuilder.workerSecret` in the site's own `.env`.
   - `QB_PHP_URL` and `QB_PHP_VERIFY_TLS=false` are already right for XAMPP at
     `https://localhost/mentora/public` (its certificate is self-signed).
3. In the site's `.env`, set `questionBuilder.useJobs = true` and the same
   `questionBuilder.workerSecret`. Start Apache and MySQL in XAMPP.
4. Create the virtual environment once (skip if `venv` exists):
   ```
   python -m venv venv
   venv\Scripts\pip install -r requirements.txt
   ```
5. Start the worker and leave it running:
   ```
   .\run-worker.ps1
   ```
   It prints which settings it will use (never the key). Stop it with Ctrl+C.
6. Check it is connected: sign in as a system administrator and open the
   monitoring page; it should say the worker was last seen a few seconds ago.
   Then generate questions from a quiz in the builder.

If a job fails, the reason is in the worker's console (for example
`provider_auth` for a refused key). Settings already present in the real
environment override the `.env` file.

## Why this exists

Rather than calling a hosted AI API, this is a small self-hosted service so
generated questions stay in-house. See
`C:\Users\Waway\.claude\plans\jazzy-jingling-giraffe.md` for the full design
rationale and trade-offs (notably: this machine has ~4GB VRAM, which is tight
for a 7B model — expect generation to take tens of seconds per chunk of
questions, not be instant).

## One-time setup (original HTTP service, Ollama)

1. **Ollama** (model server) — <https://ollama.com/download>, or via winget:
   ```
   winget install Ollama.Ollama
   ```
   Then pull the model:
   ```
   ollama pull qwen2.5:3b-instruct
   ```

   **Why a 3B and not a 7B** — measured on this machine (RTX 3050 Ti, 4GB
   VRAM, shared with the IDE/browser):

   | | 7B (`qwen2.5:7b-instruct`) | 3B (`qwen2.5:3b-instruct`) |
   |---|---|---|
   | Size / placement | 5.1GB → **55% CPU / 45% GPU** | 2.2GB → **100% GPU** |
   | Decode | 5.1 tok/s | **74 tok/s** |
   | Prefill | 58 tok/s | **311 tok/s** |
   | 5 questions, full stack | ~3.5 min | **~17s** |

   The 7B does not fit in 4GB, so over half of it runs on CPU — that, not
   prompting, was the entire performance problem. Question quality between
   the two was comparable at this task (both need the review tray).

   Run `ollama ps` after a generation to check the `PROCESSOR` column: if it
   shows any CPU percentage, the model is too big for the GPU and generation
   will be slow. On a machine with more VRAM, `QUESTION_BUILDER_MODEL` can
   point at a larger model with no code change.

   **Avoid "thinking" models** (e.g. `qwen3:4b`): they emit long
   `<think>` reasoning before answering, which burns the token budget and
   breaks schema-constrained output — tested, it failed outright and took
   80s+ doing it.

2. **Tesseract OCR** (for reading scanned pages/photos) — via winget:
   ```
   winget install UB-Mannheim.TesseractOCR
   ```
   `pytesseract` looks for `tesseract.exe` on PATH; the UB-Mannheim installer
   adds it automatically. If it can't be found, set the path explicitly:
   ```python
   pytesseract.pytesseract.tesseract_cmd = r"C:\Program Files\Tesseract-OCR\tesseract.exe"
   ```

3. **Python environment**:
   ```
   python -m venv venv
   venv\Scripts\pip install -r requirements-local.txt
   ```

4. **Run the service**:
   ```
   venv\Scripts\uvicorn app.main:app --port 8008
   ```

5. In the main Mentora app's `.env`, add:
   ```
   QUESTION_BUILDER_SERVICE_URL=http://127.0.0.1:8008
   ```
   (This project has no `.env.example` file — add it directly to `.env`.)

## Endpoints

- `GET /health` — service + Ollama reachability check.
- `POST /generate` — `{ topic?, source_text?, count (<=15), type_mix, points_per_question?, recent_questions[] }` → `{ questions: [...], model_used }`. One call generates at most 15 questions; the PHP side loops to reach a larger total (see `QuestionGenerationService`). Returns `409` if another generation job is already running (this machine has one shared GPU — no concurrent generation).
- `POST /extract-text` — multipart file upload (PDF or image) → `{ text, used_ocr, pages }`. Digital PDFs use their real text layer; scanned pages/photos fall back to Tesseract OCR automatically, page by page.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `QUESTION_BUILDER_MODEL` | `qwen2.5:3b-instruct` | Ollama model tag to use for generation |
| `QUESTION_BUILDER_LARGE_MODEL` | `qwen2.5:7b-instruct` | Only worth selecting on a GPU that can hold it fully |
| `QUESTION_BUILDER_KEEP_ALIVE` | `30m` | How long Ollama keeps the model resident (cold load ≈ 5s) |
| `OLLAMA_HOST` | `http://127.0.0.1:11434` | Where Ollama's own server is listening |
| `QUESTION_BUILDER_TIMEOUT` | `180` | Per-call generation timeout (seconds) |

## How output is constrained

`/generate` passes a full **JSON schema** to Ollama's `format` parameter, so
decoding is grammar-constrained (XGrammar). `minItems`/`maxItems` structurally
guarantee the exact requested question count, and `question_type` is locked to
the types the instructor asked for.

This replaced two retry round-trips that used to roughly double latency: a
JSON-repair retry and a "you only wrote 1 of 3, write more" top-up call. It
also means the prompt no longer needs to describe the output shape or include
an example, which cuts prefill cost on every call.

## Production note

This runs as a manually-started local process for now. Process supervision
(Windows service, Docker, etc.) for anything beyond local dev is a follow-up,
not covered here.
