"""
PROZONE Display - originalni geometrijski font prema PROZONE logu.

Proporcije su izmjerene iz loga (visina velikih slova 175 px -> 700 jedinica):
  vertikalna linija 45 px -> 180, horizontalna 38 px -> 152, nagib ~10 stepeni.
Slova su crtana od nule (pravougaonici, zaobljeni ugaonici, dijagonale),
bez preuzimanja tudjih fontova.

Pokretanje:  python3 build_prozone_font.py <izlazni_folder>
Izlaz:       ProzoneDisplay.ttf/.woff2 (klasicno E) i ProzoneLogo.ttf/.woff2 (E kao u logu)
"""
import math
import sys
from pathlib import Path

from fontTools.fontBuilder import FontBuilder
from fontTools.pens.ttGlyphPen import TTGlyphPen
from shapely import affinity
from shapely.geometry import LineString, MultiPolygon, Polygon, box
from shapely.geometry.polygon import orient
from shapely.ops import unary_union

# ---------------------------------------------------------------- parametri
CAP = 700          # visina velikih slova
S = 180            # debljina vertikalnih linija
H = 152            # debljina horizontalnih linija
RO = 220           # vanjsko zaobljenje (izmjereno na O iz loga)
RI = 110           # unutrasnje zaobljenje (izmjereno na O iz loga)
SLANT = 0.175      # nagib izmjeren iz loga (tan ~9.9 stepeni)
SB = 55            # razmak lijevo/desno od slova
MID = CAP / 2
DIAG = 170         # horizontalna debljina kosih linija (mjerilo: slovo A)
UPM = 1000


# ---------------------------------------------------------------- pomocne
def rr(x0, y0, x1, y1, tl=0, tr=0, br=0, bl=0, n=10):
    """Pravougaonik sa zaobljenim pojedinacnim uglovima (radijusi ograniceni velicinom)."""
    lim = min(x1 - x0, y1 - y0) / 2 - 0.5
    tl, tr, br, bl = (min(r, lim) for r in (tl, tr, br, bl))
    pts = []

    def arc(cx, cy, r, a0, a1):
        if r <= 0:
            return [(cx, cy)]
        return [(cx + r * math.cos(math.radians(a0 + (a1 - a0) * i / n)),
                 cy + r * math.sin(math.radians(a0 + (a1 - a0) * i / n))) for i in range(n + 1)]

    pts += arc(x0 + bl, y0 + bl, bl, 180, 270) if bl else [(x0, y0)]
    pts += arc(x1 - br, y0 + br, br, 270, 360) if br else [(x1, y0)]
    pts += arc(x1 - tr, y1 - tr, tr, 0, 90) if tr else [(x1, y1)]
    pts += arc(x0 + tl, y1 - tl, tl, 90, 180) if tl else [(x0, y1)]
    return Polygon(pts)


def allr(x0, y0, x1, y1, r):
    return rr(x0, y0, x1, y1, r, r, r, r)


def stroke(p0, p1, w):
    """Dijagonalna linija debljine w (mjereno horizontalno) izmedju dvije tacke."""
    (x0, y0), (x1, y1) = p0, p1
    return Polygon([(x0 - w / 2, y0), (x0 + w / 2, y0), (x1 + w / 2, y1), (x1 - w / 2, y1)])


def U(*g):
    return unary_union(list(g))


def band(geom, y0=0, y1=CAP, x0=-2000, x1=4000):
    return geom.intersection(box(x0, y0, x1, y1))


# ------------------------------------------------- slova prenesena iz loga
# P R O Z N i E (logo) su konture iz originalnog PROZONE loga (crtao vlasnik),
# vidi trace_logo.py -> logo_glyphs.json. Ivice se poravnavaju na 0 i CAP.
import json
from shapely.geometry import shape as _shape

_LOGO = json.load(open(Path(__file__).with_name('logo_glyphs.json')))


def _snap(geom):
    """Poravnaj slovo tacno na baseline (0) i visinu velikih slova (CAP) - skaliranjem, bez stepenika."""
    y0, y1 = geom.bounds[1], geom.bounds[3]
    g = affinity.translate(geom, 0, -y0)
    return affinity.scale(g, 1, CAP / (y1 - y0), origin=(0, 0)).buffer(0)


def logo_glyph(k):
    d = _LOGO[k]
    return _snap(_shape(d['geom'])), d['width']


G = {}  # ime -> (geometrija, sirina)


def ring(W, h=CAP):
    return allr(0, 0, W, h, RO).difference(allr(S, H, W - S, h - H, RI))


