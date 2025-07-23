<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>CERTAINTY(q)–AlgorithmeQuadAttack</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container py-4">

<?php
/*=====================================================================
  Implémentation de l’algorithme QUADATTACK – version clé composée
  Source  : J.Wijsen, «Certain Conjunctive Query Answering in First Order
  Fonction : Teste si CERTAINTY(q) est exprimable en logique du premier ordre
             pour une requête conjonctive booléenne sans auto jointure.
             Clés primaires simples ou composées autorisées.
=====================================================================*/

/*------------------------------------------------------------
  1. Routage (submit ➜ QuadAttack)
------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = $_POST['query'] ?? '';
        $atoms = parseAtoms($input);
		$cycleStr ="";

if (!hasAcyclicJoinTree($atoms)) {
    $cycle = findIntersectionCycle($atoms);
    if ($cycle) {
        // Prépare une chaîne lisible des atomes impliqués
        $cycleNames = [];
        foreach ($cycle as $i) $cycleNames[] = $atoms[$i]['rel'];
        // Boucle le cycle pour la lisibilité
        $cycleStr = implode(' → ', $cycleNames) . ' → ' . $cycleNames[0];
        $cycleStr =
            "La requête ".$input." est cyclique (au sens de Beeri et al., 1983).<br>
			Pas de join tree donc pas exprimable en FO.<br>" .
            "Exemple de cycle dans la requête<br>" .
            "<div class=\"alert alert-warning mt-2\"><strong>$cycleStr</strong></div>"
        ;
    } else {
        throw new Exception("La requête ".$input." n’est pas acyclique (au sens de Beeri et al., 1983).");
    }
}


	if($cycleStr !=""){
		echo $cycleStr;
		echo formHTML();
	}else{
        // — QuadAttack —
        [$edges, $isFO,$closed_atoms] = quadAttack($atoms);
        $formula = $isFO ? makeFO($atoms) : null;

        echo render($input, $atoms, $isFO, $formula, $edges,$closed_atoms );
		}
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">'.htmlentities($e->getMessage()).'</div>';
        echo formHTML();
    }
} else {
    echo formHTML();
}

/**
 * Construit le graphe d’intersection : chaque atome = nœud, arête entre F et G si au moins une variable partagée.
 * Retourne une matrice d’adjacence.
 */
function buildIntersectionGraph(array $atoms): array {
    $n = count($atoms);
    $adj = array_fill(0, $n, []);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (array_intersect($atoms[$i]['vars'], $atoms[$j]['vars'])) {
                $adj[$i][] = $j;
                $adj[$j][] = $i;
            }
        }
    }
    return $adj;
}

/**
 * Construit un arbre d’intersection ("join tree") pour la requête.
 * Retourne un tableau d’arêtes : [index1, index2, [variables partagées]]
 */
function buildJoinTree(array $atoms): array {
    $n = count($atoms);
    $edges = [];

    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $shared = array_intersect($atoms[$i]['vars'], $atoms[$j]['vars']);
            if ($shared) {
                $edges[] = [
                    'from' => $i,
                    'to' => $j,
                    'vars' => $shared,
                    'weight' => -count($shared)
                ];
            }
        }
    }

    usort($edges, function($a, $b) { return $a['weight'] <=> $b['weight']; });

    $parent = [];
    for ($i = 0; $i < $n; $i++) $parent[$i] = $i;

    $find = function($parent, $i) { while ($parent[$i] != $i) $i = $parent[$i]; return $i; };

    $tree = [];
    foreach ($edges as $e) {
        $a = $e['from'];
        $b = $e['to'];
        $pa = $find($parent, $a);
        $pb = $find($parent, $b);
        if ($pa != $pb) {
            $tree[] = ['from' => $a, 'to' => $b, 'label' => $e['vars']];
            $parent[$pb] = $pa;
        }
        if (count($tree) == $n - 1) break;
    }
    return $tree;
}



/**
 * Génère toutes les arêtes possibles du graphe d’intersection (pour join tree).
 */
function allIntersectionEdges(array $atoms): array {
    $n = count($atoms);
    $edges = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $shared = array_intersect($atoms[$i]['vars'], $atoms[$j]['vars']);
            if ($shared) {
                $edges[] = ['from'=>$i, 'to'=>$j, 'vars'=>$shared];
            }
        }
    }
    return $edges;
}

