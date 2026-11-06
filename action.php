<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
$me = require_login();
csrf_check();
$uid = (int)$me['id'];
$pdo = db();
$id = (int)($_POST['id'] ?? 0);

/** Accetta solo redirect interni relativi (niente open redirect). */
function back(string $default): string
{
    $b = (string)($_POST['back'] ?? '');
    return preg_match('~^(index|topic)\.php[?#a-zA-Z0-9=&%_\-+.]*$~', $b) ? $b : $default;
}

/** L'utente è autore del contenuto, oppure è admin. */
function owned(PDO $pdo, string $table, int $id, int $uid): bool
{
    $st = $pdo->prepare("SELECT user_id FROM $table WHERE id = ?");
    $st->execute([$id]);
    $owner = $st->fetchColumn();
    return $owner !== false && can_manage($owner);
}

function require_admin(): void
{
    if (!is_admin()) {
        http_response_code(403);
        exit('Solo per amministratori.');
    }
}

switch ($_POST['action'] ?? '') {
    case 'create_event':
        $title = post_str('title', 140);
        $date = post_str('event_date', 10);
        $time = post_str('event_time', 5);
        if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            flash('Titolo e data sono obbligatori.', 'err');
            redirect('index.php#eventi');
        }
        if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) $time = '';
        $pdo->prepare('INSERT INTO events (title, description, event_date, event_time, location, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$title, post_str('description', 3000), $date, $time, post_str('location', 140), $uid, time()]);
        $eid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO event_rsvp (event_id, user_id) VALUES (?, ?)')->execute([$eid, $uid]);
        flash('Evento pubblicato.');
        redirect('index.php#eventi');

    case 'delete_event':
        if (owned($pdo, 'events', $id, $uid)) {
            $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
            flash('Evento eliminato.');
        }
        redirect('index.php#eventi');

    case 'rsvp':
        $st = $pdo->prepare('SELECT 1 FROM event_rsvp WHERE event_id = ? AND user_id = ?');
        $st->execute([$id, $uid]);
        if ($st->fetchColumn()) {
            $pdo->prepare('DELETE FROM event_rsvp WHERE event_id = ? AND user_id = ?')->execute([$id, $uid]);
        } else {
            $pdo->prepare('INSERT OR IGNORE INTO event_rsvp (event_id, user_id) SELECT id, ? FROM events WHERE id = ?')->execute([$uid, $id]);
        }
        redirect('index.php#eventi');

    case 'propose_place':
        $eventId = (int)($_POST['event_id'] ?? 0);
        $name = post_str('name', 140);
        $url = post_str('maps_url', 500);
        $st = $pdo->prepare('SELECT 1 FROM events WHERE id = ? AND event_date >= ?');
        $st->execute([$eventId, date('Y-m-d')]);
        if (!$st->fetchColumn() || $name === '') {
            flash('Indica almeno il nome del posto.', 'err');
            redirect('index.php#eventi');
        }
        if ($url !== '' && !is_maps_url($url)) {
            flash('Il link deve essere di Google Maps (google.com/maps o maps.app.goo.gl).', 'err');
            redirect('index.php#ev' . $eventId);
        }
        $pdo->prepare('INSERT INTO event_places (event_id, user_id, name, maps_url, note, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$eventId, $uid, $name, $url ?: maps_search_url($name), post_str('note', 300), time()]);
        flash('Proposta allegata.');
        redirect('index.php#ev' . $eventId);

    case 'rate_place':
        $stars = (int)($_POST['stars'] ?? 0);
        $st = $pdo->prepare('SELECT event_id FROM event_places WHERE id = ?');
        $st->execute([$id]);
        $eventId = (int)$st->fetchColumn();
        if ($eventId && $stars >= 1 && $stars <= 5) {
            $st = $pdo->prepare('SELECT stars FROM place_ratings WHERE place_id = ? AND user_id = ?');
            $st->execute([$id, $uid]);
            if ((int)$st->fetchColumn() === $stars) {
                // Stesso voto cliccato di nuovo: lo toglie.
                $pdo->prepare('DELETE FROM place_ratings WHERE place_id = ? AND user_id = ?')->execute([$id, $uid]);
            } else {
                $pdo->prepare('INSERT OR REPLACE INTO place_ratings (place_id, user_id, stars) VALUES (?, ?, ?)')->execute([$id, $uid, $stars]);
            }
        }
        redirect('index.php#ev' . $eventId);

    case 'edit_place':
        $st = $pdo->prepare('SELECT p.*, e.location, e.location_url, e.event_date FROM event_places p JOIN events e ON e.id = p.event_id WHERE p.id = ?');
        $st->execute([$id]);
        $pl = $st->fetch();
        if (!$pl || !can_manage($pl['user_id']) || $pl['event_date'] < date('Y-m-d')) redirect('index.php#eventi');
        $back = 'index.php#ev' . (int)$pl['event_id'];
        $name = post_str('name', 140);
        $url = post_str('maps_url', 500);
        if ($name === '') {
            flash('Il nome del posto non può essere vuoto.', 'err');
            redirect($back);
        }
        if ($url !== '' && !is_maps_url($url)) {
            flash('Il link deve essere di Google Maps (google.com/maps o maps.app.goo.gl).', 'err');
            redirect($back);
        }
        $url = $url ?: maps_search_url($name);
        $nameChanged = mb_strtolower($name) !== mb_strtolower($pl['name']);
        $wasChosen = $pl['location'] === $pl['name'] && $pl['location_url'] === $pl['maps_url'];
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE event_places SET name = ?, maps_url = ?, note = ? WHERE id = ?')
            ->execute([$name, $url, post_str('note', 300), $id]);
        if ($nameChanged) {
            $pdo->prepare('DELETE FROM place_ratings WHERE place_id = ? AND user_id != ?')->execute([$id, (int)$pl['user_id']]);
        }
        if ($wasChosen) {
            $pdo->prepare('UPDATE events SET location = ?, location_url = ? WHERE id = ?')->execute([$name, $url, $pl['event_id']]);
        }
        $pdo->commit();
        flash($nameChanged ? 'Proposta aggiornata. Hai cambiato posto, quindi i voti degli altri sono stati azzerati.' : 'Proposta aggiornata.');
        redirect($back);

    case 'choose_place':
        $st = $pdo->prepare('SELECT p.*, e.user_id AS event_owner FROM event_places p JOIN events e ON e.id = p.event_id WHERE p.id = ?');
        $st->execute([$id]);
        $pl = $st->fetch();
        if ($pl && can_manage($pl['event_owner'])) {
            $pdo->prepare('UPDATE events SET location = ?, location_url = ? WHERE id = ?')
                ->execute([$pl['name'], $pl['maps_url'], $pl['event_id']]);
            flash('Luogo scelto: ' . $pl['name'] . '.');
        }
        redirect('index.php#ev' . (int)($pl['event_id'] ?? 0));

    case 'delete_place':
        if (owned($pdo, 'event_places', $id, $uid)) {
            $pdo->prepare('DELETE FROM event_places WHERE id = ?')->execute([$id]);
            flash('Proposta eliminata.');
        }
        redirect(back('index.php#eventi'));

    case 'create_topic':
        $title = post_str('title', 200);
        $cat = array_key_exists($_POST['category'] ?? '', categories()) ? $_POST['category'] : FALLBACK_CATEGORY;
        if ($title === '') {
            flash('Serve un titolo.', 'err');
            redirect('index.php#discussione');
        }
        $pdo->prepare('INSERT INTO topics (user_id, title, body, category, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$uid, $title, post_str('body', 10000), $cat, time()]);
        redirect('topic.php?id=' . $pdo->lastInsertId());

    case 'delete_topic':
        if (owned($pdo, 'topics', $id, $uid)) {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM votes WHERE target_type = 'comment' AND target_id IN (SELECT id FROM comments WHERE topic_id = ?)")->execute([$id]);
            $pdo->prepare("DELETE FROM votes WHERE target_type = 'topic' AND target_id = ?")->execute([$id]);
            $pdo->prepare('DELETE FROM topics WHERE id = ?')->execute([$id]);
            $pdo->commit();
            flash('Topic eliminato.');
        }
        redirect('index.php#discussione');

    case 'comment':
        $topicId = (int)($_POST['topic_id'] ?? 0);
        $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
        $body = post_str('body', 5000);
        $st = $pdo->prepare('SELECT 1 FROM topics WHERE id = ?');
        $st->execute([$topicId]);
        if (!$st->fetchColumn()) redirect('index.php#discussione');
        if ($parent) {
            $st = $pdo->prepare('SELECT 1 FROM comments WHERE id = ? AND topic_id = ?');
            $st->execute([$parent, $topicId]);
            if (!$st->fetchColumn()) $parent = null;
        }
        if ($body === '') redirect('topic.php?id=' . $topicId);
        $pdo->prepare('INSERT INTO comments (topic_id, parent_id, user_id, body, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$topicId, $parent, $uid, $body, time()]);
        redirect('topic.php?id=' . $topicId . '#c' . $pdo->lastInsertId());

    case 'delete_comment':
        if (owned($pdo, 'comments', $id, $uid)) {
            // Se ha risposte, lo svuotiamo invece di cancellare tutto il thread.
            $st = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE parent_id = ?');
            $st->execute([$id]);
            if ($st->fetchColumn() > 0) {
                $pdo->prepare("UPDATE comments SET body = '[commento eliminato]' WHERE id = ?")->execute([$id]);
            } else {
                $pdo->prepare("DELETE FROM votes WHERE target_type = 'comment' AND target_id = ?")->execute([$id]);
                $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$id]);
            }
        }
        redirect(back('index.php#discussione'));

    case 'vote':
        $type = ($_POST['type'] ?? '') === 'comment' ? 'comment' : 'topic';
        $value = (int)($_POST['value'] ?? 0) === -1 ? -1 : 1;
        $table = $type === 'comment' ? 'comments' : 'topics';
        $st = $pdo->prepare("SELECT 1 FROM $table WHERE id = ?");
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            $st = $pdo->prepare('SELECT value FROM votes WHERE user_id = ? AND target_type = ? AND target_id = ?');
            $st->execute([$uid, $type, $id]);
            $cur = $st->fetchColumn();
            if ($cur !== false && (int)$cur === $value) {
                // Secondo clic sulla stessa freccia: annulla il voto.
                $pdo->prepare('DELETE FROM votes WHERE user_id = ? AND target_type = ? AND target_id = ?')->execute([$uid, $type, $id]);
            } else {
                $pdo->prepare('INSERT OR REPLACE INTO votes (user_id, target_type, target_id, value) VALUES (?, ?, ?, ?)')
                    ->execute([$uid, $type, $id, $value]);
            }
        }
        redirect(back('index.php#discussione'));

    case 'upload':
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $tooBig = $f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            flash($tooBig ? 'File troppo grande.' : 'Caricamento non riuscito.', 'err');
            redirect('index.php#materiali');
        }
        $orig = basename(str_replace('\\', '/', (string)$f['name']));
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXT, true)) {
            flash('Tipo di file non ammesso. Formati: ' . implode(', ', ALLOWED_EXT) . '.', 'err');
            redirect('index.php#materiali');
        }
        if ($f['size'] > MAX_UPLOAD_BYTES) {
            flash('File troppo grande (max ' . (MAX_UPLOAD_BYTES / 1048576) . ' MB).', 'err');
            redirect('index.php#materiali');
        }
        $stored = bin2hex(random_bytes(16)) . '.bin';
        if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $stored)) {
            flash('Impossibile salvare il file sul server.', 'err');
            redirect('index.php#materiali');
        }
        $title = post_str('title', 200) ?: pathinfo($orig, PATHINFO_FILENAME);
        $pdo->prepare('INSERT INTO documents (user_id, title, description, original_name, stored_name, size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $title, post_str('description', 1000), mb_substr($orig, 0, 200), $stored, (int)$f['size'], time()]);
        flash('Documento caricato.');
        redirect('index.php#materiali');

    case 'delete_doc':
        $st = $pdo->prepare('SELECT stored_name FROM documents WHERE id = ?');
        $st->execute([$id]);
        if (owned($pdo, 'documents', $id, $uid) && ($stored = $st->fetchColumn())) {
            @unlink(UPLOAD_DIR . '/' . $stored);
            $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
            flash('Documento eliminato.');
        }
        redirect('index.php#materiali');

    case 'admin_reset_password':
        require_admin();
        $pass = (string)($_POST['password'] ?? '');
        if (mb_strlen($pass) < 4) {
            flash('La nuova password deve avere almeno 4 caratteri.', 'err');
        } else {
            $st = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $st->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            flash($st->rowCount() ? 'Password aggiornata. Comunicala tu all\'utente.' : 'Utente non trovato.', $st->rowCount() ? 'ok' : 'err');
        }
        redirect('admin.php');

    case 'admin_toggle_admin':
        require_admin();
        if ($id === $uid) {
            flash('Non puoi togliere i permessi a te stesso.', 'err');
        } else {
            $pdo->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?')->execute([$id]);
            flash('Permessi aggiornati.');
        }
        redirect('admin.php');

    case 'admin_delete_user':
        require_admin();
        if ($id === $uid) {
            flash('Non puoi eliminare il tuo account da qui.', 'err');
            redirect('admin.php');
        }
        $st = $pdo->prepare('SELECT stored_name FROM documents WHERE user_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $stored) {
            @unlink(UPLOAD_DIR . '/' . $stored);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        flash('Utente eliminato insieme ai suoi contenuti.');
        redirect('admin.php');

    case 'admin_add_category':
        require_admin();
        $name = preg_replace('/\s+/u', ' ', post_str('name', 40));
        $color = array_key_exists($_POST['color'] ?? '', CATEGORY_COLORS) ? $_POST['color'] : 'c-gray';
        if (mb_strlen($name) < 2) {
            flash('Il nome della categoria deve avere almeno 2 caratteri.', 'err');
        } elseif (array_key_exists(mb_strtolower($name), array_change_key_case(categories()))) {
            flash('Esiste già una categoria con questo nome.', 'err');
        } else {
            $pdo->prepare('INSERT INTO categories (name, color) VALUES (?, ?)')->execute([$name, $color]);
            flash('Categoria "' . $name . '" aggiunta.');
        }
        redirect('admin.php#categorie');

    case 'admin_edit_category':
        require_admin();
        $st = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
        $st->execute([$id]);
        $old = $st->fetchColumn();
        $name = preg_replace('/\s+/u', ' ', post_str('name', 40));
        $color = array_key_exists($_POST['color'] ?? '', CATEGORY_COLORS) ? $_POST['color'] : 'c-gray';
        if ($old === false) redirect('admin.php#categorie');
        if ($old === FALLBACK_CATEGORY) $name = FALLBACK_CATEGORY; // si può solo ricolorare
        $st = $pdo->prepare('SELECT 1 FROM categories WHERE name = ? AND id != ?');
        $st->execute([$name, $id]);
        if (mb_strlen($name) < 2 || $st->fetchColumn()) {
            flash('Nome non valido o già usato da un\'altra categoria.', 'err');
            redirect('admin.php#categorie');
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE categories SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $id]);
        $pdo->prepare('UPDATE topics SET category = ? WHERE category = ?')->execute([$name, $old]);
        $pdo->commit();
        flash('Categoria aggiornata.');
        redirect('admin.php#categorie');

    case 'admin_delete_category':
        require_admin();
        $st = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
        $st->execute([$id]);
        $old = $st->fetchColumn();
        if ($old !== false && $old !== FALLBACK_CATEGORY) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE topics SET category = ? WHERE category = ?')->execute([FALLBACK_CATEGORY, $old]);
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            $pdo->commit();
            flash('Categoria eliminata. I suoi topic sono finiti in "' . FALLBACK_CATEGORY . '".');
        }
        redirect('admin.php#categorie');

    case 'update_bio':
        $pdo->prepare('UPDATE users SET bio = ? WHERE id = ?')->execute([post_str('bio', 400), $uid]);
        flash('Presentazione aggiornata.');
        redirect('index.php#membri');
}

redirect('index.php');