def g_A():
    W = 880                                   # sirina kao N
    top = 200                                 # ravan vrh
    xl = W / 2 - top / 2 + DIAG / 2           # gornji kraj sredisnje linije lijevog kraka
    left = stroke((DIAG / 2, 0), (xl, CAP), DIAG)
    right = stroke((W - DIAG / 2, 0), (W - xl, CAP), DIAG)
    hull = Polygon([(0, 0), (W / 2 - top / 2, CAP), (W / 2 + top / 2, CAP), (W, 0)])
    bar = box(0, 210, W, 210 + H).intersection(hull)
    return band(U(left, right, bar, box(W / 2 - top / 2 + 20, CAP - H, W / 2 + top / 2 - 20, CAP))), W


def g_B():
    # B u stilu P iz loga. Donji luk = luk slova P (sirina 780, zaobljenja 235/205 i 48/52),
    # proporcionalno spusten na visinu polovine. Gornji luk: isti oblik luka kao P, 30 jedinica uzi.
    W = 780
    m0, m1 = MID - 76, MID + 76            # srednja precka
    k = m1 / (CAP - 226)                   # odnos visine donjeg luka i luka P
    lo = rr(0, 0, W, m1, tr=235 * k, br=205 * k).difference(rr(181, 151, W - S, m0, tr=48, br=52))
    up = rr(0, m0, W - 30, CAP, tr=235 * k, br=205 * k).difference(rr(181, m1, W - 30 - S, CAP - 151, tr=48, br=52))
    return U(up, lo, box(0, 0, 181, CAP)), W


def g_C():
    # krajevi C odsjeceni ravno (bez zaobljenog ugla na desnoj strani)
    # mjere kao O iz loga (sirina 887, zaobljenja 222 / 109, rupa 181..707 x 152..550)
    W = 887
    return rr(0, 0, W, CAP, tl=222, bl=222).difference(rr(181, 152, W + 1, 550, tl=109, bl=109)), W


def g_D():
    # desni luk identican slovu O iz loga (sirina 887, zaobljenja 222 / 109, rupa 181..707 x 152..550)
    W = 887
    return rr(0, 0, W, CAP, tr=222, br=222).difference(rr(181, 152, 707, 550, tr=109, br=109)), W


def e_std(W=772):          # sirina kao E u logu
    return U(box(0, 0, S, CAP), box(0, 0, W, H), box(0, MID - H / 2, W * 0.95, MID + H / 2), box(0, CAP - H, W, CAP))


def g_E():
    return e_std(), 772


def g_E_logo_geo():
    W = 776
    gap = (CAP - 3 * H) / 2
    return U(box(0, 0, W, H), box(W * 0.03, H + gap, W, 2 * H + gap), box(0, CAP - H, W, CAP)), W


def g_F():
    W = 730
    return U(box(0, 0, S, CAP), box(0, MID - H / 2, W * 0.95, MID + H / 2), box(0, CAP - H, W, CAP)), W


def g_G():
    # oblik i desni luk kao O iz loga; gornji kraj odsjecen ravno kao kod C
    W = 887
    g = rr(0, 0, W, CAP, tl=222, bl=222, br=222).difference(rr(181, 152, 707, 550, tl=109, bl=109, br=109))
    b0, b1 = 290, 290 + H                  # precka G (152)
    g = g.difference(box(706, b1, W + 1, 550))
    return U(g, box(W * 0.48, b0, 708, b1)), W


def g_H():
    W = 780
    return U(box(0, 0, S, CAP), box(W - S, 0, W, CAP), box(0, MID - H / 2, W, MID + H / 2)), W


def g_I():
    return box(0, 0, S, CAP), S


def g_J():
    W = 660
    o = rr(0, 0, W, CAP, bl=RO, br=RO).difference(rr(S, H, W - S, CAP + 1, bl=RI, br=RI))
    return o.difference(box(-1, 270, S + 1, CAP + 1)), W


def g_K():
    # Varijanta 1 (odabrao vlasnik): gornji krak produzen do kraja u stablo (bez stepenika),
    # donji krak izlazi sa sredisnje linije gornjeg. Oba kraka okomite debljine PERP (~155),
    # kao ostale kose linije (A, V, X, Y ~154; M, N, Z ~160).
    W = 780
    PERP = 155
    y0 = 160
    UP = 190
    for _ in range(8):                             # horizontalna debljina gornjeg kraka za okomitu PERP
        x0, x1 = S - UP / 2, W - UP / 2
        UP = PERP * math.hypot(x1 - x0, CAP - y0) / (CAP - y0)
    x0, x1 = S - UP / 2, W - UP / 2
    up = stroke((x0, y0), (x1, CAP), UP)
    up = up.intersection(box(0, 0, W, CAP)).difference(box(-10, -10, 0, CAP + 10))
    t = 0.42                                       # donji krak polazi sa sredisnje linije gornjeg
    xs, ys = x0 + t * (x1 - x0), y0 + t * (CAP - y0)
    DN = DIAG
    for _ in range(8):
        DN = PERP * math.hypot((W - DN / 2) - xs, ys) / ys
    dn = stroke((xs, ys), (W - DN / 2, 0), DN)
    return U(box(0, 0, S, CAP), up, dn).intersection(box(0, 0, W, CAP)), W


