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

function owned(PDO $pdo, string $table, int $id, int $uid): bool
{
    $st = $pdo->prepare("SELECT 1 FROM $table WHERE id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    return (bool)$st->fetchColumn();
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

    case 'create_topic':
        $title = post_str('title', 200);
        $cat = in_array($_POST['category'] ?? '', TOPIC_CATEGORIES, true) ? $_POST['category'] : 'Varie';
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
        $st = $pdo->prepare('SELECT stored_name FROM documents WHERE id = ? AND user_id = ?');
        $st->execute([$id, $uid]);
        if ($stored = $st->fetchColumn()) {
            @unlink(UPLOAD_DIR . '/' . $stored);
            $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
            flash('Documento eliminato.');
        }
        redirect('index.php#materiali');

    case 'update_bio':
        $pdo->prepare('UPDATE users SET bio = ? WHERE id = ?')->execute([post_str('bio', 400), $uid]);
        flash('Presentazione aggiornata.');
        redirect('index.php#membri');
}

redirect('index.php');
