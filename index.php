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

// Localizza (al massimo 2 per caricamento) le proposte inserite prima della geolocalizzazione.
foreach ($pdo->query('SELECT id, name, address, maps_url FROM event_places WHERE geo_tried = 0 LIMIT 2')->fetchAll() as $g) {
    $pos = locate_place($g['name'], $g['address'], str_starts_with($g['maps_url'], 'https://www.google.com/maps/search/') ? '' : $g['maps_url']);
    $pdo->prepare('UPDATE event_places SET lat = ?, lon = ?, geo_tried = 1 WHERE id = ?')->execute([$pos[0] ?? null, $pos[1] ?? null, $g['id']]);
}

// Chi ha indicato la zona, e chi partecipa a ogni evento: servono per il compromesso.
$zoneUsers = [];
foreach ($pdo->query('SELECT id, name, zone_lat, zone_lon FROM users WHERE zone_lat IS NOT NULL')->fetchAll() as $z) {
    $zoneUsers[(int)$z['id']] = [(float)$z['zone_lat'], (float)$z['zone_lon']];
}
$rsvpByEvent = [];
foreach ($pdo->query('SELECT event_id, user_id FROM event_rsvp')->fetchAll() as $r) {
    $rsvpByEvent[(int)$r['event_id']][] = (int)$r['user_id'];
}

/** Gruppo su cui calcolare il compromesso: chi partecipa (se almeno 2 hanno la zona), altrimenti tutti. */
function event_people(int $eid): array
{
    global $zoneUsers, $rsvpByEvent;
    $going = $rsvpByEvent[$eid] ?? [];
    $withZone = array_values(array_intersect_key($zoneUsers, array_flip($going)));
    if (count($withZone) >= 2) {
        return ['people' => $withZone, 'basis' => 'going', 'missing' => count($going) - count($withZone)];
    }
    return ['people' => array_values($zoneUsers), 'basis' => 'all', 'missing' => 0];
}

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
        <a class="btn sm secondary" href="<?= e($pl ? maps_search_url($pl['name']) : 'https://www.google.com/maps') ?>" target="_blank" rel="noopener noreferrer" data-maps-open>Apri Maps <?= icon('arrow') ?></a>
      </span>
    </label>
    <div class="maps-preview" data-maps-preview hidden>
      <iframe title="Anteprima Google Maps" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
    <label>Link Google Maps <small>Facoltativo. Incolla il link "Condividi" del posto per essere precisi.</small>
      <input name="maps_url" type="url" maxlength="500" placeholder="https://maps.app.goo.gl/..." value="<?= e($pl && $pl['maps_url'] !== maps_search_url($pl['name']) ? $pl['maps_url'] : '') ?>">
    </label>
    <label>Indirizzo <small>Facoltativo. Serve se il nome non basta a trovarlo: metro più vicina e compromesso si calcolano da qui.</small>
      <input name="address" maxlength="160" placeholder="Es. Via dei Vascellari 29" value="<?= e($pl['address'] ?? '') ?>">
    </label>
    <label>Perché questo posto? <small>Facoltativo.</small>
      <input name="note" maxlength="300" placeholder="Prezzi, capienza, comodo dalla metro..." value="<?= e($pl['note'] ?? '') ?>">
    </label>
    <?php
}

