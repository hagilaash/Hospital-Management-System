#!/usr/bin/env python3
"""
HMS Stage 3 static checker. Needs only python3 (no PHP, no database).

Run from the project root:   python3 scripts/check_stage3.py

It does NOT replace `php -l`, `lint:container` or PHPUnit. It checks the things
that are easy to get wrong by hand and that those tools cannot see:
the contract between the PHP methods and the SQL functions.
"""
import os
import re
import sys
import unicodedata

ROOT = os.getcwd()
FAILS = 0
PASSES = 0


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


def exists(path):
    return os.path.exists(os.path.join(ROOT, path))


PHP_FILES = [
    "src/DB/DatabaseFunction.php",
    "src/DTO/PatientDTO.php",
    "src/DTO/PatientListDTO.php",
    "src/DAO/PatientDAO.php",
    "src/Repository/PatientRepository.php",
    "src/Service/PatientService.php",
    "tests/Service/PatientServiceTest.php",
    "tests/DTO/PatientDTOTest.php",
]


def strip_php(src):
    """Remove comments and string contents so brackets can be counted."""
    out, i, n = [], 0, len(src)
    while i < n:
        c, nxt = src[i], src[i + 1] if i + 1 < n else ""
        if c == "/" and nxt == "*":
            j = src.find("*/", i + 2)
            i = n if j < 0 else j + 2
        elif c == "/" and nxt == "/" or (c == "#" and nxt != "["):
            j = src.find("\n", i)
            i = n if j < 0 else j
        elif c in ("'", '"'):
            q, i = c, i + 1
            while i < n and src[i] != q:
                i += 2 if src[i] == "\\" else 1
            i += 1
            out.append(q + q)
        else:
            out.append(c)
            i += 1
    return "".join(out)


def camel_to_snake(name):
    return re.sub(r"(?<!^)(?=[A-Z])", "_", name).lower()


# ----------------------------------------------------------------------
print("1. PHP structure (lexer-based)")
for f in PHP_FILES:
    if not exists(f):
        check(f"{f} exists", False)
        continue
    raw = read(f)
    code = strip_php(raw)
    check(f"{f}: starts with <?php", raw.startswith("<?php"))
    for o, c in ("{}", "()", "[]"):
        check(f"{f}: balanced {o}{c}", code.count(o) == code.count(c), f"{code.count(o)} vs {code.count(c)}")
    ns = re.search(r"^namespace ([^;]+);", raw, re.M)
    cls = re.search(r"^(?:final )?class (\w+)", raw, re.M)
    d = os.path.dirname(f)
    expected_ns = ("App\\" + d[4:].replace("/", "\\")) if d.startswith("src") else ("App\\Tests\\" + d[6:].replace("/", "\\"))
    check(f"{f}: namespace {expected_ns} and class {os.path.basename(f)[:-4]}",
          bool(ns and cls) and ns.group(1) == expected_ns and cls.group(1) == os.path.basename(f)[:-4])

print("\n2. `use App\\...` statements resolve to real files")
for f in PHP_FILES:
    if not exists(f):
        continue
    for u in re.findall(r"^use (App\\[^;]+);", read(f), re.M):
        rel = "src/" + u[4:].replace("\\", "/") + ".php"
        check(f"{f}: {u}", exists(rel), f"missing {rel}")

# ----------------------------------------------------------------------
print("\n3. DatabaseFunction <-> SQL function contract")
sql = read("sql/patients_module.sql")
db = read("src/DB/DatabaseFunction.php")


def sql_signature(fn):
    m = re.search(r"CREATE OR REPLACE FUNCTION public\.%s\((.*?)\)\s+RETURNS" % fn, sql, re.S)
    params = []
    for part in m.group(1).split(","):
        part = re.sub(r"\s+DEFAULT\s+.*$", "", part.strip(), flags=re.S)
        if part:
            name, typ = part.split(None, 1)
            params.append((name, re.sub(r"\s+", " ", typ)))
    return params


