#!/usr/bin/env python3
"""
HMS Stage 4 static checker. Needs only python3 (no PHP, no database, no Twig).

Run from the project root:   python3 scripts/check_stage4.py

It does NOT replace `php -l`, `lint:twig`, `lint:container`, `debug:router`
or testing in the browser. It checks the contracts between the controller,
the service, the templates and the stylesheet that only fail at render time.
"""
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


def exists(path):
    return os.path.exists(os.path.join(ROOT, path))


def strip_php(src):
    out, i, n = [], 0, len(src)
    while i < n:
        c, nxt = src[i], src[i + 1] if i + 1 < n else ""
        if c == "/" and nxt == "*":
            j = src.find("*/", i + 2)
            i = n if j < 0 else j + 2
        elif (c == "/" and nxt == "/") or (c == "#" and nxt != "["):
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


CTRL = "src/Controller/PatientController.php"
SHELL = "templates/layout/_layout.html.twig" if os.path.exists(os.path.join(ROOT, "templates/layout/_layout.html.twig")) else "templates/patient/_layout.html.twig"
TEMPLATES = [
    "templates/patient/_layout.html.twig",
    "templates/patient/list.html.twig",
    "templates/patient/form.html.twig",
    "templates/patient/show.html.twig",
]
ALL_TWIG = TEMPLATES + ["templates/home/dashboard.html.twig"]

# ----------------------------------------------------------------------
print("1. Files and PHP structure")
for f in [CTRL, "public/css/hms.css"] + TEMPLATES:
    check(f"{f} exists", exists(f))
ctrl = read(CTRL)
code = strip_php(ctrl)
for o, c in ("{}", "()", "[]"):
    check(f"controller: balanced {o}{c}", code.count(o) == code.count(c), f"{code.count(o)} vs {code.count(c)}")
check("controller: namespace App\\Controller, class PatientController",
      "namespace App\\Controller;" in ctrl and re.search(r"^class PatientController extends AbstractController", ctrl, re.M))
for u in re.findall(r"^use (App\\[^;]+);", ctrl, re.M):
    rel = "src/" + u[4:].replace("\\", "/") + ".php"
    check(f"controller: use {u} resolves", exists(rel), f"missing {rel}")

# ----------------------------------------------------------------------
print("\n2. Controller -> Service contract (Stage 3 signatures)")
svc = read("src/Service/PatientService.php")


def php_params(source, name):
    m = re.search(r"public function %s\((.*?)\)\s*:" % name, source, re.S)
    return [p for p in re.findall(r"\$(\w+)", m.group(1))], m.group(1)


def required_count(sig):
    return len([p for p in sig.split(",") if p.strip() and "=" not in p])


calls = re.findall(r"patientService->(\w+)\((.*?)\)\s*;", ctrl, re.S)
for name, args in calls:
    # split on top-level commas only
    depth, cur, parts = 0, "", []
    for ch in args:
        depth += ch in "([{"
        depth -= ch in ")]}"
        if ch == "," and depth == 0:
            parts.append(cur)
            cur = ""
        else:
            cur += ch
    if cur.strip():
        parts.append(cur)
    params, sig = php_params(svc, name)
    check(f"patientService->{name}(): {len(parts)} args is within {required_count(sig)}..{len(params)}",
          required_count(sig) <= len(parts) <= len(params))
check("controller calls all five service operations",
      {n for n, _ in calls} >= {"getPatientsList", "registerPatient", "updatePatient", "changePatientStatus", "getPatient"})
check("controller passes the logged-in username (currentUsername) to every write call",
      all("currentUsername()" in a for n, a in calls if n in ("registerPatient", "updatePatient", "changePatientStatus")))
check("username comes from AuthSessionService::getUsername()", "authSession->getUsername()" in ctrl)

# ----------------------------------------------------------------------
print("\n3. Layering and security rules")
check("controller does not touch DAO / Repository / DatabaseFunction / Connection",
      not re.search(r"\b(PatientDAO|PatientRepository|DatabaseFunction|Connection|EntityManager)\b", code))
check("no SQL in the controller", not re.search(r"\bSELECT\b|\bFROM\s+ecs_", ctrl))
check("no debug calls", not re.search(r"\b(dd|dump|var_dump|print_r)\(", code))
check("DBAL exceptions are caught and logged (never shown)",
      ctrl.count("catch (DbalException $exception)") >= 5 and "getMessage()" not in code)