// Sondaggi sulla data, raggruppati per evento
$st = $pdo->prepare('SELECT o.*,
    (SELECT COUNT(*) FROM date_votes v WHERE v.option_id = o.id) AS n_yes,
    (SELECT COUNT(*) FROM date_votes v WHERE v.option_id = o.id AND v.user_id = ?) AS mine,
    (SELECT GROUP_CONCAT(u.name, \', \') FROM date_votes v JOIN users u ON u.id = v.user_id WHERE v.option_id = o.id) AS names
    FROM date_options o ORDER BY o.option_date');
$st->execute([$uid]);
$dateOptions = [];
foreach ($st->fetchAll() as $o) {
    $dateOptions[(int)$o['event_id']][] = $o;
}
$st = $pdo->query('SELECT o.event_id, COUNT(DISTINCT v.user_id) FROM date_votes v JOIN date_options o ON o.id = v.option_id GROUP BY o.event_id');
$pollVoters = $st->fetchAll(PDO::FETCH_KEY_PAIR);

// Discussione
$sort = ($_GET['sort'] ?? 'new') === 'top' ? 'top' : 'new';
$cat = array_key_exists($_GET['cat'] ?? '', categories()) ? $_GET['cat'] : '';
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

/** Sondaggio di disponibilità sulla data dell'evento. */
function date_poll(array $ev, array $options, int $voters, bool $isPast): void
{
    $eid = (int)$ev['id'];
    $open = !(int)$ev['date_confirmed'] && !$isPast;
    $manage = can_manage($ev['user_id']) && !$isPast;
    if (!$options && !$manage) return;
    if (!$options && $manage) {
        // Nessun sondaggio: solo l'opzione per crearlo.
        ?>
        <div class="poll poll-empty">
          <span class="muted small">La data non va bene a tutti?</span>
          <?= open_button('poll-add-' . $eid, 'Crea un sondaggio sulla data', 'linkbtn', 'calendar') ?>
        </div>
        <?php
        poll_add_sheet($ev, true);
        return;
    }
    $max = max(array_map(fn($o) => (int)$o['n_yes'], $options));
    ?>
    <div class="poll<?= $open ? '' : ' closed' ?>">
      <div class="places-head">
        <p class="places-title"><?= $open ? 'Quando ci vediamo?' : 'Sondaggio sulla data' ?>
          <span class="count" title="Persone che hanno risposto"><?= $voters ?></span></p>
        <span class="muted small"><?= $open ? 'Segna tutti i giorni in cui puoi esserci.' : 'Data fissata.' ?></span>
      </div>
      <div class="poll-grid">
        <?php foreach ($options as $o):
            $ots = strtotime($o['option_date']);
            $n = (int)$o['n_yes'];
            $best = $max > 0 && $n === $max;
            $fixed = (int)$ev['date_confirmed'] && $o['option_date'] === $ev['event_date'];
            $cls = ($o['mine'] ? ' mine' : '') . ($best && $open ? ' best' : '') . ($fixed ? ' fixed' : '');
            $title = $n ? 'Disponibili: ' . $o['names'] : 'Ancora nessuno';
        ?>
        <div class="poll-opt<?= $cls ?>">
          <?php if ($best && $open): ?><span class="poll-flag">Più disponibili</span><?php elseif ($fixed): ?><span class="poll-flag">Scelta</span><?php endif; ?>
          <form method="post" action="action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_date">
            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
            <button class="poll-btn" <?= $open ? '' : 'disabled' ?> title="<?= e($title) ?>" aria-pressed="<?= $o['mine'] ? 'true' : 'false' ?>">
              <span class="poll-wd"><?= WEEKDAYS_SHORT[(int)date('w', $ots)] ?></span>
              <span class="poll-d"><?= date('j', $ots) ?></span>
              <span class="poll-m"><?= MONTHS[(int)date('n', $ots) - 1] ?></span>
              <span class="poll-bar"><i style="width:<?= $max ? round($n / $max * 100) : 0 ?>%"></i></span>
              <span class="poll-n"><?= $n ?> disponibil<?= $n === 1 ? 'e' : 'i' ?></span>
              <span class="poll-check"><?= icon('check') ?><?= $o['mine'] ? 'Ci sono' : ($open ? 'Posso' : '') ?></span>
            </button>
          </form>
          <?php if ($manage && $open): ?>
          <div class="poll-admin">
            <form method="post" action="action.php" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="fix_date">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button class="linkbtn strong">Fissa</button>
            </form>
            <?= delete_button('delete_date_option', (int)$o['id'], 'index.php#ev' . $eid, 'Togli') ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($manage): ?>
      <div class="poll-foot">
        <?php if ($open): ?>
          <?php if (count($options) < MAX_DATE_OPTIONS): ?><?= open_button('poll-add-' . $eid, 'Aggiungi una data', 'linkbtn', 'plus') ?><?php endif; ?>
          <span class="muted small">Chi organizza fissa la data quando il quadro è chiaro.</span>
        <?php else: ?>
          <form method="post" action="action.php" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reopen_date_poll">
            <input type="hidden" name="id" value="<?= $eid ?>">
            <button class="linkbtn">Riapri il sondaggio</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if ($open) poll_add_sheet($ev, false); ?>
      <?php endif; ?>
    </div>
    <?php
}

function poll_add_sheet(array $ev, bool $create): void
{
    $eid = (int)$ev['id'];
    sheet_start('poll-add-' . $eid, $create ? 'Sondaggio sulla data' : 'Aggiungi una data', $ev['title']);
    ?>
    <form method="post" action="action.php" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_date_option">
      <input type="hidden" name="event_id" value="<?= $eid ?>">
      <?php if ($create): ?><p class="muted small">La data attuale (<?= date('j', strtotime($ev['event_date'])) ?> <?= MONTHS_LONG[(int)date('n', strtotime($ev['event_date'])) - 1] ?>) entra nel sondaggio insieme a quella che aggiungi. Potrai aggiungerne altre.</p><?php endif; ?>
      <label>Data da proporre <input type="date" name="option_date" required min="<?= date('Y-m-d') ?>"></label>
      <button class="btn"><?= $create ? 'Crea il sondaggio' : 'Aggiungi' ?></button>
    </form>
    <?php
    sheet_end();
}

function event_card(array $ev, int $uid, array $places, bool $isPast = false, array $options = [], int $voters = 0): void
{
    $ts = strtotime($ev['event_date']);
    $eid = (int)$ev['id'];
    $tbd = !(int)$ev['date_confirmed'] && $options;
    $back = 'index.php#ev' . $eid;
    $going = $ev['going_names'] ? explode(', ', $ev['going_names']) : [];
    ?>
    <article class="tile event<?= $isPast ? ' past' : '' ?>" id="ev<?= $eid ?>">
      <div class="event-top">
        <div class="cal" aria-hidden="true">
          <span class="cal-m"><?= MONTHS[(int)date('n', $ts) - 1] ?></span>
          <span class="cal-d"><?= $tbd ? '?' : date('j', $ts) ?></span>
        </div>
        <div class="event-main">
          <?php if ($tbd): ?>
          <p class="event-when">Data da scegliere: <?= implode(', ', array_map(fn($o) => date('j', strtotime($o['option_date'])), array_slice($options, 0, -1))) ?><?= count($options) > 1 ? ' o ' : '' ?><?= date('j', strtotime(end($options)['option_date'])) ?> <?= MONTHS_LONG[(int)date('n', strtotime(end($options)['option_date'])) - 1] ?><?= $ev['event_time'] ? ' · ore ' . e($ev['event_time']) : '' ?></p>
          <?php else: ?>
          <p class="event-when"><?= e(ucfirst(WEEKDAYS[(int)date('w', $ts)])) ?> <?= date('j', $ts) ?> <?= MONTHS_LONG[(int)date('n', $ts) - 1] ?> <?= date('Y', $ts) ?><?= $ev['event_time'] ? ' · ore ' . e($ev['event_time']) : '' ?></p>
          <?php endif; ?>
          <h3><?= e($ev['title']) ?></h3>
          <?php if ($ev['location']): ?>
          <p class="event-where"><?= icon('pin') ?>
            <?php if ($ev['location_url']): ?><a href="<?= e($ev['location_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($ev['location']) ?> <?= icon('arrow', 'tiny') ?></a><?php else: ?><span><?= e($ev['location']) ?></span><?php endif; ?>
          </p>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($ev['description']): ?><p class="event-desc"><?= rich($ev['description']) ?></p><?php endif; ?>

      <div class="event-people">
        <?php if ($going): ?>
        <div class="stack">
          <?php foreach (array_slice($going, 0, 5) as $g): ?><span class="avatar xs" style="background:<?= avatar_color($g) ?>" title="<?= e($g) ?>"><?= e(initials($g)) ?></span><?php endforeach; ?>
          <?php if (count($going) > 5): ?><span class="avatar xs more">+<?= count($going) - 5 ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <span class="muted" title="<?= e($ev['going_names'] ?? '') ?>"><?= (int)$ev['n_going'] ?> partecipant<?= (int)$ev['n_going'] === 1 ? 'e' : 'i' ?></span>
        <span class="grow"></span>
        <?php if (can_manage($ev['user_id'])): ?><?= delete_button('delete_event', $eid, 'index.php#eventi') ?><?php endif; ?>
        <?php if (!$isPast): ?>
        <form method="post" action="action.php" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="rsvp">
          <input type="hidden" name="id" value="<?= $eid ?>">
          <button class="btn sm <?= $ev['going'] ? 'on' : '' ?>"><?= $ev['going'] ? icon('check') . '<span>Ci sono</span>' : '<span>Partecipo</span>' ?></button>
        </form>
        <?php endif; ?>
      </div>

      <?php date_poll($ev, $options, $voters, $isPast);
      // Geografia: metro più vicina e compromesso per ogni proposta, zone suggerite.
      $grp = event_people($eid);
      $geo = [];
      $bestId = null;
      foreach ($places as $pl) {
          if ($pl['lat'] === null) continue;
          $g = ['station' => nearest_stations((float)$pl['lat'], (float)$pl['lon'])[0]];
          $g['cmp'] = count($grp['people']) >= 2 ? compromise($grp['people'], (float)$pl['lat'], (float)$pl['lon']) : null;
          $geo[(int)$pl['id']] = $g;
          if ($g['cmp'] && ($bestId === null || $g['cmp']['cost'] < $geo[$bestId]['cmp']['cost'])) $bestId = (int)$pl['id'];
      }
      $hasCmp = $bestId !== null && count(array_filter($geo, fn($g) => $g['cmp'])) >= 2;
      $suggest = $isPast ? [] : suggest_points($grp['people']);
      ?>

      <div class="places">
        <div class="places-head">
          <p class="places-title">Luoghi proposti<?php if ($places): ?> <span class="count"><?= count($places) ?></span><?php endif; ?></p>
          <div class="places-tools">
            <?php if ($hasCmp): ?>
            <div class="seg small" data-sort-list="pl-<?= $eid ?>" role="group" aria-label="Ordina le proposte">
              <button type="button" class="on" data-sort="votes">Voti</button>
              <button type="button" data-sort="cost">Comodità</button>
            </div>
            <?php endif; ?>
            <?= open_button('voting-help', 'Come funziona', 'linkbtn help-link', 'info') ?>
          </div>
        </div>
        <?php if (!$places && !$isPast): ?><p class="muted small">Nessuna proposta per ora. Sii il primo.</p><?php endif; ?>
        <?php if ($places): $sheets = ''; ?>
        <ul class="place-list" id="pl-<?= $eid ?>">
          <?php foreach ($places as $i => $pl): $chosen = $ev['location_url'] === $pl['maps_url'] && $ev['location'] === $pl['name']; $nr = (int)$pl['n_ratings']; $g = $geo[(int)$pl['id']] ?? null; ?>
          <li class="place<?= $chosen ? ' chosen' : '' ?>" data-votes="<?= $i ?>" data-cost="<?= $g && $g['cmp'] ? round($g['cmp']['cost'], 2) : 9999 ?>">
            <div class="place-score" title="Punteggio ponderato: premia i luoghi votati da più persone">
              <b><?= $nr ? fmt_num($pl['score']) : '–' ?></b><span>punti</span>
            </div>
            <div class="place-body">
              <div class="place-line">
                <a class="place-name" href="<?= e($pl['maps_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($pl['name']) ?><?= icon('arrow', 'tiny') ?></a>
                <?php if ($chosen): ?><span class="badge teal">Scelto</span><?php endif; ?>
              </div>
              <?php if ($pl['note']): ?><p class="place-note"><?= e($pl['note']) ?></p><?php endif; ?>
              <div class="place-geo">
                <?php if ($g): [$stn, $skm] = $g['station']; ?>
                <span class="metro" title="Metro più vicina, in linea d'aria"><?= metro_badges($stn[1]) ?><?= e($stn[0]) ?> <span class="muted">· <?= fmt_km($skm) ?><?= $skm < 2 ? ', ' . fmt_min(walk_min($skm)) . ' min a piedi' : '' ?></span></span>
                <?php if ($g['cmp']): ?>
                <span class="cmp<?= $hasCmp && (int)$pl['id'] === $bestId ? ' best' : '' ?>" title="Tempo stimato con i mezzi per <?= $grp['basis'] === 'going' ? 'chi partecipa' : 'tutti i membri' ?> che hanno indicato la zona"><?= icon('clock') ?>≈ <?= fmt_min($g['cmp']['mean']) ?> min in media · max <?= fmt_min($g['cmp']['max']) ?></span>
                <?php if ($hasCmp && (int)$pl['id'] === $bestId): ?><span class="badge grad">Miglior compromesso</span><?php endif; ?>
                <?php endif; ?>
                <?php else: ?>
                <span class="muted">Posizione non trovata<?= can_manage($pl['user_id']) ? ': aggiungi l\'indirizzo con Modifica' : '' ?>.</span>
                <?php endif; ?>
              </div>
              <div class="rating">
                <form method="post" action="action.php" class="stars" aria-label="Il tuo voto">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="rate_place">
                  <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
                  <?php for ($s = 5; $s >= 1; $s--): // ordine inverso per l'effetto hover in CSS ?>
                  <button name="stars" value="<?= $s ?>" class="star<?= $s <= (int)$pl['my_stars'] ? ' on' : '' ?>"
                    aria-label="<?= $s ?> su 5" title="<?= (int)$pl['my_stars'] === $s ? 'Togli il tuo voto' : $s . ' su 5' ?>"><?= icon('star') ?></button>
                  <?php endfor; ?>
                </form>
                <span class="meta"><?php if ($nr): ?>Media <?= fmt_num($pl['avg']) ?> · <?= $nr ?> vot<?= $nr === 1 ? 'o' : 'i' ?><?php else: ?>Nessun voto<?php endif; ?><?php if ($pl['my_stars']): ?> · il tuo: <?= (int)$pl['my_stars'] ?>/5<?php endif; ?></span>
              </div>
              <div class="place-actions meta">
                <span>Proposto da <?= e($pl['author']) ?></span>
                <?php if (!$isPast && !$chosen && can_manage($ev['user_id'])): ?>
                <form method="post" action="action.php" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="choose_place">
                  <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
                  <button class="linkbtn strong">Scegli questo</button>
                </form>
                <?php endif; ?>
                <?php if (!$isPast && can_manage($pl['user_id'])): ?><?= open_button('edit-place-' . (int)$pl['id'], 'Modifica', 'linkbtn', '') ?><?php endif; ?>
                <?php if (can_manage($pl['user_id'])): ?><?= delete_button('delete_place', (int)$pl['id'], $back) ?><?php endif; ?>
              </div>
            </div>
          </li>
          <?php if (!$isPast && can_manage($pl['user_id'])): ob_start(); ?>
          <?php sheet_start('edit-place-' . (int)$pl['id'], 'Modifica proposta', $ev['title']); ?>
            <form method="post" action="action.php" class="form place-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="edit_place">
              <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
              <?php place_fields($pl); ?>
              <p class="muted small">Se cambi il nome del posto, i voti ricevuti si azzerano: di fatto è una nuova proposta.</p>
              <button class="btn">Salva modifiche</button>
            </form>
          <?php sheet_end(); $sheets .= ob_get_clean(); ?>
          <?php endif; ?>
          <?php endforeach; ?>
        </ul>
        <?= $sheets ?>
        <?php endif; ?>

        <?php if (!$isPast): global $me; ?>
        <?php if ($suggest): ?>
        <div class="suggest">
          <p class="suggest-title"><?= icon('pin') ?>Le zone più comode per <?= $grp['basis'] === 'going' ? 'chi partecipa' : 'il gruppo' ?></p>
          <ol class="suggest-list">
            <?php foreach ($suggest as $k => $sg): $s = $sg['station']; ?>
            <li>
              <span class="suggest-rank"><?= $k + 1 ?></span>
              <span class="suggest-main"><span class="suggest-name"><?= metro_badges($s[1]) ?><?= e($s[0]) ?></span>
                <span class="meta">≈ <?= fmt_min($sg['mean']) ?> min in media · max <?= fmt_min($sg['max']) ?></span></span>
              <a class="linkbtn" href="<?= e(maps_search_url('ristoranti vicino metro ' . $s[0] . ', Roma')) ?>" target="_blank" rel="noopener noreferrer">Cerca locali <?= icon('arrow', 'tiny') ?></a>
            </li>
            <?php endforeach; ?>
          </ol>
          <p class="meta">Calcolato su <?= count($grp['people']) ?> person<?= count($grp['people']) === 1 ? 'a' : 'e' ?> <?= $grp['basis'] === 'going' ? 'che partecipano' : 'del gruppo' ?> e hanno indicato la zona<?= $grp['missing'] ? ' (' . $grp['missing'] . ' partecipant' . ($grp['missing'] === 1 ? 'e' : 'i') . ' senza zona)' : '' ?>.</p>
        </div>
        <?php endif; ?>
        <?php if ($me['zone_lat'] === null): ?>
        <div class="zone-cta">
          <span>Indica la tua zona: così il compromesso tiene conto anche di te.</span>
          <?= open_button('sheet-bio', 'Indica la zona', 'btn sm secondary', 'pin') ?>
        </div>
        <?php endif; ?>
        <?= open_button('propose-' . $eid, 'Vuoi proporre una location?', 'propose-btn', 'pin') ?>
        <?php sheet_start('propose-' . $eid, 'Proponi una location', $ev['title']); ?>
          <form method="post" action="action.php" class="form place-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="propose_place">
            <input type="hidden" name="event_id" value="<?= $eid ?>">
            <?php place_fields(); ?>
            <button class="btn">Allega la proposta</button>
          </form>
        <?php sheet_end(); ?>
        <?php endif; ?>
      </div>
    </article>
    <?php
}

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['sort' => $sort, 'cat' => $cat], $over))) . '#discussione';

