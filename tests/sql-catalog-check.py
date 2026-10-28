#!/usr/bin/env python3
"""
اعتبارسنجی SQL با موتور واقعی — بدون نیاز به MySQL.

چرا این‌طور: در این محیط هیچ راهی برای گرفتن سرور MySQL/MariaDB نیست
(downloads.mariadb.org، cdn.mysql.com و حتی GitHub Releases همه بسته‌اند).
بنابراین دو چیز واقعی را کنار هم می‌گذاریم:

  ۱. گرامر واقعی MySQL  ← sqlglot با dialect="mysql"
  ۲. موتور SQL واقعی    ← DuckDB: جدول‌ها ساخته می‌شوند، seedها درج می‌شوند و
     هر کوئری با EXPLAIN اعتبارسنجی می‌شود (خطای «جدول/ستون وجود ندارد» واقعی است).

نتیجه: خطاهایی مثل «جدول clients ساخته نشده» یا «ستون فلان در فلان جدول نیست»
و همچنین خطای نحوی/نوعی در DDL و seedها گرفته می‌شود.

محدودیت صادقانه: DuckDB موتور MySQL نیست. تفاوت‌های معنایی (ENUM، InnoDB،
قواعد دقیق DECIMAL و ...) شبیه‌سازی شده‌اند، نه اجرا. خروجی این اسکریپت
JSON است و هارنس Node آن را می‌خواند.
"""

import json
import os
import re
import sys

import duckdb
import sqlglot
from sqlglot import exp

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


# ---------------------------------------------------------------- utilities
def split_sql(s):
    """تقسیم به دستورها با احترام به کوتیشن، کامنت و escape."""
    out, cur, i, n, q = [], [], 0, len(s), None
    while i < n:
        c = s[i]
        if q:
            cur.append(c)
            if c == "\\" and i + 1 < n:
                cur.append(s[i + 1])
                i += 2
                continue
            if c == q:
                if i + 1 < n and s[i + 1] == q:
                    cur.append(s[i + 1])
                    i += 2
                    continue
                q = None
            i += 1
            continue
        if c in "'\"`":
            q = c
            cur.append(c)
            i += 1
            continue
        if s[i:i + 2] in ("--", "//") or c == "#":
            j = s.find("\n", i)
            i = n if j < 0 else j + 1
            continue
        if s[i:i + 2] == "/*":
            j = s.find("*/", i)
            i = n if j < 0 else j + 2
            continue
        if c == ";":
            out.append("".join(cur).strip())
            cur = []
            i += 1
            continue
        cur.append(c)
        i += 1
    if "".join(cur).strip():
        out.append("".join(cur).strip())
    return [x for x in out if x]


TYPE_MAP = {
    "ENUM": "VARCHAR", "SET": "VARCHAR", "LONGTEXT": "VARCHAR", "MEDIUMTEXT": "VARCHAR",
    "TINYTEXT": "VARCHAR", "TEXT": "VARCHAR", "JSON": "VARCHAR", "BLOB": "BLOB",
    "LONGBLOB": "BLOB", "DATETIME": "TIMESTAMP", "YEAR": "INTEGER",
    "DOUBLE": "DOUBLE", "FLOAT": "FLOAT", "MEDIUMINT": "INTEGER",
    "UNSIGNED BIGINT": "BIGINT", "BIT": "INTEGER",
}


def duck_type(kind):
    """نوع MySQL → نوع DuckDB (فقط برای ساخت کاتالوگ، نه بازتولید معنایی دقیق)."""
    name = kind.this if isinstance(kind, exp.DataType) else kind
    base = (kind.this.name if isinstance(kind.this, exp.DataType.Type) else str(kind.this)).upper()
    dtype = kind.this.name.upper() if hasattr(kind.this, "name") else str(kind.this).upper()
    if dtype in ("ENUM", "SET"):
        return "VARCHAR"
    if dtype in TYPE_MAP:
        return TYPE_MAP[dtype]
    if dtype in ("DECIMAL", "NUMERIC"):
        p = kind.expressions
        if len(p) == 2:
            return f"DECIMAL({p[0].name},{p[1].name})"
        return "DECIMAL(18,2)"
    if dtype.startswith("VARCHAR") or dtype.startswith("CHAR"):
        p = kind.expressions
        if p:
            return f"VARCHAR({p[0].name})"
        return "VARCHAR"
    if dtype in ("INT", "INTEGER", "BIGINT", "SMALLINT", "TINYINT", "TIMESTAMP", "DATE", "TIME", "BOOLEAN"):
        return dtype
    return "VARCHAR"


