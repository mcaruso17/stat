# codice Stat

Sito riservato ai colleghi statistici della Ragioneria: eventi, forum di discussione (stile Reddit), materiali condivisi, elenco membri.

PHP 8.1+ e SQLite, nessuna dipendenza e nessun build step.

## Come funziona l'accesso

1. `gate.php`: tre domande, servono almeno 2 risposte giuste su 3.
2. `auth.php`: registrazione con nome e password (min. 4 caratteri). Chi ha già un account accede direttamente, senza rifare il quiz.

Domande e risposte stanno in `inc/bootstrap.php` (costante `QUIZ`).

## Amministratore

L'account admin (`carusowski`) viene creato automaticamente alla prima visita, quando il database viene inizializzato. Nel codice è salvato solo l'hash bcrypt della password (`ADMIN_PASSWORD_HASH` in `inc/bootstrap.php`), non la password in chiaro.

L'admin può:
- eliminare qualsiasi evento, topic, commento o documento;
- da `admin.php`: reimpostare la password di un utente, nominare o rimuovere altri admin, eliminare un account con tutti i suoi contenuti.

Per cambiare la password admin basta usare "Reimposta" sulla propria riga in `admin.php`. L'hash nel codice serve solo alla prima creazione dell'account.

## Struttura

| File | Ruolo |
|---|---|
| `index.php` | Home con le sezioni Eventi, Discussione, Materiali, Membri |
| `topic.php` | Singolo topic con commenti annidati e voti |
| `action.php` | Tutte le azioni POST (eventi, topic, commenti, voti, upload) |
| `admin.php` | Gestione utenti, solo admin |
| `download.php` | Download dei documenti, solo per utenti loggati |
| `inc/` | Configurazione, database, layout (accesso web negato) |
| `data/` | Database SQLite e file caricati (accesso web negato) |

Il database si crea da solo alla prima visita, con l'evento del 2 dicembre già inserito.

## Deploy su Plesk

1. **Siti web e domini > PHP**: versione 8.1 o superiore, con estensioni `pdo_sqlite` e `mbstring` attive (di solito lo sono).
2. Carica i file nella document root (`httpdocs`), via Git (Plesk > Git, puntando a questa repo) o via FTP/File Manager.
3. Rendi scrivibile la cartella `data/` dall'utente PHP (permessi 775 sul proprietario del sito).
4. Nelle impostazioni PHP di Plesk porta `upload_max_filesize` a 20M e `post_max_size` a 22M.
5. Attiva HTTPS con Let's Encrypt (Plesk > SSL/TLS).
6. Verifica che `https://tuodominio/data/stat.sqlite` e `https://tuodominio/inc/bootstrap.php` restituiscano 403. Se il sito gira solo su nginx (senza Apache) i file `.htaccess` vengono ignorati: aggiungi in **Impostazioni Apache e nginx > Direttive nginx aggiuntive**:

   ```nginx
   location ~ ^/(data|inc)/ { deny all; }
   ```

## Backup

Basta copiare la cartella `data/` (database + uploads).

## Sviluppo locale

```bash
php -S 127.0.0.1:8000
```
