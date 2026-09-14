# Hors périmètre

Les produits ci-dessous sont **volontairement sortis** du périmètre actif. Ils
ne sont ni planifiés, ni estimés, ni conçus.

NexaResto · NexaDirectory · NexaExpress · NexaFleet · NexaService · NexaAgro ·
NexaPay

## Pourquoi ils sont ici

Le `Document Directeur v2.0` identifiait lui-même, en §38, le « risque n°1 —
scope explosion », puis détaillait les huit produits sur quarante sections.
L'avertissement était juste ; il n'a pas été suivi.

L'architecture retenue (monolithe modulaire, frontières de domaine strictes,
outbox) rend leur ajout possible plus tard. **Les documenter aujourd'hui ne les
rend pas plus possibles ; ça consomme seulement l'attention qui manque au
produit qui doit être vendu.**

## Condition de retour

La porte de sortie de la semaine 24 : **10 commerces payants sur Nexa POS**,
ayant réglé au moins deux échéances consécutives.

Premier candidat au retour : **NexaResto** — il réutilise le catalogue, le
stock, la caisse et le ledger, et n'ajoute que tables, recettes et cuisine.

Dernier : **NexaExpress**. Un marketplace à deux faces exige une masse critique
que dix commerces ne fournissent pas. Le §82 du Master Architecture Document le
disait déjà, et il avait raison :

> « Il ne faut pas commencer par NexaExpress simplement parce que la livraison
> semble plus spectaculaire. »