/**
 * Teste récursivement toutes les combinaisons d'arbres couvrants, retourne true si au moins un est connexe pour toutes les variables.
 */
function hasAcyclicJoinTree($atoms) {
    $n = count($atoms);
    $edges = allIntersectionEdges($atoms);

    // Génère tous les arbres couvrants : algorithme naïf (exponentiel, OK pour n ≤ 7)
    function gen($n, $edges, $used = [], $chosen = [], $idx = 0) {
        if (count($chosen) == $n-1) return [$chosen];
        if ($idx >= count($edges)) return [];
        $results = [];
        // Ajout de l'arête courante si elle ne crée pas de cycle
        $uf = [];
        foreach (range(0, $n-1) as $i) $uf[$i] = $i;
        foreach ($chosen as $e) {
            $a = $e['from']; $b = $e['to'];
            $uf[$a] = $uf[$b] = min($uf[$a], $uf[$b]);
        }
        $a = $edges[$idx]['from']; $b = $edges[$idx]['to'];
        if ($uf[$a] != $uf[$b]) {
            $next = $chosen; $next[] = $edges[$idx];
            $results = array_merge($results, gen($n, $edges, $used, $next, $idx+1));
        }
        // Ne pas prendre cette arête
        $results = array_merge($results, gen($n, $edges, $used, $chosen, $idx+1));
        return $results;
    }
    $trees = gen($n, $edges);

    // Pour chaque arbre couvrant, teste la connexité join tree (reprise de isJoinTreeConnected)
    foreach ($trees as $tree) {
        $adj = array_fill(0, $n, []);
        foreach ($tree as $e) {
            $adj[$e['from']][] = $e['to'];
            $adj[$e['to']][] = $e['from'];
        }
        $allVars = [];
        foreach ($atoms as $a) foreach ($a['vars'] as $v) $allVars[$v] = true;
        $ok = true;
        foreach (array_keys($allVars) as $var) {
            $withVar = [];
            foreach ($atoms as $k => $a) if (in_array($var, $a['vars'])) $withVar[] = $k;
            if (count($withVar) <= 1) continue;
            $visited = array_fill(0, $n, false);
            $queue = [$withVar[0]];
            $found = 1;
            $visited[$withVar[0]] = true;
            while ($queue) {
                $u = array_shift($queue);
                foreach ($adj[$u] as $v) {
                    if (!$visited[$v] && in_array($v, $withVar)) {
                        $visited[$v] = true;
                        $found++;
                        $queue[] = $v;
                    }
                }
            }
            if ($found != count($withVar)) { $ok = false; break; }
        }
        if ($ok) return true; // Il existe au moins un join tree connexe !
    }
    return false;
}




/**
 * Affiche l’arbre d’intersection sous forme de liste lisible.
 */
function joinTreeToText(array $joinTree, array $atoms): string {
    if (!$joinTree) return "(aucun arbre trouvé)";
    $unique = []; 
    foreach ($joinTree as $e) {
        $key = $e['from'] < $e['to'] ? $e['from']."-".$e['to'] : $e['to']."-".$e['from'];
        if (!isset($unique[$key])) {
            $unique[$key] = [
                'from' => $e['from'],
                'to'   => $e['to'],
                'label' => $e['label'] // Correction ici : 'vars' vers 'label'
            ];
        } else {
            $unique[$key]['label'] = array_unique(array_merge($unique[$key]['label'], $e['label']));
        }
    }
    $t = [];
    foreach ($unique as $e) {
        $t[] = $atoms[$e['from']]['rel'] . ' —[' . implode(',', $e['label']) . ']— ' . $atoms[$e['to']]['rel'];
    }
    return implode("\n", $t);
}




/**
 * Cherche un cycle dans le graphe d’intersection (DFS)
 * Retourne un tableau d’indices d’atomes formant un cycle, ou [] si pas de cycle
 */
function findIntersectionCycle(array $atoms): array {
    $n = count($atoms);
    $adj = buildIntersectionGraph($atoms);
    $visited = array_fill(0, $n, false);
    $parent = array_fill(0, $n, -1);

    for ($start = 0; $start < $n; $start++) {
        if ($visited[$start]) continue;
        $stack = [[$start, -1]];
        while ($stack) {
            [$v, $p] = array_pop($stack);
            if ($visited[$v]) continue;
            $visited[$v] = true;
            $parent[$v] = $p;
            foreach ($adj[$v] as $w) {
                if ($w == $p) continue;
                if ($visited[$w]) {
                    // Cycle trouvé dans la composante de $start
                    $cycle = [$w, $v];
                    $x = $v;
                    while ($parent[$x] !== -1 && $parent[$x] != $w) {
                        $x = $parent[$x];
                        $cycle[] = $x;
                    }
                    $cycle = array_reverse($cycle);
                    return $cycle;
                }
                $stack[] = [$w, $v];
            }
        }
    }
    return [];
}



