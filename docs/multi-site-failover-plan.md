# Multi-site failover: Tršice ↔ Prostějov

> Vytvořeno 2026-07-22 brainstormingem, **revidováno téhož dne** po nezávislém ověření technických
> tvrzení proti oficiální dokumentaci. **Nic z toho není implementováno.**
>
> ⚠️ První verze dokumentu obsahovala několik věcných chyb (zejm. tvrzení, že Cloudflare Load
> Balancing vyžaduje proxied režim — **nevyžaduje**). Opravená tvrzení jsou níže označena.

## Cíl

Failover mezi dvěma bare-metal lokalitami (Tršice, Prostějov) s výhledem na třetí uzel. Obě mají
XCP-ng, HAProxy a MikroTik. Weby jsou v Cloudflare vedené jako **DNS-only**.

## Zadaná rozhodnutí (2026-07-22)

| Otázka | Rozhodnutí |
|---|---|
| RPO | **Liší se web od webu** → třídy A/B/C |
| Cloudflare | **Zůstává DNS-only** |
| Trigger | **Plně automaticky**, řídí `notifier.devuni.cz` (mimo obě lokality) |
| Rozsah | **Nejdřív pilot na jednom webu** |

## Ověřený výchozí stav

`dig @1.1.1.1`, 2026-07-22 — pozor, `dig` vrací **zbývající odpočet** resolveru, ne autoritativní
hodnotu; 300 s odpovídá Cloudflare „Auto" pro nepproxovaný záznam:

| doména | TTL | IP |
|---|---|---|
| notifier.devuni.cz | 300 | 185.242.20.246 |
| cyklodresy.cz / ridesnap.cz / devuni.cz | 300 | 176.97.241.236 (sdílená) |

---

## 0. 🔴 Rozhodnutí, které musí padnout první: koupit, nebo stavět?

**OPRAVA původního tvrzení.** Cloudflare Load Balancing **funguje i v DNS-only režimu**. Oficiálně:
„Cloudflare performs DNS-only load balancing when traffic to your hostname is not proxied" — LB
přidává či odebírá endpoint z DNS odpovědi podle health checku. Cena od **$5/měs**.

Původní verze dokumentu tvrdila opak a na tom stavěla celý plán vlastního witnesse s přepisem DNS
přes API. To je nutné přehodnotit.

Dokumentované limity DNS-only LB (všechny se tě týkají): neskrývá IP endpointů, pomalejší a méně
přesný failover (závisí na resolverech), bez session affinity, zvyšuje počet autoritativních dotazů.

**Podstatné je ale tohle:** Cloudflare LB umí **přepínat**, ale neumí **fencing**. Nemá jak zajistit,
že se starý uzel přestane sám považovat za aktivní. A dva regulátory zapisující do jednoho A záznamu
(CF LB i tvůj witness) by si šly po krku.

Reálné jsou proto dvě varianty:

| varianta | kdo přepíná DNS | kdo dělá fencing | poznámka |
|---|---|---|---|
| **Koupit** | Cloudflare LB | notifier (jen role/lease) | méně vlastního kódu, $5/měs/zóna |
| **Stavět** | witness přes CF API | witness | plná kontrola, víc kódu a rizika |

⚠️ **Dokument tuhle volbu zatím neučinil.** Ať dopadne jakkoliv, musí být zapsaná i s důvodem.

Stejně tak není zapsáno, **proč byl odmítnut proxied režim** — přitom proxied řeší ten hlavní
problém ze sekce 2 (Cloudflare by přestal posílat na starý origin). Rozhodnutí platí, ale důvod patří
do dokumentu.

---

## 1. Zálohy nejsou failover

Použít `notifier:database-restore` jako cestu k přepnutí je koncepčně špatně: RPO = interval záloh,
RTO = stažení a import dumpu.

Replikace a zálohy řeší různé problémy: replikace chrání před výpadkem železa, ale `DROP TABLE`
poslušně zreplikuje; zálohy chrání před chybou člověka, ale jsou staré. **Potřebuješ obojí.**

