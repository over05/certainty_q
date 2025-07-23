# certainty_q
 Implémentation de l’algorithme QUADATTACK – 
  Source  : J.Wijsen, «Certain Conjunctive Query Answering in First Order»
  Fonction : Teste si CERTAINTY(q) est exprimable en logique du premier ordre
             pour une requête conjonctive booléenne sans auto jointure.
             Clés primaires simples ou composées autorisées

Installation simple, il suffit de mettre le fichier php à la racine de votre serveur web.
Exemple d'entrée attendue par l'application : Emp(eid, did; nom, age), Dept(did; mgr)
Les attributs avant le point-virgule composent la clé primaire, sinon tous sont inclus dans celle-ci.