def build_table_ddl(node):
    """CREATE TABLE مای‌اس‌کیوال → CREATE TABLE قابل اجرا در DuckDB."""
    tbl = node.this.this.name
    cols, pk, uniques = [], [], []
    for col in node.find_all(exp.ColumnDef):
        cname = col.name
        cons = [c.kind for c in col.args.get("constraints", [])]
        parts = [f'"{cname}"', duck_type(col.args["kind"])]
        for c in cons:
            if isinstance(c, exp.NotNullColumnConstraint):
                if not c.args.get("allow_null"):
                    parts.append("NOT NULL")
            elif isinstance(c, exp.PrimaryKeyColumnConstraint):
                pk.append(f'"{cname}"')
                parts.append("NOT NULL")
            elif isinstance(c, exp.UniqueColumnConstraint):
                uniques.append([f'"{cname}"'])
            elif isinstance(c, exp.DefaultColumnConstraint):
                v = c.this
                if isinstance(v, exp.Literal) and v.is_string:
                    parts.append("DEFAULT '" + v.this.replace("'", "''") + "'")
                elif isinstance(v, exp.Null):
                    parts.append("DEFAULT NULL")
                elif isinstance(v, exp.Neg) and isinstance(v.this, exp.Literal):
                    parts.append(f"DEFAULT -{v.this.this}")
                elif isinstance(v, exp.Literal):
                    parts.append(f"DEFAULT {v.this}")
                elif isinstance(v, exp.Boolean):
                    parts.append("DEFAULT " + ("TRUE" if v.this else "FALSE"))
                elif isinstance(v, (exp.CurrentTimestamp, exp.CurrentDate, exp.CurrentTime)):
                    parts.append("DEFAULT " + v.sql(dialect="duckdb"))
                elif isinstance(v, exp.Func):
                    parts.append("DEFAULT " + v.sql(dialect="duckdb"))
                else:
                    parts.append("DEFAULT " + v.sql(dialect="duckdb"))
        cols.append(" ".join(parts))

    # قیدهای سطح جدول (PRIMARY KEY / UNIQUE) — برای بررسی inجذاب seedها لازم است
    for c in node.args.get("constraints") or []:
        if isinstance(c, exp.PrimaryKey):
            pk = [f'"{x.name}"' for x in c.expressions]
        elif isinstance(c, exp.UniqueColumnConstraint):
            pass

    defs = cols[:]
    if pk:
        defs.append("PRIMARY KEY (" + ", ".join(dict.fromkeys(pk)) + ")")
    for u in uniques:
        defs.append("UNIQUE (" + ", ".join(u) + ")")

    ifnot = "IF NOT EXISTS " if node.args.get("exists") else ""
    return f'CREATE TABLE {ifnot}"{tbl}" (\n  ' + ",\n  ".join(defs) + "\n)"


def clean_insert(sql):
    """INSERT مای‌اس‌کیوال → دستور قابل اجرا در DuckDB."""
    try:
        tree = sqlglot.parse_one(sql, read="mysql")
    except Exception:
        return None
    if not isinstance(tree, exp.Insert):
        return None
    ignore = bool(tree.args.get("ignore"))
    tree.set("ignore", None)
    tree.set("conflict", None)  # ON DUPLICATE KEY UPDATE
    try:
        body = tree.sql(dialect="duckdb")
    except Exception:
        return None
    if ignore:
        body = re.sub(r"^INSERT\s+", "INSERT OR IGNORE ", body, flags=re.I)
    return body