/*------------------------------------------------------------
  2. Parsing – prise en charge des clés primaires composées
------------------------------------------------------------*/
/**
 * Parse les atomes de la requête.
 * Syntaxe : Rel(clé1, clé2; attr1, attr2)
 * Chaque atome → ['rel'=>..., 'vars'=>..., 'key'=>...]
 */
function parseAtoms(string $q): array {
    preg_match_all('/([A-Za-z]\w*)\s*\(([^)]*)\)/', $q, $m, PREG_SET_ORDER);
    if (!$m) throw new Exception("Aucun atome reconnu.");

    $seen = [];
    $atoms = [];
    foreach ($m as $a) {
        $rel = $a[1];
        if (isset($seen[$rel])) throw new Exception("Auto‑jointure sur «".$rel."».");
        $seen[$rel] = true;

        // Clé primaire et non-clés séparées par un point-virgule
        $parts = explode(';', $a[2]);
        $keyVars = array_map('trim', explode(',', $parts[0]));
        $otherVars = [];
        if (count($parts) > 1) {
            $otherVars = array_filter(array_map('trim', explode(',', $parts[1])));
        }
        $allVars = array_merge($keyVars, $otherVars);
        $atoms[] = [
            'rel'  => $rel,
            'vars' => $allVars,
            'key'  => $keyVars,
        ];
    }
    return $atoms;
}

/*------------------------------------------------------------
  3. QuadAttack — graphe d’attaque & verdict FO
------------------------------------------------------------*/
/**
 * Lance la construction du graphe d'attaque et détecte l’acyclicité.
 */
function quadAttack(array $atoms): array {
	$joinTree = buildJoinTree($atoms);
    [$edges,$closed_atoms] = buildAttackGraph($atoms, $joinTree);
    $fo    = isAcyclic($edges, count($atoms));
    return [$edges, $fo,$closed_atoms];
}

/**
 * Calcule la fermeture d'un atome (propagation des clés primaires composées).
 */
function closure(array $atoms, int $idx): array {
    $closure = $atoms[$idx]['key'];
    $changed = true;

    while ($changed) {
        $changed = false;
        foreach ($atoms as $j => $atom) {
            if ($j !== $idx && array_diff($atom['key'], $closure) === []) {
                foreach ($atom['vars'] as $var) {
                    if (!in_array($var, $closure)) {
                        $closure[] = $var;
                        $changed = true;
                    }
                }
            }
        }
    }
    return $closure;
}

// Fonction pour trouver le chemin entre deux atomes dans l'arbre de jointure
function findPath($start, $end, $joinTree, $visited = []) {
    if ($start == $end) return [];
    $visited[$start] = true;
    foreach ($joinTree as $edge) {
        // Suivant le sens de l'arête
        foreach ([['from', 'to'], ['to', 'from']] as $dir) {
            list($src, $dst) = $dir;
            if ($edge[$src] == $start && (empty($visited[$edge[$dst]]))) {
                $path = findPath($edge[$dst], $end, $joinTree, $visited);
                if ($path !== null) {
                    // On reconstitue le chemin dans le bon sens (label reste inchangé)
                    if ($src === 'from') {
                        $arc = $edge;
                    } else {
                        // On inverse le sens de l'arête pour avoir from→to dans le chemin
                        $arc = [
                            'from' => $edge['to'],
                            'to' => $edge['from'],
                            'label' => $edge['label'],
                        ];
                    }
                    return array_merge([$arc], $path);
                }
            }
        }
    }
    return null;
}


function buildAttackGraph(array $atoms, array $joinTree): array {
    $edges = [];
	$closed_atoms = [];
    $n = count($atoms);
    for ($i = 0; $i < $n; $i++) {
        $closureF = closure($atoms, $i); // F+
		$closed_atoms[$i] = $closureF;
		//echo $atoms[$i]['rel'] . "+=" . implode(',', $closureF) . "\n";
        for ($j = 0; $j < $n; $j++) {
            if ($i == $j) continue;
            $pathEdges = findPath($i, $j, $joinTree);
            if ($pathEdges === null) continue;

            $attaque = true;
            foreach ($pathEdges as $edge) {
                if (!array_diff($edge['label'], $closureF)) {
                    $attaque = false;
                    break;
                }
            }
            if ($attaque) $edges[] = [$i, $j];
        }
    }
    return [$edges,$closed_atoms];
}