Restore příkazy se staví pro: bootstrap nového uzlu, obnovu po katastrofě, refresh stagingu,
failover třídy C.

### Bezpečnostní požadavky

- odmítnout běh v produkci bez explicitního flagu
- potvrzení opsáním názvu databáze
- automatický snapshot **před** přepsáním
- dry-run režim
- 🔴 **pravidelný automatický test restore do dočasné DB** — bez něj je RPO ve sloupci tabulky
  jen zbožné přání

---

## 2. Fencing — jádro celého návrhu

Po přepsání A záznamu **starý server dál dostává provoz**. Pokud zapisují oba uzly, vzniknou
rozejité databáze.

> ⚠️ **Neuvádět konkrétní čísla jako bezpečnou hranici.** DNS odvede většinu provozu za ~2–5 min
> (TTL 60), ale **nedává žádnou horní mez**. Správnost musí plynout výhradně z fence, nikdy
> z čekání na vypršení TTL.

### Co TTL neřídí vůbec

- **Existující TCP/TLS spojení** — HTTP keep-alive, HTTP/2, HTTP/3, WebSocket se váží na
  vyresolvovanou IP a přežijí změnu DNS neomezeně.
- **Prohlížeče** — Chromium cachuje hostname ~60 s *nad rámec* TTL záznamu, nevnořeně.
- 🔴 **Provoz, který DNS vůbec nepoužívá** — callbacky platebních bran (GoPay IPN!), partnerské
  webhooky, integrace s IP allowlistem. Ty míří na starou IP **donekonečna**.

### Lease, ne polling