def php_sql_statements(src):
    """همهٔ دستورهای SQL که در یک فایل PHP به‌صورت رشته‌ای نوشته شده‌اند."""
    out = []

    # ۱) کل عبارت یک انتساب: $sql = "SELECT ..." . ($x ? ", a" : "") . " FROM t";
    #    همهٔ رشته‌های دوتایی داخل عبارت به‌ترتیب الحاق می‌شوند تا کوئری کامل شود.
    i, n = 0, len(src)
    while i < n:
        m = re.compile(r"\$(\w+)\s*=\s*").search(src, i)
        if not m:
            break
        var, j, depth, q, parts = m.group(1), m.end(), 0, None, []
        buf = []
        while j < n:
            c = src[j]
            if q:
                if c == "\\":
                    j += 2
                    continue
                if c == q:
                    q = None
                    if q is None and parts is not None and False:
                        pass
                j += 1
                continue
            if c == '"':
                k, chunk = j + 1, []
                while k < n:
                    if src[k] == "\\":
                        chunk.append(src[k + 1] if src[k + 1] in '"\\' else src[k] + src[k + 1])
                        k += 2
                        continue
                    if src[k] == '"':
                        break
                    chunk.append(src[k])
                    k += 1
                parts.append("".join(chunk))
                j = k + 1
                continue
            if c == "'":
                j += 1
                while j < n:
                    if src[j] == "\\":
                        j += 2
                        continue
                    if src[j] == "'":
                        break
                    j += 1
                j += 1
                continue
            if c in "([{":
                depth += 1
            elif c in ")]}":
                depth -= 1
            elif c == ";" and depth <= 0:
                break
            j += 1
        joined = "".join(parts)
        if re.match(r"\s*\(?\s*(SELECT|INSERT|UPDATE|DELETE)\b", joined, re.I):
            out.append(("concat", joined))
        i = max(j, m.end())

    # ۲) هر رشتهٔ دوتایی که خودش یک دستور است
    for m in re.finditer(r'"((?:[^"\\]|\\.)*)"', src):
        body = m.group(1)
        if re.match(r"\s*\(?\s*(SELECT|INSERT|UPDATE|DELETE)\b", body, re.I):
            out.append(("string", body))

    # ۳) heredoc / nowdoc
    for m in re.finditer(r"<<<['\"]?(\w+)['\"]?\r?\n([\s\S]*?)\r?\n\s*\1", src):
        for stmt in split_sql(m.group(2)):
            out.append(("heredoc", stmt))

    # ۴) exec/prepare روی رشته‌های چنددستوری
    out2 = []
    for kind, body in out:
        parts = split_sql(body) if ";" in body else [body]
        for p in parts:
            if re.match(r"\s*(SELECT|INSERT|UPDATE|DELETE)\b", p, re.I):
                out2.append((kind, p.strip()))
    return out2


