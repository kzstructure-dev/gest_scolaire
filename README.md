# Gestion d'une ecole

Documentation fonctionnelle de reference pour une application de gestion scolaire.

## 1. Objectif

L'application centralise les informations et les operations quotidiennes d'un etablissement scolaire : eleves, responsables, classes, enseignants, inscriptions, absences, notes et paiements.

Elle doit permettre de fiabiliser les donnees, reduire les taches manuelles et fournir rapidement des informations aux membres autorises du personnel.

## 1.1 Cadre ivoirien

Cette documentation vise une ecole privee ou publique en Cote d'Ivoire, du prescolaire au secondaire. Les regles, pieces, frais, coefficients et calendriers doivent rester configurables, car ils peuvent varier selon le type d'etablissement et les instructions officielles de chaque rentree.

Le referentiel doit permettre de configurer notamment :

| Cycle | Organisation indicative |
| --- | --- |
| Prescolaire | Petite, moyenne et grande section |
| Primaire | CP, CE et CM, avec les niveaux retenus par l'etablissement |
| Secondaire general | College puis lycee |
| Technique et professionnel | Filiere, specialite, niveau et stages |

Les classes comme CP1, CP2, CE1, CE2, CM1, CM2, 6e, 5e, 4e, 3e, 2nde, 1ere et Terminale sont des donnees de parametrage, et non des valeurs codees en dur.

Le systeme peut suivre les evaluations internes, examens blancs et resultats lies notamment au CEPE, au BEPC, au baccalaureat et aux certifications de l'enseignement technique et professionnel. Les dates, conditions et baremes sont parametrables par session.

## 2. Utilisateurs et droits

| Role | Responsabilites principales |
| --- | --- |
| Administrateur | Parametrage de l'etablissement, gestion des comptes et des droits |
| Direction | Consultation des indicateurs, validation des decisions et rapports |
| Scolarite | Inscriptions, dossiers des eleves, classes et documents administratifs |
| Enseignant | Saisie des presences, evaluations et consultation de ses classes |
| Comptable | Frais scolaires, paiements, recus et etats financiers |
| Parent ou responsable | Consultation des informations autorisees concernant ses enfants |

Les droits sont controles par role. Un utilisateur ne doit acceder qu'aux donnees necessaires a sa fonction.

## 3. Modules fonctionnels

### 3.1 Parametrage

- Informations de l'ecole : nom, adresse, contacts et annees scolaires.
- Cycles, niveaux, classes et matieres.
- Periodes scolaires et calendrier des cours.
- Baremes, types d'evaluation et frais scolaires.

### 3.2 Gestion des eleves

- Creation et modification du dossier eleve.
- Identite, date et lieu de naissance, adresse et contacts.
- Gestion des responsables legaux et des personnes a contacter en urgence.
- Ajout de documents administratifs.
- Historique des inscriptions et des changements de classe.
- Recherche par nom, matricule, classe ou annee scolaire.

### 3.3 Inscriptions et classes

1. Creer ou rechercher le dossier de l'eleve.
2. Verifier les pieces requises.
3. Choisir l'annee scolaire, le niveau et la classe.
4. Enregistrer l'inscription et attribuer un matricule.
5. Produire le recu ou le certificat necessaire.

Une inscription est rattachee a une seule annee scolaire et a une classe active. Un eleve peut avoir plusieurs inscriptions dans son historique.

### 3.4 Gestion des enseignants

- Fiche enseignant et informations de contact.
- Matieres enseignees et classes affectees.
- Emploi du temps.
- Acces limite aux classes et matieres attribuees.

### 3.5 Presences et absences

- Saisie quotidienne par classe et par cours.
- Statuts : present, absent, retarde ou dispense.
- Motif et justificatif d'absence.
- Calcul du total des absences et retards par periode.
- Edition d'un rapport pour la direction ou les responsables.

Une presence ne peut pas etre enregistree deux fois pour le meme eleve, cours et date.

### 3.6 Notes et evaluations

- Creation d'une evaluation avec matiere, classe, periode, date, bareme et coefficient.
- Saisie et modification des notes par l'enseignant autorise.
- Calcul des moyennes selon les coefficients.
- Validation des resultats par la direction ou la scolarite.
- Edition des bulletins.

Une note doit etre comprise entre 0 et le bareme de l'evaluation. Une note validee ne peut plus etre modifiee sans autorisation.

### 3.7 Paiements et frais scolaires

- Definition des frais par niveau et annee scolaire.
- Enregistrement des paiements partiels ou complets.
- Generation d'un numero de recu unique.
- Suivi du reste a payer.
- Etats des impayes et recettes par periode.

