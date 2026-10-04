<?php
/**
 * demo-formation/lib.php — Outils du seeder de la démo « DEMO F ».
 * ------------------------------------------------------------------
 * La démo est reconstruite chaque nuit sur la base de production. On ne
 * connaît donc pas son schéma exact au moment d'écrire ce code : des
 * colonnes s'ajoutent au fil des migrations, d'autres n'existent que sur
 * certaines installations. D'où trois règles :
 *
 *   1. On n'insère jamais à l'aveugle. df_ins() lit le schéma réel
 *      (information_schema) et ignore les colonnes absentes.
 *   2. On ne devine pas une valeur d'ENUM. Une valeur refusée par la
 *      colonne est retirée de la ligne (la colonne prend sa valeur par
 *      défaut) et signalée dans le rapport — plutôt qu'enregistrée vide
 *      en silence, ce que ferait MariaDB hors mode strict.
 *   3. On ne supprime que ce qui appartient à l'org « demo-formation »,
 *      et seulement dans des tables nommées une à une (voir purge.php).
 *
 * Tout ce qui est aléatoire est tiré d'un générateur à graine fixe : la
 * démo est la même chaque nuit, seules les dates glissent avec le
 * calendrier pour qu'elle ne vieillisse jamais.
 * ------------------------------------------------------------------
 */

if (defined('DF_LIB')) return;
define('DF_LIB', 1);

/** Identifiant technique de l'association de démo. Ne jamais changer. */
const DF_SLUG = 'demo-formation';

/**
 * Domaine de TOUTES les adresses de la démo (comptes de connexion compris).
 * Sous-domaine d'assokit.fr sans boîte aux lettres : rien de ce qui part
 * vers lui ne peut atteindre un tiers. resend-helper.php et sms-helper.php
 * refusent en plus tout envoi vers lui (voir df_est_adresse_demo()).
 */
const DF_DOMAINE = 'demo-f.assokit.fr';

/** Mot de passe commun aux comptes de connexion de la démo. */
const DF_MOT_DE_PASSE = 'DemoFormation2026!';

/** Graine du générateur : changer la valeur change toute la distribution. */
const DF_GRAINE = 20261004;

final class DF
{
    public static PDO $pdo;
    public static int $org = 0;
    /** Personnages nommés : clé => id utilisateur. */
    public static array $u = [];
    /** Groupes de personnes : 'salaries', 'benevoles', 'adherents', 'bureau'… => [ids]. */
    public static array $g = [];
    /** Registre libre que les modules se passent : DF::$ids['projets']['fle'] = 42. */
    public static array $ids = [];
    /** Minuit, aujourd'hui, en timestamp local. */
    public static int $t0 = 0;
    /** Fonction de journalisation fournie par l'appelant. */
    public static $log = null;
    public static array $rapport = [
        'inseres'   => [],   // table => nombre de lignes
        'tables_absentes' => [],
        'colonnes_ignorees' => [], // table => [colonnes]
        'enum'      => [],   // messages
        'erreurs'   => [],   // messages
        'modules'   => [],   // module => 'ok' | message d'erreur
    ];
    private static array $schema = [];
    private static array $tables = [];
    private static array $stmts = [];

    public static function reset_caches(): void
    {
        self::$schema = [];
        self::$tables = [];
        self::$stmts = [];
    }

    public static function table_existe(string $t): bool
    {
        if (!array_key_exists($t, self::$tables)) {
            $s = self::$pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $s->execute([$t]);
            self::$tables[$t] = ((int)$s->fetchColumn() > 0);
        }
        return self::$tables[$t];
    }

