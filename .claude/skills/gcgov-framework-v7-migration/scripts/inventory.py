#!/usr/bin/env python3
"""
Inventory a gcgov/framework v6 application before a v7 migration.

Reads the application checkout and prints the facts the migration needs as Markdown:
names and URLs, Framework Services, CLI Routes, CORS origins, PHP settings, code that
uses removed or changed framework APIs, and every place a v6 credential sits.

It never prints a secret value. A value whose key or shape marks it as a credential is
reported as "<set>" or "<empty>" only.

Usage:
    python3 inventory.py [path-to-app-root]          # Markdown report
    python3 inventory.py [path-to-app-root] --json   # the same facts as JSON

Standard library only. Read-only: it changes no file.
"""

import json
import os
import re
import subprocess
import sys
from urllib.parse import urlparse

SECRET_KEYS = {
    "uri", "clientsecret", "password", "apikey", "smtppassword", "dsn",
    "auditdatabaseuri", "secret", "token",
}

SERVICE_NAMESPACES = {
    "gcgov\\framework\\services\\documentation": "documentation",
    "gcgov\\framework\\services\\usercrud": "userCrud",
    "gcgov\\framework\\services\\authoauth": "auth (provider: oauth)",
    "gcgov\\framework\\services\\authmsfront": "auth (provider: msFront)",
    "gcgov\\framework\\services\\cronMonitor": "cronMonitor (not a Framework Service in v7)",
}

SERVICE_PACKAGES = [
    "gcgov/framework-service-auth-oauth-server",
    "gcgov/framework-service-auth-ms-front",
    "gcgov/framework-service-user-crud",
    "gcgov/framework-service-documentation",
    "gcgov/framework-service-gcgov-cron-monitor",
]

# Code patterns that need attention under v7, with the reason shown in the report.
CODE_PATTERNS = [
    (r"registerFrameworkServiceNamespaces", "Removed from the app interface. Services move to config.json `services`."),
    (r"oauthConfig|msFrontConfig", "Service singletons are gone. Their settings move to config.json `services.auth`."),
    (r"getEnvironmentConfig\s*\(", "Deprecated pass-through. Use the flattened `config::get*()` accessors."),
    (r"getAppConfig\s*\(", "Deprecated pass-through. Use `config::getApp()`, `getEmail()`, `getSettings()`."),
    (r"getConfigDir\s*\(", "Deprecated: app/config no longer exists."),
    (r"\benvironmentConfig\b", "The v6 environmentConfig object is gone (tests seed `unifiedConfig`)."),
    (r"getBasePath\s*\(\s*\)", "Route prefixes must use `config::getRoutePrefix()`. `getBasePath()` returns '/' at a domain root."),
    (r"getRunFrameworkServiceRouteAuthentication", "Now the `interfaces\\router\\skipsServiceAuthentication` interface, and it takes a `$routeHandler`."),
    (r"services\\authoauth|services\\usercrud|services\\authmsfront", "v6 service namespaces. v7 uses `services\\auth`, `services\\userCrud`."),
    (r"cronMonitorUrl", "Moved from appDictionary to `cronMonitor.url` (`config::getCronMonitor()`)."),
    (r"useSession|\$_SESSION|session_start", "`settings.useSession` was removed. A container loses filesystem sessions on every deploy."),
    (r"serverName|cookieUrl|phpPath", "Removed config keys. Nothing in v7 reads them."),
    (r"new\s+\\?gcgov\\framework\\framework\s*\(\s*\$", "The framework constructor takes no argument in v7."),
    (r"include(_once)?\s*\(\s*'\.\./vendor", "Relative include. Use `__DIR__ . '/../vendor/autoload.php'` (PHP-FPM's working directory differs)."),
    (r"services\\chrome|HeadlessChromium|controllerPdfResponse", "Headless Chrome: the image needs the chrome build stage and its shared libraries."),
    (r"PhpSpreadsheet|ZipArchive", "Needs ext-zip in the image."),
    (r"\bimagick\b|Imagick", "Needs the imagick extension in the image."),
    (r"\bimagecreate|\bgetimagesize|\bimagepng|\bimagejpeg", "Needs the gd extension in the image."),
    (r"\bpdodb\b|sqlDatabases|new\s+\\?PDO\b", "SQL credentials must become %env(secret:...)% references by hand."),
    (r"payjunction", "PayJunction credentials become %env(secret:...)% references."),
    (r"garrettcounty\.org|apps\.garrettcountymd\.gov|internal-apps\.garrettcountymd\.gov|garrettcounty\.local", "Hard-coded host. The API moves to api.{app_key}.apps.garrettcountymd.gov."),
    (r"/logs/|getRootDir\(\)\s*\.\s*'/logs", "Writes to logs/. A container keeps no files across deploys; logs go to stderr."),
]

