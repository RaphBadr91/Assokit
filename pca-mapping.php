<?php
/**
 * ASSOKIT — Plan Comptable Associatif
 * Mapping catégorie de facture -> poste comptable (60/61/62/63/64/68)
 * + numérotation partagée des pièces justificatives.
 * Utilisé par le bilan analytique ET le PDF des justificatifs
 * (numérotation cohérente entre les deux par construction).
 */

if (!function_exists('ak_pca_poste_label')) {
    function ak_pca_poste_label(string $code): string {
        $m = [
            '60' => '60 ACHATS',
            '61' => '61 SERVICES EXTERIEURS',
            '62' => '62 AUTRES SERVICES EXTERIEURS',
            '63' => '63 IMPOTS ET TAXES',
            '64' => '64 CHARGES DE PERSONNEL',
            '68' => '68 DOTATION AUX AMORTISSEMENTS',
        ];
        return $m[$code] ?? ($code . ' AUTRES');
    }
}

if (!function_exists('ak_pca_normalize')) {
    function ak_pca_normalize(?string $s): string {
        $s = trim((string)$s);
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }
}

if (!function_exists('ak_pca_table')) {
    function ak_pca_table(): array {
        // clé = catégorie normalisée ; valeur = [poste_code, compte, libellé du compte]
        return [
            // ---- 60 ACHATS ----
            'alimentation & boissons'       => ['60','60623','ALIMENTATION'],
            'alimentation'                  => ['60','60623','ALIMENTATION'],
            'matériel & équipement'         => ['60','60630','FOURN ENTRET PETIT EQUIPEMENT'],
            'matériel'                      => ['60','60630','FOURN ENTRET PETIT EQUIPEMENT'],
            'matériel audio'                => ['60','60630','FOURN ENTRET PETIT EQUIPEMENT'],
            'matériel vidéo'                => ['60','60630','FOURN ENTRET PETIT EQUIPEMENT'],
            'matériel informatique'         => ['60','60630','FOURN ENTRET PETIT EQUIPEMENT'],
            'sport'                         => ['60','60630','FOURN ENTRET PETIT EQUIPEMENT'],
            'fournitures'                   => ['60','60640','FOURNITURES ADMINISTRATIVES'],
            'livres / matériel pédagogique' => ['60','60680','FOURNITURES PEDAGOGIQUES'],
            'matériel pédagogique'          => ['60','60680','FOURNITURES PEDAGOGIQUES'],
            'livres'                        => ['60','60680','FOURNITURES PEDAGOGIQUES'],
            // ---- 61 SERVICES EXTERIEURS ----
            'loyer & charges'               => ['61','61320','LOCATIONS IMMOBILIERES'],
            'location'                      => ['61','61350','LOCATIONS MOBILIERES'],
            'location de salle/matériel'    => ['61','61350','LOCATIONS MOBILIERES'],
            'logiciels'                     => ['61','61350','LOCATIONS APPLI TECHNIQUES'],
            // ---- 62 AUTRES SERVICES EXTERIEURS ----
            'communication digitale'        => ['62','62300','PUBLICITE, COMMUNICATION'],
            'communication & impression'    => ['62','62300','PUBLICITE, COMMUNICATION'],
            'frais de déplacement'          => ['62','62510','VOYAGES ET DEPLACEMENTS'],
            'transport'                     => ['62','62510','VOYAGES ET DEPLACEMENTS'],
            'télécom'                       => ['62','62600','FRAIS DE TELECOMMUNICATION'],
            'services professionnels'       => ['62','62260','HONORAIRES'],
            'prestations'                   => ['62','62260','HONORAIRES'],
            'prestations externes'          => ['62','62260','HONORAIRES'],
            'formation & intervention'      => ['62','62260','HONORAIRES'],
            'frais administratifs'          => ['62','62270','FRAIS ADMINISTRATIFS'],
        ];
    }
}

