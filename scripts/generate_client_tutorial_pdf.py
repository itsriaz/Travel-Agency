from __future__ import annotations

import re
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import HRFlowable, Paragraph, SimpleDocTemplate, Spacer


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs" / "client-user-tutorial.md"
OUTPUT = ROOT / "docs" / "client-user-tutorial.pdf"


def escape_xml(text: str) -> str:
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
    )


def format_inline(text: str) -> str:
    text = escape_xml(text)
    text = re.sub(r"`([^`]+)`", r"<font name='Courier'>\1</font>", text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", text)
    return text


def parse_markdown_blocks(markdown_text: str) -> list[tuple[str, str]]:
    lines = markdown_text.replace("\r\n", "\n").split("\n")
    blocks: list[tuple[str, str]] = []
    paragraph_buffer: list[str] = []

    def flush_paragraph() -> None:
        nonlocal paragraph_buffer
        if paragraph_buffer:
            blocks.append(("paragraph", " ".join(part.strip() for part in paragraph_buffer)))
            paragraph_buffer = []

    for raw_line in lines:
        line = raw_line.rstrip()
        stripped = line.strip()

        if not stripped:
            flush_paragraph()
            continue

        if stripped == "---":
            flush_paragraph()
            blocks.append(("rule", ""))
            continue

        if stripped.startswith("### "):
            flush_paragraph()
            blocks.append(("h3", stripped[4:].strip()))
            continue

        if stripped.startswith("## "):
            flush_paragraph()
            blocks.append(("h2", stripped[3:].strip()))
            continue

        if stripped.startswith("# "):
            flush_paragraph()
            blocks.append(("h1", stripped[2:].strip()))
            continue

        bullet_match = re.match(r"^(\s*)-\s+(.*)$", line)
        if bullet_match:
            flush_paragraph()
            indent = len(bullet_match.group(1))
            kind = "subbullet" if indent >= 2 else "bullet"
            blocks.append((kind, bullet_match.group(2).strip()))
            continue

        number_match = re.match(r"^\s*(\d+)\.\s+(.*)$", stripped)
        if number_match:
            flush_paragraph()
            blocks.append(("numbered", f"{number_match.group(1)}. {number_match.group(2).strip()}"))
            continue

        paragraph_buffer.append(stripped)

    flush_paragraph()
    return blocks


def build_pdf() -> None:
    markdown_text = SOURCE.read_text(encoding="utf-8")
    blocks = parse_markdown_blocks(markdown_text)

    styles = getSampleStyleSheet()
    title_style = ParagraphStyle(
        "TutorialTitle",
        parent=styles["Title"],
        fontName="Helvetica-Bold",
        fontSize=22,
        leading=28,
        textColor=colors.HexColor("#0b2f57"),
        alignment=TA_LEFT,
        spaceAfter=8,
    )
    section_style = ParagraphStyle(
        "TutorialSection",
        parent=styles["Heading2"],
        fontName="Helvetica-Bold",
        fontSize=15,
        leading=20,
        textColor=colors.HexColor("#0b2f57"),
        spaceBefore=8,
        spaceAfter=6,
    )
    subhead_style = ParagraphStyle(
        "TutorialSubhead",
        parent=styles["Heading3"],
        fontName="Helvetica-Bold",
        fontSize=12,
        leading=16,
        textColor=colors.HexColor("#174c7c"),
        spaceBefore=6,
        spaceAfter=3,
    )
    body_style = ParagraphStyle(
        "TutorialBody",
        parent=styles["BodyText"],
        fontName="Helvetica",
        fontSize=10.5,
        leading=15,
        textColor=colors.HexColor("#1f2933"),
        spaceAfter=5,
    )
    bullet_style = ParagraphStyle(
        "TutorialBullet",
        parent=body_style,
        leftIndent=14,
        firstLineIndent=-8,
        bulletIndent=0,
        spaceAfter=3,
    )
    subbullet_style = ParagraphStyle(
        "TutorialSubBullet",
        parent=body_style,
        leftIndent=28,
        firstLineIndent=-8,
        bulletIndent=14,
        spaceAfter=3,
    )
    numbered_style = ParagraphStyle(
        "TutorialNumbered",
        parent=body_style,
        leftIndent=16,
        firstLineIndent=-12,
        bulletIndent=0,
        spaceAfter=3,
    )

    story = [
        Paragraph("Travel Agency Operations", title_style),
        Paragraph("Client User Tutorial", section_style),
        Spacer(1, 2 * mm),
    ]

    for block_type, content in blocks:
        formatted = format_inline(content)
        if block_type == "h1":
            continue
        if block_type == "h2":
            story.append(Spacer(1, 1.5 * mm))
            story.append(Paragraph(formatted, section_style))
        elif block_type == "h3":
            story.append(Paragraph(formatted, subhead_style))
        elif block_type == "paragraph":
            story.append(Paragraph(formatted, body_style))
        elif block_type == "bullet":
            story.append(Paragraph(formatted, bullet_style, bulletText="•"))
        elif block_type == "subbullet":
            story.append(Paragraph(formatted, subbullet_style, bulletText="•"))
        elif block_type == "numbered":
            number, text = formatted.split(" ", 1)
            story.append(Paragraph(text, numbered_style, bulletText=number))
        elif block_type == "rule":
            story.append(Spacer(1, 1 * mm))
            story.append(HRFlowable(width="100%", thickness=0.5, color=colors.HexColor("#c6d3e1")))
            story.append(Spacer(1, 2 * mm))

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    doc = SimpleDocTemplate(
        str(OUTPUT),
        pagesize=A4,
        leftMargin=16 * mm,
        rightMargin=16 * mm,
        topMargin=16 * mm,
        bottomMargin=16 * mm,
        title="Travel Agency Operations - Client User Tutorial",
        author="Codex",
    )
    doc.build(story)


if __name__ == "__main__":
    build_pdf()
    print(f"Created PDF: {OUTPUT}")