Le montant paye ne peut pas depasser le montant du a payer, sauf regle explicitement configuree par l'etablissement.

### 3.8 Documents et rapports

Documents courants :

- fiche d'inscription ;
- certificat de scolarite ;
- carte scolaire ;
- liste des eleves par classe ;
- registre des absences ;
- bulletins ;
- recus de paiement ;
- etat des impayes ;
- statistiques d'effectifs et de resultats.

Les documents doivent indiquer l'annee scolaire, la date d'edition et l'utilisateur qui les a generes.

## 4. Processus annuel

1. Creer la nouvelle annee scolaire et ses periodes.
2. Configurer les niveaux, classes, matieres et frais.
3. Reinscrire les anciens eleves et enregistrer les nouveaux.
4. Affecter les enseignants et publier les emplois du temps.
5. Suivre les presences, evaluations et paiements pendant l'annee.
6. Valider les resultats et generer les bulletins.
7. Cloturer l'annee en conservant les historiques.

La cloture interdit les modifications courantes sur l'annee concernee. Toute correction ulterieure doit etre tracee et reservee a un utilisateur autorise.

## 5. Donnees principales

- **Utilisateur** : compte, role, statut et derniere connexion.
- **Eleve** : identite, matricule, contacts et statut.
- **Responsable** : identite, lien avec l'eleve et coordonnees.
- **Annee scolaire** : libelle, dates et statut ouverte ou cloturee.
- **Classe** : niveau, libelle, capacite et enseignant principal.
- **Inscription** : eleve, annee, classe, date et statut.
- **Matiere** : libelle, coefficient et niveau concerne.
- **Presence** : eleve, cours, date et statut.
- **Evaluation** : matiere, periode, bareme et coefficient.
- **Note** : evaluation, eleve, valeur et statut de validation.
- **Facture ou frais** : eleve, libelle, montant et echeance.
- **Paiement** : frais, montant, date, mode et numero de recu.

## 6. Regles de securite

- Authentification obligatoire pour les espaces prives.
- Mots de passe stockes de maniere chiffree et jamais affiches.
- Controle des permissions sur chaque operation sensible.
- Journalisation des connexions, suppressions, validations et modifications importantes.
- Sauvegardes regulieres de la base de donnees.
- Protection des donnees personnelles et limitation de leur export.

## 7. Tableau de bord

Le tableau de bord peut afficher :

- effectif total et repartition par classe ou sexe ;
- nouvelles inscriptions ;
- absences recentes ;
- evaluations a valider ;
- total encaisse et montant restant ;
- alertes sur les dossiers incomplets ou les impayes.

Les indicateurs sont filtres par annee scolaire et respectent les droits de l'utilisateur.

## 8. Exigences non fonctionnelles

- Interface utilisable sur ordinateur, tablette et mobile.
- Recherche et affichage rapides pour les listes courantes.
- Validation des formulaires avec messages explicites.
- Export controle en PDF ou tableur.
- Conservation de l'historique sans suppression physique des donnees importantes.
- Respect du fuseau horaire et des formats de date de l'etablissement.

## 9. Criteres d'acceptation minimaux

- Un agent peut creer un eleve et l'inscrire dans une classe.
- Un enseignant peut saisir une presence et une note pour sa classe.
- La moyenne et le bulletin sont calcules correctement.
- Un comptable peut enregistrer un paiement et imprimer un recu.
- Un utilisateur ne peut pas consulter une fonction interdite par son role.
- Les donnees d'une annee cloturee restent consultables et sont protegees contre les modifications ordinaires.

## 10. Acteurs et droits detailles

| Role | Responsabilites principales |
| --- | --- |
| Administrateur technique | Comptes, roles, parametres, sauvegardes et journaux |
| Directeur ou chef d'etablissement | Validations, pilotage, rapports et decisions scolaires |
| Censeur ou responsable pedagogique | Classes, enseignants, emploi du temps, discipline et conseils |
| Scolarite | Dossiers, inscriptions, certificats, transferts et archives |
| Enseignant | Cahier de textes, presences, evaluations et notes de ses classes |
| Educateur ou surveillant | Presences, retards, discipline et suivi des eleves |
| Comptable ou caissier | Facturation, paiements, recus, caisse et impayes |
| Parent ou tuteur | Consultation des enfants rattaches et communications |
| Eleve | Consultation de son emploi du temps, notes et absences selon son age |