    /**
     * Colonnes réelles d'une table : nom => [type, column_type, null, defaut, auto, enum].
     * null si la table n'existe pas.
     */
    public static function colonnes(string $t): ?array
    {
        if (array_key_exists($t, self::$schema)) return self::$schema[$t];
        if (!self::table_existe($t)) return self::$schema[$t] = null;
        $s = self::$pdo->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION"
        );
        $s->execute([$t]);
        $cols = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $enum = null;
            if (in_array(strtolower($c['DATA_TYPE']), ['enum', 'set'], true)
                && preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $c['COLUMN_TYPE'], $m)) {
                $enum = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
            }
            $cols[$c['COLUMN_NAME']] = [
                'type'   => strtolower($c['DATA_TYPE']),
                'ctype'  => strtolower($c['COLUMN_TYPE']),
                'null'   => ($c['IS_NULLABLE'] === 'YES'),
                // MariaDB renvoie la chaîne 'NULL' pour « pas de défaut » sur une colonne nullable.
                'defaut' => $c['COLUMN_DEFAULT'],
                'auto'   => (stripos((string)$c['EXTRA'], 'auto_increment') !== false),
                'genere' => (stripos((string)$c['EXTRA'], 'generated') !== false
                             || stripos((string)$c['EXTRA'], 'virtual') !== false
                             || stripos((string)$c['EXTRA'], 'persistent') !== false
                             || stripos((string)$c['EXTRA'], 'stored') !== false),
                'enum'   => $enum,
            ];
        }
        return self::$schema[$t] = $cols;
    }

    public static function prep(string $sql): PDOStatement
    {
        return self::$stmts[$sql] ??= self::$pdo->prepare($sql);
    }
}

function df_log(string $m): void
{
    if (is_callable(DF::$log)) (DF::$log)($m);
}

function df_erreur(string $m): void
{
    DF::$rapport['erreurs'][] = $m;
    df_log('   ! ' . $m);
}

function df_a_table(string $t): bool
{
    return DF::table_existe($t);
}

function df_a_colonne(string $t, string $c): bool
{
    $cols = DF::colonnes($t);
    return $cols !== null && isset($cols[$c]);
}

/** Valeur de remplissage pour une colonne NOT NULL sans défaut qu'on n'a pas fournie. */
function df_valeur_neutre(array $col)
{
    if (!empty($col['enum'])) return $col['enum'][0];
    switch ($col['type']) {
        case 'tinyint': case 'smallint': case 'mediumint': case 'int': case 'integer':
        case 'bigint': case 'decimal': case 'float': case 'double': case 'bit': case 'year':
            return 0;
        case 'date':
            return date('Y-m-d', DF::$t0);
        case 'datetime': case 'timestamp':
            return date('Y-m-d H:i:s');
        case 'time':
            return '00:00:00';
        default:
            return '';
    }
}

/**
 * Prépare une ligne pour une table : retire les colonnes absentes, valide
 * les ENUM, complète les NOT NULL sans défaut. null si la table n'existe pas.
 */
function df_ligne(string $t, array $row): ?array
{
    $cols = DF::colonnes($t);
    if ($cols === null) {
        DF::$rapport['tables_absentes'][$t] = true;
        return null;
    }
    $out = [];
    foreach ($row as $k => $v) {
        if (!isset($cols[$k])) {
            DF::$rapport['colonnes_ignorees'][$t][$k] = true;
            continue;
        }
        $c = $cols[$k];
        if ($c['genere']) continue;
        if (is_bool($v)) $v = $v ? 1 : 0;
        if ($v instanceof DateTimeInterface) $v = $v->format('Y-m-d H:i:s');
        if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($v !== null && !empty($c['enum']) && $c['type'] === 'enum') {
            $ok = null;
            foreach ($c['enum'] as $e) {
                if ($e === (string)$v) { $ok = $e; break; }
            }
            if ($ok === null) {
                foreach ($c['enum'] as $e) {
                    if (strcasecmp($e, (string)$v) === 0) { $ok = $e; break; }
                }
            }
            if ($ok === null) {
                $msg = "$t.$k : « $v » n'est pas une valeur permise (" . implode(', ', $c['enum']) . ')';
                DF::$rapport['enum'][$msg] = true;
                continue;
            }
            $v = $ok;
        }
        if ($v === null && !$c['null']) {
            // NULL refusé : on laisse la base appliquer son défaut, sinon valeur neutre.
            if ($c['defaut'] !== null && strtoupper((string)$c['defaut']) !== 'NULL') continue;
            if ($c['auto']) continue;
            $v = df_valeur_neutre($c);
        }
        $out[$k] = $v;
    }
    foreach ($cols as $k => $c) {
        if (array_key_exists($k, $out) || $c['null'] || $c['auto'] || $c['genere']) continue;
        $sansDefaut = ($c['defaut'] === null || strtoupper((string)$c['defaut']) === 'NULL');
        if ($sansDefaut) $out[$k] = df_valeur_neutre($c);
    }
    return $out;
}

