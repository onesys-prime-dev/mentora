from qb import envfile


def test_pairs_comments_blank_lines_and_export_are_read():
    text = "\n".join([
        "# a comment",
        "",
        "QB_BACKEND=groq",
        "export QB_MODELS = a/b , c/d",
        "no equals sign here",
        "=novalue",
    ])

    assert envfile.parse(text) == {"QB_BACKEND": "groq", "QB_MODELS": "a/b , c/d"}


def test_quotes_are_removed_and_inline_comments_cut_only_when_unquoted():
    text = 'A="with # hash"\nB=\'single\'\nC=plain # trailing\nD=a#b'

    assert envfile.parse(text) == {"A": "with # hash", "B": "single", "C": "plain", "D": "a#b"}


def test_a_value_may_contain_equals_signs():
    assert envfile.parse("QB_BASE_URL=https://x/y?a=1&b=2") == {"QB_BASE_URL": "https://x/y?a=1&b=2"}


def test_the_real_environment_always_wins_and_only_names_are_returned(tmp_path):
    file = tmp_path / ".env"
    file.write_text("GROQ_API_KEY=from-file\nQB_WORKER_ID=local-1\n", encoding="utf-8")
    environ = {"GROQ_API_KEY": "from-process"}

    applied = envfile.load(file, environ)

    assert environ == {"GROQ_API_KEY": "from-process", "QB_WORKER_ID": "local-1"}
    assert applied == ["QB_WORKER_ID"]


def test_a_missing_file_is_not_an_error(tmp_path):
    environ = {}

    assert envfile.load(tmp_path / "nope.env", environ) == []
    assert environ == {}


def test_the_path_can_come_from_qb_env_file(tmp_path, monkeypatch):
    file = tmp_path / "custom.env"
    file.write_text("QB_BACKEND=fake\n", encoding="utf-8")
    monkeypatch.setenv("QB_ENV_FILE", str(file))
    environ = {}

    envfile.load(environ=environ)

    assert environ == {"QB_BACKEND": "fake"}
