"""
Deterministic math verification for generated Multiple Choice questions.

This exists because the model itself is not reliable at arithmetic/algebra:
observed failure in testing — a question "2x - 5 = 3x + 10" was generated
with the marked-correct choice "x = -5" (the actual solution is x = -15).
Rather than trust the model's self-reported correct answer, independently
solve any equation we can confidently extract and cross-check it.

Deliberately conservative: this only ever adds a "please double-check this"
flag, never silently changes anything, and any parsing ambiguity results in
"not_applicable" rather than a guessed verdict. False negatives (missing a
real error) are acceptable; false positives (flagging a correct question as
wrong) actively erode trust in the review tray, so we fail closed throughout.
"""

import re

import sympy
from sympy.core.sympify import SympifyError
from sympy.parsing.sympy_parser import (
    parse_expr,
    standard_transformations,
    implicit_multiplication_application,
)

# Without this, sympy's parser requires explicit "3*x" and raises a
# SyntaxError on the "3x" notation our own generated questions actually use.
_PARSE_TRANSFORMATIONS = standard_transformations + (implicit_multiplication_application,)

# A standalone single lowercase letter, e.g. the "x" in "solve for x" or
# "3x" — used only to *find the candidate variable letter*, not to extract
# the equation itself (that's a second, narrower pass — see
# _extract_equation). Uppercase is deliberately excluded throughout: sympy
# treats several capitals (E, I, N, O, S, ...) as reserved constants, which
# would silently change meaning rather than fail loudly.
_VARIABLE_LETTER_PATTERN = re.compile(r"(?<![a-zA-Z])([a-z])(?![a-zA-Z])")

# Matches a variable assignment or bare number in a choice's text, e.g.
# "x = 5", "y=-4", "-15", "7.5".
_CHOICE_VALUE_PATTERN = re.compile(
    r"^\s*(?:[a-zA-Z]\s*=\s*)?(-?\d+(?:\.\d+)?)\s*$"
)


def check_multiple_choice_math(question_text: str, choices: list[dict]) -> str:
    """
    Returns "verified", "mismatch", or "not_applicable".

    Only ever called for Multiple Choice questions. Requires exactly one
    choice marked correct with an extractable numeric value, and exactly
    one solvable equation with exactly one variable found in the question
    text — anything else is "not_applicable", not a guess.
    """

    correct_choices = [c for c in choices if c.get("is_correct")]

    if len(correct_choices) != 1:
        return "not_applicable"

    correct_value = _extract_choice_value(correct_choices[0].get("text", ""))

    if correct_value is None:
        return "not_applicable"

    equation = _extract_equation(question_text)

    if equation is None:
        return "not_applicable"

    solved_value = _solve_single_variable_equation(equation)

    if solved_value is None:
        return "not_applicable"

    if abs(float(solved_value) - correct_value) < 1e-6:
        return "verified"

    return "mismatch"


def _extract_choice_value(choice_text: str) -> float | None:
    match = _CHOICE_VALUE_PATTERN.match(choice_text.strip())

    if not match:
        return None

    try:
        return float(match.group(1))
    except ValueError:
        return None


def _extract_equation(question_text: str) -> str | None:
    # Phase 1: find the candidate variable letter across the *whole*
    # question text (e.g. the isolated "x" in "solve for x in ..."). This
    # must be unambiguous — zero or 2+ distinct candidates means either no
    # single-variable equation is being asked for, or multiple unknowns
    # are involved (e.g. "a = 2 and b = 3") — both out of scope.
    candidate_letters = set(_VARIABLE_LETTER_PATTERN.findall(question_text))

    if len(candidate_letters) != 1:
        return None

    letter = next(iter(candidate_letters))

    # Phase 2: extract the equation using a character class scoped to only
    # *that* letter (never a-z generally) — broadening this to any letter
    # would make it greedily swallow surrounding English prose too, since
    # words like "solve" or "equation" are themselves all lowercase
    # letters and would otherwise match the same class.
    #
    # The leading (?<![a-zA-Z]) guards against a subtler trap: without it,
    # a match can start from the target letter where it happens to appear
    # embedded at the *end* of an unrelated word (e.g. the "n" in
    # "...the equation 5n - 3 = 18" — "equation" ends in "n", and
    # everything after that point happens to be in the allowed class, so
    # the match would start there instead of at "5n"). Requiring the
    # character immediately before the match not be a letter forces it to
    # start at a real word/number boundary.
    char_class = r"[0-9" + re.escape(letter) + r"\s\+\-\*/\(\)\.]"

    equation_pattern = re.compile(
        r"(?<![a-zA-Z])(" + char_class + r"+=" + char_class + r"+)"
    )

    matches = equation_pattern.findall(question_text)

    # More than one "=" span (e.g. an equation plus something else that
    # happens to match) means we can't be confident which one to solve.
    if len(matches) != 1:
        return None

    return matches[0].strip()


def _solve_single_variable_equation(equation_text: str):
    if equation_text.count("=") != 1:
        return None

    left_text, right_text = equation_text.split("=", 1)

    # Strip stray sentence punctuation the regex extraction can pick up
    # (e.g. a trailing "." from "...= 19.") — not part of the expression.
    left_text = left_text.strip().rstrip(".,;: ")
    right_text = right_text.strip().rstrip(".,;: ")

    variable_letter = next(iter(set(re.findall(r"[a-z]", equation_text))))

    local_dict = {variable_letter: sympy.Symbol(variable_letter)}

    try:
        left = parse_expr(
            left_text, local_dict=local_dict, transformations=_PARSE_TRANSFORMATIONS
        )
        right = parse_expr(
            right_text, local_dict=local_dict, transformations=_PARSE_TRANSFORMATIONS
        )
    except (SympifyError, SyntaxError, TypeError):
        return None

    variables = (left.free_symbols | right.free_symbols)

    if len(variables) != 1:
        return None

    variable = next(iter(variables))

    try:
        solutions = sympy.solve(sympy.Eq(left, right), variable)
    except (NotImplementedError, TypeError):
        return None

    # Only a single, real, numeric solution counts as verifiable — multiple
    # roots or a symbolic/complex result is ambiguous, so skip rather than
    # pick one.
    if len(solutions) != 1:
        return None

    solution = solutions[0]

    if not solution.is_real or not solution.is_number:
        return None

    try:
        return float(solution)
    except TypeError:
        return None