page_start('Home');
intro(
    'Bentornato/a, ' . explode(' ', $me['name'])[0] . '.',
    '<a class="btn light" href="#eventi">Prossimi eventi</a> <a class="btn outline-light" href="#discussione">Vai alla discussione ' . icon('right') . '</a>'
);
?>

<!-- EVENTI -->
<section class="section" id="eventi">
  <div class="wrap">
    <?php section_head('Eventi', 'Ci vediamo lì.', 'Cene, incontri e occasioni per stare insieme. Segna la tua presenza e proponi dove andare.',
        open_button('sheet-event', 'Nuovo evento')); ?>
    <?php if (!$upcoming): ?><p class="empty">Nessun evento in programma. Proponine uno.</p><?php endif; ?>
    <div class="events">
      <?php foreach ($upcoming as $ev) event_card($ev, $uid, $places[(int)$ev['id']] ?? [], false, $dateOptions[(int)$ev['id']] ?? [], (int)($pollVoters[$ev['id']] ?? 0)); ?>
    </div>
    <?php if ($past): ?>
    <details class="past-events">
      <summary>Eventi passati <span class="count"><?= count($past) ?></span><?= icon('down') ?></summary>
      <div class="events"><?php foreach ($past as $ev) event_card($ev, $uid, $places[(int)$ev['id']] ?? [], true, $dateOptions[(int)$ev['id']] ?? [], (int)($pollVoters[$ev['id']] ?? 0)); ?></div>
    </details>
    <?php endif; ?>
  </div>