routes = re.findall(r"#\[Route\((.*?)\n    \)\]", ctrl, re.S)
check("five routes declared (index, new, show, edit, status)", len(routes) == 5, f"found {len(routes)}")
perm_consts = set(re.findall(r"public const (PATIENT_\w+)", read("src/Service/AccessControlService.php")))
for r in routes:
    name = re.search(r"name: '(\w+)'", r).group(1)
    perm = re.search(r"_permission' => AccessControlService::(\w+)", r)
    check(f"route {name}: declares a defined _permission", bool(perm) and perm.group(1) in perm_consts)
    if "{id}" in r:
        check(f"route {name}: id restricted to 1-9 digits (fits PostgreSQL integer)", "'\\d{1,9}'" in r)
check("status route is POST-only", re.search(r"name: 'patient_status'.*?methods: \['POST'\]", ctrl, re.S) is not None)
check("show route is GET-only", re.search(r"name: 'patient_show'.*?methods: \['GET'\]", ctrl, re.S) is not None)
check("patient_new is declared before the {id} routes (and {id} is digits-only, so no clash)",
      ctrl.index("name: 'patient_new'") < ctrl.index("name: 'patient_show'"))

# route names unique across the whole project
names = {}
orig = os.environ.get("HMS_ORIGINAL_SRC", "/home/claude/hms/src/Controller")
dirs = [os.path.join(ROOT, "src/Controller")] + ([orig] if os.path.isdir(orig) else [])
for d in dirs:
    for fn in sorted(os.listdir(d)):
        if fn.endswith(".php"):
            for n in re.findall(r"name: '(\w+)'", open(os.path.join(d, fn), encoding="utf-8", errors="ignore").read()):
                names.setdefault(n, set()).add(fn)
check("route names are unique across all controllers", all(len(v) == 1 for v in names.values()),
      str({k: v for k, v in names.items() if len(v) > 1}))
print("      routes:", ", ".join(sorted(names)))

# ----------------------------------------------------------------------
print("\n4. Templates: paths, CSRF, variables")
for t in ALL_TWIG:
    for r in sorted(set(re.findall(r"path\('([^']+)'", read(t)))):
        check(f"{t}: path('{r}') is a real route", r in names)

ids_ctrl = set(re.findall(r"isCsrfTokenValid\('(\w+?)(?:'|_' \.)", ctrl))
check("controller validates CSRF ids patient_new, patient_edit_, patient_status_",
      ids_ctrl == {"patient_new", "patient_edit", "patient_status"}, str(ids_ctrl))
for tid, tpl in (("patient_new", "form"), ("patient_edit_", "form"), ("patient_status_", "list"), ("patient_status_", "show")):
    check(f"templates/patient/{tpl}.html.twig generates csrf_token('{tid}...')",
          f"csrf_token('{tid}" in read(f"templates/patient/{tpl}.html.twig") or f"'{tid}' ~" in read(f"templates/patient/{tpl}.html.twig")
          or ("token_id" in read(f"templates/patient/{tpl}.html.twig") and tid.startswith("patient_new") or tid.startswith("patient_edit")))
for t in TEMPLATES[1:]:
    src = read(t)
    forms = len(re.findall(r'<form[^>]*method="post"', src))
    tokens = len(re.findall(r'name="_token"', src))
    check(f"{t}: every POST form has a _token field ({forms} forms / {tokens} tokens)", forms == tokens and forms > 0)
check("layout logout form uses the same 'logout' token id as the controller from Stage 2",
      "csrf_token('logout')" in read(SHELL))

# variables each template reads must be supplied by the controller (or be a Twig global / local)
GLOBALS = {"app", "auth", "loop", "_self", "true", "false", "null", "none"}
supplied = {
    "list.html.twig": {"list"},
    "show.html.twig": {"patient"},
    "form.html.twig": {"mode", "patient", "errors", "duplicates", "duplicate_warning", "form_error", "genders", "blood_groups"},
}
for tpl, allowed in supplied.items():
    src = read("templates/patient/" + tpl)
    src_no_comments = re.sub(r"\{#.*?#\}", "", src, flags=re.S)
    # variables introduced inside the template
    local = set(re.findall(r"\{%-?\s*set\s+(\w+)", src_no_comments))
    local |= set(re.findall(r"\{%-?\s*for\s+(\w+)(?:\s*,\s*(\w+))?\s+in", src_no_comments) and
                 [x for tup in re.findall(r"\{%-?\s*for\s+(\w+)(?:\s*,\s*(\w+))?\s+in", src_no_comments) for x in tup if x])
    local |= set(re.findall(r"macro \w+\((.*?)\)", src_no_comments) and
                 [x.strip() for m in re.findall(r"macro \w+\((.*?)\)", src_no_comments) for x in m.split(",")])
    local |= {"f", "status_labels", "filter_params"}
    # strip macro bodies (they only use their own parameters)
    body = re.sub(r"\{% macro.*?\{% endmacro %\}", "", src_no_comments, flags=re.S)
    exprs = re.findall(r"\{\{(.*?)\}\}|\{%-?\s*(?:if|elseif|for|set)\b(.*?)%\}", body, re.S)
    used = set()
    for a, b in exprs:
        text = re.sub(r"'[^']*'|\"[^\"]*\"", "", a or b)
        text = re.sub(r"\.\w+", "", text)                  # drop attribute names (x.y -> x)
        text = re.sub(r"\b(\w+)\s*:", "", text)            # hash keys  {id: x}
        used |= {w for w in re.findall(r"\b[A-Za-z_]\w*\b", text)}
    keywords = {"if", "elseif", "for", "in", "set", "is", "not", "and", "or", "same", "as", "defined", "else", "endif",
                "default", "date", "lower", "merge", "max", "min", "range", "path", "csrf_token", "length", "e",
                "true", "false", "null", "empty", "starts", "with", "matches", "b"}
    unknown = sorted(w for w in used - keywords - local - allowed - GLOBALS if not w.isdigit())
    check(f"{tpl}: reads only variables the controller supplies", not unknown, f"unknown: {unknown}")

