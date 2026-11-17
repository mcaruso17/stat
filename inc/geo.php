<?php
declare(strict_types=1);

/*
 * Geografia per le location: metro più vicina, tempi di viaggio stimati,
 * punteggio di compromesso e zone suggerite.
 *
 * Il modello dei tempi è volutamente semplice e trasparente: non è un
 * navigatore, ma basta per confrontare le proposte tra loro.
 */

const METRO_COLORS = ['A' => '#e8742c', 'B' => '#2563eb', 'B1' => '#2563eb', 'C' => '#16a34a'];

// Parametri del modello dei tempi (minuti, km/h).
const WALK_KMH = 4.8;          // passo medio
const WALK_DETOUR = 1.3;       // le strade non sono in linea d'aria
const METRO_KMH = 30;          // velocità commerciale media, fermate incluse
const METRO_WAIT = 4;          // attesa media in banchina
const METRO_TRANSFER = 6;      // cambio linea (Termini, San Giovanni, Bologna, Colosseo)
const METRO_ACCESS = 2;        // scale e tornelli, a ogni ingresso/uscita
const SURFACE_KMH = 16;        // bus/auto in città, traffico incluso
const SURFACE_WAIT = 10;       // attesa bus o ricerca parcheggio

// Punteggio di compromesso: media e caso peggiore pesano uguale.
const COMPROMISE_W_MEAN = 0.5;
const COMPROMISE_W_MAX = 0.5;

function metro_stations(): array
{
    static $st = null;
    return $st ??= require __DIR__ . '/metro_roma.php';
}

function km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $x = deg2rad($lon2 - $lon1) * cos(deg2rad(($lat1 + $lat2) / 2));
    $y = deg2rad($lat2 - $lat1);
    return 6371 * sqrt($x * $x + $y * $y);
}

function walk_min(float $km): float
{
    return $km * WALK_DETOUR / WALK_KMH * 60;
}

/** Le $k stazioni più vicine: [[stazione, km], ...]. */
function nearest_stations(float $lat, float $lon, int $k = 1): array
{
    $all = [];
    foreach (metro_stations() as $s) {
        $all[] = [$s, km($lat, $lon, $s[2], $s[3])];
    }
    usort($all, fn($a, $b) => $a[1] <=> $b[1]);
    return array_slice($all, 0, $k);
}

/** Tempo stimato in minuti da A a B: il migliore tra a piedi, bus/auto, metro. */
function travel_min(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $d = km($lat1, $lon1, $lat2, $lon2);
    // Bus/auto: in città 16 km/h; oltre gli 8 km (fuori dal centro) si va più veloci.
    $surface = (min($d, 8) * 1.35 / SURFACE_KMH + max(0, $d - 8) * 1.3 / 35) * 60 + SURFACE_WAIT;
    $best = min(walk_min($d), $surface);
    foreach (nearest_stations($lat1, $lon1, 3) as [$s1, $k1]) {
        foreach (nearest_stations($lat2, $lon2, 3) as [$s2, $k2]) {
            $ride = km($s1[2], $s1[3], $s2[2], $s2[3]) * 1.2 / METRO_KMH * 60;
            $change = array_intersect($s1[1], $s2[1]) ? 0 : METRO_TRANSFER;
            $t = walk_min($k1) + walk_min($k2) + 2 * METRO_ACCESS + METRO_WAIT + $ride + $change;
            $best = min($best, $t);
        }
    }
    return $best;
}

/**
 * Compromesso per un punto rispetto a un gruppo di persone [[lat, lon], ...].
 * Restituisce media, massimo e costo (più basso = migliore).
 */
function compromise(array $people, float $lat, float $lon): ?array
{
    if (!$people) return null;
    $t = array_map(fn($p) => travel_min($p[0], $p[1], $lat, $lon), $people);
    $mean = array_sum($t) / count($t);
    $max = max($t);
    return ['mean' => $mean, 'max' => $max, 'cost' => COMPROMISE_W_MEAN * $mean + COMPROMISE_W_MAX * $max];
}

/** Le stazioni della metro più comode per il gruppo, distanti almeno 1,2 km tra loro. */
function suggest_points(array $people, int $n = 3): array
{
    if (count($people) < 2) return [];
    $rank = [];
    foreach (metro_stations() as $s) {
        $c = compromise($people, $s[2], $s[3]);
        $rank[] = ['station' => $s] + $c;
    }
    usort($rank, fn($a, $b) => $a['cost'] <=> $b['cost']);
    $out = [];
    foreach ($rank as $r) {
        foreach ($out as $o) {
            if (km($o['station'][2], $o['station'][3], $r['station'][2], $r['station'][3]) < 1.2) continue 2;
        }
        $out[] = $r;
        if (count($out) === $n) break;
    }
    return $out;
}

function fmt_min(float $m): string
{
    return (string)max(1, (int)round($m));
}

function fmt_km(float $km): string
{
    $m = (int)(round($km * 100) * 10);
    return $m < 1000 ? $m . ' m' : number_format($km, 1, ',', '') . ' km';
}