Les droits sont controles par role, site, annee scolaire, classe et fonction. Toute action sensible est journalisee.

## 11. Parametrage initial

Avant les inscriptions, l'administrateur configure :

1. l'identite de l'etablissement, ses sites et ses contacts ;
2. l'annee scolaire, les periodes, vacances et jours feries ;
3. les cycles, niveaux, classes, capacites, salles et matieres ;
4. les coefficients, baremes, types d'evaluations et modeles de bulletins ;
5. les enseignants, personnels et affectations ;
6. les frais, echeances, remises, bourses et modes de paiement ;
7. les pieces demandees et les modeles de documents ;
8. les utilisateurs, roles, notifications et validations.

Chaque parametre important est rattache a une annee scolaire afin de conserver l'historique.

## 12. Workflow des admissions et inscriptions

### Admission

1. Recevoir la demande et creer un candidat.
2. Verifier les informations et les pieces.
3. Effectuer un test ou un entretien si l'etablissement le prevoit.
4. Enregistrer la decision : accepte, liste d'attente, refuse ou a completer.
5. Transformer le candidat en eleve et attribuer un matricule.
6. Affecter le niveau, la classe et le regime.
7. Generer la fiche d'inscription et l'echeancier.

### Reinscription

1. Reprendre l'inscription de l'annee precedente.
2. Verifier la decision de passage, redoublement ou depart.
3. Actualiser les contacts et les documents.
4. Affecter l'eleve a la nouvelle annee et a une classe.
5. Appliquer les frais et remises autorisees.
6. Confirmer la reinscription et produire le recu.

Statuts possibles : brouillon, en attente, validee, suspendue, transferee, abandonnee et cloturee.

Les pieces peuvent comprendre, selon le niveau et la procedure en vigueur : acte ou extrait de naissance, photo, bulletins precedents, certificat de scolarite, dossier de transfert, justificatifs du responsable, carnet ou certificat de vaccination. Les pieces obligatoires sont configurables.

## 13. Organisation pedagogique ivoirienne

- Creer les classes avec niveau, capacite, salle et responsable.
- Affecter les eleves et prevenir les depassements de capacite.
- Affecter les enseignants aux matieres et aux classes.
- Construire l'emploi du temps par jour, heure, salle et cours.
- Detecter les conflits d'enseignant, de salle et de classe.
- Gerer les cours annules, remplaces ou rattrapes.
- Conserver les versions precedentes de l'emploi du temps.

Pour le secondaire technique et professionnel, ajouter la filiere, la specialite, les travaux pratiques, les stages et le maitre de stage lorsque ces fonctions existent.

## 14. Presences, evaluations et conseils de classe

L'enseignant ou l'educateur saisit, par date et par cours : present, absent, retard, dispense ou sortie autorisee. Une absence comporte si necessaire un motif, un justificatif et le statut justifie ou non justifie.

Une seule presence est autorisee pour un eleve, un cours et une date. Une correction apres validation exige un droit special, un motif et une trace dans le journal.

Types d'evaluations configurables : interrogation, devoir, composition, examen blanc, projet, oral, pratique et examen officiel.

Chaque evaluation indique la matiere, la classe, la periode, la date, le bareme, le coefficient et l'enseignant. La moyenne peut etre calculee ainsi :

`moyenne = somme(note normalisee x coefficient) / somme(coefficients)`

Une note normalisee peut suivre `note / bareme x base`, avec une base souvent fixee a 20. Le logiciel doit aussi accepter une appreciation ou un niveau de competence sans note numerique.

Les etapes sont : brouillon, saisie, soumission, controle, validation, publication et archivage. Une note validee est verrouillee. Une correction conserve l'ancienne valeur, la nouvelle valeur, le motif, l'auteur et la date.

Le conseil de classe peut enregistrer les appreciations, absences, conduite et decisions : passage, redoublement, orientation, exclusion ou autre decision configuree.

## 15. Frais scolaires et caisse

Le module gere les droits d'inscription, scolarite, cantine, transport, internat, tenue, fournitures, examens et autres prestations definies par l'etablissement.

- Grille tarifaire par annee, cycle, niveau ou regime.
- Echeancier et dates d'exigibilite.
- Remise, bourse, exonération partielle ou prise en charge.
- Paiement comptant ou echelonne.
- Modes : especes, cheque, virement, mobile money ou mode autorise.
- Recu numerote et journal de caisse.
- Annulation avec autorisation, motif et operation inverse.
- Etat des impayes, relances et rapprochement quotidien.

Le montant, la date, le mode, le caissier et le numero de recu sont immuables apres validation, sauf procedure d'annulation tracee.