CASES = {
    "registerPatient": "ecs_register_patient",
    "updatePatient": "ecs_update_patient",
    "setPatientStatus": "ecs_set_patient_status",
    "findPossibleDuplicates": "ecs_find_possible_duplicates",
    "getPatientById": "ecs_get_patient_by_id",
    "getPatientsList": "ecs_get_patients_list",
    "countPatients": "ecs_count_patients",
}
db_params_by_method = {}
for method, fn in CASES.items():
    m = re.search(r"function %s\((.*?)\)\s*:\s*[\w?|]+\s*\{(.*?)\n    \}" % method, db, re.S)
    if not m:
        check(f"{method}: found in DatabaseFunction", False)
        continue
    sig, body = m.group(1), m.group(2)
    php_params = re.findall(r"\$(\w+)", sig)
    db_params_by_method[method] = php_params

    call = re.search(r"FROM %s\((.*?)\)'|SELECT %s\((.*?)\)'" % (fn, fn), body, re.S)
    call_args = (call.group(1) or call.group(2)) if call else ""
    casts = re.findall(r"CAST\(:(\w+) AS ([a-z ]+?)\)", call_args)
    expected = sql_signature(fn)

    check(f"{method} -> {fn}: argument count ({len(expected)})", len(casts) == len(expected),
          f"PHP passes {len(casts)}, SQL has {len(expected)}")
    check(f"{method}: CAST types and order match the SQL signature",
          [t for _, t in casts] == [t for _, t in expected],
          f"PHP {[t for _, t in casts]}\n        SQL {[t for _, t in expected]}")
    check(f"{method}: bind names are the SQL parameter names without 'p_', in order",
          [b for b, _ in casts] == [n[2:] for n, _ in expected],
          f"PHP {[b for b, _ in casts]}\n        SQL {[n[2:] for n, _ in expected]}")

    binds = re.findall(r"'(\w+)'\s*=>\s*([^,\n\]]+?)\s*[,\]]", body)
    check(f"{method}: every :bind has a value and none are unused",
          [b for b, _ in binds] == [b for b, _ in casts], f"{[b for b, _ in binds]}")
    mismatched = [b for b, v in binds if camel_to_snake(re.search(r"\$(\w+)", v).group(1)) != b]
    check(f"{method}: each bind is fed by the PHP parameter of the same name", not mismatched, str(mismatched))
    check(f"{method}: PHP parameter order = SQL order",
          [camel_to_snake(p) for p in php_params] == [n[2:] for n, _ in expected],
          f"PHP {[camel_to_snake(p) for p in php_params]}\n        SQL {[n[2:] for n, _ in expected]}")

print("\n4. PatientDAO passes arguments to DatabaseFunction in the right order")
dao = read("src/DAO/PatientDAO.php")
for method in ("registerPatient", "updatePatient", "setPatientStatus", "findPossibleDuplicates",
               "getPatientById", "getPatientsList", "countPatients"):
    m = re.search(r"function %s\((.*?)\)\s*:\s*[\w?|]+\s*\{(.*?)\n    \}" % method, dao, re.S)
    if not m:
        check(f"DAO {method}: found", False)
        continue
    call = re.search(r"databaseFunction->%s\((.*?)\);" % method, m.group(2), re.S)
    args = [re.sub(r".*?(?:->|\$)(\w+)\s*$", r"\1", a.strip()) for a in call.group(1).split(",") if a.strip()]
    expected = db_params_by_method[method]
    check(f"DAO {method}: {len(expected)} args in DatabaseFunction order", args == expected,
          f"DAO {args}\n        DatabaseFunction {expected}")

# ----------------------------------------------------------------------
print("\n5. PHP validation patterns agree with the SQL patterns (test vectors)")
svc = read("src/Service/PatientService.php")


def php_pattern(const):
    m = re.search(r"const %s = '/(.*)/[a-z]*';" % const, svc)
    return m.group(1)


def sql_pattern(constraint_name):
    m = re.search(r"CONSTRAINT %s\s+CHECK \(.*?~\*? '(.*?)'" % constraint_name, sql, re.S)
    return m.group(1)


phone_php, phone_sql = php_pattern("PHONE_PATTERN"), sql_pattern("ecs_patients_phone_chk")
email_php, email_sql = php_pattern("EMAIL_PATTERN"), sql_pattern("ecs_patients_email_chk")
check("phone: PHP pattern text equals the SQL CHECK pattern", phone_php == phone_sql, f"{phone_php}  vs  {phone_sql}")
check("email: PHP pattern text equals the SQL CHECK pattern", email_php == email_sql, f"{email_php}  vs  {email_sql}")

phones = ["9841012345", "+919876543210", "1234567", "12345", "123456789012345", "1234567890123456",
          "+", "98410+12345", "abc1234567", "", "+1234567", "++1234567", "98410 12345"]
emails = ["a@b.co", "anita.raman@example.com", "a@b", "not-an-email", "x+y@mail.example.org", "a@b.c",
          "a b@c.com", "@example.com", "a@.com", "UPPER@EXAMPLE.COM"]
bad = [v for v in phones if bool(re.search(phone_php, v)) != bool(re.search(phone_sql, v))]
check(f"phone: PHP and SQL accept/reject the same {len(phones)} values", not bad, str(bad))
bad = [v for v in emails if bool(re.search(email_php, v, re.I)) != bool(re.search(email_sql, v, re.I))]
check(f"email: PHP and SQL accept/reject the same {len(emails)} values", not bad, str(bad))

