from app import generation, verification
from app.schemas import GeneratedQuestion


def test_split_count_sums_to_count_and_follows_ratio():
    result = generation._split_count_by_type_mix(
        10, {"multiple_choice": 8, "short_answer": 1, "essay": 1}
    )

    assert sum(result.values()) == 10
    assert result["multiple_choice"] == 8


def test_split_count_without_usable_mix_is_all_multiple_choice():
    assert generation._split_count_by_type_mix(7, {}) == {"multiple_choice": 7}


def test_split_count_is_deterministic_on_ties():
    mix = {"multiple_choice": 1, "short_answer": 1}

    assert generation._split_count_by_type_mix(3, mix) == generation._split_count_by_type_mix(3, mix)


def test_response_schema_locks_choices_for_multiple_choice():
    schema = generation._build_response_schema(5, "Multiple Choice")
    text = str(schema)

    assert schema["minItems"] == 5 and schema["maxItems"] == 5
    assert "Multiple Choice" in text


def test_similarity_flags_near_duplicate_and_keeps_distinct():
    seen = [generation._normalize_for_dedupe("What is the capital city of France?")]

    assert generation._is_too_similar("What is the capital city of France", seen)
    assert not generation._is_too_similar("Explain photosynthesis in plants", seen)


def _mc(correct_count: int, choices: int = 4) -> GeneratedQuestion:
    return GeneratedQuestion(
        question_type="Multiple Choice",
        question_text="Pick one",
        points=1,
        choices=[
            {"text": str(i), "is_correct": i < correct_count} for i in range(choices)
        ],
        accepted_answers=[],
    )


def test_structural_check_requires_four_choices_one_correct():
    assert generation._is_structurally_valid(_mc(1))
    assert not generation._is_structurally_valid(_mc(2))
    assert not generation._is_structurally_valid(_mc(1, choices=3))


def test_math_check_verified_mismatch_and_not_applicable():
    right = [{"text": "x = 4", "is_correct": True}, {"text": "x = 5", "is_correct": False}]
    wrong = [{"text": "x = 5", "is_correct": True}, {"text": "x = 4", "is_correct": False}]

    assert verification.check_multiple_choice_math("Solve 2x + 3 = 11", right) == "verified"
    assert verification.check_multiple_choice_math("Solve 2x + 3 = 11", wrong) == "mismatch"
    assert verification.check_multiple_choice_math("Name a color", right) == "not_applicable"