//echo $atoms[$i]['rel'] . "+=" . implode(',', $closureF) . "\n";





/**
 * Détection de cycle (DFS) – O(|E|+|V|).
 */
function isAcyclic(array $edges, int $n): bool {
    $adj = array_fill(0, $n, []);
    foreach ($edges as [$u,$v]) $adj[$u][] = $v;
    $vis = $rec = array_fill(0, $n, false);
    $dfs = function($u) use (&$dfs, &$adj, &$vis, &$rec) {
        $vis[$u] = $rec[$u] = true;
        foreach ($adj[$u] as $v) {
            if (!$vis[$v] && !$dfs($v)) return false;
            if ($rec[$v]) return false;
        }
        $rec[$u] = false; return true;
    };
    for ($i = 0; $i < $n; $i++) if (!$vis[$i] && !$dfs($i)) return false;
    return true;
}


/**
 * Détecte et liste tous les cycles dans le graphe d'attaque.
 * Retourne un tableau de cycles, chaque cycle étant un tableau d'indices de sommets.
 */
function findCycles(array $edges, int $n): array {
    $adj = array_fill(0, $n, []);
    foreach ($edges as [$u, $v]) $adj[$u][] = $v;

    $cycles = [];
    $stack = [];
    $blocked = array_fill(0, $n, false);
    $B = array_fill(0, $n, []);
    $start = 0;

    // Version simplifiée pour graphe petit (DFS à la Johnson)
    function circuit($v, $start, &$adj, &$stack, &$blocked, &$B, &$cycles) {
        $found = false;
        $stack[] = $v;
        $blocked[$v] = true;
        foreach ($adj[$v] as $w) {
            if ($w == $start) {
                $cycles[] = array_merge($stack, [$start]);
                $found = true;
            } elseif (!$blocked[$w]) {
                if (circuit($w, $start, $adj, $stack, $blocked, $B, $cycles)) $found = true;
            }
        }
        if ($found) {
            $blocked[$v] = false;
        } else {
            foreach ($adj[$v] as $w) {
                if (!in_array($v, $B[$w] ?? [])) $B[$w][] = $v;
            }
        }
        array_pop($stack);
        return $found;
    }

    for ($s = 0; $s < $n; $s++) {
        $stack = [];
        $blocked = array_fill(0, $n, false);
        $B = array_fill(0, $n, []);
        circuit($s, $s, $adj, $stack, $blocked, $B, $cycles);
    }

    // Filtrage: pas de cycles de taille 1 et pas de doublon
    $uniques = [];
    $final = [];
    foreach ($cycles as $c) {
        // Retire la répétition finale pour la clef de dédoublonnage
        $c_short = $c;
        array_pop($c_short);
        if (count($c_short) < 2) continue; // pas de cycle de taille 1
        // Génère une clé canonique (en démarrant par le plus petit indice)
        $min = min($c_short);
        while ($c_short[0] != $min) array_push($c_short, array_shift($c_short));
        $key = implode('-', $c_short);
        if (!in_array($key, $uniques)) {
            $uniques[] = $key;
            $final[] = $c;
        }
    }
    return $final;
}



/*------------------------------------------------------------
  4. Réécriture FO 
------------------------------------------------------------*/
/**
 * Génère la réécriture FO «à la Koutris&Wijsen» pour q sans cycle.
 * Respecte les clés primaires multiples dans l’énoncé et dans les ∀/∃.
 */
