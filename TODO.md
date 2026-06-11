# TODO — devuni/notifier-agent

> Vygenerováno z multi-agentní analýzy 2026-06-11 (stav: v1.0.1, main == origin/main).
> Priorita: 🔴 critical/high · 🟠 medium · 🟡 low/nice-to-have · ✅ ověřeno jako hotové (kontext).
> Reference jsou `soubor:řádek` vůči stavu při analýze — před zásahem ověřit, kód se mohl pohnout.

## 🔴 Bezpečnost

- [x] ~~**Rate-limitovat neúspěšné pokusy o token na příchozím `/backup` endpointu.**~~ — v1.0.2: throttle běží před ověřením tokenu (limit 10/hod beze změny) + test na 429 při wrong-token floodu.
- [x] ~~**Plaintext `.sql` dump na disku.**~~ — v1.0.2: `@chmod 0600` hned po dumpu (Windows no-op) + `try/finally` kolem zipování, dump se smaže i při selhání ZIPu; pokryto testem.
- [x] ~~**Endpoint před autentizací prozrazuje konfiguraci.**~~ — v1.0.2: chybějící token / špatný token / rozbitá konfigurace = identická generická 403; skutečný důvod (vč. IP volajícího) jde do logu.
- [x] ~~**`notifier:install` zapisuje tajemství do `.env` bez escapování.**~~ — v1.0.2: hodnoty se escapují (backslash + uvozovka), `--force` replace přes `preg_replace_callback` (jinak by escapované hodnoty mrzačil); testy vč. round-trip přes Dotenv::parse.
- [ ] 🟡 **ZIP nešifruje názvy souborů uvnitř archivu** (obsah je AES-256, filenames ne). Zvážit, zda metadata souborů nejsou citlivá.

## 🔴 Release / packaging

- [ ] **Označit starý `devuni/notifier-package` na Packagistu jako `abandoned`** (s náhradou `devuni/notifier-agent`) a **archivovat starý GitHub repo.** Jinak konzumenti dál instalují/aktualizují mrtvou linii. (Akce mimo tento repo, ale patří sem jako tracking.)
- [ ] **Doplnit `conflict` i pro pomyslnou cestu přes v1.0.0** / nebo zajistit, že nikdo neinstaluje `1.0.0` (conflict přidán až v 1.0.1, `1aedb1e`). Drobné — zvážit yank v1.0.0 nebo nechat být.
- [ ] **Tvrdý filtr tagů přímo v `release.yml`.** Dnes jediná bariéra proti publikaci starých `v2*/v3*` tagů je GitHub ruleset (id 17493897); `release.yml` se spustí na JAKÝKOLI `v*`. Přidat do workflow guard na povolený rozsah verzí (defense-in-depth). — `.github/workflows/release.yml`

## 🟠 Testy / CI

- [ ] **Filament auto-injekce announcements je netestovaná** — Filament není v `require-dev`, render hook `panels::content.start` se v testech nikdy nevykoná, přitom je to vlajková funkce ON by default. Přidat `filament/filament` do dev závislostí a behaviorální test injekce banneru.
- [ ] **40 z ~240 testů je trvale skipnutých** (install command 15/15, storage-backup 11/16) → pokrytí commandů je z velké části iluzorní. Doplnit reálné testy nebo skipy odstranit/odůvodnit. — `tests/`
- [ ] **`ProcessBackupJob` a queue-dispatch větev controlleru nemají žádný test** (timeout 900 s, tries 1).
- [ ] **CI matice testuje jen PHP 8.4 + Laravel 12**, ačkoli balíček deklaruje i L13 a lokálně se na L13 vyvíjí (L13 se ověří až release gate při tagu). Přidat L13 do průběžné push/PR matice; přidat i L12 lokálně. — `.github/workflows/ci.yml`
- [ ] 🟡 **`NotifierStorageServiceTest` je převážně tautologický**; ZIP creatory (7z CLI / PHP ZipArchive) nemají behaviorální test.
- [ ] 🟡 **Testovací hygiena:** doplnit `Http::preventStrayRequests()`, pročistit vatu v `NotifierServiceProviderTest`.
- [ ] 🟡 **Rector je nakonfigurován, ale CI ho nikdy nespouští** — přidat krok, nebo přiznat, že je jen lokální. — `rector.php`

## 🟠 Statická analýza

- [ ] **PHPStan zpřísnit nad level 5** (běží bez baseline a s čistými ignoreErrors — dobrá výchozí pozice pro posun na level 6+). — `phpstan.neon`

## 🟡 Konzistence / dokumentace

- [ ] **Sjednotit fallback defaulty `announcements` mezi kódem a configem.** `failure_cache_ttl` 300 (config) vs. 60 (kód), `features.announcements` true (config) vs. false (kód). Efektivně neškodné díky `mergeConfigFrom`, ale matoucí. — `src/Services/AnnouncementsService.php:36,119` vs `config/notifier.php:218,239`
- [ ] **Aktualizovat `SECURITY.md`** — deklaruje neexistující podporovanou řadu 2.x, aktuální tagy jsou v1.0.x.
- [ ] **Sjednotit dokumentaci throttle limitu.** README tvrdí „10 req/min", kód má `throttle:10,60` (= 10/hod). — `routes/web.php` vs `README.md`
- [ ] 🟡 **`VERSION_MANAGEMENT.md`** je generický boilerplate s drobně zastaralou sekcí o registraci na Packagist — sladit s realitou.

## ✅ Ověřeno jako vyřešené (jen kontext, neřešit)

- ✅ composer `conflict` na `devuni/notifier-package:*` **existuje** (od v1.0.1, `1aedb1e`) — kolize tříd vyřešena.
- ✅ tag hazard mitigován GitHub rulesetem (`v2*`/`v3*` blokované).
- ✅ HTTP transport zatvrzený: HTTPS-only, `allow_redirects=false`, origin-pinning `status_url`.
- ✅ DB hesla přes env (`MYSQL_PWD`/`PGPASSWORD`), ne argv; ZIP heslo přes stdin.
- ✅ Announcements obsah ze serveru je v Blade vždy escapovaný (`{{ }}`) — XSS nehrozí.
- ✅ Zpětná kompatibilita env jmen zachována (všech 9 klíčů + legacy `BACKUP_*` fallbacky).