TEXT_EXTENSIONS = (".php", ".js", ".ts", ".json", ".md", ".yml", ".yaml", ".config", ".ini", ".bat", ".ps1", ".sh")


def is_secret_key(key):
    return key.lower().replace("_", "") in SECRET_KEYS


def looks_like_credential(value):
    return isinstance(value, str) and re.search(r"://[^/\s:@]+:[^/\s@]+@", value) is not None


def mask(key, value):
    if value is None or value == "":
        return "<empty>"
    if is_secret_key(key) or looks_like_credential(value):
        return "<set>"
    return value


def read_json(path):
    try:
        with open(path, encoding="utf-8-sig") as handle:
            return json.load(handle)
    except (OSError, ValueError):
        return None


def read_text(path):
    try:
        with open(path, encoding="utf-8", errors="replace") as handle:
            return handle.read()
    except OSError:
        return ""


def strip_php_comments(source):
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    return re.sub(r"(?m)(^|[^:\"'])//.*$|(^|\s)#(?!\[).*$", r"\1", source)


def git(root, *args):
    try:
        return subprocess.run(["git", "-C", root, *args], capture_output=True, text=True, timeout=30).stdout
    except (OSError, subprocess.SubprocessError):
        return ""


def app_key_from(base_path, root_url):
    """'/lab/api/' -> 'lab'. Falls back to the first host label of rootUrl."""
    segments = [s for s in (base_path or "").strip("/").split("/") if s]
    if segments and segments[-1] == "api":
        segments = segments[:-1]
    if segments:
        return segments[-1]
    host = urlparse(root_url or "").hostname or ""
    return host.split(".")[0] if host else None


def environment_files(root):
    config_dir = os.path.join(root, "app", "config")
    found = []
    if os.path.isdir(config_dir):
        for name in sorted(os.listdir(config_dir)):
            if re.fullmatch(r"environment(-[\w.-]+)?\.json", name):
                found.append(os.path.join(config_dir, name))
    return found


def summarize_environment(path):
    data = read_json(path) or {}
    summary = {
        "file": os.path.relpath(path),
        "type": data.get("type"),
        "rootUrl": data.get("rootUrl"),
        "basePath": data.get("basePath"),
        "removedKeysPresent": [k for k in ("serverName", "cookieUrl", "phpPath", "baseUrl") if k in data],
        "mongoDatabases": [],
        "sqlDatabases": len(data.get("sqlDatabases") or []),
        "jwtAuth": {k: mask(k, v) for k, v in (data.get("jwtAuth") or {}).items()},
        "microsoft": {k: mask(k, v) for k, v in (data.get("microsoft") or {}).items()},
        "payjunction": {k: mask(k, v) for k, v in (data.get("payjunction") or {}).items()},
        "appDictionaryKeys": sorted((data.get("appDictionary") or {}).keys()),
        "credentialsPresent": [],
    }
    for db in data.get("mongoDatabases") or []:
        if isinstance(db, dict):
            summary["mongoDatabases"].append({
                "database": db.get("database"),
                "uri": mask("uri", db.get("uri")),
                "logging": db.get("logging"),
                "audit": db.get("audit"),
                "encryption": bool((db.get("encryption") or {}).get("useEncryption")),
            })

    def walk(node, trail):
        if isinstance(node, dict):
            for key, value in node.items():
                walk(value, trail + [key])
        elif isinstance(node, list):
            for index, value in enumerate(node):
                walk(value, trail + [str(index)])
        elif isinstance(node, str) and node and (is_secret_key(trail[-1]) or looks_like_credential(node)):
            summary["credentialsPresent"].append(".".join(trail))

    walk(data, [])
    return summary


