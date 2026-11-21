"""Prenosi slova P R O Z N E iz PROZONE loga (crtao vlasnik) u konture fonta.
Izlaz: logo_glyphs.json  (koordinate u jedinicama fonta, uspravno = bez nagiba)."""
import json, sys
import numpy as np
from PIL import Image
from scipy import ndimage
from skimage import measure
from shapely.geometry import Polygon, mapping
from shapely.ops import unary_union

SRC = sys.argv[1]
TOP, BASE = 197.5, 373.0          # gornja ivica i baseline loga u px (kontura na nivou 0.5)
CAP = 700
SC = CAP / (BASE - TOP)           # px -> jedinice fonta
SLANT = 0.175                     # nagib izmjeren na lijevoj ivici slova P

a = np.array(Image.open(SRC).convert('RGBA'))[:, :, 3].astype(float) / 255.0
lab, n = ndimage.label(a > 0.5)
objs = ndimage.find_objects(lab)
order = sorted(range(n), key=lambda i: objs[i][1].start)
groups = {'P': [order[0]], 'R': [order[1]], 'O': [order[2]], 'Z': [order[3]], 'O2': [order[4]],
          'N': [order[5]], 'E': order[6:9]}

def letter(ids):
    polys = []
    for i in ids:
        sy, sx = objs[i]
        pad = 3
        crop = a[sy.start - pad:sy.stop + pad, sx.start - pad:sx.stop + pad] * (lab[sy.start - pad:sy.stop + pad, sx.start - pad:sx.stop + pad] == i + 1)
        cs = measure.find_contours(crop, 0.5)
        rings = []
        for c in cs:
            pts = [((x + sx.start - pad) * SC, (BASE - (y + sy.start - pad)) * SC) for y, x in c]
            pts = [(x - y * SLANT, y) for x, y in pts]        # ukloni nagib -> uspravno
            p = Polygon(pts).buffer(0)
            if p.area > 50:
                rings.append(p)
        rings.sort(key=lambda p: -p.area)
        shape = Polygon()
        for r in rings:
            shape = shape.difference(r) if shape.contains(r.representative_point()) else shape.union(r)
        polys.append(shape)
    g = unary_union(polys).simplify(1.2)
    minx = g.bounds[0]
    from shapely import affinity
    g = affinity.translate(g, -minx, 0)
    return g

out = {}
for k, ids in groups.items():
    g = letter(ids)
    out[k] = {'width': round(g.bounds[2]), 'geom': mapping(g)}
    print(k, 'sirina', round(g.bounds[2]), 'visina', round(g.bounds[1]), round(g.bounds[3]), 'tacaka', len(g.exterior.coords) if g.geom_type == 'Polygon' else '-')
json.dump(out, open('logo_glyphs.json', 'w'))
