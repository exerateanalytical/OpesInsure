"""Side-by-side design QA sheets.

For every screen in scripts/qa-screens.json, puts the design image (left)
next to the app captures at each width (docs/qa/shots/<name>@<w>.png),
all scaled to the same height, and writes docs/qa/compare/<name>.png.

    python scripts/qa-compose.py [filter]
"""
import json
import os
import sys

from PIL import Image, ImageDraw

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SCREENS = json.load(open(os.path.join(ROOT, "scripts", "qa-screens.json"), encoding="utf-8"))
SHOTS = os.path.join(ROOT, "docs", "qa", "shots")
OUT = os.path.join(ROOT, "docs", "qa", "compare")
os.makedirs(OUT, exist_ok=True)
H = 1400  # every panel is scaled to this height
filt = sys.argv[1] if len(sys.argv) > 1 else ""


def load(path, crop_phone_height=None):
    im = Image.open(path).convert("RGB")
    if crop_phone_height:
        # App capture: keep the first `crop_phone_height` screens' worth of content.
        im = im.crop((0, 0, im.width, min(im.height, crop_phone_height)))
    return im.resize((max(1, int(im.width * H / im.height)), H), Image.LANCZOS)


for s in SCREENS:
    exact = any(x["name"] == filt for x in SCREENS)
    if filt and (s["name"] != filt if exact else filt not in s["name"]):
        continue
    panels = []
    labels = []
    design = os.path.join(ROOT, "app screens", s["design"])
    if os.path.exists(design):
        panels.append(load(design))
        labels.append("DESIGN " + s["design"])
    for w in (360, 390, 430):
        shot = os.path.join(SHOTS, f"{s['name']}@{w}.png")
        if os.path.exists(shot):
            # Compare the same "screen length" as the design (phone ratio ~2.16).
            panels.append(load(shot, crop_phone_height=int(w * 2.16 * 1.6)))
            labels.append(f"APP {w}dp")
    if len(panels) < 2:
        continue
    gap = 24
    width = sum(p.width for p in panels) + gap * (len(panels) + 1)
    sheet = Image.new("RGB", (width, H + 70), "white")
    draw = ImageDraw.Draw(sheet)
    x = gap
    for p, label in zip(panels, labels):
        sheet.paste(p, (x, 60))
        draw.text((x, 20), label, fill=(20, 30, 70))
        x += p.width + gap
    sheet.save(os.path.join(OUT, f"{s['name']}.png"), optimize=True)
    print("wrote", s["name"])