# Config paths gf migrate turns into Config References on its own (migrateCommand::EXTRACTED,
# plus every mongoDatabases uri and database). Anything else that differs between Environments
# stays a literal unless someone converts it.
GF_MIGRATE_EXTRACTS = {
    "type", "rootUrl", "basePath", "jwtAuth.redirectAfterLoginUrl", "jwtAuth.redirectAfterLogoutUrl",
    "microsoft.clientId", "microsoft.clientSecret", "microsoft.tenant", "microsoft.driveId",
    "payjunction.username", "payjunction.password", "payjunction.apiKey",
    "email.SMTPUsername", "email.SMTPPassword",
}
REMOVED_BY_GF_MIGRATE = {"serverName", "cookieUrl", "phpPath", "baseUrl"}


def flatten(node, trail=()):
    if isinstance(node, dict):
        for key, value in node.items():
            yield from flatten(value, trail + (str(key),))
    elif isinstance(node, list):
        for index, value in enumerate(node):
            yield from flatten(value, trail + (str(index),))
    else:
        yield ".".join(trail), node


def credential_values(paths):
    """Every credential value in the v6 config files. Returned for searching, never printed."""
    values = set()
    for path in paths:
        for key_path, value in flatten(read_json(path) or {}):
            if not isinstance(value, str) or len(value) < 6:
                continue
            if is_secret_key(key_path.split(".")[-1]):
                values.add(value)
                match = re.search(r"://[^/\s:@]+:([^/\s@]+)@", value)
                if match and len(match.group(1)) >= 6:
                    values.add(match.group(1))
            elif looks_like_credential(value):
                values.add(re.search(r"://[^/\s:@]+:([^/\s@]+)@", value).group(1))
    return values


def credential_copies(root, values, config_paths):
    """Tracked files outside app/config that hold one of the v6 credential values."""
    if not values:
        return []
    skip = {os.path.relpath(p, root) for p in config_paths}
    listed = git(root, "ls-files").split("\n")
    hits = []
    for rel in listed:
        if not rel or rel in skip or rel.startswith("vendor/") or rel == "composer.lock":
            continue
        text = read_text(os.path.join(root, rel))
        for number, line in enumerate(text.splitlines(), 1):
            if any(v in line for v in values):
                hits.append(f"{rel}:{number}")
    return hits


def differing_literals(paths):
    """Paths whose value differs between the environment variants but that gf migrate keeps literal."""
    variants = [dict(flatten(read_json(p) or {})) for p in paths]
    if len(variants) < 2:
        return []
    found = []
    keys = set().union(*variants)
    for key_path in sorted(keys):
        if key_path in GF_MIGRATE_EXTRACTS or key_path.split(".")[0] in REMOVED_BY_GF_MIGRATE:
            continue
        if re.fullmatch(r"mongoDatabases\.\d+\.(uri|database)", key_path):
            continue
        seen = {repr(v.get(key_path)) for v in variants}
        if len(seen) > 1:
            secret = is_secret_key(key_path.split(".")[-1]) or any(looks_like_credential(v.get(key_path)) for v in variants)
            found.append(key_path + (" (credential)" if secret else ""))
    return found


def detect_services(root):
    source = strip_php_comments(read_text(os.path.join(root, "app", "app.php")))
    services = [label for ns, label in SERVICE_NAMESPACES.items() if ns in source]
    singletons = []
    for match in re.finditer(r"(set\w+)\s*\(([^;]*)\)\s*;", source):
        if match.group(1) in ("setBlockNewUsers", "setAuthorizeUrlParameters", "setDefaultNewUserRoles"):
            singletons.append(match.group(0).strip())
    return services, singletons


def cli_routes(root):
    source = strip_php_comments(read_text(os.path.join(root, "app", "router.php")))
    routes = []
    for match in re.finditer(r"new\s+route\s*\(\s*['\"]CLI['\"]\s*,\s*([^,]+),\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]", source):
        routes.append({"path": match.group(1).strip().strip("'\""), "class": match.group(2), "method": match.group(3)})
    return routes


def iis_facts(root):
    facts = []
    www = os.path.join(root, "www")
    if not os.path.isdir(www):
        return facts
    for name in sorted(os.listdir(www)):
        if not re.fullmatch(r"web(-[\w.-]+)?\.config", name):
            continue
        text = read_text(os.path.join(www, name))
        facts.append({
            "file": "www/" + name,
            "corsOrigins": re.findall(r"<add\s+origin=\"([^\"]+)\"", text),
            "maxAllowedContentLength": (re.findall(r"maxAllowedContentLength=\"(\d+)\"", text) or [None])[0],
            "passThrough": re.findall(r"<match\s+url=\"(\^/\?\([^\"]*\))", text),
            "redirectBase": re.findall(r"url=\"https://\{HTTP_HOST\}(/[^\"{]*)", text),
        })
    return facts


