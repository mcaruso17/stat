<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$me = require_login();
$uid = (int)$me['id'];
$pdo = db();
$today = date('Y-m-d');

// Eventi
$evSql = 'SELECT e.*, u.name AS author,
    (SELECT COUNT(*) FROM event_rsvp r WHERE r.event_id = e.id) AS n_going,
    (SELECT COUNT(*) FROM event_rsvp r WHERE r.event_id = e.id AND r.user_id = :uid) AS going,
    (SELECT GROUP_CONCAT(u2.name, \', \') FROM event_rsvp r JOIN users u2 ON u2.id = r.user_id WHERE r.event_id = e.id) AS going_names
    FROM events e LEFT JOIN users u ON u.id = e.user_id';
$st = $pdo->prepare("$evSql WHERE e.event_date >= :d ORDER BY e.event_date, e.event_time");
$st->execute([':uid' => $uid, ':d' => $today]);
$upcoming = $st->fetchAll();
$st = $pdo->prepare("$evSql WHERE e.event_date < :d ORDER BY e.event_date DESC LIMIT 12");
$st->execute([':uid' => $uid, ':d' => $today]);
$past = $st->fetchAll();

// Luoghi proposti, raggruppati per evento
$st = $pdo->prepare('SELECT p.*, u.name AS author,
    (SELECT COUNT(*) FROM place_ratings r WHERE r.place_id = p.id) AS n_ratings,
    (SELECT COALESCE(SUM(stars), 0) FROM place_ratings r WHERE r.place_id = p.id) AS sum_stars,
    (SELECT stars FROM place_ratings r WHERE r.place_id = p.id AND r.user_id = ?) AS my_stars
    FROM event_places p JOIN users u ON u.id = p.user_id
    ORDER BY p.created_at ASC');
$st->execute([$uid]);
$places = [];
foreach ($st->fetchAll() as $pl) {
    $n = (int)$pl['n_ratings'];
    $pl['avg'] = $n ? $pl['sum_stars'] / $n : 0.0;
    $pl['score'] = weighted_score($n, (float)$pl['sum_stars']);
    $places[(int)$pl['event_id']][] = $pl;
}
// Prima i luoghi votati, per punteggio ponderato; poi quelli senza voti.
foreach ($places as &$list) {
    usort($list, fn($a, $b) => [(int)$b['n_ratings'] > 0, $b['score']] <=> [(int)$a['n_ratings'] > 0, $a['score']]);
}
unset($list);

/** Campi del form luogo, usati sia per proporre che per modificare. */
function place_fields(?array $pl = null): void
{
    ?>
    <label>Cerca su Google Maps
      <span class="maps-search">
        <input name="name" required maxlength="140" placeholder="Es. Trattoria Da Enzo, Trastevere" autocomplete="off" data-maps-query value="<?= e($pl['name'] ?? '') ?>">
        <a class="btn sm ghost" href="<?= e($pl ? maps_search_url($pl['name']) : 'https://www.google.com/maps') ?>" target="_blank" rel="noopener noreferrer" data-maps-open>Apri Maps ↗</a>
      </span>
    </label>
    <div class="maps-preview" data-maps-preview hidden>
      <iframe title="Anteprima Google Maps" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
    <label>Link Google Maps <small class="muted">facoltativo: incolla il link "Condividi" del posto per essere precisi</small>
      <input name="maps_url" type="url" maxlength="500" placeholder="https://maps.app.goo.gl/..." value="<?= e($pl && $pl['maps_url'] !== maps_search_url($pl['name']) ? $pl['maps_url'] : '') ?>">
    </label>
    <label>Perché questo posto? <small class="muted">facoltativo</small>
      <input name="note" maxlength="300" placeholder="Prezzi, capienza, comodo dalla metro..." value="<?= e($pl['note'] ?? '') ?>">
    </label>
    <?php
}

// Discussione
$sort = ($_GET['sort'] ?? 'new') === 'top' ? 'top' : 'new';
$cat = in_array($_GET['cat'] ?? '', TOPIC_CATEGORIES, true) ? $_GET['cat'] : '';
$where = $cat ? 'WHERE t.category = :cat' : '';
$order = $sort === 'top' ? 'score DESC, t.created_at DESC' : 't.created_at DESC';
$st = $pdo->prepare("SELECT t.*, u.name AS author,
    COALESCE((SELECT SUM(value) FROM votes v WHERE v.target_type = 'topic' AND v.target_id = t.id), 0) AS score,
    COALESCE((SELECT value FROM votes v WHERE v.target_type = 'topic' AND v.target_id = t.id AND v.user_id = :uid), 0) AS mine,
    (SELECT COUNT(*) FROM comments c WHERE c.topic_id = t.id) AS n_comments
    FROM topics t JOIN users u ON u.id = t.user_id $where ORDER BY $order LIMIT 100");
$params = [':uid' => $uid];
if ($cat) $params[':cat'] = $cat;
$st->execute($params);
$topics = $st->fetchAll();

// Materiali
$docs = $pdo->query('SELECT d.*, u.name AS author FROM documents d JOIN users u ON u.id = d.user_id ORDER BY d.created_at DESC')->fetchAll();

// Membri
$members = $pdo->query('SELECT u.*,
    (SELECT COUNT(*) FROM topics t WHERE t.user_id = u.id) AS n_topics,
    (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS n_comments,
    (SELECT COUNT(*) FROM documents d WHERE d.user_id = u.id) AS n_docs
    FROM users u ORDER BY u.name COLLATE NOCASE')->fetchAll();

function event_card(array $ev, int $uid, array $places, bool $isPast = false): void
{
    $ts = strtotime($ev['event_date']);
    $eid = (int)$ev['id'];
    $back = 'index.php#ev' . $eid;
    ?>
    <article class="card event<?= $isPast ? ' past' : '' ?>" id="ev<?= $eid ?>">
      <div class="datebox">
        <span class="d"><?= date('j', $ts) ?></span>
        <span class="m"><?= MONTHS[(int)date('n', $ts) - 1] ?></span>
        <span class="y"><?= date('Y', $ts) ?></span>
      </div>
      <div class="event-body">
        <p class="label"><?= e(WEEKDAYS[(int)date('w', $ts)]) ?><?= $ev['event_time'] ? ' · ore ' . e($ev['event_time']) : '' ?><?php if ($ev['location']): ?> · <?php if ($ev['location_url']): ?><a href="<?= e($ev['location_url']) ?>" target="_blank" rel="noopener noreferrer">📍 <?= e($ev['location']) ?></a><?php else: ?><?= e($ev['location']) ?><?php endif; ?><?php endif; ?></p>
        <h3><?= e($ev['title']) ?></h3>
        <?php if ($ev['description']): ?><p><?= rich($ev['description']) ?></p><?php endif; ?>
        <div class="event-foot">
          <?php if (!$isPast): ?>
          <form method="post" action="action.php" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rsvp">
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <button class="btn sm <?= $ev['going'] ? 'on' : 'ghost' ?>"><?= $ev['going'] ? '✓ Ci sono' : 'Partecipo' ?></button>
          </form>
          <?php endif; ?>
          <span class="muted" title="<?= e($ev['going_names'] ?? '') ?>"><?= (int)$ev['n_going'] ?> partecipant<?= (int)$ev['n_going'] === 1 ? 'e' : 'i' ?><?= $ev['going_names'] ? ': ' . e($ev['going_names']) : '' ?></span>
          <?php if (can_manage($ev['user_id'])): ?><?= delete_button('delete_event', (int)$ev['id'], 'index.php#eventi') ?><?php endif; ?>
        </div>

        <div class="places">
          <div class="places-head">
            <p class="places-title">Luoghi proposti <span><?= count($places) ?></span></p>
            <button type="button" class="help-btn" data-help="voting-help" aria-haspopup="dialog">ⓘ Come funziona</button>
          </div>
          <?php if (!$places && !$isPast): ?><p class="muted small">Nessuna proposta per ora. Sii il primo!</p><?php endif; ?>
          <?php if ($places): ?>
          <ul class="place-list">
            <?php foreach ($places as $pl): $chosen = $ev['location_url'] === $pl['maps_url'] && $ev['location'] === $pl['name']; ?>
            <li class="place<?= $chosen ? ' chosen' : '' ?>">
              <div class="place-score" title="Punteggio ponderato: media bayesiana che premia i luoghi votati da più persone">
                <b><?= (int)$pl['n_ratings'] ? fmt_num($pl['score']) : '–' ?></b>
                <span>punti</span>
              </div>
              <div class="place-body">
                <a class="place-name" href="<?= e($pl['maps_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($pl['name']) ?> <span aria-hidden="true">↗</span></a>
                <?php if ($chosen): ?><span class="badge">scelto</span><?php endif; ?>
                <?php if ($pl['note']): ?><p class="place-note"><?= e($pl['note']) ?></p><?php endif; ?>
                <div class="rating">
                  <form method="post" action="action.php" class="stars" aria-label="Il tuo voto">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rate_place">
                    <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
                    <?php for ($s = 5; $s >= 1; $s--): // ordine inverso per l'effetto hover in CSS ?>
                    <button name="stars" value="<?= $s ?>" class="star<?= $s <= (int)$pl['my_stars'] ? ' on' : '' ?>"
                      title="<?= (int)$pl['my_stars'] === $s ? 'Togli il tuo voto' : $s . ' stell' . ($s === 1 ? 'a' : 'e') ?>">★</button>
                    <?php endfor; ?>
                  </form>
                  <span class="meta">
                    <?php if ((int)$pl['n_ratings']): ?>media <?= fmt_num($pl['avg']) ?> · <?= (int)$pl['n_ratings'] ?> vot<?= (int)$pl['n_ratings'] === 1 ? 'o' : 'i' ?><?php else: ?>nessun voto<?php endif; ?>
                    <?php if ($pl['my_stars']): ?> · tu: <?= (int)$pl['my_stars'] ?>★<?php endif; ?>
                  </span>
                </div>
                <div class="meta">proposto da <?= e($pl['author']) ?>
                  <?php if (!$isPast && !$chosen && can_manage($ev['user_id'])): ?> ·
                  <form method="post" action="action.php" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="choose_place">
                    <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
                    <button class="linkbtn">Scegli questo</button>
                  </form>
                  <?php endif; ?>
                  <?php if (can_manage($pl['user_id'])): ?> · <?= delete_button('delete_place', (int)$pl['id'], $back) ?><?php endif; ?>
                </div>
                <?php if (!$isPast && can_manage($pl['user_id'])): ?>
                <details class="edit-place">
                  <summary class="linkbtn">Modifica</summary>
                  <form method="post" action="action.php" class="form place-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_place">
                    <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
                    <?php place_fields($pl); ?>
                    <p class="muted small">Se cambi il nome del posto i voti ricevuti si azzerano: di fatto è una nuova proposta.</p>
                    <button class="btn sm">Salva modifiche</button>
                  </form>
                </details>
                <?php endif; ?>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>

          <?php if (!$isPast): ?>
          <details class="propose">
            <summary class="linkbtn">📍 Vuoi proporre una location?</summary>
            <form method="post" action="action.php" class="form place-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="propose_place">
              <input type="hidden" name="event_id" value="<?= $eid ?>">
              <?php place_fields(); ?>
              <button class="btn sm">Allega la proposta</button>
            </form>
          </details>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php
}

page_start('Home');
?>
<?php intro(
    'Bentornato/a, ' . explode(' ', $me['name'])[0],
    '<a class="btn" href="#discussione">Apri un topic</a> <a class="btn ghost light" href="#eventi">Prossimi eventi</a>'
); ?>

<!-- EVENTI -->
<section class="section" id="eventi">
  <div class="wrap">
    <div class="section-head">
      <h2><span class="tag coral">01</span> Eventi</h2>
      <details class="composer">
        <summary class="btn">+ Proponi un evento</summary>
        <form method="post" action="action.php" class="card form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_event">
          <label>Titolo <input name="title" required maxlength="140"></label>
          <div class="row">
            <label>Data <input type="date" name="event_date" required min="<?= $today ?>"></label>
            <label>Ora <input type="time" name="event_time"></label>
          </div>
          <label>Luogo <input name="location" maxlength="140"></label>
          <label>Descrizione <textarea name="description" rows="3" maxlength="3000"></textarea></label>
          <button class="btn">Pubblica evento</button>
        </form>
      </details>
    </div>
    <?php if (!$upcoming): ?><p class="empty">Nessun evento in programma. Proponine uno!</p><?php endif; ?>
    <div class="events">
      <?php foreach ($upcoming as $ev) event_card($ev, $uid, $places[(int)$ev['id']] ?? []); ?>
    </div>
    <?php if ($past): ?>
    <details class="past-events">
      <summary>Eventi passati (<?= count($past) ?>)</summary>
      <div class="events"><?php foreach ($past as $ev) event_card($ev, $uid, $places[(int)$ev['id']] ?? [], true); ?></div>
    </details>
    <?php endif; ?>
  </div>
</section>

<!-- DISCUSSIONE -->
<section class="section alt" id="discussione">
  <div class="wrap">
    <div class="section-head">
      <h2><span class="tag teal">02</span> Discussione</h2>
      <details class="composer">
        <summary class="btn">+ Nuovo topic</summary>
        <form method="post" action="action.php" class="card form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_topic">
          <label>Titolo <input name="title" required maxlength="200"></label>
          <label>Categoria
            <select name="category">
              <?php foreach (TOPIC_CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label>Testo <textarea name="body" rows="5" maxlength="10000" placeholder="Idee, domande, link a bandi o concorsi..."></textarea></label>
          <button class="btn">Pubblica</button>
        </form>
      </details>
    </div>
    <div class="filters">
      <a class="chip<?= $sort === 'new' ? ' on' : '' ?>" href="?sort=new<?= $cat ? '&cat=' . urlencode($cat) : '' ?>#discussione">Recenti</a>
      <a class="chip<?= $sort === 'top' ? ' on' : '' ?>" href="?sort=top<?= $cat ? '&cat=' . urlencode($cat) : '' ?>#discussione">Più votati</a>
      <span class="sep"></span>
      <a class="chip<?= !$cat ? ' on' : '' ?>" href="?sort=<?= $sort ?>#discussione">Tutti</a>
      <?php foreach (TOPIC_CATEGORIES as $c): ?>
        <a class="chip<?= $cat === $c ? ' on' : '' ?>" href="?sort=<?= $sort ?>&cat=<?= urlencode($c) ?>#discussione"><?= e($c) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$topics): ?><p class="empty">Ancora nessun topic<?= $cat ? ' in questa categoria' : '' ?>. Rompi il ghiaccio.</p><?php endif; ?>
    <div class="topics">
      <?php foreach ($topics as $t): ?>
      <article class="card topic">
        <?= vote_box('topic', (int)$t['id'], (int)$t['score'], (int)$t['mine'], 'index.php?sort=' . $sort . ($cat ? '&cat=' . urlencode($cat) : '') . '#discussione') ?>
        <div class="topic-body">
          <p class="label"><?= e($t['category']) ?></p>
          <h3><a href="topic.php?id=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a></h3>
          <?php if ($t['body']): ?><p class="excerpt"><?= e(mb_strimwidth($t['body'], 0, 220, '…')) ?></p><?php endif; ?>
          <p class="meta"><?= e($t['author']) ?> · <?= ago((int)$t['created_at']) ?> · <a href="topic.php?id=<?= (int)$t['id'] ?>#commenti"><?= (int)$t['n_comments'] ?> comment<?= (int)$t['n_comments'] === 1 ? 'o' : 'i' ?></a></p>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- MATERIALI -->
<section class="section" id="materiali">
  <div class="wrap">
    <div class="section-head">
      <h2><span class="tag sun">03</span> Materiali</h2>
      <details class="composer">
        <summary class="btn">+ Carica un documento</summary>
        <form method="post" action="action.php" enctype="multipart/form-data" class="card form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload">
          <label>File (max <?= MAX_UPLOAD_BYTES / 1048576 ?> MB)
            <input type="file" name="file" required accept="<?= e(implode(',', array_map(fn($x) => '.' . $x, ALLOWED_EXT))) ?>">
          </label>
          <label>Titolo <input name="title" maxlength="200" placeholder="Se vuoto, uso il nome del file"></label>
          <label>Perché è interessante? <textarea name="description" rows="2" maxlength="1000"></textarea></label>
          <button class="btn">Carica</button>
        </form>
      </details>
    </div>
    <?php if (!$docs): ?><p class="empty">Nessun materiale caricato. Slide, paper, script R o Python, bandi: tutto utile.</p><?php endif; ?>
    <div class="docs">
      <?php foreach ($docs as $d): $ext = strtolower(pathinfo($d['original_name'], PATHINFO_EXTENSION)); ?>
      <article class="card doc">
        <span class="ext"><?= e($ext) ?></span>
        <div class="doc-body">
          <h3><a href="download.php?id=<?= (int)$d['id'] ?>"><?= e($d['title']) ?></a></h3>
          <?php if ($d['description']): ?><p><?= rich($d['description']) ?></p><?php endif; ?>
          <div class="meta"><?= e($d['original_name']) ?> · <?= fmt_size((int)$d['size']) ?> · <?= e($d['author']) ?> · <?= ago((int)$d['created_at']) ?>
            <?php if (can_manage($d['user_id'])): ?> · <?= delete_button('delete_doc', (int)$d['id'], 'index.php#materiali') ?><?php endif; ?>
          </div>
        </div>
        <a class="btn sm ghost" href="download.php?id=<?= (int)$d['id'] ?>">Scarica</a>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- MEMBRI -->
<section class="section alt" id="membri">
  <div class="wrap">
    <div class="section-head">
      <h2><span class="tag lilac">04</span> Membri <small><?= count($members) ?></small></h2>
    </div>
    <div class="members">
      <?php foreach ($members as $m): $isMe = (int)$m['id'] === $uid; ?>
      <article class="card member<?= $isMe ? ' me' : '' ?>">
        <span class="avatar" style="background:<?= avatar_color($m['name']) ?>"><?= e(initials($m['name'])) ?></span>
        <h3><?= e($m['name']) ?><?= $isMe ? ' <small>(tu)</small>' : '' ?><?= $m['is_admin'] ? ' <span class="badge">admin</span>' : '' ?></h3>
        <?php if ($m['bio']): ?><p><?= e($m['bio']) ?></p><?php elseif (!$isMe): ?><p class="muted">Nessuna presentazione.</p><?php endif; ?>
        <p class="meta">Dal <?= fmt_day((int)$m['created_at']) ?> · <?= (int)$m['n_topics'] ?> topic · <?= (int)$m['n_comments'] ?> commenti · <?= (int)$m['n_docs'] ?> file</p>
        <?php if ($isMe): ?>
        <details>
          <summary class="linkbtn"><?= $m['bio'] ? 'Modifica presentazione' : 'Aggiungi una presentazione' ?></summary>
          <form method="post" action="action.php" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_bio">
            <textarea name="bio" rows="3" maxlength="400" placeholder="Ufficio, temi di cui ti occupi, interessi..."><?= e($m['bio']) ?></textarea>
            <button class="btn sm">Salva</button>
          </form>
        </details>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<dialog class="help" id="voting-help" aria-labelledby="voting-help-title">
  <form method="dialog" class="help-close-form"><button class="help-close" aria-label="Chiudi">×</button></form>
  <p class="kicker">Guida rapida</p>
  <h2 id="voting-help-title">Come si sceglie il posto</h2>
  <ol class="help-steps">
    <li>
      <h3>📍 Proponi</h3>
      <p>Su ogni evento in programma apri <b>"Vuoi proporre una location?"</b>, scrivi il nome del posto e controlla l'anteprima della mappa. Per essere precisi incolla il link "Condividi" di Google Maps. Puoi aggiungere una nota: prezzi, capienza, comodità.</p>
    </li>
    <li>
      <h3>★ Vota</h3>
      <p>Dai da 1 a 5 stelle a tutti i posti che vuoi, anche a quelli che hai proposto tu. Puoi cambiare voto quando vuoi; se riclicchi lo stesso numero di stelle, il voto viene tolto.</p>
    </li>
    <li>
      <h3>⚖️ Classifica</h3>
      <p>I posti non sono ordinati per media semplice, ma per un <b>punteggio ponderato</b> che premia quelli votati da più persone. È come se ogni posto partisse già con <?= RATING_PRIOR_WEIGHT ?> voti "neutri" da <?= (int)RATING_PRIOR_MEAN ?> stelle:</p>
      <p class="formula">punteggio = (<?= (int)RATING_PRIOR_MEAN ?> × <?= RATING_PRIOR_WEIGHT ?> + somma delle stelle) ÷ (<?= RATING_PRIOR_WEIGHT ?> + numero di voti)</p>
      <table class="help-table">
        <thead><tr><th>Voti ricevuti</th><th>Media</th><th>Punteggio</th></tr></thead>
        <tbody>
          <tr><td>4 persone danno 4★</td><td>4,0</td><td><b><?= fmt_num(weighted_score(4, 16)) ?></b></td></tr>
          <tr><td>1 persona dà 5★</td><td>5,0</td><td><?= fmt_num(weighted_score(1, 5)) ?></td></tr>
          <tr><td>8 persone danno 4★</td><td>4,0</td><td><b><?= fmt_num(weighted_score(8, 32)) ?></b></td></tr>
          <tr><td>2 persone danno 2★</td><td>2,0</td><td><?= fmt_num(weighted_score(2, 4)) ?></td></tr>
        </tbody>
      </table>
      <p class="muted small">Un solo entusiasta non basta: per salire in cima serve il gradimento di molti. I posti ancora senza voti stanno in fondo alla lista.</p>
    </li>
    <li>
      <h3>✅ Scelta</h3>
      <p>Chi ha creato l'evento (o un amministratore) preme <b>"Scegli questo"</b>: il posto diventa la location ufficiale, con il link a Maps in cima alla card e l'etichetta <span class="badge">scelto</span>. La classifica è un'indicazione forte, ma la decisione finale spetta a chi organizza: può contare anche la disponibilità del locale.</p>
    </li>
    <li>
      <h3>✏️ Modifiche</h3>
      <p>Chi ha proposto un posto può modificarlo. Se cambi solo nota o link, i voti restano; se cambi il nome del posto i voti degli altri si azzerano, perché di fatto è una proposta nuova.</p>
    </li>
  </ol>
  <form method="dialog"><button class="btn">Ho capito</button></form>
</dialog>

<?php page_end();