if (!function_exists('ak_pca_comptes')) {
    /**
     * Liste canonique des comptes PCG selectionnables (reclassement manuel).
     * cle = code compte ; valeur = ['poste_code'=>.., 'label'=>..]
     * Derivee de ak_pca_table() (dedupliquee par code) + classes 63/64/68.
     */
    function ak_pca_comptes(): array {
        $out = [];
        foreach (ak_pca_table() as $row) {
            [$pc,$compte,$lib] = $row;
            if (!isset($out[$compte])) $out[$compte] = ['poste_code'=>$pc, 'label'=>$lib];
        }
        $extra = [
            '63500' => ['63','IMPOTS ET TAXES'],
            '64000' => ['64','CHARGES DE PERSONNEL'],
            '68110' => ['68','DOTATIONS AUX AMORTISSEMENTS'],
        ];
        foreach ($extra as $code=>$d) {
            if (!isset($out[$code])) $out[$code] = ['poste_code'=>$d[0], 'label'=>$d[1]];
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}

if (!function_exists('ak_pca_map')) {
    function ak_pca_map(?string $category, ?string $override = null): array {
        $ov = trim((string)$override);
        if ($ov !== '') {
            $comptes = ak_pca_comptes();
            if (isset($comptes[$ov])) {
                $pc = $comptes[$ov]['poste_code'];
                return ['poste_code'=>$pc,'poste'=>ak_pca_poste_label($pc),'compte'=>$ov,'libelle'=>$comptes[$ov]['label'],'unmapped'=>false,'overridden'=>true];
            }
        }
        $key = ak_pca_normalize($category);
        $t = ak_pca_table();
        if ($key !== '' && isset($t[$key])) {
            [$pc,$compte,$lib] = $t[$key];
            return ['poste_code'=>$pc,'poste'=>ak_pca_poste_label($pc),'compte'=>$compte,'libelle'=>$lib,'unmapped'=>false,'overridden'=>false];
        }
        return ['poste_code'=>'60','poste'=>ak_pca_poste_label('60'),'compte'=>'60680','libelle'=>'AUTRES ACHATS','unmapped'=>true,'overridden'=>false];
    }
}

if (!function_exists('ak_pca_contrepartie')) {
    function ak_pca_contrepartie(?string $supplier): string {
        $s = function_exists('mb_strtoupper') ? mb_strtoupper((string)$supplier,'UTF-8') : strtoupper((string)$supplier);
        $s = strtr($s, ['É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','À'=>'A','Â'=>'A','Ä'=>'A','Ç'=>'C','Ô'=>'O','Ö'=>'O','Û'=>'U','Ü'=>'U','Î'=>'I','Ï'=>'I','Ù'=>'U']);
        $s = preg_replace('/[^A-Z0-9]/', '', $s);
        $code = substr($s, 0, 3);
        return '401' . ($code !== '' ? $code : 'DIV');
    }
}

if (!function_exists('ak_invoice_dossier')) {
    /**
     * Factures d'un projet triées (compte -> date -> id), numérotées (1..N),
     * regroupées par poste, avec totaux. Débit = TTC.
     */
    function ak_invoice_dossier(PDO $pdo, int $project_id, bool $validated_only = true): array {
        $sql = "SELECT id, project_id, supplier_name, category, account_override, description,
                       amount_ht, vat_amount, amount_ttc, invoice_date, status,
                       COALESCE(NULLIF(filepath,''), file_path) AS photo
                FROM project_invoices
                WHERE project_id = ?";
        if ($validated_only) $sql .= " AND status = 'validated'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$project_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $map = ak_pca_map($r['category'], $r['account_override'] ?? null);
            $r['poste_code']   = $map['poste_code'];
            $r['poste']        = $map['poste'];
            $r['compte']       = $map['compte'];
            $r['compte_lib']   = $map['libelle'];
            $r['unmapped']     = $map['unmapped'];
            $r['overridden']   = !empty($map['overridden']);
            $r['contrepartie'] = ak_pca_contrepartie($r['supplier_name']);
            $r['montant']      = (float)$r['amount_ttc'];
        }
        unset($r);

        usort($rows, function($a, $b) {
            $c = strcmp((string)$a['compte'], (string)$b['compte']);
            if ($c !== 0) return $c;
            $c = strcmp((string)($a['invoice_date'] ?? ''), (string)($b['invoice_date'] ?? ''));
            if ($c !== 0) return $c;
            return $a['id'] <=> $b['id'];
        });

        $postes = []; $total = 0.0; $n = 0;
        foreach ($rows as &$r) {
            $r['piece'] = ++$n;
            $total += $r['montant'];
            $pc = $r['poste_code'];
            if (!isset($postes[$pc])) $postes[$pc] = ['code'=>$pc,'label'=>$r['poste'],'lines'=>[],'total'=>0.0];
            $postes[$pc]['lines'][] = $r;
            $postes[$pc]['total'] += $r['montant'];
        }
        unset($r);
        ksort($postes, SORT_STRING);

        return ['lines'=>$rows, 'postes'=>$postes, 'total'=>$total, 'count'=>$n];
    }
}
