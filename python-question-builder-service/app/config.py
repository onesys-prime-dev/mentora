import os

# Model tag as served by Ollama.
#
# Sized deliberately: this machine's GPU has 4GB total and other apps (IDE,
# browser) hold 2+GB of it, so a 7B (~5.1GB) runs at a 55/45 CPU/GPU split
# and manages only ~5 tok/s. A 3B (~2.2GB) fits entirely in VRAM and
# measured ~74 tok/s — roughly 10x faster end to end, with comparable
# question quality at this size class. Swap via QUESTION_BUILDER_MODEL if
# you move to a machine with more VRAM.
MODEL_NAME = os.environ.get("QUESTION_BUILDER_MODEL", "qwen2.5:3b-instruct")

# Larger model, only worth selecting on a GPU that can hold it fully. On
# 4GB it will partially offload to CPU and get dramatically slower.
LARGE_MODEL_NAME = os.environ.get("QUESTION_BUILDER_LARGE_MODEL", "qwen2.5:7b-instruct")

OLLAMA_HOST = os.environ.get("OLLAMA_HOST", "http://127.0.0.1:11434")

# Hard cap per /generate call. The PHP side loops to reach a larger
# requested total (see QuestionGenerationService chunking).
MAX_QUESTIONS_PER_CALL = 15

# Keep the model resident between requests. A cold load measured ~5.4s,
# and Ollama's default (5m) means an idle instructor pays that repeatedly.
KEEP_ALIVE = os.environ.get("QUESTION_BUILDER_KEEP_ALIVE", "30m")

# Rough output budget per question (stem + 4 choices + fields). Used to cap
# num_predict so a rambling response can't run unbounded.
TOKENS_PER_QUESTION = 160

# Generation timeout, in seconds, per model call.
GENERATION_TIMEOUT_SECONDS = int(os.environ.get("QUESTION_BUILDER_TIMEOUT", "180"))
