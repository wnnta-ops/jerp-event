"""Extract seed data from the two source .xlsx workbooks into JSON files
under prisma/seed-data/. Re-runnable. Requires openpyxl.

Generated inputs:
  - MASTER DATA SALES (9).xlsx  -> units, clusters/phases/types, sales, agencies, banks
  - Daily Report 29 JUNI 26.xlsx (sheet AGENT PER KOOR NEW) -> rotation
"""
import json
import os
import datetime as dt
import openpyxl

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "prisma", "seed-data")
MASTER = os.path.join(ROOT, "MASTER DATA SALES (9).xlsx")
DAILY = os.path.join(ROOT, "Daily Report 29 JUNI 26.xlsx")

PROJECT_NAME = "Java Sales Hub"
PROJECT_CODE = "JAVA"
ROTATION_EFFECTIVE_FROM = "2024-01-01"

# column indices (0-based) per cluster sheet layout
COLS = {
    "CLUSTER 2": dict(type=3, code=5, sprCetak=6, tglUtj=10, user=14, akad=15,
                      sales=17, agent=18, bayar=19, promo=20, status=21, bank=22),
    "CLUSTER 3": dict(type=3, code=5, sprCetak=6, tglUtj=10, user=14, akad=15,
                      sales=17, agent=18, bayar=19, promo=20, status=21, bank=22),
    "CLUSTER 5": dict(type=3, code=5, sprCetak=6, tglUtj=10, user=13, akad=14,
                      sales=16, agent=17, bayar=18, promo=19, status=20, bank=21),
}
CLUSTER_LABEL = {"CLUSTER 2": "Cluster 2", "CLUSTER 3": "Cluster 3", "CLUSTER 5": "Cluster 5"}

STATUS_MAP = {
    "REALISASI": "REALISASI", "CASH LUNAS": "CASH_LUNAS", "OPEN STOCK": "OPEN_STOCK",
    "PROSES": "PROSES", "HOLD UNIT": "HOLD", "HOLD": "HOLD",
}
BAYAR_MAP = {"KPR": "KPR", "CASH": "CASH"}


def s(v):
    if v is None:
        return None
    if isinstance(v, float):
        # drop trailing .0 for ints stored as float
        if v.is_integer():
            return str(int(v))
        return str(v)
    return str(v).strip()


def iso(v):
    if isinstance(v, (dt.datetime, dt.date)):
        return dt.datetime(v.year, v.month, v.day).isoformat()
    return None


def title(name):
    return " ".join(w.capitalize() for w in name.split())


def parse_block_no(code):
    """From 'JRK3 IV No.5' -> (block='JRK3 IV', no='5')."""
    if not code:
        return None, None
    if "No" in code:
        left, _, right = code.partition("No")
        no = right.lstrip(".").strip()
        return left.strip() or None, no or None
    return code, None