def g_L():
    return U(box(0, 0, S, CAP), box(0, 0, 690, H)), 690


def g_M():
    # M u stilu N iz loga: stabla 181, kose linije iste okomite debljine kao N (~160),
    # krecu iz gornjih uglova kao kod N i spajaju se na baseline u sredini.
    W = 1100
    T = 160                                        # okomita debljina kose linije (kao N)
    top_c, bot_c = 172, W / 2                      # sredisnja linija lijeve kose linije: vrh / dno
    w = T * math.sqrt(1 + ((bot_c - top_c) / CAP) ** 2)   # horizontalna debljina
    left = stroke((top_c, CAP), (bot_c, 0), w)
    right = stroke((W - top_c, CAP), (W - bot_c, 0), w)
    stems = U(box(0, 0, 181, CAP), box(W - 181, 0, W, CAP))
    return U(stems, left, right).intersection(box(0, 0, W, CAP)), W


def g_N_geo():
    W = 860
    diag = Polygon([(0, CAP), (S * 1.65, CAP), (W, 0), (W - S * 1.65, 0)])
    return U(box(0, 0, S, CAP), box(W - S, 0, W, CAP), diag), W


def g_O_geo():
    W = 860
    return ring(W), W


def g_P_geo():
    W = 680
    bowl = rr(0, 270, W, CAP, tr=RO, br=RO).difference(rr(S, 270 + H, W - S, CAP - H, tr=RI, br=RI))
    return U(box(0, 0, S, CAP), bowl), W


def g_Q():
    W = 887
    tail = Polygon([(W * 0.50, H + 60), (W * 0.50 + S * 1.1, H + 60), (W + 40, -90), (W + 40 - S * 1.1, -90)])
    return U(ring(W), tail), W + 40


def g_R_geo():
    W = 700
    p, _ = g_P()
    leg = Polygon([(W * 0.40, 300), (W * 0.40 + S * 1.15, 300), (W + 60, 0), (W + 60 - S * 1.15, 0)])
    return U(p, leg), W + 60


def g_S():
    # Lukovi kao kod P iz loga (vanjski 235/205, unutrasnji 48/52, skalirano na pola visine),
    # krajevi (gore desno, dolje lijevo) ravno odsjeceni kao kod C. Tackasto simetricno.
    W = 800
    m0, m1 = MID - 76, MID + 76                # srednja greda
    k = m1 / (CAP - 226)
    A, B = 235 * k, 205 * k                    # luk P: gornji / donji vanjski radijus
    # donji dio: desni luk kao P, kraj dolje lijevo odsjecen
    lo = rr(0, 0, W, m1, tr=A, br=B).difference(rr(-1, H, W - S, m0, tr=48, br=52))
    lo = lo.difference(box(-1, m0 - 1, S, m1 + 1))          # lijevi kraj srednje grede daje gornji dio
    # gornji dio: lijevi luk (zarotiran luk P), kraj gore desno odsjecen
    up = rr(0, m0, W, CAP, tl=B, bl=A).difference(rr(S, m1, W + 1, CAP - H, tl=52, bl=48))
    up = up.difference(box(S, m0 - 1, W + 1, m1))            # srednju gredu desno daje donji dio
    up = up.difference(box(W * 0.90, CAP - H - 1, W + 1, CAP + 1))   # gornja greda skracena na 90%
    return U(lo, up), W


def g_T():
    W = 760
    return U(box(0, CAP - H, W, CAP), box(W / 2 - S / 2, 0, W / 2 + S / 2, CAP)), W


def g_U():
    # sirina i zaobljenja kao O iz loga (887; 222 / 109)
    W = 887
    return rr(0, 0, W, CAP, bl=222, br=222).difference(rr(181, 152, 707, CAP + 1, bl=109, br=109)), W


def g_V():
    W = 820
    v = U(stroke((DIAG / 2, CAP), (W / 2, 0), DIAG), stroke((W - DIAG / 2, CAP), (W / 2, 0), DIAG))
    return band(v).intersection(box(0, 0, W, CAP)), W


