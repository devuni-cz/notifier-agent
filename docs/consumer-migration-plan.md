# Migrace konzumentů: devuni/notifier-package → devuni/notifier-agent

> Vytvořeno 2026-07-01 multi-agentním auditem (13 konzumentů na notifier-package + 3 referenční čerstvě migrované). Read-only, ověřeno proti composer.lock / .env klíčům / scheduling.

> **⛔ ROZSAH (uživatelské rozhodnutí 2026-07-01): NEŘEŠIT** `pujcovna-cz`, `pujcovna-newmanschool-cz`, `pujcovna-skiricky-cz`, `pujcovnakuncice-newmanschool-cz`, `autoauto-cz`, `desk`, `grid`. **Reálný pracovní rozsah = 6 projektů:** `dochazka-azstavby-cz`, `dochazka-luboschlanda-cz`, `dopravnihriste.eu`, `xcp-commander` (Vlna 1) · `kamzajitolomouc.cz` (Vlna 2) · `vodo-topo-brauner-cz` (Vlna 3). Vyloučené projekty zůstávají v matici níže jen pro referenci.

## Migrační plán: `devuni/notifier-package` (2.x) → `devuni/notifier-agent` (^1.6)

Rozsah: 13 interních Laravel projektů. Cíl: nahradit opuštěný `devuni/notifier-package` agentem `devuni/notifier-agent`. Plán vychází výhradně z auditu 3 čerstvě zmigrovaných referencí (kalianko-cz, priznanisnadno-cz, sadrokartony-izolace.cz) a z per-projekt auditu zbývajících 13.

**Gate agenta `^1.6`:** `php: ^8.4` a `illuminate: ^12.55.0 || ^13.14`. Agent deklaruje `conflict: {devuni/notifier-package: "*"}` — obě knihovny nemohou koexistovat.

---

## 1. Kanonický recept (destilát ze 3 migrovaných)

Obecný krok-za-krokem postup, který u drop-in projektů stačí 1:1 zopakovat.

1. **composer.json — nahradit, ne přidat.** Řádek `"devuni/notifier-package": "<cokoli>"` NAHRADIT za `"devuni/notifier-agent": "^1.6"`. Kvůli `conflict: *` nelze mít oba naráz — musí to být záměna in-place.
2. **`composer update devuni/notifier-agent`.** Ve všech 3 případech vytáhlo v1.6.2 a tranzitivně bumplo `laravel/framework`, aby splnil `illuminate ^12.55.0 || ^13.14` (pozorováno: 13.13.0→13.16.1, 12.48.1→12.62.0, 12.56.0→12.62.0). `composer.json` pro `laravel/framework` se NEMĚNÍ — pohne se jen `composer.lock` (očekávej diff o stovkách řádků pro koncepčně jednořádkovou změnu).
3. **Žádné přejmenování .env klíčů.** Agent čte přesně stejné `NOTIFIER_*` klíče jako package (`NOTIFIER_BACKUP_CODE`, `NOTIFIER_URL`, `NOTIFIER_BACKUP_PASSWORD`, `NOTIFIER_QUEUE_CONNECTION`, …). Pro existující instalace je to drop-in swap.
4. **(Doporučeno) Znovu vypublikovat `config/notifier.php`** z agentova stubu kvůli novým klíčům: `trigger_secret`, `database_connection`, `postgres_dump_binary`, `postgres_schema`, `features.announcements`, `features.heartbeat` a blok `announcements.*`. Starý config je 100% zpětně kompatibilní (2 ze 3 projektů ho nechaly beze změny a funguje) — ale bez republish se nedostanete k heartbeatu, announcementům ani k Postgres/multi-connection podpoře. Republish udělalo jen kalianko-cz.
5. **(Volitelné) `.env.example`:** doplnit dokumentační řádek pro nový volitelný `NOTIFIER_TRIGGER_SECRET` (fallback na `NOTIFIER_BACKUP_CODE`). Jen kalianko-cz.
6. **(Volitelné) Heartbeat:** pokud ho chcete, přidat jednorázově do `routes/console.php` (Laravel 11+ styl, žádný projekt nemá `app/Console/Kernel.php` v této roli): `Schedule::command('notifier:heartbeat')->hourly();`. Bez `NOTIFIER_URL` je to no-op, takže bezpečné. Jen kalianko-cz.
7. **Názvy backup příkazů jsou IDENTICKÉ** (`notifier:database-backup`, `notifier:storage-backup`, `notifier:check`) — ať už projekt spouští backup přes inbound HTTP trigger z control-plane (`routes_enabled=true`, `/api/notifier/backup`) nebo přes artisan v `.gitlab-ci.yml`, nic se nemění.
8. **Žádná registrace service-provideru** — package auto-discovery to řeší; žádné úpravy `bootstrap/providers.php`.
9. **Žádná adopce Blade komponenty** (`<x-notifier-announcements-notice />`, `AnnouncementsService::customAnnouncements()`, Filament render-hook banner) nebyla v žádném ze 3 projektů provedena — jsou to nové agentí featury bez reálného konzumenta.
10. **Ověř `php: ^8.4`** před migrací (všechny 3 reference už splňovaly).