## 16. Documents et communication

Documents courants : fiche eleve, fiche d'inscription, certificat de scolarite, attestation de frequentation, liste de classe, carte scolaire, emploi du temps, registre des absences, releve de notes, bulletin, decision de conseil, dossier de transfert, facture, recu et etat des impayes.

Chaque document affiche l'annee scolaire, la date, un numero ou identifiant, l'emetteur et, si necessaire, la signature ou le cachet de l'etablissement.

Les notifications peuvent etre envoyees par portail, courriel ou SMS : inscription, absence, reunion, paiement, impaye et publication des resultats. L'historique des envois et les erreurs sont conserves.

## 17. Modules optionnels

- Discipline, recompenses et suivi des incidents.
- Informations de sante strictement necessaires et acces restreint.
- Autorisations de sortie, cantine, transport et internat.
- Bibliotheque : catalogue, prets, retours et retards.
- Inventaire des salles, equipements, fournitures et consommables.
- Stages, projets et suivi de l'insertion pour l'enseignement professionnel.

Les donnees medicales et disciplinaires ne doivent pas apparaitre dans les exports generaux sans autorisation.

## 18. Cycle annuel de gestion

1. Cloturer resultats, paiements et archives de l'annee precedente.
2. Creer l'annee suivante et copier les parametres autorises.
3. Configurer calendrier, classes, matieres, frais et documents.
4. Ouvrir les admissions et traiter les candidats.
5. Reinscrire les anciens eleves apres les decisions de fin d'annee.
6. Affecter personnel, salles et emplois du temps.
7. Suivre cours, presences, evaluations, discipline et paiements.
8. Tenir les conseils et publier les bulletins.
9. Gerer transferts, sorties et dossiers d'examens.
10. Produire les rapports, exporter les donnees et archiver.

Une annee cloturee est consultable mais non modifiable dans les operations courantes. Sa reouverture doit etre autorisee, motivee et journalisee.

## 19. Donnees et regles essentielles

Entites recommandees : etablissement, site, annee scolaire, periode, utilisateur, role, journal d'audit, candidat, eleve, responsable, document, cycle, niveau, classe, salle, matiere, cours, affectation, inscription, transfert, presence, evaluation, note, bulletin, tarif, facture, paiement, recu et notification.

Regles :

- un matricule, un recu et un document officiel ont un identifiant unique ;
- un eleve a au plus une inscription active par annee et par site ;
- une note est comprise entre zero et son bareme ;
- les coefficients et baremes utilises dans un bulletin sont conserves avec le resultat ;
- une classe ne depasse pas sa capacite sans derogation autorisee ;
- les paiements valides ne sont pas supprimes, mais annules par une operation tracee ;
- toute modification sensible conserve l'ancienne valeur, la nouvelle valeur, l'utilisateur, la date et le motif ;
- les regles de calcul, dates et pieces ne sont jamais codees en dur.

## 20. Securite, exploitation et validation

- Authentification obligatoire et mots de passe haches.
- Permissions verifiees cote serveur pour chaque lecture et ecriture.
- Expiration des sessions apres une inactivite configurable.
- Chiffrement des sauvegardes et des documents sensibles.
- Journal des connexions, exports, validations et changements de droits.
- Sauvegarde quotidienne et test regulier de restauration.
- Masquage des donnees financieres, medicales et disciplinaires selon le role.
- Fuseau horaire `Africa/Abidjan`, interface francaise et fonctionnement acceptable avec une connexion instable.
- Recherche par nom, prenom, matricule, classe et telephone.
- Import controle depuis un tableur et export PDF ou tableur filtre par role.

Avant la mise en production, la direction confirme les cycles, niveaux, pieces, periodes, coefficients, tarifs, profils de validation, durees d'archivage, formats d'export et instructions officielles applicables a la rentree concernee.

## 21. Prototype livre

Le dossier contient une premiere interface fonctionnelle autonome :

- `index.php` : point d'entree PHP, structure de l'application et vues principales ;
- `styles.css` : interface responsive et charte visuelle ;
- `app.js` : navigation, donnees de demonstration, recherche et formulaire d'inscription.

### Lancer le prototype

Aucune installation n'est necessaire. Ouvrir l'application avec Apache/WampServer ou le serveur PHP integre.

Fonctions deja disponibles :

- navigation entre tableau de bord, eleves, presences, notes, finances, classes et documents ;
- consultation d'indicateurs de demonstration ;
- recherche dans la liste des eleves ;
- creation d'une inscription de demonstration ;
- affichage responsive sur mobile ;
- notifications et etats d'interface.