</section>

<!-- DISCUSSIONE -->
<section class="section" id="discussione">
  <div class="wrap">
    <?php section_head('Discussione', 'Parliamone.', 'Lavoro, concorsi, nuove posizioni, idee. Apri un topic e lascia che gli altri rispondano.',
        open_button('sheet-topic', 'Nuovo topic')); ?>
    <div class="toolbar">
      <div class="seg" role="tablist" aria-label="Ordina">
        <a class="<?= $sort === 'new' ? 'on' : '' ?>" href="<?= e($qs(['sort' => 'new'])) ?>">Recenti</a>
        <a class="<?= $sort === 'top' ? 'on' : '' ?>" href="<?= e($qs(['sort' => 'top'])) ?>">Più votati</a>
      </div>
      <div class="pills">
        <a class="pill<?= !$cat ? ' on' : '' ?>" href="<?= e($qs(['cat' => ''])) ?>">Tutti</a>
        <?php foreach (array_keys(categories()) as $c): ?>
        <a class="pill<?= $cat === $c ? ' on' : '' ?>" href="<?= e($qs(['cat' => $c])) ?>"><?= e($c) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (!$topics): ?><p class="empty">Ancora nessun topic<?= $cat ? ' in questa categoria' : '' ?>. Rompi il ghiaccio.</p><?php else: ?>
    <div class="panel topics">
      <?php foreach ($topics as $t): $tid = (int)$t['id']; ?>
      <article class="row topic">
        <?= vote_box('topic', $tid, (int)$t['score'], (int)$t['mine'], 'index.php' . $qs([])) ?>
        <a class="topic-link" href="topic.php?id=<?= $tid ?>">
          <span class="cat <?= category_class($t['category']) ?>"><?= e($t['category']) ?></span>
          <h3><?= e($t['title']) ?></h3>
          <?php if ($t['body']): ?><p class="excerpt"><?= e(mb_strimwidth($t['body'], 0, 200, '…')) ?></p><?php endif; ?>
          <span class="meta"><?= e($t['author']) ?> · <?= ago((int)$t['created_at']) ?></span>
        </a>
        <a class="comments-count" href="topic.php?id=<?= $tid ?>#commenti" aria-label="<?= (int)$t['n_comments'] ?> commenti"><?= icon('chat') ?><?= (int)$t['n_comments'] ?></a>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- MATERIALI -->
