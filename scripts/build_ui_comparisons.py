from pathlib import Path
from PIL import Image, ImageDraw, ImageOps


ROOT = Path(__file__).resolve().parents[1]
AUDIT = ROOT / "docs" / "ui-audit"
COMPARISONS = AUDIT / "comparisons"
COMPARISONS.mkdir(parents=True, exist_ok=True)


def fit_screen(image: Image.Image, size: tuple[int, int]) -> Image.Image:
    canvas = Image.new("RGB", size, "white")
    fitted = ImageOps.contain(image.convert("RGB"), size, Image.Resampling.LANCZOS)
    x = (size[0] - fitted.width) // 2
    y = (size[1] - fitted.height) // 2
    canvas.paste(fitted, (x, y))
    return canvas


def build_vod_comparison() -> None:
    source = Image.open(AUDIT / "reference" / "user-vod-create-before.png").convert("RGB")
    implementation = Image.open(AUDIT / "screenshots" / "after-pass-3" / "22-wide-vod-create-1710x740.png").convert("RGB")
    normalized_source = source.resize((1710, 740), Image.Resampling.LANCZOS)

    header = 34
    combined = Image.new("RGB", (3420, 740 + header), "white")
    combined.paste(normalized_source, (0, header))
    combined.paste(implementation, (1710, header))
    draw = ImageDraw.Draw(combined)
    draw.rectangle((0, 0, 1710, header), fill="#173c70")
    draw.rectangle((1710, 0, 3420, header), fill="#245f95")
    draw.text((14, 10), "BEFORE - user screenshot normalized from 2x", fill="white")
    draw.text((1724, 10), "AFTER - local implementation at 1710x740 1x", fill="white")
    combined.save(COMPARISONS / "vod-create-before-after.png", optimize=True)


def build_feifei43_edit_comparison() -> None:
    source = Image.open(AUDIT / "reference" / "user-vod-edit-20260929-before.png").convert("RGB")
    implementation = Image.open(AUDIT / "screenshots" / "feifeicms43-final" / "22b-reference-vod-edit-1107x605.png").convert("RGB")
    normalized_source = source.resize((1107, 605), Image.Resampling.LANCZOS)

    header = 34
    combined = Image.new("RGB", (2214, 605 + header), "white")
    combined.paste(normalized_source, (0, header))
    combined.paste(implementation, (1107, header))
    draw = ImageDraw.Draw(combined)
    draw.rectangle((0, 0, 1107, header), fill="#173c70")
    draw.rectangle((1107, 0, 2214, header), fill="#245f95")
    draw.text((14, 10), "BEFORE - custom editor from user screenshot", fill="white")
    draw.text((1121, 10), "AFTER - FeiFeiCMS 4.3 table structure", fill="white")
    combined.save(COMPARISONS / "vod-edit-feifeicms43-before-after.png", optimize=True)


def build_scenario_split_comparison() -> None:
    before = Image.open(AUDIT / "screenshots" / "feifeicms43-final" / "22b-reference-vod-edit-1107x605.png").convert("RGB")
    video = Image.open(AUDIT / "screenshots" / "scenario-final" / "22b-reference-vod-edit-1107x605.png").convert("RGB")
    scenario = Image.open(AUDIT / "screenshots" / "scenario-final" / "20c-scenario-edit-populated-1440x900.png").convert("RGB")
    scenario = fit_screen(scenario, (1107, 605))

    header = 34
    combined = Image.new("RGB", (3321, 605 + header), "white")
    for index, (screen, label, color) in enumerate([
        (before, "BEFORE - scenario embedded in video editor", "#173c70"),
        (video, "AFTER - video fields and external IDs", "#245f95"),
        (scenario, "AFTER - standalone scenario linked to media", "#173c70"),
    ]):
        x = index * 1107
        combined.paste(screen, (x, header))
        draw = ImageDraw.Draw(combined)
        draw.rectangle((x, 0, x + 1107, header), fill=color)
        draw.text((x + 14, 10), label, fill="white")
    combined.save(COMPARISONS / "scenario-standalone-before-after.png", optimize=True)


def build_contact_sheet(stage: str) -> None:
    source_dir = AUDIT / "screenshots" / stage
    files = sorted(source_dir.glob("*-1440x900.png"))
    thumb_size = (288, 180)
    caption_height = 24
    columns = 4
    rows = (len(files) + columns - 1) // columns
    sheet = Image.new("RGB", (columns * thumb_size[0], rows * (thumb_size[1] + caption_height)), "#e8eef6")
    draw = ImageDraw.Draw(sheet)

    for index, filename in enumerate(files):
        x = (index % columns) * thumb_size[0]
        y = (index // columns) * (thumb_size[1] + caption_height)
        screen = fit_screen(Image.open(filename), thumb_size)
        sheet.paste(screen, (x, y))
        draw.rectangle((x, y + thumb_size[1], x + thumb_size[0], y + thumb_size[1] + caption_height), fill="#173c70")
        draw.text((x + 6, y + thumb_size[1] + 7), filename.stem[:42], fill="white")

    sheet.save(COMPARISONS / f"{stage}-desktop-contact-sheet.png", optimize=True)


def build_contact_sheet_comparison() -> None:
    before = Image.open(COMPARISONS / "before-desktop-contact-sheet.png").convert("RGB")
    after = Image.open(COMPARISONS / "after-pass-3-desktop-contact-sheet.png").convert("RGB")
    header = 34
    combined = Image.new("RGB", (before.width + after.width, max(before.height, after.height) + header), "white")
    combined.paste(before, (0, header))
    combined.paste(after, (before.width, header))
    draw = ImageDraw.Draw(combined)
    draw.rectangle((0, 0, before.width, header), fill="#173c70")
    draw.rectangle((before.width, 0, before.width + after.width, header), fill="#245f95")
    draw.text((14, 10), "BEFORE - complete desktop route set", fill="white")
    draw.text((before.width + 14, 10), "AFTER - complete desktop route set", fill="white")
    combined.save(COMPARISONS / "desktop-all-pages-before-after.png", optimize=True)


build_vod_comparison()
build_feifei43_edit_comparison()
build_scenario_split_comparison()
build_contact_sheet("before")
build_contact_sheet("after-pass-3")
build_contact_sheet_comparison()
