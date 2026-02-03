import sys
import json
import cv2
import pytesseract
import re
import numpy as np
from pdf2image import convert_from_path

# =========================
# CONFIG
# =========================
POPPLER_PATH = r"C:\poppler\Library\bin"
TESSERACT_PATH = r"C:\Program Files\Tesseract-OCR\tesseract.exe"

pytesseract.pytesseract.tesseract_cmd = TESSERACT_PATH

# =========================
# INPUT PDF
# =========================
pdf_path = sys.argv[1]

# =========================
# PDF → IMAGE
# =========================
pages = convert_from_path(
    pdf_path,
    dpi=300,
    poppler_path=POPPLER_PATH
)

img = cv2.cvtColor(np.array(pages[0]), cv2.COLOR_RGB2BGR)

# =========================
# SNAPSHOT CROP
# =========================
h, w, _ = img.shape
snapshot = img[1500:2300, int(w * 0.05):int(w * 0.95)]

# =========================
# PREPROCESS
# =========================
gray = cv2.cvtColor(snapshot, cv2.COLOR_BGR2GRAY)
gray = cv2.adaptiveThreshold(
    gray, 255,
    cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
    cv2.THRESH_BINARY,
    31, 11
)

# =========================
# OCR
# =========================
config = "--psm 6"
text = pytesseract.image_to_string(gray, config=config)

lines = [l.strip() for l in text.splitlines() if l.strip()]

# =========================
# CLEAN VALUE
# =========================
def clean_value(s):
    s = s.replace(' ', '')
    if '%' in s:
        return s
    s = re.sub(r'[^\d]', '', s)
    return s if s else '0'

values = [clean_value(l) for l in lines]

# =========================
# ROW-BASED MAPPING (SAFE)
# =========================
def get_val(idx, default="0"):
    try:
        return values[idx]
    except:
        return default

investment    = int(get_val(0))
switch_in     = get_val(1)
switch_out    = get_val(2)
redemption    = get_val(3)
div_payout    = get_val(4)
current_value = int(get_val(5))

# Net Gain = Current Value - Investment
net_gain = current_value - investment if current_value >= investment else 0

# Extract % from XIRR line (handles "XIRR1.30%")
raw_xirr = get_val(7)
m = re.search(r'\d+(\.\d+)?%', raw_xirr)
xirr = m.group(0) if m else "-"

data = {
    "investment": str(investment),
    "switch_in": switch_in,
    "switch_out": switch_out,
    "redemption": redemption,
    "div_payout": div_payout,
    "current_value": str(current_value),
    "net_gain": str(net_gain),
    "xirr": xirr
}

print(json.dumps(data))