<section class="section" id="materiali">
  <div class="wrap">
    <?php section_head('Materiali', 'Tutto quello che vale la pena leggere.', 'Paper, slide, script R e Python, bandi. Carica quello che hai trovato utile.',
        open_button('sheet-upload', 'Carica un documento', 'btn', 'upload')); ?>
    <?php if (!$docs): ?><p class="empty">Nessun materiale caricato. Il primo file è il più importante.</p><?php endif; ?>
    <div class="grid docs">
      <?php foreach ($docs as $d): $ext = strtolower(pathinfo($d['original_name'], PATHINFO_EXTENSION)); ?>
      <article class="tile doc hoverable">
        <span class="fileicon" style="background:<?= file_tint($ext) ?>"><?= e(mb_strtoupper($ext)) ?></span>
        <h3><a href="download.php?id=<?= (int)$d['id'] ?>"><?= e($d['title']) ?></a></h3>
        <?php if ($d['description']): ?><p class="doc-desc"><?= rich($d['description']) ?></p><?php endif; ?>
        <p class="meta"><?= fmt_size((int)$d['size']) ?> · <?= e($d['author']) ?> · <?= ago((int)$d['created_at']) ?></p>
        <div class="doc-foot">
          <a class="btn sm secondary" href="download.php?id=<?= (int)$d['id'] ?>"><?= icon('download') ?><span>Scarica</span></a>
          <?php if (can_manage($d['user_id'])): ?><?= delete_button('delete_doc', (int)$d['id'], 'index.php#materiali') ?><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- MEMBRI -->