def theme_assets(root):
    theme = os.path.join(root, "www", "theme")
    assets = []
    for current, _dirs, files in os.walk(theme):
        for name in files:
            if not name.endswith(".php"):
                assets.append(os.path.relpath(os.path.join(current, name), root))
    return assets


def php_ini_facts(root):
    facts = []
    srv = os.path.join(root, "srv")
    if not os.path.isdir(srv):
        return facts
    for name in sorted(os.listdir(srv)):
        ini = os.path.join(srv, name, "php.ini")
        if not os.path.isfile(ini):
            continue
        text = read_text(ini)
        values = {}
        for key in ("memory_limit", "upload_max_filesize", "post_max_size", "max_execution_time", "date.timezone"):
            match = re.search(r"(?m)^\s*" + re.escape(key) + r"\s*=\s*(.+?)\s*$", text)
            if match:
                values[key] = match.group(1)
        extensions = sorted(set(re.findall(r"(?m)^\s*(?:zend_)?extension\s*=\s*\"?(?:php_)?([\w]+)", text)))
        facts.append({"file": os.path.relpath(ini, root), "settings": values, "extensions": extensions})
    return facts


def scan_code(root):
    hits = {}
    for base in ("app", "www", "tests", "scripts", "db"):
        start = os.path.join(root, base)
        for current, dirs, files in os.walk(start):
            dirs[:] = [d for d in dirs if d not in ("vendor", "node_modules", ".git")]
            for name in files:
                if not name.endswith(TEXT_EXTENSIONS):
                    continue
                path = os.path.join(current, name)
                rel = os.path.relpath(path, root)
                for number, line in enumerate(read_text(path).splitlines(), 1):
                    for pattern, reason in CODE_PATTERNS:
                        if re.search(pattern, line):
                            hits.setdefault(reason, []).append(f"{rel}:{number}")
    return hits


def composer_facts(root):
    facts = []
    for name in sorted(os.listdir(root)):
        if not re.fullmatch(r"composer(-[\w.-]+)?\.json", name):
            continue
        data = read_json(os.path.join(root, name)) or {}
        require = data.get("require") or {}
        facts.append({
            "file": name,
            "name": data.get("name"),
            "framework": require.get("gcgov/framework"),
            "servicePackages": [p for p in SERVICE_PACKAGES if p in require],
            "extensions": sorted(k for k in require if k.startswith("ext-")),
            "php": require.get("php"),
            "pathRepositories": [r.get("url") for r in data.get("repositories") or [] if isinstance(r, dict) and r.get("type") == "path"],
        })
    return facts


def git_facts(root):
    tracked = git(root, "ls-files", "app/config", "composer.json", "composer.lock", "www/web.config").split()
    history = git(root, "log", "--all", "--format=%h", "--", "app/config").split()
    return {
        "clean": git(root, "status", "--porcelain").strip() == "",
        "branch": git(root, "rev-parse", "--abbrev-ref", "HEAD").strip(),
        "remote": git(root, "remote", "get-url", "origin").strip(),
        "trackedConfigFiles": tracked,
        "commitsTouchingAppConfig": len(history),
    }


def existing_v7_work(root):
    """Branches or tags that suggest someone already started this migration."""
    refs = git(root, "for-each-ref", "--format=%(refname:short)", "refs/heads", "refs/remotes", "refs/tags").split()
    return [r for r in refs if re.search(r"v7|framework-?7|migrat", r, re.I)]