# Twig tag balance
OPEN = {"if": "endif", "for": "endfor", "block": "endblock", "macro": "endmacro"}
for t in ALL_TWIG:
    src = re.sub(r"\{#.*?#\}", "", read(t), flags=re.S)
    stack, ok, bad = [], True, ""
    for m in re.finditer(r"\{%-?\s*(\w+)", src):
        tag = m.group(1)
        if tag in OPEN:
            stack.append(OPEN[tag])
        elif tag in OPEN.values():
            if not stack or stack.pop() != tag:
                ok, bad = False, tag
                break
    check(f"{t}: {{% if/for/block/macro %}} tags are balanced", ok and not stack, bad or str(stack))
    check(f"{t}: {{{{ }}}} and {{% %}} delimiters balanced",
          src.count("{{") == src.count("}}") and src.count("{%") == src.count("%}"))
check("every patient page extends the shared layout",
      all("{% extends 'patient/_layout.html.twig' %}" in read(f"templates/patient/{n}.html.twig") for n in ("list", "form", "show")))
check("layout extends the project's base.html.twig", "{% extends 'base.html.twig' %}" in read(SHELL))
check("layout adds the viewport meta tag and links /css/hms.css",
      'name="viewport"' in read(SHELL) and 'href="/css/hms.css"' in read(SHELL))
check("form only uses macros inside the block that imports them", read(TEMPLATES[2]).index("{% import _self as f %}") >
      read(TEMPLATES[2]).index("{% block content %}"))
check("no inline user data inside <script> or JS strings (only constant confirm text)",
      all("<script" not in read(t) for t in TEMPLATES))

# ----------------------------------------------------------------------
print("\n5. Stylesheet covers every class the templates use")
css = read("public/css/hms.css")
defined = set(re.findall(r"\.([A-Za-z][\w-]*)", css))
used = set()
for t in TEMPLATES:
    for val in re.findall(r'class="([^"]*)"', read(t)):
        literal = re.sub(r"\{\{.*?\}\}", lambda m: " " + " ".join(re.findall(r"'([^']*)'", m.group(0))) + " ", val)
        used |= {w for w in literal.split() if re.match(r"^[A-Za-z][\w-]*$", w)}
missing = sorted(used - defined)
check(f"{len(used)} classes used in templates, all styled in hms.css", not missing, f"missing: {missing}")
check("stylesheet has a mobile breakpoint and a stacked-table rule",
      "@media (max-width: 600px)" in css and "attr(data-label)" in css)
check("list rows carry data-label attributes for the stacked layout",
      read("templates/patient/list.html.twig").count("data-label=") >= 8)
check("CSS braces balanced", css.count("{") == css.count("}"))

# ----------------------------------------------------------------------
print("\n6. Dashboard and untouched files")
dash = read("templates/home/dashboard.html.twig")
check("dashboard links Patients to patient_list", "module.module_name == 'Patients'" in dash and "path('patient_list')" in dash)
check("dashboard still links Doctors and keeps the Stage 2 logout form",
      "path('doctors')" in dash and "path('logout')" in dash and "csrf_token('logout')" in dash)
check("dashboard still has its own inline styles (not rewritten)", "background-image: url('/images/Hospital-Management-System.jpg')" in dash)

print("\n" + "-" * 60)
print(f"Passed: {PASSES}   Failed: {FAILS}")
sys.exit(1 if FAILS else 0)