def g_W():
    W = 1120
    w = DIAG
    parts = [stroke((w / 2, CAP), (W * 0.26, 0), w), stroke((W * 0.5, CAP), (W * 0.26, 0), w),
             stroke((W * 0.5, CAP), (W * 0.74, 0), w), stroke((W - w / 2, CAP), (W * 0.74, 0), w)]
    return band(U(*parts)).intersection(box(0, 0, W, CAP)), W


def g_X():
    # kose linije iste okomite debljine kao kod V (V: horizontalno DIAG, nagib (W_V/2 - DIAG/2) / CAP)
    W = 820
    wv, Wv = DIAG, 820
    perp = wv * CAP / math.hypot(CAP, Wv / 2 - wv / 2)          # okomita debljina linije V (~154)
    w = DIAG
    for _ in range(5):                                           # horizontalna debljina za isti okomiti presjek
        w = perp * math.hypot(CAP, W - w) / CAP
    return band(U(stroke((w / 2, CAP), (W - w / 2, 0), w), stroke((W - w / 2, CAP), (w / 2, 0), w))), W


def g_Y():
    # krakovi iste okomite debljine kao kod X i V (~154)
    W = 800
    y0 = 300
    perp = DIAG * CAP / math.hypot(CAP, 820 / 2 - DIAG / 2)   # okomita debljina V/X
    w = DIAG
    for _ in range(5):
        w = perp * math.hypot(CAP - y0, W / 2 - w / 2) / (CAP - y0)
    v = U(stroke((w / 2, CAP), (W / 2, y0), w), stroke((W - w / 2, CAP), (W / 2, y0), w))
    # blagi prelaz sa spoja krakova (sirina w) na stablo (S), bez stepenika
    neck = Polygon([(W / 2 - S / 2, y0 - 70), (W / 2 + S / 2, y0 - 70), (W / 2 + w / 2, y0), (W / 2 - w / 2, y0)])
    return band(U(v, neck, box(W / 2 - S / 2, 0, W / 2 + S / 2, y0 - 69))).intersection(box(0, 0, W, CAP)), W


def g_Z_geo():
    W = 840
    diag = Polygon([(W - S * 1.5, CAP - H), (W, CAP - H), (S * 1.5, H), (0, H)])
    return U(box(0, CAP - H, W, CAP), box(0, 0, W, H), diag), W


# Slova iz PROZONE loga, rekonstruisana prema izmjerenim tackama (trace_logo.py),
# s pravim linijama i kruznim lukovima. Uspravne mjere (bez nagiba), jedinice fonta.

def _bowl(W=780, logo=False):
    """Luk P/R: otvoren s lijeve strane (stablo ide samo do donje precke luka).
    logo=True: donja precka kao u logu (226..377); inace u ravni srednje grede F/E (274..426)."""
    b0, b1 = (226, 377) if logo else (274, 426)
    t0 = 549                   # gornja precka luka (549..700)
    outer = rr(0, b0, W, CAP, tr=235, br=205)
    counter = rr(-1, b1, W - S, t0, tr=48, br=52)
    return outer.difference(counter)


def g_P(logo=False):
    W = 780
    return U(_bowl(W, logo), box(0, 0, 182, 230 if logo else 280)), W


def g_R(logo=False):
    W = 780
    if logo:   # kao u logu
        leg = Polygon([(417, 280), (610, 280), (776, 0), (600, 0)])
        return U(_bowl(W, True), box(0, 0, 182, 230), leg), W
    leg = Polygon([(384, 330), (580, 330), (776, 0), (600, 0)])   # noga ulazi u donju precku luka (bez zareza)
    return U(_bowl(W), box(0, 0, 182, 280), leg), W


def g_O():
    W = 887
    return allr(0, 0, W, CAP, 222).difference(allr(181, 152, 707, 550, 109)), W


def g_Z():
    W = 849
    diag = Polygon([(0, 152), (292, 152), (W, 549), (576, 549)])
    return U(box(0, 0, W, 152), box(0, 549, W, CAP), diag), W


def g_N():
    # kosa linija stanjena na debljinu kose linije Z (okomito ~160; u logu ~170)
    W = 878
    d = 6.5
    diag = Polygon([(65 + d, CAP), (279 - d, CAP), (813 - d, 0), (592 + d, 0)])
    return U(box(0, 0, 181, CAP), box(W - 181, 0, W, CAP), diag).intersection(box(0, 0, W, CAP)), W


def g_E_logo():
    W = 772
    return U(box(0, 0, W, 151), box(0, 277, W - 21, 427), box(0, 549, W - 2, CAP)), W


