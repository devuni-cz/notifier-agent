# Notifier — Remediation & Cleanup Plan (notifier-agent + notifier-devuni-cz)

## Context

Čerstvý dvou-repo audit (multi-agentní workflow, Opus 4.8, ověřeno proti živému kódu — Pint/PHPStan/Pest reálně spuštěné) ukázal, že oba repozitáře jsou v zásadě zdravé, ale nesou několik konkrétních dluhů:

- **notifier-agent (v1.6.2)** je vyzrálý a zelený (Pint čistý, PHPStan L5 bez baseline, Pest 342/4/0). Otevřené jsou jen **dokumentační/konfigurační okraje** + **CI/release-hardening**.
- **notifier-devuni-cz** má commitnutý MCP refactor (49/49 testů), ale dvě věci kazí obraz: **phpstan baseline je rozbitý** po MCP reorgu (analyse abortuje), a několik bezpečnostních věcí zůstává otevřených — zejm. **SSH bridge token nebinduje cíl** (HIGH) a **P0 unikly secrety v git historii** (operační).

Tento plán sjednocuje všechny nálezy do jednoho akčního seznamu a rozděluje je na **(K) kódové změny, které udělám v pracovním stromu** a **(O) operační kroky, které musíš udělat ty** (git, rotace secretů, GitHub UI, prod deploy, GCP).

## Ground rules

- Všechny změny dělám **jen v pracovním stromu**. Git (commit/push/tag/release) i operační akce děláš ty (per `no-commit-no-push-rule`).
- Po schválení nejdřív uložím tento plán do repo docs (`notifier-devuni-cz/docs/remediation-plan-2026-06-30.md` + relevantní slice do `notifier-agent/docs/`) per `save-plans-to-repo-docs`.
- Po každém kódovém bloku spustím příslušné brány (pint/phpstan/pest) a nahlásím reálná čísla.

---

## ČÁST A — notifier-agent (v1.6.2) — in-tree polish

Drobné, nízkorizikové úpravy. Vše ověřeno na konkrétních řádcích.

