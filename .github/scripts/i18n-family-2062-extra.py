#!/usr/bin/env python3
"""Conservative gettext rewrite of standalone literal echo statements.

Only text nodes of self-contained HTML fragments are localized; HTML tags,
attributes, stored values, scripts, shortcodes and dynamic concatenations remain
unchanged. Ambiguous cases are deliberately reported for human review.
"""
from __future__ import annotations
import re

_LITERAL = r"""'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*" """
_ECHO = re.compile(r"\becho\s+(" + _LITERAL.strip() + r")\s*;", re.M)
_TEXT = re.compile(r"[a-zA-ZÀ-ÿ]{3,}")
_ENTITY = re.compile(r"&(?:\w+|#\d+);")
_PROTECTED = re.compile(r"</?(?:script|style|textarea|pre|code)\b", re.I)

def _php_quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

def wrap_literal_echo(src: str, domain: str) -> tuple[str, int]:
    total = [0]

    def replace(match: re.Match) -> str:
        body = match.group(1)[1:-1]
        if "\\" in body or len(body) > 4000 or _PROTECTED.search(body):
            return match.group(0)
        pieces = re.split(r"(<[^<>]*>)", body)
        if any("<" in piece or ">" in piece for piece in pieces[::2]):
            return match.group(0)
        # Non-HTML text needs to look like user-facing text, not a data token.
        if len(pieces) == 1 and (not body[:1].isupper() and not " " in body):
            return match.group(0)
        expressions = []
        replacements = 0
        for piece in pieces:
            if not piece:
                continue
            if piece.startswith("<") and piece.endswith(">"):
                expressions.append(_php_quote(piece))
                continue
            lead = re.match(r"^\s*", piece).group()
            trail = re.search(r"\s*$", piece).group()
            end = len(piece) - len(trail) if trail else len(piece)
            middle = piece[len(lead):end]
            if (len(middle) < 3 or not _TEXT.search(middle) or
                    _ENTITY.search(middle) or
                    middle.startswith("[") or middle.startswith("{")):
                expressions.append(_php_quote(piece))
                continue
            if lead:
                expressions.append(_php_quote(lead))
            expressions.append("esc_html__( " + _php_quote(middle) + ", '" + domain + "' )")
            if trail:
                expressions.append(_php_quote(trail))
            replacements += 1
        if not replacements:
            return match.group(0)
        total[0] += replacements
        return "echo " + " . ".join(expressions) + ";"

    updated = _ECHO.sub(replace, src)
    return updated, total[0]

if __name__ == "__main__":
    before = "echo '<h2>Banners públicos</h2>'; echo '<input name=\"id\">';"
    after, count = wrap_literal_echo(before, "taxocommerce-campaigns")
    assert count == 1 and "esc_html__(" in after and "name=\"id\"" in after
    print("Static HTML echo gettext smoke: OK")
