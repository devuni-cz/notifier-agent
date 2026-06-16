# TODO - devuni/notifier-agent

> Backlog z multi-agentního auditu 2026-06-14 (dedup + ověřeno proti kódu). Stav: v1.1.0.
> Priorita: 🔴 critical · 🟠 high/medium · 🟡 low · ✅ hotovo.
> Reference `soubor:řádek` vůči stavu při auditu - před zásahem ověřit.

## ✅ Hotovo

v1.0.2 (security: throttle-před-token, pre-auth 403 unifikace, dump chmod 0600 + cleanup, `.env` escapování) · v1.1.0 (announcement types `maintenance/outage/release/notice` + chip rendering + `data-announcement-id` groundwork; eslint-style enum). Server↔klient kontrakt end-to-end konzistentní.

---

## 🟠 Testy (iluzorní pokrytí)

- [~] **„33 skipnutých command testů" — NEAKTUÁLNÍ** (audit 2026-06-14). Reálný stav: ~4 skipnuté (3 pg_dump Windows-only + 1 7z behaviorální). Install/Storage/Database command testy běží. Číslo bylo stale.
- [x] **Filament render-hook auto-injekce — UŽ POKRYTO** (TODO bylo stale): `tests/Filament/FilamentRenderHook*` (13 testů) ověřuje registraci/ne-registraci/per-target/validity přes reálný `FilamentView::hasRenderHook`/`renderHook`. `filament/support ^5.6` je správně v `require-dev` (konzumenti nedostanou; runtime `class_exists` guard = dashboard-agnostické) + `suggest` entry přidán (PR #19).
- [x] 🟠 **(PR #19)** `ProcessBackupJob` (tries=1/900s kontrakt) + `NOTIFIER_QUEUE_CONNECTION` dispatch větev pokryty (`ProcessBackupJobTest` + `BackupQueueDispatchTest`).
- [x] 🟡 **(PR #19)** ZIP creators behaviorálně otestovány (`PhpZipCreatorTest` dokazuje AES-256 garanci — správné heslo dešifruje, špatné ne; `CliZipCreatorTest` 7z na CI). + opraven Windows entry-path bug (separátory).
- [ ] 🟡 Přidat `Http::preventStrayRequests()` do HTTP testů; protrhat tautologický `NotifierServiceProviderTest`.

## 🟠 CI / release

- [ ] **CI matrix má jediný entry** (php 8.4 / laravel 12.*) - composer povoluje `illuminate ^12||^13.14`, dev jede na L13, ale L13 se validuje až na release gate. Přidat L13 (a druhou PHP verzi) do push/PR CI.
- [x] 🟠 **(fix/release-workflow-hardening)** `release.yml` hardening: CHANGELOG guard (`exit 1` při chybějící sekci místo placeholderu), least-privilege `permissions` (`contents: write` jen na `release` job), `environment: release` gate na `release` i `version-bump` job. ⚠️ **ZBÝVÁ repo-side (GitHub UI) — bez něj jsou YAML gates no-op:** (1) **Protected tag ruleset** `v*` — vytvářet smí jen maintaineři (TOTO je reálná pojistka proti „kdokoli pushne tag → publikuje všem klientům"); (2) **Environment `release`** se *required reviewers* (Settings → Environments) → manuální approval před release i version-bump; (3) omezit, kdo smí spouštět `workflow_dispatch`.
- [ ] 🟡 `version-bump.yml` pushuje přímo na `main` přes `GITHUB_TOKEN` a `code-style-fix.yml` auto-commituje na `github.head_ref` s `contents:write` na `pull_request` (fork-PR privilege-escalation surface). Ověřit branch-protection / omezit na same-repo PR.
- [ ] 🟡 Rector: pustit v CI nebo zdokumentovat jako local-only (nakonfigurovaný, ale CI ho nikdy nevolá).

## 🟡 Dokumentace / drobnosti

- [ ] **`SECURITY.md` má prohozené verze** (ř. 7–11) - deklaruje 2.x supported / 1.x unsupported; reálně je to v1.0.0–v1.1.0 (2.x patří superseded `notifier-package`). Opravit.
- [ ] `README.md:76` tvrdí „Rate-limited to 10 req/min", ale `routes/web.php:16` je `throttle:10,60` = **10/hod**. Opravit doc.
- [ ] Přepsat stale `VERSION_MANAGEMENT.md` (boilerplate o registraci na packagist/webhook neodpovídá realitě).
- [ ] 🟡 Divergentní announcements fallback literály: config `features.announcements` default true vs `AnnouncementsService.php:36` default false; `failure_cache_ttl` 300 vs fallback 60 (neškodné přes `mergeConfigFrom`, ale matoucí). Sjednotit.
- [ ] 🟡 Ověřit, že Filament hook `panels::content.start` je platný v majoru Filamentu nasazeném v klientských appkách (přesunutý hook = banner tiše nerenderuje).
- [ ] 🟡 Ověřit sync `AnnouncementTypeEnum` se serverem + graceful degrade na `tryFrom` (žádný chip pro NOTICE/neznámé).
- [ ] 🟡 Raise PHPStan nad level 5 (teď level 5 clean, bez baseline/ignores) - dobrý odrazový bod pro 6+.

## 🟡 ZIP / backup

- [x] 🟠 **(fix/install-zip-password-policy)** `notifier:install` vynucuje sílu ZIP hesla: min. 16 znaků + min. 6 různých znaků (`NotifierInstallCommand::backupPasswordError`), default nabízí auto-generaci `bin2hex(random_bytes(24))`. Dřív přijal libovolný neprázdný řetězec (security review HIGH `d_secret123`). 4 testy + aktualizované install/escaping testy.
- [ ] 🟡 ZIP šifruje obsah (AES-256), ale **ne názvy souborů** v central directory. Rozhodnout, zda jsou path metadata citlivá (`7z -mhe=on` umí, PHP `ZipArchive` ne).

## 📦 Packaging (cross-cutting s notifier-package)

- [ ] **Označit `devuni/notifier-package` jako `abandoned`** na Packagistu (replacement `devuni/notifier-agent`) + archiv GitHub repa - jeho `composer.json` nemá `abandoned` klíč, konzumenti dál updatují mrtvou 2.x linii.
- [ ] 🟡 Rozhodnout o `v1.0.0` (yank vs nechat): `conflict` proti notifier-package byl přidán až ve `v1.0.1`, takže konzument pinnutý přesně na `v1.0.0` může nainstalovat oba balíčky a rozbít resolving sdíleného namespace.

## 🔭 Roadmap (probráno, nepostaveno)

- [x] **Heartbeat/identity manifest (v1.3.0, server !111)** - `HeartbeatService` + `notifier:heartbeat` POSTuje manifest (verze, php/laravel, queue_connection, enabled_features, disk free/total, last db/storage backup, reported_at) na `/heartbeat`; push semantika (throws); host scheduluje hourly. Server: `RepositoryHeartbeat` (upsert, vlastní receipt time), Agent card na repo show, opt-in + once-guard stale alerting. **Scheduler/cron liveness je pokrytý implicitně** (příchod heartbeatu = scheduler žije).
- [ ] **Heartbeat → extensible health-checks layer (budoucí increment)** - dnešní manifest je TYPOVANÝ (sloupcový), takže nový signál = migrace. Pro monitorování host infra (queue workeři/supervisor, systemd, cron-detail) přidat GENERICKÝ `checks` json bag: agent hlásí mapu pojmenovaných checků (`{queue:{status,detail}, supervisor:notifier-worker:{...}, systemd:redis:{...}}`), server uloží do JEDNOHO json sloupce (nový check = ŽÁDNÁ migrace), UI renderuje genericky, alerting per-check status. Na agentu pluggable `HealthCheck` interface (každý check = třída; config allowlist služeb). **Snadné+vysoko-hodnotné:** queue-depth/oldest-pending + scheduler-last-run (DB/cache, žádný shell). **Pozor:** supervisorctl = shell (jde-li web-user), `systemctl is-active` = obvykle potřebuje root → web-user nemusí smět. (Probráno 2026-06-15, odloženo.)
- [ ] **Backup restore-verifiability** - dokázat, že shippnutá šifrovaná záloha se dešifruje a dump obnoví.
- [ ] Thin WARNING+ log shipper do control plane (level filtering + rate/volume control).
- [ ] Capability-kernel refactor (formalizovat backups/announcements za společný registry).
