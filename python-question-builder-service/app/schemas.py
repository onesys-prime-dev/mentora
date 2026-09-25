from typing import Literal, Optional

from pydantic import BaseModel, Field, model_validator

QuestionType = Literal["Multiple Choice", "Short Answer", "Essay", "File Upload"]


class ChoiceIn(BaseModel):
    text: str
    is_correct: bool = False


MathCheck = Literal["verified", "mismatch", "not_applicable"]


class GeneratedQuestion(BaseModel):
    question_text: str
    question_type: QuestionType
    points: float
    is_required: bool = True
    choices: list[ChoiceIn] = Field(default_factory=list)
    # Short Answer only: the expected answer first, then equivalent forms
    # (acronym / full term / alternate spelling). The app auto-grades a
    # student's answer against these; empty for every other type.
    accepted_answers: list[str] = Field(default_factory=list)
    # Populated by our own deterministic sympy check after generation, never
    # by the model — see verification.py. Never trust the model's own claim
    # about whether its answer is correct.
    math_check: MathCheck = "not_applicable"


class GenerateRequest(BaseModel):
    topic: Optional[str] = None
    source_text: Optional[str] = None
    count: int = Field(default=10, ge=1)
    type_mix: dict[str, int] = Field(
        default_factory=lambda: {"multiple_choice": 10}
    )
    points_per_question: Optional[float] = None
    # Short titles only (not full question text) of questions already
    # generated in this batch, so the model avoids repeating itself without
    # bloating the prompt across many chunked calls.
    recent_questions: list[str] = Field(default_factory=list)

    @model_validator(mode="after")
    def _require_a_source(self):
        if not self.topic and not self.source_text:
            raise ValueError("Provide at least one of topic or source_text.")
        return self


class GenerateResponse(BaseModel):
    questions: list[GeneratedQuestion]
    model_used: str


class ExtractTextResponse(BaseModel):
    text: str
    used_ocr: bool
    pages: int