def collect(root):
    root = os.path.abspath(root)
    environments = [summarize_environment(p) for p in environment_files(root)]
    prod = next((e for e in environments if e["type"] == "prod"), None) or next(
        (e for e in environments if "prod" in e["file"]), None)
    reference = prod or (environments[0] if environments else {})
    app_json = read_json(os.path.join(root, "app", "config", "app.json")) or {}
    composer = composer_facts(root)
    slug = os.path.basename(root)
    for entry in composer:
        if entry.get("name") and "/" in entry["name"]:
            slug = entry["name"].split("/", 1)[1]
            break
    key = app_key_from(reference.get("basePath"), reference.get("rootUrl"))
    services, singletons = detect_services(root)
    env_paths = environment_files(root)
    return {
        "root": root,
        "git": git_facts(root),
        "names": {
            "slug": slug,
            "appKey": key,
            "v6ProdUrl": ((reference.get("rootUrl") or "").rstrip("/") + (reference.get("basePath") or "")) or None,
            "v7ApiUrl": f"https://api.{key}.apps.garrettcountymd.gov/api/" if key else None,
            "v7FrontendUrl": f"https://{key}.apps.garrettcountymd.gov" if key else None,
        },
        "appJson": {
            "title": (app_json.get("app") or {}).get("title"),
            "guid": (app_json.get("app") or {}).get("guid"),
            "settings": app_json.get("settings"),
            "email": {k: mask(k, v) for k, v in (app_json.get("email") or {}).items()},
        },
        "environments": environments,
        "composer": composer,
        "services": services,
        "serviceSingletonCalls": singletons,
        "cliRoutes": cli_routes(root),
        "schedulerEntryPoints": [p for p in ("app/cli/prod.bat", "app/cli/local.bat", "app/cli/index.php") if os.path.exists(os.path.join(root, p))],
        "iis": iis_facts(root),
        "themeAssets": theme_assets(root),
        "phpIni": php_ini_facts(root),
        "codeFindings": scan_code(root),
        "credentialCopiesOutsideAppConfig": credential_copies(root, credential_values(env_paths), env_paths),
        "differsByEnvironmentButStaysLiteral": differing_literals([p for p in env_paths if os.path.basename(p) != "environment.json"]),
        "existingV7Work": existing_v7_work(root),
        "v7ArtifactsAlreadyPresent": [p for p in ("config.json", ".env", "Dockerfile", "docker-compose.yml", ".github/workflows/release.yml") if os.path.exists(os.path.join(root, p))],
    }


