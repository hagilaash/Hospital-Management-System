#!/usr/bin/env python3
"""
HMS FDM Stage 2 static checker for the SQL scripts. Needs only python3.

Run from the project root:   python3 scripts/check_fdm_sql.py

It does NOT replace executing the scripts in PostgreSQL. It catches the
mistakes that are cheap to find without a database and expensive to find at
run time: RETURN QUERY column-count mismatches, misspelled tables, columns and
functions, wrong argument counts in the verification script, and drift between
the install script and the rollback script.

It also writes sql/fdm_function_signatures.json, the contract Stage 3 (PHP) will be
checked against.
"""
import json
import os
import re
import sys

ROOT = os.getcwd()
FAILS = PASSES = 0


def check(label, cond, detail=""):
    global FAILS, PASSES
    if cond:
        PASSES += 1
        print(f"  PASS  {label}")
    else:
        FAILS += 1
        print(f"  FAIL  {label}" + (f"\n        {detail}" if detail else ""))


def read(path):
    with open(os.path.join(ROOT, path), encoding="utf-8") as fh:
        return fh.read()


# ---------------------------------------------------------------- helpers
def strip_comments(sql):
    return re.sub(r"--[^\n]*", "", sql)


def strip_strings(sql):
    return re.sub(r"'(?:[^']|'')*'", "''", sql)


def match_paren(text, open_idx):
    """Index of the ')' matching the '(' at open_idx (quote-aware)."""
    depth, i, n = 0, open_idx, len(text)
    while i < n:
        c = text[i]
        if c == "'":
            i += 1
            while i < n and not (text[i] == "'" and text[i + 1: i + 2] != "'"):
                i += 2 if text[i] == "'" else 1
        elif c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    raise ValueError("unbalanced parenthesis")


def split_top(text, sep=","):
    parts, depth, cur, i, n = [], 0, "", 0, len(text)
    while i < n:
        c = text[i]
        if c == "'":
            j = i + 1
            while j < n and not (text[j] == "'" and text[j + 1: j + 2] != "'"):
                j += 2 if text[j] == "'" else 1
            cur += text[i: j + 1]
            i = j + 1
            continue
        if c in "([":
            depth += 1
        elif c in ")]":
            depth -= 1
        if c == sep and depth == 0:
            parts.append(cur)
            cur = ""
        else:
            cur += c
        i += 1
    if cur.strip():
        parts.append(cur)
    return [p.strip() for p in parts]


def norm_type(t):
    t = re.sub(r"\s+", " ", t.strip().lower())
    return t


def parse_functions(sql):
    funcs = []
    for m in re.finditer(r"CREATE OR REPLACE FUNCTION public\.(\w+)\(", sql):
        name = m.group(1)
        open_idx = m.end() - 1
        close_idx = match_paren(sql, open_idx)
        params = []
        for p in split_top(sql[open_idx + 1: close_idx]):
            default = None
            dm = re.search(r"\s+DEFAULT\s+(.*)$", p, re.S | re.I)
            if dm:
                default = dm.group(1).strip()
                p = p[: dm.start()]
            pname, ptype = p.split(None, 1)
            params.append({"name": pname, "type": norm_type(ptype), "default": default})
        rest = sql[close_idx + 1:]
        rm = re.match(r"\s*RETURNS\s+", rest)
        after = rest[rm.end():]
        columns, scalar = None, None
        if after.upper().startswith("TABLE"):
            tp = after.index("(")
            tc = match_paren(after, tp)
            columns = []
            for c in split_top(after[tp + 1: tc]):
                cname, ctype = c.split(None, 1)
                columns.append({"name": cname, "type": norm_type(ctype)})
            after2 = after[tc + 1:]
        else:
            lm = re.search(r"\bLANGUAGE\b", after)
            scalar = norm_type(after[: lm.start()])
            after2 = after[lm.start():]
        lang = re.search(r"LANGUAGE\s+(\w+)", after2).group(1)
        vol = re.search(r"\b(VOLATILE|STABLE|IMMUTABLE)\b", after2)
        bs = after2.index("$BODY$")
        be = after2.index("$BODY$", bs + 6)
        funcs.append({
            "name": name, "params": params, "columns": columns, "returns": scalar,
            "language": lang.lower(), "volatility": vol.group(1) if vol else "VOLATILE",
            "body": after2[bs + 6: be],
        })
    return funcs