# ------------------------------------------------------------ dijakritici
def caron(W, y=CAP + 70):
    w = 120
    return U(stroke((W / 2 - 150, y + 150), (W / 2, y), w), stroke((W / 2 + 150, y + 150), (W / 2, y), w)).intersection(
        box(-500, y, 3000, y + 150))


def acute(W, y=CAP + 70):
    return Polygon([(W / 2 - 40, y), (W / 2 + 90, y), (W / 2 + 260, y + 150), (W / 2 + 130, y + 150)])


def with_mark(fn, mark):
    g, W = fn()
    return U(g, mark(W)), W


def g_Dj():
    g, W = g_D()
    return U(g, box(-70, MID - 60, S + 150, MID + 60)), W


# ------------------------------------------------------------------ brojevi
DW = 680


# Brojevi u stilu trenutnih slova: lukovi kao P/S/B (vanjski 235/205 i unutrasnji 48/52,
# skalirano na pola visine), krajevi ravno odsjeceni kao C/S, 0 kao uzi O iz loga.
_DM0, _DM1 = MID - 76, MID + 76                       # srednja greda brojeva (kao S/B)
_DK = _DM1 / (CAP - 226)
_DA, _DB = 235 * _DK, 205 * _DK                        # gornji / donji vanjski radijus luka P


def _bowl_r(W, y0, y1, open_left=True):
    """Desni luk (kao P) od y0 do y1; rupa otvorena lijevo (kraj odsjecen) ili zatvorena."""
    outer = rr(0, y0, W, y1, tr=_DA, br=_DB)
    x0 = -1 if open_left else S
    return outer.difference(rr(x0, y0 + H, W - S, y1 - H, tr=48, br=52))


def n_0():
    W = DW
    return allr(0, 0, W, CAP, 222).difference(allr(181, 152, W - 181, 548, 109)), W


def n_1():
    # uski (proporcionalni) 1 - odabrao vlasnik (varijanta B): normalni razmaci u tekstu
    W = 420
    return U(box(W - S, 0, W, CAP), Polygon([(W - S, CAP), (W - S, CAP - H - 20), (40, CAP - H - 120), (40, CAP - 120)])), W


def n_2():
    W = DW
    top = _bowl_r(W, _DM0, CAP)                         # gornji luk, kraj gore lijevo odsjecen
    top = top.difference(box(-1, _DM0 - 1, S, _DM1 + 1))   # lijevi kraj srednje grede daje donji dio
    # donji dio: srednja greda se lukom spusta niz lijevu stranu na donju horizontalnu gredu
    low = rr(0, 0, W, _DM1, tl=_DA).difference(rr(S, H, W + 1, _DM0, tl=48))
    low = low.difference(box(S, _DM0, W + 1, _DM1 + 1))    # desni dio srednje grede daje gornji luk
    return U(top, low), W


def n_3():
    W = DW
    return U(_bowl_r(W, _DM0, CAP), _bowl_r(W, 0, _DM1)), W


def n_4():
    # klasican 4 (varijanta B): kosa linija od vrha stabla do grede
    W = DW
    st = box(W - S - 40, 0, W - 40, CAP)
    bar = box(0, 200, W, 200 + H)
    xt = W - S - 30
    d = Polygon([(xt - 230, CAP), (xt, CAP), (230, 200 + H), (0, 200 + H)])
    return U(st, bar, d).intersection(box(0, 0, W, CAP)), W


def n_5():
    W = DW
    low = _bowl_r(W, 0, _DM1)                           # donji luk kao S, kraj dolje lijevo odsjecen
    return U(low, box(0, _DM0, S, CAP), box(0, CAP - H, W * 0.90, CAP)), W   # gornja greda 90% kao S


def n_6(sharp_bar=True):
    # sharp_bar=True (6 i 9): greda zatvara rupu pod pravim uglom, bez unutrasnjeg luka
    W = DW
    ct = 0 if sharp_bar else 52
    cr = 0 if sharp_bar else 48
    low = rr(0, 0, W, _DM1, tr=_DA, br=_DB, bl=_DA).difference(rr(S, H, W - S, _DM0, tl=ct, tr=cr, br=52, bl=48))
    up = rr(0, _DM0, W, CAP, tl=_DB).difference(rr(S, _DM1, W + 1, CAP - H, tl=52))
    up = up.difference(box(W * 0.90, CAP - H - 1, W + 1, CAP + 1))             # gornja greda 90% kao S
    up = up.difference(box(S, _DM0 - 1, W + 1, _DM1))                           # srednju gredu daje donji luk
    return U(low, up), W


