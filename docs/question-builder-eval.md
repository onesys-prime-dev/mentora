# Question builder evaluation

A manual harness for judging the question builder before and after a change.
It lives in `python-question-builder-service/eval/` and is **never run in
per-PR CI**: it calls a real model (the free allowance, once hosted).

## Run

```
cd python-question-builder-service
venv\Scripts\python -m eval.run --out eval\report.json        # whole set
venv\Scripts\python -m eval.run --only text-networking        # one case
```

It uses whatever model the service is configured for
(`QUESTION_BUILDER_MODEL`; today `qwen2.5:3b-instruct` through Ollama).

## What it reports (per case and in total)

| Metric | Meaning |
|---|---|
| fill rate | produced / requested |
| schema-valid rate | passes the service's own structural check |
| duplicate pairs | near-copies within a case |
| grounded fraction | share of questions whose words appear in the source (a lexical proxy; topic-only cases have none) |
| math mismatch / verified | sympy check results |
| tokens in / out | **estimates** from character counts, until the hosted providers report real usage |
| seconds | wall clock per case |

## Golden set

`eval/golden.json` holds 6 starter cases. The plan calls for 25 to 30 real
passages, including a PDF, a scan and a document of existing questions: add
them as they are supplied. A human-judged correctness sample needs a named
reviewer (open item in the handout).

## Baseline

Not recorded yet. Run the set against the 3B model on a machine with the GPU
and paste the summary here before any pipeline change is judged against it.
