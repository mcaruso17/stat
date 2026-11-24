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

## Classifica dei luoghi proposti

Ogni collega dà da 1 a 5 stelle ai luoghi proposti per un evento. La classifica usa una media bayesiana:

```
punteggio = (m · C + somma dei voti) / (C + numero di voti)
```

con `m = 3` (voto neutro) e `C = 10`: ogni luogo parte come se avesse 10 voti da 3 stelle. Un luogo sale davvero solo se lo votano in tanti: 4 voti da 4 stelle (3,3 punti) battono 1 voto da 5 (3,2 punti). I luoghi senza voti vanno in fondo. Si regola con `RATING_PRIOR_MEAN` e `RATING_PRIOR_WEIGHT` in `inc/bootstrap.php`: alzando `C` il numero di votanti pesa ancora di più.

Chi ha proposto un luogo può modificarlo. Se cambia solo nota o link, i voti restano; se cambia il nome del posto, i voti degli altri si azzerano.

## Metro, compromesso e zone suggerite

Ogni location proposta viene localizzata con OpenStreetMap (Photon, con Nominatim come riserva; nessuna chiave API), partendo dal link Google Maps se contiene le coordinate, poi dall'indirizzo, poi dal nome. Ogni membro può indicare la propria zona (quartiere o comune) nel profilo: la posizione viene salvata arrotondata a circa 100 m.

- **Metro più vicina**: dalle 74 stazioni di A, B, B1 e C in `inc/metro_roma.php` (coordinate OpenStreetMap, verificate sulla distanza tra stazioni consecutive).
- **Tempo di viaggio stimato** da ogni zona: il migliore tra a piedi, metro (accesso, attesa, eventuale cambio) e bus/auto. Distanze in linea d'aria con fattore di deviazione. Non considera tram, treni regionali e orari: serve a confrontare, non a navigare.
- **Compromesso**: per ogni location, media e massimo dei tempi del gruppo; costo = ½ media + ½ massimo. Vince il costo più basso. Il gruppo è chi ha detto "Partecipo" e ha la zona; se sono meno di due, tutti i membri con la zona.
- **Zone suggerite**: le tre stazioni con il costo più basso, distanti almeno 1,2 km tra loro, con link alla ricerca di locali su Google Maps.

Parametri in `inc/geo.php`. Il server deve poter fare richieste HTTPS in uscita verso `photon.komoot.io` (e `nominatim.openstreetmap.org`): su Plesk di norma è già così; se il geocoding non funziona, controllare firewall e `allow_url_fopen`/estensione cURL.

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

## Sessioni

Si resta collegati 30 giorni (scadenza rinnovata a ogni visita). Le sessioni sono salvate in `data/sessions`, non nella cartella di sistema: la pulizia automatica di PHP/Plesk (24 minuti di inattività di default) altrimenti scollegava gli utenti a metà di un voto. Se la sessione scade comunque, il sito porta alla pagina di accesso (non al quiz) e, dopo l'accesso, riporta alla pagina di prima.

## Backup

Basta copiare la cartella `data/` (database + uploads).

## Sviluppo locale

```bash
php -S 127.0.0.1:8000
```