def render(facts):
    out = []
    add = out.append
    names = facts["names"]
    add(f"# v6 inventory: `{names['slug']}`\n")
    add(f"Root: `{facts['root']}`  \nBranch: `{facts['git']['branch']}`  \nWorking tree clean: **{facts['git']['clean']}**\n")
    if facts["existingV7Work"]:
        add(f"> Refs that look like earlier v7 work: {', '.join('`'+r+'`' for r in facts['existingV7Work'])}. Compare before you start a parallel migration.\n")
    if facts["v7ArtifactsAlreadyPresent"]:
        add(f"> v7 files already exist: {', '.join('`'+p+'`' for p in facts['v7ArtifactsAlreadyPresent'])}. The migration may be partly done.\n")

    add("## Names and URLs\n")
    add("| Fact | Value |\n|---|---|")
    add(f"| App slug (repository name) | `{names['slug']}` |")
    add(f"| App key (from the v6 base path) | `{names['appKey']}` |")
    add(f"| v6 production API URL | `{names['v6ProdUrl']}` |")
    add(f"| v7 API URL | `{names['v7ApiUrl']}` |")
    add(f"| v7 frontend URL (if the app has one) | `{names['v7FrontendUrl']}` |")
    add(f"| app.guid (OAuth client_id) | `{facts['appJson']['guid']}` |")
    add(f"| app.title | `{facts['appJson']['title']}` |")
    add("")

    add("## Environment files\n")
    for env in facts["environments"]:
        add(f"### `{env['file']}` (type `{env['type']}`)\n")
        add(f"- rootUrl: `{env['rootUrl']}`, basePath: `{env['basePath']}`")
        for db in env["mongoDatabases"]:
            add(f"- mongo: database `{db['database']}`, uri {db['uri']}, logging `{db['logging']}`, audit `{db['audit']}`, encryption `{db['encryption']}`")
        if env["sqlDatabases"]:
            add(f"- sqlDatabases: **{env['sqlDatabases']}** (convert by hand)")
        add(f"- jwtAuth: `{json.dumps(env['jwtAuth'])}`")
        if env["microsoft"]:
            add(f"- microsoft: `{json.dumps(env['microsoft'])}`")
        if env["payjunction"]:
            add(f"- payjunction: `{json.dumps(env['payjunction'])}`")
        if env["appDictionaryKeys"]:
            add(f"- appDictionary keys: {', '.join('`'+k+'`' for k in env['appDictionaryKeys'])}")
        if env["removedKeysPresent"]:
            add(f"- keys v7 removed: {', '.join(env['removedKeysPresent'])}")
        if env["credentialsPresent"]:
            add(f"- **credentials in this file:** {', '.join('`'+c+'`' for c in env['credentialsPresent'])}")
        add("")
    tracked = facts["git"]["trackedConfigFiles"]
    if tracked:
        add(f"Tracked in git: {', '.join('`'+t+'`' for t in tracked)}. "
            f"{facts['git']['commitsTouchingAppConfig']} commit(s) touch `app/config`. "
            "Every credential ever committed there is in the history: rotate it.\n")

    if facts["credentialCopiesOutsideAppConfig"]:
        add("**v6 credentials also appear outside app/config** (values not shown). Remove each, and rotate the credential: "
            + ", ".join("`" + h + "`" for h in facts["credentialCopiesOutsideAppConfig"][:20]) + "\n")
    if facts["differsByEnvironmentButStaysLiteral"]:
        add("**Values that differ between the environment files but that `gf migrate` leaves as literals.** "
            "It copies the local value into config.json. Make each one a Config Reference, or confirm it is the same everywhere: "
            + ", ".join("`" + k + "`" for k in facts["differsByEnvironmentButStaysLiteral"]) + "\n")

    add("## Composer\n")
    for entry in facts["composer"]:
        add(f"- `{entry['file']}`: framework `{entry['framework']}`, php `{entry['php']}`, "
            f"service packages {entry['servicePackages'] or 'none'}, extensions {entry['extensions'] or 'none'}"
            + (f", path repositories {entry['pathRepositories']}" if entry["pathRepositories"] else ""))
    add("")

    add("## Framework Services\n")
    add("- Registered in app/app.php: " + (", ".join(facts["services"]) or "none"))
    for call in facts["serviceSingletonCalls"]:
        add(f"- Singleton call to move into `services.auth`: `{call}`")
    add(f"- app.json settings: `{json.dumps(facts['appJson']['settings'])}`\n")

    add("## CLI Routes\n")
    if facts["cliRoutes"]:
        add("| Path | Class | Method |\n|---|---|---|")
        for route in facts["cliRoutes"]:
            add(f"| `{route['path']}` | `{route['class']}` | `{route['method']}` |")
    else:
        add("None found.")
    add(f"\nScheduler entry points present: {', '.join(facts['schedulerEntryPoints']) or 'none'}. "
        "Ask the operator which CLI Routes Windows Task Scheduler runs today.\n")

    add("## IIS behaviour to carry into nginx\n")
    for entry in facts["iis"]:
        add(f"- `{entry['file']}`: CORS origins {entry['corsOrigins'] or 'none'}; "
            f"maxAllowedContentLength `{entry['maxAllowedContentLength']}`; pass-through {entry['passThrough'] or 'none'}; "
            f"HTTPS redirect base {entry['redirectBase'] or 'none'}")
    add(f"- Static files under www/theme: {facts['themeAssets'] or 'none (drop the static location)'}\n")

    add("## PHP settings from srv/*/php.ini\n")
    for entry in facts["phpIni"]:
        add(f"- `{entry['file']}`: {json.dumps(entry['settings'])}; extensions {entry['extensions']}")
    add("")

    add("## Code that needs attention\n")
    if not facts["codeFindings"]:
        add("Nothing matched.")
    for reason, places in facts["codeFindings"].items():
        shown = ", ".join(f"`{p}`" for p in places[:12])
        more = f" and {len(places) - 12} more" if len(places) > 12 else ""
        add(f"- {reason}  \n  {shown}{more}")
    add("")
    return "\n".join(out)


def main(argv):
    args = [a for a in argv[1:] if not a.startswith("--")]
    root = args[0] if args else "."
    if not os.path.isfile(os.path.join(root, "app", "app.php")):
        print(f"{os.path.abspath(root)} has no app/app.php. Point this at a gcgov/framework application root.", file=sys.stderr)
        return 2
    facts = collect(root)
    if "--json" in argv:
        print(json.dumps(facts, indent=2))
    else:
        print(render(facts))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