function makeFO(array $atoms): string {
    // 4.1 Variables existentielles de la requête
    $exist = [];
    foreach ($atoms as $a) foreach ($a['vars'] as $v)
        $exist[$v] = true;
    $existList = implode(', ', array_keys($exist));

    // 4.2 Corps conjonctif initial (les atomes eux-mêmes)
    $body = [];
    foreach ($atoms as $a)
        $body[] = sprintf('%s(%s)', $a['rel'], implode(', ', $a['vars']));

    // 4.3 Identification explicite des dépendances fonctionnelles implicites K(q \ {F})
    $dependencies = [];
    foreach ($atoms as $index => $F) {
        $remainingAtoms = array_filter($atoms, fn($key) => $key !== $index, ARRAY_FILTER_USE_KEY);
        $kqMinusF = [];
        foreach ($remainingAtoms as $G) {
            $keyG = $G['key']; // clé primaire de G
            $varsG = $G['vars']; // variables de G
            $kqMinusF[] = ['from' => $keyG, 'to' => $varsG];
        }
        $dependencies[$F['rel']] = $kqMinusF;
    }
    // $dependencies contient maintenant les ensembles K(q \ {F})

    // 4.4 Parcours linéaire des atomes (chemin acyclique)
    $univ = [];
    $n = count($atoms);
    for ($i = 0; $i < $n - 1; $i++) {
        $parent = $atoms[$i];
        $child  = $atoms[$i + 1];

        $shared = $child['vars'][0]; // variable clé partagée
        $sharedP = $shared . "'";    // renommage universel pour la variable partagée

        // Antécédent : Parent(key, shared')
        $pVarsPrime = $parent['vars'];
        for ($j = 1; $j < count($pVarsPrime); $j++) {
            $pVarsPrime[$j] = ($pVarsPrime[$j] === $shared) ? $sharedP : $pVarsPrime[$j]."'";
        }
        $antecedent = sprintf('%s(%s)', $parent['rel'], implode(', ', $pVarsPrime));

        // Conséquent corrigé : variable clé initiale préservée, variables non-clé renommées
        $childTuple = $child['vars'];
        $childTuple[0] = $shared; // on conserve la clé initiale
        for ($k = 1; $k < count($childTuple); $k++) {
            $childTuple[$k] = $childTuple[$k] . "'";
        }
        $existsPart = sprintf('%s(%s)', $child['rel'], implode(', ', $childTuple));

        $univ[] = "∀$sharedP ($antecedent → ∃" . implode(', ', array_slice($childTuple, 1)) . " ($existsPart))";
    }

    // 4.5 Formule finale assemblée
    return '∃' . $existList . ' (' . implode(' ∧ ', array_merge($body, $univ)) . ')';
}


/*------------------------------------------------------------
  5. Helpers HTML & lecture française
------------------------------------------------------------*/
/**
 * Affiche le formulaire et l’aide sur la syntaxe des clés primaires.
 */
function formHTML(): string {
    return '<form method="POST" class="mb-4">
      <label class="form-label">Requête q<br>
        <small class="text-muted">
          (ex: Emp(eid, did; nom, age), Dept(did; mgr).<br>
          Les attributs <b>avant</b> le point-virgule composent la clé primaire, sinon tous sont inclus dans celle-ci.)<br>		  
        </small>
      </label>
      <input name="query" class="form-control mb-2" required>
      <button class="btn btn-primary">Tester CERTAINTY(q)</button>
    </form>';
}

/**
 * Lecture française adaptée pour clé composée.
 */
function frenchReading(array $atoms): string {
    // Récupérer les variables uniques utilisées dans les atomes
    $vars = array_unique(array_merge(...array_column($atoms, 'vars')));
    $txt  = 'Il existe des valeurs <strong>'.implode(', ', $vars).'</strong> telles que les conditions suivantes soient toutes vraies :<br>';

    // Générer la phrase décrivant les conditions initiales (atomes)
    $phrases = [];
    foreach ($atoms as $a) {
        $phrases[] = htmlspecialchars($a['rel']).'('.implode(', ', $a['vars']).')';
    }
    $txt .= '1. Les relations '.implode(', ', $phrases).' sont vraies dans la base de données ;<br>';

    // Générer les contraintes universelles et existentielles pour chaque paire consécutive d'atomes
    for ($i = 0; $i < count($atoms) - 1; $i++) {
        $current = $atoms[$i];
        $next = $atoms[$i + 1];

        $currentVars = $current['vars'];
        $nextVars = $next['vars'];

        // Créer des versions primées pour les variables non clés pour éviter les conflits
        $yPrime = $currentVars[1] . "'";
        $zPrime = $nextVars[1] . "'";

        $txt .= $i+2 .'. Et pour chaque valeur possible <strong>'.htmlspecialchars($yPrime).'</strong>, si '.htmlspecialchars($current['rel']).'('.htmlspecialchars($currentVars[0]).', '.htmlspecialchars($yPrime).') est vrai, alors il doit obligatoirement exister une valeur <strong>'.htmlspecialchars($zPrime).'</strong> rendant '.htmlspecialchars($next['rel']).'('.htmlspecialchars($nextVars[0]).', '.htmlspecialchars($zPrime).') vrai ;<br>';
    }

    return $txt;
}