def extract_units():
    wb = openpyxl.load_workbook(MASTER, read_only=True, data_only=True)
    units = []
    clusters = {}   # clusterLabel -> {phases:set, types:set}
    sales_set, agency_set, bank_set = set(), set(), set()
    for sheet, c in COLS.items():
        label = CLUSTER_LABEL[sheet]
        clusters.setdefault(label, {"phases": set(), "types": set()})
        ws = wb[sheet]
        for row in ws.iter_rows(min_row=2, values_only=True):
            if len(row) <= c["status"]:
                continue
            code = s(row[c["code"]])
            typ = s(row[c["type"]])
            phase = s(row[1])
            if not code or not typ or not phase:
                continue
            typ = title(typ)
            status_raw = s(row[c["status"]]) or "OPEN STOCK"
            status = STATUS_MAP.get(status_raw.upper(), "OPEN_STOCK")
            user = s(row[c["user"]])
            sales = s(row[c["sales"]])
            agent = s(row[c["agent"]])
            bayar_raw = s(row[c["bayar"]])
            bayar = BAYAR_MAP.get((bayar_raw or "").upper())
            bank = s(row[c["bank"]])
            block, no = parse_block_no(code)
            clusters[label]["phases"].add(phase)
            clusters[label]["types"].add(typ)
            if sales and sales.upper() != "NONE":
                sales_set.add(title(sales))
            if agent and agent.upper() != "NONE":
                agency_set.add(title(agent))
            if bank and bank.upper() != "NONE":
                bank_set.add(bank.upper())
            units.append({
                "code": code, "cluster": label, "phase": phase, "type": typ,
                "block": block, "no": no, "status": status,
                "user": None if not user or user.upper() == "NONE" else title(user),
                "sales": None if not sales or sales.upper() == "NONE" else title(sales),
                "agent": None if not agent or agent.upper() == "NONE" else title(agent),
                "bayar": bayar,
                "bank": None if not bank or bank.upper() == "NONE" else bank.upper(),
                "tglSprCetak": iso(row[c["sprCetak"]]),
                "tglUtj": iso(row[c["tglUtj"]]),
                "tglAkad": iso(row[c["akad"]]),
            })
    wb.close()
    # summary-only phase (C5 II has units in summary but no rows) — ensure it exists
    clusters.setdefault("Cluster 5", {"phases": set(), "types": set()})
    clusters["Cluster 5"]["phases"].add("II")
    clusters_out = [
        {"name": k, "phases": sorted(v["phases"]), "types": sorted(v["types"])}
        for k, v in clusters.items()
    ]
    return units, clusters_out, sorted(sales_set), sorted(agency_set), sorted(bank_set)


def extract_rotation():
    wb = openpyxl.load_workbook(DAILY, read_only=True, data_only=True)
    ws = wb["AGENT PER KOOR NEW"]
    rows = list(ws.iter_rows(min_row=1, max_row=20, max_col=12, values_only=True))
    # header row containing coordinator names
    header_idx = None
    for i, r in enumerate(rows):
        vals = [s(x) for x in r]
        if vals and "PUTRI" in [ (v or "").upper() for v in vals ]:
            header_idx = i
            break
    header = rows[header_idx]
    coord_cols = {}  # col_index -> coordinator name
    for ci, v in enumerate(header):
        name = s(v)
        if name and name.upper() != "NONE":
            coord_cols[ci] = title(name)
    coordinators = list(coord_cols.values())
    pairs = []          # (agency, coordinator) in column order
    seen = set()
    assignments = []    # deduped: first coordinator wins as current
    for r in rows[header_idx + 1:]:
        for ci, coord in coord_cols.items():
            if ci < len(r):
                ag = s(r[ci])
                if ag and ag.upper() != "NONE":
                    ag = title(ag)
                    pairs.append({"agency": ag, "coordinator": coord})
                    if ag not in seen:
                        seen.add(ag)
                        assignments.append({
                            "agency": ag, "coordinator": coord,
                            "effectiveFrom": ROTATION_EFFECTIVE_FROM,
                        })
    wb.close()
    agencies = sorted({p["agency"] for p in pairs})
    dups = [p for p in pairs if sum(1 for q in pairs if q["agency"] == p["agency"]) > 1]
    return coordinators, agencies, assignments, dups


def map_alasan(text):
    t = (text or "").upper()
    if "REJECT" in t:
        return "REJECT_BANK"
    if "KOL 5" in t or "KOL5" in t:
        return "KOL_5"
    if "KOL 2" in t or "KOL2" in t:
        return "KOL_2"
    if "SEPIHAK" in t:
        return "BATAL_SEPIHAK"
    if "KELUARGA" in t or "ORTU" in t or "RESTU" in t:
        return "MASALAH_KELUARGA"
    if any(k in t for k in ["SKEMA", "BUNGA", "BAYAR", "GAJI", "MAMPU", "TUM",
                            "PPN", " DP", "REGULER", "BERJENJANG", "ANGSUR"]):
        return "SKEMA_BAYAR"
    return "OTHER"