**Dvě reálné cesty:** buď „plná" (kalianko-cz: composer + config republish + heartbeat + `.env.example`), nebo „minimální" (priznanisnadno-cz, sadrokartony-izolace.cz: pouze composer.json/lock swap, „žádné změny v aplikačním kódu"). Obě fungují.

---

## 2. Per-projekt matice

Seřazeno od nejsnazších (drop-in) po BLOCKED.

| # | Projekt | Package | Laravel (lock) / PHP | Agent-kompat. | Risk | Hlavní blocker / poznámka |
|---|---------|---------|----------------------|---------------|------|---------------------------|
| 1 | **dochazka-luboschlanda-cz** | v2.5.0 | 12.55.0 / ^8.4 (8.5.7) | YES | **LOW** | Nejlepší případ. **WIP větev `origin/chore/migrate-notifier-agent` (7ebd2bb) už migraci provedla** a je strict fast-forward main. Jen syncnout vendor (`composer install`, lokálně stale 2.3.0) a mergnout. |
| 2 | **dochazka-azstavby-cz** | v2.6.4 | 13.15.0 / ^8.4\|^8.5 | YES | **LOW** | Čistý drop-in. 1 scheduled cmd (`notifier:database-backup` v `routes/console.php`). `.env` postrádá `NOTIFIER_QUEUE_CONNECTION` (je v .example) — sladit. |
| 3 | **pujcovna-skiricky-cz** | v2.6.0 | 12.56.0 / ^8.5 | YES | **LOW** | Nejčistší. Config lehce driftnutý (chybí `declare(strict_types=1)` + nepoužitý `queue_connection`) — při republish očistit. `.env` bez `NOTIFIER_QUEUE_CONNECTION`. |
| 4 | **pujcovna-newmanschool-cz** | v2.6.0 | 12.56.0 / ^8.5 | YES | **LOW** | Drop-in. Vendor lokálně stale (1.0.24) → `composer install`. V checkoutu není `.env` — ověřit prod hodnoty `NOTIFIER_*` před cutoverem. |
| 5 | **xcp-commander** | v2.6.4 | 13.13.0 / ^8.5 | MAYBE | **LOW** | Lock 13.13.0 těsně pod `^13.14`. Constraint `^13.13.0` už to povoluje → `composer update laravel/framework`, žádný conflict. 1 scheduled cmd. Bez `.env` v tree. |
| 6 | **dopravnihriste.eu** | v2.3.4 | 12.52.0 / ^8.4 | MAYBE | **LOW** | Lock pod 12.55. Constraint `^12.49.0` povoluje → `composer update`. `composer.json` pinuje **přesně `2.3.4`** (bez caretu) — nahradit za `^1.6`. Config = vendor default. |
| 7 | **grid** | v2.4.3 | 12.53.0 / ^8.4 | MAYBE | **LOW** | Lock pod 12.55, constraint `^12.0` povoluje → `composer update`. `.env` nemá žádné reálné `NOTIFIER_*`/`BACKUP_*` (jen prázdné placeholdery) — ověřit, zda backup vůbec běží. |
| 8 | **pujcovna-cz** | v2.6.0 | lock 12.56.0 / ^8.5 | MAYBE | **MEDIUM** | Lock/vendor desync: `composer.lock` = 12.56.0/2.6.0, ale fyzicky nainstalováno 12.48.1/2.3.0. **Nutno `composer install` (ne update)** a ověřit `artisan --version` ≥12.55 v dev i **prod (manuální deploy!)** před swapem. |
| 9 | **kamzajitolomouc.cz** | v2.6.4 | 13.14.0 / ^8.5 | YES | **MEDIUM** | Framework OK (přesně na floor 13.14, nulová rezerva). **`.env` má duplicitní blok `BACKUP_*` (2×), druhý vyhrává a je prázdný → backupy z working copy pravděpodobně neběží.** Žádné scheduling v repu, jen legacy `BACKUP_*` klíče. |
| 10 | **autoauto-cz** | v2.3.0 | 12.48.1 / ^8.4 | MAYBE | **MEDIUM** | Framework bump (constraint `^12.40.2` povoluje → `composer update`). **`config/notifier.php` je STALE** (jen 3 flat klíče, plain `env('BACKUP_*')`, chybí NOTIFIER_* fallbacky i novější klíče) → reálný re-publish/hand-merge. Žádné `NOTIFIER_*` env, nejasný stav scheduling (Jenkins „Backup" stage je stub). |
| 11 | **desk** | v2.4.3 | 12.54.1 / ^8.2(text) | NO | **MEDIUM** | Framework pod floor. Naivní `composer update laravel/framework` selže na `lcobucci/clock` (přes passport→oauth2-server), bez PHP 8.5 supportu. **Řešení: `composer update laravel/framework lcobucci/clock league/oauth2-server --with-dependencies`** → 12.62.0. Navíc `php` constraint `^8.2`→`^8.4` (kosmetika, runtime už 8.4+). |
| 12 | **vodo-topo-brauner-cz** | v2.6.1 | 13.3.0 / ^8.5 | NO | **BLOCKED** | Lock 13.3.0 pod `^13.14`. Composer install agenta **dnes odmítne**. Constraint `^13.3.0` bump povoluje → `composer update laravel/framework` na ≥13.14. Zbytek wiringu čistý (3 `NOTIFIER_*`, config vendor-default). |
| 13 | **pujcovnakuncice-newmanschool-cz** | v2.3.4 | 12.48.1 / ^8.4 | NO | **BLOCKED** | Lock 12.48.1 pod `^12.55`, composer install agenta odmítne. Constraint `^12.44.0` bump povoluje → `composer update`. V checkoutu není `.env` — ověřit prod hodnoty (pozor na kamzajitolomouc precedent s duplicitními `BACKUP_*`). |

---

## 3. Doporučené vlny

### Vlna 1 — LOW / drop-in (dělat hned)

Framework buď splňuje gate, nebo stačí triviální `composer update` v rámci už povoleného constraintu (žádná editace `composer.json`, žádný major upgrade). Migrace = záměna composer řádku + `composer update` + review env/config.

- **dochazka-luboschlanda-cz** — POUZE syncnout vendor (`composer install`) a **mergnout existující větev `chore/migrate-notifier-agent` (7ebd2bb)**, spustit testy, nasadit. Ověřit `NOTIFIER_QUEUE_CONNECTION` default `sync`.
- **dochazka-azstavby-cz** — krok 1–2 receptu; doplnit `NOTIFIER_QUEUE_CONNECTION` do `.env`; ověřit, že `notifier:database-backup` v `routes/console.php` stále existuje pod stejným názvem (existuje).
- **pujcovna-skiricky-cz** — krok 1–2; při republish configu očistit drift (`declare(strict_types=1)`, odstranit/potvrdit `queue_connection`); doplnit `NOTIFIER_QUEUE_CONNECTION`.
- **pujcovna-newmanschool-cz** — `composer install` (vendor stale 1.0.24) → krok 1–2; před cutoverem ověřit prod `.env` (`NOTIFIER_URL`/`_BACKUP_CODE`/`_BACKUP_PASSWORD`), v repu chybí.
- **xcp-commander** — `composer update laravel/framework` (13.13→≥13.14) → krok 1–2; ověřit, že scheduler string `'notifier:database-backup'` odpovídá agentu; ověřit prod `.env`.
- **dopravnihriste.eu** — `composer update laravel/framework` (12.52→12.55+); v `composer.json` nahradit **přesný pin `2.3.4`** za `^1.6`; remap 3–4 `NOTIFIER_*` klíčů; doplnit `NOTIFIER_QUEUE_CONNECTION`.
- **grid** — `composer update laravel/framework` (12.53→12.55+) → krok 1–2; **ověřit, zda backup vůbec běží** (`.env` má jen prázdné placeholdery, žádný scheduling) — pokud nefunkční, dořešit mimo migraci.

### Vlna 2 — MEDIUM (vyžaduje reálnou práci navíc)

- **pujcovna-cz** — **nejdřív `composer install`** (ne update) k synchronizaci vendor (fyzicky 12.48.1/2.3.0 vs lock 12.56.0/2.6.0), ověřit `artisan --version` ≥12.55 v dev **i v prod (deploy je manuální!)**, teprve pak swap na agenta. Wiring jinak čistý.
- **kamzajitolomouc.cz** — **před migrací vyčistit `.env`**: odstranit duplicitní `BACKUP_*` blok (druhý prázdný vyhrává → backupy neběží), přenést reálné secrety do `NOTIFIER_*` klíčů. Framework OK. Nastavit scheduling od nuly (v repu žádný není), pak krok 1–2.
- **autoauto-cz** — `composer update laravel/framework` (12.48.1→12.55+); **reálný re-publish + hand-merge `config/notifier.php`** (stale: chybí NOTIFIER_* fallbacky i `excluded_tables/excluded_files/logging_channel/routes_enabled/route_prefix/zip_strategy/chunk_size`); přidat `NOTIFIER_*` env (dnes jen legacy `BACKUP_*`); **vyjasnit s userem, jak/zda backupy dnes reálně běží** (Jenkins stage je stub) — díra je pre-existující, ale zůstane.
- **desk** — framework bump speciálním příkazem: **`composer update laravel/framework lcobucci/clock league/oauth2-server --with-dependencies`** (naivní update selže na PHP-8.5-nekompatibilní `lcobucci/clock` přes passport→oauth2-server; 9.4.1 tu závislost dropuje) → 12.62.0; utáhnout `php` constraint `^8.2`→`^8.4`; pak swap. Config = vendor default (triviální). Před cutoverem zdroj reálných secretů (working `.env` je prázdný).

### Vlna 3 — BLOCKED (nejdřív framework, pak migrace)

Composer install agenta dnes **odmítne** — lock je pod floor. Nejde o major upgrade, ale musí proběhnout a být otestován **jako samostatný, ověřený předstupeň** (a nezapomenout na manuální prod deploy).

- **vodo-topo-brauner-cz** — `composer update laravel/framework` z 13.3.0 na ≥13.14 (constraint `^13.3.0` to povoluje), spustit testy, pak krok 1–2. Zbytek čistý → po bumpu klesá na LOW.
- **pujcovnakuncice-newmanschool-cz** — `composer update laravel/framework` z 12.48.1 na ≥12.55 (constraint `^12.44.0` povoluje), pak krok 1–2. Config vendor-default, env už `NOTIFIER_*`. **Ověřit prod `.env`** (v checkoutu chybí; pozor na precedent kamzajitolomouc s duplicitními `BACKUP_*`) → po bumpu LOW.

---

## 4. Rizika & pozor (společné pasti)

- **Composer conflict:** `notifier-agent` má `conflict: devuni/notifier-package: "*"`. Řádek v `composer.json` se musí **nahradit**, nikdy nepřidat vedle — jinak nejde nainstalovat.
- **Velký `composer.lock` diff:** `laravel/framework` (a tranzitivně guzzle/carbon/…) se bumpne kvůli `illuminate ^12.55||^13.14`, i když `composer.json` frameworku neupravujete. Stovky řádků v locku pro jednořádkovou koncepční změnu — nelekat se v code review.
- **Duplicitní / stale env klíče:**
  - **kamzajitolomouc.cz** — empiricky ověřeno: **`.env` má `BACKUP_*` blok 2×, poslední (prázdný) vyhrává** → zálohy z working copy neběží. Vyčistit před migrací.
  - **sadrokartony-izolace.cz** (reference) i další nesou mrtvé legacy `BACKUP_CODE/BACKUP_URL/BACKUP_ZIP_PASSWORD` vedle aktivních `NOTIFIER_*` — neškodné (fallback-only), ale při editaci `.env(.example)` uklidit.
  - **Nezaměňovat s touto migrací:** rename `BACKUP_*` → `NOTIFIER_*` proběhl dřív (leden 2026, package v1→v2, priznanisnadno-cz commit 6c973e2). Package→agent swap žádné klíče nepřejmenovává.
- **Framework blockery = lockfile refresh, ne major upgrade:** u všech projektů pod floor už existující `composer.json` constraint vyšší verzi povoluje. Riziko je nízké, ale musí se to reálně spustit a otestovat. **Výjimka: desk** — naivní `composer update laravel/framework` selže na `lcobucci/clock`; nutné explicitně přibrat `lcobucci/clock league/oauth2-server --with-dependencies`.
- **Vendor/lock desync (read-only sandbox artefakt):** u **pujcovna-cz** (fyzicky 12.48.1/2.3.0 vs lock 12.56.0/2.6.0), pujcovna-newmanschool-cz (1.0.24), dochazka-luboschlanda-cz (2.3.0), pujcovnakuncice a dalších je vendor stale, protože `vendor/` je gitignored a `composer install` v sandboxu neproběhl. **Autoritativní je `composer.lock`.** Před lokálním ověřením/diffem configu vždy `composer install`. U pujcovna-cz je to nejostřejší — bez syncu install agenta selže / poběží proti nekompatibilnímu frameworku.
- **Manuální deploy = kód před migracemi/composerem:** deploy Notifier projektů je v této organizaci ručně a nemigruje automaticky. U MEDIUM/BLOCKED (zejména pujcovna-cz) ověřit framework verzi **i na produkci**, jinak fleet-wide fail.
- **Scheduling se nemění, ale někde chybí úplně:** názvy příkazů jsou identické, takže existující cron/CI netřeba měnit. Ale **kamzajitolomouc.cz, autoauto-cz, grid** nemají v repu žádné scheduling ani reálné env hodnoty → ověřit, zda backup dnes vůbec běží (out-of-repo cron / inbound HTTP trigger). Tato díra je pre-existující a zůstane i po migraci, pokud se neřeší zvlášť.
- **Config je zpětně kompatibilní, ale minimal-diff přijde o featury:** starý `config/notifier.php` funguje beze změny, ale bez republish nedostanete `heartbeat`, `announcements` ani Postgres/multi-connection podporu. Pokud je chcete → republish + hand-merge (u autoauto-cz je to navíc reálná práce, config je hodně stale).
- **Announcement UI nikdo nenasadil:** `<x-notifier-announcements-notice />`, `customAnnouncements()` ani Filament banner nejsou v žádném projektu reálně umístěny ve view (jen default-on toggly v configu). Nepředpokládat, že cokoli announcementy zobrazuje.
- **Nový `NOTIFIER_TRIGGER_SECRET`** (aditivní, ne rename): fallback `NOTIFIER_BACKUP_CODE` → `BACKUP_CODE`. Sémantika: `backup_code` nově autentizuje OUTBOUND (X-Notifier-Token), `trigger_secret` INBOUND server→klient backup trigger (secret-split, defense in depth). Bez nastavení funguje po staru.