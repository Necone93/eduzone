# EduZone

Izvorni kod obrazovnog sajta. Ovaj paket je pripremljen za novi GitHub repozitorijum; nije rezervna kopija produkcionog sajta.

## Postavljanje na GitHub

Raspakuj ZIP i dodaj njegov sadržaj u koren praznog repozitorijuma. Uključi `.gitignore`, `.htaccess` i `content/lesson-html/.htaccess` (skriveni fajlovi). Nemoj dodavati sam ZIP niti `.git` iz starog projekta.

## Šta paket sadrži

PHP stranice, CSS, JavaScript, javne slike i fontove, sadržaj Digitalne učionice, pomoćne skripte i testove. Primer konekcije je u `db_connect.example.php`.

## Šta je izostavljeno

- Stvarni `db_connect.php`, SQL izvozi i migracije sa podacima.
- Učenički radovi, otpremljeni dokumenti i ostali fajlovi iz `uploads/`.
- Generisane konverzije lekcija iz `content/lesson-html/`.
- Git istorija, ZIP kopije, logovi, lokalno izvršno okruženje i podešavanja editora/SFTP-a.
- Fajlovi za potvrdu vlasništva domena i pomoćni `check.php`.

Administratorska adresa je u ovoj kopiji zamenjena sa `admin@example.invalid`, a privatna putanja hosting naloga primerom. Razvojna prijava `dev_login` je uklonjena iz ove kopije. Radni projekat i hosting nisu menjani.

## Pokretanje

Potreban je PHP 8+ sa mysqli ekstenzijom, MySQL/MariaDB i Apache sa mod_rewrite i podrškom za `.htaccess`. PDF konverzija ima dodatne zavisnosti opisane u `scripts/README-pdf.md`.

Baza i njena početna šema nisu deo ovog paketa: aplikacija očekuje postojeću bazu. Kopiraj `db_connect.example.php` u `db_connect.php` samo na računaru ili serveru. Postavi `EDUZONE_DB_HOST`, `EDUZONE_DB_USER`, `EDUZONE_DB_PASSWORD` i `EDUZONE_DB_NAME` u serverskom okruženju ili popuni privatni `db_connect.php`. Nikad ne unosi stvarne pristupne podatke u primer ili u Git.

Pre korišćenja na svom serveru zameni `admin@example.invalid` svojom administratorskom adresom na svim mestima gde se koristi. Podesi domen škole u `login.php`, adresu pošiljaoca i slanje mejla na serveru. Sačuvaj postojeću privatnu konfiguraciju i učeničke fajlove kada ažuriraš hosting. Ovaj paket ne treba da ih zameni ili obriše.

## Provere

```sh
php tests/test_visibility.php
php tests/test_result_mail.php
```

Test mejla koristi simulirano slanje i ne šalje stvarne poruke. Provera paketa radi uklanjanja osetljivih podataka nije potpuna bezbednosna revizija aplikacije.
