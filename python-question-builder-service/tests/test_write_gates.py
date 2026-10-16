

def test_instruction_reaches_the_write_prompt_as_style_only():
    from qb.write import build_prompt

    assert "How the teacher wants these written" not in build_prompt([], [])

    prompt = build_prompt([], [], "Make it harder: apply ideas.")

    assert "Make it harder: apply ideas." in prompt
    assert "style and difficulty only" in prompt
