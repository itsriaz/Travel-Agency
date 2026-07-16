from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import ListFlowable, ListItem, Paragraph, SimpleDocTemplate, Spacer


OUTPUT_PATH = r"C:\xampp\htdocs\Travel-Agency\docs\Hostinger-Reimport-Safety-Guide.pdf"


def bullet_list(items, style, left_indent=16):
    return ListFlowable(
        [ListItem(Paragraph(item, style)) for item in items],
        bulletType="bullet",
        leftIndent=left_indent,
        bulletFontName="Helvetica",
        bulletFontSize=10,
    )


styles = getSampleStyleSheet()
styles.add(
    ParagraphStyle(
        name="DocTitle",
        parent=styles["Title"],
        fontName="Helvetica-Bold",
        fontSize=22,
        leading=28,
        textColor=colors.HexColor("#153E75"),
        spaceAfter=10,
        alignment=TA_LEFT,
    )
)
styles.add(
    ParagraphStyle(
        name="DocSubtitle",
        parent=styles["Normal"],
        fontName="Helvetica",
        fontSize=10.5,
        leading=15,
        textColor=colors.HexColor("#4A5568"),
        spaceAfter=14,
    )
)
styles.add(
    ParagraphStyle(
        name="SectionHeading",
        parent=styles["Heading2"],
        fontName="Helvetica-Bold",
        fontSize=13,
        leading=17,
        textColor=colors.HexColor("#1A365D"),
        spaceBefore=10,
        spaceAfter=6,
    )
)
styles.add(
    ParagraphStyle(
        name="Body",
        parent=styles["Normal"],
        fontName="Helvetica",
        fontSize=10.5,
        leading=15,
        textColor=colors.HexColor("#1F2937"),
        spaceAfter=6,
    )
)
styles.add(
    ParagraphStyle(
        name="GuideCode",
        parent=styles["Normal"],
        fontName="Courier",
        fontSize=8.8,
        leading=12,
        textColor=colors.HexColor("#111827"),
        backColor=colors.HexColor("#F7FAFC"),
        borderColor=colors.HexColor("#D9E2EC"),
        borderWidth=0.6,
        borderPadding=6,
        spaceBefore=4,
        spaceAfter=8,
    )
)

doc = SimpleDocTemplate(
    OUTPUT_PATH,
    pagesize=A4,
    leftMargin=18 * mm,
    rightMargin=18 * mm,
    topMargin=18 * mm,
    bottomMargin=18 * mm,
)

story = []
story.append(Paragraph("Hostinger Re-Import Safety Guide", styles["DocTitle"]))
story.append(
    Paragraph(
        "Prepared for the Travel Agency Operations deployment so the production "
        "database can be restored safely and verified immediately after import.",
        styles["DocSubtitle"],
    )
)

story.append(Paragraph("Before Import", styles["SectionHeading"]))
story.append(
    bullet_list(
        [
            "Keep <b>u468486948_Travel_agency (2).sql</b> as the trusted source dump.",
            "Confirm the server <b>.env</b> points to the exact target database name, user, and password.",
            "If possible, avoid importing over a messy or partially repaired production database.",
        ],
        styles["Body"],
    )
)

story.append(Paragraph("Best Option: Fresh Database", styles["SectionHeading"]))
story.append(
    bullet_list(
        [
            "Create a fresh empty database in Hostinger.",
            "Update the production <b>.env</b> with the new database name, username, and password.",
            "Import the dump into the fresh database.",
            "Test the application completely before retiring the old database.",
            "Keep the old database untouched until the new one is confirmed working.",
        ],
        styles["Body"],
    )
)

story.append(Paragraph("If Reusing the Same Database", styles["SectionHeading"]))
story.append(
    bullet_list(
        [
            "Take one last emergency export from Hostinger first.",
            "Drop all existing tables from the target database.",
            "Confirm the database is empty.",
            "Import the dump and wait for the import to finish fully.",
            "Do not close the browser tab or interrupt the import process.",
        ],
        styles["Body"],
    )
)

story.append(Paragraph("Post-Import Verification", styles["SectionHeading"]))
story.append(
    Paragraph(
        "Run this query immediately after import. Expected result: <b>0 rows</b>.",
        styles["Body"],
    )
)
story.append(
    Paragraph(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_KEY, EXTRA<br/>"
        "FROM information_schema.COLUMNS<br/>"
        "WHERE TABLE_SCHEMA = 'u468486948_Travel_agency'<br/>"
        "  AND COLUMN_NAME = 'id'<br/>"
        "  AND COLUMN_KEY = 'PRI'<br/>"
        "  AND EXTRA NOT LIKE '%auto_increment%';",
        styles["GuideCode"],
    )
)

story.append(Paragraph("Additional Verification Queries", styles["SectionHeading"]))
story.append(
    Paragraph(
        "Run the following checks after the zero-row result above:",
        styles["Body"],
    )
)
story.append(
    Paragraph(
        "SHOW TABLES;<br/>"
        "SELECT COUNT(*) FROM migrations;<br/>"
        "SHOW CREATE TABLE travelers;<br/>"
        "SHOW CREATE TABLE auth_login_attempts;<br/>"
        "SHOW CREATE TABLE customer_receipts;",
        styles["GuideCode"],
    )
)

story.append(Paragraph("Test Order", styles["SectionHeading"]))
story.append(
    bullet_list(
        [
            "Open the health URL and confirm the application, database, migrations, and storage all report <b>ok</b>.",
            "Test launcher login.",
            "Create a customer profile.",
            "Create a booking.",
            "Save a service line.",
            "Save a receipt with amount <b>0</b>.",
            "Print the receipt.",
        ],
        styles["Body"],
    )
)

story.append(Paragraph("If Import Fails Midway", styles["SectionHeading"]))
story.append(
    bullet_list(
        [
            "Stop using that database immediately.",
            "Create a fresh empty database.",
            "Import the trusted dump again into the fresh database.",
            "Do not continue repairing random tables one by one unless absolutely necessary.",
        ],
        styles["Body"],
    )
)

story.append(Paragraph("Important Reminder", styles["SectionHeading"]))
story.append(
    Paragraph(
        "The previous production issues were caused by broken schema after import, "
        "especially missing <b>AUTO_INCREMENT</b> on primary key <b>id</b> columns. "
        "A successful import is not considered safe until the verification query returns zero rows.",
        styles["Body"],
    )
)

story.append(Spacer(1, 6))
story.append(
    Paragraph(
        "Project: Travel Agency Operations<br/>Date: 2026-06-13",
        styles["DocSubtitle"],
    )
)

doc.build(story)
print(OUTPUT_PATH)
