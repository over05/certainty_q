# certainty_q
 Implémentation de l’algorithme QUADATTACK – 
  Source  : J.Wijsen, «Certain Conjunctive Query Answering in First Order»
  Fonction : Teste si CERTAINTY(q) est exprimable en logique du premier ordre
             pour une requête conjonctive booléenne sans auto jointure.
             Clés primaires simples ou composées autorisées

Installation simple, il suffit de mettre le fichier php à la racine de votre serveur web.
Exemple d'entrée attendue par l'application : Emp(eid, did; nom, age), Dept(did; mgr)
Les attributs avant le point-virgule composent la clé primaire, sinon tous sont inclus dans celle-ci.

Exemples de requêtes testées

		  R0(x, y;z) ∧ R1(x; y) ∧ R2(z;x) -> GA a 3 cycles
		  R(x; y) ∧ S(y;z) ∧ T (y; z) -> pas d attaque
		  R(x;y) ∧ S(y;z) ∧ T (z;m1, m2) ∧ U (m1;m2) -> GA avec 7 cycles
		  Emp(eid; did), Dept(did; mgr)-> pas d attaque
		  R0(x; y), R1(y; x), R2(x, y), R3(x; z), R4(x; z) -> GA avec 1 cycle
		  R0(y, z; u), R1(x; y), R2(z; x, u) -> requête cyclique
