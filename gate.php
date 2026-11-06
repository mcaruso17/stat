<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user()) redirect('index.php');
if (!empty($_SESSION['quiz_passed'])) redirect('auth.php?mode=register');

$error = null;
$given = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $correct = 0;
    foreach (QUIZ as $i => $q) {
        $a = $_POST['q' . $i] ?? null;
        $given[$i] = $a === null ? null : (int)$a;
        if ($a !== null && (int)$a === $q['answer']) $correct++;
    }
    if ($correct >= QUIZ_MIN_CORRECT) {
        session_regenerate_id(true);
        $_SESSION['quiz_passed'] = true;
        flash("Benvenuto/a, $correct su 3. Ora crea il tuo account.");
        redirect('auth.php?mode=register');
    }
    $error = "Solo $correct risposta" . ($correct === 1 ? '' : 'e') . " giusta su 3. Ne servono almeno " . QUIZ_MIN_CORRECT . ". Riprova.";
}

page_start('Accesso', false);
?>
<section class="hero hero-gate">
  <div class="wrap hero-grid">
    <div>
      <p class="kicker">Area riservata</p>
      <h1>Per assicurarci di avere qui solo codice Stat, rispondi alle seguenti domande</h1>
      <p class="lead">Ne bastano due giuste su tre. Poi ti registri con nome e password e sei dentro.</p>
      <p class="muted">Hai già un account? <a href="auth.php?mode=login">Accedi</a></p>
    </div>
    <div class="shapes" aria-hidden="true">
      <span class="shape tri"></span><span class="shape half"></span><span class="shape dot"></span>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap narrow">
    <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="quiz">
      <?= csrf_field() ?>
      <?php foreach (QUIZ as $i => $q): ?>
      <fieldset class="card quiz-q">
        <legend><span class="qnum"><?= $i + 1 ?></span><?= e($q['q']) ?></legend>
        <div class="options">
          <?php foreach ($q['options'] as $j => $opt): ?>
          <label class="option">
            <input type="radio" name="q<?= $i ?>" value="<?= $j ?>" required <?= ($given[$i] ?? null) === $j ? 'checked' : '' ?>>
            <span><?= e($opt) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <?php endforeach; ?>
      <button class="btn big">Verifica le risposte</button>
    </form>
  </div>
</section>
<?php page_end();