**OPRAVA.** Původní návrh („každých 5 s se zeptej, jestli jsem aktivní") je nedostatečný. Správně
je **lease s epochou**:

```
witness uděluje lease(epoch, ttl = 10 s)
uzel obnovuje každé 2 s proti MONOTONNÍMU času
při vypršení: fail closed bezpodmínečně — žádná cache nesmí lease prodloužit
standby smí promovat až po uplynutí ttl + clock_skew + max_delay
DNS se přepisuje AŽ PO promoci
```

Tu nerovnost je potřeba spočítat a zapsat do dokumentu číselně.

- **Fencing token / epocha** — každý zápis musí nést generaci, která ho vytvořila. Bez toho se
  zápis „za letu" od starého primárního tiše aplikuje po promoci.
- **Default-deny při startu** — uzel po restartu nebo obnovení ze snapshotu startuje jako
  **standby**, nikdy neobnovuje poslední známou roli.

### Externí fence — máš na něj hardware a nevyužíváš ho

Self-fencing vyžaduje **spolupráci nemocného uzlu**. Ta se nedá předpokládat. Máš přitom k dispozici
skutečný STONITH:

- **XCP-ng / XenAPI** — witness vypne nebo pozastaví VM
- **MikroTik API** — zahodit 80/443 směrem na ten host

Rozvrstvení: **self-fence = rychlá cesta, externí fence = to, co dělá promoci bezpečnou.** Promovat
se smí až po potvrzeném fence.

### Jeden witness nestačí

**OPRAVA.** Jeden externí witness není dostatečný arbitr. Potřebuješ **3 hlasy ve 3 doménách
selhání** (witness + Tršice + Prostějov) se skutečným kvórem, nebo aspoň druhý witness na nesouvisejícím
hostingu.

Oddělit dvě role, které se v původním textu mísily:

- **kvórum rozhoduje, KDO smí zapisovat** (otázka správnosti)
- **peer-check + hystereze + cooldown rozhodují, KDY se vyplatí přepnout** (otázka rozumnosti)

Failback vždy ručně.

### Co fence musí pokrýt

Maintenance mode je **kosmetika pro návštěvníky**, ne fence. Skutečné zastavení zápisů:

- 🔴 **`SET GLOBAL super_read_only = ON`** — samotné `read_only` nezastaví SUPER účty
- zastavit cron/systemd timer a worker unity **na úrovni OS**
- `APP_MAINTENANCE_DRIVER=cache` se sdíleným úložištěm **zakázat**
- pozor na běžící joby, artisan mimo scheduler, webhooky s maintenance bypass

---

## 3. TTL

Snížit z 300 na **60 s, a to předem**.

**Přesná formulace (oprava):** TTL je součástí odpovědi uložené v cache resolveru. Resolver, který
si záznam stáhl s TTL 300, se o nové nižší hodnotě dozví až po vypršení té staré. Snížení se tedy
projeví nejdřív za jeden plný starý interval — proto předem, ne při incidentu.

⚠️ **60 s je tvrdé minimum** na Free/Pro/Business; 30 s vyžaduje Enterprise. Žádná další rezerva
neexistuje.

---

## 4. Vrstvení podle RPO

| třída | weby | mechanismus | RPO |
|---|---|---|---|
| **A** | eshopy, platby | MariaDB replikace + sdílený Garage bucket | sekundy **za normálu, neomezeně při zpoždění replikace** |
| **B** | aplikace s uživ. daty | totéž (viz níže) | minuty |
| **C** | prezentace | `notifier:*-restore` | hodiny (full dump) → minuty s archivací binlogů (PITR) |

🔴 **Pro platby platí, že async replikace může ztratit potvrzenou objednávku.** Buď to písemně
akceptovat, nebo pro tuhle doménu řešit jinak (idempotentní přehrání z brány, semi-sync).

Třídy A a B by měly používat **stejný mechanismus** (sdílený bucket). Pokud ne, zapsat proč.
Pro storage bez Garage platí: **jednosměrný** rsync/lsyncd ve směru odvozeném z role, nikdy
obousměrná synchronizace. **Syncthing je vyloučený** — konflikt řeší přejmenováním na
`.sync-conflict-*`, čímž rozbije cestu uloženou v DB.

### Co k replikaci patří a chybělo

- **Monitoring replikace** — `Slave_IO_Running`, `Slave_SQL_Running`, `Seconds_Behind_Master`.
  Tiše rozbitá replikace je nejčastější příčina toho, že failover nefunguje. Alerting na lag je
  **součást řešení, ne doplněk**.
- **`super_read_only=ON` na standby** — v původním návrhu úplně chybělo.
- **GTID zapnout od začátku** a `binlog_format=ROW` — dodatečné zavádění je samostatný projekt.
- **Seed** — `mariadb-backup --stream` přes tunel, ne `mysqldump`. Retence binlogů musí být delší
  než nejdelší předpokládaný výpadek linky, jinak čeká full re-seed.
- **Chování při výpadku linky** — `PersistentKeepalive=25` na obou WG peerech; explicitně nastavit
  `MASTER_CONNECT_RETRY` (u MySQL 8.4 je default jen 10 pokusů = 10 min, pak replikace **natvrdo
  stojí**).
- 🔴 **auto_increment kolize** — pokud během překryvu zapisují oba uzly, kolidují ID.
- 🔴 **Po havarijním failoveru zachovat binlogy starého primárního** — jsou jediným záznamem
  transakcí, které se necommitovaly na repliku. To je konkrétní obsah RPO mezery.
- **NTP a monotonní čas** na witnessu i obou uzlech, se stanoveným maximálním skew.

---

## 5. Garage

### Poučení z RideSnapu — oprava

Původní verze tvrdila, že chyba je v rozmístění 2+1. **To je špatně.** RideSnap **má tři zóny
správně rozmístěné a přesto 2026-07-16 přišel o kvórum**, protože oba vzdálené uzly odešly společně
při výpadku sdíleného WireGuard hubu / MikroTiku.

🔴 **Rozmístění do zón nechrání před common-mode selháním transportu.** Třetí uzel u witnesse nesmí
viset na stejném WG hubu a stejném MikroTiku jako obě lokality, jinak je zónová redundance kosmetická.

To je vážné pro celý tenhle návrh, protože **stojí na mezilokalitním WireGuard tunelu přes MikroTik**
— a ten je podle auditu WG segmentace neověřený a označený jako kritický.

### Fakta o kvóru

3 uzly / RF3 tolerují **ztrátu jednoho uzlu** při zachování čtení i zápisu. Při ztrátě dvou jsou
data v bezpečí (každý uzel má plnou kopii), ale S3 API vrací chyby, dokud se kvórum neobnoví.

### Co sdílený bucket neřeší

- **Endpoint failover** — každý app uzel potřebuje buď lokální Garage gateway, nebo záložní S3
  endpoint. Samotný sdílený bucket to neřeší.
- 🔴 **Zálohy bucketu** — Garage **nemá verzování objektů**, takže smazání je okamžitě globální
  a nevratné. Sekce 1 správně říká, že replikace není záloha — a pak by to sekce 5 tiše porušila.
- **Chování aplikace při ztrátě kvóra** — u RideSnapu to skončilo HTTP 500, protože
  `Storage::exists()` hodí `UnableToCheckFileExistence`. Ošetřit.
- **Monitoring** — Garage má `:3903/health` (503 = „Quorum is not available"). Runbook RideSnapu
  výslovně zaznamenává, že Docker healthcheck hlásil „healthy" **po celých 11 hodin výpadku**.

### S3 kompatibilita — omezení

Garage **nemá S3 ACL ani bucket policies**. Laravel disky konfigurovat **bez `visibility`/ACL —
`setVisibility()` selže a `public-read` je tiše ignorováno.** Veřejné URL přes `web`/`root_domain`
nebo presigned. Není verzování, tagging, object-lock ani bucket notifications.

### Latence

Při jednom uzlu na zónu vyžaduje **každé čtení metadata kvórum 2** → nejméně jeden WAN round trip
na objekt, každý zápis potřebuje vzdálené potvrzení. **Změřit RTT Tršice↔Prostějov, a znovu pod
zátěží DB replikace**, než na Garage půjdou obrázky eshopu.

⚠️ Garage řadí verze objektu podle `timestamp` — tedy podle **wall-clock**. Další důvod pro NTP.

---

## 6. Co přidat do notifier-agentu

**OPRAVA rozsahu.** Původní text tvrdil, že role „navazuje na existující heartbeat kanál". To je
zavádějící — jde o **podstatnou novou práci** na agentovi i serveru, koordinovanou napříč všemi
konzumenty:

- 🔴 **Identita uzlu dnes vůbec neexistuje** — oba uzly by se hlásily stejným repository id a stejným
  tokenem. Každý mechanismus role ji přitom předpokládá. Přidat `NOTIFIER_NODE_ID` (nebo
  `gethostname()`) do manifestu.
- **Nový autentizovaný endpoint** pro roli/lease vracející `{role, epoch, ttl}` — s **obrácenou
  sémantikou selhání** (nedostupný ⇒ standby) a vlastním krátkým TTL.
- **Neopakovat použití** `AnnouncementsService` ani jeho cache klíčů.
- **Verzovat drátový kontrakt** — server musí staré agenty přijímat.
- Heartbeat zůstává hodinovou inventurní telemetrií; **fencing poll je samostatný rezidentní démon**.

### Gating

```php
Notifier::isActive()  // fail-safe, bez síťového I/O, čte lokální token psaný démonem
```

- **Primární fence je o vrstvu níž:** na standby **vůbec neinstalovat** `schedule:run` cron
  a neposkakovat Horizon unit. `->when()` je až druhý pás.
- Closure **nesmí nikdy vyhodit výjimku** ani dělat síťové volání (běží uvnitř `schedule:run`).
- **`schedule:pause`** (Laravel 13) je nejlevnější globální vypínač scheduleru — v původním návrhu
  chyběl. Pozor: `composer.json` dovoluje i `^12.55`, kde neexistuje.
- **`onOneServer()`** je platná pojistka **jen při jednom sdíleném cache store pro obě lokality** —
  což je táž otázka jako sessions/cache níže. Sdílená cache přes WG je sama o sobě mezilokalitní
  závislost: při výpadku linky nezíská zámek **nikdo**.
- **`withoutOverlapping()` není failover fence** — je to re-entrance guard na jednom uzlu.
- **Cizí naplánované úlohy** (`horizon:snapshot`, `telescope:prune`, `pulse:*`, `model:prune`,
  `queue:prune-batches`, `sanctum:prune-expired`) per-task `->when()` nepokryje.
- **Nikdy negatovat** heartbeat, obnovu lease, zálohy standby a monitoring — jen business úlohy
  s vedlejšími účinky.
- 🔴 `NOTIFIER_QUEUE_CONNECTION` má default **`sync`** — na takové instalaci gating workerů nefencuje
  nic, protože `NotifierSendBackupController` běží inline v HTTP requestu.
- README dnes u heartbeatu radí `->onOneServer()`; se sdílenou cache to znamená, že **liveness hlásí
  jen jeden uzel** — tiše to zabíjí per-node monitoring.

---

## 7. Topologie: rozdělit weby po lokalitách

Část webů aktivní v Tršicích se standby v Prostějově, část naopak. Výpadek přesune jen polovinu
portfolia, obě železa pracují a failover je průběžně testovaný provozem.

---

## 8. Role HAProxy a MikroTiku

- **HAProxy** je lokální L7 balancer uvnitř lokality; mezilokalitní failover neřeší.
- **MikroTik/VRRP** funguje jen ve společné L2; přes WG tunel nedává smysl. MikroTik ale má roli
  jako **fabric fence** (viz sekce 2).

Rozhodnutí „která lokalita jede" se odehrává na úrovni Cloudflare.

---

## 9. Pilot

Ne na `notifier-devuni-cz` (orchestrátor → kruhová závislost). Vzít klidný web **třídy C**.

Řetěz: sledování zdraví → kvórum → **externí fence** → promoce po uplynutí bezpečnostní nerovnosti →
přepis DNS → obnova dat → gating → **ruční failback**.

### 🔴 Adversariální testy — bez nich fence neexistuje

ClusterLabs bere neotestovaný fencing jako žádný fencing. Otestovat:

- zabít witness
- **přeříznout mezilokalitní linku** (nejdůležitější test)
- zabít samotný fence agent
- restart uzlu uprostřed failoveru (ověřit default-deny)
- ztráta kvóra Garage
- ověřit, že callback platební brány na starou IP nic nezapíše

---

## 10. Otevřené otázky

1. **Linka Tršice↔Prostějov** — WG přes MikroTik, nebo L2? Změřit RTT a propustnost, i pod zátěží.
   `UNKNOWN` z ověření: zda má linka kapacitu na replikaci i na počáteční seed.
2. **Velikost největšího eshopu** → délka seedu.
3. **TLS certifikáty** na obou uzlech — DNS-01 wildcard, nebo synchronizace?
4. **Cloudflare API** — token omezený na potřebné zóny; ošetřit rate limiting a selhání přepisu
   (sekce 9 krok 4 je dnes single point of failure).
5. Obě origin IP jsou v DNS-only veřejné → standby musí být zabezpečený stejně jako aktivní.

## 11. Blokery — ne otevřené otázky

- 🔴 **Sessions a cache.** V původní verzi to byla otevřená otázka; ve skutečnosti je to **blokující
  rozhodnutí** pro třídy A/B. Souborové sessions v `storage/framework/sessions` podléhají témuž
  problému jako storage; lokální Redis znamená při překryvu dvě rozejité session úložiště navíc
  k databázím. A je to táž volba jako předpoklad pro `onOneServer()`.
- 🔴 **Volba koupit/stavět** ze sekce 0.

## 12. Co dokument vědomě neřeší

- konkrétní konfigurace MariaDB (topologie, semi-sync)
- kapacitní plánování — unese jedna lokalita provoz obou?
- migrace stávajícího storage do Garage a cena rebalance přes WAN
- cílová verze Garage a postup upgradu (RideSnap běží 1.1.0, aktuální dokumentace 2.2.0; při 3 uzlech
  a RF3 není při rolling upgrade žádná rezerva)
- třetí lokalita