def n_7():
    W = DW
    PERP = 155
    xb = W * 0.40                                        # donji kraj sredisnje linije
    w = 225
    for _ in range(8):
        xt = W - w / 2
        w = PERP * math.hypot(xt - xb, CAP - H) / (CAP - H)
    diag = stroke((W - w / 2, CAP - H), (xb, 0), w)
    return U(box(0, CAP - H, W, CAP), diag).intersection(box(0, 0, W, CAP)), W


def n_8():
    W = DW
    # varijanta B: uglovi rupa uz srednju gredu ostri, ostali zaobljeni
    up = rr(15, _DM0, W - 15, CAP, tl=_DA, tr=_DA, bl=_DB, br=_DB).difference(
        rr(S + 15, _DM1, W - 15 - S, CAP - H, tl=48, tr=48, bl=0, br=0))
    lo = rr(0, 0, W, _DM1, tl=_DA, tr=_DA, bl=_DB, br=_DB).difference(
        rr(S, H, W - S, _DM0, tl=0, tr=0, bl=52, br=52))
    return U(up, lo), W


def n_9():
    g, W = n_6(sharp_bar=True)
    return affinity.scale(g, -1, -1, origin=(W / 2, CAP / 2)), W


# ---------------------------------------------------------- interpunkcija
def p_space():
    return Polygon(), 260


def p_period():
    return box(0, 0, S, H + 10), S


def p_comma():
    return U(box(0, 0, S, H + 10), Polygon([(0, 0), (S, 0), (S * 0.55, -140), (-40, -140)])), S


def p_colon():
    return U(box(0, 0, S, H + 10), box(0, 380, S, 380 + H + 10)), S


def p_semicolon():
    c, _ = p_comma()
    return U(c, box(0, 380, S, 380 + H + 10)), S


def p_exclam():
    return U(box(0, 0, S, H + 10), box(0, 230, S, CAP)), S


def p_question():
    W = 600
    top = rr(0, MID - H / 2, W, CAP, tr=RO, br=RI).difference(rr(-1, MID + H / 2, W - S, CAP - H, tr=RI))
    top = top.difference(box(-1, MID - H / 2 - 1, W * 0.30, MID + H / 2 + 1))
    return U(top, box(W * 0.30, 230, W * 0.30 + S, MID + H / 2), box(W * 0.30, 0, W * 0.30 + S, H + 10)), W


def p_hyphen():
    return box(0, 260, 380, 260 + H), 380


def p_plus():
    W = 560
    return U(box(0, 260, W, 260 + H), box(W / 2 - H / 2, 60, W / 2 + H / 2, 600)), W


def p_equal():
    W = 560
    return U(box(0, 130, W, 130 + H), box(0, 400, W, 400 + H)), W


def p_slash():
    W = 520
    return band(stroke((S * 0.65, -40), (W - S * 0.65, CAP + 40), S * 1.3), -40, CAP + 40).intersection(
        box(0, -40, W, CAP + 40)), W


def p_percent():
    W = 900
    a = allr(0, 400, 300, CAP, 60).difference(allr(90, 400 + 90, 210, CAP - 90, 20))
    b = allr(W - 300, 0, W, 300, 60).difference(allr(W - 210, 90, W - 90, 210, 20))
    return U(a, b, band(stroke((260, 0), (W - 260, CAP), 150))), W


def p_quotesingle():
    return box(0, CAP - 260, S * 0.8, CAP), S * 0.8


def p_quotedbl():
    return U(box(0, CAP - 260, S * 0.8, CAP), box(S * 0.8 + 90, CAP - 260, 2 * S * 0.8 + 90, CAP)), 2 * S * 0.8 + 90


def p_parenleft():
    W = 330
    return rr(0, -120, W + 400, CAP + 120, tl=RO + 40, bl=RO + 40).difference(
        rr(S, -120 + H, W + 401, CAP + 120 - H, tl=RI, bl=RI)).intersection(box(0, -130, W, CAP + 130)), W


def p_parenright():
    g, W = p_parenleft()
    return affinity.scale(g, -1, 1, origin=(W / 2, 0)), W


def p_euro():
    W = 820
    c, _ = g_C()
    c = affinity.translate(c, 40, 0)
    return U(c, box(0, MID + 30, W * 0.62, MID + 30 + H * 0.7), box(0, MID - 30 - H * 0.7, W * 0.62, MID - 30)), W + 40


def p_numbersign():
    W = 760
    bars = U(box(0, 210, W, 210 + H * 0.8), box(0, 440, W, 440 + H * 0.8))
    st = U(band(stroke((W * 0.30, 0), (W * 0.38, CAP), S * 0.9)), band(stroke((W * 0.62, 0), (W * 0.70, CAP), S * 0.9)))
    return U(bars, st).intersection(box(0, 0, W, CAP)), W