/** Badge di linea metro (A, B, B1, C). */
function metro_badges(array $lines): string
{
    $lines = in_array('B', $lines, true) ? array_values(array_diff($lines, ['B1'])) : $lines;
    $out = '';
    foreach ($lines as $l) {
        $out .= '<span class="mline" style="background:' . METRO_COLORS[$l] . '">' . e($l) . '</span>';
    }
    return $out;
}

// ---------- Geocoding (OpenStreetMap via Photon, nessuna chiave) ----------

function http_get(string $url, int $timeout = 4, bool $followOnly = false): ?string
{
    $ua = 'codice-stat/1.0 (sito interno tra colleghi)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_USERAGENT => $ua,
            CURLOPT_NOBODY => $followOnly,
        ]);
        $body = curl_exec($ch);
        $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($followOnly) return $final ?: null;
        return ($body !== false && $code < 400) ? $body : null;
    }
    if ($followOnly) return null;
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: $ua\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/** Candidati Photon (OpenStreetMap) nell'area di Roma, vicini al centro per primi. */
function photon_search(string $query, ?string $osmTag = null, int $limit = 8): array
{
    // Niente parametro lingua: Photon restituisce già i nomi locali (e "it" non è supportato).
    $params = ['q' => $query, 'limit' => $limit, 'bbox' => '11.9,41.4,13.2,42.3', 'lat' => 41.893, 'lon' => 12.483];
    if ($osmTag) $params['osm_tag'] = $osmTag;
    $json = http_get('https://photon.komoot.io/api/?' . http_build_query($params));
    return $json ? (json_decode($json, true)['features'] ?? []) : [];
}

function photon_result(array $f): array
{
    [$lon, $lat] = $f['geometry']['coordinates'];
    $p = $f['properties'];
    $label = implode(', ', array_unique(array_filter([$p['name'] ?? null, $p['locality'] ?? $p['district'] ?? null, $p['city'] ?? null])));
    return [(float)$lat, (float)$lon, $label];
}

/** Cerca un luogo (locale, indirizzo) nell'area di Roma: [lat, lon, etichetta] oppure null. */
function geocode(string $query): ?array
{
    $query = trim($query);
    if ($query === '') return null;
    $features = photon_search($query);
    // Un risultato a Roma ha la precedenza su un omonimo fuori città.
    foreach ($features as $f) {
        if (($f['properties']['city'] ?? '') === 'Roma') return photon_result($f);
    }
    if ($features) return photon_result($features[0]);
    // Riserva: Nominatim (OpenStreetMap), stessa area.
    $json = http_get('https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $query, 'format' => 'json', 'limit' => 1, 'accept-language' => 'it',
        'viewbox' => '11.9,42.3,13.2,41.4', 'bounded' => 1,
    ]));
    $r = $json ? (json_decode($json, true)[0] ?? null) : null;
    return $r ? [(float)$r['lat'], (float)$r['lon'], implode(', ', array_slice(explode(', ', $r['display_name']), 0, 3))] : null;
}

/**
 * Zona di residenza (quartiere o comune). Tra i candidati vince chi ha il nome
 * uguale a quello scritto, è un luogo (quartiere, comune) e si trova a Roma:
 * così "Prati" è il quartiere di Roma e "Frascati" il comune, non Via Frascati.
 */
function geocode_zone(string $zone): ?array
{
    $norm = fn(string $s) => preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s));
    $q = $norm($zone);
    if ($q === '') return null;
    $best = null;
    $bestScore = -1;
    foreach (array_merge(photon_search($zone, 'place', 5), photon_search($zone, null, 10)) as $i => $f) {
        $p = $f['properties'];
        $name = $norm($p['name'] ?? '');
        $score = ($name === $q ? 4 : (str_starts_with($name, $q) ? 2 : 0))
            + (($p['osm_key'] ?? '') === 'place' ? 3 : 0)
            + (($p['city'] ?? '') === 'Roma' ? 1.5 : 0)
            - $i * 0.01;
        if ($score > $bestScore) { $bestScore = $score; $best = $f; }
    }
    if ($best && $bestScore >= 1) return photon_result($best);
    return geocode($zone);
}

/** Coordinate dentro un link Google Maps (anche brevi maps.app.goo.gl, seguendo il redirect). */
function coords_from_maps_url(string $url): ?array
{
    if ($url === '') return null;
    if (preg_match('~^https://(maps\.app\.goo\.gl|goo\.gl)/~', $url)) {
        $url = http_get($url, 5, true) ?? $url;
    }
    $url = urldecode($url);
    foreach (['~!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)~', '~@(-?\d+\.\d+),(-?\d+\.\d+)~', '~[?&](?:q|query|ll)=(-?\d+\.\d+),\s*(-?\d+\.\d+)~'] as $re) {
        if (preg_match($re, $url, $m)) return [(float)$m[1], (float)$m[2]];
    }
    return null;
}

/** Posizione di una location proposta: prima dal link Maps, poi indirizzo, poi nome. */
function locate_place(string $name, string $address, string $mapsUrl): ?array
{
    if ($c = coords_from_maps_url($mapsUrl)) return $c;
    foreach (array_filter([$address ? "$address Roma" : '', "$name Roma", $name]) as $q) {
        if ($g = geocode($q)) return [$g[0], $g[1]];
    }
    return null;
}