def return_query_statements(body):
    """Yield the text of every RETURN QUERY ... ; statement (quote-aware)."""
    for m in re.finditer(r"\bRETURN\s+QUERY\b", body):
        i, depth, n = m.end(), 0, len(body)
        start = i
        while i < n:
            c = body[i]
            if c == "'":
                i += 1
                while i < n and not (body[i] == "'" and body[i + 1: i + 2] != "'"):
                    i += 2 if body[i] == "'" else 1
            elif c == "(":
                depth += 1
            elif c == ")":
                depth -= 1
            elif c == ";" and depth == 0:
                break
            i += 1
        yield body[start:i]


def select_list_count(stmt):
    """Number of columns in the final top-level SELECT list of a statement."""
    depth, i, n, sel_positions = 0, 0, len(stmt), []
    scrubbed = list(stmt)
    # blank out string literals so keywords inside them are ignored
    j = 0
    while j < n:
        if stmt[j] == "'":
            k = j + 1
            while k < n and not (stmt[k] == "'" and stmt[k + 1: k + 2] != "'"):
                k += 2 if stmt[k] == "'" else 1
            for x in range(j, min(k + 1, n)):
                scrubbed[x] = "_"
            j = k + 1
        else:
            j += 1
    s = "".join(scrubbed)
    while i < n:
        c = s[i]
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
        elif depth == 0 and re.match(r"SELECT\b", s[i:], re.I) and (i == 0 or not s[i - 1].isalnum() and s[i - 1] != "_"):
            sel_positions.append(i)
        i += 1
    last = sel_positions[-1]
    depth, i, end = 0, last + 6, n
    while i < n:
        c = s[i]
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
        elif depth == 0 and re.match(r"FROM\b", s[i:], re.I) and not (s[i - 1].isalnum() or s[i - 1] == "_"):
            end = i
            break
        i += 1
    return len(split_top(stmt[last + 6: end]))


# ------------------------------------------------------------------ load
SQL = "sql/fdm_module.sql"
main_raw = read(SQL)
main = strip_comments(main_raw)
funcs = parse_functions(main)
by_name = {f["name"]: f for f in funcs}

print("1. Install script structure")
check("function bodies are delimited by an even number of $BODY$ markers", main_raw.count("$BODY$") % 2 == 0,
      str(main_raw.count("$BODY$")))
check(f"{len(funcs)} functions parsed (header says 23)", len(funcs) == 23, str(len(funcs)))
check("header comment states the real function count", "4 tables, 23 functions" in main_raw)
check("exactly one BEGIN; and one COMMIT; (single transaction)",
      len(re.findall(r"^BEGIN;", main_raw, re.M)) == 1 and len(re.findall(r"^COMMIT;", main_raw, re.M)) == 1)
check("no DROP / TRUNCATE / DELETE / ALTER of existing objects in the install script",
      not re.search(r"\b(DROP|TRUNCATE|DELETE\s+FROM|ALTER\s+TABLE)\b", strip_strings(main), re.I))
check("every CREATE TABLE and CREATE INDEX is IF NOT EXISTS",
      all("IF NOT EXISTS" in m for m in re.findall(r"CREATE (?:UNIQUE )?(?:TABLE|INDEX)[^\n]*", main)))
check("duplicate function names do not exist", len(by_name) == len(funcs))