Le backend PHP et la base SQLite sont maintenant connectes pour les eleves, les cycles, les classes et les inscriptions. Les autres modules disposent deja de leurs tables et seront raccordes progressivement aux vues metier.

## 22. Version PHP 8.4

Le projet utilise maintenant PHP 8.4 avec PDO SQLite :

- `index.php` : point d'entree PHP et injection des donnees initiales dans le frontend ;
- `config.php` : connexion SQLite, creation du schema et donnees de demonstration ;
- `api.php` : API JSON pour lister et creer les eleves ;
- `data/gest_scolaire.sqlite` : base locale creee automatiquement au premier lancement ;
- `styles.css` et `app.js` : frontend responsive et interactions.

### Installation sous WampServer

1. Verifier que PHP 8.4 est selectionne dans WampServer.
2. Verifier que les extensions `pdo_sqlite` et `sqlite3` sont activees.
3. Placer le dossier dans `D:/wamp64/www/PROJETS/gest_scolaire`.
4. Ouvrir `http://localhost/PROJETS/gest_scolaire/`.

La base SQLite et son dossier sont crees automatiquement. Pour utiliser MySQL plus tard, remplacer la connexion PDO dans `config.php` et migrer les tables sans modifier le frontend.

### Test en ligne de commande

Depuis le dossier du projet :

```powershell
php -l index.php
php -l config.php
php -l api.php
php -S 127.0.0.1:8088
```

Puis ouvrir `http://127.0.0.1:8088/index.php`.

## 23. Entites implementees

Le schema SQLite cree automatiquement par `config.php` contient 46 tables metier :

- **Etablissement** : `establishments`, `sites`, `school_years`, `periods`, `calendars` ;
- **Securite** : `users`, `roles`, `permissions`, `role_permissions`, `audit_logs` ;
- **Referentiel pedagogique** : `cycles`, `levels`, `rooms`, `subjects`, `classes` ;
- **Eleves** : `students`, `candidates`, `guardians`, `student_guardians`, `emergency_contacts`, `student_documents` ;
- **Personnel** : `staff`, `teachers`, `teacher_assignments` ;
- **Scolarite** : `registrations`, `transfers` ;
- **Enseignement** : `courses`, `timetables`, `attendance`, `evaluations`, `grades`, `report_cards`, `class_councils` ;
- **Finance** : `tariffs`, `invoices`, `installments`, `cash_registers`, `payments`, `receipts` ;
- **Communication et vie scolaire** : `messages`, `notifications`, `incidents`, `health_records` ;
- **Ressources** : `library_books`, `loans`, `inventory_items`.

Les tables sont creees avec les relations entre eleves, inscriptions, classes, evaluations, notes et paiements. Des index sont ajoutes pour les recherches d'eleves, presences, notes et paiements. Les donnees de demonstration existantes sont conservees.

Les 46 tables sont maintenant couvertes par 46 entites Doctrine dans `src/Entity`, notamment `Student`, `SchoolClass`, `Registration`, `Evaluation`, `Grade`, `ReportCard`, `Invoice`, `Payment`, `LibraryBook` et `InventoryItem`. Les classes `RolePermission` et `StudentGuardian` representent les tables de liaison a cle composee.

## 24. Migration Symfony 8

Le projet contient maintenant une application Symfony **8.0.15**, compatible avec PHP 8.4 :

- `composer.json` et `composer.lock` : dependances Symfony 8, Doctrine ORM, Twig et Monolog ;
- `src/Entity/Student.php` : entite Doctrine mappee sur la table SQLite `students` existante ;
- `src/Controller/DashboardController.php` : route Symfony `/symfony` ;
- `templates/dashboard/index.html.twig` : vue Symfony des eleves et des cycles ;
- `public/` : racine web Symfony ;
- `.env` : Doctrine configure sur `data/gest_scolaire.sqlite`.

### Lancer Symfony

Depuis `D:/wamp64/www/PROJETS/gest_scolaire` :

```powershell
php bin/console about
php bin/console cache:clear
php -S 127.0.0.1:8090 -t public
```

Puis ouvrir `http://127.0.0.1:8090/symfony`.

La page PHP historique reste disponible avec `http://localhost/PROJETS/gest_scolaire/`. La nouvelle vue Symfony utilise le meme fichier SQLite, ce qui permet une migration progressive module par module.

### Migration Doctrine appliquee