/**
 * Insère une ligne en s'adaptant au schéma réel. Renvoie l'id inséré
 * (ou 0 si la table n'a pas d'auto-incrément), null en cas d'échec.
 */
function df_ins(string $t, array $row): ?int
{
    $l = df_ligne($t, $row);
    if ($l === null) return null;
    if (!$l) { df_erreur("$t : aucune colonne exploitable"); return null; }
    $cols = array_keys($l);
    $sql = 'INSERT INTO `' . $t . '` (`' . implode('`,`', $cols) . '`) VALUES ('
         . implode(',', array_fill(0, count($cols), '?')) . ')';
    try {
        DF::prep($sql)->execute(array_values($l));
        DF::$rapport['inseres'][$t] = (DF::$rapport['inseres'][$t] ?? 0) + 1;
        return (int)DF::$pdo->lastInsertId();
    } catch (Throwable $e) {
        df_erreur("$t : " . substr($e->getMessage(), 0, 220));
        return null;
    }
}

/** Met à jour des colonnes (celles qui existent) d'une table. */
function df_maj(string $t, array $set, string $where, array $params = []): int
{
    $cols = DF::colonnes($t);
    if ($cols === null) { DF::$rapport['tables_absentes'][$t] = true; return 0; }
    $l = [];
    foreach ($set as $k => $v) {
        if (!isset($cols[$k])) { DF::$rapport['colonnes_ignorees'][$t][$k] = true; continue; }
        $tmp = df_ligne($t, [$k => $v]);
        if ($tmp !== null && array_key_exists($k, $tmp)) $l[$k] = $tmp[$k];
    }
    if (!$l) return 0;
    $sql = 'UPDATE `' . $t . '` SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($l)))
         . ' WHERE ' . $where;
    try {
        $s = DF::$pdo->prepare($sql);
        $s->execute(array_merge(array_values($l), $params));
        return $s->rowCount();
    } catch (Throwable $e) {
        df_erreur("maj $t : " . substr($e->getMessage(), 0, 220));
        return 0;
    }
}

/** Requête libre, erreurs consignées. */
function df_sql(string $sql, array $p = []): ?PDOStatement
{
    try {
        $s = DF::$pdo->prepare($sql);
        $s->execute($p);
        return $s;
    } catch (Throwable $e) {
        df_erreur('sql : ' . substr($e->getMessage(), 0, 220));
        return null;
    }
}

function df_val(string $sql, array $p = [])
{
    $s = df_sql($sql, $p);
    return $s ? $s->fetchColumn() : null;
}

// ------------------------------------------------------------------
// Dates — toujours relatives à aujourd'hui, pour que la démo ne vieillisse pas
// ------------------------------------------------------------------

/** Date 'Y-m-d' à $j jours d'aujourd'hui (négatif = passé). */
function df_j(int $j): string
{
    return date('Y-m-d', strtotime(($j >= 0 ? '+' : '') . $j . ' days', DF::$t0));
}

/** Date-heure 'Y-m-d H:i:s' à $j jours, à l'heure donnée ('14:30'). */
function df_jh(int $j, string $h = '10:00'): string
{
    return df_j($j) . ' ' . (strlen($h) === 5 ? $h . ':00' : $h);
}