<section class="section" id="membri">
  <div class="wrap">
    <?php section_head('Membri', count($members) . ' colleghi, un solo codice.', 'Chi è entrato con noi in Ragioneria. Aggiungi due righe su di te.'); ?>
    <div class="grid members">
      <?php foreach ($members as $m): $isMe = (int)$m['id'] === $uid; ?>
      <article class="tile member<?= $isMe ? ' me' : '' ?>">
        <span class="avatar xl" style="background:<?= avatar_color($m['name']) ?>"><?= e(initials($m['name'])) ?></span>
        <h3><?= e($m['name']) ?></h3>
        <p class="member-tags"><?php if ($isMe): ?><span class="badge light">Tu</span><?php endif; ?><?php if ($m['is_admin']): ?><span class="badge">Admin</span><?php endif; ?></p>
        <?php if ($m['zone']): ?><p class="member-zone"><?= icon('pin') ?><?= e($m['zone']) ?></p><?php endif; ?>
        <p class="member-bio"><?= $m['bio'] ? e($m['bio']) : '<span class="muted">' . ($isMe ? 'Non hai ancora una presentazione.' : 'Nessuna presentazione.') . '</span>' ?></p>
        <dl class="stats">
          <div><dt>Topic</dt><dd><?= (int)$m['n_topics'] ?></dd></div>
          <div><dt>Commenti</dt><dd><?= (int)$m['n_comments'] ?></dd></div>
          <div><dt>File</dt><dd><?= (int)$m['n_docs'] ?></dd></div>
        </dl>
        <p class="meta">Dal <?= fmt_day((int)$m['created_at']) ?></p>
        <?php if ($isMe): ?><?= open_button('sheet-bio', $m['bio'] ? 'Modifica profilo' : 'Completa il profilo', 'btn sm secondary', 'edit') ?><?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php sheet_start('sheet-event', 'Nuovo evento', 'Eventi'); ?>
  <form method="post" action="action.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_event">
    <label>Titolo <input name="title" required maxlength="140" placeholder="Es. Aperitivo di fine mese"></label>
    <div class="row">
      <label>Data <input type="date" name="event_date" required min="<?= $today ?>"></label>
      <label>Ora <input type="time" name="event_time"></label>
    </div>
    <label>Luogo <small>Facoltativo. Potete anche proporlo dopo, tutti insieme.</small><input name="location" maxlength="140"></label>
    <label>Descrizione <textarea name="description" rows="3" maxlength="3000"></textarea></label>
    <button class="btn">Pubblica evento</button>
  </form>