La migration `migrations/Version20260819180000.php` a ete executee avec succes. Elle normalise les cycles Primaire et Secondaire, rattache les eleves existants a leurs inscriptions et ajoute les index de recherche sans supprimer les donnees.

Le workflow Scolarite est maintenant disponible sur `/symfony/students` : recherche par nom ou classe, filtre Primaire/Secondaire, consultation du numero d'inscription et creation transactionnelle d'une inscription avec affectation a une classe.

Le referentiel comprend maintenant les 13 niveaux du primaire et du secondaire ivoirien, chacun avec les divisions A, B et C : 39 classes sont disponibles dans le formulaire d'inscription. Les classes historiques deja utilisees sont conservees et ne sont pas dupliquees.

La vue Organisation pedagogique est disponible sur `/symfony/classes` : elle affiche les 39 classes, les niveaux, les cycles, les capacites, les effectifs inscrits et le taux d'occupation, avec un filtre Primaire/Secondaire.

Commandes de controle :

```powershell
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate --no-interaction
```

## 25. Authentification et droits

L'application Symfony est maintenant protegee par le composant Security. Toutes les routes exigent une session authentifiee, seule `/login` est publique.

- `src/Entity/User.php` : entite mappee sur la table `users`, elle implemente `UserInterface` et `PasswordAuthenticatedUserInterface` et lit le hachage dans `password_hash` ;
- `src/Entity/Role.php` : role metier de la table `roles`, converti en role Symfony par `getSecurityRole()`, par exemple `Scolarite` devient `ROLE_SCOLARITE` ;
- `src/Controller/SecurityController.php` et `templates/security/login.html.twig` : formulaire de connexion avec jeton CSRF et option « rester connecte » ;
- `src/EventListener/LoginAuditListener.php` : mise a jour de `users.last_login_at` et journalisation de la connexion dans `audit_logs` ;
- `config/packages/security.yaml` : fournisseur Doctrine, hierarchie des roles et controle d'acces par module.

### Hierarchie des roles

`ROLE_ADMINISTRATEUR` herite de `ROLE_DIRECTION`, qui herite de `ROLE_SCOLARITE`, `ROLE_ENSEIGNANT`, `ROLE_EDUCATEUR` et `ROLE_COMPTABLE`.

| Module | Roles autorises |
| --- | --- |
| `/symfony/students` | Scolarite, Direction |
| `/symfony/classes` | Scolarite, Enseignant, Direction |
| `/symfony/attendance` | Enseignant, Educateur, Scolarite, Direction |
| `/symfony/evaluations` | Enseignant, Scolarite, Direction |
| `/symfony/report-cards` | Enseignant, Scolarite, Direction |
| `/symfony/finance` | Comptable, Direction |
| `/symfony/teachers` | Scolarite, Direction |
| `/symfony/timetable` | Enseignant, Scolarite, Direction |

### Premiere connexion

La base SQLite n'est pas versionnee : il faut la creer avant de pouvoir se connecter.

Sous Windows, double-cliquez sur `demarrer-windows.bat` : le script installe Composer si besoin, cree la base, demande l'email et le mot de passe de l'administrateur puis lance le serveur sur `http://127.0.0.1:8000`.

Manuellement :

```powershell
composer install
php bin/console app:db:init
php bin/console app:user:create admin@ecole-horizon.ci "MotDePasse" Administrateur Adama Kone
php -S 127.0.0.1:8000 -t public
```

`app:db:init` cree `data/gest_scolaire.sqlite`, le schema complet et les donnees de reference (dont les roles metier) ; la commande est reexecutable sans perte.

Les mots de passe sont haches par Symfony, aucun mot de passe en clair n'est stocke. Le nom du role doit exister dans la table `roles`.

La page PHP historique `index.php` reste hors du pare-feu Symfony : elle doit etre retiree ou passee derriere le pare-feu avant toute mise en production.

## 26. Tests automatises

```powershell
php bin/phpunit
```

Les tests fonctionnels couvrent l'authentification et les droits :

- redirection vers `/login` pour un visiteur non authentifie ;
- echec de connexion avec de mauvais identifiants ;
- mise a jour de `users.last_login_at` et insertion dans `audit_logs` a chaque connexion reussie ;
- acces aux sept modules pour les roles Administrateur, Scolarite, Enseignant et Comptable (200 ou 403) ;
- menu lateral filtre par role ;
- deconnexion ;
- page 403 en francais.