def p_asterisk():
    W = 520
    c = (W / 2, 520)
    arms = [affinity.rotate(box(c[0] - 55, c[1], c[0] + 55, c[1] + 200), a, origin=c) for a in (0, 60, 120, 180, 240, 300)]
    return U(*arms), W


def p_underscore():
    return box(0, -150, 640, -150 + H * 0.8), 640


def p_ampersand():
    # pojednostavljen geometrijski &: osmica otvorena desno + rep
    W = 820
    o = allr(0, 0, W - 120, CAP, RO).difference(allr(S, H, W - 120 - S, MID - H / 2, RI))
    o = o.difference(allr(S + 10, MID + H / 2, W - 120 - S - 10, CAP - H, RI))
    o = o.difference(box(W - 120 - S - 1, H, W, MID - H / 2))
    return U(o, box(W - 120 - S, MID - H / 2 - 10, W, MID - H / 2 + H * 0.8)), W


def p_at():
    W = 880
    o = allr(0, -60, W, CAP + 60, RO + 40).difference(allr(H, -60 + H, W - H, CAP + 60 - H, RI))
    o = o.difference(box(W * 0.55, -61, W * 0.80, -60 + H + 1))
    inner = allr(250, 160, W - H - 40, 540, 60).difference(allr(250 + S, 160 + H * 0.8, W - H - 40 - S, 540 - H * 0.8, 20))
    return U(o, inner), W


# ------------------------------------------------------------------- mapa
def build_map(logo_e):
    m = {}
    caps = {c: globals()['g_' + c] for c in 'ABCDFGHIJKLMNOPQRSTUVWXYZ'}
    caps['E'] = g_E_logo if logo_e else g_E
    if logo_e:   # Prozone Logo: P i R tacno kao u logu
        caps['P'] = lambda: g_P(True)
        caps['R'] = lambda: g_R(True)
    for c, fn in caps.items():
        m[c] = fn
    m['Č'] = lambda: with_mark(g_C, caron)
    m['Ć'] = lambda: with_mark(g_C, acute)
    m['Š'] = lambda: with_mark(g_S, caron)
    m['Ž'] = lambda: with_mark(g_Z, caron)
    m['Đ'] = g_Dj
    for d in '0123456789':
        m[d] = globals()['n_' + d]
    m.update({' ': p_space, '.': p_period, ',': p_comma, ':': p_colon, ';': p_semicolon, '!': p_exclam,
              '?': p_question, '-': p_hyphen, '+': p_plus, '=': p_equal, '/': p_slash, '%': p_percent,
              "'": p_quotesingle, '"': p_quotedbl, '(': p_parenleft, ')': p_parenright, '€': p_euro,
              '#': p_numbersign, '*': p_asterisk, '_': p_underscore, '@': p_at})
    return m


def to_glyph(geom):
    pen = TTGlyphPen(None)
    if geom.is_empty:
        return pen.glyph()
    polys = [geom] if isinstance(geom, Polygon) else [g for g in getattr(geom, 'geoms', []) if isinstance(g, Polygon)]
    for p in polys:
        p = orient(p.buffer(0), sign=-1.0)  # TrueType: vanjski konturi u smjeru kazaljke
        if p.is_empty:
            continue
        for ring_ in [p.exterior] + list(p.interiors):
            pts = [(round(x), round(y)) for x, y in list(ring_.coords)[:-1]]
            clean = []
            for pt in pts:
                if not clean or clean[-1] != pt:
                    clean.append(pt)
            if len(clean) < 3:
                continue
            pen.moveTo(clean[0])
            for pt in clean[1:]:
                pen.lineTo(pt)
            pen.closePath()
    return pen.glyph()