sql_blood = re.search(r"blood_group IN \(([^)]*)\)", sql).group(1)
php_blood = re.search(r"BLOOD_GROUPS = \[(.*?)\]", svc).group(1)
norm = lambda s: [x.strip().strip("'") for x in s.split(",")]
check("blood groups: PHP list equals SQL list", norm(sql_blood) == norm(php_blood))
sql_gender = re.search(r"gender IN \(([^)]*)\)", sql).group(1)
php_gender = re.search(r"GENDERS = \[(.*?)\]", svc).group(1)
check("genders: PHP list equals SQL list", norm(sql_gender) == norm(php_gender))
check("status filters: PHP list equals the values the SQL function recognises",
      set(norm(re.search(r"STATUS_FILTERS = \[(.*?)\]", svc).group(1).replace("self::STATUS_", "").lower())) ==
      {"all", "active", "inactive"} and all(f"'{v}'" in sql for v in ("all", "active", "inactive")))
check("per-page default is within the SQL clamp (1-100)", 1 <= int(re.search(r"PER_PAGE = (\d+)", svc).group(1)) <= 100)

# \p{L} / \p{M} cannot run in Python's re, so the name rule is emulated with Unicode categories.
ALLOWED_PUNCT = set(" '\u2019.-")


def name_ok(s):
    return bool(s) and unicodedata.category(s[0]).startswith("L") and all(
        unicodedata.category(ch)[0] in "LM" or ch in ALLOWED_PUNCT for ch in s[1:])


vectors = {"Anita": True, "அனிதா": True, "Mary-Ann": True, "D'Souza": True, "A. R.": True, "José": True,
           "Anita2": False, "R2D2": False, "<script>": False, "-Anita": False, "": False, "Raman<": False}
bad = [k for k, v in vectors.items() if name_ok(k) != v]
check(f"name rule (emulated with Unicode categories) on {len(vectors)} vectors incl. Tamil", not bad, str(bad))
check("NAME_PATTERN source keeps \\p{L}, \\p{M} and the /u flag",
      "\\p{L}[\\p{L}\\p{M}" in svc and re.search(r"NAME_PATTERN = '.*/u';", svc) is not None)

# ----------------------------------------------------------------------
print("\n6. Layering rules")
check("Service does not use DatabaseFunction/DAO/Connection",
      not re.search(r"DatabaseFunction|PatientDAO|Connection|RequestStack|SessionInterface", strip_php(svc)))
check("Service does not read the session (actor is a parameter)", "AuthSessionService" not in svc)
check("Repository only depends on the DAO",
      re.search(r"use App\\DAO\\PatientDAO;", read("src/Repository/PatientRepository.php")) is not None
      and "DatabaseFunction" not in read("src/Repository/PatientRepository.php"))
check("DAO only depends on DatabaseFunction",
      "Repository" not in strip_php(dao) and "Connection" not in strip_php(dao))
def has_sql(path):
    """True if a non-comment line contains SQL (SELECT / FROM ecs_ / INSERT / UPDATE ... SET)."""
    for line in read(path).splitlines():
        if re.match(r"\s*(\*|//|/\*)", line):
            continue
        if re.search(r"\bSELECT\b|\bFROM\s+ecs_|\bINSERT\s+INTO\b|\bDELETE\s+FROM\b", line):
            return True
    return False


check("only DatabaseFunction contains SQL (DAO, Repository, Service, DTOs have none)",
      not any(has_sql(f) for f in ("src/DAO/PatientDAO.php", "src/Repository/PatientRepository.php",
                                   "src/Service/PatientService.php", "src/DTO/PatientDTO.php",
                                   "src/DTO/PatientListDTO.php")) and has_sql("src/DB/DatabaseFunction.php"))
check("no debugging calls (dd/dump/var_dump) in Stage 3 files",
      not any(re.search(r"\b(dd|dump|var_dump|print_r)\(", strip_php(read(f))) for f in PHP_FILES if exists(f)))
check("DatabaseFunction keeps the three original methods",
      all(f"function {m}(" in db for m in ("login", "getHomeModules", "getDoctorsList")))

# ----------------------------------------------------------------------
print("\n7. Test files")
for f in ("tests/Service/PatientServiceTest.php", "tests/DTO/PatientDTOTest.php"):
    t = read(f)
    tests = re.findall(r"public function (test\w+)\(", t)
    check(f"{f}: {len(tests)} test methods, no duplicate names", len(tests) == len(set(tests)) and len(tests) > 0)
    for prov in re.findall(r"#\[DataProvider\('(\w+)'\)\]", t):
        check(f"{f}: provider {prov}() exists and is public static",
              re.search(r"public static function %s\(\)" % prov, t) is not None)
check("phpunit.dist.xml exists and points at tests/ and vendor/autoload.php",
      exists("phpunit.dist.xml") and "vendor/autoload.php" in read("phpunit.dist.xml")
      and "<directory>tests</directory>" in read("phpunit.dist.xml"))

print("\n" + "-" * 60)
print(f"Passed: {PASSES}   Failed: {FAILS}")
sys.exit(1 if FAILS else 0)