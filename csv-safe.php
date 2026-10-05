<?php
/**
 * csv-safe.php — neutralise les « formules » dans les exports CSV.
 *
 * Une cellule qui commence par = + - @ (ou tabulation / retour chariot) est
 * interprétée comme une formule par Excel ou LibreOffice à l'ouverture
 * (ex. un nom saisi « =HYPERLINK(...) » sur la page publique d'émargement).
 * On la préfixe d'une apostrophe, comme le recommande l'OWASP.
 */
if (!function_exists('ak_csv_safe')) {
    function ak_csv_safe($row) {
        $one = static function ($v) {
            if (!is_string($v) || $v === '') return $v;
            if (preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^[+-]?[\d\s.,()]+$/', $v)) return "'" . $v;
            return $v;
        };
        return is_array($row) ? array_map($one, $row) : $one($row);
    }
}