<?php sheet_end(); ?>

<?php sheet_start('sheet-topic', 'Nuovo topic', 'Discussione'); ?>
  <form method="post" action="action.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_topic">
    <label>Titolo <input name="title" required maxlength="200"></label>
    <label>Categoria
      <select name="category"><?php foreach (array_keys(categories()) as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select>
    </label>
    <label>Testo <textarea name="body" rows="6" maxlength="10000" placeholder="Idee, domande, link a bandi o concorsi..."></textarea></label>
    <button class="btn">Pubblica</button>
  </form>
<?php sheet_end(); ?>

<?php sheet_start('sheet-upload', 'Carica un documento', 'Materiali'); ?>
  <form method="post" action="action.php" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <label>File <small>Massimo <?= MAX_UPLOAD_BYTES / 1048576 ?> MB.</small>
      <input type="file" name="file" required accept="<?= e(implode(',', array_map(fn($x) => '.' . $x, ALLOWED_EXT))) ?>">
    </label>
    <label>Titolo <input name="title" maxlength="200" placeholder="Se vuoto, uso il nome del file"></label>
    <label>Perché è interessante? <textarea name="description" rows="3" maxlength="1000"></textarea></label>
    <button class="btn">Carica</button>
  </form>
<?php sheet_end(); ?>

<?php sheet_start('sheet-bio', 'Il tuo profilo', 'Membri'); ?>
  <form method="post" action="action.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_bio">
    <input type="hidden" name="back" value="index.php#membri" data-back>
    <label>La tua zona <small>Quartiere o una via vicina, non serve l'indirizzo esatto. Se non sei di Roma, scrivi il comune. La vedono gli altri membri.</small>
      <input name="zone" maxlength="80" placeholder="Es. Pigneto, Monteverde, Frascati" value="<?= e($me['zone']) ?>">
    </label>
    <label>Due righe su di te <textarea name="bio" rows="4" maxlength="400" placeholder="Ufficio, temi di cui ti occupi, interessi..."><?= e($me['bio']) ?></textarea></label>
    <button class="btn">Salva</button>
  </form>
<?php sheet_end(); ?>

<?php sheet_start('voting-help', 'Come si sceglie il posto', 'Guida rapida'); ?>
  <ol class="steps">
    <li>
      <h3>Proponi</h3>
      <p>Su ogni evento in programma premi <b>Vuoi proporre una location?</b>, scrivi il nome del posto e controlla l'anteprima della mappa. Per essere precisi incolla il link "Condividi" di Google Maps. Puoi aggiungere una nota: prezzi, capienza, comodità.</p>
    </li>
    <li>
      <h3>Vota</h3>
      <p>Dai da 1 a 5 stelle a tutti i posti che vuoi, anche a quelli proposti da te. Puoi cambiare voto quando vuoi; se riclicchi lo stesso numero di stelle, il voto viene tolto.</p>
    </li>
    <li>
      <h3>Classifica</h3>
      <p>I posti non sono ordinati per media semplice, ma per un <b>punteggio ponderato</b> che premia quelli votati da più persone. È come se ogni posto partisse già con <?= RATING_PRIOR_WEIGHT ?> voti neutri da <?= (int)RATING_PRIOR_MEAN ?> stelle.</p>
      <p class="formula">punteggio = (<?= (int)RATING_PRIOR_MEAN ?> × <?= RATING_PRIOR_WEIGHT ?> + somma delle stelle) ÷ (<?= RATING_PRIOR_WEIGHT ?> + numero di voti)</p>
      <table class="mini-table">
        <thead><tr><th>Voti ricevuti</th><th>Media</th><th>Punteggio</th></tr></thead>
        <tbody>
          <tr><td>4 persone, 4 stelle</td><td>4,0</td><td><b><?= fmt_num(weighted_score(4, 16)) ?></b></td></tr>
          <tr><td>1 persona, 5 stelle</td><td>5,0</td><td><?= fmt_num(weighted_score(1, 5)) ?></td></tr>
          <tr><td>8 persone, 4 stelle</td><td>4,0</td><td><b><?= fmt_num(weighted_score(8, 32)) ?></b></td></tr>
          <tr><td>2 persone, 2 stelle</td><td>2,0</td><td><?= fmt_num(weighted_score(2, 4)) ?></td></tr>
        </tbody>
      </table>
      <p class="muted small">Un solo entusiasta non basta: per salire in cima serve il gradimento di molti. I posti ancora senza voti stanno in fondo.</p>
    </li>
    <li>
      <h3>Comodità</h3>
      <p>Ogni proposta mostra la <b>metro più vicina</b> e quanto è comoda per il gruppo: un tempo di viaggio stimato con i mezzi da ogni zona, la <b>media</b> e il <b>caso peggiore</b>. Il <b>miglior compromesso</b> è il posto con il valore più basso di ½ media + ½ massimo: così non vince un posto comodissimo per molti ma lontanissimo per qualcuno.</p>
      <p>Il calcolo usa chi ha detto "Partecipo" e ha indicato la zona nel profilo; se sono meno di due, usa tutti i membri con la zona. Le <b>zone più comode</b> sono le stazioni della metro dove questo valore è minimo: un buon punto da cui cercare un locale.</p>
      <p class="muted small">I tempi sono stime: a piedi, metro (attese e cambi inclusi) o bus/auto in città, con distanze in linea d'aria. Servono a confrontare le proposte, non a sostituire il navigatore.</p>
    </li>
    <li>
      <h3>Scelta</h3>
      <p>Chi ha creato l'evento, o un amministratore, preme <b>Scegli questo</b>: il posto diventa la location ufficiale, con il link a Maps in cima all'evento. La classifica è un'indicazione forte, ma la decisione finale spetta a chi organizza: conta anche la disponibilità del locale.</p>
    </li>
    <li>
      <h3>Modifiche</h3>
      <p>Chi ha proposto un posto può modificarlo. Se cambi solo nota o link, i voti restano; se cambi il nome del posto, i voti degli altri si azzerano, perché di fatto è una proposta nuova.</p>
    </li>
  </ol>
  <form method="dialog"><button class="btn wide">Ho capito</button></form>
<?php sheet_end(); ?>

<?php page_end();
