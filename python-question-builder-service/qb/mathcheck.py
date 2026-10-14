"""Math is computed, not asked: the writer returns a `math_spec` and sympy
evaluates it. A model is never the judge of arithmetic.

    {"expression": "2*x + 3 - 11", "variable": "x", "expected": 4}
        -> the expression is set to zero and solved for x
    {"expression": "3*4 + 2", "expected": 14}
        -> the expression is evaluated

The model's text is untrusted input to a parser, so it is checked against a
small character set and parsed with an empty namespace plus a short whitelist
of functions before anything is evaluated."""

import math
import re

from sympy import Float, Integer, Rational, Symbol, cos, log, pi, sin, solve, sqrt, tan
from sympy.parsing.sympy_parser import (
    convert_xor,
    implicit_multiplication_application,
    parse_expr,
    standard_transformations,
)

VERIFIED = "verified"
MISMATCH = "mismatch"
NOT_APPLICABLE = "not_applicable"

_ALLOWED = re.compile(r"^[0-9A-Za-z_+\-*/^().,\s]+$")
_NAME = re.compile(r"[A-Za-z_][A-Za-z_0-9]*")
_FUNCTIONS = {"sqrt": sqrt, "sin": sin, "cos": cos, "tan": tan, "log": log, "pi": pi}
_TRANSFORMS = standard_transformations + (implicit_multiplication_application, convert_xor)
_NUMBER = re.compile(r"-?\d+(?:\.\d+)?")
TOLERANCE = 1e-6


def check_spec(spec: dict | None) -> str:
    """`verified` when sympy agrees with `expected`, `mismatch` when it
    disagrees, `not_applicable` when the spec is absent or not computable."""

    if not isinstance(spec, dict):
        return NOT_APPLICABLE

    expression = str(spec.get("expression", "")).strip()
    variable = str(spec.get("variable", "") or "").strip()

    try:
        expected = float(spec["expected"])
    except (KeyError, TypeError, ValueError):
        return NOT_APPLICABLE

    if not expression or len(expression) > 200 or not _ALLOWED.match(expression) or "__" in expression:
        return NOT_APPLICABLE

    if variable and not re.fullmatch(r"[a-z]", variable):
        return NOT_APPLICABLE

    local = {**_FUNCTIONS}

    for name in _NAME.findall(expression):
        if name not in _FUNCTIONS and name != variable:
            return NOT_APPLICABLE  # an unknown name is never guessed at

    if variable:
        local[variable] = Symbol(variable)

    try:
        value = parse_expr(
            expression,
            local_dict=local,
            global_dict={"Integer": Integer, "Float": Float, "Rational": Rational, "Symbol": Symbol},
            transformations=_TRANSFORMS,
            evaluate=True,
        )

        if variable:
            roots = solve(value, local[variable])

            if not roots:
                return NOT_APPLICABLE

            numbers = [complex(r.evalf()) for r in roots]
            real = [n.real for n in numbers if abs(n.imag) < TOLERANCE]

            if not real:
                return NOT_APPLICABLE

            return VERIFIED if any(abs(r - expected) < TOLERANCE for r in real) else MISMATCH

        result = complex(value.evalf())
    except Exception:
        return NOT_APPLICABLE

    if not (math.isfinite(result.real) and math.isfinite(result.imag)) or abs(result.imag) >= TOLERANCE:
        return NOT_APPLICABLE

    return VERIFIED if abs(result.real - expected) < TOLERANCE else MISMATCH


def check_question(spec: dict | None, correct_choice_text: str | None) -> str:
    """The spec must hold, and for a multiple-choice question the choice
    marked correct must be the computed value: a right spec with a wrong key
    is a mismatch."""

    verdict = check_spec(spec)

    if verdict != VERIFIED or correct_choice_text is None:
        return verdict

    match = _NUMBER.search(correct_choice_text.replace(",", ""))

    if match is None:
        return NOT_APPLICABLE

    return VERIFIED if abs(float(match.group()) - float(spec["expected"])) < TOLERANCE else MISMATCH
