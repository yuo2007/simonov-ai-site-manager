"""Generate the WordPress.org directory assets for Simonov AI Site Manager.

Everything is drawn at 4x and downsampled with LANCZOS, because Pillow's
drawing primitives do not antialias on their own.
"""
import math
import os

from PIL import Image, ImageDraw, ImageFont

OUT = os.path.dirname(os.path.abspath(__file__)) + "/assets"
os.makedirs(OUT, exist_ok=True)

S = 4  # supersampling factor

INK = (255, 255, 255)
MUTED = (143, 180, 208)
ACCENT = (58, 154, 217)
ACCENT_DIM = (34, 113, 177)
BG_TOP = (13, 27, 42)
BG_BOTTOM = (20, 41, 61)

BOLD = "C:/Windows/Fonts/segoeuib.ttf"
SEMI = "C:/Windows/Fonts/seguisb.ttf"
REG = "C:/Windows/Fonts/segoeui.ttf"


def font(path, size):
    return ImageFont.truetype(path, size)


def gradient(w, h):
    """Vertical background gradient."""
    img = Image.new("RGB", (1, h))
    px = img.load()
    for y in range(h):
        t = y / max(1, h - 1)
        px[0, y] = tuple(int(BG_TOP[i] + (BG_BOTTOM[i] - BG_TOP[i]) * t) for i in range(3))
    return img.resize((w, h), Image.BILINEAR)


def glow(img, cx, cy, radius, colour, strength=70):
    """Soft radial light, so the flat gradient does not read as a plain slab."""
    layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    steps = 48
    for i in range(steps, 0, -1):
        r = radius * i / steps
        a = int(strength * (1 - i / steps) ** 2)
        d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=colour + (a,))
    return Image.alpha_composite(img.convert("RGBA"), layer)


def constellation(d, cx, cy, r, hub_r, node_r, line_w):
    """A hub with satellites: one site, several clients, explicit links."""
    angles = [-72, -18, 52, 128, 196]
    pts = [(cx + r * math.cos(math.radians(a)), cy + r * math.sin(math.radians(a))) for a in angles]

    for x, y in pts:
        d.line([cx, cy, x, y], fill=ACCENT_DIM, width=line_w)

    # Ring around the hub, drawn as a stroked circle.
    d.ellipse([cx - hub_r * 2.0, cy - hub_r * 2.0, cx + hub_r * 2.0, cy + hub_r * 2.0],
              outline=ACCENT_DIM, width=max(1, line_w))

    for x, y in pts:
        d.ellipse([x - node_r, y - node_r, x + node_r, y + node_r], fill=ACCENT)
    d.ellipse([cx - hub_r, cy - hub_r, cx + hub_r, cy + hub_r], fill=INK)


def banner(w, h, path):
    W, H = w * S, h * S
    img = gradient(W, H)
    img = glow(img, W * 0.80, H * 0.50, H * 0.95, ACCENT, strength=55)
    d = ImageDraw.Draw(img)

    pad = int(W * 0.065)
    # Shrink the title until it clears the constellation on the right.
    size = int(H * 0.215)
    while font(BOLD, size).getlength("Simonov AI Site Manager") > W * 0.66:
        size -= S
    title = font(BOLD, size)
    sub = font(REG, int(H * 0.085))
    tag = font(SEMI, int(H * 0.068))

    y = H * 0.265
    d.text((pad, y), "Simonov AI Site Manager", font=title, fill=INK)
    y += H * 0.255
    d.text((pad, y), "Model Context Protocol server for WordPress", font=sub, fill=MUTED)
    y += H * 0.145
    d.text((pad, y), "READ-ONLY MODE  ·  AUDIT LOG  ·  REVOCABLE ACCESS", font=tag, fill=ACCENT)

    constellation(
        d,
        cx=W * 0.845, cy=H * 0.50, r=H * 0.285,
        hub_r=H * 0.052, node_r=H * 0.030, line_w=max(1, int(H * 0.009)),
    )

    img.convert("RGB").resize((w, h), Image.LANCZOS).save(path, "PNG", optimize=True)
    return path


def icon(size, path):
    Z = size * S
    img = Image.new("RGBA", (Z, Z), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)

    # Rounded square plate, so the mark holds together at list sizes.
    d.rounded_rectangle([0, 0, Z - 1, Z - 1], radius=int(Z * 0.22), fill=BG_TOP)
    img = glow(img, Z * 0.5, Z * 0.42, Z * 0.70, ACCENT, strength=60)
    d = ImageDraw.Draw(img)

    constellation(
        d,
        cx=Z * 0.5, cy=Z * 0.5, r=Z * 0.285,
        hub_r=Z * 0.072, node_r=Z * 0.049, line_w=max(1, int(Z * 0.020)),
    )

    img.resize((size, size), Image.LANCZOS).save(path, "PNG", optimize=True)
    return path


made = [
    banner(772, 250, OUT + "/banner-772x250.png"),
    banner(1544, 500, OUT + "/banner-1544x500.png"),
    icon(128, OUT + "/icon-128x128.png"),
    icon(256, OUT + "/icon-256x256.png"),
]
for p in made:
    print("%-28s %6.1f KB" % (os.path.basename(p), os.path.getsize(p) / 1024))
print("out:", OUT)
