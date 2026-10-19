#!/usr/bin/env python3
"""
HMS FDM (Stages 3-5) static checker. Needs only python3: no PHP, Twig or database.

Run from the project root:   python3 scripts/check_fdm_app.py

It does NOT replace `php -l`, `lint:container`, `lint:twig`, PHPUnit or testing in the
browser. It checks the contracts between the layers, the templates and the SQL
function signatures, which otherwise fail only when a page is rendered or a query runs.
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


def exists(path):
    return os.path.exists(os.path.join(ROOT, path))


# ------------------------------------------------------------------ lexing helpers
def strip_php(src, keep_strings=False):
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
            q, j = c, i + 1
            while j < n and src[j] != q:
                j += 2 if src[j] == "\\" else 1
            out.append(src[i: j + 1] if keep_strings else q + q)
            i = j + 1
        else:
            out.append(c)
            i += 1
    return "".join(out)


def match_paren(text, open_idx):
    depth, i, n = 0, open_idx, len(text)
    while i < n:
        c = text[i]
        if c in ("'", '"'):
            q, i = c, i + 1
            while i < n and text[i] != q:
                i += 2 if text[i] == "\\" else 1
        elif c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    raise ValueError("unbalanced")


def split_top(text):
    parts, depth, cur, i, n = [], 0, "", 0, len(text)
    while i < n:
        c = text[i]
        if c in ("'", '"'):
            q, j = c, i + 1
            while j < n and text[j] != q:
                j += 2 if text[j] == "\\" else 1
            cur += text[i: j + 1]
            i = j + 1
            continue
        if c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
        if c == "," and depth == 0:
            parts.append(cur)
            cur = ""
        else:
            cur += c
        i += 1
    if cur.strip():
        parts.append(cur)
    return [p.strip() for p in parts]


def camel_to_snake(name):
    return re.sub(r"(?<!^)(?=[A-Z])", "_", name).lower()


def php_methods(path, include_private=False):
    """{name: {'params': [names], 'required': n, 'public': bool}} for a PHP class file."""
    code = strip_php(read(path), keep_strings=True)
    methods = {}
    for m in re.finditer(r"\b(public|private|protected)\s+(?:static\s+)?function\s+(\w+)\s*\(", code):
        vis, name = m.group(1), m.group(2)
        close = match_paren(code, m.end() - 1)
        params = split_top(code[m.end(): close])
        names, required = [], 0
        for p in params:
            pm = re.search(r"\$(\w+)", p)
            names.append(pm.group(1))
            if "=" not in p:
                required += 1
        methods[name] = {"params": names, "required": required, "public": vis == "public"}
    return methods


def class_members(path):
    """Public properties and methods of a class (incl. promoted constructor properties)."""
    code = strip_php(read(path), keep_strings=True)
    props = set(re.findall(r"\bpublic\s+(?:readonly\s+)?(?:\??[\w\\|]+\s+)\$(\w+)", code))
    methods = {n for n, v in php_methods(path).items() if v["public"]}
    return props, methods


def calls(code, prop):
    """[(method, [args])] for every  $this->prop->method(...)  call."""
    found = []
    for m in re.finditer(r"\$this->%s->(\w+)\(" % re.escape(prop), code):
        close = match_paren(code, m.end() - 1)
        found.append((m.group(1), split_top(code[m.end(): close])))
    return found


# ------------------------------------------------------------------ files
F = {
    "ctrl": "src/Controller/FDMController.php",
    "service": "src/Service/FDMService.php",
    "repo": "src/Repository/FDMRepository.php",
    "dao": "src/DAO/FDMDAO.php",
    "db": "src/DB/FDMDatabaseFunction.php",
}
DTOS = ["AppointmentDTO", "AppointmentListDTO", "QueueEntryDTO", "QueueListDTO", "FDMDashboardDTO", "FDMReportDTO"]
PHP_FILES = list(F.values()) + [f"src/DTO/{d}.php" for d in DTOS] + [
    "src/Service/AccessControlService.php", "tests/Service/FDMServiceTest.php", "tests/DTO/FDMDTOTest.php"]
TEMPLATES = ["templates/layout/_layout.html.twig", "templates/patient/_layout.html.twig", "templates/fdm/_layout.html.twig",
             "templates/fdm/_macros.html.twig", "templates/fdm/index.html.twig", "templates/fdm/appointments.html.twig",
             "templates/fdm/appointment_form.html.twig", "templates/fdm/appointment_show.html.twig",
             "templates/fdm/check_in.html.twig", "templates/fdm/queue.html.twig", "templates/fdm/reports.html.twig",
             "templates/home/dashboard.html.twig"]
PAGES = [t for t in TEMPLATES if re.search(r"fdm/(index|appointments|appointment_form|appointment_show|check_in|queue|reports)", t)]

# ================================================================== 1
print("1. PHP structure")
for f in PHP_FILES:
    if not exists(f):
        check(f"{f} exists", False)
        continue
    raw = read(f)
    code = strip_php(raw)
    check(f"{f}: starts with <?php", raw.startswith("<?php"))
    ok = all(code.count(o) == code.count(c) for o, c in ("{}", "()", "[]"))
    check(f"{f}: balanced braces, parentheses and brackets", ok)
    ns = re.search(r"^namespace ([^;]+);", raw, re.M)
    cls = re.search(r"^(?:final )?class (\w+)", raw, re.M)
    d = os.path.dirname(f)
    exp = ("App\\" + d[4:].replace("/", "\\")) if d.startswith("src") else ("App\\Tests\\" + d[6:].replace("/", "\\"))
    check(f"{f}: namespace and class name match the path",
          bool(ns and cls) and ns.group(1) == exp and cls.group(1) == os.path.basename(f)[:-4])
    for u in re.findall(r"^use (App\\[^;]+);", raw, re.M):
        check(f"{f}: use {u.split(chr(92))[-1]} resolves", exists("src/" + u[4:].replace("\\", "/") + ".php"))
    check(f"{f}: no debugging calls", not re.search(r"\b(dd|dump|var_dump|print_r)\(", code))

# ================================================================== 2
print("\n2. SQL contract: FDMDatabaseFunction <-> sql/fdm_function_signatures.json")
contract = json.load(open(os.path.join(ROOT, "sql/fdm_function_signatures.json"), encoding="utf-8"))
db_code = strip_php(read(F["db"]), keep_strings=True)
db_methods = php_methods(F["db"])
used_sql = {}
for m in re.finditer(r"'SELECT (?:\* FROM )?(?:public\.)?(ecs_\w+)\(", db_code):
    fn = m.group(1)
    # method that owns this statement
    owner = [n for n, pos in ((n, db_code.find(f"function {n}(")) for n in db_methods) if pos != -1 and pos < m.start()]
    owner = max(owner, key=lambda n: db_code.find(f"function {n}("))
    close = match_paren(db_code, db_code.index("(", m.start() + 7 + len(fn)))
    args = db_code[db_code.index("(", m.start() + 7 + len(fn)) + 1: close]
    casts = re.findall(r"CAST\(:(\w+) AS ([a-z ]+?)\)", args)
    used_sql[owner] = fn
    sig = contract.get(fn)
    check(f"{owner}() calls {fn}: function exists in the installed contract", sig is not None)
    if sig is None:
        continue
    check(f"{owner}(): {len(sig['params'])} SQL arguments", len(casts) == len(sig["params"]), f"PHP passes {len(casts)}")
    check(f"{owner}(): CAST types and order match the SQL signature",
          [t for _, t in casts] == [p["type"] for p in sig["params"]],
          f"PHP {[t for _, t in casts]} / SQL {[p['type'] for p in sig['params']]}")
    check(f"{owner}(): bind names are the SQL parameter names without 'p_', in order",
          [b for b, _ in casts] == [p["name"][2:] for p in sig["params"]],
          f"PHP {[b for b, _ in casts]} / SQL {[p['name'][2:] for p in sig['params']]}")
    body_start = db_code.index(f"function {owner}(")
    nxt = [db_code.find(f"function {n}(") for n in db_methods if db_code.find(f"function {n}(") > body_start]
    body = db_code[body_start: min(nxt) if nxt else len(db_code)]
    binds = re.findall(r"'(\w+)'\s*=>\s*([^,\n\]]+?)\s*[,\]]", body)
    names = [b for b, _ in binds if not b.startswith("SELECT")]
    check(f"{owner}(): every bind is supplied exactly once", names == [b for b, _ in casts], str(names))
    bad = []
    for b, v in binds:
        vm = re.search(r"\$(\w+)", v)
        if vm and camel_to_snake(vm.group(1)) != b:
            bad.append((b, v))
    check(f"{owner}(): each bind is fed by the PHP parameter of the same name", not bad, str(bad))
    pnames = [camel_to_snake(p) for p in db_methods[owner]["params"]]
    check(f"{owner}(): PHP parameter order equals SQL parameter order", pnames == [p["name"][2:] for p in sig["params"]],
          f"{pnames} / {[p['name'][2:] for p in sig['params']]}")
check("every function the application needs is called (23 installed, 19 used directly + hospital_today)",
      {"createAppointment", "updateAppointment", "cancelAppointment", "markAppointmentNoShow", "getAppointmentById",
       "getAppointmentsList", "countAppointments", "checkInAppointment", "checkInWalkIn", "getQueueList", "countQueue",
       "updateQueueStatus", "getDashboardSummary", "reportRegistrations", "reportAppointments",
       "reportCancellations", "reportQueueSummary"} <= set(used_sql))
check("getHospitalToday() calls the installed ecs_hospital_today()",
      "ecs_hospital_today()" in db_code and "ecs_hospital_today" in contract)
check("FDMDatabaseFunction is the only FDM class that contains SQL",
      all(not re.search(r"\bSELECT\b|\bFROM\s+ecs_", strip_php(read(F[k]), keep_strings=True)) for k in ("ctrl", "service", "repo", "dao")))

# ================================================================== 3
print("\n3. Layer-to-layer calls (names and argument counts)")
LAYERS = [("dao", "fdmDatabaseFunction", "db"), ("repo", "fdmDAO", "dao"), ("service", "fdmRepository", "repo"),
          ("ctrl", "fdmService", "service")]
for caller, prop, callee in LAYERS:
    cm = php_methods(F[callee])
    found = calls(strip_php(read(F[caller]), keep_strings=True), prop)
    bad = []
    for name, args in found:
        if name not in cm or not cm[name]["public"]:
            bad.append(f"{name}: not a public method of {os.path.basename(F[callee])}")
        elif not cm[name]["required"] <= len(args) <= len(cm[name]["params"]):
            bad.append(f"{name}: {len(args)} args, expects {cm[name]['required']}..{len(cm[name]['params'])}")
    check(f"{os.path.basename(F[caller])} -> {os.path.basename(F[callee])}: {len(found)} calls valid", found and not bad, "; ".join(bad))

dao_calls = dict(calls(strip_php(read(F["dao"]), keep_strings=True), "fdmDatabaseFunction"))
dbm = php_methods(F["db"])
dao_bad = []
for m in re.finditer(r"public function (\w+)\(", read(F["dao"])):
    name = m.group(1)
    block = read(F["dao"])[m.start():]
    call = re.search(r"fdmDatabaseFunction->%s\((.*?)\);" % name, block, re.S)
    if not call:
        continue
    args = [re.sub(r".*?(?:->|\$)(\w+)\s*$", r"\1", re.sub(r"^\(\w+\)\s*", "", a.strip())) for a in split_top(call.group(1)) if a.strip()]
    if args != dbm[name]["params"]:
        dao_bad.append(f"{name}: DAO passes {args} / DatabaseFunction expects {dbm[name]['params']}")
check("FDMDAO passes arguments in the order FDMDatabaseFunction declares them", not dao_bad, "; ".join(dao_bad))

ctrl_service_calls = {n for n, _ in calls(strip_php(read(F["ctrl"]), keep_strings=True), "fdmService")}
svc_public = {n for n, v in php_methods(F["service"]).items() if v["public"]}
unused = sorted(svc_public - ctrl_service_calls - {"__construct", "getToday"})
check("every public FDMService operation is used by the controller", not unused, str(unused))

# ================================================================== 4
print("\n4. Controller: routes, authorization, CSRF, HTTP methods")
ctrl_raw = read(F["ctrl"])
ctrl_code = strip_php(ctrl_raw, keep_strings=True)
routes = {}
for m in re.finditer(r"#\[Route\((.*?)\n    \)\]\s*public function (\w+)\(", ctrl_raw, re.S):
    attrs, action = m.group(1), m.group(2)
    routes[re.search(r"name: '(\w+)'", attrs).group(1)] = {
        "path": re.search(r"^\s*'(/[^']*)'", attrs, re.M).group(1),
        "methods": re.findall(r"'(GET|POST)'", re.search(r"methods: \[(.*?)\]", attrs).group(1)),
        "perm": re.search(r"_permission' => AccessControlService::(\w+)", attrs).group(1) if "_permission" in attrs else None,
        "id_ok": "{id}" not in attrs or "'\\d{1,9}'" in attrs,
        "action": action,
    }
check("13 routes declared", len(routes) == 13, str(sorted(routes)))
acs_src = read("src/Service/AccessControlService.php")
consts = set(re.findall(r"public const (FDM_\w+)\s*=", acs_src))
defined_list = set(re.findall(r"self::(FDM_\w+)", acs_src.split("DEFINED_PERMISSIONS")[1]))
check("every route declares a permission that AccessControlService defines",
      all(r["perm"] in consts for r in routes.values()), str({n: r["perm"] for n, r in routes.items() if r["perm"] not in consts}))
check("every FDM permission constant is listed in DEFINED_PERMISSIONS (else it would be denied)", consts == defined_list,
      str(consts ^ defined_list))
check("every {id} route restricts the id to 1-9 digits", all(r["id_ok"] for r in routes.values()))
check("fdm_appointment_new is declared before the {id} routes",
      ctrl_raw.index("'fdm_appointment_new'") < ctrl_raw.index("'fdm_appointment_show'"))

WRITES = {"createAppointment", "updateAppointment", "cancelAppointment", "markNoShow", "checkInAppointment",
          "checkInWalkIn", "updateQueueStatus"}
post_only = {n for n, r in routes.items() if r["methods"] == ["POST"]}
for name, r in routes.items():
    m = re.search(r"public function %s\(.*?\n    \}\n" % r["action"], ctrl_code, re.S)
    body = m.group(0)
    writes_here = {c for c, _ in calls(body, "fdmService") if c in WRITES}
    # private helpers reached from this action
    for helper in re.findall(r"\$this->(\w+)\(", body):
        hm = re.search(r"private function %s\(.*?\n    \}\n" % helper, ctrl_code, re.S)
        if hm:
            writes_here |= {c for c, _ in calls(hm.group(0), "fdmService") if c in WRITES}
            if "isCsrfTokenValid" in hm.group(0):
                body += hm.group(0)
    if writes_here:
        check(f"{name}: state change ({', '.join(sorted(writes_here))}) is only reachable by POST",
              "POST" in r["methods"] and ("GET" not in r["methods"] or "isMethod('POST')" in body), str(r["methods"]))
        check(f"{name}: validates a CSRF token", "isCsrfTokenValid(" in body)
    else:
        check(f"{name}: read-only route allows GET", "GET" in r["methods"])
for n in ("fdm_appointment_cancel", "fdm_appointment_no_show", "fdm_check_in_appointment", "fdm_walk_in", "fdm_queue_status"):
    check(f"{n} is POST-only", routes[n]["methods"] == ["POST"])

existing_routes = {"home", "doctors", "logout", "login", "dashboard", "patient_list", "patient_new", "patient_show",
                   "patient_edit", "patient_status"}
all_names = {}
for d in ("src/Controller",):
    for fn in sorted(os.listdir(os.path.join(ROOT, d))):
        if fn.endswith(".php"):
            for n in re.findall(r"name: '(\w+)'", read(f"{d}/{fn}")):
                all_names.setdefault(n, []).append(fn)
check("route names are unique across all controllers", all(len(v) == 1 for v in all_names.values()),
      str({k: v for k, v in all_names.items() if len(v) > 1}))
check("the Patient and Doctor routes still exist", existing_routes <= set(all_names), str(existing_routes - set(all_names)))
check("controller does not touch DAO / Repository / Connection / SQL",
      not re.search(r"\b(FDMDAO|FDMRepository|PatientDAO|DatabaseFunction|Connection)\b", strip_php(ctrl_raw)))
check("controller never shows raw exception text", "getMessage()" not in ctrl_code)
check("DBAL exceptions are caught and logged", ctrl_code.count("catch (DbalException $exception)") >= 12)
check("the logged-in username is passed to every write call",
      all("currentUsername()" in " ".join(a) for c, a in calls(ctrl_code, "fdmService") if c in WRITES))

# ================================================================== 5
print("\n5. Templates")
for t in TEMPLATES:
    if not exists(t):
        check(f"{t} exists", False)
        continue
    src = re.sub(r"\{#.*?#\}", "", read(t), flags=re.S)
    OPEN = {"if": "endif", "for": "endfor", "block": "endblock", "macro": "endmacro"}
    stack, ok = [], True
    for m in re.finditer(r"\{%-?\s*(\w+)", src):
        tag = m.group(1)
        if tag in OPEN:
            stack.append(OPEN[tag])
        elif tag in OPEN.values():
            if not stack or stack.pop() != tag:
                ok = False
                break
    check(f"{t}: tags balanced and delimiters paired",
          ok and not stack and src.count("{{") == src.count("}}") and src.count("{%") == src.count("%}"))
    for r in sorted(set(re.findall(r"path\('([^']+)'", src))):
        check(f"{t}: path('{r}') is a real route", r in all_names or r in routes)
    for ext in re.findall(r"\{% extends '([^']+)' %\}", src):
        check(f"{t}: extends {ext} (exists)", exists("templates/" + ext))
    for imp in re.findall(r"\{% import '([^']+)' as ui %\}", src):
        check(f"{t}: imports {imp} (exists)", exists("templates/" + imp))
        check(f"{t}: the import sits inside a block (macros are not visible in blocks otherwise)",
              src.index("{% import") > src.index("{% block"))

macros_src = read("templates/fdm/_macros.html.twig")
defined_macros = set(re.findall(r"\{% macro (\w+)\(", macros_src))
used_macros = set()
for t in PAGES:
    used_macros |= set(re.findall(r"\bui\.(\w+)\(", read(t)))
check("every ui.macro() call names a macro that exists", used_macros <= defined_macros, str(used_macros - defined_macros))
check("layout: extends base.html.twig, has content block and loads fdm.js",
      "{% extends 'base.html.twig' %}" in read(TEMPLATES[0]) and "{% block content %}" in read(TEMPLATES[0])
      and "/js/fdm.js" in read(TEMPLATES[0]))
check("patient/_layout.html.twig is now a one-line extension of layout/_layout.html.twig",
      "{% extends 'layout/_layout.html.twig' %}" in read(TEMPLATES[1]) and "{% block" not in read(TEMPLATES[1]))
check("layout navigation links Front Desk and Doctors", "path('fdm_dashboard')" in read(TEMPLATES[0]) and "path('doctors')" in read(TEMPLATES[0]))
check("Patient pages still extend patient/_layout.html.twig",
      all("{% extends 'patient/_layout.html.twig' %}" in read(f"templates/patient/{n}.html.twig") for n in ("list", "form", "show")))
dash = read("templates/home/dashboard.html.twig")
check("dashboard links the FDM tile and still links Doctors and Patients",
      "path('fdm_dashboard')" in dash and "path('doctors')" in dash and "path('patient_list')" in dash)

# ---- CSRF ids
ctrl_ids = set()
for m in re.finditer(r"isCsrfTokenValid\('(\w+?)(_?)'(\s*\.\s*\$id)?", ctrl_code):
    ctrl_ids.add(m.group(1) + ("_" if m.group(3) else ""))
ctrl_ids = {i for i in ctrl_ids}
tpl_ids = set()
for t in PAGES:
    for m in re.finditer(r"csrf_token\(('(\w+?)'|token_id)", read(t)):
        if m.group(2):
            tpl_ids.add(m.group(2))
    for m in re.finditer(r"csrf_token\('(\w+_)' ~", read(t)):
        tpl_ids.add(m.group(1))
tpl_ids.add("fdm_appointment_new")
tpl_ids.add("fdm_appointment_edit_")  # built through token_id in appointment_form
check("every CSRF id the controller validates is generated in a template",
      {i.rstrip("_") for i in ctrl_ids} <= {i.rstrip("_") for i in tpl_ids}, str(ctrl_ids - tpl_ids))
form_tpl = read("templates/fdm/appointment_form.html.twig")
check("appointment form builds its token id as fdm_appointment_new / fdm_appointment_edit_{id}",
      "'fdm_appointment_new'" in form_tpl and "'fdm_appointment_edit_' ~" in form_tpl)
for t in PAGES:
    src = read(t)
    forms = len(re.findall(r'<form[^>]*method="post"', src))
    tokens = len(re.findall(r'name="_token"', src))
    if forms:
        check(f"{t}: every POST form has a _token field ({forms}/{tokens})", forms == tokens)
check("layout's logout form carries the Stage 2 token", "csrf_token('logout')" in read(TEMPLATES[0]))

# ---- variables passed to each page
GLOBALS = {"app", "auth", "loop", "_self", "ui", "true", "false", "null", "none"}
KEYWORDS = {"if", "elseif", "else", "endif", "for", "endfor", "in", "set", "is", "not", "and", "or", "same", "as", "defined",
            "empty", "null", "starts", "with", "ends", "matches", "default", "date", "lower", "merge", "max", "min", "range",
            "path", "csrf_token", "length", "e", "slice", "true", "false", "import", "macro", "endmacro", "block", "endblock"}
render_keys = {}
for m in re.finditer(r"\$this->render\('(fdm/\w+\.html\.twig)', \[(.*?)\](?:, new Response|\);)", ctrl_code, re.S):
    render_keys[m.group(1)] = set(re.findall(r"'(\w+)'\s*=>", m.group(2)))
check("controller renders all seven FDM pages",
      set(render_keys) == {f"fdm/{n}.html.twig" for n in ("index", "appointments", "appointment_form", "appointment_show", "check_in", "queue", "reports")},
      str(sorted(render_keys)))
for tpl, supplied in render_keys.items():
    path = "templates/" + tpl
    src = re.sub(r"\{#.*?#\}", "", read(path), flags=re.S)
    local = set(re.findall(r"\{%-?\s*set\s+(\w+)", src))
    for a, b in re.findall(r"\{%-?\s*for\s+(\w+)(?:\s*,\s*(\w+))?\s+in", src):
        local |= {a, b} - {""}
    body = re.sub(r"\{% macro.*?\{% endmacro %\}", "", src, flags=re.S)
    used = set()
    for a, b in re.findall(r"\{\{(.*?)\}\}|\{%-?\s*(?:if|elseif|for|set)\b(.*?)%\}", body, re.S):
        text = re.sub(r"'[^']*'|\"[^\"]*\"", "", a or b)
        text = re.sub(r"\.\w+", "", text)
        text = re.sub(r"\b(\w+)\s*:", "", text)
        used |= set(re.findall(r"\b[A-Za-z_]\w*\b", text))
    unknown = sorted(w for w in used - KEYWORDS - local - supplied - GLOBALS if not w.isdigit())
    check(f"{path}: reads only variables the controller supplies", not unknown, f"unknown: {unknown}")
    unused = sorted(supplied - used)
    check(f"{path}: every variable the controller supplies is used", not unused, f"unused: {unused}")

# ---- properties and methods read on DTOs
members = {d: class_members(f"src/DTO/{d}.php") for d in DTOS}
members["PatientDTO"] = class_members("src/DTO/PatientDTO.php")
members["PatientListDTO"] = class_members("src/DTO/PatientListDTO.php")


def twig_has(cls, attr):
    props, methods = members[cls]
    cap = attr[:1].upper() + attr[1:]
    return attr in props or attr in methods or any(p + cap in methods for p in ("get", "is", "has", "can"))


# (template, variable) -> class. Loop variables are listed explicitly.
TYPES = {
    "fdm/index.html.twig": {"dashboard": "FDMDashboardDTO"},
    "fdm/appointments.html.twig": {"list": "AppointmentListDTO", "a": "AppointmentDTO"},
    "fdm/appointment_form.html.twig": {"appointment": "AppointmentDTO", "patient": "PatientDTO", "patients": "PatientListDTO", "p": "PatientDTO"},
    "fdm/appointment_show.html.twig": {"appointment": "AppointmentDTO"},
    "fdm/check_in.html.twig": {"appointments": "AppointmentListDTO", "a": "AppointmentDTO", "walkin_patient": "PatientDTO",
                               "patient_results": "PatientListDTO", "p": "PatientDTO"},
    "fdm/queue.html.twig": {"queue": "QueueListDTO", "e": "QueueEntryDTO"},
    "fdm/reports.html.twig": {"report": "FDMReportDTO"},
}
bad_attr = []
total_attr = 0
for tpl, types in TYPES.items():
    src = re.sub(r"\{#.*?#\}", "", read("templates/" + tpl), flags=re.S)
    for var, cls in types.items():
        for attr in set(re.findall(r"(?<![\w.])%s\.(\w+)" % re.escape(var), src)):
            total_attr += 1
            if not twig_has(cls, attr):
                bad_attr.append(f"{tpl}: {var}.{attr} is not a property or method of {cls}")
check(f"all {total_attr} DTO property/method reads in the templates exist", not bad_attr, "; ".join(bad_attr[:5]))

# ---- report rows and totals
rep = read("templates/fdm/reports.html.twig")
SECTION_FN = {"registrations": "ecs_report_registrations", "appointments": "ecs_report_appointments",
              "cancellations": "ecs_report_cancellations", "queue": "ecs_report_queue_summary"}
row_bad = []
for section, fn in SECTION_FN.items():
    m = re.search(r"\{% for row in report\.SECTION %\}(.*?)\{% endfor %\}".replace("SECTION", section), rep, re.S)
    cols = {c["name"] for c in contract[fn]["returns_table"]}
    for attr in set(re.findall(r"row\.(\w+)", m.group(1))):
        if attr not in cols:
            row_bad.append(f"report.{section}: row.{attr} is not a column of {fn}")
check("every report row field exists in its SQL function's result columns", not row_bad, "; ".join(row_bad))
svc_src = read(F["service"])
tot_a = re.search(r"sumColumns\(\$appointments, \[(.*?)\]\)", svc_src).group(1)
tot_q = re.search(r"sumColumns\(\$queue, \[(.*?)\]\)", svc_src).group(1)
tot_a, tot_q = set(re.findall(r"'(\w+)'", tot_a)), set(re.findall(r"'(\w+)'", tot_q))
check("report total keys used in the template are the ones the service computes",
      set(re.findall(r"appointmentTotals\.(\w+)", rep)) <= tot_a and set(re.findall(r"queueTotals\.(\w+)", rep)) <= tot_q)
check("the service's total columns exist in the SQL results",
      tot_a <= {c["name"] for c in contract["ecs_report_appointments"]["returns_table"]}
      and tot_q <= {c["name"] for c in contract["ecs_report_queue_summary"]["returns_table"]})
check("the DTO factories read the real SQL result columns",
      all(c in read("src/DTO/FDMDashboardDTO.php") for c in (x["name"] for x in contract["ecs_get_fdm_dashboard_summary"]["returns_table"]))
      and all(c in read("src/DTO/QueueEntryDTO.php") for c in (x["name"] for x in contract["ecs_get_queue_list"]["returns_table"]))
      and all(c in read("src/DTO/AppointmentDTO.php") for c in (x["name"] for x in contract["ecs_get_appointment_by_id"]["returns_table"])))

# ================================================================== 6
print("\n6. Stylesheet, JavaScript, configuration")
css = read("public/css/hms.css")
defined = set(re.findall(r"\.([A-Za-z][\w-]*)", css))
used = set()
for t in PAGES + [TEMPLATES[0], TEMPLATES[2], TEMPLATES[3]]:
    src = read(t)
    for val in re.findall(r'class="([^"]*)"', src):
        lit = re.sub(r"\{\{.*?\}\}", lambda m: " " + " ".join(re.findall(r"'([^']*)'", m.group(0))) + " ", val)
        lit = re.sub(r"\{%.*?%\}", " ", lit)
        used |= {w for w in lit.split() if re.match(r"^[A-Za-z][\w-]*$", w)}
    for m in re.finditer(r"'(badge-[\w-]+)'", src):
        used.add(m.group(1))
missing = sorted(used - defined)
check(f"{len(used)} CSS classes used by the FDM pages, all styled in hms.css", not missing, f"missing: {missing}")
check("hms.css braces balanced and the Patient styles are intact",
      css.count("{") == css.count("}") and all(c in css for c in (".hms-topbar", ".table-wrap", ".pager", ".detail-grid", ".badge-active")))
js = read("public/js/fdm.js")
attrs = set()
for t in PAGES:
    attrs |= set(re.findall(r"\b(data-confirm|data-auto-refresh)=", read(t)))
check("the data- attributes the templates use are handled by fdm.js", all(a in js for a in attrs), str(attrs))
check("fdm.js never issues requests (state changes are plain POST forms)",
      not re.search(r"fetch\(|XMLHttpRequest|\.ajax|sendBeacon", js))
twig_yaml = read("config/packages/twig.yaml")
check("twig.yaml sets the hospital time zone and keeps the auth global",
      "timezone: 'Asia/Kolkata'" in twig_yaml and "AuthSessionService" in twig_yaml)

# ================================================================== 7
print("\n7. Tests refer to real code")
svc_methods = php_methods(F["service"])
tsrc = strip_php(read("tests/Service/FDMServiceTest.php"), keep_strings=True)
bad = []
for name, args in calls(tsrc, "service"):
    if name not in svc_methods or not svc_methods[name]["public"]:
        bad.append(f"{name}: no such public FDMService method")
    elif not svc_methods[name]["required"] <= len(args) <= len(svc_methods[name]["params"]):
        bad.append(f"{name}: {len(args)} args, expects {svc_methods[name]['required']}..{len(svc_methods[name]['params'])}")
check("every FDMService call in the tests matches the real signature", not bad, "; ".join(bad))
repo_methods = set(php_methods(F["repo"]))
mocked = set(re.findall(r"\$this->repository(?:->expects\([^)]*\))?->method\('(\w+)'\)", tsrc))
check("every FDMRepository method the tests mock exists", mocked <= repo_methods, str(mocked - repo_methods))
ps_methods = set(php_methods("src/Service/PatientService.php"))
ds_methods = set(php_methods("src/Service/DoctorService.php"))
check("the PatientService / DoctorService methods the tests mock exist",
      set(re.findall(r"\$this->patientService(?:->expects\([^)]*\))?->method\('(\w+)'\)", tsrc)) <= ps_methods
      and set(re.findall(r"\$this->doctorService(?:->expects\([^)]*\))?->method\('(\w+)'\)", tsrc)) <= ds_methods)
for tf in ("tests/Service/FDMServiceTest.php", "tests/DTO/FDMDTOTest.php"):
    t = read(tf)
    names = re.findall(r"public function (test\w+)\(", t)
    check(f"{tf}: {len(names)} tests, no duplicate names", len(names) == len(set(names)) and names)
    for prov in re.findall(r"#\[DataProvider\('(\w+)'\)\]", t):
        check(f"{tf}: provider {prov}() is public static", re.search(r"public static function %s\(\)" % prov, t) is not None)
dto_calls = []
for dto in ("AppointmentDTO", "QueueEntryDTO", "FDMDashboardDTO"):
    for fm in re.findall(r"%s::(\w+)\(" % dto, read("tests/DTO/FDMDTOTest.php") + read("tests/Service/FDMServiceTest.php")):
        if fm not in php_methods(f"src/DTO/{dto}.php"):
            dto_calls.append(f"{dto}::{fm}")
check("static DTO factories the tests call exist", not dto_calls, str(dto_calls))

print("\n" + "-" * 60)
print(f"Passed: {PASSES}   Failed: {FAILS}")
sys.exit(1 if FAILS else 0)