def extract_batal():
    """Real cancellations from the BATALREFUND sheet -> failed-pipeline records."""
    wb = openpyxl.load_workbook(MASTER, read_only=True, data_only=True)
    ws = wb["BATALREFUND"]
    out = []
    sales_set, agency_set = set(), set()
    for r in ws.iter_rows(min_row=2, values_only=True):
        nama = s(r[1]) if len(r) > 1 else None
        if not nama:
            continue
        cluster = s(r[2])
        cluster = title(cluster) if cluster else None  # "CLUSTER 3" -> "Cluster 3"
        sales = s(r[4])
        agent = s(r[5])
        status = (s(r[14]) or "").upper() if len(r) > 14 else ""
        keterangan = s(r[15]) if len(r) > 15 else None
        alasan_col = s(r[16]) if len(r) > 16 else None
        reason_raw = alasan_col or keterangan
        kategori = "SPR" if "SPR" in status else ("WAITING_LIST" if "WAITING" in status else "UTJ")
        if sales and sales.upper() != "NONE":
            sales_set.add(title(sales))
        if agent and agent.upper() != "NONE":
            agency_set.add(title(agent))
        out.append({
            "nama": title(nama),
            "cluster": cluster if cluster in ("Cluster 2", "Cluster 3", "Cluster 5") else None,
            "kodeUnit": s(r[3]),
            "sales": title(sales) if sales and sales.upper() != "NONE" else None,
            "agent": title(agent) if agent and agent.upper() != "NONE" else None,
            "tglUtj": iso(r[6]) if len(r) > 6 else None,
            "sprCetak": iso(r[9]) if len(r) > 9 else None,
            "batalTanggal": iso(r[11]) if len(r) > 11 else None,
            "refund": bool(r[12]) if len(r) > 12 and r[12] not in (None, 0, "0") else False,
            "kategori": kategori,
            "alasan": map_alasan(f"{alasan_col or ''} {keterangan or ''}"),
            "alasanRaw": reason_raw,
        })
    wb.close()
    return out, sorted(sales_set), sorted(agency_set)


def main():
    units, clusters, sales, agencies_master, banks = extract_units()
    coordinators, agencies_rot, assignments, dups = extract_rotation()
    batal, batal_sales, batal_agencies = extract_batal()
    sales = sorted(set(sales) | set(batal_sales))
    agencies = sorted(set(agencies_master) | set(agencies_rot) | set(batal_agencies))
    teams = ["Team Putri", "Team Naufal"]
    promos = ["Free DP", "DP 3x", "Cash", "Reguler"]
    targets = [
        {"scope": "ANNUAL", "metric": "SPR", "period": "2026", "value": 798},
        {"scope": "MONTHLY", "metric": "UNIT", "period": "2026-06", "value": 40},
        {"scope": "TEAM_MONTH", "metric": "UNIT", "period": "2026-06", "team": "Team Putri", "value": 20},
        {"scope": "TEAM_MONTH", "metric": "UNIT", "period": "2026-06", "team": "Team Naufal", "value": 20},
    ]
    data = {
        "project": {"name": PROJECT_NAME, "code": PROJECT_CODE},
        "clusters": clusters,
        "coordinators": coordinators,
        "agencies": agencies,
        "rotation": assignments,
        "teams": teams,
        "sales": sales,
        "banks": banks,
        "promos": promos,
        "targets": targets,
    }
    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, "reference.json"), "w") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
    with open(os.path.join(OUT, "units.json"), "w") as f:
        json.dump(units, f, indent=2, ensure_ascii=False)
    with open(os.path.join(OUT, "batal.json"), "w") as f:
        json.dump(batal, f, indent=2, ensure_ascii=False)

    from collections import Counter
    print(f"batal records: {len(batal)}  by alasan: {dict(Counter(b['alasan'] for b in batal))}")
    print(f"units: {len(units)}")
    print(f"clusters: {[(c['name'], c['phases']) for c in clusters]}")
    print(f"coordinators: {coordinators}")
    print(f"agencies: {len(agencies)}  sales: {len(sales)}  banks: {len(banks)}")
    print(f"rotation assignments (deduped): {len(assignments)}")
    print(f"duplicate agency->koor pairs (kept first): "
          f"{sorted({d['agency'] for d in dups})}")


if __name__ == "__main__":
    main()