### A1 — (K) Announcements config-literal divergence
Sjednotit fallback literály se skutečnými config defaulty:
- `src/Services/AnnouncementsService.php:43` → `config('notifier.features.announcements', false)` → změnit default na **`true`** (config je `env(..., true)`, provider/manifest/command už používají `true`).
- `src/Services/AnnouncementsService.php:237` → `config('notifier.announcements.failure_cache_ttl', 60)` → **`300`** (config default je 300, CHANGELOG 1.0.1 explicitně „60→300").

### A2 — (K) `features.backups` fiktivní toggle
`src/Services/HeartbeatService.php:62` čte `config('notifier.features.backups', true)`, ale `config/notifier.php` ho v `features` nedefinuje (jen `announcements` + `heartbeat`). **Přidat reálný klíč** do `config/notifier.php` `features`:
```php
'backups' => env('NOTIFIER_BACKUPS_ENABLED', true),
```
(konzistentní s `announcements`/`heartbeat`; manifest pak inzeruje reálný vypínač). Doplnit komentář k bloku.

### A3 — (K) CHANGELOG footer compare-link tabulka
`CHANGELOG.md:150-158` má link-defs jen do v1.4.1 (chybí 1.5.0/1.6.0/1.6.1/1.6.2; tagy existují). Doplnit chybějící `[x.y.z]: .../compare/...` řádky a přepsat `[Unreleased]` compare base na **`v1.6.2...HEAD`**.

### A4 — (K) Mrtvý kód
`src/Enums/BackupTypeEnum.php` — `values()` a `validationRule()` nemají žádné externí použití (`BackupRequest` validuje přes `Rule::enum`). Po finálním grep-ověření odstranit.

### A5 — (K) Test úklid
`tests/Unit/NotifierServiceProviderTest.php` — odstranit tautologické asserty (config not-null/isArray) + zavádějící „deferred provider" komentář. (`Http::preventStrayRequests()` už je v `tests/TestCase.php` — hotovo.)

### A6 — (K) Drobné doc nepřesnosti
- `README.md:6` badge „Laravel 12" → odrážet `^12.55 || ^13.14`.
- `VERSION_MANAGEMENT.md` — drobně osvěžit (zmínit RELEASE_PAT + approval gate; „Updates documentation" krok v release.yml neexistuje).

### A7 — (K) TODO.md refresh
Označit jako **DONE** položky, které jsou už hotové v kódu: ln23 (CI matrix má 3 řádky), ln30 (SECURITY.md verze správně), ln31 (README rate-limit „10/hour"). Přeanchorovat ln33 na `:43`/`:237`. Bumpnout hlavičku „Stav: v1.1.0" → **v1.6.2**.

### A8 — (K) CI matrix díra (workflow soubor)
`.github/workflows/ci.yml` matice má {8.4/L12, 8.4/L13, 8.5/L13} — chybí **PHP 8.5 + Laravel 12** (obě deklarovaná jako podporovaná). Přidat řádek (s `composer require orchestra/testbench:^10.0` overridem + carbon<3.12 pinem jako u stávajícího L12 řádku). *(in-tree edit; pushneš ty)*

### A-OPS — (O) Operační / GitHub UI (děláš ty)
- **Release hardening (UI):** protected-tag ruleset `v*` (vytvářet smí jen maintaineři), required reviewers na `release` environment, omezit `workflow_dispatch`. Bez toho jsou YAML brány v `release.yml` no-op.
- **`code-style-fix.yml`:** omezit na same-repo PR (běží na `pull_request` s `contents: write` + auto-commit `github.head_ref` = fork-PR eskalace).
- **Ověřit, že `RELEASE_PAT` je nastavený secret** (jinak `version-bump` padá na `GITHUB_TOKEN`, který nespustí Release).

---

## ČÁST B — notifier-devuni-cz

### B1 — (K) SSH bridge token-binding (HIGH) — **rozhodnuto: opravit teď**

**Problém:** token podepisuje jen `{expiry}` (`SshBridgeTokenService::generate()`), a `server.js:67` bere `host/port/username` z **client query stringu** bez ověření → jeden platný token = SSH na libovolný cíl/uživatele sdíleným klíčem.

**Fix (core) — host/port/username musí přijít z PODEPSANÉHO tokenu, ne z query:**

1. **PHP — `app/Services/Ssh/SshBridgeTokenService.php`:**
   `generate(RepositoryServer $server, int $userId): ?string` — payload = `base64url(json{host, port, username, server_id, user_id, exp, jti})`, token = `payload_b64 . '.' . hash_hmac('sha256', payload_b64, secret)`. `jti` = `Str::uuid()`. Zachovat fail-closed (null když secret prázdný) + TTL z `security.ssh_bridge.token_ttl_seconds`.

2. **Node — `server.js`:** přepsat `isValidBridgeToken` aby parsoval `payload_b64.signature`, ověřil HMAC nad `payload_b64`, base64-dekódoval JSON, zkontroloval `exp`, a **host/port/username bral z dekódovaného payloadu — query string úplně ignorovat** (řádek 67 zrušit). Connection params jsou tím server-trusted (minted z DB).

3. **Controller — `app/Http/Controllers/RepositoryServerController.php:166-191` (`vpsConnect`):** volat `generate($repository_server, $request->user()->id)`; audit log už existuje (rozšířit o `jti`). Gating ponechat (`can:view_any_repositoryserver` + `throttle:10,1`).

4. **Frontend — `resources/js/Pages/RepositoryServer/VpsConnect.jsx:11-19`:** přestat skládat `?host=&port=&username=` (bridge je ignoruje); posílat jen `auth:{token}` na `VITE_NODE_SERVER_URL`.

5. **Tests — `tests/Feature/Security/SshBridgeTokenTest.php`:** přepsat na nový formát (payload obsahuje host/port/username/server_id/user_id/exp/jti; signature = hmac nad payloadem; token bez kontextu / s pozměněným payloadem neprojde). `RepositoryServerVpsConnectTest.php` ponechat (audit + throttle stále platí).

**Fix (hardening, doporučený follow-on ve stejném work-itemu) — one-time nonce (`jti`):**
- Nový interní endpoint `POST /internal/ssh-bridge/consume` (auth přes HMAC bridge-secret header, ne session), který atomicky `Cache::add("ssh-bridge:jti:{jti}", true, ttl)` → 200 při prvním použití, 409 jinak. `server.js` po ověření tokenu zavolá tento endpoint a odmítne spojení, pokud ≠ 200.
- ⚠️ Vyžaduje Node→PHP HTTP roundtrip; core fix (kroky 1–5) už uzavírá kritický exploit „jeden token → libovolný cíl", takže nonce je nižší priorita. Doporučím rozhodnout při exekuci.

> ⚠️ **Pozn. k P0:** tento fix NEnahrazuje rotaci uniklého `privatekey.txt` (viz B-OPS) — klíč je kompromitovaný v historii bez ohledu na binding.

### B2 — (K) RepositoryFiles index (file-list) stránka — **rozhodnuto: doimplementovat**

Doc backups #10 slibuje file-list, ale existuje jen `Create.jsx`. Mirror `Hosting` index vzoru. `RepositoryFileIndexResource` **už existuje** (`app/Http/Resources/RepositoryFiles/RepositoryFileIndexResource.php`), route group `repository-files` je gateována `can:view_any_repositoryfile`.

| Vrstva | Soubor | Akce | Vzor |
|---|---|---|---|
| Request | `app/Http/Requests/RepositoryFiles/IndexRepositoryFileRequest.php` | **vytvořit** | `IndexHostingRequest` (search/sort_field/sort_order; sort in `id,name,file_type,size,created_at`) |
| Controller | `app/Http/Controllers/RepositoryFileController.php` → `index()` | **přidat metodu** | `HostingController::index()` — `RepositoryFile::with('repository')->when(search)->orderBy()->paginate(20)->withQueryString()`, `inertia('RepositoryFiles/Index', [...])` |
| Route | `routes/web.php` (skupina `repository-files`) | **přidat** `GET /` → `index`, `->name('index')` | první v group |
| Page | `resources/js/Pages/RepositoryFiles/Index.jsx` | **vytvořit** | `Hostings/Index.jsx` (card-default + RepositoryFileIcon + dropdown na `create`) |
| Table | `resources/js/Components/Tables/RepositoryFilesTable.jsx` | **vytvořit** | `HostingsTable.jsx` (sloupce name/file_type badge/size_human/created_at_human/repository; akce download+delete reuse stávajících routes) |
| Test | `tests/Feature/RepositoryFiles/RepositoryFileIndexTest.php` | **vytvořit** | `HostingIndexTest.php` (200+component+data, search, sort, auth redirect, 403 bez `view_any_repositoryfile`) |

### B3 — (K) backups doc #10 sjednotit
Po B2 upravit `docs/backups-improvements.md` #10, aby odrážel **web** RepositoryFiles index (ne smazaný `Api\V1\RepositoryFileController`) — popsat reálné deliverables (controller `index` + `Index.jsx` + GET route + test).

### B4 — (K) MCP `#[Instructions]` kosmetika
`app/Mcp/Servers/NotifierServer.php` — `#[Instructions]` over-claimuje „agent heartbeats" (žádné takové primitivum neexistuje) a v doménovém seznamu chybí prefixy `clients.` / `activity.`. Sladit s reálnou plochou (25 toolů / 8 resources / 1 prompt).

### B5 — (K) PHPStan baseline regenerace — **rozhodnuto: regenerovat teď**
Baseline (`phpstan-baseline.neon`, 6943 ř.) odkazuje staré ploché `app/Mcp/Resources/*.php` / Tools cesty → reorg je přesunul → `analyse` abortuje („Invalid entries in ignoreErrors"). **Provést až POSLEDNÍ** (po B1–B4, ať baseline pokryje nové soubory):
- `vendor/bin/phpstan analyse --generate-baseline`
- ⚠️ **Env-caveat:** tento sandbox vykazuje verzní drift (Pest `actingAs` → ~661 falešných položek). Regenerovaný baseline odráží TOTO prostředí; **musíš ho ověřit / přegenerovat ve svém CI prostředí**, jinak může v CI znovu nesednout (`reportUnmatchedIgnoredErrors`). Nahlásím přesně, kolik položek baseline má a jaké reálné notice zbývají (očekávané 3 `TrashedScope` larastan false-positive — runtime OK).

### B6 — (dokumentace, NE re-enable) Dormantní schedulery
`routes/console.php` má zakomentované `CheckStaleAgentHeartbeatsCommand` (:107) a `SyncPohodaDataCommand` (:66). **Nezapínat naslepo** — jsou blokované externími problémy (heartbeat server rozbitý dle paměti; mPohoda 401). Pouze zdokumentovat jako blocked v plánu/TODO; re-enable je samostatná úloha po vyřešení blockerů.

### B-OPS — (O) Operační (děláš ty)
- 🔴 **Rotovat 3 P0 secrety u zdroje IHNED:** nový SSH keypair + strip starého pubkey z `authorized_keys` fleet-wide (`privatekey.txt`); změnit hesla `tomludwig`/`tech1` na `178.22.117.90`; invalidovat GCP service-account klíč (`storage/credentials.json`) + audit GCP logů. Až poté přepsat git historii (`git filter-repo`/BFG) + force-push.
- 🔴 **Nasadit main do produkce kontrolovaně:** ověřit `SSH_BRIDGE_SECRET` + `HEARTBEAT_*` env PŘED restartem `server.js`, pak `php artisan migrate` (encrypted `login_password` sloupec na migraci závisí — jinak čtení legacy řádků hodí výjimku). Dokud se nenasadí, in-code bezpečnostní opravy nejsou live.

---

## Sekvence exekuce (návrh)

1. **(po schválení)** uložit plán do repo docs.
2. **notifier-agent A1–A7** (rychlé, izolované) → `pint --test` + `phpstan` + `pest`. *(A8 workflow edit přidat tamtéž.)*
3. **devuni B1 SSH bridge** (PHP + Node + FE + testy) → `pint --dirty` + `pest tests/Feature/Security tests/Feature/RepositoryServer`.
4. **devuni B2 RepositoryFiles index** → `pest tests/Feature/RepositoryFiles` + `npm run build`.
5. **devuni B3/B4/B6** (doc + MCP instructions + dormant-doc).
6. **devuni B5 phpstan baseline regen** (poslední) → `phpstan analyse` čisté.
7. Předat ti **operační checklist** (A-OPS + B-OPS).

## Verifikace

- **notifier-agent:** `vendor/bin/pint --test` čistý; `vendor/bin/phpstan analyse` 0 chyb (L5); `vendor/bin/pest --compact` zelené (≥342 passed).
- **devuni B1 (PHP):** `php artisan test --compact tests/Feature/Security tests/Feature/RepositoryServer tests/Feature/Mcp` zelené; nové testy dokazují, že token bez/​s pozměněným kontextem neprojde. ⚠️ **Node `server.js` + xterm frontend nelze spustit/otestovat zde** — vyžaduje manuální ověření u tebe (spustit bridge, otevřít VPS konzoli, ověřit připojení na správný cíl + odmítnutí spoofnutého host).
- **devuni B2:** `php artisan test --compact tests/Feature/RepositoryFiles` zelené (5 testů); `npm run build` projde; ruční smoke index stránky.
- **devuni B5:** `vendor/bin/phpstan analyse` doběhne bez abortu; nahlásím počet baseline položek + zbylé reálné notice.
- **Celé devuni:** `vendor/bin/pint --dirty` čistý; dotčené testovací adresáře zelené. (Plnou suite spustím cíleně na dotčené domény; nikoli celých 800+ pokud nebude třeba.)

## Co potřebuje TEBE (operační checklist)

| # | Akce | Repo |
|---|---|---|
| O1 | Protected-tag ruleset `v*` + required reviewers na `release` env + omezit `workflow_dispatch` | notifier-agent (GitHub UI) |
| O2 | Omezit `code-style-fix.yml` na same-repo PR | notifier-agent |
| O3 | Ověřit `RELEASE_PAT` secret | notifier-agent |
| O4 | Rotace 3 P0 secretů (SSH key + 2 hesla + GCP key) + přepis git historie | notifier-devuni-cz |
| O5 | Prod deploy: `SSH_BRIDGE_SECRET`/`HEARTBEAT_*` env → restart `server.js` → `php artisan migrate` | notifier-devuni-cz |
| O6 | Manuální ověření SSH bridge po B1 (správný cíl OK, spoof odmítnut) | notifier-devuni-cz |
| O7 | Všechny git commit/push/tag/release | oba |

## Vědomě mimo rozsah (zatím)
- Third-party integrations (`docs/third-party-integrations-plan.md`) — 0 % postaveno, samostatný projekt.
- AI-fix feature — 0 % postaveno, samostatný projekt.
- Re-enable dormantních schedulerů (heartbeat stale-alert, Pohoda) — blokováno externími problémy.
- Zvednutí PHPStan nad L5 (agent) / Rector v CI — nice-to-have.