print("\n2. RETURNS TABLE functions: column counts and conflict pragma")
for f in funcs:
    if f["columns"] is None:
        continue
    n_cols = len(f["columns"])
    stmts = list(return_query_statements(f["body"]))
    bad = []
    for st in stmts:
        try:
            got = select_list_count(st)
        except Exception as exc:  # parsing problem is itself a finding
            bad.append(f"unparsable: {exc}")
            continue
        if got != n_cols:
            bad.append(f"{got} columns in: {re.sub(r'\\s+', ' ', st.strip())[:90]}...")
    check(f"{f['name']}: all {len(stmts)} RETURN QUERY statements return {n_cols} columns", stmts and not bad, "; ".join(bad))
    check(f"{f['name']}: has #variable_conflict use_column", "#variable_conflict use_column" in f["body"])

print("\n3. Names used in the install script exist")
tables = set(re.findall(r"CREATE TABLE IF NOT EXISTS public\.(\w+)", main))
check("four new tables", tables == {"ecs_appointment_code_counter", "ecs_queue_token_counter", "ecs_appointments", "ecs_queue_entries"},
      str(tables))
existing = {"ecs_patients", "ecs_doctors"}
defined_other = set(re.findall(r"CONSTRAINT (\w+)", main)) | set(re.findall(r"INDEX IF NOT EXISTS (\w+)", main))
used = set(re.findall(r"\becs_\w+", strip_strings(main)))
unknown = sorted(used - tables - existing - set(by_name) - defined_other)
check("every ecs_* identifier is a table, function, constraint or index defined here (or an existing table)", not unknown, str(unknown))

COLUMNS = {
    "ecs_patients": "patient_id patient_code first_name last_name gender date_of_birth phone email address blood_group "
                    "emergency_contact_name emergency_contact_phone is_active created_at updated_at created_by updated_by".split(),
    "ecs_doctors": "doctor_id doctor_name specialization department phone_no email qualification gender date_of_birth "
                   "created_at updated_at is_active".split(),
}
for t in ("ecs_appointments", "ecs_queue_entries"):
    m = re.search(r"CREATE TABLE IF NOT EXISTS public\.%s\s*\((.*?)\n\);" % t, main, re.S)
    cols = []
    for line in split_top(m.group(1)):
        first = line.split()[0]
        if first.upper() not in ("CONSTRAINT", "PRIMARY", "UNIQUE", "CHECK", "FOREIGN"):
            cols.append(first)
    COLUMNS[t] = cols
check("ecs_appointments has the generated start_at / end_at columns", {"start_at", "end_at"} <= set(COLUMNS["ecs_appointments"]))
ALIAS = {"a": "ecs_appointments", "q": "ecs_queue_entries", "p": "ecs_patients", "d": "ecs_doctors"}
missing = []
for f in funcs:
    body = strip_strings(f["body"])
    for al, col in re.findall(r"(?<![\w.])([apqd])\.(\w+)", body):
        if col not in COLUMNS[ALIAS[al]]:
            missing.append(f"{f['name']}: {al}.{col}")
check("every a./q./p./d. column reference exists in its table", not missing, "; ".join(missing[:6]))

fk_ok = all(re.search(p, main) for p in (
    r"REFERENCES public\.ecs_patients \(patient_id\)", r"REFERENCES public\.ecs_doctors \(doctor_id\)",
    r"REFERENCES public\.ecs_appointments \(appointment_id\)"))
check("foreign keys point at the existing patient/doctor primary keys", fk_ok)

print("\n4. Rollback script mirrors the install script")
rb = strip_comments(read("sql/fdm_module_rollback.sql"))
drops = {}
for m in re.finditer(r"DROP FUNCTION IF EXISTS public\.(\w+)\((.*?)\);", rb, re.S):
    drops[m.group(1)] = [norm_type(x) for x in split_top(m.group(2))]
check("rollback drops exactly the functions the install script creates", set(drops) == set(by_name),
      f"missing {sorted(set(by_name) - set(drops))} extra {sorted(set(drops) - set(by_name))}")