/** Premier jour du mois décalé de $m mois ('Y-m-01'), + $jour - 1 jours. */
function df_mois(int $m, int $jour = 1): string
{
    $base = strtotime(date('Y-m-01', DF::$t0));
    $ts = strtotime(($m >= 0 ? '+' : '') . $m . ' months', $base);
    $max = (int)date('t', $ts);
    return date('Y-m-', $ts) . str_pad((string)min(max(1, $jour), $max), 2, '0', STR_PAD_LEFT);
}

/** Année civile courante (int). */
function df_annee(int $decalage = 0): int
{
    return (int)date('Y', DF::$t0) + $decalage;
}

/** Jour ouvré le plus proche (on ne planifie pas une formation un dimanche). */
function df_ouvre(int $j): int
{
    $w = (int)date('N', strtotime(df_j($j)));
    if ($w === 6) return $j + 2;
    if ($w === 7) return $j + 1;
    return $j;
}

// ------------------------------------------------------------------
// Hasard reproductible
// ------------------------------------------------------------------

function df_graine(int $sel = 0): void
{
    mt_srand(DF_GRAINE + $sel);
}

function df_entre(int $a, int $b): int
{
    return mt_rand($a, $b);
}

function df_proba(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}

function df_choix(array $a)
{
    $a = array_values($a);
    return $a[mt_rand(0, max(0, count($a) - 1))];
}

/** $n éléments distincts de $a (ordre stable pour une graine donnée). */
function df_echantillon(array $a, int $n): array
{
    $a = array_values($a);
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
    }
    return array_slice($a, 0, max(0, min($n, count($a))));
}

// ------------------------------------------------------------------
// Petites aides métier
// ------------------------------------------------------------------

/** Adresse de démo à partir d'un prénom et d'un nom. */
function df_email(string $prenom, string $nom, string $suffixe = ''): string
{
    $s = strtolower(df_ascii($prenom) . '.' . df_ascii($nom));
    $s = preg_replace('/[^a-z0-9.]+/', '', $s);
    return $s . $suffixe . '@' . DF_DOMAINE;
}

function df_ascii(string $s): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t === false || $t === '') {
        $t = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a','î'=>'i','ï'=>'i',
                         'ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','É'=>'E','È'=>'E','Ç'=>'C',
                         'Î'=>'I','Ô'=>'O','À'=>'A','œ'=>'oe','Œ'=>'OE']);
    }
    return preg_replace('/[^A-Za-z0-9 \-]/', '', $t);
}

/**
 * Numéro de téléphone FICTIF : tranches réservées par l'ARCEP à la fiction
 * (01 99 00 xx xx, 06 39 98 xx xx). Aucun SMS ne peut joindre une vraie personne.
 */
function df_tel(int $n, bool $mobile = true): string
{
    $n = abs($n) % 9900 + 100;
    $a = str_pad((string)intdiv($n, 100), 2, '0', STR_PAD_LEFT);
    $b = str_pad((string)($n % 100), 2, '0', STR_PAD_LEFT);
    return ($mobile ? '06 39 98 ' : '01 99 00 ') . $a . ' ' . $b;
}

/** Arrondi monétaire. */
function df_eur(float $v): float
{
    return round($v, 2);
}

/** Vrai si l'adresse appartient au domaine réservé de la démo. */
function df_est_adresse_demo(?string $email): bool
{
    $email = strtolower(trim((string)$email));
    return $email !== '' && str_ends_with($email, '@' . DF_DOMAINE);
}

/** Retient un id dans le registre partagé entre modules. */
function df_retenir(string $groupe, string $cle, ?int $id): ?int
{
    if ($id) DF::$ids[$groupe][$cle] = $id;
    return $id;
}

function df_id(string $groupe, string $cle): ?int
{
    return DF::$ids[$groupe][$cle] ?? null;
}