function edgesToText(array $edges, array $atoms): string {
    if (!$edges) return '(aucune arête)';
    $t = [];
    foreach ($edges as [$u,$v]) $t[] = $atoms[$u]['rel'].' → '.$atoms[$v]['rel'];
    return implode("\n", $t);
}

function svgAtomLabel($atom) {
    $rel = $atom['rel'];
    $vars = $atom['vars'];
    $key  = $atom['key'];
    $label = "<tspan font-weight='bold'>$rel</tspan>(";
    $spans = [];
    $keyQueue = $key; // On va consommer la clé une fois qu'on l'a utilisée

    foreach ($vars as $i => $var) {
        if (($k = array_search($var, $keyQueue, true)) !== false) {
            // On souligne, et on retire ce var de la clé (pour ne pas souligner 2x si même var non clé)
            $spans[] = "<tspan text-decoration='underline' font-style='italic' fill='#ffe066'>$var</tspan>";
            unset($keyQueue[$k]);
        } else {
            $spans[] = "<tspan>$var</tspan>";
        }
    }
    $label .= implode('<tspan>, </tspan>', $spans) . ")";
    return $label;
}


function findRoot($nodes, $edges) {
    $targets = [];
    foreach ($edges as $e) $targets[$e['to']] = true;
    foreach ($nodes as $n) if (!isset($targets[$n['id']])) return $n['id'];
    return $nodes[0]['id'];
}
function buildGraphTree($nodes, $edges) {
    $tree = [];
    foreach ($nodes as $n) $tree[$n['id']] = ['label'=>$n['label'], 'children'=>[]];
    foreach ($edges as $e) $tree[$e['from']]['children'][] = $e['to'];
    return $tree;
}
function layoutTree(&$tree, $id, $x, &$y, $xstep, $ystep, &$positions) {
    $children = $tree[$id]['children'];
    $myY = $y;
    if (count($children) == 0) {
        $positions[$id] = ['x'=>$x, 'y'=>$y];
        $y += $ystep;
    } else {
        $firstY = $y;
        foreach ($children as $c) {
            layoutTree($tree, $c, $x + $xstep, $y, $xstep, $ystep, $positions);
        }
        $lastY = $y - $ystep;
        $myY = ($firstY + $lastY) / 2;
        $positions[$id] = ['x'=>$x, 'y'=>$myY];
    }
}