sig_bad = [n for n in by_name if n in drops and drops[n] != [p["type"] for p in by_name[n]["params"]]]
check("rollback signatures match parameter types exactly", not sig_bad, str(sig_bad))
rb_tables = re.findall(r"DROP TABLE IF EXISTS public\.(\w+)", rb)
check("rollback drops the four tables, children before parents",
      set(rb_tables) == tables and rb_tables.index("ecs_queue_entries") < rb_tables.index("ecs_appointments"))
check("rollback never touches existing tables", not re.search(r"ecs_patients|ecs_doctors|\busers\b", rb))

print("\n5. Verification script")
ver_raw = read("sql/fdm_module_verify.sql")
ver = strip_comments(ver_raw)
check("one DO block with matching $TEST$ markers", ver_raw.count("$TEST$") == 2)
check("it ends with a deliberate RAISE EXCEPTION (guaranteed rollback)", re.search(r"RAISE EXCEPTION[^;]*rolls back every row[^;]*;\s*END;\s*\$TEST\$;", ver_raw, re.S))
check("it contains no COMMIT / INSERT / UPDATE / DELETE / DROP / TRUNCATE of its own",
      not re.search(r"\b(COMMIT|INSERT\s+INTO|UPDATE\s+ecs|DELETE\s+FROM|DROP\s|TRUNCATE)\b", strip_strings(ver)))
calls = []
body = strip_strings(ver)
for m in re.finditer(r"\b(ecs_\w+)\s*\(", ver):
    name = m.group(1)
    if name not in by_name and name not in ("ecs_patients", "ecs_doctors"):
        calls.append((name, "unknown function"))
        continue
    if name in ("ecs_patients", "ecs_doctors"):
        continue
    close = match_paren(ver, m.end() - 1)
    n_args = len(split_top(ver[m.end(): close]))
    f = by_name[name]
    lo = len([p for p in f["params"] if p["default"] is None])
    hi = len(f["params"])
    if not lo <= n_args <= hi:
        calls.append((name, f"{n_args} args, expects {lo}..{hi}"))
check(f"all function calls in the test use known functions with a valid number of arguments", not calls, str(calls[:5]))
all_out = {c["name"] for f in funcs if f["columns"] for c in f["columns"]}
all_cols = all_out | set(COLUMNS["ecs_appointments"]) | set(COLUMNS["ecs_queue_entries"]) | set(COLUMNS["ecs_patients"]) | set(COLUMNS["ecs_doctors"])
bad_fields = sorted({f"{al}.{col}" for al, col in re.findall(r"(?<![\w.])(r|s0|s1|l|x|a|q|p|d)\.(\w+)", strip_strings(ver))
                     if col not in all_cols})
check("every record/alias field the test reads exists in some function result or table", not bad_fields, str(bad_fields))
labels = re.findall(r"fdm_chk\('([A-Z]\d+)\b", ver_raw)
check(f"{len(labels)} numbered checks, no duplicate labels", len(labels) == len(set(labels)) and len(labels) > 60, str(len(labels)))

print("\n6. Optional exclusion script")
opt = read("sql/fdm_module_optional_exclusion.sql")
check("uses the generated columns and the active-status predicate",
      "tsrange(start_at, end_at)" in opt and "status IN ('Scheduled', 'Checked-In')" in opt)
check("is idempotent (guards on pg_constraint)", "pg_constraint" in opt and "ecs_appointments_no_overlap_excl" in opt)

# --------------------------------------------------------------- export
contract = {
    f["name"]: {
        "params": [{"name": p["name"], "type": p["type"], "has_default": p["default"] is not None} for p in f["params"]],
        "returns_table": f["columns"],
        "returns_scalar": f["returns"],
    } for f in funcs
}
with open(os.path.join(ROOT, "sql/fdm_function_signatures.json"), "w", encoding="utf-8") as fh:
    json.dump(contract, fh, indent=2)
print("\nwrote sql/fdm_function_signatures.json (%d functions)" % len(contract))

print("\n" + "-" * 60)
print(f"Passed: {PASSES}   Failed: {FAILS}")
sys.exit(1 if FAILS else 0)