# --------------------------------------------------------------- kerning
# Korekcije razmaka za parove kosih slova (jedinice fonta, negativno = blize).
KERN_CLASSES = {
    'A': 'A', 'V': 'V', 'W': 'W', 'Y': 'Y', 'T': 'T', 'L': 'L', 'P': 'P', 'F': 'F', 'K': 'K', 'R': 'R', 'X': 'X',
    'round': 'OCĆČGQD0',     # okrugli oblici
    'punct': '.,',
}
KERN_PAIRS = [
    ('A', 'V', -95), ('A', 'W', -70), ('A', 'Y', -100), ('A', 'T', -75),
    ('V', 'A', -95), ('W', 'A', -70), ('Y', 'A', -100), ('T', 'A', -75),
    ('L', 'T', -110), ('L', 'V', -110), ('L', 'W', -80), ('L', 'Y', -120),
    ('P', 'A', -70), ('F', 'A', -55), ('K', 'A', -25), ('X', 'A', -25), ('A', 'X', -25),
    ('A', 'round', -20), ('round', 'A', -20), ('L', 'round', -30),
    ('T', 'round', -25), ('round', 'T', -25), ('V', 'round', -30), ('round', 'V', -30),
    ('Y', 'round', -40), ('round', 'Y', -40), ('W', 'round', -20), ('round', 'W', -20),
    ('K', 'round', -40), ('X', 'round', -30), ('round', 'X', -30), ('R', 'V', -30), ('R', 'Y', -40),
    ('T', 'punct', -110), ('V', 'punct', -110), ('W', 'punct', -80), ('Y', 'punct', -120),
    ('P', 'punct', -120), ('F', 'punct', -90),
]


def add_kerning(font, cmap):
    from fontTools.feaLib.builder import addOpenTypeFeaturesFromString
    def names(chars):
        out = []
        for c in chars:
            n = cmap.get(ord(c))
            if n and n not in out:
                out.append(n)
        return out
    lines = []
    for k, chars in KERN_CLASSES.items():
        ns = names(chars)
        if ns:
            lines.append(f"@k_{k} = [{' '.join(ns)}];")
    lines.append('feature kern {')
    for a, b, v in KERN_PAIRS:
        lines.append(f'    pos @k_{a} @k_{b} {v};')
    lines.append('} kern;')
    addOpenTypeFeaturesFromString(font, '\n'.join(lines))


def build(out_dir, family, logo_e):
    m = build_map(logo_e)
    order = ['.notdef']
    glyphs, metrics, cmap = {}, {}, {}
    pen = TTGlyphPen(None)
    pen.moveTo((60, 0)); pen.lineTo((60, CAP)); pen.lineTo((500, CAP)); pen.lineTo((500, 0)); pen.closePath()
    glyphs['.notdef'] = pen.glyph(); metrics['.notdef'] = (600, 60)
    for ch, fn in m.items():
        geom, W = fn()
        if ch != ' ':
            geom = affinity.translate(geom.buffer(0), SB, 0)
            geom = affinity.skew(geom, xs=math.degrees(math.atan(SLANT)), origin=(0, 0))
        name = 'space' if ch == ' ' else f'uni{ord(ch):04X}'
        order.append(name)
        glyphs[name] = to_glyph(geom)
        adv = int(W + 2 * SB) if ch != ' ' else W
        xmin = int(geom.bounds[0]) if not geom.is_empty else 0
        metrics[name] = (adv, xmin)
        cmap[ord(ch)] = name
        low = ch.lower()
        if low != ch and len(low) == 1:
            cmap[ord(low)] = name  # mala slova = velika (display font)

    fb = FontBuilder(UPM, isTTF=True)
    fb.setupGlyphOrder(order)
    fb.setupCharacterMap(cmap)
    fb.setupGlyf(glyphs)
    fb.setupHorizontalMetrics(metrics)
    fb.setupHorizontalHeader(ascent=980, descent=-220)
    fb.setupNameTable({'familyName': family, 'styleName': 'Regular',
                       'uniqueFontIdentifier': f'{family.replace(" ", "")}-Regular-1.000',
                       'fullName': f'{family} Regular', 'psName': f'{family.replace(" ", "")}-Regular',
                       'version': 'Version 1.000', 'copyright': '(c) 2026 PROZONE (prozone.ba)',
                       'licenseDescription': 'Vlasnistvo PROZONE. Slobodno za koristenje unutar PROZONE brenda.'})
    fb.setupOS2(sTypoAscender=980, sTypoDescender=-220, sTypoLineGap=0, usWinAscent=1000, usWinDescent=240,
                sCapHeight=CAP, sxHeight=CAP, fsType=0, achVendID='PRZN')
    fb.setupPost(italicAngle=-round(math.degrees(math.atan(SLANT)), 1))
    add_kerning(fb.font, cmap)
    fb.setupDummyDSIG = None
    stem = family.replace(' ', '')
    ttf = Path(out_dir) / f'{stem}.ttf'
    fb.save(str(ttf))
    fb.font.flavor = 'woff2'
    fb.font.save(str(Path(out_dir) / f'{stem}.woff2'))
    return ttf


if __name__ == '__main__':
    out = Path(sys.argv[1] if len(sys.argv) > 1 else '.')
    out.mkdir(parents=True, exist_ok=True)
    for fam, logo in (('Prozone Display', False), ('Prozone Logo', True)):
        print('->', build(out, fam, logo))