function render(string $in, array $atoms, bool $fo, ?string $f, array $edges, array $closed_atoms ): string {
    $edgesTxt = edgesToText($edges, $atoms);
    if ($fo) {
    $explainGraph = "Le graphe d’attaque est acyclique; selon QuadAttack, CERTAINTY(q) est exprimable en FO avec cet application.";
    $cycleInfo = '';
} else {
    $explainGraph = "Le graphe d’attaque contient un cycle; QuadAttack conclut que CERTAINTY(q) n’est pas exprimable en FO.";
    $cycles = findCycles($edges, count($atoms));
    if ($cycles) {
        $cycleInfo = '<ul>';
        foreach ($cycles as $c) {
            // On affiche le nom des relations du cycle et sa taille
            $relCycle = [];
            for ($i = 0; $i < count($c) - 1; $i++) $relCycle[] = $atoms[$c[$i]]['rel'];
            $cycleInfo .= '<li>Cycle de taille '.(count($c)-1).': '.implode(' → ', $relCycle).' → '.$relCycle[0].'</li>';
        }
        $cycleInfo .= '</ul>';
    } else {
        $cycleInfo = '';
    }
}
    $explainFO = $fo
        ? "La partie «∃» instancie chaque variable, puis chaque clause «∀…→» impose la propagation clé→valeur."
        : "";
	
	$joinTree = buildJoinTree($atoms);
	$joinTreeTxt = joinTreeToText($joinTree, $atoms);
	
    ob_start(); ?>
    <div class="mb-3 p-2 border bg-light"><strong>Requête q:</strong> <code><?= htmlentities($in) ?></code></div>

    <div class="alert <?= $fo ? 'alert-success' : 'alert-danger' ?>">
        <strong>Verdict:</strong> CERTAINTY(q) <?= $fo ? 'est' : 'n’est pas' ?> exprimable en logique du premier ordre.
    </div>

    <?php if ($fo): ?>
    <div class="card border-primary mb-3">
        <div class="card-header">Réécriture FO</div>
        <div class="card-body">
            <pre class="mb-2"><?= htmlentities($f) ?></pre>
            <p class="mb-2"><?= $explainFO ?></p>
            <p class="mb-0"><em><?= frenchReading($atoms) ?></em></p>
        </div>
    </div>
    <?php endif; ?>
	<div class="row">
	<div class="col 6">
	<div class="card border-info mb-3">
    <div class="card-header">Arêtes de l'arbre de jointure</div>
    <div class="card-body">
        <pre class="mb-2"><?= htmlentities($joinTreeTxt) ?></pre>
        <small class="text-muted">Chaque arête relie deux atomes partageant au moins une variable commune.</small>
    </div>
	</div>
	</div>
	<div class="col 6">
	<div class="card border-info mb-3">
    <div class="card-header">Fermeture des atomes</div>
    <div class="card-body">
        <pre class="mb-2"><?php 
		foreach ($closed_atoms as $idx => $closure) {
			echo "{$atoms[$idx]['rel']}+ = {" . implode(', ', $closure) . "}<br>";
		}
		?></pre>        
    </div>
	</div>
	</div>
	</div>
	
	
	  <div class="row mb-3">
    <div class="col-md-6">
      <h4 class="text-primary mb-3">Arbre de jointure</h4>
	  <?php	if($joinTree): 
	  $nodes = [];
foreach ($atoms as $i => $atom) {
    // Tu peux adapter ce qui suit pour changer l’affichage
    $label = $atom['rel'] . '(' . implode(',', $atom['vars']) . ')';
    $nodes[] = ['id'=>$i, 'label'=>$label];
}
	  // === CALCUL LAYOUTS ===
$root = findRoot($nodes, $joinTree);
$tree = buildGraphTree($nodes, $joinTree);
$positionsJoinTree = [];
$y = 70;
//var_dump($edges);
layoutTree($tree, $root, 80, $y, 140, 90, $positionsJoinTree);
$heightJoin = max($y + 30, 300);
$widthJoin = 600;
	  
	  ?>
      <div class="card shadow-sm p-3 mb-4">
        <svg width="<?=$widthJoin?>" height="<?=$heightJoin?>" style="background:#f8fafc; border-radius:18px;width:100%;max-width:<?=$widthJoin?>px;">
          <!-- Arêtes -->
          <?php foreach ($joinTree as $e):
			$label2txt="";
            $from = $positionsJoinTree[$e['from']];
            $to   = $positionsJoinTree[$e['to']];
            $lx = ($from['x']+$to['x'])/2;
            $ly = ($from['y']+$to['y'])/2 - 13;
			foreach ($e['label'] as $l){
				$label2txt.=$l.",";
			}
			$label2txt=substr($label2txt,0,-1);
          ?>
            <line x1="<?=$from['x']?>" y1="<?=$from['y']?>" x2="<?=$to['x']?>" y2="<?=$to['y']?>" stroke="#8bb8ff" stroke-width="4"/>
            <text x="<?=$lx?>" y="<?=$ly?>" font-size="14" fill="#165" text-anchor="middle"><?=$label2txt?></text>
          <?php endforeach; ?>
          <!-- Noeuds -->
          <?php foreach ($positionsJoinTree as $id=>$pos):
              //$label = $tree[$id]['label'];
              $isRoot = ($id == $root);
			  $atom = $atoms[$id];
			  $label = svgAtomLabel($atom);
          ?>
            <circle cx="<?=$pos['x']?>" cy="<?=$pos['y']?>" r="28" fill="<?=$isRoot ? '#0d6efd' : '#297af7'?>" stroke="#1852a5" stroke-width="3"/>
            <text x="<?=$pos['x']?>" y="<?=$pos['y']+5?>" text-anchor="middle" fill="#fff" font-size="12" font-weight="<?=$isRoot ? 'bold' : 'normal'?>"><?=$label?></text>
          <?php endforeach; ?>
        </svg>
        <div class="text-end small mt-2 text-secondary">
          Racine auto-détectée : <b><?=$tree[$root]['label']?></b>
        </div>
      </div>
	  <?php endif; ?>
    </div>
    <div class="col-md-6">
      <h4 class="text-danger mb-3">Graphe d’attaque</h4>
	  <?php	if($edges): 
	  // --- Graphe d'attaque : layout en cercle ---
$n = count($nodes);
$cx = 250; $cy = 160; $r = 110;
$positionsAttack = [];
for ($i=0; $i<$n; $i++) {
    $angle = 2*M_PI*$i/$n - M_PI/2;
    $positionsAttack[$nodes[$i]['id']] = [
        'x'=>$cx + $r*cos($angle),
        'y'=>$cy + $r*sin($angle)
    ];
}
$widthAttack = 600;
$heightAttack = 340;
	  ?>
      <div class="card shadow-sm p-3 mb-4">
        <svg width="<?=$widthAttack?>" height="<?=$heightAttack?>" style="background:#f8fafc; border-radius:18px;width:100%;max-width:<?=$widthAttack?>px;">
          <defs>
            <marker id="arrow" markerWidth="12" markerHeight="12" refX="10" refY="6" orient="auto" markerUnits="strokeWidth">
              <path d="M2,2 L10,6 L2,10 L6,6 L2,2" fill="#ff502a"/>
            </marker>
          </defs>
          <?php
          foreach ($edges as $e) {
              $from = $positionsAttack[$e['0']];
              $to   = $positionsAttack[$e['1']];
              $dx = $to['x'] - $from['x'];
              $dy = $to['y'] - $from['y'];
              $len = sqrt($dx*$dx+$dy*$dy);
              $rr = 28; // rayon du cercle
              $startx = $from['x'] + $dx * $rr / $len;
              $starty = $from['y'] + $dy * $rr / $len;
              $endx = $to['x'] - $dx * $rr / $len;
              $endy = $to['y'] - $dy * $rr / $len;
              // Ligne fléchée
              echo "<line x1='$startx' y1='$starty' x2='$endx' y2='$endy' stroke='#ff502a' stroke-width='4' marker-end='url(#arrow)'/>";
              // Label
              $lx = ($from['x']+$to['x'])/2;
              $ly = ($from['y']+$to['y'])/2 - 12;
              echo "<text x='$lx' y='$ly' font-size='14' fill='#b43e10' text-anchor='middle'>{$e['label']}</text>";
          }
          foreach ($positionsAttack as $id=>$pos) {
              //$label = $nodes[$id]['label'] ?? $id;
			  $atom = $atoms[$id];
			  $label = svgAtomLabel($atom);
              echo "<circle cx='{$pos['x']}' cy='{$pos['y']}' r='28' fill='#e0591e' stroke='#b43e10' stroke-width='3'/>";
              echo "<text x='{$pos['x']}' y='".($pos['y']+5)."' text-anchor='middle' fill='#fff' font-size='12'>$label</text>";
          }
          ?>
        </svg>
        <div class="text-end small mt-2 text-secondary">
          Orientation : <b>flèches = attaque F &rarr; G</b>
        </div>
      </div>
	  <?php endif; ?>
    </div>
  </div>
	
	
	
	

	<div class="row">
	<div class="col 6">
    <div class="card border-secondary mb-3">
    <div class="card-header">Liste des attaques</div>
    <div class="card-body">
        <pre class="mb-2"><?= htmlentities($edgesTxt) ?></pre>
        <p class="mb-0"><?= $explainGraph ?></p>
    </div>
	</div>
	</div>
	<div class="col 6">
    <div class="card border-secondary mb-3">
    <div class="card-header">Liste des cycles</div>
    <div class="card-body">
        <?php if(!$fo) echo $cycleInfo; ?>
    </div>
	</div>
    </div>
	</div>


    <?= formHTML(); ?>
    <?php
    return ob_get_clean();
}
?>

</body>
</html>
<!--
		  R0(x, y;z) ∧ R1(x; y) ∧ R2(z;x) -> GA a 3cycles<br>
		  R(x; y) ∧ S(y;z) ∧ T (y; z) -> pas d attaque<br>
		  R(x;y) ∧ S(y;z) ∧ T (z;m1, m2) ∧ U (m1;m2) -> GA avec 7 cycle<br>
		  Emp(eid; did), Dept(did; mgr)-> pas d attaque<br>
		  R0(x; y), R1(y; x), R2(x, y), R3(x; z), R4(x; z) -> GA avec 1 cycle<br>
		  R0(y, z; u), R1(x; y), R2(z; x, u) -> requête cyclique<br>
-->