`tests/bootstrap.php` recree la base SQLite de test `var/gest_scolaire_test.sqlite` a chaque execution (schema et donnees de reference produits par `config.php`) et y insere les comptes de test declares dans `tests/TestUsers.php`. La base de developpement `data/gest_scolaire.sqlite` n'est jamais touchee. Le chemin de la base peut etre change via la variable d'environnement `GEST_SCOLAIRE_DB`.

## 27. Module eleves et inscriptions

| Route | Methode | Role requis | Action |
| --- | --- | --- | --- |
| `/symfony/students` | GET | Scolarite, Direction | Liste, recherche et filtre par cycle |
| `/symfony/students` | POST | Scolarite, Direction | Inscription d'un eleve dans l'annee courante |
| `/symfony/students/{id}/edit` | GET / POST | Scolarite, Direction | Modification de la fiche et de la classe |
| `/symfony/students/{id}/delete` | POST | Scolarite, Direction | Suppression de l'eleve et de son inscription |

Regles appliquees par `App\Service\StudentManager` :

- prenoms, nom et classe obligatoires, classe existante, telephone au format ivoirien tolerant, statut parmi `Inscrit`, `A verifier`, `Transfere`, `Radie` ;
- le cycle est deduit du nom de la classe (`CP`, `CE`, `CM` => Primaire, sinon Secondaire) ;
- l'inscription est creee avec un numero `INS-00042` et le statut `Validee` ;
- un changement de classe met a jour l'inscription et laisse une ligne dans `transfers` ;
- chaque creation, modification et suppression est tracee dans `audit_logs` avec l'auteur et les valeurs avant/apres ;
- tous les formulaires sont proteges par un jeton CSRF.

## 28. Module presences et absences

| Route | Methode | Role requis | Action |
| --- | --- | --- | --- |
| `/symfony/attendance` | GET | Enseignant, Educateur, Scolarite, Direction | Feuille du jour par classe, compteurs et cumul mensuel |
| `/symfony/attendance` | POST | Enseignant, Educateur, Scolarite, Direction | Enregistrement ou mise a jour de la feuille du jour |
| `/symfony/attendance/{id}/justify` | POST | Enseignant, Educateur, Scolarite, Direction | Justification d'une absence avec motif obligatoire |

Regles appliquees par `App\Service\AttendanceManager` :

- seuls les eleves reellement inscrits dans la classe (`registrations.status = 'Validee'`) sont enregistres ;
- une seule ligne par eleve, classe et date : une nouvelle saisie met a jour la precedente ;
- les minutes de retard ne sont conservees que pour le statut `Retard` ;
- l'auteur de la saisie est trace dans `attendance.validated_by` ;
- une absence peut etre justifiee (`attendance.justified`) avec un motif obligatoire ;
- le cumul mensuel affiche par eleve les absences, les absences justifiees, les retards et les minutes cumulees ;
- les formulaires sont proteges par un jeton CSRF et suivent le schema POST / redirection / GET.

## 29. Module notes et bulletins

| Route | Methode | Role requis | Action |
| --- | --- | --- | --- |
| `/symfony/evaluations` | GET | Enseignant, Direction, Scolarite | Liste des evaluations, filtre par classe |
| `/symfony/evaluations` | POST | Enseignant, Direction, Scolarite | Creation d'une evaluation puis redirection vers la saisie |
| `/symfony/evaluations/{id}` | GET / POST | Enseignant, Direction, Scolarite | Feuille de notes de la classe et statistiques |
| `/symfony/evaluations/{id}/delete` | POST | Enseignant, Direction, Scolarite | Suppression de l'evaluation et de ses notes |
| `/symfony/report-cards` | GET | Enseignant, Direction, Scolarite | Bulletins filtres par classe et periode |
| `/symfony/report-cards` | POST | Enseignant, Direction, Scolarite | Calcul (`action` absent) ou publication (`action=publish`) |
| `/symfony/report-cards/{id}` | GET | Enseignant, Direction, Scolarite | Detail d'un bulletin, moyennes par matiere et absences |

Regles appliquees par `App\Service\EvaluationManager` :

- titre, classe, matiere et periode obligatoires et existants, type parmi les huit types officiels, date au format `AAAA-MM-JJ` ;
- bareme et coefficient strictement positifs ;
- seuls les eleves inscrits dans la classe de l'evaluation peuvent recevoir une note ;
- une note doit etre numerique et comprise entre 0 et le bareme, une seule note par eleve et evaluation ;
- un champ vide supprime la note existante ;
- l'auteur de la saisie est trace dans `grades.validated_by` ;
- l'evaluation passe a `Complete` quand toutes les notes attendues sont saisies, sinon `Saisie`.

