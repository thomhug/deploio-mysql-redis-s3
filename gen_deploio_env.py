#!/usr/bin/python3

import argparse, json, shlex, subprocess, sys, urllib.parse

def run(cmd):
    res = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if res.returncode != 0:
        print(res.stderr.strip() or res.stdout.strip(), file=sys.stderr)
        sys.exit(res.returncode)
    return res.stdout.strip()

def get_json(kind, name, project):
    cmd = f"nctl get {shlex.quote(kind)} {shlex.quote(name)} -p {shlex.quote(project)} -o json"
    return json.loads(run(cmd))

def get_secret(kind, name, project, flag):
    cmd = f"nctl get {shlex.quote(kind)} {shlex.quote(name)} -p {shlex.quote(project)} {flag}"
    return run(cmd)

def yaml_entry(name, value):
    if "\n" in value:
        lines = "\n".join("    " + ln for ln in value.splitlines())
        return f"- name: {name}\n  value: |-\n{lines}\n"
    # einfache YAML-Escapes für single-quoted
    val = value.replace("'", "''")
    return f"- name: {name}\n  value: '{val}'\n"

def main():
    ap = argparse.ArgumentParser(description="Generate deplo.io env YAML from nctl resources")
    ap.add_argument("--project", "-p", required=True, help="nctl project (z.B. demo-12345)")
    ap.add_argument("--app", "-a", required=False, help="OPTIONAL: deplo.io Appname (für Beispiel-Update-Command)")
    ap.add_argument("--mysql-kind", default="mysql", choices=["mysql","mysqldatabase"], help="mysql vs mysqldatabase (Economy)")
    ap.add_argument("--mysql-name", required=True, help="Name der MySQL Ressource")
    ap.add_argument("--kvs-name", required=True, help="Name der KeyValueStore Ressource (Redis)")
    # S3 (manuell/aus Cockpit)
    ap.add_argument("--s3-endpoint", required=True)
    ap.add_argument("--s3-region", required=True)
    ap.add_argument("--s3-bucket", required=True)
    ap.add_argument("--s3-access-key", required=True)
    ap.add_argument("--s3-secret-key", required=True)
    args = ap.parse_args()

    # MySQL
    mj = get_json(args.mysql_kind, args.mysql_name, args.project)
    mfqdn = mj["status"]["atProvider"]["fqdn"]
    mport = mj["status"]["atProvider"].get("port", 3306)
    muser = get_secret(args.mysql_kind, args.mysql_name, args.project, "--print-user")
    mpass = get_secret(args.mysql_kind, args.mysql_name, args.project, "--print-password")
    mca   = get_secret(args.mysql_kind, args.mysql_name, args.project, "--print-ca-cert")
    # Bei Nine = DB-Name == User (siehe Doku)
    mdb = muser
    db_url = f"mysql://{urllib.parse.quote(muser)}:{urllib.parse.quote(mpass)}@{mfqdn}:{mport}/{mdb}"

    # Redis
    rj = get_json("keyvaluestore", args.kvs_name, args.project)
    rhost = rj["status"]["atProvider"]["fqdn"]
    rport = rj["status"]["atProvider"].get("port", 6379)
    ruser = ""  # wird von Redis meist ignoriert
    rpass = get_secret("keyvaluestore", args.kvs_name, args.project, "--print-password")
    rca   = get_secret("keyvaluestore", args.kvs_name, args.project, "--print-ca-cert")
    redis_url = f"rediss://:{urllib.parse.quote(rpass)}@{rhost}:{rport}/0"

    # YAML bauen
    entries = []
    entries.append(yaml_entry("ALLOW_DB_CREATE", "false"))
    entries.append(yaml_entry("DATABASE_URL", db_url))
    entries.append(yaml_entry("DB_CHARSET", "utf8mb4"))
    entries.append(yaml_entry("DB_SSL_CA_PEM", mca))
    entries.append(yaml_entry("REDIS_CA_PEM", rca))
    entries.append(yaml_entry("REDIS_URL", redis_url))
    entries.append(yaml_entry("S3_ENDPOINT", args.s3_endpoint))
    entries.append(yaml_entry("S3_REGION", args.s3_region))
    entries.append(yaml_entry("S3_BUCKET", args.s3_bucket))
    entries.append(yaml_entry("S3_ACCESS_KEY", args.s3_access_key))
    entries.append(yaml_entry("S3_SECRET_KEY", args.s3_secret_key))
    entries.append(yaml_entry("S3_USE_PATH_STYLE", "true"))

    # Ausgabe: YAML kompatibel zu deiner env-sample.yaml
    print("---")
    sys.stdout.write("".join(entries))

    # Optional: Beispiel-Update-Command (Runtime-Env) + Build-Env Hinweise
    if args.app:
        print("\n# Beispiel: direkt setzen (Runtime-Env) – PEMs vorher in VARS packen:")
        print(f"# DB_CA=$(nctl get {args.mysql_kind} {args.mysql_name} -p {args.project} --print-ca-cert)")
        print(f"# REDIS_CA=$(nctl get keyvaluestore {args.kvs_name} -p {args.project} --print-ca-cert)")
        print(f"# nctl update app {args.app} -p {args.project} \\")
        print(f"#   --env DATABASE_URL='{db_url}' \\")
        print(f"#   --env DB_CHARSET='utf8mb4' \\")
        print(f"#   --env DB_SSL_CA_PEM=\"$DB_CA\" \\")
        print(f"#   --env REDIS_URL='{redis_url}' \\")
        print(f"#   --env REDIS_CA_PEM=\"$REDIS_CA\" \\")
        print(f"#   --env S3_ENDPOINT='{args.s3_endpoint}' --env S3_REGION='{args.s3_region}' \\")
        print(f"#   --env S3_BUCKET='{args.s3_bucket}' --env S3_ACCESS_KEY='{args.s3_access_key}' --env S3_SECRET_KEY='{args.s3_secret_key}' \\")
        print(f"#   --env S3_USE_PATH_STYLE='true'")

        print("\n# Build-Env (Beispiel):")
        print(f"# nctl update app {args.app} -p {args.project} --build-env BP_PHP_WEB_DIR=public --build-env BP_COMPOSER_INSTALL_OPTIONS='--ignore-platform-reqs'")
        print("# (Build-Env gehört NICHT in .deploio.yaml – laut Doku)")

if __name__ == '__main__':
    main()