# ------------------------------------------------------------------- checks
def main():
    schema_path = os.path.join(ROOT, "api", ".secrets", "schema.sql")
    schema_sql = open(schema_path, encoding="utf-8").read()
    stmts = split_sql(schema_sql)

    result = {
        "duckdb": duckdb.__version__,
        "sqlglot": sqlglot.__version__,
        "schemaStatements": len(stmts),
        "syntaxErrors": [],
        "ddlFailures": [],
        "seedFailures": [],
        "tables": 0,
        "seedRows": 0,
        "queriesChecked": 0,
        "queriesSkipped": 0,
        "skips": [],
        "problems": [],
    }

    con = duckdb.connect()

    # ---- ۱. گرامر MySQL روی کل اسکیما
    for st in stmts:
        try:
            sqlglot.parse(st, read="mysql")
        except Exception as e:
            result["syntaxErrors"].append({"sql": st[:120], "error": str(e).splitlines()[0][:160]})

    # ---- ۲. ساخت کاتالوگ واقعی
    for st in stmts:
        head = st.lstrip().upper()
        if head.startswith("CREATE TABLE"):
            try:
                tree = sqlglot.parse_one(st, read="mysql")
                ddl = build_table_ddl(tree)
            except Exception as e:
                result["ddlFailures"].append({"sql": st[:100], "error": "parse: " + str(e)[:140]})
                continue
            try:
                con.execute(ddl)
                result["tables"] += 1
            except Exception as e:
                result["ddlFailures"].append({"sql": st[:100].replace("\n", " "), "error": str(e)[:160]})
        elif head.startswith("INSERT"):
            body = clean_insert(st)
            if not body:
                continue
            try:
                con.execute(body)
                result["seedStatements"] = result.get("seedStatements", 0) + 1
            except Exception as e:
                msg = str(e)[:160]
                # اگر جدول ساخته نشده باشد، این خودش یک یافتهٔ واقعی است
                result["seedFailures"].append({"sql": st[:100].replace("\n", " "), "error": msg})

    # ---- ۳. اعتبارسنجی هر کوئری PHP با موتور واقعی
    concat_prefixes = {}

    api_dir = os.path.join(ROOT, "api")
    for fname in sorted(os.listdir(api_dir)):
        if not fname.endswith(".php"):
            continue
        src = open(os.path.join(api_dir, fname), encoding="utf-8", errors="replace").read()
        stmts_php = php_sql_statements(src)
        concat_prefixes = [t.strip() for k, t in stmts_php if k == "concat"]
        for kind, sql in stmts_php:
            if kind == "string" and any(c.startswith(sql.strip()[:60]) and len(c) > len(sql.strip())
                                        for c in concat_prefixes):
                continue  # همان کوئری، نسخهٔ کاملش جدا بررسی می‌شود
            # جای‌نگهدارهای PHP که در زمان اجرا با عدد جایگزین می‌شوند
            probe = sql
            probe = re.sub(r"\$\w+", "1", probe)
            probe = re.sub(r"\{\$\w+\}", "1", probe)
            # PDO: :name و ? — DuckDB در EXPLAIN آن‌ها را پارامتر آماده می‌بیند
            probe = re.sub(r"(?<![:@\w]):[A-Za-z_]\w*", "NULL", probe)
            probe = re.sub(r"\?", "NULL", probe)
            # MySQL: ON DUPLICATE KEY UPDATE ... — برای اعتبارسنجی کاتالوگ لازم نیست
            probe = re.sub(r"ON\s+DUPLICATE\s+KEY\s+UPDATE[\s\S]*$", "", probe, flags=re.I)
            try:
                tree = sqlglot.parse_one(probe, read="mysql")
            except Exception as e:
                result["queriesSkipped"] += 1
                result["skips"].append({"file": fname, "kind": kind, "sql": sql[:110].replace("\n", " "),
                                        "error": "parse: " + str(e).splitlines()[0][:100]})
                continue
            if tree is None:
                result["queriesSkipped"] += 1
                continue
            try:
                dql = tree.sql(dialect="duckdb")
            except Exception as e:
                result["queriesSkipped"] += 1
                result["skips"].append({"file": fname, "kind": kind, "sql": sql[:110].replace("\n", " "),
                                        "error": "transpile: " + str(e)[:100]})
                continue
            result["queriesChecked"] += 1
            try:
                con.execute("EXPLAIN " + dql)
            except Exception as e:
                msg = str(e)
                if "does not exist" in msg or "not found in FROM clause" in msg or "Referenced column" in msg:
                    result["problems"].append({"file": fname, "kind": kind,
                                               "sql": sql[:140].replace("\n", " "), "error": msg[:200]})
                else:
                    result["queriesChecked"] -= 1
                    result["queriesSkipped"] += 1
                    result["skips"].append({"file": fname, "kind": kind, "sql": sql[:110].replace("\n", " "),
                                            "error": msg.splitlines()[0][:120]})

    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
