# Libro de Reclamaciones test results

Run: 2026-11-24T21:22:27.010Z - PHP 8.1 + Apache (docker-compose.libro-test.yml)

43/43 checks passed. Mail was never really delivered (dry-run or unreachable SMTP).

| Result | Group | Check |
|---|---|---|
| PASS | ok | form page served (honeypot + reclamaciones.js) |
| PASS | ok | /api/config.php is not public (403) |
| PASS | ok | /api/storage/counter.dat is not public (403) |
| PASS | ok | /api/lib/ is not public (403) |
| PASS | ok | /api/src/Notifier.php is not public (403) |
| PASS | ok | valid claim -> 200 ok + LR number |
| PASS | ok | sheet archived (PDF starts with %PDF, JSON has the data) |
| PASS | ok | delivery state recorded: company+consumer = dry_run |
| PASS | ok | both .eml written (empresa + consumidor), no pending marker |
| PASS | ok | token is single-use (replay rejected 403) |
| PASS | ok | invalid fields -> 422 with field errors |
| PASS | ok | honeypot -> fake 200 (LR-0000-000000), nothing archived |
| PASS | ok | no Origin/Referer -> 403 |
| PASS | ok | wrong token -> 403 |
| PASS | ok | GET reclamo.php -> 405 |
| PASS | ok | counter.dat deleted -> rebuilt from archive, next = last+1 (no reuse) |
| PASS | ok | corrupt counter.dat -> recovered, next = last+2 |
| PASS | ok | stale counter (behind the archive) -> never below the archive, next = last+3 |
| PASS | ok | archive not writable -> 500, claim NOT confirmed |
| PASS | ok | archive failure: nothing archived, no e-mail written |
| PASS | ok | archive failure logged as "NOT archived" |
| PASS | ok | same token works again once the archive is writable (token kept) |
| PASS | ok | kill switch (storage/.disabled): token.php 503 |
| PASS | ok | kill switch: reclamo.php 503 with a message that points to the mailbox |
| PASS | ok | kill switch removed -> claims accepted again |
| PASS | strict | submitted before min fill time (6 s) -> 403 |
| PASS | strict | after 6.5 s -> 200 |
| PASS | strict | second claim from the same IP within 30 min -> 429 (rate limit) |
| PASS | strict | 429 message points to the mailbox and no new sheet is archived |
| PASS | mailfail | claim is registered (200 ok) even though e-mail fails |
| PASS | mailfail | response tells the consumer to keep the number (sent=false) |
| PASS | mailfail | company + consumer state = failed |
| PASS | mailfail | pending marker written with sheet path |
| PASS | mailfail | PENDING line in claims.log, PDF archived |
| PASS | mailfail | no .eml files (nothing was delivered) |
| PASS | mailfail | storage/pendientes is not public (403) |
| PASS | nosmtp | claim is registered (200 ok) even though e-mail fails |
| PASS | nosmtp | response tells the consumer to keep the number (sent=false) |
| PASS | nosmtp | company + consumer state = skipped |
| PASS | nosmtp | pending marker written with sheet path |
| PASS | nosmtp | PENDING line in claims.log, PDF archived |
| PASS | nosmtp | no .eml files (nothing was delivered) |
| PASS | nosmtp | storage/pendientes is not public (403) |