Regles appliquees par `App\Service\ReportCardManager` :

- chaque note est ramenee sur 20 (`note / bareme * 20`) puis ponderee par le coefficient de l'evaluation pour obtenir la moyenne de la matiere ;
- la moyenne generale pondere les moyennes par matiere avec le coefficient de la matiere ;
- le rang est calcule par classe et periode, les ex aequo partagent le meme rang, un eleve sans note n'est pas classe ;
- appreciation : Excellent (>= 16), Tres bien (>= 14), Bien (>= 12), Passable (>= 10), sinon Insuffisant ;
- decision : Admis (>= 10), Admis sous conditions (>= 8,5), sinon Redouble ;
- le calcul est idempotent : un bulletin existant est mis a jour, jamais duplique ;
- la publication ne concerne que les bulletins au statut `Calcule` de la classe et de la periode choisies et horodate `published_at` ;
- les formulaires sont proteges par un jeton CSRF et suivent le schema POST / redirection / GET.

## 30. Module frais scolaires et paiements

| Route | Methode | Role requis | Action |
| --- | --- | --- | --- |
| `/symfony/finance` | GET | Comptable, Direction, Administrateur | Tableau de bord caisse, factures filtrables, paiements, impayes |
| `/symfony/finance` | POST | Comptable, Direction, Administrateur | `action=tariff`, `action=invoice`, `action=payment` ou `action=cancel` |
| `/symfony/finance/receipts/{id}` | GET | Comptable, Direction, Administrateur | Recu imprimable d'un paiement |

Regles appliquees par `App\Service\FinanceManager` :

- un tarif exige un libelle, un montant strictement positif, une echeance au format `AAAA-MM-JJ` si fournie et un niveau existant ; deux tarifs actifs ne peuvent pas porter le meme libelle ;
- une facture ne peut viser qu'un eleve ayant une inscription validee sur l'annee en cours et un tarif actif ; le meme tarif ne peut pas etre facture deux fois au meme eleve ;
- le numero de facture (`FAC-00001`) et le numero de recu (`REC-00001`) sont generes automatiquement ;
- un paiement doit etre positif, date au format `AAAA-MM-JJ`, avec un mode parmi Especes, Cheque, Virement et Mobile money, et ne peut pas depasser le reste a payer ;
- chaque paiement valide emet un recu unique qui conserve l'utilisateur emetteur (`receipts.issued_by`) ;
- le statut de la facture est recalcule apres chaque operation : `A payer`, `Partiel` ou `Payee` ;
- une annulation exige un motif, conserve la ligne de paiement au statut `Annule` et remet le montant au reste a payer ;
- les impayes sont recapitules par eleve ; les formulaires sont proteges par un jeton CSRF et suivent le schema POST / redirection / GET.

## 31. Module enseignants et emploi du temps

| Route | Methode | Role requis | Action |
| --- | --- | --- | --- |
| `/symfony/teachers` | GET | Scolarite, Direction, Administrateur | Liste des enseignants, charge horaire et affectations filtrables |
| `/symfony/teachers` | POST | Scolarite, Direction, Administrateur | `action=create`, `action=assign` ou `action=unassign` |
| `/symfony/timetable` | GET | Enseignant, Scolarite, Direction, Administrateur | Emploi du temps hebdomadaire d'une classe |
| `/symfony/timetable` | POST | Enseignant, Scolarite, Direction, Administrateur | `action=schedule` ou `action=remove` |

Regles appliquees par `App\Service\TeacherManager` :

- la creation d'un enseignant cree la fiche `staff` (type Enseignant) et la fiche `teachers` dans une seule transaction ; le matricule est genere (`ENS-0001`) s'il n'est pas fourni et reste unique ;
- l'email, s'il est renseigne, doit etre valide ;
- une matiere n'est confiee qu'a un seul enseignant par classe ; reaffecter le meme trio enseignant / classe / matiere met simplement a jour le volume horaire ;
- la charge hebdomadaire d'un enseignant est plafonnee a 30 heures, tous cours confondus ;
- retirer une affectation supprime aussi les creneaux correspondants de l'emploi du temps ;
- un creneau exige une affectation existante, un jour du lundi au samedi et des heures `HH:MM` avec une fin posterieure au debut ;
- un creneau est refuse s'il chevauche un autre creneau du meme enseignant, de la meme classe ou de la meme salle ; deux creneaux jointifs (10:00-12:00 apres 08:00-10:00) sont acceptes ;
- les formulaires sont proteges par un jeton CSRF et suivent le schema POST / redirection / GET.
